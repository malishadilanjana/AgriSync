<?php
/**
 * AgriSync — AI Broker Agent Core Logic (TASK-055 / Issue #3)
 * Multi-Step Autonomous Workflow & Multi-Farmer Partial Fulfillment:
 * 1. Order Ingestion & State Validation
 * 2. Harvest Listings Database Query (filtering by available unreserved stock)
 * 3. District Proximity & Fair-Trade Guardrail Filtering
 * 4. Google Gemini 2.5 Flash AI Reasoning & Evaluation
 * 5. Multi-Farmer Partial Quantity Allocations & Atomic Database Reservations
 * 6. Audit Logging to agent_logs table, In-App Notifications, & SMS Alerts
 */

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../config/constants.php';
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/gemini_client.php';
require_once __DIR__ . '/agent_logger.php';

class BrokerAgent {
    private PDO $db;
    private GeminiClient $gemini;

    public function __construct(?PDO $db = null, ?GeminiClient $gemini = null) {
        $this->db = $db ?? getDbConnection();
        $this->gemini = $gemini ?? new GeminiClient();
    }

    /**
     * Run the full multi-step broker agent for a given order request
     *
     * @param int $orderId
     * @return array
     */
    public function matchOrder(int $orderId): array {
        $startTime = microtime(true);

        try {
            // =========================================================================
            // STEP 1: Fetch and Validate Order
            // =========================================================================
            $order = $this->fetchOrder($orderId);
            if (!$order) {
                AgentLogger::log('broker', 'Order Validation Failed', $orderId, ['error' => 'Order not found'], $this->db);
                return ['success' => false, 'matched' => false, 'error' => "Order #{$orderId} not found"];
            }

            if (!in_array($order['status'], ['pending', 'matching'])) {
                AgentLogger::log('broker', 'Order Status Check', $orderId, ['status' => $order['status'], 'message' => 'Order already processed or cancelled'], $this->db);
                return ['success' => false, 'matched' => false, 'error' => "Order is already in '{$order['status']}' state"];
            }

            // Update status to 'matching'
            $this->updateOrderStatus($orderId, 'matching');

            AgentLogger::log('broker', '1. Order Ingested', $orderId, [
                'crop_type' => $order['crop_type'],
                'quantity_kg' => (float) $order['quantity_kg'],
                'max_price' => (float) $order['max_price'],
                'delivery_date' => $order['delivery_date'],
                'business_name' => $order['business_name'],
                'district' => $order['business_district']
            ], $this->db);

            // =========================================================================
            // STEP 2: Query Candidate Harvest Listings (Available Unreserved Stock > 0)
            // =========================================================================
            $candidates = $this->searchCandidateListings($order['crop_type'], (float) $order['max_price']);

            AgentLogger::log('broker', '2. Database Candidate Search', $orderId, [
                'crop_queried' => $order['crop_type'],
                'candidates_found_count' => count($candidates),
                'candidate_ids' => array_column($candidates, 'id')
            ], $this->db);

            if (empty($candidates)) {
                $this->updateOrderStatus($orderId, 'pending');
                AgentLogger::log('broker', '2b. No Matching Listings Available', $orderId, [
                    'message' => 'No active harvest listings found matching crop criteria'
                ], $this->db);
                return [
                    'success' => true,
                    'matched' => false,
                    'order_id' => $orderId,
                    'message' => "No active harvest listings found for {$order['crop_type']} within maximum budget Rs. {$order['max_price']}/kg.",
                    'match' => null
                ];
            }

            // =========================================================================
            // STEP 3: Pre-Evaluate Candidates (Proximity + Fair Trade Guardrails)
            // =========================================================================
            $evaluatedCandidates = $this->evaluateCandidates($order, $candidates);

            AgentLogger::log('broker', '3. Proximity & Fair-Trade Evaluation', $orderId, [
                'evaluated_candidates' => $evaluatedCandidates
            ], $this->db);

            // =========================================================================
            // STEP 4: Gemini AI Multi-Factor Matching & Reasoning
            // =========================================================================
            $aiDecision = $this->runGeminiMatching($order, $evaluatedCandidates);

            $executionTimeMs = (int) round((microtime(true) - $startTime) * 1000);

            AgentLogger::log('broker', '4. AI Reasoning & Decision', $orderId, [
                'selected_listing_id' => $aiDecision['selected_listing_id'],
                'recommended_price' => $aiDecision['recommended_price_per_kg'],
                'confidence_score' => $aiDecision['confidence_score'],
                'reasoning' => $aiDecision['agent_reasoning'],
                'used_ai' => $aiDecision['used_gemini'],
                'execution_time_ms' => $executionTimeMs
            ], $this->db);

            // =========================================================================
            // STEP 5: Multi-Farmer Quantity Allocation & Atomic Database Reservations
            // =========================================================================
            $requestedQty = (float) $order['quantity_kg'];
            $remainingNeeded = $requestedQty;
            $allocations = [];

            // Prioritize AI recommended listing, followed by ranked candidates
            $prioritizedCandidates = [];
            foreach ($evaluatedCandidates as $cand) {
                if ((int)$cand['listing_id'] === (int)$aiDecision['selected_listing_id']) {
                    array_unshift($prioritizedCandidates, $cand);
                } else {
                    $prioritizedCandidates[] = $cand;
                }
            }

            foreach ($prioritizedCandidates as $cand) {
                $candId = (int) $cand['listing_id'];
                $availKg = (float) ($cand['available_kg'] ?? $cand['quantity_kg']);
                if ($availKg <= 0) {
                    continue;
                }

                $rawCandidate = null;
                foreach ($candidates as $c) {
                    if ((int) $c['id'] === $candId) {
                        $rawCandidate = $c;
                        break;
                    }
                }
                if (!$rawCandidate) {
                    continue;
                }

                $matchQty = min($remainingNeeded, $availKg);
                $matchPrice = (float) $cand['listing_price_per_kg'];

                $allocations[] = [
                    'listing' => $rawCandidate,
                    'matched_quantity' => $matchQty,
                    'matched_price' => $matchPrice,
                    'confidence_score' => (int) ($aiDecision['confidence_score'] ?? 90),
                    'agent_reasoning' => (string) ($aiDecision['agent_reasoning'] ?? "Matched {$matchQty}kg with Farmer {$rawCandidate['farmer_name']} ({$rawCandidate['farmer_district']}).")
                ];

                $remainingNeeded -= $matchQty;
                if ($remainingNeeded <= 0) {
                    break;
                }
            }

            if (empty($allocations)) {
                $this->updateOrderStatus($orderId, 'pending');
                return [
                    'success' => true,
                    'matched' => false,
                    'order_id' => $orderId,
                    'message' => "Insufficient unreserved harvest stock available for order #{$orderId}."
                ];
            }

            // Execute Atomic Transaction for Reservations & Match Record Creation
            $this->db->beginTransaction();

            $createdMatches = [];
            $totalMatchedQty = 0.0;

            foreach ($allocations as $alloc) {
                $c = $alloc['listing'];
                $listingId = (int) $c['id'];
                $farmerId = (int) $c['farmer_id'];
                $matchQty = (float) $alloc['matched_quantity'];
                $matchPrice = (float) $alloc['matched_price'];
                $reasoning = $alloc['agent_reasoning'];
                $confidence = (int) $alloc['confidence_score'];

                // Atomic reservation update: fail if available stock < matchQty
                $stmtReserve = $this->db->prepare("
                    UPDATE harvest_listings
                    SET quantity_reserved = quantity_reserved + :qty1,
                        status = IF((quantity_kg - (quantity_reserved + :qty2)) <= 0, 'matched', status),
                        updated_at = NOW()
                    WHERE id = :id AND (quantity_kg - quantity_reserved) >= :qty3
                ");
                $stmtReserve->execute([
                    ':qty1' => $matchQty,
                    ':qty2' => $matchQty,
                    ':qty3' => $matchQty,
                    ':id'   => $listingId
                ]);

                if ($stmtReserve->rowCount() === 0) {
                    throw new Exception("Reservation race condition for harvest listing #{$listingId}. Insufficient unreserved stock.");
                }

                $matchId = $this->createOrderMatch(
                    $orderId,
                    $listingId,
                    $farmerId,
                    (int) $order['business_id'],
                    $matchPrice,
                    $matchQty,
                    $reasoning,
                    $confidence
                );

                // Send In-App & SMS notifications to producer
                $this->createNotification(
                    $farmerId,
                    "🌾 AI Broker matched {$matchQty}kg of your {$order['crop_type']} harvest with {$order['business_name']} for Rs. " . number_format($matchPrice, 2) . "/kg!",
                    "/farmer/offers.php"
                );

                $farmerPhone = $c['farmer_phone'] ?? null;
                $smsText = "AgriSync Alert: You have a new order match offer for {$matchQty}kg of {$order['crop_type']} at Rs. " . number_format($matchPrice, 2) . "/kg! Log in to accept within 24 hours.";
                send_sms($farmerPhone, $smsText);

                $createdMatches[] = [
                    'id' => $matchId,
                    'farmer_name' => $c['farmer_name'],
                    'farmer_district' => $c['farmer_district'],
                    'crop_type' => $order['crop_type'],
                    'matched_quantity' => $matchQty,
                    'matched_price' => $matchPrice,
                    'confidence_score' => $confidence,
                    'agent_reasoning' => $reasoning,
                    'status' => 'proposed'
                ];

                $totalMatchedQty += $matchQty;
            }

            // Update order_requests status to 'matched'
            $this->updateOrderStatus($orderId, 'matched');

            // Send notification to commercial buyer
            $this->createNotification(
                (int) $order['business_id'],
                "✨ AI Broker completed multi-farmer matching for your {$order['crop_type']} order (#ORD-{$orderId}) totaling {$totalMatchedQty}kg across " . count($createdMatches) . " producer listing(s)!",
                "/business/orders.php"
            );

            $this->db->commit();

            AgentLogger::log('broker', '5. Matches Finalized & Reservations Committed', $orderId, [
                'matches_count' => count($createdMatches),
                'total_matched_quantity' => $totalMatchedQty,
                'business_id' => $order['business_id']
            ], $this->db);

            $firstMatch = $createdMatches[0];

            return [
                'success' => true,
                'matched' => true,
                'order_id' => $orderId,
                'match_id' => $firstMatch['id'],
                'total_matched_quantity' => $totalMatchedQty,
                'matches' => $createdMatches,
                'execution_time_ms' => $executionTimeMs,
                'match' => [
                    'id' => $firstMatch['id'],
                    'farmer_name' => $firstMatch['farmer_name'],
                    'farmer_district' => $firstMatch['farmer_district'],
                    'crop_type' => $order['crop_type'],
                    'quantity_kg' => $totalMatchedQty,
                    'matched_price' => $firstMatch['matched_price'],
                    'confidence_score' => $firstMatch['confidence_score'],
                    'agent_reasoning' => $firstMatch['agent_reasoning'],
                    'status' => 'proposed',
                    'used_gemini' => $aiDecision['used_gemini']
                ]
            ];

        } catch (Throwable $e) {
            if (isset($this->db) && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            AgentLogger::log('broker', 'Fatal Error in Broker Agent', $orderId, ['error' => $e->getMessage()], $this->db);
            return [
                'success' => false,
                'matched' => false,
                'error' => 'Broker Agent error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Query order details with associated business profile
     */
    private function fetchOrder(int $orderId): ?array {
        $stmt = $this->db->prepare("
            SELECT o.*, u.name AS business_name, u.district AS business_district, u.phone AS business_phone
            FROM order_requests o
            JOIN users u ON o.business_id = u.id
            WHERE o.id = :order_id
            LIMIT 1
        ");
        $stmt->execute([':order_id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        return $order ?: null;
    }

    /**
     * Search available harvest listings with unreserved stock > 0
     */
    private function searchCandidateListings(string $cropType, float $maxPrice): array {
        $stmt = $this->db->prepare("
            SELECT h.*, 
                   (h.quantity_kg - COALESCE(h.quantity_reserved, 0.00)) AS available_kg,
                   u.name AS farmer_name, u.district AS farmer_district, u.phone AS farmer_phone
            FROM harvest_listings h
            JOIN users u ON h.farmer_id = u.id
            WHERE (h.status = 'available' OR h.status = 'matched')
              AND (h.quantity_kg - COALESCE(h.quantity_reserved, 0.00)) > 0
              AND h.harvest_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
              AND LOWER(h.crop_type) = LOWER(:crop_type)
              AND h.price_per_kg <= :max_price
            ORDER BY h.price_per_kg ASC, h.harvest_date ASC
            LIMIT 15
        ");
        $stmt->execute([
            ':crop_type' => trim($cropType),
            ':max_price' => $maxPrice
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Calculate district proximity score based on Sri Lankan agro-ecological zones and transit corridors
     */
    private function calculateProximityScore(string $districtA, string $districtB): float {
        $a = strtolower(trim($districtA));
        $b = strtolower(trim($districtB));

        if (empty($a) || empty($b)) {
            return 0.70;
        }
        if ($a === $b) {
            return 1.00;
        }

        $agroClusters = [
            'central_highlands'  => ['nuwara eliya', 'badulla', 'kandy', 'matale'],
            'central_dry_hub'    => ['dambulla', 'matale', 'anuradhapura', 'polonnaruwa', 'kurunegala'],
            'western_metropolis' => ['colombo', 'gampaha', 'kalutara'],
            'southern_basin'     => ['galle', 'matara', 'hambantota', 'monaragala', 'ratnapura'],
            'northern_peninsula' => ['jaffna', 'kilinochchi', 'mullaitivu', 'vavuniya', 'mannar'],
            'eastern_coastal'    => ['batticaloa', 'ampara', 'trincomalee']
        ];

        foreach ($agroClusters as $cluster) {
            if (in_array($a, $cluster, true) && in_array($b, $cluster, true)) {
                return 0.85;
            }
        }

        $isCentral = in_array($a, ['dambulla', 'matale', 'kandy', 'nuwara eliya', 'kurunegala'], true) || in_array($b, ['dambulla', 'matale', 'kandy', 'nuwara eliya', 'kurunegala'], true);
        $isWestern = in_array($a, ['colombo', 'gampaha', 'kalutara'], true) || in_array($b, ['colombo', 'gampaha', 'kalutara'], true);
        if ($isCentral && $isWestern) {
            return 0.75;
        }

        return 0.50;
    }

    /**
     * Calculate harvest date freshness and delivery synchronization score
     */
    private function calculateFreshnessScore(?string $harvestDate, ?string $deliveryDate): float {
        if (empty($harvestDate) || empty($deliveryDate)) {
            return 0.80;
        }
        $hTime = strtotime($harvestDate);
        $dTime = strtotime($deliveryDate);
        if (!$hTime || !$dTime) {
            return 0.80;
        }
        $diffDays = ($dTime - $hTime) / 86400;

        if ($diffDays >= 0 && $diffDays <= 2) {
            return 1.00;
        } elseif ($diffDays > 2 && $diffDays <= 5) {
            return 0.85;
        } elseif ($diffDays > 5 && $diffDays <= 10) {
            return 0.65;
        } elseif ($diffDays < 0) {
            return 0.70;
        }
        return 0.50;
    }

    /**
     * Pre-evaluate candidates using multi-factor constraint scoring and fair-trade checks
     */
    private function evaluateCandidates(array $order, array $candidates): array {
        $evaluated = [];
        $businessDistrict = $order['business_district'] ?? '';
        $deliveryDate = $order['delivery_date'] ?? '';

        foreach ($candidates as $c) {
            $farmerDistrict = $c['farmer_district'] ?? '';
            $price = (float) $c['price_per_kg'];
            $maxPrice = (float) $order['max_price'];
            $availKg = (float) ($c['available_kg'] ?? ($c['quantity_kg'] - ($c['quantity_reserved'] ?? 0)));

            $proximityScore = $this->calculateProximityScore($businessDistrict, $farmerDistrict);
            $freshnessScore = $this->calculateFreshnessScore($c['harvest_date'] ?? null, $deliveryDate);

            $priceRatio = $maxPrice > 0 ? ($price / $maxPrice) : 1.0;
            $priceScore = max(0.2, min(1.0, 1.2 - $priceRatio));

            $quantityRatio = min(1.0, $availKg / (float) $order['quantity_kg']);

            $compositeScore = round(
                ($proximityScore * 0.30) + 
                ($priceScore * 0.30) + 
                ($freshnessScore * 0.20) + 
                ($quantityRatio * 0.20), 
                2
            );

            $evaluated[] = [
                'listing_id' => (int) $c['id'],
                'farmer_id' => (int) $c['farmer_id'],
                'farmer_name' => $c['farmer_name'],
                'farmer_district' => $c['farmer_district'],
                'quantity_kg' => (float) $c['quantity_kg'],
                'available_kg' => $availKg,
                'listing_price_per_kg' => $price,
                'harvest_date' => $c['harvest_date'],
                'proximity_score' => $proximityScore,
                'freshness_score' => $freshnessScore,
                'composite_score' => $compositeScore
            ];
        }

        usort($evaluated, fn($a, $b) => $b['composite_score'] <=> $a['composite_score']);
        return $evaluated;
    }

    /**
     * Run Gemini AI to evaluate candidates and construct transparent reasoning
     */
    private function runGeminiMatching(array $order, array $evaluatedCandidates): array {
        $topCandidate = $evaluatedCandidates[0];

        $systemInstruction = "You are the AgriSync Autonomous AI Broker Agent for Sri Lankan agriculture. "
            . "Your goal is to match business bulk purchase orders with optimal local farmer harvest listings. "
            . "You must balance Fair Trade (SDG 8), Proximity/Freshness (SDG 12), and Economic Efficiency. "
            . "Output your decision in strict JSON format.";

        $prompt = "Order Request:\n" . json_encode([
            'order_id' => (int) $order['id'],
            'business_name' => $order['business_name'],
            'business_district' => $order['business_district'],
            'crop_type' => $order['crop_type'],
            'required_quantity_kg' => (float) $order['quantity_kg'],
            'max_budget_per_kg' => (float) $order['max_price'],
            'desired_delivery_date' => $order['delivery_date']
        ], JSON_PRETTY_PRINT) . "\n\n"
        . "Available Candidate Farmer Listings:\n"
        . json_encode($evaluatedCandidates, JSON_PRETTY_PRINT) . "\n\n"
        . "Return a JSON object with keys:\n"
        . "- selected_listing_id (integer)\n"
        . "- recommended_price_per_kg (float)\n"
        . "- confidence_score (integer between 75 and 99)\n"
        . "- agent_reasoning (string)\n"
        . "- summary (string)";

        try {
            if ($this->gemini->isConfigured()) {
                $aiResponse = $this->gemini->generateJSON($prompt, [
                    'systemInstruction' => $systemInstruction,
                    'temperature' => 0.15
                ]);

                if ($aiResponse['success'] && !empty($aiResponse['data']['selected_listing_id'])) {
                    $data = $aiResponse['data'];
                    $selectedId = (int) $data['selected_listing_id'];
                    
                    $matchedCand = null;
                    foreach ($evaluatedCandidates as $ec) {
                        if ($ec['listing_id'] === $selectedId) {
                            $matchedCand = $ec;
                            break;
                        }
                    }
                    if (!$matchedCand) {
                        $matchedCand = $topCandidate;
                        $selectedId = (int) $topCandidate['listing_id'];
                    }

                    $rawPrice = (float) ($data['recommended_price_per_kg'] ?? $matchedCand['listing_price_per_kg']);
                    $boundedPrice = max((float)$matchedCand['listing_price_per_kg'], min((float)$order['max_price'], $rawPrice));

                    return [
                        'selected_listing_id' => $selectedId,
                        'recommended_price_per_kg' => $boundedPrice,
                        'confidence_score' => (int) ($data['confidence_score'] ?? 92),
                        'agent_reasoning' => (string) ($data['agent_reasoning'] ?? "Matched based on optimal district proximity, harvest freshness, and sustainable fair-trade pricing."),
                        'used_gemini' => true
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('BrokerAgent Gemini matching error: ' . $e->getMessage());
        }

        $distanceNote = ($topCandidate['proximity_score'] >= 1.0) 
            ? "same district ({$topCandidate['farmer_district']})" 
            : (($topCandidate['proximity_score'] >= 0.85) 
                ? "regional agro cluster ({$topCandidate['farmer_district']})" 
                : "connected economic corridor ({$topCandidate['farmer_district']})");

        $reasoning = "AI Broker selected Farmer {$topCandidate['farmer_name']} located in {$distanceNote} with {$topCandidate['available_kg']}kg available unreserved stock. "
            . "The matched price of Rs. " . number_format($topCandidate['listing_price_per_kg'], 2) . "/kg preserves fair-trade margins while fulfilling the buyer's requested delivery timeline with minimal food miles (SDG 8 & 12).";

        return [
            'selected_listing_id' => (int) $topCandidate['listing_id'],
            'recommended_price_per_kg' => (float) $topCandidate['listing_price_per_kg'],
            'confidence_score' => 90,
            'agent_reasoning' => $reasoning,
            'used_gemini' => false
        ];
    }

    /**
     * Insert match into order_matches table with matched_quantity
     */
    private function createOrderMatch(
        int $orderId,
        int $listingId,
        int $farmerId,
        int $businessId,
        float $matchedPrice,
        float $matchedQuantity,
        string $reasoning,
        int $confidenceScore
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO order_matches (
                order_id, listing_id, farmer_id, business_id,
                matched_price, matched_quantity, agent_reasoning, confidence_score, status, created_at
            ) VALUES (
                :order_id, :listing_id, :farmer_id, :business_id,
                :matched_price, :matched_quantity, :agent_reasoning, :confidence_score, 'proposed', NOW()
            )
            ON DUPLICATE KEY UPDATE
                matched_price = VALUES(matched_price),
                matched_quantity = VALUES(matched_quantity),
                agent_reasoning = VALUES(agent_reasoning),
                confidence_score = VALUES(confidence_score),
                status = 'proposed'
        ");

        $stmt->execute([
            ':order_id' => $orderId,
            ':listing_id' => $listingId,
            ':farmer_id' => $farmerId,
            ':business_id' => $businessId,
            ':matched_price' => $matchedPrice,
            ':matched_quantity' => $matchedQuantity,
            ':agent_reasoning' => $reasoning,
            ':confidence_score' => $confidenceScore
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function updateOrderStatus(int $orderId, string $status): void {
        $stmt = $this->db->prepare("UPDATE order_requests SET status = :status WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $orderId]);
    }

    private function createNotification(int $userId, string $message, string $link): void {
        $stmt = $this->db->prepare("
            INSERT INTO notifications (user_id, message, link, is_read, created_at)
            VALUES (:user_id, :message, :link, 0, NOW())
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':message' => $message,
            ':link' => $link
        ]);
    }
}

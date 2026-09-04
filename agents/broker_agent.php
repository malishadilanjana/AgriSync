<?php
/**
 * AgriSync — AI Broker Agent Core Logic (TASK-055 / Issue #3)
 * Multi-Step Autonomous Workflow:
 * 1. Order Ingestion & State Validation
 * 2. Harvest Listings Database Query
 * 3. District Proximity & Fair-Trade Guardrail Filtering
 * 4. Google Gemini 2.5 Flash AI Reasoning & Evaluation (with algorithmic fallback)
 * 5. Order Match Creation & Status Transitions
 * 6. Audit Logging to agent_logs table & In-App Notifications
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
            // STEP 2: Query Candidate Harvest Listings
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
            // STEP 5: Create Match Record & Update System State
            // =========================================================================
            $selectedCandidate = null;
            foreach ($candidates as $c) {
                if ((int) $c['id'] === (int) $aiDecision['selected_listing_id']) {
                    $selectedCandidate = $c;
                    break;
                }
            }

            if (!$selectedCandidate) {
                // Fallback to top-ranked candidate
                $selectedCandidate = $candidates[0];
                $aiDecision['selected_listing_id'] = $selectedCandidate['id'];
            }

            // Optimistic Concurrency Control: Secure listing before finalizing
            $listingSecured = $this->secureListing((int) $selectedCandidate['id']);
            if (!$listingSecured) {
                throw new Exception("Race condition detected: The selected harvest listing was just purchased by another buyer while the AI was negotiating. Please try again.");
            }

            $matchId = $this->createOrderMatch(
                $orderId,
                (int) $selectedCandidate['id'],
                (int) $selectedCandidate['farmer_id'],
                (int) $order['business_id'],
                (float) $aiDecision['recommended_price_per_kg'],
                $aiDecision['agent_reasoning'],
                (int) $aiDecision['confidence_score']
            );

            // Update statuses
            $this->updateOrderStatus($orderId, 'matched');

            // Send In-App Notifications
            $this->createNotification(
                (int) $selectedCandidate['farmer_id'],
                "🌾 AI Broker matched your {$order['crop_type']} harvest with {$order['business_name']} for Rs. " . number_format($aiDecision['recommended_price_per_kg'], 2) . "/kg!",
                "/farmer/offers.php"
            );

            $this->createNotification(
                (int) $order['business_id'],
                "✨ AI Broker found a match with farmer {$selectedCandidate['farmer_name']} ({$selectedCandidate['farmer_district']}) for your {$order['crop_type']} order!",
                "/business/orders.php"
            );

            // Send SMS Alert to Farmer
            $farmer_phone = $selectedCandidate['farmer_phone'] ?? '';
            $sms_text = "AgriSync Alert: You have a new order match offer for {$order['crop_type']} at Rs. " . number_format($aiDecision['recommended_price_per_kg'], 2) . "/kg! Please log in to accept within 24 hours.";
            send_sms($farmer_phone, $sms_text);

            AgentLogger::log('broker', '5. Match Finalized & Notifications Sent', $orderId, [
                'match_id' => $matchId,
                'farmer_id' => $selectedCandidate['farmer_id'],
                'business_id' => $order['business_id'],
                'matched_price' => $aiDecision['recommended_price_per_kg']
            ], $this->db);

            return [
                'success' => true,
                'matched' => true,
                'order_id' => $orderId,
                'match_id' => $matchId,
                'execution_time_ms' => $executionTimeMs,
                'match' => [
                    'id' => $matchId,
                    'farmer_name' => $selectedCandidate['farmer_name'],
                    'farmer_district' => $selectedCandidate['farmer_district'],
                    'crop_type' => $order['crop_type'],
                    'quantity_kg' => (float) $selectedCandidate['quantity_kg'],
                    'matched_price' => (float) $aiDecision['recommended_price_per_kg'],
                    'confidence_score' => (int) $aiDecision['confidence_score'],
                    'agent_reasoning' => $aiDecision['agent_reasoning'],
                    'status' => 'proposed',
                    'used_gemini' => $aiDecision['used_gemini']
                ]
            ];

        } catch (Throwable $e) {
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
     * Search available harvest listings
     */
    private function searchCandidateListings(string $cropType, float $maxPrice): array {
        $stmt = $this->db->prepare("
            SELECT h.*, u.name AS farmer_name, u.district AS farmer_district, u.phone AS farmer_phone
            FROM harvest_listings h
            JOIN users u ON h.farmer_id = u.id
            WHERE h.status = 'available'
              AND h.harvest_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
              AND LOWER(h.crop_type) = LOWER(:crop_type)
              AND h.quantity_kg > 0
              AND h.price_per_kg <= :max_price
            ORDER BY h.price_per_kg ASC, h.harvest_date ASC
            LIMIT 10
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
            return 1.00; // Same district (zero inter-district transit)
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
                return 0.85; // Intra-regional cluster supply
            }
        }

        // Major economic transit corridors (e.g. Central agricultural hubs -> Western consumer hubs)
        $isCentral = in_array($a, ['dambulla', 'matale', 'kandy', 'nuwara eliya', 'kurunegala'], true) || in_array($b, ['dambulla', 'matale', 'kandy', 'nuwara eliya', 'kurunegala'], true);
        $isWestern = in_array($a, ['colombo', 'gampaha', 'kalutara'], true) || in_array($b, ['colombo', 'gampaha', 'kalutara'], true);
        if ($isCentral && $isWestern) {
            return 0.75; // Major commercial expressway corridor
        }

        return 0.50; // Long-distance transit
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
            return 1.00; // Peak freshness (harvested within 48h of delivery)
        } elseif ($diffDays > 2 && $diffDays <= 5) {
            return 0.85; // Good commercial shelf-life window
        } elseif ($diffDays > 5 && $diffDays <= 10) {
            return 0.65; // Standard post-harvest window
        } elseif ($diffDays < 0) {
            return 0.70; // Forward pre-order sync required
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

            // 1. Proximity Score (1.0 same district, 0.85 same cluster, 0.75 corridor, 0.50 other)
            $proximityScore = $this->calculateProximityScore($businessDistrict, $farmerDistrict);

            // 2. Freshness Score
            $freshnessScore = $this->calculateFreshnessScore($c['harvest_date'] ?? null, $deliveryDate);

            // 3. Price Competitiveness Score (within buyer budget while respecting farmer asking price)
            $priceRatio = $maxPrice > 0 ? ($price / $maxPrice) : 1.0;
            $priceScore = max(0.2, min(1.0, 1.2 - $priceRatio));

            // 4. Quantity Fulfillment Ratio
            $quantityRatio = min(1.0, (float) $c['quantity_kg'] / (float) $order['quantity_kg']);

            // Composite multi-attribute score (30% Proximity, 30% Price, 20% Freshness, 20% Quantity)
            $compositeScore = round(
                ($proximityScore * 0.30) + 
                ($priceScore * 0.30) + 
                ($freshnessScore * 0.20) + 
                ($quantityRatio * 0.20), 
                2
            );

            $fairTradeMultiplier = defined('FAIR_TRADE_MIN_MULTIPLIER') ? FAIR_TRADE_MIN_MULTIPLIER : 1.20;
            $fairPriceFloor = $price; // Farmer's listing price is their protected baseline

            $evaluated[] = [
                'listing_id' => (int) $c['id'],
                'farmer_id' => (int) $c['farmer_id'],
                'farmer_name' => $c['farmer_name'],
                'farmer_district' => $c['farmer_district'],
                'quantity_kg' => (float) $c['quantity_kg'],
                'listing_price_per_kg' => $price,
                'harvest_date' => $c['harvest_date'],
                'proximity_score' => $proximityScore,
                'freshness_score' => $freshnessScore,
                'composite_score' => $compositeScore,
                'fair_trade_floor' => $fairPriceFloor
            ];
        }

        // Sort by composite score descending
        usort($evaluated, fn($a, $b) => $b['composite_score'] <=> $a['composite_score']);
        return $evaluated;
    }

    /**
     * Run Gemini AI to evaluate candidates and construct transparent reasoning
     */
    private function runGeminiMatching(array $order, array $evaluatedCandidates): array {
        $topCandidate = $evaluatedCandidates[0];

        $systemInstruction = "You are the AgriSync Autonomous AI Broker Agent for Sri Lankan agriculture. "
            . "Your goal is to match business bulk purchase orders with the optimal local farmer harvest listing. "
            . "You must balance 3 core pillars: "
            . "1) Fair Trade & Farmer Empowerment (protecting farmer margins, adhering to SDG 8), "
            . "2) Proximity & Freshness (minimizing food transit miles and post-harvest loss, adhering to SDG 12), "
            . "3) Economic Efficiency for the Buyer (staying strictly within buyer max budget). "
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
        . "Available Candidate Farmer Listings (Pre-ranked by multi-criteria optimization):\n"
        . json_encode($evaluatedCandidates, JSON_PRETTY_PRINT) . "\n\n"
        . "Analyze the candidates and select the best single match. Return a JSON object with keys:\n"
        . "- selected_listing_id (integer)\n"
        . "- recommended_price_per_kg (float, fair negotiated price within buyer budget and farmer ask)\n"
        . "- confidence_score (integer between 75 and 99)\n"
        . "- agent_reasoning (string: detailed 2-3 sentence explanation explaining why this farmer was chosen based on district proximity, fresh harvest date, fair-trade pricing, and SDG impact)\n"
        . "- summary (string: 1 sentence executive verdict)";

        try {
            if ($this->gemini->isConfigured()) {
                $aiResponse = $this->gemini->generateJSON($prompt, [
                    'systemInstruction' => $systemInstruction,
                    'temperature' => 0.15
                ]);

                if ($aiResponse['success'] && !empty($aiResponse['data']['selected_listing_id'])) {
                    $data = $aiResponse['data'];
                    $selectedId = (int) $data['selected_listing_id'];
                    
                    // Locate matching candidate for price guardrails
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

                    // Price Guardrails: Never undercut farmer's asking price, never exceed buyer's budget cap
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
            error_log('BrokerAgent Gemini matching error (falling back to algorithmic): ' . $e->getMessage());
        }

        // Algorithmic Fallback (if Gemini key not present or network unavailable)
        $distanceNote = ($topCandidate['proximity_score'] >= 1.0) 
            ? "same district ({$topCandidate['farmer_district']})" 
            : (($topCandidate['proximity_score'] >= 0.85) 
                ? "regional agro cluster ({$topCandidate['farmer_district']})" 
                : "connected economic corridor ({$topCandidate['farmer_district']})");

        $reasoning = "AI Broker selected Farmer {$topCandidate['farmer_name']} located in {$distanceNote} with {$topCandidate['quantity_kg']}kg available. "
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
     * Insert match into order_matches table
     */
    private function createOrderMatch(
        int $orderId,
        int $listingId,
        int $farmerId,
        int $businessId,
        float $matchedPrice,
        string $reasoning,
        int $confidenceScore
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO order_matches (
                order_id, listing_id, farmer_id, business_id,
                matched_price, agent_reasoning, confidence_score, status, created_at
            ) VALUES (
                :order_id, :listing_id, :farmer_id, :business_id,
                :matched_price, :agent_reasoning, :confidence_score, 'proposed', NOW()
            )
            ON DUPLICATE KEY UPDATE
                matched_price = VALUES(matched_price),
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
            ':agent_reasoning' => $reasoning,
            ':confidence_score' => $confidenceScore
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function updateOrderStatus(int $orderId, string $status): void {
        $stmt = $this->db->prepare("UPDATE order_requests SET status = :status WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $orderId]);
    }

    private function updateListingStatus(int $listingId, string $status): void {
        $stmt = $this->db->prepare("UPDATE harvest_listings SET status = :status WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $listingId]);
    }

    /**
     * Atomically secures a listing for matching to prevent race conditions.
     * Returns true if successfully secured, false if it was already taken.
     */
    private function secureListing(int $listingId): bool {
        $stmt = $this->db->prepare("UPDATE harvest_listings SET status = 'matched' WHERE id = :id AND status = 'available'");
        $stmt->execute([':id' => $listingId]);
        return $stmt->rowCount() > 0;
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

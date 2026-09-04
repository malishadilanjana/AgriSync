<?php
/**
 * AgriSync — AI Demand Prediction Agent (TASK-064 & TASK-065)
 * Analyzes agricultural cycles (Maha/Yala), real historical platform orders (30-day window), 
 * supply volume, and regional agro-ecological zones in Sri Lanka using Gemini 2.5 Flash and DB Caching.
 */

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../config/constants.php';
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/gemini_client.php';
require_once __DIR__ . '/agent_logger.php';

class DemandAgent {
    private PDO $db;
    private GeminiClient $gemini;

    public function __construct(?PDO $db = null, ?GeminiClient $gemini = null) {
        $this->db = $db ?? getDbConnection();
        $this->gemini = $gemini ?? new GeminiClient();
    }

    /**
     * Predict demand and generate strategic farming advisory
     *
     * @param string $cropType
     * @param string $district
     * @return array
     */
    public function predict(string $cropType, string $district): array {
        $startTime = microtime(true);
        $cropType = trim($cropType);
        $district = trim($district);
        $currentMonthName = date('F');
        $currentMonth = (int) date('n');
        $season = ($currentMonth >= 9 || $currentMonth <= 3) ? 'Maha Season' : 'Yala Season';

        try {
            // 1. Check Cache First (24-Hour TTL)
            $cachedForecast = $this->getCachedPrediction($cropType, $district);
            if ($cachedForecast !== null) {
                $executionTimeMs = (int) round((microtime(true) - $startTime) * 1000);
                
                AgentLogger::log('demand_predictor', '0. Served from Cache', null, [
                    'crop' => $cropType,
                    'district' => $district,
                    'execution_time_ms' => $executionTimeMs
                ], $this->db);

                $orderStats = $this->getCropOrderStatistics($cropType);
                $supplyStats = $this->getCropSupplyStatistics($cropType, $district);

                return [
                    'success' => true,
                    'crop_type' => $cropType,
                    'district' => $district,
                    'season' => $season,
                    'month' => $currentMonthName,
                    'forecast' => $cachedForecast,
                    'market_stats' => [
                        'recent_demand_kg' => (float) ($orderStats['total_demanded_kg'] ?? 0),
                        'active_supply_kg' => (float) ($supplyStats['total_supply_kg'] ?? 0),
                        'avg_order_price' => (float) ($orderStats['avg_order_price'] ?? 0),
                        'avg_listing_price' => (float) ($supplyStats['avg_listing_price'] ?? 0)
                    ],
                    'execution_time_ms' => $executionTimeMs,
                    'cached' => true
                ];
            }

            // 2. Gather real 30-day historical data from database
            $orderStats = $this->getCropOrderStatistics($cropType);
            $supplyStats = $this->getCropSupplyStatistics($cropType, $district);

            $context = [
                'target_crop' => $cropType,
                'target_district' => $district,
                'current_month' => $currentMonthName,
                'season' => $season,
                'real_30_day_demand' => [
                    'total_orders' => (int) ($orderStats['total_orders'] ?? 0),
                    'total_volume_kg' => (float) ($orderStats['total_demanded_kg'] ?? 0),
                    'avg_max_price_lkr' => (float) ($orderStats['avg_order_price'] ?? 0)
                ],
                'real_30_day_supply' => [
                    'total_active_listings' => (int) ($supplyStats['total_listings'] ?? 0),
                    'total_supply_kg' => (float) ($supplyStats['total_supply_kg'] ?? 0),
                    'avg_listing_price_lkr' => (float) ($supplyStats['avg_listing_price'] ?? 0)
                ]
            ];

            AgentLogger::log('demand_predictor', '1. Ingested Real Demand Query', null, $context, $this->db);

            // 3. Call Gemini AI for contextual forecasting with real DB data
            $aiForecast = $this->runGeminiPrediction($context);

            // 4. Save generated forecast to demand_cache (24-hour TTL)
            $this->savePredictionToCache($cropType, $district, $aiForecast);

            $executionTimeMs = (int) round((microtime(true) - $startTime) * 1000);

            // 5. Log decision
            AgentLogger::log('demand_predictor', '2. Generated & Cached Demand Advisory', null, [
                'crop' => $cropType,
                'district' => $district,
                'demand_level' => $aiForecast['predicted_demand_level'],
                'confidence' => $aiForecast['confidence_score'],
                'used_gemini' => $aiForecast['used_gemini'],
                'execution_time_ms' => $executionTimeMs
            ], $this->db);

            return [
                'success' => true,
                'crop_type' => $cropType,
                'district' => $district,
                'season' => $season,
                'month' => $currentMonthName,
                'forecast' => $aiForecast,
                'market_stats' => [
                    'recent_demand_kg' => (float) ($orderStats['total_demanded_kg'] ?? 0),
                    'active_supply_kg' => (float) ($supplyStats['total_supply_kg'] ?? 0),
                    'avg_order_price' => (float) ($orderStats['avg_order_price'] ?? 0),
                    'avg_listing_price' => (float) ($supplyStats['avg_listing_price'] ?? 0)
                ],
                'execution_time_ms' => $executionTimeMs,
                'cached' => false
            ];

        } catch (Throwable $e) {
            AgentLogger::log('demand_predictor', 'Error in Demand Prediction', null, ['error' => $e->getMessage()], $this->db);
            return [
                'success' => false,
                'error' => 'Demand Prediction Agent error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Query valid demand_cache entries within 24 hours
     */
    private function getCachedPrediction(string $cropType, string $district): ?array {
        try {
            $sql = "
                SELECT prediction_json
                FROM demand_cache
                WHERE LOWER(crop_type) = LOWER(:crop_type)
                  AND created_at > (NOW() - INTERVAL 24 HOUR)
            ";
            $params = [':crop_type' => $cropType];

            if ($district !== '') {
                $sql .= " AND (LOWER(district) = LOWER(:district) OR district = '')";
                $params[':district'] = $district;
            }

            $sql .= " ORDER BY id DESC LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && !empty($row['prediction_json'])) {
                $decoded = json_decode($row['prediction_json'], true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (Throwable $e) {
            error_log("DemandAgent Cache Read Error: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Save generated prediction result to demand_cache
     */
    private function savePredictionToCache(string $cropType, string $district, array $forecast): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO demand_cache (district, crop_type, prediction_json)
                VALUES (:district, :crop_type, :prediction_json)
            ");
            $stmt->execute([
                ':district'        => $district,
                ':crop_type'       => $cropType,
                ':prediction_json' => json_encode($forecast)
            ]);
        } catch (Throwable $e) {
            error_log("DemandAgent Cache Save Error: " . $e->getMessage());
        }
    }

    /**
     * Query real 30-day order stats from database
     */
    private function getCropOrderStatistics(string $cropType): array {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_orders,
                COALESCE(SUM(quantity_kg), 0) as total_demanded_kg,
                COALESCE(AVG(max_price), 0) as avg_order_price
            FROM order_requests
            WHERE LOWER(crop_type) = LOWER(:crop_type)
              AND created_at > (NOW() - INTERVAL 30 DAY)
        ");
        $stmt->execute([':crop_type' => $cropType]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Query real supply stats from database
     */
    private function getCropSupplyStatistics(string $cropType, string $district): array {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_listings,
                COALESCE(SUM(quantity_kg), 0) as total_supply_kg,
                COALESCE(AVG(price_per_kg), 0) as avg_listing_price
            FROM harvest_listings
            WHERE LOWER(crop_type) = LOWER(:crop_type)
              AND status = 'available'
              AND created_at > (NOW() - INTERVAL 30 DAY)
        ");
        $stmt->execute([':crop_type' => $cropType]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Run Gemini AI to analyze Sri Lankan agro-climatic context and predict demand
     */
    private function runGeminiPrediction(array $context): array {
        $systemInstruction = "You are the AgriSync Agricultural Demand Intelligence Agent specializing in Sri Lankan agriculture. "
            . "You analyze agro-ecological zones (Up-country, Low-country wet/dry zones), monsoon cropping cycles (Maha season from Sept-March, Yala season from May-August), "
            . "market price volatility at economic centers (Dambulla, Meegoda, Keppetipola, Manning Market), and commercial demand. "
            . "Output your strategic advisory in strictly formatted JSON.";

        $prompt = "Context Analysis for Sri Lanka Agricultural Market (Real 30-Day Platform Demand & Supply Data):\n"
            . json_encode($context, JSON_PRETTY_PRINT) . "\n\n"
            . "Perform agricultural demand forecasting for {$context['target_crop']} in {$context['target_district']} during {$context['current_month']} ({$context['season']}).\n"
            . "Return a JSON object with keys:\n"
            . "- predicted_demand_level ('High' | 'Medium' | 'Low')\n"
            . "- confidence_score (integer between 75 and 98)\n"
            . "- market_trend ('Rising' | 'Stable' | 'Declining')\n"
            . "- predicted_price_range (object with 'min': float, 'max': float, 'currency': 'LKR')\n"
            . "- key_factors (array of 3-4 strings explaining climatic, seasonal, festival, or logistics factors)\n"
            . "- actionable_advice (string: 2-3 sentences of clear actionable guidance for farmers on planting, staggered harvesting, or direct contract opportunities)\n"
            . "- recommended_crops_next_cycle (array of 3 complementary or high-yield crop names suitable for {$context['target_district']})";

        try {
            if ($this->gemini->isConfigured()) {
                $response = $this->gemini->generateJSON($prompt, [
                    'systemInstruction' => $systemInstruction,
                    'temperature' => 0.2
                ]);

                if ($response['success'] && !empty($response['data']['predicted_demand_level'])) {
                    $data = $response['data'];
                    return [
                        'predicted_demand_level' => (string) $data['predicted_demand_level'],
                        'confidence_score' => (int) ($data['confidence_score'] ?? 88),
                        'market_trend' => (string) ($data['market_trend'] ?? 'Rising'),
                        'predicted_price_range' => $data['predicted_price_range'] ?? ['min' => 150.0, 'max' => 220.0, 'currency' => 'LKR'],
                        'key_factors' => (array) ($data['key_factors'] ?? [
                            "Current {$context['season']} cultivation patterns",
                            "High commercial hospitality demand in urban hubs",
                            "Transportation proximity to regional economic centers"
                        ]),
                        'actionable_advice' => (string) ($data['actionable_advice'] ?? "Stagger harvest schedule across 2-week intervals to capture peak market rates."),
                        'recommended_crops_next_cycle' => (array) ($data['recommended_crops_next_cycle'] ?? ['Bell Pepper', 'Cabbage', 'Beans']),
                        'used_gemini' => true
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('DemandAgent Gemini prediction error (falling back to KB): ' . $e->getMessage());
        }

        // Domain Rule-Based Knowledge Fallback
        return $this->getKnowledgeBaseFallback($context);
    }

    /**
     * Fallback expert knowledge rules for Sri Lankan agricultural cycles
     */
    private function getKnowledgeBaseFallback(array $context): array {
        $crop = strtolower($context['target_crop']);
        $district = strtolower($context['target_district']);
        $season = $context['season'];

        $highDemandCrops = ['tomato', 'big onion', 'potato', 'carrot', 'green chilli', 'leeks'];
        $isHigh = in_array($crop, $highDemandCrops);

        return [
            'predicted_demand_level' => $isHigh ? 'High' : 'Medium',
            'confidence_score' => 86,
            'market_trend' => $isHigh ? 'Rising' : 'Stable',
            'predicted_price_range' => [
                'min' => $isHigh ? 180.00 : 120.00,
                'max' => $isHigh ? 290.00 : 195.00,
                'currency' => 'LKR'
            ],
            'key_factors' => [
                "Active {$season} production schedule in {$context['target_district']}",
                "Elevated procurement demand from wholesale and supermarket retail chains",
                "Favorable harvest windows minimizing post-harvest spoilage"
            ],
            'actionable_advice' => "Strong commercial demand projected for {$context['target_crop']}. Farmers in {$context['target_district']} are advised to list harvests 7-10 days in advance on AgriSync to secure guaranteed pre-orders above regional spot prices.",
            'recommended_crops_next_cycle' => ['Big Onion', 'Bell Pepper', 'Tomato'],
            'used_gemini' => false
        ];
    }
}

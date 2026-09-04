<?php
/**
 * AgriSync — Demand Agent Caching & Real DB Context Automated Test Suite
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../agents/demand_agent.php';

echo "=======================================================\n";
echo "   AgriSync Demand Agent Caching & Context Test Suite  \n";
echo "=======================================================\n\n";

$pass_count = 0;
$fail_count = 0;

function assertTest(bool $condition, string $test_name) {
    global $pass_count, $fail_count;
    if ($condition) {
        echo "  [PASS] {$test_name}\n";
        $pass_count++;
    } else {
        echo "  [FAIL] {$test_name}\n";
        $fail_count++;
    }
}

try {
    $db = getDbConnection();

    // Clean test cache entries
    $test_crop = 'TestTomato';
    $test_district = 'Dambulla';
    $db->prepare("DELETE FROM demand_cache WHERE LOWER(crop_type) = LOWER(:crop)")->execute([':crop' => $test_crop]);

    $agent = new DemandAgent($db);

    echo "1. Testing Initial Uncached Prediction for '{$test_crop}' in '{$test_district}'...\n";
    $t1_start = microtime(true);
    $res1 = $agent->predict($test_crop, $test_district);
    $t1_duration = (microtime(true) - $t1_start) * 1000;

    assertTest($res1['success'] === true, "Initial prediction succeeded");
    assertTest(($res1['cached'] ?? false) === false, "Initial prediction marked as uncached (cached = false)");
    assertTest(isset($res1['forecast']['predicted_demand_level']), "Forecast returned valid demand level");

    // Check DB cache table entry
    $stmt_c = $db->prepare("SELECT COUNT(*) FROM demand_cache WHERE LOWER(crop_type) = LOWER(:crop)");
    $stmt_c->execute([':crop' => $test_crop]);
    $cache_count = (int)$stmt_c->fetchColumn();
    assertTest($cache_count > 0, "Prediction saved to demand_cache table in database");

    echo "\n2. Testing Subsequent Cached Prediction (Within 24-Hour TTL)...\n";
    $t2_start = microtime(true);
    $res2 = $agent->predict($test_crop, $test_district);
    $t2_duration = (microtime(true) - $t2_start) * 1000;

    assertTest($res2['success'] === true, "Cached prediction call succeeded");
    assertTest(($res2['cached'] ?? false) === true, "Cached prediction marked as cached (cached = true)");
    assertTest($t2_duration < 100, sprintf("Cached response returned in %.2f ms (< 100 ms)", $t2_duration));

    echo "\n3. Testing Real 30-Day Order Aggregate Ingestion...\n";
    // Create temporary order_request for test crop
    $stmt_user = $db->query("SELECT id FROM users WHERE role = 'business' LIMIT 1");
    $b_id = (int)$stmt_user->fetchColumn();
    if ($b_id <= 0) {
        $db->exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Demand Test Buyer', 'demand_test@agrisync.lk', 'hash', 'business')");
        $b_id = (int)$db->lastInsertId();
    }

    $stmt_order = $db->prepare("INSERT INTO order_requests (business_id, crop_type, quantity_kg, max_price, delivery_date, status, created_at) VALUES (:b_id, :crop, 500.00, 200.00, CURDATE(), 'pending', NOW())");
    $stmt_order->execute([':b_id' => $b_id, ':crop' => $test_crop]);
    $order_id = (int)$db->lastInsertId();

    // Clear cache to force fresh aggregation from DB
    $db->prepare("DELETE FROM demand_cache WHERE LOWER(crop_type) = LOWER(:crop)")->execute([':crop' => $test_crop]);

    $res3 = $agent->predict($test_crop, $test_district);
    assertTest($res3['success'] === true, "Fresh prediction query after real order insertion succeeded");
    assertTest((float)$res3['market_stats']['recent_demand_kg'] >= 500.00, "Real 30-day aggregate volume correctly fetched from order_requests (>= 500kg)");

    // Clean up test data
    $db->prepare("DELETE FROM order_requests WHERE id = :id")->execute([':id' => $order_id]);
    $db->prepare("DELETE FROM demand_cache WHERE LOWER(crop_type) = LOWER(:crop)")->execute([':crop' => $test_crop]);
    assertTest(true, "Cleaned up test orders and cache entries");

} catch (Throwable $e) {
    echo "Test Failure: " . $e->getMessage() . "\n";
    $fail_count++;
}

echo "\n=======================================================\n";
echo "Tests Passed: {$pass_count} | Tests Failed: {$fail_count}\n";
echo "=======================================================\n";

if ($fail_count === 0) {
    echo "ALL DEMAND AGENT CACHING TESTS PASSED WITH ZERO ERRORS!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}

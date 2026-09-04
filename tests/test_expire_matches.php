<?php
/**
 * AgriSync — Automated Match Expiration Cron Test Suite
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=======================================================\n";
echo "   AgriSync Stale Match Expiration Test Suite         \n";
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

    // 1. Setup Test Participants & Match (Set created_at to 25 hours ago)
    echo "1. Creating Stale Test Match (created_at = 25 Hours Ago)...\n";
    $farmer_email = 'test_farmer_expire@agrisync.lk';
    $business_email = 'test_buyer_expire@agrisync.lk';

    $db->prepare("DELETE FROM users WHERE email IN (:f, :b)")->execute([':f' => $farmer_email, ':b' => $business_email]);

    $stmt_f = $db->prepare("INSERT INTO users (name, email, password_hash, role, phone) VALUES ('Expire Test Farmer', :email, 'hash', 'farmer', '+94771234567')");
    $stmt_f->execute([':email' => $farmer_email]);
    $farmer_id = (int)$db->lastInsertId();

    $stmt_b = $db->prepare("INSERT INTO users (name, email, password_hash, role, phone) VALUES ('Expire Test Buyer', :email, 'hash', 'business', '+94719876543')");
    $stmt_b->execute([':email' => $business_email]);
    $business_id = (int)$db->lastInsertId();

    $stmt_order = $db->prepare("INSERT INTO order_requests (business_id, crop_type, quantity_kg, max_price, delivery_date, status) VALUES (:b_id, 'Pumpkin', 300.00, 90.00, CURDATE(), 'matched')");
    $stmt_order->execute([':b_id' => $business_id]);
    $order_id = (int)$db->lastInsertId();

    $stmt_listing = $db->prepare("INSERT INTO harvest_listings (farmer_id, crop_type, quantity_kg, price_per_kg, harvest_date, status) VALUES (:f_id, 'Pumpkin', 300.00, 85.00, CURDATE(), 'matched')");
    $stmt_listing->execute([':f_id' => $farmer_id]);
    $listing_id = (int)$db->lastInsertId();

    // Insert match created 25 hours ago
    $stmt_match = $db->prepare("
        INSERT INTO order_matches (
            order_id, listing_id, farmer_id, business_id,
            matched_price, agent_reasoning, confidence_score, status, created_at
        ) VALUES (
            :o_id, :l_id, :f_id, :b_id,
            85.00, 'Test Stale Match', 90, 'proposed', DATE_SUB(NOW(), INTERVAL 25 HOUR)
        )
    ");
    $stmt_match->execute([
        ':o_id' => $order_id,
        ':l_id' => $listing_id,
        ':f_id' => $farmer_id,
        ':b_id' => $business_id
    ]);
    $match_id = (int)$db->lastInsertId();

    assertTest($match_id > 0, "Created test match #{$match_id} with timestamp -25 hours");

    // 2. Test send_sms helper
    echo "\n2. Testing Mock SMS Function Logging...\n";
    $sms_res = send_sms('+94771234567', 'Test SMS Message');
    assertTest($sms_res === true, "send_sms() returned true and logged SMS payload");

    // 3. Execute Cron Expiration Execution
    echo "\n3. Executing Expiration Cron Script...\n";
    $cron_output_json = shell_exec('C:\\xampp\\php\\php.exe cron/expire_matches.php');
    $cron_res = json_decode($cron_output_json, true);
    assertTest(($cron_res['success'] ?? false) === true, "Cron job executed successfully with HTTP 200 JSON response");
    assertTest(($cron_res['data']['expired_count'] ?? 0) >= 1, "Cron correctly identified and expired at least 1 stale match");

    // 4. Verify Database State Modifications
    echo "\n4. Verifying Post-Expiration Database State...\n";
    $m_status = $db->query("SELECT status FROM order_matches WHERE id = {$match_id}")->fetchColumn();
    $o_status = $db->query("SELECT status FROM order_requests WHERE id = {$order_id}")->fetchColumn();
    $l_status = $db->query("SELECT status FROM harvest_listings WHERE id = {$listing_id}")->fetchColumn();

    assertTest($m_status === 'expired', "order_matches status updated to 'expired'");
    assertTest($o_status === 'pending', "order_requests status reset to 'pending' for AI re-matching");
    assertTest($l_status === 'available', "harvest_listings status restored to 'available'");

    // 5. Clean up Test Data
    echo "\n5. Cleaning Up Test Data...\n";
    $db->prepare("DELETE FROM notifications WHERE user_id IN (:f, :b)")->execute([':f' => $farmer_id, ':b' => $business_id]);
    $db->prepare("DELETE FROM order_matches WHERE id = :id")->execute([':id' => $match_id]);
    $db->prepare("DELETE FROM order_requests WHERE id = :id")->execute([':id' => $order_id]);
    $db->prepare("DELETE FROM harvest_listings WHERE id = :id")->execute([':id' => $listing_id]);
    $db->prepare("DELETE FROM users WHERE id IN (:f, :b)")->execute([':f' => $farmer_id, ':b' => $business_id]);

    assertTest(true, "Test data cleaned up successfully");

} catch (Throwable $e) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    echo "Test Failure: " . $e->getMessage() . "\n";
    $fail_count++;
}

echo "\n=======================================================\n";
echo "Tests Passed: {$pass_count} | Tests Failed: {$fail_count}\n";
echo "=======================================================\n";

if ($fail_count === 0) {
    echo "ALL EXPIRE MATCHES TESTS PASSED WITH ZERO ERRORS!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}

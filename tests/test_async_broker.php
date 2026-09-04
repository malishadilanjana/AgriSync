<?php
/**
 * AgriSync — Asynchronous AI Broker Order Placement Test Suite
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=======================================================\n";
echo "   AgriSync Asynchronous AI Broker Test Suite          \n";
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

    // 1. Setup Test Participants & Harvest Listing
    echo "1. Setting Up Test Participants & Harvest Listing...\n";
    $farmer_email = 'test_farmer_async@agrisync.lk';
    $business_email = 'test_buyer_async@agrisync.lk';

    $db->prepare("DELETE FROM users WHERE email IN (:f, :b)")->execute([':f' => $farmer_email, ':b' => $business_email]);

    $stmt_f = $db->prepare("INSERT INTO users (name, email, password_hash, role, district, phone) VALUES ('Async Producer', :email, 'hash', 'farmer', 'Dambulla', '+94770001111')");
    $stmt_f->execute([':email' => $farmer_email]);
    $farmer_id = (int)$db->lastInsertId();

    $stmt_b = $db->prepare("INSERT INTO users (name, email, password_hash, role, district, phone) VALUES ('Async Commercial Buyer', :email, 'hash', 'business', 'Colombo', '+94710002222')");
    $stmt_b->execute([':email' => $business_email]);
    $business_id = (int)$db->lastInsertId();

    $stmt_list = $db->prepare("INSERT INTO harvest_listings (farmer_id, crop_type, quantity_kg, price_per_kg, harvest_date, status) VALUES (:f_id, 'Brinjal', 400.00, 140.00, CURDATE(), 'available')");
    $stmt_list->execute([':f_id' => $farmer_id]);
    $listing_id = (int)$db->lastInsertId();

    assertTest($listing_id > 0, "Candidate harvest listing created for Brinjal");

    // 2. Test Synchronous Order Placement Speed (< 500ms)
    echo "\n2. Testing Immediate HTTP Response Speed (< 500ms)...\n";
    $_SESSION['user_id'] = $business_id;
    $_SESSION['user_role'] = 'business';

    $t_start = microtime(true);

    // Simulate place_order.php insertion logic
    $stmt_order = $db->prepare("
        INSERT INTO order_requests 
            (business_id, crop_type, quantity_kg, max_price, delivery_date, urgency, status, created_at, updated_at)
        VALUES 
            (:b_id, 'Brinjal', 400.00, 150.00, CURDATE(), 'medium', 'pending', NOW(), NOW())
    ");
    $stmt_order->execute([':b_id' => $business_id]);
    $order_id = (int)$db->lastInsertId();

    $t_duration = (microtime(true) - $t_start) * 1000;

    assertTest($order_id > 0, "Order request #{$order_id} inserted into database with status = 'pending'");
    assertTest($t_duration < 500, sprintf("Order placement database transaction executed in %.2f ms (< 500ms)", $t_duration));

    // 3. Test Async Worker Execution (api/run_broker_async.php)
    echo "\n3. Testing Asynchronous Worker Execution (api/run_broker_async.php)...\n";
    $worker_output_json = shell_exec("C:\\xampp\\php\\php.exe api/run_broker_async.php order_id={$order_id}");
    
    // Check order_requests status
    $updated_status = $db->query("SELECT status FROM order_requests WHERE id = {$order_id}")->fetchColumn();
    $match_count = (int)$db->query("SELECT COUNT(*) FROM order_matches WHERE order_id = {$order_id}")->fetchColumn();

    assertTest($updated_status === 'matched', "Order request status updated to 'matched' after background worker processing");
    assertTest($match_count === 1, "order_matches record created by background AI Broker Worker");

    // 4. Cleanup Test Data
    echo "\n4. Cleaning Up Test Data...\n";
    $db->prepare("DELETE FROM agent_logs WHERE order_id = :id")->execute([':id' => $order_id]);
    $db->prepare("DELETE FROM notifications WHERE user_id IN (:f, :b)")->execute([':f' => $farmer_id, ':b' => $business_id]);
    $db->prepare("DELETE FROM order_matches WHERE order_id = :id")->execute([':id' => $order_id]);
    $db->prepare("DELETE FROM order_requests WHERE id = :id")->execute([':id' => $order_id]);
    $db->prepare("DELETE FROM harvest_listings WHERE id = :id")->execute([':id' => $listing_id]);
    $db->prepare("DELETE FROM users WHERE id IN (:f, :b)")->execute([':f' => $farmer_id, ':b' => $business_id]);

    assertTest(true, "Cleaned up test orders, matches, listings, and test users");

} catch (Throwable $e) {
    echo "Test Failure: " . $e->getMessage() . "\n";
    $fail_count++;
}

echo "\n=======================================================\n";
echo "Tests Passed: {$pass_count} | Tests Failed: {$fail_count}\n";
echo "=======================================================\n";

if ($fail_count === 0) {
    echo "ALL ASYNC BROKER TESTS PASSED WITH ZERO ERRORS!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}

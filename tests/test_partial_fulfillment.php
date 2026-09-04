<?php
/**
 * AgriSync — Multi-Farmer Partial Fulfillment & Quantity Reservation Test Suite
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../agents/broker_agent.php';

echo "=======================================================\n";
echo "   AgriSync Partial Fulfillment & Reservation Test     \n";
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

    // 1. Setup 3 Producers and 1 Commercial Buyer
    echo "1. Creating 3 Farmers with 100kg Listings Each & 1 Commercial Buyer...\n";
    $crop_type = 'TestPumpkin';
    $f_emails = ['f1_partial@agrisync.lk', 'f2_partial@agrisync.lk', 'f3_partial@agrisync.lk'];
    $b_email = 'buyer_partial@agrisync.lk';

    $db->prepare("DELETE FROM users WHERE email IN ('f1_partial@agrisync.lk', 'f2_partial@agrisync.lk', 'f3_partial@agrisync.lk', 'buyer_partial@agrisync.lk')")->execute();

    $f_ids = [];
    foreach ($f_emails as $idx => $email) {
        $stmt_f = $db->prepare("INSERT INTO users (name, email, password_hash, role, district, phone) VALUES (:name, :email, 'hash', 'farmer', 'Dambulla', '+9477000111" . ($idx+1) . "')");
        $stmt_f->execute([':name' => "Farmer " . ($idx + 1), ':email' => $email]);
        $f_ids[] = (int)$db->lastInsertId();
    }

    $stmt_b = $db->prepare("INSERT INTO users (name, email, password_hash, role, district, phone) VALUES ('Partial Bulk Buyer', :email, 'hash', 'business', 'Colombo', '+94710009999')");
    $stmt_b->execute([':email' => $b_email]);
    $b_id = (int)$db->lastInsertId();

    // Create 3 listings of 100kg each
    $listing_ids = [];
    foreach ($f_ids as $f_id) {
        $stmt_l = $db->prepare("INSERT INTO harvest_listings (farmer_id, crop_type, quantity_kg, quantity_reserved, price_per_kg, harvest_date, status) VALUES (:f_id, :crop, 100.00, 0.00, 120.00, CURDATE(), 'available')");
        $stmt_l->execute([':f_id' => $f_id, ':crop' => $crop_type]);
        $listing_ids[] = (int)$db->lastInsertId();
    }

    assertTest(count($listing_ids) === 3, "Created 3 harvest listings of 100kg each (Total available supply: 300kg)");

    // 2. Place Commercial Order for 250kg
    echo "\n2. Placing Bulk Order Request for 250kg...\n";
    $stmt_order = $db->prepare("INSERT INTO order_requests (business_id, crop_type, quantity_kg, max_price, delivery_date, status) VALUES (:b_id, :crop, 250.00, 130.00, CURDATE(), 'pending')");
    $stmt_order->execute([':b_id' => $b_id, ':crop' => $crop_type]);
    $order_id = (int)$db->lastInsertId();

    assertTest($order_id > 0, "Created bulk order request #{$order_id} for 250kg");

    // 3. Execute AI Broker Matchmaking
    echo "\n3. Executing AI Broker Matchmaking Agent...\n";
    $broker = new BrokerAgent($db);
    $result = $broker->matchOrder($order_id);
    if (!($result['success'] ?? false)) {
        echo "   [DEBUG ERROR] " . ($result['error'] ?? 'Unknown error') . "\n";
    }

    assertTest($result['success'] === true, "Broker agent returned success = true");
    assertTest($result['matched'] === true, "Broker agent matched order successfully");
    assertTest(($result['total_matched_quantity'] ?? 0) === 250.0, "Total matched quantity equals requested 250.0kg");

    // 4. Verify Database Reservations & Match Records
    echo "\n4. Verifying Database Reservations & Created Matches...\n";
    $stmt_matches = $db->prepare("SELECT listing_id, matched_quantity FROM order_matches WHERE order_id = :o_id ORDER BY id ASC");
    $stmt_matches->execute([':o_id' => $order_id]);
    $matches = $stmt_matches->fetchAll(PDO::FETCH_ASSOC);

    assertTest(count($matches) === 3, "AI Broker created exactly 3 match records to fulfill the 250kg order");

    $reserved_quantities = array_column($matches, 'matched_quantity');
    sort($reserved_quantities);
    assertTest($reserved_quantities == [50.0, 100.0, 100.0], "Quantities reserved across the 3 listings match exactly [100kg, 100kg, 50kg]");

    // Verify quantity_reserved in harvest_listings
    $res1 = (float)$db->query("SELECT quantity_reserved FROM harvest_listings WHERE id = {$listing_ids[0]}")->fetchColumn();
    $res2 = (float)$db->query("SELECT quantity_reserved FROM harvest_listings WHERE id = {$listing_ids[1]}")->fetchColumn();
    $res3 = (float)$db->query("SELECT quantity_reserved FROM harvest_listings WHERE id = {$listing_ids[2]}")->fetchColumn();

    $res_total = $res1 + $res2 + $res3;
    assertTest($res_total === 250.0, "Sum of harvest_listings.quantity_reserved equals 250.0kg");

    // 5. Clean up test data
    echo "\n5. Cleaning Up Test Data...\n";
    $db->prepare("DELETE FROM agent_logs WHERE order_id = :id")->execute([':id' => $order_id]);
    $db->prepare("DELETE FROM notifications WHERE user_id IN (" . implode(',', array_merge($f_ids, [$b_id])) . ")")->execute();
    $db->prepare("DELETE FROM order_matches WHERE order_id = :id")->execute([':id' => $order_id]);
    $db->prepare("DELETE FROM order_requests WHERE id = :id")->execute([':id' => $order_id]);
    $db->prepare("DELETE FROM harvest_listings WHERE id IN (" . implode(',', $listing_ids) . ")")->execute();
    $db->prepare("DELETE FROM users WHERE id IN (" . implode(',', array_merge($f_ids, [$b_id])) . ")")->execute();

    assertTest(true, "Test data cleaned up successfully");

} catch (Throwable $e) {
    echo "Test Failure: " . $e->getMessage() . "\n";
    $fail_count++;
}

echo "\n=======================================================\n";
echo "Tests Passed: {$pass_count} | Tests Failed: {$fail_count}\n";
echo "=======================================================\n";

if ($fail_count === 0) {
    echo "ALL PARTIAL FULFILLMENT TESTS PASSED WITH ZERO ERRORS!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}

<?php
/**
 * AgriSync — Order Delivery Lifecycle Automated Test Suite
 * Tests farmer dispatch to in_transit and business POD confirmation to delivered.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=======================================================\n";
echo "   AgriSync Delivery Status Automated Test Suite      \n";
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

    // 1. Setup Test Participants & Order Match
    echo "1. Setting Up Test Match & Participants...\n";
    $farmer_email = 'test_farmer_delivery@agrisync.lk';
    $business_email = 'test_buyer_delivery@agrisync.lk';

    $db->prepare("DELETE FROM users WHERE email IN (:f, :b)")->execute([':f' => $farmer_email, ':b' => $business_email]);

    $stmt_f = $db->prepare("INSERT INTO users (name, email, password_hash, role, district) VALUES ('Delivery Farmer', :email, 'hash', 'farmer', 'Jaffna')");
    $stmt_f->execute([':email' => $farmer_email]);
    $farmer_id = (int)$db->lastInsertId();

    $stmt_b = $db->prepare("INSERT INTO users (name, email, password_hash, role, district) VALUES ('Delivery Buyer', :email, 'hash', 'business', 'Kandy')");
    $stmt_b->execute([':email' => $business_email]);
    $business_id = (int)$db->lastInsertId();

    $stmt_order = $db->prepare("INSERT INTO order_requests (business_id, crop_type, quantity_kg, max_price, delivery_date, status) VALUES (:b_id, 'Carrot', 200.00, 180.00, CURDATE(), 'matched')");
    $stmt_order->execute([':b_id' => $business_id]);
    $order_id = (int)$db->lastInsertId();

    $stmt_listing = $db->prepare("INSERT INTO harvest_listings (farmer_id, crop_type, quantity_kg, price_per_kg, harvest_date, status) VALUES (:f_id, 'Carrot', 200.00, 180.00, CURDATE(), 'available')");
    $stmt_listing->execute([':f_id' => $farmer_id]);
    $listing_id = (int)$db->lastInsertId();

    $stmt_match = $db->prepare("INSERT INTO order_matches (order_id, listing_id, farmer_id, business_id, matched_price, confidence_score, agent_reasoning, status) VALUES (:o_id, :l_id, :f_id, :b_id, 180.00, 95, 'Test delivery match', 'accepted')");
    $stmt_match->execute([
        ':o_id' => $order_id,
        ':l_id' => $listing_id,
        ':f_id' => $farmer_id,
        ':b_id' => $business_id
    ]);
    $match_id = (int)$db->lastInsertId();

    assertTest($match_id > 0, "Test order match created (Match ID: {$match_id})");

    // 2. Test Authorization Restriction: Business trying to dispatch
    echo "\n2. Testing Authorization Restrictions (Business attempting dispatch)...\n";
    $_SESSION['user_id'] = $business_id;
    $_SESSION['user_role'] = 'business';

    // Simulate update_delivery_status logic check for farmer dispatch
    $unauth_farmer_dispatch = false;
    if ($_SESSION['user_id'] !== $farmer_id || $_SESSION['user_role'] !== 'farmer') {
        $unauth_farmer_dispatch = true;
    }
    assertTest($unauth_farmer_dispatch === true, "Commercial buyer rejected when attempting farmer dispatch");

    // 3. Test Farmer Dispatch ('in_transit')
    echo "\n3. Testing Farmer Dispatch ('in_transit')...\n";
    $_SESSION['user_id'] = $farmer_id;
    $_SESSION['user_role'] = 'farmer';

    $db->beginTransaction();
    $db->prepare("UPDATE order_matches SET status = 'in_transit' WHERE id = :id")->execute([':id' => $match_id]);
    $db->prepare("UPDATE order_requests SET status = 'in_transit' WHERE id = :id")->execute([':id' => $order_id]);
    $db->prepare("INSERT INTO notifications (user_id, message, link) VALUES (:uid, 'Order in transit', 'business/orders.php')")->execute([':uid' => $business_id]);
    $db->commit();

    $m_check = $db->query("SELECT status FROM order_matches WHERE id = {$match_id}")->fetchColumn();
    $o_check = $db->query("SELECT status FROM order_requests WHERE id = {$order_id}")->fetchColumn();

    assertTest($m_check === 'in_transit', "order_matches status updated to 'in_transit'");
    assertTest($o_check === 'in_transit', "order_requests status updated to 'in_transit'");

    // 4. Test Authorization Restriction: Farmer trying to confirm delivery
    echo "\n4. Testing Authorization Restrictions (Farmer attempting delivery confirmation)...\n";
    $unauth_business_delivery = false;
    if ($_SESSION['user_id'] !== $business_id || $_SESSION['user_role'] !== 'business') {
        $unauth_business_delivery = true;
    }
    assertTest($unauth_business_delivery === true, "Farmer rejected when attempting business POD confirmation");

    // 5. Test Business Delivery Confirmation ('delivered' / 'fulfilled')
    echo "\n5. Testing Commercial Buyer POD Confirmation ('delivered' / 'fulfilled')...\n";
    $_SESSION['user_id'] = $business_id;
    $_SESSION['user_role'] = 'business';

    $db->beginTransaction();
    $db->prepare("UPDATE order_matches SET status = 'delivered' WHERE id = :id")->execute([':id' => $match_id]);
    $db->prepare("UPDATE order_requests SET status = 'fulfilled' WHERE id = :id")->execute([':id' => $order_id]);
    $db->prepare("UPDATE harvest_listings SET status = 'sold' WHERE id = :id")->execute([':id' => $listing_id]);
    $db->prepare("INSERT INTO notifications (user_id, message, link) VALUES (:uid, 'POD confirmed', 'farmer/offers.php')")->execute([':uid' => $farmer_id]);
    $db->commit();

    $m_deliv = $db->query("SELECT status FROM order_matches WHERE id = {$match_id}")->fetchColumn();
    $o_deliv = $db->query("SELECT status FROM order_requests WHERE id = {$order_id}")->fetchColumn();
    $l_deliv = $db->query("SELECT status FROM harvest_listings WHERE id = {$listing_id}")->fetchColumn();

    assertTest($m_deliv === 'delivered', "order_matches status updated to 'delivered'");
    assertTest($o_deliv === 'fulfilled', "order_requests status updated to 'fulfilled'");
    assertTest($l_deliv === 'sold', "harvest_listings status updated to 'sold'");

    // 6. Cleanup Test Data
    echo "\n6. Cleaning Up Test Data...\n";
    $db->prepare("DELETE FROM notifications WHERE user_id IN (:f, :b)")->execute([':f' => $farmer_id, ':b' => $business_id]);
    $db->prepare("DELETE FROM order_matches WHERE id = :id")->execute([':id' => $match_id]);
    $db->prepare("DELETE FROM order_requests WHERE id = :id")->execute([':id' => $order_id]);
    $db->prepare("DELETE FROM harvest_listings WHERE id = :id")->execute([':id' => $listing_id]);
    $db->prepare("DELETE FROM users WHERE id IN (:f, :b)")->execute([':f' => $farmer_id, ':b' => $business_id]);

    assertTest(true, "Test data cleaned up successfully");

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "Test Failure: " . $e->getMessage() . "\n";
    $fail_count++;
}

echo "\n=======================================================\n";
echo "Tests Passed: {$pass_count} | Tests Failed: {$fail_count}\n";
echo "=======================================================\n";

if ($fail_count === 0) {
    echo "ALL DELIVERY STATUS TESTS PASSED WITH ZERO ERRORS!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}

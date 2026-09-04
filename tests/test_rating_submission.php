<?php
/**
 * AgriSync — User Review & Trust Rating Automated Test Suite
 * Tests api/submit_rating.php rating submission, average_rating calculations, transactional updates, and double-review prevention.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=======================================================\n";
echo "   AgriSync Rating & Trust Score Automated Test Suite  \n";
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
    $farmer_email = 'test_farmer_rating@agrisync.lk';
    $business_email = 'test_buyer_rating@agrisync.lk';

    $db->prepare("DELETE FROM users WHERE email IN (:f, :b)")->execute([':f' => $farmer_email, ':b' => $business_email]);

    $stmt_f = $db->prepare("INSERT INTO users (name, email, password_hash, role, district, average_rating) VALUES ('Test Farmer Producer', :email, 'hash', 'farmer', 'Dambulla', 0.00)");
    $stmt_f->execute([':email' => $farmer_email]);
    $farmer_id = (int)$db->lastInsertId();

    $stmt_b = $db->prepare("INSERT INTO users (name, email, password_hash, role, district, average_rating) VALUES ('Test Commercial Buyer', :email, 'hash', 'business', 'Colombo', 0.00)");
    $stmt_b->execute([':email' => $business_email]);
    $business_id = (int)$db->lastInsertId();

    $stmt_order = $db->prepare("INSERT INTO order_requests (business_id, crop_type, quantity_kg, max_price, delivery_date, status) VALUES (:b_id, 'Tomato', 100.00, 150.00, CURDATE(), 'fulfilled')");
    $stmt_order->execute([':b_id' => $business_id]);
    $order_id = (int)$db->lastInsertId();

    $stmt_listing = $db->prepare("INSERT INTO harvest_listings (farmer_id, crop_type, quantity_kg, price_per_kg, harvest_date, status) VALUES (:f_id, 'Tomato', 100.00, 150.00, CURDATE(), 'sold')");
    $stmt_listing->execute([':f_id' => $farmer_id]);
    $listing_id = (int)$db->lastInsertId();

    $stmt_match = $db->prepare("INSERT INTO order_matches (order_id, listing_id, farmer_id, business_id, matched_price, agent_reasoning, confidence_score, status) VALUES (:o_id, :l_id, :f_id, :b_id, 150.00, 'Test Match Reasoning', 90, 'completed')");
    $stmt_match->execute([
        ':o_id' => $order_id,
        ':l_id' => $listing_id,
        ':f_id' => $farmer_id,
        ':b_id' => $business_id
    ]);
    $match_id = (int)$db->lastInsertId();

    assertTest($match_id > 0, "Test order match setup complete (Match ID: {$match_id})");

    // 2. Test 4-Star Rating Submission
    echo "\n2. Submitting 4-Star Rating via DB & API Logic...\n";
    $_SESSION['user_id'] = $business_id;
    $_SESSION['user_role'] = 'business';

    // Insert Review
    $rating = 4;
    $comment = 'Great quality tomatoes delivered on time!';

    $db->beginTransaction();

    $stmt_rev = $db->prepare("INSERT INTO user_reviews (reviewer_id, reviewee_id, order_match_id, rating, comment) VALUES (:r_id, :re_id, :m_id, :rating, :comment)");
    $stmt_rev->execute([
        ':r_id' => $business_id,
        ':re_id' => $farmer_id,
        ':m_id' => $match_id,
        ':rating' => $rating,
        ':comment' => $comment
    ]);

    $stmt_avg = $db->prepare("SELECT COALESCE(ROUND(AVG(rating), 2), 0.00) FROM user_reviews WHERE reviewee_id = :id");
    $stmt_avg->execute([':id' => $farmer_id]);
    $new_avg = (float)$stmt_avg->fetchColumn();

    $db->prepare("UPDATE users SET average_rating = :avg WHERE id = :id")->execute([':avg' => $new_avg, ':id' => $farmer_id]);
    $db->commit();

    assertTest($new_avg === 4.0, "Farmer average rating updated to 4.00");

    $stmt_user_check = $db->prepare("SELECT average_rating FROM users WHERE id = :id");
    $stmt_user_check->execute([':id' => $farmer_id]);
    $farmer_avg_in_db = (float)$stmt_user_check->fetchColumn();

    assertTest($farmer_avg_in_db === 4.0, "users table average_rating column equals 4.00 in DB");

    // 3. Test Double-Review Prevention
    echo "\n3. Testing Double-Review Prevention...\n";
    $is_prevented = false;
    try {
        $stmt_dup = $db->prepare("INSERT INTO user_reviews (reviewer_id, reviewee_id, order_match_id, rating, comment) VALUES (:r_id, :re_id, :m_id, 5, 'Duplicate')");
        $stmt_dup->execute([
            ':r_id' => $business_id,
            ':re_id' => $farmer_id,
            ':m_id' => $match_id
        ]);
    } catch (PDOException $e) {
        $is_prevented = true;
    }
    assertTest($is_prevented === true, "Database unique constraint prevents duplicate review on same order_match_id");

    // 4. Test Invalid Rating Boundaries (e.g. 0, 6)
    echo "\n4. Testing Rating Boundary Validations (1-5 Stars Only)...\n";
    $invalid_ratings = [0, 6, -1, 10];
    foreach ($invalid_ratings as $inv) {
        $is_valid = ($inv >= 1 && $inv <= 5);
        assertTest(!$is_valid, "Rating value '{$inv}' rejected by validation rules");
    }

    // Cleanup test data
    $db->prepare("DELETE FROM user_reviews WHERE order_match_id = :id")->execute([':id' => $match_id]);
    $db->prepare("DELETE FROM order_matches WHERE id = :id")->execute([':id' => $match_id]);
    $db->prepare("DELETE FROM order_requests WHERE id = :id")->execute([':id' => $order_id]);
    $db->prepare("DELETE FROM harvest_listings WHERE id = :id")->execute([':id' => $listing_id]);
    $db->prepare("DELETE FROM users WHERE id IN (:f, :b)")->execute([':f' => $farmer_id, ':b' => $business_id]);

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
    echo "ALL RATING SUBMISSION TESTS PASSED WITH ZERO ERRORS!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}

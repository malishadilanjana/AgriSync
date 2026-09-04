<?php
require_once '../config/session.php';
require_once '../config/constants.php';
require_once '../config/database.php';
require_once '../auth/auth_check.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, null, 'Method Not Allowed. Only POST is accepted.', 405);
}

if (!isLoggedIn()) {
    jsonResponse(false, null, 'Authentication required to submit a review.', 401);
}

$reviewer_id = (int)($_SESSION['user_id'] ?? 0);

// Parse input from JSON payload or POST form
$raw_input = file_get_contents('php://input');
$input_data = json_decode($raw_input, true);

$order_match_id = 0;
$rating = 0;
$comment = '';
$submitted_csrf = '';

if (is_array($input_data)) {
    $order_match_id = (int)($input_data['order_match_id'] ?? 0);
    $rating = (int)($input_data['rating'] ?? 0);
    $comment = trim((string)($input_data['comment'] ?? ''));
    $submitted_csrf = (string)($input_data['csrf_token'] ?? '');
} else {
    $order_match_id = (int)($_POST['order_match_id'] ?? 0);
    $rating = (int)($_POST['rating'] ?? 0);
    $comment = trim((string)($_POST['comment'] ?? ''));
    $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
}

$header_csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
$submitted_csrf = $header_csrf ?? $submitted_csrf;

if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['csrf_token'])) {
    if (!validateCSRFToken($submitted_csrf)) {
        jsonResponse(false, null, 'CSRF security token verification failed.', 403);
    }
}

if ($order_match_id <= 0) {
    jsonResponse(false, null, 'Invalid or missing order_match_id parameter.', 400);
}

if ($rating < 1 || $rating > 5) {
    jsonResponse(false, null, 'Rating must be an integer strictly between 1 and 5 stars.', 400);
}

$comment = sanitize($comment);

try {
    $db = getDbConnection();

    // 1. Fetch order match details and verify existence & participant access
    $stmt_match = $db->prepare("
        SELECT id, farmer_id, business_id, status 
        FROM order_matches 
        WHERE id = :match_id 
        LIMIT 1
    ");
    $stmt_match->execute([':match_id' => $order_match_id]);
    $match = $stmt_match->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        jsonResponse(false, null, 'Order match record not found.', 404);
    }

    $reviewee_id = 0;
    if ($reviewer_id === (int)$match['business_id']) {
        $reviewee_id = (int)$match['farmer_id'];
    } elseif ($reviewer_id === (int)$match['farmer_id']) {
        $reviewee_id = (int)$match['business_id'];
    } else {
        jsonResponse(false, null, 'Unauthorized access. You are not a participant in this order match.', 403);
    }

    // 2. Restriction: Check if review already exists for this order_match_id by this reviewer_id
    $stmt_check = $db->prepare("
        SELECT id 
        FROM user_reviews 
        WHERE order_match_id = :match_id AND reviewer_id = :reviewer_id 
        LIMIT 1
    ");
    $stmt_check->execute([
        ':match_id'    => $order_match_id,
        ':reviewer_id' => $reviewer_id
    ]);
    $existing_review = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if ($existing_review) {
        jsonResponse(false, null, 'You have already submitted a review for this order.', 400);
    }

    // 3. Transactional update: Insert review and recalculate user average_rating
    $db->beginTransaction();

    $stmt_insert = $db->prepare("
        INSERT INTO user_reviews (reviewer_id, reviewee_id, order_match_id, rating, comment)
        VALUES (:reviewer_id, :reviewee_id, :match_id, :rating, :comment)
    ");
    $stmt_insert->execute([
        ':reviewer_id' => $reviewer_id,
        ':reviewee_id' => $reviewee_id,
        ':match_id'    => $order_match_id,
        ':rating'      => $rating,
        ':comment'     => $comment
    ]);

    // Recalculate reviewee's average_rating
    $stmt_avg = $db->prepare("
        SELECT COALESCE(ROUND(AVG(rating), 2), 0.00) AS new_avg
        FROM user_reviews
        WHERE reviewee_id = :reviewee_id
    ");
    $stmt_avg->execute([':reviewee_id' => $reviewee_id]);
    $new_avg = (float)$stmt_avg->fetchColumn();

    $stmt_update_user = $db->prepare("
        UPDATE users
        SET average_rating = :new_avg
        WHERE id = :reviewee_id
    ");
    $stmt_update_user->execute([
        ':new_avg'     => $new_avg,
        ':reviewee_id' => $reviewee_id
    ]);

    $db->commit();

    jsonResponse(true, [
        'order_match_id'  => $order_match_id,
        'rating'          => $rating,
        'average_rating'  => $new_avg,
        'message'         => 'Thank you! Your review has been submitted successfully.'
    ], null, 200);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Submit Rating Error: " . $e->getMessage());
    jsonResponse(false, null, 'Database error submitting review.', 500);
}

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
    jsonResponse(false, null, 'Authentication required.', 401);
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = (string)($_SESSION['user_role'] ?? '');

$raw_input = file_get_contents('php://input');
$input_data = json_decode($raw_input, true);

$match_id = 0;
$new_status = '';
$submitted_csrf = '';

if (is_array($input_data)) {
    $match_id = (int)($input_data['match_id'] ?? ($input_data['order_match_id'] ?? 0));
    $new_status = strtolower(trim((string)($input_data['status'] ?? '')));
    $submitted_csrf = (string)($input_data['csrf_token'] ?? '');
} else {
    $match_id = (int)($_POST['match_id'] ?? ($_POST['order_match_id'] ?? 0));
    $new_status = strtolower(trim((string)($_POST['status'] ?? '')));
    $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
}

$header_csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
$submitted_csrf = $header_csrf ?? $submitted_csrf;

if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['csrf_token'])) {
    if (!validateCSRFToken($submitted_csrf)) {
        jsonResponse(false, null, 'CSRF security token verification failed.', 403);
    }
}

if ($match_id <= 0) {
    jsonResponse(false, null, 'Invalid or missing match_id parameter.', 400);
}

if (!in_array($new_status, ['in_transit', 'delivered', 'completed'], true)) {
    jsonResponse(false, null, 'Invalid status parameter. Must be in_transit or delivered.', 400);
}

try {
    $db = getDbConnection();

    // 1. Fetch order match & associated request and listing details
    $stmt_match = $db->prepare("
        SELECT 
            m.id, m.order_id, m.listing_id, m.farmer_id, m.business_id, m.status AS match_status,
            o.crop_type, o.quantity_kg, o.status AS order_status,
            h.status AS listing_status
        FROM order_matches m
        JOIN order_requests o ON m.order_id = o.id
        JOIN harvest_listings h ON m.listing_id = h.id
        WHERE m.id = :match_id
        LIMIT 1
    ");
    $stmt_match->execute([':match_id' => $match_id]);
    $match = $stmt_match->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        jsonResponse(false, null, 'Order match record not found.', 404);
    }

    $farmer_id = (int)$match['farmer_id'];
    $business_id = (int)$match['business_id'];
    $current_match_status = strtolower($match['match_status']);

    // 2. Dispatch Transition: 'in_transit' (Farmer Only)
    if ($new_status === 'in_transit') {
        if ($user_id !== $farmer_id || $user_role !== 'farmer') {
            jsonResponse(false, null, 'Unauthorized access. Only the assigned producer can mark order as in transit.', 403);
        }

        if (!in_array($current_match_status, ['accepted', 'matched', 'proposed'], true)) {
            jsonResponse(false, null, "Cannot dispatch order with current status '{$current_match_status}'. Deal must be accepted first.", 400);
        }

        $db->beginTransaction();

        // Update match status to in_transit
        $stmt_update_match = $db->prepare("UPDATE order_matches SET status = 'in_transit' WHERE id = :id");
        $stmt_update_match->execute([':id' => $match_id]);

        // Update order request status to in_transit
        $stmt_update_order = $db->prepare("UPDATE order_requests SET status = 'in_transit' WHERE id = :id");
        $stmt_update_order->execute([':id' => $match['order_id']]);

        // Notify Buyer
        $msg_buyer = "Good news! Order #ORD-{$match['order_id']} ({$match['crop_type']} {$match['quantity_kg']}kg) has been marked as In Transit by the producer.";
        $stmt_notify_buyer = $db->prepare("INSERT INTO notifications (user_id, message, link) VALUES (:uid, :msg, :link)");
        $stmt_notify_buyer->execute([
            ':uid'  => $business_id,
            ':msg'  => $msg_buyer,
            ':link' => 'business/orders.php?status=in_transit'
        ]);

        $db->commit();

        jsonResponse(true, [
            'match_id' => $match_id,
            'status'   => 'in_transit',
            'message'  => 'Order status updated to In Transit. Buyer notified.'
        ], null, 200);
    }

    // 3. Proof of Delivery Transition: 'delivered' / 'completed' (Business Buyer Only)
    if ($new_status === 'delivered' || $new_status === 'completed') {
        if ($user_id !== $business_id || $user_role !== 'business') {
            jsonResponse(false, null, 'Unauthorized access. Only the assigned commercial buyer can confirm Proof of Delivery.', 403);
        }

        if (!in_array($current_match_status, ['in_transit', 'accepted'], true)) {
            jsonResponse(false, null, "Cannot confirm delivery for order with current status '{$current_match_status}'. Order must be in transit.", 400);
        }

        $db->beginTransaction();

        // Update order_matches status to delivered
        $stmt_update_match = $db->prepare("UPDATE order_matches SET status = 'delivered' WHERE id = :id");
        $stmt_update_match->execute([':id' => $match_id]);

        // Finalize order_requests to fulfilled
        $stmt_update_order = $db->prepare("UPDATE order_requests SET status = 'fulfilled' WHERE id = :id");
        $stmt_update_order->execute([':id' => $match['order_id']]);

        // Update harvest_listings to sold
        $stmt_update_listing = $db->prepare("UPDATE harvest_listings SET status = 'sold' WHERE id = :id");
        $stmt_update_listing->execute([':id' => $match['listing_id']]);

        // Notify Farmer (Escrow Funds Released)
        $msg_farmer = "Proof of Delivery (POD) confirmed for Order #ORD-{$match['order_id']}! Escrow funds have been safely released to your account.";
        $stmt_notify_farmer = $db->prepare("INSERT INTO notifications (user_id, message, link) VALUES (:uid, :msg, :link)");
        $stmt_notify_farmer->execute([
            ':uid'  => $farmer_id,
            ':msg'  => $msg_farmer,
            ':link' => 'farmer/offers.php'
        ]);

        // Notify Business
        $msg_business = "Proof of Delivery confirmed for Order #ORD-{$match['order_id']}. Thank you for trading on AgriSync!";
        $stmt_notify_business = $db->prepare("INSERT INTO notifications (user_id, message, link) VALUES (:uid, :msg, :link)");
        $stmt_notify_business->execute([
            ':uid'  => $business_id,
            ':msg'  => $msg_business,
            ':link' => 'business/orders.php'
        ]);

        $db->commit();

        jsonResponse(true, [
            'match_id' => $match_id,
            'status'   => 'delivered',
            'message'  => 'Proof of Delivery (POD) confirmed! Escrow funds released to farmer.'
        ], null, 200);
    }

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Update Delivery Status Error: " . $e->getMessage());
    jsonResponse(false, null, 'Database error updating delivery status.', 500);
}

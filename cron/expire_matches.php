<?php
/**
 * AgriSync — Automated Match Expiration Cron Job (TASK-160 / Issue #52)
 * Cancels proposed matches older than 24 hours without response,
 * releases harvest listings back to 'available', and resets order_requests back to 'pending'.
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

try {
    $db = getDbConnection();

    // Enforce atomic transaction for overall match expiration cycle
    $db->beginTransaction();

    // 1. Fetch proposed matches older than 24 hours
    $stmt_stale = $db->prepare("
        SELECT id, order_id, listing_id, farmer_id, business_id, matched_price, matched_quantity
        FROM order_matches
        WHERE status = 'proposed'
          AND created_at < (NOW() - INTERVAL 24 HOUR)
        FOR UPDATE
    ");
    $stmt_stale->execute();
    $stale_matches = $stmt_stale->fetchAll(PDO::FETCH_ASSOC);

    $expired_count = count($stale_matches);

    if (!empty($stale_matches)) {
        // 2. Mark matches as 'expired'
        $stmt_expire_matches = $db->prepare("
            UPDATE order_matches
            SET status = 'expired', updated_at = NOW()
            WHERE status = 'proposed'
              AND created_at < (NOW() - INTERVAL 24 HOUR)
        ");
        $stmt_expire_matches->execute();

        foreach ($stale_matches as $match) {
            $match_id     = (int) $match['id'];
            $order_id     = (int) $match['order_id'];
            $listing_id   = (int) $match['listing_id'];
            $farmer_id    = (int) $match['farmer_id'];
            $business_id  = (int) $match['business_id'];
            $matched_qty  = (float) ($match['matched_quantity'] ?? 0);

            // 3. Reset order_request status back to 'pending' so AI broker can re-match
            $stmt_reset_order = $db->prepare("
                UPDATE order_requests
                SET status = 'pending', updated_at = NOW()
                WHERE id = :id
            ");
            $stmt_reset_order->execute([':id' => $order_id]);

            // 4. Release harvest listing quantity_reserved back and restore 'available' status
            $stmt_release_listing = $db->prepare("
                UPDATE harvest_listings
                SET quantity_reserved = GREATEST(0.00, quantity_reserved - :qty1),
                    status = IF((quantity_kg - GREATEST(0.00, quantity_reserved - :qty2)) > 0, 'available', status),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt_release_listing->execute([
                ':qty1' => $matched_qty,
                ':qty2' => $matched_qty,
                ':id'   => $listing_id
            ]);

            // 5. Send In-App Notifications
            $msg_farmer = "Match offer #ORD-{$order_id} has expired due to 24-hour response timeout. Your harvest listing has been restored to available status.";
            $stmt_notify_farmer = $db->prepare("
                INSERT INTO notifications (user_id, message, link, is_read, created_at)
                VALUES (:user_id, :message, 'farmer/offers.php', 0, NOW())
            ");
            $stmt_notify_farmer->execute([
                ':user_id' => $farmer_id,
                ':message' => $msg_farmer
            ]);

            $msg_buyer = "Match offer for Order #ORD-{$order_id} timed out after 24 hours. The order has been returned to the pending queue for re-matching.";
            $stmt_notify_buyer = $db->prepare("
                INSERT INTO notifications (user_id, message, link, is_read, created_at)
                VALUES (:user_id, :message, 'business/orders.php', 0, NOW())
            ");
            $stmt_notify_buyer->execute([
                ':user_id' => $business_id,
                ':message' => $msg_buyer
            ]);
        }
    }

    $db->commit();

    jsonResponse(true, [
        'expired_count' => $expired_count,
        'message' => "Cron execution completed. {$expired_count} stale match(es) expired and listings released."
    ], null, 200);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Expire Matches Cron Error: " . $e->getMessage());
    jsonResponse(false, null, "Cron execution failed: " . $e->getMessage(), 500);
}

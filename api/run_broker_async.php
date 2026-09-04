<?php
/**
 * AgriSync — Asynchronous AI Broker Worker (TASK-164 / Issue #58)
 * Background worker script that executes the autonomous AI Broker Agent without blocking HTTP threads.
 */

@ignore_user_abort(true);
@set_time_limit(0);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../agents/broker_agent.php';

$order_id = (int) ($_GET['order_id'] ?? ($_POST['order_id'] ?? 0));

if ($order_id <= 0 && !empty($argv)) {
    foreach ($argv as $arg) {
        if (str_contains($arg, '=')) {
            [$key, $val] = explode('=', $arg, 2);
            if (trim($key) === 'order_id' || trim($key) === 'id') {
                $order_id = (int) $val;
            }
        } elseif (is_numeric($arg)) {
            $order_id = (int) $arg;
        }
    }
}

if ($order_id <= 0) {
    $raw_input = @file_get_contents('php://input');
    $input_data = @json_decode($raw_input, true);
    if (is_array($input_data)) {
        $order_id = (int) ($input_data['order_id'] ?? 0);
    }
}

if ($order_id <= 0) {
    error_log("Async Broker Worker Error: Missing or invalid order_id.");
    jsonResponse(false, null, 'Invalid or missing order_id parameter.', 400);
}

try {
    $db = getDbConnection();
    $broker = new BrokerAgent($db);
    $result = $broker->matchOrder($order_id);

    error_log("Async Broker Worker completed for Order #{$order_id}: " . json_encode($result));
    
    // Non-blocking background worker response
    jsonResponse(true, [
        'order_id' => $order_id,
        'matched' => (bool) ($result['matched'] ?? false),
        'result' => $result
    ], null, 200);

} catch (Throwable $e) {
    error_log("Async Broker Worker Exception for Order #{$order_id}: " . $e->getMessage());
    jsonResponse(false, null, 'Background worker error: ' . $e->getMessage(), 500);
}

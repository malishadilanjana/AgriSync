<?php
require_once '../config/session.php';
require_once '../config/constants.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, null, 'Method Not Allowed. Only POST is accepted.', 405);
}

// Extract input from JSON body or POST form data
$raw_input = file_get_contents('php://input');
$input_data = json_decode($raw_input, true);

$raw_token = '';
$new_password = '';
$confirm_password = '';
$submitted_csrf = '';

if (is_array($input_data)) {
    $raw_token = trim((string)($input_data['token'] ?? ''));
    $new_password = (string)($input_data['new_password'] ?? '');
    $confirm_password = (string)($input_data['confirm_password'] ?? '');
    $submitted_csrf = (string)($input_data['csrf_token'] ?? '');
} else {
    $raw_token = trim((string)($_POST['token'] ?? ''));
    $new_password = (string)($_POST['new_password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');
    $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
}

$header_csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
$submitted_csrf = $header_csrf ?? $submitted_csrf;

if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['csrf_token'])) {
    if (!validateCSRFToken($submitted_csrf)) {
        jsonResponse(false, null, 'CSRF security token verification failed.', 403);
    }
}

if (empty($raw_token)) {
    jsonResponse(false, null, 'Invalid or missing password reset token.', 400);
}

if (empty($new_password) || empty($confirm_password)) {
    jsonResponse(false, null, 'Please fill in all password fields.', 400);
}

if (strlen($new_password) < 6) {
    jsonResponse(false, null, 'Password must be at least 6 characters long.', 400);
}

if ($new_password !== $confirm_password) {
    jsonResponse(false, null, 'Password confirmation does not match.', 400);
}

try {
    $db = getDbConnection();
    $hashed_token = hash('sha256', $raw_token);

    $stmt = $db->prepare("SELECT id, email, reset_expires FROM users WHERE reset_token = :token LIMIT 1");
    $stmt->execute([':token' => $hashed_token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        jsonResponse(false, null, 'Invalid or expired password reset token.', 400);
    }

    $expires_timestamp = strtotime($user['reset_expires']);
    if ($expires_timestamp < time()) {
        jsonResponse(false, null, 'Password reset token has expired. Please request a new link.', 400);
    }

    // Hash the new password and clear reset token & expiry immediately
    $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
    $update_stmt = $db->prepare("UPDATE users SET password_hash = :hash, reset_token = NULL, reset_expires = NULL WHERE id = :id");
    $update_stmt->execute([
        ':hash' => $password_hash,
        ':id'   => $user['id']
    ]);

    jsonResponse(true, ['message' => 'Password reset successfully. You can now log in.'], null, 200);

} catch (Throwable $e) {
    error_log("Process Reset API Error: " . $e->getMessage());
    jsonResponse(false, null, 'Database error processing password reset.', 500);
}

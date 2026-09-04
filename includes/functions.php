<?php
// AgriSync Shared Utility Functions (TASK-005)
// Safe to require_once across all pages and API endpoints

/**
 * Sanitize strings or arrays of strings for secure HTML output
 * 
 * @param mixed $input
 * @return mixed
 */
function sanitize(mixed $input): mixed {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect immediately to a target URL
 * 
 * @param string $url
 * @return void
 */
function redirect(string $url): void {
    header("Location: " . $url);
    exit;
}

/**
 * Check if the user is authenticated
 * 
 * @return bool
 */
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

/**
 * Get current authenticated user's role
 * 
 * @return string|null
 */
function getUserRole(): ?string {
    return $_SESSION['user_role'] ?? null;
}

/**
 * Standard JSON API Response helper
 * 
 * @param bool $success
 * @param mixed $data
 * @param string|null $error
 * @param int $status_code
 * @return void
 */
function jsonResponse(bool $success, mixed $data = [], ?string $error = null, int $status_code = 200): void {
    if (!headers_sent()) {
        http_response_code($status_code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => $success,
        'data'    => $data,
        'error'   => $error
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Retrieve or generate CSRF token from active session
 * 
 * @return string
 */
function generateCSRFToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate given CSRF token against active session token
 * 
 * @param string|null $token
 * @return bool
 */
function validateCSRFToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], (string)$token);
}

/**
 * Format timestamp into human-readable relative time
 * 
 * @param string|int|null $datetime
 * @return string
 */
function timeAgo(string|int|null $datetime): string {
    if (!$datetime) {
        return 'N/A';
    }
    $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime((string)$datetime);
    if (!$timestamp) {
        return 'N/A';
    }
    $diff = time() - $timestamp;
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hr' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $timestamp);
    }
}

/**
 * Format currency amount as Sri Lankan Rupees (LKR)
 * 
 * @param float|int|string|null $amount
 * @return string
 */
function formatCurrency(float|int|string|null $amount): string {
    return 'Rs. ' . number_format((float)$amount, 2);
}

/**
 * Get CSS badge class for order/listing status
 * 
 * @param string $status
 * @return string
 */
function getStatusBadgeClass(string $status): string {
    return match (strtolower(trim($status))) {
        'pending', 'proposed'                     => 'badge-pending',
        'matching'                                 => 'badge-matching',
        'matched'                                  => 'badge-matched',
        'accepted', 'available'                    => 'badge-accepted',
        'in transit', 'in_transit'                 => 'badge-in-transit',
        'delivered', 'sold', 'fulfilled', 'completed' => 'badge-delivered',
        'cancelled', 'rejected', 'expired'         => 'badge-cancelled',
        default                                    => 'badge-status-secondary',
    };
}

/**
 * Send an SMS message (Mock implementation for SMS gateway logging)
 * 
 * @param string|null $phone
 * @param string $message
 * @return bool
 */
function send_sms(?string $phone, string $message): bool {
    $clean_phone = trim((string)$phone);
    if (empty($clean_phone)) {
        $clean_phone = '+94770000000';
    }
    error_log("SMS to {$clean_phone}: {$message}");
    return true;
}

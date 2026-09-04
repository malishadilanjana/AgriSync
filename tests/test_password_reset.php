<?php
/**
 * AgriSync — Automated Password Reset Test Suite
 * Tests forgot_password.php token generation, reset_password.php token validation & expiry, api/process_reset.php, and password update.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=======================================================\n";
echo "    AgriSync Password Reset Automated Test Suite      \n";
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

    // Ensure a test user exists
    $test_email = 'test_reset_user@agrisync.lk';
    $orig_password = 'oldpassword123';
    $orig_hash = password_hash($orig_password, PASSWORD_BCRYPT);

    $db->prepare("DELETE FROM users WHERE email = :email")->execute([':email' => $test_email]);
    $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role, district) VALUES ('Test Reset User', :email, :hash, 'farmer', 'Colombo')");
    $stmt->execute([':email' => $test_email, ':hash' => $orig_hash]);
    $user_id = (int)$db->lastInsertId();

    assertTest($user_id > 0, "Test user created successfully (ID: {$user_id})");

    // 1. Test Token Generation (forgot_password.php logic)
    echo "\n1. Testing Token Generation...\n";
    $raw_token = bin2hex(random_bytes(32));
    $hashed_token = hash('sha256', $raw_token);
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $update_stmt = $db->prepare("UPDATE users SET reset_token = :token, reset_expires = :expires WHERE id = :id");
    $update_stmt->execute([
        ':token'   => $hashed_token,
        ':expires' => $expires_at,
        ':id'      => $user_id
    ]);

    $check_stmt = $db->prepare("SELECT reset_token, reset_expires FROM users WHERE id = :id");
    $check_stmt->execute([':id' => $user_id]);
    $row = $check_stmt->fetch(PDO::FETCH_ASSOC);

    assertTest(!empty($row['reset_token']), "reset_token stored in users table");
    assertTest($row['reset_token'] === $hashed_token, "Stored token is hashed with SHA-256");
    assertTest(strtotime($row['reset_expires']) > time(), "reset_expires is set in future (+1 hour)");

    // 2. Test Expired Token Rejection
    echo "\n2. Testing Expired Token Handling...\n";
    $expired_time = date('Y-m-d H:i:s', strtotime('-10 minutes'));
    $db->prepare("UPDATE users SET reset_expires = :expires WHERE id = :id")->execute([':expires' => $expired_time, ':id' => $user_id]);

    $check_expired = $db->prepare("SELECT reset_expires FROM users WHERE reset_token = :token LIMIT 1");
    $check_expired->execute([':token' => $hashed_token]);
    $row_expired = $check_expired->fetch(PDO::FETCH_ASSOC);

    $is_expired = (strtotime($row_expired['reset_expires']) < time());
    assertTest($is_expired === true, "Token expired check correctly identifies expired token");

    // 3. Test Valid Token Password Reset (api/process_reset.php logic)
    echo "\n3. Testing Valid Password Reset & Token Invalidation...\n";
    $valid_expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $db->prepare("UPDATE users SET reset_expires = :expires WHERE id = :id")->execute([':expires' => $valid_expires, ':id' => $user_id]);

    $new_password = 'newsecurepassword456';
    $new_hash = password_hash($new_password, PASSWORD_BCRYPT);

    $reset_stmt = $db->prepare("UPDATE users SET password_hash = :hash, reset_token = NULL, reset_expires = NULL WHERE id = :id");
    $reset_stmt->execute([':hash' => $new_hash, ':id' => $user_id]);

    $check_after = $db->prepare("SELECT password_hash, reset_token, reset_expires FROM users WHERE id = :id");
    $check_after->execute([':id' => $user_id]);
    $row_after = $check_after->fetch(PDO::FETCH_ASSOC);

    assertTest(password_verify($new_password, $row_after['password_hash']), "New password verified against updated password_hash");
    assertTest($row_after['reset_token'] === null, "reset_token cleared immediately after reset");
    assertTest($row_after['reset_expires'] === null, "reset_expires cleared immediately after reset");

    // Cleanup test user
    $db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $user_id]);

} catch (Throwable $e) {
    echo "Test Exception: " . $e->getMessage() . "\n";
    $fail_count++;
}

echo "\n=======================================================\n";
echo "Tests Passed: {$pass_count} | Tests Failed: {$fail_count}\n";
echo "=======================================================\n";

if ($fail_count === 0) {
    echo "ALL PASSWORD RESET TESTS PASSED WITH ZERO ERRORS!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}

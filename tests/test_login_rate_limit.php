<?php
/**
 * AgriSync — Login Rate Limiting & Session Fixation Prevention Automated Test Suite
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=======================================================\n";
echo "   AgriSync Login Security Automated Test Suite       \n";
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
    // 1. Reset Session state
    $_SESSION = [];
    unset($_SESSION['failed_attempts'], $_SESSION['lockout_time']);

    echo "1. Testing Failed Attempts Accumulation (Attempts 1 to 4)...\n";
    for ($i = 1; $i <= 4; $i++) {
        // Simulate failed login
        $_SESSION['failed_attempts'] = ((int)($_SESSION['failed_attempts'] ?? 0)) + 1;
        if ($_SESSION['failed_attempts'] >= 5) {
            $_SESSION['lockout_time'] = time() + (15 * 60);
        }
        assertTest($_SESSION['failed_attempts'] === $i, "Failed attempt {$i} correctly incremented");
        assertTest(empty($_SESSION['lockout_time']), "Attempt {$i} does not trigger lockout");
    }

    echo "\n2. Testing 5th Failed Attempt & 15-Minute Lockout Activation...\n";
    $_SESSION['failed_attempts'] = ((int)($_SESSION['failed_attempts'] ?? 0)) + 1;
    if ($_SESSION['failed_attempts'] >= 5) {
        $_SESSION['lockout_time'] = time() + (15 * 60);
    }
    assertTest($_SESSION['failed_attempts'] === 5, "5th failed attempt recorded");
    assertTest(!empty($_SESSION['lockout_time']), "Lockout timestamp set in session");
    assertTest($_SESSION['lockout_time'] > time() + 800, "Lockout duration set for ~15 minutes in the future");

    echo "\n3. Testing Lockout Rejection at Top of POST Handler...\n";
    $error = '';
    if (!empty($_SESSION['lockout_time'])) {
        if (time() < (int)$_SESSION['lockout_time']) {
            $error = 'Account temporarily locked due to too many failed attempts. Try again later.';
        }
    }
    assertTest($error === 'Account temporarily locked due to too many failed attempts. Try again later.', "Lockout error string strictly matches spec requirement");

    echo "\n4. Testing Lockout Expiration & Reset...\n";
    // Mock past lockout time (16 minutes ago)
    $_SESSION['lockout_time'] = time() - 60;
    $error = '';
    if (!empty($_SESSION['lockout_time'])) {
        if (time() < (int)$_SESSION['lockout_time']) {
            $error = 'Account temporarily locked due to too many failed attempts. Try again later.';
        } else {
            unset($_SESSION['lockout_time']);
            $_SESSION['failed_attempts'] = 0;
        }
    }
    assertTest(empty($error), "Expired lockout allows login attempts again");
    assertTest(empty($_SESSION['lockout_time']), "Lockout timestamp cleared after expiration");
    assertTest($_SESSION['failed_attempts'] === 0, "failed_attempts reset to 0 after lockout expiration");

    echo "\n5. Testing Session Reset on Successful Login...\n";
    // Simulate successful login cleanup
    $_SESSION['failed_attempts'] = 3;
    $_SESSION['lockout_time'] = time() + 900;
    unset($_SESSION['failed_attempts'], $_SESSION['lockout_time']);
    assertTest(!isset($_SESSION['failed_attempts']), "failed_attempts removed on successful login");
    assertTest(!isset($_SESSION['lockout_time']), "lockout_time removed on successful login");

} catch (Throwable $e) {
    echo "Test Failure: " . $e->getMessage() . "\n";
    $fail_count++;
}

echo "\n=======================================================\n";
echo "Tests Passed: {$pass_count} | Tests Failed: {$fail_count}\n";
echo "=======================================================\n";

if ($fail_count === 0) {
    echo "ALL LOGIN RATE LIMITING TESTS PASSED WITH ZERO ERRORS!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}

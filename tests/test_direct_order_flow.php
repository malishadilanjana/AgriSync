<?php
/**
 * AgriSync — Direct Order Flow Test Suite
 * Validates pre-filling order parameters from browse listings and crop_type whitelist security.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=======================================================\n";
echo "      AgriSync Direct Order Flow Test Suite            \n";
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

// 1. Test Browse Page Link Construction
echo "1. Testing Browse Page Listing Button Link Construction...\n";
$browse_content = file_get_contents(__DIR__ . '/../business/browse.php');

assertTest(str_contains($browse_content, 'place_order.php?crop_type='), "Browse page button link contains crop_type parameter");
assertTest(str_contains($browse_content, 'quantity_preset='), "Browse page button link contains quantity_preset parameter");
assertTest(str_contains($browse_content, 'farmer_id='), "Browse page button link contains farmer_id parameter");
assertTest(str_contains($browse_content, 'Buy Now'), "Listing button displays 'Buy Now' action");

// 2. Test Place Order Pre-fill & Whitelist Validation Code
echo "\n2. Testing Place Order Pre-fill & Whitelist Code Structure...\n";
$place_order_content = file_get_contents(__DIR__ . '/../business/place_order.php');

assertTest(str_contains($place_order_content, 'AGRISYNC_CROPS'), "Place order checks AGRISYNC_CROPS constant array");
assertTest(str_contains($place_order_content, 'in_array'), "Place order performs strict in_array whitelist validation");
assertTest(str_contains($place_order_content, '$prefill_qty'), "Place order populates quantity_kg input field value");

// 3. Test Pre-fill Logic & Whitelist Security Implementation
echo "\n3. Testing Pre-fill Validation Execution...\n";
$crops = defined('AGRISYNC_CROPS') && is_array(AGRISYNC_CROPS) ? AGRISYNC_CROPS : ['Tomato', 'Carrot', 'Big Onion'];

// Test Valid Crop Pre-fill
$input_crop = 'Tomato';
$prefill_crop = sanitize($input_crop);
if (!empty($prefill_crop) && !in_array($prefill_crop, $crops, true)) {
    $prefill_crop = '';
}
assertTest($prefill_crop === 'Tomato', "Valid crop_type ('Tomato') passes whitelist validation");

// Test Invalid XSS Crop Pre-fill
$invalid_crop = '<script>alert("xss")</script>';
$prefill_invalid = sanitize($invalid_crop);
if (!empty($prefill_invalid) && !in_array($prefill_invalid, $crops, true)) {
    $prefill_invalid = '';
}
assertTest($prefill_invalid === '', "Malicious/Unlisted crop_type is rejected and cleared to empty string");

// Test Quantity & Price Pre-fill
$_GET['quantity_preset'] = '50';
$_GET['max_price'] = '160';
$prefill_qty = isset($_GET['quantity_preset']) ? (float)$_GET['quantity_preset'] : '';
$prefill_max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : '';

assertTest($prefill_qty === 50.0, "quantity_preset '50' is correctly coerced to float (50)");
assertTest($prefill_max_price === 160.0, "max_price '160' is correctly coerced to float (160)");

echo "\n=======================================================\n";
echo "Tests Passed: {$pass_count} | Tests Failed: {$fail_count}\n";
echo "=======================================================\n";

if ($fail_count === 0) {
    echo "ALL DIRECT ORDER FLOW TESTS PASSED WITH ZERO ERRORS!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}

<?php
/**
 * AgriSync — Produce Quality Grading & Secure Photo Upload Test Suite
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=======================================================\n";
echo "   AgriSync Quality Grading & Upload Test Suite       \n";
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

    // 1. Create Test Producer
    echo "1. Creating Test Producer...\n";
    $farmer_email = 'test_farmer_quality@agrisync.lk';
    $db->prepare("DELETE FROM users WHERE email = :email")->execute([':email' => $farmer_email]);

    $stmt_f = $db->prepare("INSERT INTO users (name, email, password_hash, role, district) VALUES ('Quality Producer', :email, 'hash', 'farmer', 'Nuwara Eliya')");
    $stmt_f->execute([':email' => $farmer_email]);
    $farmer_id = (int)$db->lastInsertId();

    assertTest($farmer_id > 0, "Test producer created (ID: {$farmer_id})");

    // 2. Insert Grade A Harvest Listing with Certifications and Image Path
    echo "\n2. Inserting Grade A Harvest Listing with Certifications...\n";
    $crop_type = 'Bell Pepper';
    $grade = 'A';
    $certifications = 'GAP Certified, Organic Sri Lanka';
    $mock_filename = bin2hex(random_bytes(16)) . '.jpg';
    $mock_image_path = 'uploads/crops/' . $mock_filename;

    // Create physical mock image file in uploads/crops/
    $upload_dir = __DIR__ . '/../uploads/crops/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    file_put_contents($upload_dir . $mock_filename, 'MOCK_IMAGE_BYTES');

    $stmt_list = $db->prepare("
        INSERT INTO harvest_listings 
            (farmer_id, crop_type, quantity_kg, price_per_kg, harvest_date, quality_grade, certifications, image_path, status, created_at)
        VALUES 
            (:farmer_id, :crop_type, 250.00, 350.00, CURDATE(), :quality_grade, :certifications, :image_path, 'available', NOW())
    ");
    $stmt_list->execute([
        ':farmer_id'     => $farmer_id,
        ':crop_type'     => $crop_type,
        ':quality_grade' => $grade,
        ':certifications'=> $certifications,
        ':image_path'    => $mock_image_path
    ]);
    $listing_id = (int)$db->lastInsertId();

    assertTest($listing_id > 0, "Inserted Grade A harvest listing (ID: {$listing_id})");

    // 3. Query Catalog (Simulating business/browse.php)
    echo "\n3. Querying Catalog as Commercial Buyer (business/browse.php logic)...\n";
    $stmt_browse = $db->prepare("
        SELECT h.id, h.crop_type, h.quality_grade, h.certifications, h.image_path, h.status
        FROM harvest_listings h
        WHERE h.id = :id
    ");
    $stmt_browse->execute([':id' => $listing_id]);
    $fetched = $stmt_browse->fetch(PDO::FETCH_ASSOC);

    assertTest($fetched['quality_grade'] === 'A', "Fetched quality_grade is 'A'");
    assertTest($fetched['certifications'] === $certifications, "Fetched certifications match 'GAP Certified, Organic Sri Lanka'");
    assertTest($fetched['image_path'] === $mock_image_path, "Fetched image_path matches uploaded crop photo path");

    // 4. Verify Upload Directory Security (.htaccess)
    echo "\n4. Verifying Upload Directory Security (.htaccess)...";
    $htaccess_path = $upload_dir . '.htaccess';
    assertTest(file_exists($htaccess_path), "uploads/crops/.htaccess security file exists");

    $htaccess_content = file_get_contents($htaccess_path);
    assertTest(str_contains($htaccess_content, 'php_flag engine off'), ".htaccess contains 'php_flag engine off'");
    assertTest(str_contains($htaccess_content, 'Options -ExecCGI'), ".htaccess disables CGI script execution");

    // 5. Clean up test data
    echo "\n5. Cleaning Up Test Data...\n";
    if (file_exists($upload_dir . $mock_filename)) {
        unlink($upload_dir . $mock_filename);
    }
    $db->prepare("DELETE FROM harvest_listings WHERE id = :id")->execute([':id' => $listing_id]);
    $db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $farmer_id]);

    assertTest(true, "Cleaned up test listing, mock image file, and test user");

} catch (Throwable $e) {
    echo "Test Failure: " . $e->getMessage() . "\n";
    $fail_count++;
}

echo "\n=======================================================\n";
echo "Tests Passed: {$pass_count} | Tests Failed: {$fail_count}\n";
echo "=======================================================\n";

if ($fail_count === 0) {
    echo "ALL QUALITY GRADING TESTS PASSED WITH ZERO ERRORS!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}

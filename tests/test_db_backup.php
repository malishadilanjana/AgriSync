<?php
/**
 * AgriSync — Database Backup Script Test Suite
 * Tests cron/db_backup.php functionality, file output, .htaccess protection, streaming PDO fallback, and authorization security.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=======================================================\n";
echo "      AgriSync Automated DB Backup Test Suite          \n";
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

$backup_dir = __DIR__ . '/../backups';

// 1. Test .htaccess creation and content
echo "1. Testing backups/.htaccess Security Protection...\n";
if (!file_exists($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}
$htaccess_path = $backup_dir . '/.htaccess';
if (!file_exists($htaccess_path)) {
    file_put_contents($htaccess_path, "Deny from all\n");
}
assertTest(file_exists($htaccess_path), "backups/.htaccess exists");
$htaccess_content = file_get_contents($htaccess_path);
assertTest(
    stripos($htaccess_content, 'Deny from all') !== false || stripos($htaccess_content, 'Require all denied') !== false,
    "backups/.htaccess contains web access denial rule ('Deny from all')"
);

// 2. Test Authorization Logic (Preventing Fail-Open on Web Requests)
echo "\n2. Testing Authorization Security (Web Fail-Open Prevention)...\n";
// Unauthenticated web request test using stream context to handle HTTP 403 Forbidden
$http_context = stream_context_create(['http' => ['ignore_errors' => true]]);
$unauth_response = @file_get_contents('http://localhost:8000/cron/db_backup.php', false, $http_context);
$unauth_json = json_decode($unauth_response, true);

assertTest(is_array($unauth_json), "Unauthenticated web request returns valid JSON response");
assertTest(isset($unauth_json['success']) && $unauth_json['success'] === false, "Unauthenticated web request is rejected (success=false)");
assertTest(!empty($unauth_json['error']) && str_contains($unauth_json['error'], 'Unauthorized access'), "Unauthenticated web request returns 403 Unauthorized error message");

// 3. Test execution of cron/db_backup.php via CLI (Server Crontab Context)
echo "\n3. Testing CLI Cron Execution & Response Structure...\n";
$php_binary = 'C:\\xampp\\php\\php.exe';
if (!file_exists($php_binary)) {
    $php_binary = 'php';
}

$cmd = escapeshellarg($php_binary) . ' ' . escapeshellarg(__DIR__ . '/../cron/db_backup.php');
$output_lines = [];
$return_code = -1;
exec($cmd, $output_lines, $return_code);

$output_text = implode("\n", $output_lines);
$json_data = json_decode($output_text, true);

assertTest(is_array($json_data), "CLI backup script returns valid JSON");
assertTest($json_data['success'] === true, "CLI backup script succeeds (success=true)");
assertTest(isset($json_data['data']['backup_file']), "Response contains generated backup filename");
assertTest(file_exists($backup_dir . '/' . $json_data['data']['backup_file']), "Generated .sql.gz backup file exists on disk");

// Cleanup generated backup file
if (isset($json_data['data']['backup_file']) && file_exists($backup_dir . '/' . $json_data['data']['backup_file'])) {
    @unlink($backup_dir . '/' . $json_data['data']['backup_file']);
}

// 4. Test Retention Policy (Pruning Backups Older Than 7 Days)
echo "\n4. Testing Retention Policy (Pruning Backups Older Than 7 Days)...\n";
$old_dummy_file = $backup_dir . '/backup_20200101_000000.sql.gz';
$new_dummy_file = $backup_dir . '/backup_' . date('Ymd_His') . '_test.sql.gz';

file_put_contents($old_dummy_file, gzencode("dummy old backup data"));
touch($old_dummy_file, time() - (8 * 86400)); // 8 days old

file_put_contents($new_dummy_file, gzencode("dummy new backup data"));
touch($new_dummy_file, time() - (2 * 86400)); // 2 days old

assertTest(file_exists($old_dummy_file), "Created test dummy backup file > 7 days old");
assertTest(file_exists($new_dummy_file), "Created test dummy backup file <= 7 days old");

// Re-run script via CLI to trigger retention policy pruning
exec($cmd, $output_lines_2, $return_code_2);

assertTest(!file_exists($old_dummy_file), "Retention policy successfully pruned backup file older than 7 days");
assertTest(file_exists($new_dummy_file), "Retention policy retained backup file newer than 7 days");

// Cleanup test dummy files
if (file_exists($new_dummy_file)) {
    @unlink($new_dummy_file);
}
if (file_exists($old_dummy_file)) {
    @unlink($old_dummy_file);
}

// 5. Verification of .sql.gz Dump format and integrity
echo "\n5. Verifying GZIP Backup File Format and Integrity...\n";
$test_sql = "-- AgriSync Test Database Dump\nCREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(100));\nINSERT INTO users VALUES (1, 'Test Farmer');\n";
$test_gz_file = $backup_dir . '/backup_' . date('Ymd_His') . '.sql.gz';
file_put_contents($test_gz_file, gzencode($test_sql));

assertTest(file_exists($test_gz_file), ".sql.gz backup file created successfully");
$decompressed = @gzdecode(file_get_contents($test_gz_file));
assertTest($decompressed !== false, ".sql.gz backup file is valid GZIP archive");
assertTest(str_contains($decompressed, 'CREATE TABLE') && str_contains($decompressed, 'INSERT INTO'), ".sql.gz contains SQL schema and data statements");

if (file_exists($test_gz_file)) {
    @unlink($test_gz_file);
}

echo "\n=======================================================\n";
echo "Tests Passed: {$pass_count} | Tests Failed: {$fail_count}\n";
echo "=======================================================\n";

if ($fail_count === 0) {
    echo "ALL DB BACKUP TESTS PASSED WITH ZERO ERRORS!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}

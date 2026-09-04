<?php
chdir(__DIR__);
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

if (file_exists('../auth/auth_check.php')) {
    require_once '../auth/auth_check.php';
}

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    header('Content-Type: application/json; charset=UTF-8');

    // Strict Authorization: Require authenticated Admin role or valid CRON_KEY
    $cron_key = $_GET['key'] ?? ($_POST['key'] ?? null);
    $configured_key = defined('CRON_KEY') ? CRON_KEY : null;
    $is_authorized_key = (!empty($configured_key) && !empty($cron_key) && hash_equals($configured_key, (string)$cron_key));

    $is_admin = (isLoggedIn() && function_exists('getUserRole') && getUserRole() === 'admin');

    if (!$is_admin && !$is_authorized_key) {
        jsonResponse(false, null, 'Unauthorized access. Admin privileges required.', 403);
    }

    // CSRF validation on POST web requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw_input = file_get_contents('php://input');
        $input_data = json_decode($raw_input, true);
        $header_csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $body_csrf = is_array($input_data) ? ($input_data['csrf_token'] ?? null) : ($_POST['csrf_token'] ?? null);
        $submitted_csrf = $header_csrf ?? $body_csrf;

        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['csrf_token'])) {
            if (!validateCSRFToken($submitted_csrf)) {
                jsonResponse(false, null, 'CSRF security token verification failed.', 403);
            }
        }
    }
}

try {
    // 1. Ensure backups directory exists and is protected by .htaccess
    $backup_dir = __DIR__ . '/../backups';
    if (!file_exists($backup_dir)) {
        if (!mkdir($backup_dir, 0755, true)) {
            throw new RuntimeException("Failed to create backups directory.");
        }
    }

    $htaccess_path = $backup_dir . '/.htaccess';
    if (!file_exists($htaccess_path)) {
        $htaccess_content = "Deny from all\n";
        file_put_contents($htaccess_path, $htaccess_content);
    }

    // 2. Retention policy: Prune backups older than 7 days
    $cutoff_time = time() - (7 * 86400);
    $pruned_files = [];
    $pruned_count = 0;

    $dir_handle = opendir($backup_dir);
    if ($dir_handle) {
        while (($file_name = readdir($dir_handle)) !== false) {
            if ($file_name === '.' || $file_name === '..' || $file_name === '.htaccess' || $file_name === '.gitkeep') {
                continue;
            }
            $file_path = $backup_dir . '/' . $file_name;
            if (is_file($file_path)) {
                $file_mtime = filemtime($file_path);
                if ($file_mtime < $cutoff_time) {
                    if (@unlink($file_path)) {
                        $pruned_files[] = $file_name;
                        $pruned_count++;
                    }
                }
            }
        }
        closedir($dir_handle);
    }

    // 3. Generate dynamic backup filenames
    $timestamp = date('Ymd_His');
    $sql_filename = "backup_{$timestamp}.sql";
    $gz_filename = "backup_{$timestamp}.sql.gz";
    $sql_path = $backup_dir . '/' . $sql_filename;
    $gz_path = $backup_dir . '/' . $gz_filename;

    // 4. Database Connection & Dump using mysqldump via exec()
    $db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
    $db_name = defined('DB_NAME') ? DB_NAME : 'agrisync';
    $db_user = defined('DB_USER') ? DB_USER : 'root';
    $db_pass = defined('DB_PASS') ? DB_PASS : '';

    $pass_flag = !empty($db_pass) ? '-p' . escapeshellarg($db_pass) : '';
    $mysqldump_cmd = sprintf(
        'mysqldump -h %s -u %s %s %s > %s 2>&1',
        escapeshellarg($db_host),
        escapeshellarg($db_user),
        $pass_flag,
        escapeshellarg($db_name),
        escapeshellarg($sql_path)
    );

    $output_lines = [];
    $return_code = -1;
    @exec($mysqldump_cmd, $output_lines, $return_code);

    // Fallback: If mysqldump exec fails or yields empty file, stream PDO dump to file (Zero Memory Buffering)
    if ($return_code !== 0 || !file_exists($sql_path) || filesize($sql_path) === 0) {
        $pdo = getDbConnection();
        $tables = [];
        $stmt_tables = $pdo->prepare("SHOW TABLES");
        $stmt_tables->execute();
        $tables = $stmt_tables->fetchAll(PDO::FETCH_COLUMN);

        $file_handle = fopen($sql_path, 'w');
        if (!$file_handle) {
            throw new RuntimeException("Failed to create SQL dump file handle.");
        }

        fwrite($file_handle, "-- AgriSync Database Dump (PDO Fallback Stream)\n");
        fwrite($file_handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
        fwrite($file_handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $stmt_create = $pdo->prepare("SHOW CREATE TABLE `" . str_replace("`", "``", $table) . "`");
            $stmt_create->execute();
            $row_create = $stmt_create->fetch(PDO::FETCH_NUM);
            if ($row_create && isset($row_create[1])) {
                fwrite($file_handle, "DROP TABLE IF EXISTS `" . str_replace("`", "``", $table) . "`;\n");
                fwrite($file_handle, $row_create[1] . ";\n\n");
            }

            $stmt_rows = $pdo->prepare("SELECT * FROM `" . str_replace("`", "``", $table) . "`");
            $stmt_rows->execute();

            $has_rows = false;
            while ($row = $stmt_rows->fetch(PDO::FETCH_ASSOC)) {
                $has_rows = true;
                $escaped_vals = [];
                foreach ($row as $val) {
                    if ($val === null) {
                        $escaped_vals[] = "NULL";
                    } else {
                        $escaped_vals[] = $pdo->quote((string)$val);
                    }
                }
                fwrite($file_handle, "INSERT INTO `" . str_replace("`", "``", $table) . "` VALUES (" . implode(", ", $escaped_vals) . ");\n");
            }
            if ($has_rows) {
                fwrite($file_handle, "\n");
            }
        }
        fwrite($file_handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($file_handle);
    }

    // 5. Chunked GZIP compression (Zero Memory Buffering)
    if (file_exists($sql_path) && filesize($sql_path) > 0) {
        $sql_handle = fopen($sql_path, 'rb');
        $gz_handle = gzopen($gz_path, 'w9');

        if ($sql_handle && $gz_handle) {
            while (!feof($sql_handle)) {
                $chunk = fread($sql_handle, 8192);
                if ($chunk !== false && strlen($chunk) > 0) {
                    gzwrite($gz_handle, $chunk);
                }
            }
            fclose($sql_handle);
            gzclose($gz_handle);

            if (file_exists($gz_path) && filesize($gz_path) > 0) {
                unlink($sql_path);
            }
        }
    }

    if (!file_exists($gz_path) || filesize($gz_path) === 0) {
        throw new RuntimeException("Failed to generate valid .sql.gz backup file.");
    }

    // 6. Response payload
    $backup_size = filesize($gz_path);
    $response_data = [
        'backup_file' => $gz_filename,
        'backup_path' => 'backups/' . $gz_filename,
        'file_size_bytes' => $backup_size,
        'created_at' => date('Y-m-d H:i:s'),
        'pruned_count' => $pruned_count,
        'pruned_files' => $pruned_files
    ];

    if ($is_cli) {
        echo json_encode([
            'success' => true,
            'data' => $response_data,
            'error' => null
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    } else {
        jsonResponse(true, $response_data, null, 200);
    }

} catch (Throwable $e) {
    $error_msg = "Backup operation failed: " . sanitize($e->getMessage());
    if ($is_cli) {
        echo json_encode([
            'success' => false,
            'data' => null,
            'error' => $error_msg
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(1);
    } else {
        jsonResponse(false, null, $error_msg, 500);
    }
}

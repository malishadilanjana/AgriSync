<?php
require_once '../config/session.php';
require_once '../config/constants.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

$page_title = 'Reset Password';
$error_msg = '';
$success_msg = '';
$raw_token = sanitize($_GET['token'] ?? ($_POST['token'] ?? ''));
$is_valid_token = false;
$user_id = null;

if (empty($raw_token)) {
    $error_msg = 'Invalid or missing password reset token. Please request a new reset link.';
} else {
    try {
        $db = getDbConnection();
        $hashed_token = hash('sha256', $raw_token);
        $stmt = $db->prepare("SELECT id, email, reset_expires FROM users WHERE reset_token = :token LIMIT 1");
        $stmt->execute([':token' => $hashed_token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error_msg = 'This password reset link is invalid or has already been used.';
        } elseif (strtotime($user['reset_expires']) < time()) {
            $error_msg = 'This password reset link has expired (valid for 1 hour). Please request a new link.';
        } else {
            $is_valid_token = true;
            $user_id = (int)$user['id'];
        }
    } catch (Throwable $e) {
        error_log("Reset Password Validation Error: " . $e->getMessage());
        $error_msg = 'Database error verifying reset token.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_valid_token) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $submitted_csrf = $_POST['csrf_token'] ?? '';

    if (!validateCSRFToken($submitted_csrf)) {
        $error_msg = 'Security validation failed. Please try again.';
    } elseif (empty($new_password) || empty($confirm_password)) {
        $error_msg = 'Please fill in all password fields.';
    } elseif (strlen($new_password) < 6) {
        $error_msg = 'Password must be at least 6 characters long.';
    } elseif ($new_password !== $confirm_password) {
        $error_msg = 'Password confirmation does not match.';
    } else {
        try {
            $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
            $update_stmt = $db->prepare("UPDATE users SET password_hash = :hash, reset_token = NULL, reset_expires = NULL WHERE id = :id");
            $update_stmt->execute([
                ':hash' => $password_hash,
                ':id'   => $user_id
            ]);

            $success_msg = 'Your password has been successfully reset! You can now log in with your new password.';
            $is_valid_token = false; // Disable form after successful reset
        } catch (Throwable $e) {
            error_log("Reset Password Update Error: " . $e->getMessage());
            $error_msg = 'Error updating password. Please try again.';
        }
    }
}

require_once '../includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">

            <div class="text-center mb-4">
                <a href="../index.php" class="d-inline-flex align-items-center text-decoration-none mb-2">
                    <span class="fs-2">🌾</span>
                    <span class="fs-3 fw-bold text-success ms-2"><?= defined('APP_NAME') ? APP_NAME : 'AgriSync' ?></span>
                </a>
                <h4 class="fw-bold text-dark mb-1">Set New Password</h4>
                <p class="text-muted small">Enter your new password below to secure your account</p>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">

                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger rounded-3 py-2 px-3 small d-flex align-items-center mb-3">
                        <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i>
                        <div><?= htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <?php if (!$is_valid_token && empty($success_msg)): ?>
                        <div class="text-center mt-3">
                            <a href="forgot_password.php" class="btn btn-outline-success rounded-3 btn-sm fw-semibold">
                                Request New Reset Link
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!empty($success_msg)): ?>
                    <div class="alert alert-success rounded-3 py-2 px-3 small mb-3">
                        <div class="d-flex align-items-center mb-2">
                            <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                            <div class="fw-semibold"><?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="mt-2">
                            <a href="login.php" class="btn btn-success w-100 py-2 fw-semibold rounded-3 shadow-sm">
                                Proceed to Login
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($is_valid_token): ?>
                    <form method="POST" action="reset_password.php?token=<?= htmlspecialchars($raw_token, ENT_QUOTES, 'UTF-8') ?>" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($raw_token, ENT_QUOTES, 'UTF-8') ?>">

                        <div class="mb-3">
                            <label for="newPasswordInput" class="form-label small fw-semibold text-muted">New Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                                <input type="password" name="new_password" id="newPasswordInput" class="form-control rounded-end-3 border-start-0 ps-0" placeholder="Minimum 6 characters" required autofocus>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="confirmPasswordInput" class="form-label small fw-semibold text-muted">Confirm New Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" name="confirm_password" id="confirmPasswordInput" class="form-control rounded-end-3 border-start-0 ps-0" placeholder="Repeat new password" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold rounded-3 mb-3 shadow-sm" style="min-height: 44px; border-radius: 8px;">
                            <i class="bi bi-key me-1"></i> Update Password
                        </button>
                    </form>
                <?php endif; ?>

                <div class="text-center mt-2 border-top pt-3 text-muted small">
                    Back to <a href="login.php" class="text-success fw-semibold text-decoration-none">Login Gateway</a>
                </div>

            </div>

        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

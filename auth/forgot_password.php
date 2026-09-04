<?php
require_once '../config/session.php';
require_once '../config/constants.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

$page_title = 'Forgot Password';
$error_msg = '';
$success_msg = '';
$email_val = '';
$demo_reset_link = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email_val = sanitize($_POST['email'] ?? '');
    $submitted_csrf = $_POST['csrf_token'] ?? '';

    if (!validateCSRFToken($submitted_csrf)) {
        $error_msg = 'Security validation failed. Please try again.';
    } elseif (empty($email_val)) {
        $error_msg = 'Please enter your registered email address.';
    } else {
        try {
            $db = getDbConnection();
            $stmt = $db->prepare("SELECT id, name, email FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email_val]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Generate secure random token and hash it for DB storage
                $raw_token = bin2hex(random_bytes(32));
                $hashed_token = hash('sha256', $raw_token);
                $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $update_stmt = $db->prepare("UPDATE users SET reset_token = :token, reset_expires = :expires WHERE id = :id");
                $update_stmt->execute([
                    ':token'   => $hashed_token,
                    ':expires' => $expires_at,
                    ':id'      => $user['id']
                ]);

                // Mock email send by logging the plaintext token and reset URL
                $app_url = defined('APP_URL') ? APP_URL : 'http://localhost:8000';
                $reset_url = $app_url . '/auth/reset_password.php?token=' . $raw_token;
                error_log("Password reset requested for {$user['email']}. Plaintext Token: {$raw_token}. Reset Link: {$reset_url}");

                $success_msg = 'A password reset link has been generated for your account (mock email logged).';
                $demo_reset_link = $reset_url;
            } else {
                // Generic response to prevent email enumeration
                $success_msg = 'If an account with that email exists, a password reset link has been generated.';
            }
        } catch (Throwable $e) {
            error_log("Forgot Password Error: " . $e->getMessage());
            $error_msg = 'Database error processing your request. Please try again later.';
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
                <h4 class="fw-bold text-dark mb-1">Reset Your Password</h4>
                <p class="text-muted small">Enter your account email to receive a password reset link</p>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">

                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger rounded-3 py-2 px-3 small d-flex align-items-center mb-3">
                        <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i>
                        <div><?= htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success_msg)): ?>
                    <div class="alert alert-success rounded-3 py-2 px-3 small mb-3">
                        <div class="d-flex align-items-center mb-1">
                            <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                            <div class="fw-semibold"><?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php if (!empty($demo_reset_link)): ?>
                            <div class="mt-2 pt-2 border-top extra-small text-dark">
                                <span class="badge bg-success mb-1">Mock Email Sent</span>
                                <div class="text-muted mb-1">Click the demo reset link below:</div>
                                <a href="<?= htmlspecialchars($demo_reset_link, ENT_QUOTES, 'UTF-8') ?>" class="text-break fw-semibold text-success small">
                                    <?= htmlspecialchars($demo_reset_link, ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="forgot_password.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

                    <div class="mb-4">
                        <label for="emailInput" class="form-label small fw-semibold text-muted">Registered Email Address</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                            <input type="email" name="email" id="emailInput" class="form-control rounded-end-3 border-start-0 ps-0" placeholder="name@domain.lk" value="<?= htmlspecialchars($email_val, ENT_QUOTES, 'UTF-8') ?>" required autofocus>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold rounded-3 mb-3 shadow-sm" style="min-height: 44px; border-radius: 8px;">
                        <i class="bi bi-send me-1"></i> Send Reset Link
                    </button>
                </form>

                <div class="text-center mt-2 border-top pt-3 text-muted small">
                    Remembered your password? <a href="login.php" class="text-success fw-semibold text-decoration-none">Back to Login</a>
                </div>

            </div>

        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

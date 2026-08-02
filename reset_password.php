<?php
include 'config.php';

// Check if user has reset session
if (!isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_otp_hash'])) {
    header("Location: forgot_password.php");
    exit();
}

$message = '';
$messageType = '';
$resetEmail = $_SESSION['reset_email'] ?? '';
$resetId = 0;
$reset = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim($_POST['otp'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validate OTP
    if (!preg_match('/^\d{6}$/', $otp)) {
        $message = 'Please enter a valid 6-digit OTP.';
        $messageType = 'danger';
    } 
    // Validate password
    elseif (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
        $messageType = 'danger';
    } 
    elseif ($password !== $confirmPassword) {
        $message = 'Passwords do not match.';
        $messageType = 'danger';
    } 
    else {
        // Verify OTP against session hash
        $otpHash = hash('sha256', $otp);
        
        if (hash_equals($_SESSION['reset_otp_hash'], $otpHash)) {
            // Verify against database (extra security)
            $stmt = $conn->prepare("SELECT pr.reset_id, pr.user_id FROM password_resets pr WHERE pr.user_id = ? AND pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() LIMIT 1");
            $stmt->bind_param("is", $_SESSION['reset_user_id'], $otpHash);
            $stmt->execute();
            $reset = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$reset) {
                $message = 'The OTP is invalid or expired. Please request a new one.';
                $messageType = 'danger';
                // Clear session
                unset($_SESSION['reset_user_id']);
                unset($_SESSION['reset_otp_hash']);
                unset($_SESSION['reset_otp']);
                unset($_SESSION['reset_email']);
            } else {
                // Update password
                $resetId = (int)$reset['reset_id'];
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                
                $conn->begin_transaction();
                
                $passwordStmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $passwordStmt->bind_param("si", $passwordHash, $reset['user_id']);
                $passwordSuccess = $passwordStmt->execute();
                $passwordStmt->close();

                $usedStmt = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE reset_id = ?");
                $usedStmt->bind_param("i", $resetId);
                $usedSuccess = $usedStmt->execute();
                $usedStmt->close();

                if ($passwordSuccess && $usedSuccess) {
                    $conn->commit();
                    // Clear session
                    unset($_SESSION['reset_user_id']);
                    unset($_SESSION['reset_otp_hash']);
                    unset($_SESSION['reset_otp']);
                    unset($_SESSION['reset_email']);
                    
                    header("Location: login.php?reset=1");
                    exit();
                } else {
                    $conn->rollback();
                    $message = 'Unable to reset your password. Please try again.';
                    $messageType = 'danger';
                }
            }
        } else {
            $message = 'Invalid OTP. Please check and try again.';
            $messageType = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f4f6f9; display: flex; align-items: center; min-height: 100vh; }
        .card { border-radius: 20px; border: none; max-width: 450px; margin: 0 auto; }
        .btn-primary { border-radius: 50px; padding: 10px; font-weight: 600; }
        .otp-hint { font-size: 13px; color: #6c757d; }
        .demo-badge {
            background: #fff3cd;
            color: #856404;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 12px;
            display: inline-block;
        }
    </style>
</head>
<body>
<div class="container mt-3" style="max-width: 460px;">
    <div class="card shadow-sm p-4">
        <div class="text-center mb-3">
            <i class="bi bi-shield-lock" style="font-size: 48px; color: #1464ff;"></i>
            <h4 class="fw-bold mt-2">Reset Password</h4>
            <p class="text-muted">Enter the OTP and your new password</p>
            <?php if(isset($_SESSION['reset_otp'])): ?>
                <div class="demo-badge mt-2">
                    <i class="bi bi-info-circle"></i> Demo Mode: OTP was shown on previous page
                </div>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (filter_var($resetEmail, FILTER_VALIDATE_EMAIL)): ?>
            <p class="text-muted">
                <i class="bi bi-envelope"></i> Resetting password for: 
                <strong><?php echo htmlspecialchars($resetEmail); ?></strong>
            </p>
            <form method="POST" autocomplete="off">
                <div class="mb-3">
                    <label class="form-label fw-semibold">One-Time Password (OTP)</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
                        <input type="text" name="otp" class="form-control" placeholder="6-digit code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required>
                    </div>
                    <small class="text-muted">Enter the 6-digit OTP from the previous page</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" class="form-control" minlength="8" autocomplete="new-password" required>
                    </div>
                    <small class="text-muted">Minimum 8 characters</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="confirm_password" class="form-control" minlength="8" autocomplete="new-password" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2">
                    <i class="bi bi-check-circle"></i> Reset Password
                </button>
            </form>
        <?php else: ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> 
                No reset session found. Please <a href="forgot_password.php">request a new OTP</a>.
            </div>
        <?php endif; ?>

        <p class="text-center mt-3 mb-0">
            <a href="login.php" class="text-decoration-none"><i class="bi bi-arrow-left"></i> Back to Login</a>
        </p>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script src="app.js"></script>
</body>
</html>
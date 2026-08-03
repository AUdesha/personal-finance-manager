<?php
include 'config.php';
include 'mail_config.php';

// Uncomment this line to test without email (Demo Mode)
// define('DEMO_MODE', true);

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$message = '';
$messageType = '';
$otpDisplay = '';

// Show any session error (e.g., redirected from reset_password.php)
if (isset($_SESSION['error'])) {
    $message = $_SESSION['error'];
    $messageType = 'danger';
    unset($_SESSION['error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $stmt = $conn->prepare("SELECT user_id, username FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {
        $otp = (string)random_int(100000, 999999);
        $otpHash = hash('sha256', $otp);

        // Invalidate old tokens
        $invalidateStmt = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
        $invalidateStmt->bind_param("i", $user['user_id']);
        $invalidateStmt->execute();
        $invalidateStmt->close();

        // Insert new token
        $resetStmt = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
        $resetStmt->bind_param("is", $user['user_id'], $otpHash);
        $resetStmt->execute();
        $resetStmt->close();

        // Store in session for verification
        $_SESSION['reset_user_id'] = $user['user_id'];
        $_SESSION['reset_otp_hash'] = $otpHash;
        $_SESSION['reset_email'] = $email;
        $_SESSION['reset_otp'] = $otp;

        // ==========================================
        // SEND EMAIL WITH OTP
        // ==========================================
        $mailSent = send_otp_email($email, $user['username'], $otp);
        
        if ($mailSent) {
            $message = '✅ OTP sent to your email! Please check your inbox (and spam folder).';
            $messageType = 'success';

            if (defined('DEMO_MODE') && DEMO_MODE) {
                $otpDisplay = $otp;
            }
        } else {
            $message = '⚠️ Email could not be sent. Please verify your SMTP settings and try again.';
            $messageType = 'warning';

            if (defined('DEMO_MODE') && DEMO_MODE) {
                $otpDisplay = $otp;
            }

            // Log the error
            error_log("Failed to send OTP to: $email");
        }

    } else {
        $message = '❌ Email not found in our system.';
        $messageType = 'danger';
    }

    // If no error and not already set
    if ($message === '' && !isset($otpDisplay)) {
        $message = 'If that email is registered, an OTP has been sent. It expires in one hour.';
        $messageType = 'info';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f4f6f9; display: flex; align-items: center; min-height: 100vh; }
        .card { border-radius: 20px; border: none; max-width: 450px; margin: 0 auto; }
        .otp-box { 
            font-size: 42px; 
            letter-spacing: 12px; 
            font-weight: bold; 
            color: #1464ff; 
            background: #f0f5ff; 
            padding: 15px 20px; 
            border-radius: 12px; 
            text-align: center;
            border: 2px dashed #1464ff;
            font-family: monospace;
        }
        .otp-label {
            font-size: 13px;
            color: #6c757d;
            text-align: center;
            margin-top: 8px;
        }
        .btn-primary { border-radius: 50px; padding: 10px; font-weight: 600; }
        .btn-success { border-radius: 50px; padding: 10px; font-weight: 600; }
    </style>
</head>
<body>
<div class="container mt-3" style="max-width: 460px;">
    <div class="card shadow-sm p-4">
        <div class="text-center mb-3">
            <i class="bi bi-envelope-fill" style="font-size: 48px; color: #1464ff;"></i>
            <h4 class="fw-bold mt-2">Forgot Password?</h4>
            <p class="text-muted">Enter your registered email to receive an OTP</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <?php if($otpDisplay): ?>
                    <div class="mt-3">
                        <div class="otp-box"><?php echo $otpDisplay; ?></div>
                        <div class="otp-label">📋 Copy this OTP (Email could not be sent)</div>
                    </div>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="mb-3">
                <label class="form-label fw-semibold">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" name="email" class="form-control" placeholder="student@university.lk" value="student@university.lk" autocomplete="off" required autofocus>
                </div>
            </div>
            <button class="btn btn-primary w-100 py-2">
                <i class="bi bi-envelope-paper"></i> Send OTP
            </button>
        </form>

        <?php if (isset($_SESSION['reset_email']) && isset($_SESSION['reset_otp_hash'])): ?>
            <hr class="my-3">
            <p class="text-center mb-2">Already have an OTP?</p>
            <a href="reset_password.php" class="btn btn-success w-100 py-2">
                <i class="bi bi-shield-lock"></i> Enter OTP & Reset Password
            </a>
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
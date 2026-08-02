<?php
include 'config.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT user_id, username, email, password_hash, profile_picture FROM users WHERE username = ? OR email = ? LIMIT 1");
    $stmt->bind_param("ss", $login, $login);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['profile_picture'] = $user['profile_picture'];
        header("Location: index.php");
        exit();
    }

    $message = 'Invalid username/email or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="container mt-5" style="max-width: 440px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h4 class="fw-bold mb-3">Login</h4>
            <?php if (isset($_GET['registered'])): ?><div class="alert alert-success">Registration successful. Please log in.</div><?php endif; ?>
            <?php if (isset($_GET['reset'])): ?><div class="alert alert-success">Password reset successfully. Please log in.</div><?php endif; ?>
            <?php if ($message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
            <form method="POST" id="loginForm" autocomplete="off">
                <div class="mb-3"><label class="form-label">Username or email</label><input type="text" name="login" id="loginUsername" class="form-control" value="" autocomplete="off" required autofocus></div>
                <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" id="loginPassword" class="form-control" value="" autocomplete="new-password" required></div>
                <button class="btn btn-primary w-100">Login</button>
            </form>
            <div class="d-flex justify-content-between mt-3 small"><a href="forgot_password.php">Forgot password?</a><a href="register.php">Create an account</a></div>
        </div>
    </div>
</div>
<script src="app.js"></script>
</body>
</html>
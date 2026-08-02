<?php
include 'config.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$message = '';
$messageType = 'danger';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
        $message = 'Username must be 3-50 characters and use only letters, numbers, or underscores.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $message = 'Passwords do not match.';
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $username, $email, $passwordHash);

        if ($stmt->execute()) {
            $stmt->close();
            header("Location: login.php?registered=1");
            exit();
        }

        $message = $stmt->errno === 1062 ? 'Username or email is already registered.' : 'Registration failed. Please try again.';
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="container mt-5" style="max-width: 440px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h4 class="fw-bold mb-3">Create Account</h4>
            <?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
            <form method="POST" id="registerForm" autocomplete="off">
                <div class="mb-3"><label class="form-label">Username</label><input type="text" name="username" id="registerUsername" class="form-control" value="" autocomplete="off" maxlength="50" required></div>
                <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" id="registerEmail" class="form-control" value="" autocomplete="off" maxlength="100" required></div>
                <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" id="registerPassword" class="form-control" value="" autocomplete="new-password" minlength="8" required></div>
                <div class="mb-3"><label class="form-label">Confirm password</label><input type="password" name="confirm_password" id="registerConfirmPassword" class="form-control" value="" autocomplete="new-password" minlength="8" required></div>
                <button class="btn btn-primary w-100">Register</button>
            </form>
            <p class="text-center mt-3 mb-0">Already registered? <a href="login.php">Login</a></p>
        </div>
    </div>
</div>
<script src="app.js"></script>
</body>
</html>
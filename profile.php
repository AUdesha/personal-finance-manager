<?php
include 'config.php';
$user_id = require_login();

$message = '';
$messageType = '';

$stmt = $conn->prepare("SELECT user_id, username, email, profile_picture, created_at FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header("Location: logout.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
        $message = 'Username must be 3-50 characters and use only letters, numbers, or underscores.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid email address.';
    } else {
        $profilePicture = $user['profile_picture'];
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK || $_FILES['profile_picture']['size'] > 2 * 1024 * 1024) {
                $message = 'Profile picture must be smaller than 2 MB.';
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['profile_picture']['tmp_name']);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                if (!isset($extensions[$mime])) {
                    $message = 'Use a JPG, PNG, GIF, or WebP profile picture.';
                } else {
                    $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
                    if (!is_dir($uploadDirectory)) {
                        mkdir($uploadDirectory, 0755, true);
                    }
                    $filename = 'profile_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $filename)) {
                        $profilePicture = 'uploads/' . $filename;
                    } else {
                        $message = 'The profile picture could not be uploaded.';
                    }
                }
            }
        }

        if ($message === '') {
            $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, profile_picture = ? WHERE user_id = ?");
            $stmt->bind_param("sssi", $username, $email, $profilePicture, $user_id);
            if ($stmt->execute()) {
                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;
                $_SESSION['profile_picture'] = $profilePicture;
                $user['username'] = $username;
                $user['email'] = $email;
                $user['profile_picture'] = $profilePicture;
                $message = 'Profile updated successfully.';
                $messageType = 'success';
            } else {
                $message = $stmt->errno === 1062 ? 'Username or email is already in use.' : 'Profile update failed.';
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'header_nav.php'; ?>
<div class="container mt-3" style="max-width: 620px;">
    <div class="d-flex align-items-center mb-4">
        <a href="index.php" class="btn btn-outline-secondary back-btn me-3"><i class="bi bi-arrow-left"></i> Back</a>
        <h5 class="fw-bold mb-0"><i class="bi bi-person-circle"></i> Profile</h5>
    </div>
    <?php if ($message): ?><div class="alert alert-<?php echo $messageType ?: 'danger'; ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <div class="profile-picture-editor text-center mb-4">
                <?php if ($user['profile_picture']): ?>
                    <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" class="profile-avatar" id="profilePicturePreview" alt="Profile picture">
                <?php else: ?>
                    <i class="bi bi-person-circle profile-avatar-placeholder" id="profilePicturePreview"></i>
                <?php endif; ?>
                <label for="profilePictureInput" class="profile-picture-edit" title="Change profile picture" aria-label="Change profile picture">
                    <i class="bi bi-camera-fill"></i>
                </label>
                <p class="text-muted mt-2 mb-0">Member since <?php echo date('d M Y', strtotime($user['created_at'])); ?></p>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3"><label class="form-label">Username</label><input name="username" class="form-control" maxlength="50" value="<?php echo htmlspecialchars($user['username']); ?>" required></div>
                <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" maxlength="100" value="<?php echo htmlspecialchars($user['email']); ?>" required></div>
                <div class="mb-3"><label class="form-label">Profile picture</label><input type="file" name="profile_picture" id="profilePictureInput" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp"><small class="text-muted">JPG, PNG, GIF, or WebP up to 2 MB.</small></div>
                <button class="btn btn-primary w-100"><i class="bi bi-save"></i> Save Profile</button>
            </form>
            <a href="logout.php" class="btn btn-outline-danger w-100 mt-3"><i class="bi bi-box-arrow-right"></i> Log out</a>
        </div>
    </div>
</div>
<script src="app.js"></script>
</body>
</html>

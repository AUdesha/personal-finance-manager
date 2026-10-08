<?php
include 'config.php';
$user_id = require_login();

$message = '';

// Handle clear action (delete budget alerts)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_budget_alerts'])) {
    $delStmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ? AND (title LIKE '%Budget Alert' OR title LIKE '%Budget Overspent')");
    $delStmt->bind_param('i', $user_id);
    if ($delStmt->execute()) {
        $message = 'Budget alerts cleared.';
    } else {
        $message = 'Unable to clear alerts: ' . $delStmt->error;
    }
    $delStmt->close();
}

// Fetch budget-related notifications
$stmt = $conn->prepare("SELECT notification_id, title, message, type, is_read, created_at FROM notifications WHERE user_id = ? AND (title LIKE '%Budget Alert' OR title LIKE '%Budget Overspent') ORDER BY created_at DESC");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$alerts = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Budget Alerts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'header_nav.php'; ?>
<div class="container mt-3">
    <div class="d-flex align-items-center mb-4">
        <a href="index.php" class="btn btn-outline-secondary me-3"><i class="bi bi-arrow-left"></i> Back</a>
        <h5 class="fw-bold mb-0"><i class="bi bi-bell-fill"></i> Budget Alerts</h5>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if (count($alerts) === 0): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-check-circle text-success fs-1 mb-2"></i>
                <h6>No budget alerts</h6>
                <p class="text-muted">You're all caught up for your budgeting notifications.</p>
                <a href="set_budget.php" class="btn btn-primary">Manage Budgets</a>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="POST">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">Recent Budget Alerts</h6>
                        <button type="submit" name="clear_budget_alerts" class="btn btn-sm btn-danger">Clear All Alerts</button>
                    </div>
                </form>
                <div class="list-group">
                    <?php foreach ($alerts as $a): ?>
                        <div class="list-group-item list-group-item-action <?php echo $a['is_read'] ? '' : 'list-group-item-warning'; ?>">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><?php echo htmlspecialchars($a['title']); ?></h6>
                                <small class="text-muted"><?php echo htmlspecialchars($a['created_at']); ?></small>
                            </div>
                            <p class="mb-1"><?php echo htmlspecialchars($a['message']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

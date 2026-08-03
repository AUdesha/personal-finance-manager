<?php
include 'config.php';
$user_id = require_login();

$message = '';
$messageType = '';

// ==========================================
// HANDLE ADD GOAL
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_goal'])) {
    $goal_name = htmlspecialchars(trim($_POST['goal_name']));
    $target_amount = (float)$_POST['target_amount'];
    $current_amount = isset($_POST['current_amount']) ? (float)$_POST['current_amount'] : 0;
    $target_date = $_POST['target_date'];
    
    if (empty($goal_name) || $target_amount <= 0) {
        $message = "⚠️ Please enter a valid goal name and target amount (> 0).";
        $messageType = "danger";
    } else {
        $stmt = $conn->prepare("INSERT INTO savings_goals (user_id, goal_name, target_amount, current_amount, target_date) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isdds", $user_id, $goal_name, $target_amount, $current_amount, $target_date);
        
        if ($stmt->execute()) {
            $message = "✅ Goal '{$goal_name}' created successfully!";
            $messageType = "success";
        } else {
            $message = "❌ Error: " . $stmt->error;
            $messageType = "danger";
        }
        $stmt->close();
    }
}

// ==========================================
// HANDLE EDIT GOAL
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_goal'])) {
    $goal_id = (int)$_POST['goal_id'];
    $goal_name = htmlspecialchars(trim($_POST['goal_name']));
    $target_amount = (float)$_POST['target_amount'];
    $current_amount = (float)$_POST['current_amount'];
    $target_date = $_POST['target_date'];
    
    if (empty($goal_name) || $target_amount <= 0) {
        $message = "⚠️ Please enter a valid goal name and target amount (> 0).";
        $messageType = "danger";
    } else {
        $stmt = $conn->prepare("UPDATE savings_goals SET goal_name = ?, target_amount = ?, current_amount = ?, target_date = ? WHERE goal_id = ? AND user_id = ?");
        $stmt->bind_param("sddssi", $goal_name, $target_amount, $current_amount, $target_date, $goal_id, $user_id);
        
        if ($stmt->execute()) {
            $message = "✅ Goal updated successfully!";
            $messageType = "success";
        } else {
            $message = "❌ Error: " . $stmt->error;
            $messageType = "danger";
        }
        $stmt->close();
    }
}

// ==========================================
// HANDLE QUICK DEPOSIT (Add money to goal)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['deposit_goal'])) {
    $goal_id = (int)$_POST['goal_id'];
    $deposit_amount = (float)$_POST['deposit_amount'];
    
    if ($deposit_amount <= 0) {
        $message = "⚠️ Deposit amount must be greater than 0.";
        $messageType = "danger";
    } else {
        $stmt = $conn->prepare("UPDATE savings_goals SET current_amount = current_amount + ? WHERE goal_id = ? AND user_id = ?");
        $stmt->bind_param("dii", $deposit_amount, $goal_id, $user_id);
        
        if ($stmt->execute()) {
            $message = "✅ LKR " . number_format($deposit_amount, 2) . " added to your goal!";
            $messageType = "success";
        } else {
            $message = "❌ Error: " . $stmt->error;
            $messageType = "danger";
        }
        $stmt->close();
    }
}

// ==========================================
// HANDLE DELETE GOAL
// ==========================================
if (isset($_GET['delete'])) {
    $goal_id = (int)$_GET['delete'];
    
    $stmt = $conn->prepare("DELETE FROM savings_goals WHERE goal_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $goal_id, $user_id);
    
    if ($stmt->execute()) {
        $message = "✅ Goal deleted successfully!";
        $messageType = "success";
    } else {
        $message = "❌ Error: " . $stmt->error;
        $messageType = "danger";
    }
    $stmt->close();
}

// ==========================================
// FETCH EDIT DATA (if edit parameter is set)
// ==========================================
$editData = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM savings_goals WHERE goal_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $edit_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $editData = $result->fetch_assoc();
    }
    $stmt->close();
}

// ==========================================
// FETCH ALL GOALS FOR DISPLAY
// ==========================================
$goalsStmt = $conn->prepare("SELECT * FROM savings_goals WHERE user_id = ? ORDER BY
                             CASE WHEN current_amount >= target_amount THEN 0 ELSE 1 END,
                             target_date ASC");
$goalsStmt->bind_param("i", $user_id);
$goalsStmt->execute();
$goalsResult = $goalsStmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Savings Goals</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f4f6f9; padding-bottom: 70px; }
        .card { border-radius: 15px; border: none; }
        .back-btn { border-radius: 50px; padding: 8px 20px; }
        .goal-card {
            border-radius: 15px;
            padding: 15px;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border-left: 5px solid #007bff;
            transition: transform 0.2s;
            height: 100%;
        }
        .goal-card:hover { transform: translateY(-3px); }
        .goal-card.completed { border-left-color: #28a745; }
        .goal-card .goal-icon { font-size: 28px; }
        .progress-bar-custom { height: 10px; border-radius: 10px; }
        .btn-sm { border-radius: 30px; }
        .deposit-form { display: flex; gap: 5px; margin-top: 8px; }
        .deposit-form input { width: 80px; padding: 4px 8px; font-size: 13px; }
        .deposit-form button { padding: 4px 12px; font-size: 13px; }
        .status-badge {
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-completed { background: #d4edda; color: #155724; }
        .status-inprogress { background: #cce5ff; color: #004085; }
        .days-remaining { font-size: 12px; color: #6c757d; }
        .goal-amount { font-weight: 700; font-size: 1.1rem; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container mt-3">
    <!-- Header -->
    <div class="d-flex align-items-center mb-4">
        <a href="index.php" class="btn btn-outline-secondary back-btn me-3">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <h5 class="fw-bold mb-0"><i class="bi bi-graph-up-arrow"></i> Savings Goals</h5>
    </div>

    <!-- Alert Messages -->
    <?php if($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ========================================== -->
    <!-- ADD / EDIT GOAL FORM -->
    <!-- ========================================== -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white fw-bold">
            <?php echo ($editData) ? '✏️ Edit Goal' : '🎯 Add New Savings Goal'; ?>
        </div>
        <div class="card-body">
            <form method="POST">
                <?php if($editData): ?>
                    <input type="hidden" name="goal_id" value="<?php echo $editData['goal_id']; ?>">
                <?php endif; ?>
                
                <div class="row g-3">
                    <!-- Goal Name -->
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Goal Name</label>
                        <input type="text" name="goal_name" class="form-control" 
                               placeholder="e.g., Buy a Laptop" 
                               value="<?php echo ($editData) ? htmlspecialchars($editData['goal_name']) : ''; ?>" required>
                    </div>
                    
                    <!-- Target Amount -->
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Target Amount (LKR)</label>
                        <input type="number" step="0.01" name="target_amount" class="form-control" 
                               placeholder="50000" min="0.01" 
                               value="<?php echo ($editData) ? $editData['target_amount'] : ''; ?>" required>
                    </div>
                    
                    <!-- Current Amount -->
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Current Amount (LKR)</label>
                        <input type="number" step="0.01" name="current_amount" class="form-control" 
                               placeholder="0.00" min="0" 
                               value="<?php echo ($editData) ? $editData['current_amount'] : '0.00'; ?>">
                        <small class="text-muted">Leave as 0 if starting fresh.</small>
                    </div>
                    
                    <!-- Target Date -->
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Target Date</label>
                        <input type="date" name="target_date" class="form-control" 
                               value="<?php echo ($editData) ? $editData['target_date'] : date('Y-m-d', strtotime('+6 months')); ?>" required>
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="col-md-2 d-flex align-items-end">
                        <?php if($editData): ?>
                            <button type="submit" name="edit_goal" class="btn btn-primary w-100">
                                <i class="bi bi-check-circle"></i> Update
                            </button>
                            <a href="savings_goals.php" class="btn btn-outline-secondary ms-2">
                                <i class="bi bi-x-circle"></i>
                            </a>
                        <?php else: ?>
                            <button type="submit" name="add_goal" class="btn btn-success w-100">
                                <i class="bi bi-plus-circle"></i> Create Goal
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- GOALS LIST - CARDS VIEW -->
    <!-- ========================================== -->
    <div class="row">
        <?php if ($goalsResult->num_rows > 0): ?>
            <?php while($goal = $goalsResult->fetch_assoc()): 
                $progress = ($goal['target_amount'] > 0) ? ($goal['current_amount'] / $goal['target_amount']) * 100 : 0;
                $progress = min($progress, 100); // Cap at 100%
                $isCompleted = ($goal['current_amount'] >= $goal['target_amount']);
                $daysRemaining = max(0, ceil((strtotime($goal['target_date']) - time()) / 86400));
                $cardClass = $isCompleted ? 'completed' : '';
            ?>
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="goal-card <?php echo $cardClass; ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="goal-icon">
                                    <?php if($isCompleted): ?>
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                    <?php else: ?>
                                        <i class="bi bi-flag-fill text-primary"></i>
                                    <?php endif; ?>
                                </div>
                                <h6 class="fw-bold mt-1 mb-0"><?php echo htmlspecialchars($goal['goal_name']); ?></h6>
                            </div>
                            <span class="status-badge <?php echo $isCompleted ? 'status-completed' : 'status-inprogress'; ?>">
                                <?php echo $isCompleted ? '✅ Completed' : '⏳ In Progress'; ?>
                            </span>
                        </div>

                        <!-- Amounts -->
                        <div class="mt-2">
                            <div class="d-flex justify-content-between">
                                <span class="goal-amount text-success">LKR <?php echo number_format($goal['current_amount'], 2); ?></span>
                                <span class="text-muted">/ LKR <?php echo number_format($goal['target_amount'], 2); ?></span>
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <div class="mt-1">
                            <div class="progress progress-bar-custom">
                                <div class="progress-bar <?php echo $isCompleted ? 'bg-success' : 'bg-primary'; ?>" 
                                     style="width: <?php echo $progress; ?>%;">
                                </div>
                            </div>
                            <small class="text-muted"><?php echo round($progress, 1); ?>% complete</small>
                        </div>

                        <!-- Days Remaining -->
                        <div class="mt-1">
                            <?php if($isCompleted): ?>
                                <small class="text-success"><i class="bi bi-check-circle"></i> Goal achieved! 🎉</small>
                            <?php else: ?>
                                <small class="days-remaining">
                                    <i class="bi bi-calendar3"></i> 
                                    <?php echo $daysRemaining; ?> days remaining
                                    (Target: <?php echo date('d M Y', strtotime($goal['target_date'])); ?>)
                                </small>
                            <?php endif; ?>
                        </div>

                        <!-- ========================================== -->
                        <!-- QUICK DEPOSIT FORM (Add Money) -->
                        <!-- ========================================== -->
                        <form method="POST" action="savings_goals.php" class="deposit-form mt-2">
                            <input type="hidden" name="goal_id" value="<?php echo $goal['goal_id']; ?>">
                            <div class="input-group input-group-sm" style="width: 100%;">
                                <span class="input-group-text">+ LKR</span>
                                <input type="number" step="0.01" name="deposit_amount" class="form-control" 
                                       placeholder="amount" min="0.01" required style="max-width: 100px;">
                                <button type="submit" name="deposit_goal" class="btn btn-outline-success btn-sm" 
                                        <?php echo $isCompleted ? 'disabled title="Goal already completed!"' : ''; ?>>
                                    <i class="bi bi-plus-circle"></i> Add
                                </button>
                            </div>
                        </form>

                        <!-- Actions (Edit / Delete) -->
                        <div class="mt-2 d-flex gap-1">
                            <a href="savings_goals.php?edit=<?php echo $goal['goal_id']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            <a href="savings_goals.php?delete=<?php echo $goal['goal_id']; ?>" class="btn btn-sm btn-outline-danger" 
                               data-confirm="Delete this goal? All progress will be lost.">
                                <i class="bi bi-trash"></i> Delete
                            </a>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-12">
                <div class="text-center text-muted py-5">
                    <i class="bi bi-flag fs-1 d-block"></i>
                    <h6>No savings goals yet!</h6>
                    <small>Start saving by creating your first goal above.</small>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick Info -->
    <div class="alert alert-info mt-3" role="alert">
        <i class="bi bi-lightbulb"></i> 
        <strong>Pro Tip:</strong> Use the <strong>"Add"</strong> button on each goal card to quickly deposit money towards your goal. 
        The progress bar updates automatically!
    </div>
</div>
<!-- Bottom Navigation -->
<div class="bottom-nav">
    <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
    <div class="nav-container">
        <a href="index.php" class="nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>"><i class="bi bi-house-fill"></i><span>Home</span></a>
        <a href="add_transaction.php" class="nav-item <?php echo in_array($currentPage, ['add_transaction.php', 'edit_transaction.php'], true) ? 'active' : ''; ?>"><i class="bi bi-plus-circle-fill"></i><span>Add</span></a>
        <a href="transfer.php" class="nav-item <?php echo $currentPage === 'transfer.php' ? 'active' : ''; ?>"><i class="bi bi-arrow-left-right"></i><span>Transfer</span></a>
        <a href="manage_accounts.php" class="nav-item <?php echo $currentPage === 'manage_accounts.php' ? 'active' : ''; ?>"><i class="bi bi-wallet-fill"></i><span>Accounts</span></a>
        <a href="set_budget.php" class="nav-item <?php echo $currentPage === 'set_budget.php' ? 'active' : ''; ?>"><i class="bi bi-wallet2"></i><span>Budget</span></a>
        <a href="savings_goals.php" class="nav-item <?php echo $currentPage === 'savings_goals.php' ? 'active' : ''; ?>"><i class="bi bi-graph-up-arrow"></i><span>Goals</span></a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script src="app.js"></script>
</body>
</html>
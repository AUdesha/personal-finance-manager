<?php
include 'config.php';

if (!isset($_SESSION['user_id'])):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personal Finance Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="welcome-page">
    <main class="welcome-shell">
        <section class="welcome-panel">
            <div class="welcome-mark"><i class="bi bi-wallet2"></i></div>
            <p class="welcome-kicker">PERSONAL FINANCE MANAGER</p>
            <h1>Make Every Rupee<br><span>Work Smarter.</span></h1>
            <p class="welcome-copy">A calm, clear place to track spending, grow savings, and stay in control of your money.</p>
            <div class="welcome-actions">
                <a href="login.php" class="btn btn-primary btn-lg"><i class="bi bi-box-arrow-in-right"></i> Login</a>
                <a href="register.php" class="btn btn-outline-primary btn-lg"><i class="bi bi-person-plus"></i> Create account</a>
            </div>
            <div class="welcome-features">
                <span><i class="bi bi-shield-check"></i> Private</span>
                <span><i class="bi bi-graph-up-arrow"></i> Insightful</span>
                <span><i class="bi bi-lightning-charge"></i> Simple</span>
            </div>
        </section>
        <div class="welcome-orbit orbit-one"></div>
        <div class="welcome-orbit orbit-two"></div>
    </main>
    <script src="app.js"></script>
</body>
</html>
<?php
exit();
endif;

$user_id = require_login();

// ==========================================
// 1. Get All Accounts with Current Balances (Prepared Statement)
// ==========================================
$accountsStmt = $conn->prepare("SELECT * FROM accounts WHERE user_id = ?");
$accountsStmt->bind_param("i", $user_id);
$accountsStmt->execute();
$accountsResult = $accountsStmt->get_result();
$accountsStmt->close();

// ==========================================
// 2. Get Total Income & Expenses (Prepared Statements)
// ==========================================
$incomeStmt = $conn->prepare("SELECT SUM(t.amount) as total 
                              FROM transactions t 
                              JOIN categories c ON t.category_id = c.category_id 
                              WHERE t.user_id = ? AND c.type = 'INCOME'");
$incomeStmt->bind_param("i", $user_id);
$incomeStmt->execute();
$incomeResult = $incomeStmt->get_result();
$totalIncome = $incomeResult->fetch_assoc()['total'] ?? 0;
$incomeStmt->close();

$expenseStmt = $conn->prepare("SELECT SUM(t.amount) as total 
                               FROM transactions t 
                               JOIN categories c ON t.category_id = c.category_id 
                               WHERE t.user_id = ? AND c.type = 'EXPENSE'");
$expenseStmt->bind_param("i", $user_id);
$expenseStmt->execute();
$expenseResult = $expenseStmt->get_result();
$totalExpense = $expenseResult->fetch_assoc()['total'] ?? 0;
$expenseStmt->close();

$balance = $totalIncome - $totalExpense;

// ==========================================
// 3. Get Savings Goals (Prepared Statement)
// ==========================================
$goalsStmt = $conn->prepare("SELECT * FROM savings_goals WHERE user_id = ?");
$goalsStmt->bind_param("i", $user_id);
$goalsStmt->execute();
$goalsResult = $goalsStmt->get_result();
$goalsStmt->close();

// ==========================================
// 4. Get Budget Alerts from notifications (Prepared Statement)
// ==========================================
$notifStmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 3");
$notifStmt->bind_param("i", $user_id);
$notifStmt->execute();
$notifResult = $notifStmt->get_result();
$notifStmt->close();

// ==========================================
// 5. Get Recent Transactions (Prepared Statement)
// ==========================================
$transStmt = $conn->prepare("SELECT t.*, c.category_name, c.type, a.account_name 
                             FROM transactions t 
                             JOIN categories c ON t.category_id = c.category_id 
                             JOIN accounts a ON t.account_id = a.account_id 
                             WHERE t.user_id = ? 
                             ORDER BY t.transaction_date DESC, t.created_at DESC 
                             LIMIT 10");
$transStmt->bind_param("i", $user_id);
$transStmt->execute();
$transResult = $transStmt->get_result();
$transStmt->close();

// ==========================================
// 6. Get Recent Transfers (Prepared Statement)
// ==========================================
$transferStmt = $conn->prepare("SELECT tr.*, from_account.account_name AS from_account_name,
                                       to_account.account_name AS to_account_name
                                FROM transfers tr
                                JOIN accounts from_account ON tr.from_account_id = from_account.account_id
                                JOIN accounts to_account ON tr.to_account_id = to_account.account_id
                                WHERE tr.user_id = ?
                                ORDER BY tr.transfer_date DESC, tr.created_at DESC
                                LIMIT 10");
$transferStmt->bind_param("i", $user_id);
$transferStmt->execute();
$transferResult = $transferStmt->get_result();
$transferStmt->close();

// ==========================================
// 7. BUDGET ALERTS - Check spending against budgets (Prepared Statements)
// ==========================================
$current_month = date('Y-m-01');
$alerts = [];
$hasAlerts = false;

$budgetStmt = $conn->prepare("SELECT b.*, c.category_name 
                              FROM budgets b 
                              JOIN categories c ON b.category_id = c.category_id 
                              WHERE b.user_id = ? AND b.month_year = ?");
$budgetStmt->bind_param("is", $user_id, $current_month);
$budgetStmt->execute();
$budgetResult = $budgetStmt->get_result();

if ($budgetResult->num_rows > 0) {
    while($budget = $budgetResult->fetch_assoc()) {
        $spendStmt = $conn->prepare("SELECT SUM(amount) as total 
                                     FROM transactions t 
                                     WHERE t.user_id = ? 
                                     AND t.category_id = ? 
                                     AND MONTH(t.transaction_date) = MONTH(CURRENT_DATE()) 
                                     AND YEAR(t.transaction_date) = YEAR(CURRENT_DATE())");
        $spendStmt->bind_param("ii", $user_id, $budget['category_id']);
        $spendStmt->execute();
        $spendResult = $spendStmt->get_result();
        $actual = $spendResult->fetch_assoc()['total'] ?? 0;
        $spendStmt->close();
        
        if ($actual > 0) {
            $percentage = ($actual / $budget['monthly_limit']) * 100;
            
            if ($percentage > 80) {
                $alerts[] = [
                    'category' => $budget['category_name'],
                    'limit' => $budget['monthly_limit'],
                    'actual' => $actual,
                    'percentage' => round($percentage)
                ];
                $hasAlerts = true;
            }
        }
    }
}
$budgetStmt->close();

// Check for success messages
$success = isset($_GET['success']) ? $_GET['success'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personal Finance Manager</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- External CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
    
    <style>
        body { background: #f4f6f9; padding-bottom: 70px; }
        .dashboard-card { 
            border-radius: 15px; 
            padding: 20px; 
            color: white; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .bg-income { background: linear-gradient(135deg, #28a745, #20c997); }
        .bg-expense { background: linear-gradient(135deg, #dc3545, #fd7e14); }
        .bg-balance { background: linear-gradient(135deg, #007bff, #6610f2); }
        .account-card {
            background: white;
            border-radius: 12px;
            padding: 12px 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 4px solid #007bff;
        }
        .account-card .balance { font-weight: 700; font-size: 1.1rem; }
        .amount-income { color: #28a745; font-weight: 600; }
        .amount-expense { color: #dc3545; font-weight: 600; }
        .action-btn { padding: 2px 8px; font-size: 12px; }
        .progress-bar-custom { height: 8px; border-radius: 10px; }
        .goal-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 12px 15px;
            margin-bottom: 8px;
        }
        .category-badge { 
            background: #e9ecef; 
            padding: 2px 12px; 
            border-radius: 20px; 
            font-size: 11px; 
            display: inline-block;
        }
        .account-tag {
            font-size: 11px;
            background: #e9ecef;
            padding: 2px 10px;
            border-radius: 12px;
            color: #495057;
        }
    </style>
</head>
<body>

<div class="container mt-3">
    <!-- Success Alerts -->
    <?php if($success == '1'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill"></i> Transaction saved successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif($success == 'deleted'): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="bi bi-trash-fill"></i> Transaction deleted successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif($success == 'transfer'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-arrow-left-right"></i> Transfer completed successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="fw-bold mb-0">💰 My Finance</h5>
            <small class="text-muted">Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?>!</small>
        </div>
        <div class="dashboard-header-actions">
            <div class="dashboard-profile-menu">
                <?php include 'header_nav.php'; ?>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- 1. SUMMARY CARDS (Income, Expense, Balance) -->
    <!-- ========================================== -->
    <div class="row g-3 mb-3">
        <div class="col-4">
            <div class="dashboard-card bg-income text-center p-3">
                <small>Income</small>
                <h5 class="mb-0">LKR <?php echo number_format($totalIncome, 2); ?></h5>
            </div>
        </div>
        <div class="col-4">
            <div class="dashboard-card bg-expense text-center p-3">
                <small>Expenses</small>
                <h5 class="mb-0">LKR <?php echo number_format($totalExpense, 2); ?></h5>
            </div>
        </div>
        <div class="col-4">
            <div class="dashboard-card bg-balance text-center p-3">
                <small>Balance</small>
                <h5 class="mb-0">LKR <?php echo number_format($balance, 2); ?></h5>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- 2. ACCOUNT BALANCES (Shows each account) -->
    <!-- ========================================== -->
    <div class="mb-3">
        <small class="text-muted fw-bold"><i class="bi bi-wallet2"></i> Your Accounts</small>
        <div class="row g-2 mt-1">
            <?php while($acc = $accountsResult->fetch_assoc()): ?>
                <div class="col-4">
                    <div class="account-card text-center">
                        <small class="text-muted"><?php echo htmlspecialchars($acc['account_name']); ?></small>
                        <div class="balance">LKR <?php echo number_format($acc['current_balance'], 2); ?></div>
                        <small class="text-muted"><?php echo htmlspecialchars($acc['account_type']); ?></small>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- 3. SAVINGS GOALS (Progress Bars) -->
    <!-- ========================================== -->
    <?php if($goalsResult->num_rows > 0): ?>
    <div class="mb-3">
        <small class="text-muted fw-bold"><i class="bi bi-graph-up-arrow"></i> Savings Goals</small>
        <?php while($goal = $goalsResult->fetch_assoc()):
            $progress = ($goal['target_amount'] > 0) ? ($goal['current_amount'] / $goal['target_amount']) * 100 : 0;
            $progress = min($progress, 100);
            $isCompleted = ($goal['current_amount'] >= $goal['target_amount']);
        ?>
            <div class="goal-card">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold">
                        <?php if($isCompleted): ?>
                            <i class="bi bi-check-circle-fill text-success"></i>
                        <?php else: ?>
                            <i class="bi bi-flag-fill text-primary"></i>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($goal['goal_name']); ?>
                    </span>
                    <span class="badge <?php echo $isCompleted ? 'bg-success' : 'bg-primary'; ?>">
                        <?php echo $isCompleted ? 'Completed' : round($progress) . '%'; ?>
                    </span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-success fw-bold">LKR <?php echo number_format($goal['current_amount'], 2); ?></span>
                    <span class="text-muted">/ LKR <?php echo number_format($goal['target_amount'], 2); ?></span>
                </div>
                <div class="progress progress-bar-custom mt-1">
                    <div class="progress-bar <?php echo $isCompleted ? 'bg-success' : 'bg-primary'; ?>" style="width: <?php echo $progress; ?>%;"></div>
                </div>
                <small class="text-muted">
                    <i class="bi bi-calendar3"></i> Target: <?php echo date('d M Y', strtotime($goal['target_date'])); ?>
                    <?php if(!$isCompleted): ?>
                        · <?php echo max(0, ceil((strtotime($goal['target_date']) - time()) / 86400)); ?> days remaining
                    <?php endif; ?>
                </small>
                <form method="POST" action="savings_goals.php" class="mt-1">
                    <input type="hidden" name="goal_id" value="<?php echo $goal['goal_id']; ?>">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">+ LKR</span>
                        <input type="number" step="0.01" name="deposit_amount" class="form-control" placeholder="amount" min="0.01" style="max-width: 100px;">
                        <button type="submit" name="deposit_goal" class="btn btn-outline-success btn-sm"><i class="bi bi-plus-circle"></i> Add</button>
                        <a href="savings_goals.php?edit=<?php echo $goal['goal_id']; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i></a>
                    </div>
                </form>
            </div>
        <?php endwhile; ?>
    </div>
    <?php else: ?>
    <div class="mb-3">
        <small class="text-muted fw-bold"><i class="bi bi-graph-up-arrow"></i> Savings Goals</small>
        <div class="text-center text-muted py-2" style="font-size: 13px;">
            <i class="bi bi-flag"></i> No savings goals yet.
            <a href="savings_goals.php" class="text-primary">Create one!</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ========================================== -->
    <!-- 4. BUDGET ALERTS -->
    <!-- ========================================== -->
    <?php if($notifResult->num_rows > 0): ?>
        <?php while($notif = $notifResult->fetch_assoc()): 
            $alertClass = ($notif['type'] == 'WARNING') ? 'alert-warning' : (($notif['type'] == 'SUCCESS') ? 'alert-success' : 'alert-info');
        ?>
            <div class="alert <?php echo $alertClass; ?> py-2 d-flex align-items-center" role="alert">
                <i class="bi <?php echo ($notif['type'] == 'WARNING') ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'; ?> me-2"></i>
                <small><strong><?php echo htmlspecialchars($notif['title']); ?>:</strong> <?php echo htmlspecialchars($notif['message']); ?></small>
            </div>
        <?php endwhile; ?>
    <?php elseif($hasAlerts): ?>
        <?php foreach($alerts as $alert): ?>
            <?php if($alert['percentage'] > 100): ?>
                <div class="alert alert-danger py-2 d-flex align-items-center" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <small>
                        <strong>🚨 Budget Overspent:</strong> 
                        You have EXCEEDED your <strong><?php echo htmlspecialchars($alert['category']); ?></strong> budget! 
                        Spent LKR <?php echo number_format($alert['actual'], 2); ?> / LKR <?php echo number_format($alert['limit'], 2); ?>
                        (<?php echo $alert['percentage']; ?>%)
                    </small>
                </div>
            <?php elseif($alert['percentage'] > 80): ?>
                <div class="alert alert-warning py-2 d-flex align-items-center" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <small>
                        <strong>⚠️ Budget Alert:</strong> 
                        You have used <strong><?php echo $alert['percentage']; ?>%</strong> of your 
                        <strong><?php echo htmlspecialchars($alert['category']); ?></strong> budget. 
                        (LKR <?php echo number_format($alert['actual'], 2); ?> / LKR <?php echo number_format($alert['limit'], 2); ?>)
                    </small>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="alert alert-success py-2 d-flex align-items-center" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <small>✅ No alerts! You're managing your finances well.</small>
        </div>
    <?php endif; ?>

    <!-- ========================================== -->
    <!-- 5. ADD TRANSACTION BUTTON -->
    <!-- ========================================== -->
    <a href="add_transaction.php" class="btn btn-success btn-lg w-100 rounded-pill shadow mb-4">
        <i class="bi bi-plus-circle"></i> Add New Income and Expense
    </a>
    <a href="transfer.php" class="btn btn-primary btn-lg w-100 rounded-pill shadow mb-4">
        <i class="bi bi-arrow-left-right"></i> Transfer Between Accounts
    </a>
    <a href="set_budget.php" class="btn btn-outline-primary btn-lg w-100 rounded-pill shadow mb-4">
        <i class="bi bi-wallet2"></i> Update Budgets
    </a>

    <!-- ========================================== -->
    <!-- 6. RECENT INCOME AND EXPENSE (With Account Name) -->
    <!-- ========================================== -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-bold border-0">
            <i class="bi bi-clock-history"></i> Recent Income and Expense
        </div>
        <div class="card-body p-0">
            <?php if ($transResult->num_rows > 0): ?>
                <ul class="list-group list-group-flush">
                    <?php while($row = $transResult->fetch_assoc()): 
                        $isIncome = ($row['type'] == 'INCOME');
                        $amountClass = $isIncome ? 'amount-income' : 'amount-expense';
                        $amountPrefix = $isIncome ? '+' : '-';
                    ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($row['category_name']); ?></div>
                                <small class="text-muted">
                                    <?php echo date('d M Y', strtotime($row['transaction_date'])); ?>
                                    <?php if($row['note']): ?> · <?php echo htmlspecialchars(substr($row['note'], 0, 20)); ?><?php endif; ?>
                                </small>
                                <div>
                                    <span class="account-tag"><i class="bi bi-wallet2"></i> <?php echo htmlspecialchars($row['account_name']); ?></span>
                                    <span class="category-badge"><?php echo htmlspecialchars($row['payment_method']); ?></span>
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="fw-bold <?php echo $amountClass; ?>">
                                    <?php echo $amountPrefix; ?>LKR <?php echo number_format($row['amount'], 2); ?>
                                </span>
                                <div>
                                    <a href="edit_transaction.php?id=<?php echo $row['transaction_id']; ?>" class="btn btn-sm btn-outline-primary action-btn mt-1">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="delete_transaction.php?id=<?php echo $row['transaction_id']; ?>" class="btn btn-sm btn-outline-danger action-btn mt-1" data-confirm="Delete this transaction?">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </div>
                        </li>
                    <?php endwhile; ?>
                </ul>
            <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-inbox fs-1 d-block"></i>
                    <small>No transactions yet. Start tracking today!</small>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- 7. RECENT TRANSFERS -->
    <!-- ========================================== -->
    <div class="card shadow-sm border-0 mt-3 mb-4">
        <div class="card-header bg-white fw-bold border-0">
            <i class="bi bi-arrow-left-right"></i> Recent Transfers
        </div>
        <div class="card-body p-0">
            <?php if ($transferResult->num_rows > 0): ?>
                <ul class="list-group list-group-flush">
                    <?php while($transfer = $transferResult->fetch_assoc()): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold">
                                    <?php echo htmlspecialchars($transfer['from_account_name']); ?>
                                    <i class="bi bi-arrow-right mx-1 text-primary"></i>
                                    <?php echo htmlspecialchars($transfer['to_account_name']); ?>
                                </div>
                                <small class="text-muted">
                                    <?php echo date('d M Y', strtotime($transfer['transfer_date'])); ?>
                                    <?php if($transfer['note']): ?> · <?php echo htmlspecialchars($transfer['note']); ?><?php endif; ?>
                                </small>
                            </div>
                            <span class="fw-bold text-primary">LKR <?php echo number_format($transfer['amount'], 2); ?></span>
                        </li>
                    <?php endwhile; ?>
                </ul>
            <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-arrow-left-right fs-1 d-block"></i>
                    <small>No transfers yet.</small>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- 7. BOTTOM NAVIGATION -->
<!-- ========================================== -->
<div class="bottom-nav">
    <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
    <div class="nav-container">
        <a href="index.php" class="nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>"><i class="bi bi-house-fill"></i><span>Home</span></a>
        <a href="add_transaction.php" class="nav-item <?php echo in_array($currentPage, ['add_transaction.php', 'edit_transaction.php'], true) ? 'active' : ''; ?>"><i class="bi bi-plus-circle-fill"></i><span>Add</span></a>
        <a href="transfer.php" class="nav-item <?php echo $currentPage === 'transfer.php' ? 'active' : ''; ?>"><i class="bi bi-arrow-left-right"></i><span>Transfer</span></a>
        <a href="manage_accounts.php" class="nav-item <?php echo $currentPage === 'manage_accounts.php' ? 'active' : ''; ?>"><i class="bi bi-wallet-fill"></i><span>Accounts</span></a>
        <a href="savings_goals.php" class="nav-item <?php echo $currentPage === 'savings_goals.php' ? 'active' : ''; ?>"><i class="bi bi-graph-up-arrow"></i><span>Goals</span></a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script src="app.js"></script>
</body>
</html>
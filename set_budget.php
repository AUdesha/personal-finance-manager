<?php
include 'config.php';
$user_id = require_login();

$message = '';
$messageType = '';
$budgetIcons = [
    'bi-wallet2' => 'Wallet',
    'bi-egg-fried' => 'Food',
    'bi-bus-front' => 'Transport',
    'bi-book' => 'Academics',
    'bi-film' => 'Entertainment',
    'bi-phone' => 'Bills',
    'bi-house' => 'Housing',
    'bi-cart' => 'Shopping',
    'bi-heart' => 'Health',
    'bi-cash' => 'Cash',
    'bi-credit-card' => 'Card',
    'bi-gift' => 'Gift'
];

// ==========================================
// GET SELECTED MONTH (default = current month)
// ==========================================
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m-01');

// ==========================================
// HANDLE BUDGET UPDATE
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && (isset($_POST['update_budgets']) || isset($_POST['add_budget']))) {
    $month_year = $_POST['month_year'] ?? date('Y-m-01');
    $budgetInputs = $_POST['budget'] ?? [];
    $iconInputs = $_POST['budget_icon'] ?? [];
    $nameInputs = $_POST['budget_name'] ?? [];
    $updateError = '';
    if (isset($_POST['add_budget'])) {
        $newCategoryName = trim($_POST['new_category_name'] ?? '');
        if ($newCategoryName === '') {
            $updateError = 'Enter a name for the new budget.';
        }

        if ($updateError === '') {
            $newCategoryStmt = $conn->prepare("SELECT category_id FROM categories WHERE user_id = ? AND type = 'EXPENSE' AND category_name = ? LIMIT 1");
            $newCategoryStmt->bind_param("is", $user_id, $newCategoryName);
            $newCategoryStmt->execute();
            $existingCategory = $newCategoryStmt->get_result()->fetch_assoc();
            $newCategoryStmt->close();

            if ($existingCategory) {
                $newCategoryId = (int)$existingCategory['category_id'];
            } else {
                $defaultIcon = 'bi-wallet2';
                $selectedIcon = $_POST['new_category_icon'] ?? $defaultIcon;
                if (!array_key_exists($selectedIcon, $budgetIcons)) {
                    $selectedIcon = $defaultIcon;
                }
                $defaultColor = '#1464ff';
                $defaultDescription = 'User-created budget category';
                $newCategoryStmt = $conn->prepare("INSERT INTO categories (user_id, category_name, type, icon, color, description) VALUES (?, ?, 'EXPENSE', ?, ?, ?)");
                $newCategoryStmt->bind_param("issss", $user_id, $newCategoryName, $selectedIcon, $defaultColor, $defaultDescription);
                if ($newCategoryStmt->execute()) {
                    $newCategoryId = $conn->insert_id;
                } else {
                    $updateError = 'Unable to create the new budget category.';
                }
                $newCategoryStmt->close();
            }
        }

        $budgetInputs = [
            $newCategoryId ?? 0 => $_POST['new_limit'] ?? ''
        ];
    }
    $transactionStarted = false;

    if (!preg_match('/^\d{4}-\d{2}-01$/', $month_year)) {
        $updateError = 'Invalid budget month.';
    }

    if ($updateError === '') {
        $categoryCheckStmt = $conn->prepare("SELECT category_id FROM categories WHERE category_id = ? AND user_id = ? AND type = 'EXPENSE'");
        $iconUpdateStmt = $conn->prepare("UPDATE categories SET icon = ? WHERE category_id = ? AND user_id = ? AND type = 'EXPENSE'");
        $nameUpdateStmt = $conn->prepare("UPDATE categories SET category_name = ? WHERE category_id = ? AND user_id = ? AND type = 'EXPENSE'");
        $upsertStmt = $conn->prepare("INSERT INTO budgets (user_id, category_id, monthly_limit, month_year)
                                      VALUES (?, ?, ?, ?)
                                      ON DUPLICATE KEY UPDATE monthly_limit = VALUES(monthly_limit)");
        $deleteStmt = $conn->prepare("DELETE FROM budgets WHERE user_id = ? AND category_id = ? AND month_year = ?");

        if (!$categoryCheckStmt || !$iconUpdateStmt || !$nameUpdateStmt || !$upsertStmt || !$deleteStmt) {
            $updateError = $conn->error;
        }
    }

    if ($updateError === '') {
        $conn->begin_transaction();
        $transactionStarted = true;
        foreach ($budgetInputs as $category_id => $rawLimit) {
            $category_id = (int)$category_id;
            $rawLimit = trim((string)$rawLimit);

            $categoryCheckStmt->bind_param("ii", $category_id, $user_id);
            $categoryCheckStmt->execute();
            if ($categoryCheckStmt->get_result()->num_rows === 0) {
                $updateError = 'Invalid expense category.';
                break;
            }

            if (isset($nameInputs[$category_id])) {
                $categoryName = trim((string)$nameInputs[$category_id]);
                if ($categoryName === '' || strlen($categoryName) > 100) {
                    $updateError = 'Budget category names must be 1 to 100 characters.';
                    break;
                }
                $nameUpdateStmt->bind_param("sii", $categoryName, $category_id, $user_id);
                if (!$nameUpdateStmt->execute()) {
                    $updateError = $nameUpdateStmt->error;
                    break;
                }
            }

            if ($rawLimit === '') {
                $limit = 0.0;
            } elseif (!is_numeric($rawLimit) || (float)$rawLimit < 0) {
                $updateError = 'Budget limits must be zero or a positive number.';
                break;
            }
            $limit = (float)$rawLimit;

            if (isset($iconInputs[$category_id]) && array_key_exists($iconInputs[$category_id], $budgetIcons)) {
                $selectedIcon = $iconInputs[$category_id];
                $iconUpdateStmt->bind_param("sii", $selectedIcon, $category_id, $user_id);
                if (!$iconUpdateStmt->execute()) {
                    $updateError = $iconUpdateStmt->error;
                    break;
                }
            }

            if ($limit > 0) {
                $upsertStmt->bind_param("iids", $user_id, $category_id, $limit, $month_year);
                if (!$upsertStmt->execute()) {
                    $updateError = $upsertStmt->error;
                    break;
                }
            } else {
                $deleteStmt->bind_param("iis", $user_id, $category_id, $month_year);
                if (!$deleteStmt->execute()) {
                    $updateError = $deleteStmt->error;
                    break;
                }
            }
        }

        $categoryCheckStmt->close();
        $iconUpdateStmt->close();
        $nameUpdateStmt->close();
        $upsertStmt->close();
        $deleteStmt->close();
    }

    if ($updateError !== '') {
        if ($transactionStarted) {
            $conn->rollback();
        }
        $message = "Unable to update budgets: " . $updateError;
        $messageType = "danger";
    } else {
        $conn->commit();

        // Rebuild budget warnings so edits and removals cannot leave stale alerts.
        $clearAlertsStmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ? AND (title LIKE '%Budget Alert' OR title LIKE '%Budget Overspent')");
        $clearAlertsStmt->bind_param("i", $user_id);
        $clearAlertsStmt->execute();
        $clearAlertsStmt->close();

        $currentMonth = date('Y-m-01');
        $currentBudgetsStmt = $conn->prepare("SELECT b.category_id, b.monthly_limit, c.category_name
                                               FROM budgets b
                                               JOIN categories c ON c.category_id = b.category_id
                                               WHERE b.user_id = ? AND b.month_year = ?");
        $currentBudgetsStmt->bind_param("is", $user_id, $currentMonth);
        $currentBudgetsStmt->execute();
        $currentBudgets = $currentBudgetsStmt->get_result();

        $spendingStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total
                                         FROM transactions
                                         WHERE user_id = ? AND category_id = ?
                                         AND MONTH(transaction_date) = MONTH(CURRENT_DATE())
                                         AND YEAR(transaction_date) = YEAR(CURRENT_DATE())");
        $alertStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'WARNING')");
        while ($currentBudget = $currentBudgets->fetch_assoc()) {
            $categoryId = (int)$currentBudget['category_id'];
            $limit = (float)$currentBudget['monthly_limit'];
            $spendingStmt->bind_param("ii", $user_id, $categoryId);
            $spendingStmt->execute();
            $actual = (float)$spendingStmt->get_result()->fetch_assoc()['total'];
            $percentage = $limit > 0 ? ($actual / $limit) * 100 : ($actual > 0 ? 101 : 0);

            if ($percentage > 80) {
                $alertTitle = $percentage > 100 ? 'Budget Overspent' : 'Budget Alert';
                $alertMessage = $percentage > 100
                    ? "You have exceeded your '{$currentBudget['category_name']}' budget. Spent LKR " . number_format($actual, 2) . " / LKR " . number_format($limit, 2)
                    : "You have used " . round($percentage) . "% of your '{$currentBudget['category_name']}' budget. Limit: LKR " . number_format($limit, 2);
                $alertStmt->bind_param("iss", $user_id, $alertTitle, $alertMessage);
                $alertStmt->execute();
            }
        }
        $spendingStmt->close();
        $alertStmt->close();
        $currentBudgetsStmt->close();

        header("Location: set_budget.php?month=" . urlencode($month_year) . "&success=1");
        exit();
    }
}

// ==========================================
// FETCH EXPENSE CATEGORIES
// ==========================================
$catStmt = $conn->prepare("SELECT * FROM categories WHERE user_id = ? AND type = 'EXPENSE' ORDER BY category_name");
$catStmt->bind_param("i", $user_id);
$catStmt->execute();
$catResult = $catStmt->get_result();
$expenseCategories = $catResult->fetch_all(MYSQLI_ASSOC);

// ==========================================
// FETCH EXISTING BUDGETS FOR SELECTED MONTH
// ==========================================
$budgetMap = [];
$budgetStmt = $conn->prepare("SELECT * FROM budgets WHERE user_id = ? AND month_year = ?");
$budgetStmt->bind_param("is", $user_id, $selected_month);
$budgetStmt->execute();
$budgetResult = $budgetStmt->get_result();
while($budget = $budgetResult->fetch_assoc()) {
    $budgetMap[$budget['category_id']] = $budget['monthly_limit'];
}

// ==========================================
// GET PREVIOUS AND NEXT MONTHS FOR NAVIGATION
// ==========================================
$prevMonth = date('Y-m-01', strtotime($selected_month . ' -1 month'));
$nextMonth = date('Y-m-01', strtotime($selected_month . ' +1 month'));
$currentMonth = date('Y-m-01');

// ==========================================
// CHECK FOR SUCCESS MESSAGE
// ==========================================
if (isset($_GET['success'])) {
    $message = "✅ Budgets updated successfully!";
    $messageType = "success";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Budgets</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f4f6f9; padding-bottom: 70px; }
        .card { border-radius: 15px; border: none; }
        .back-btn { border-radius: 50px; padding: 8px 20px; }
        .budget-input { max-width: 140px; display: inline-block; }
        .budget-action-cell { min-width: 170px; white-space: nowrap; }
        .table th { font-weight: 600; color: #495057; }
        .category-row:hover { background-color: #f8f9fa; }
        .month-nav-btn {
            border-radius: 50px;
            padding: 5px 15px;
            font-size: 14px;
        }
        .current-month-badge {
            background: #e9ecef;
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
        }
        .icon-preview { font-size: 20px; }
        .budget-summary {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px 15px;
        }
        .color-dot {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .empty-state { padding: 60px 20px; }
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
        <h5 class="fw-bold mb-0"><i class="bi bi-wallet2"></i> Set Monthly Budgets</h5>
    </div>

    <!-- Alert Messages -->
    <?php if($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ========================================== -->
    <!-- MONTH NAVIGATION -->
    <!-- ========================================== -->
    <div class="card shadow-sm mb-4">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <a href="set_budget.php?month=<?php echo $prevMonth; ?>" class="btn btn-outline-secondary month-nav-btn">
                    <i class="bi bi-chevron-left"></i> Previous
                </a>
                <span class="current-month-badge ms-2 me-2">
                    <i class="bi bi-calendar3"></i> <?php echo date('F Y', strtotime($selected_month)); ?>
                </span>
                <?php if($selected_month < $currentMonth): ?>
                    <span class="badge bg-warning text-dark">Past Month</span>
                <?php elseif($selected_month == $currentMonth): ?>
                    <span class="badge bg-success">Current Month</span>
                <?php else: ?>
                    <span class="badge bg-info">Future Month</span>
                <?php endif; ?>
                <a href="set_budget.php?month=<?php echo $nextMonth; ?>" class="btn btn-outline-secondary month-nav-btn ms-2">
                    Next <i class="bi bi-chevron-right"></i>
                </a>
            </div>
            <div class="mt-2 mt-sm-0">
                <a href="set_budget.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-arrow-clockwise"></i> Go to Current Month
                </a>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- BUDGET FORM -->
    <!-- ========================================== -->
    <?php if (count($expenseCategories) > 0): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-bold">
                <i class="bi bi-plus-circle"></i> Add New Budget
            </div>
            <div class="card-body">
                <form method="POST" action="set_budget.php?month=<?php echo urlencode($selected_month); ?>">
                    <input type="hidden" name="month_year" value="<?php echo htmlspecialchars($selected_month); ?>">
                    <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="newBudgetCategory" class="form-label">New Budget Name</label>
                        <input type="text" id="newBudgetCategory" name="new_category_name" class="form-control" placeholder="e.g. Emergency Fund" maxlength="100" required>
                    </div>
                    <div class="col-md-3">
                        <label for="newBudgetIcon" class="form-label">Symbol</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-wallet2" data-new-budget-icon-preview></i></span>
                            <select id="newBudgetIcon" name="new_category_icon" class="form-select">
                                <?php foreach ($budgetIcons as $iconClass => $iconLabel): ?>
                                    <option value="<?php echo htmlspecialchars($iconClass); ?>"><?php echo htmlspecialchars($iconLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="newBudgetLimit" class="form-label">Monthly Limit (LKR)</label>
                        <input type="number" id="newBudgetLimit" name="new_limit" class="form-control" min="0.01" step="0.01" placeholder="Enter amount" required>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" name="add_budget" class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i> Add Budget
                        </button>
                    </div>
                    </div>
                </form>
                <small class="text-muted">Enter a new budget name and amount. It will be saved immediately as an expense category.</small>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-pencil"></i> Set Spending Limits</span>
                <span class="text-muted" style="font-size: 13px; font-weight: normal;">
                    Enter 0 or leave empty to remove the budget
                </span>
            </div>
            <div class="card-body">
                <form method="POST" action="set_budget.php?month=<?php echo urlencode($selected_month); ?>" id="budgetForm">
                    <input type="hidden" name="month_year" value="<?php echo $selected_month; ?>">
                    
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th>Icon</th>
                                    <th>Category</th>
                                    <th>Color</th>
                                    <th style="width: 200px;">Monthly Limit (LKR)</th>
                                    <th style="width: 170px; min-width: 170px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $count = 1;
                                foreach($expenseCategories as $cat):
                                    $currentLimit = isset($budgetMap[$cat['category_id']]) ? $budgetMap[$cat['category_id']] : '';
                                ?>
                                    <tr class="category-row">
                                        <td><?php echo $count++; ?></td>
                                        <td>
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text"><i class="bi <?php echo htmlspecialchars($cat['icon']); ?>" data-budget-icon-preview="<?php echo (int)$cat['category_id']; ?>"></i></span>
                                                <select name="budget_icon[<?php echo (int)$cat['category_id']; ?>]" class="form-select budget-icon-select d-none" data-budget-icon-select="<?php echo (int)$cat['category_id']; ?>" aria-label="Select icon for <?php echo htmlspecialchars($cat['category_name']); ?>" <?php echo $currentLimit > 0 ? 'disabled' : ''; ?>>
                                                    <?php foreach ($budgetIcons as $iconClass => $iconLabel): ?>
                                                                        <option value="<?php echo htmlspecialchars($iconClass); ?>" <?php echo $cat['icon'] === $iconClass ? 'selected' : ''; ?>><?php echo htmlspecialchars($iconLabel); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="text" name="budget_name[<?php echo (int)$cat['category_id']; ?>]" class="form-control-plaintext fw-bold budget-name-input" data-budget-name="<?php echo (int)$cat['category_id']; ?>" value="<?php echo htmlspecialchars($cat['category_name']); ?>" readonly maxlength="100">
                                        </td>
                                        <td>
                                            <span class="color-dot" style="background-color: <?php echo htmlspecialchars($cat['color']); ?>;"></span>
                                            <small class="text-muted"><?php echo htmlspecialchars($cat['color']); ?></small>
                                        </td>
                                        <td>
                                            <div class="input-group">
                                                <span class="input-group-text">LKR</span>
                                                                <input type="number" step="0.01" name="budget[<?php echo $cat['category_id']; ?>]"
                                                                         class="form-control budget-input"
                                                                         placeholder="0.00" min="0"
                                                                         id="budget_<?php echo $cat['category_id']; ?>"
                                                                         readonly
                                                                         value="<?php echo htmlspecialchars((string)$currentLimit); ?>">
                                            </div>
                                        </td>
                                        <td class="budget-action-cell">
                                            <?php if($currentLimit > 0): ?>
                                                <span class="badge bg-success"><i class="bi bi-check-circle"></i> Set</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><i class="bi bi-dash-circle"></i> Not Set</span>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary ms-1" data-budget-row-edit="<?php echo (int)$cat['category_id']; ?>" title="Edit budget" aria-label="Edit <?php echo htmlspecialchars($cat['category_name']); ?> budget">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-3">
                        <button type="submit" name="update_budgets" class="btn btn-success btn-lg px-5 rounded-pill">
                            <i class="bi bi-save"></i> Save All Budgets
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Budget Summary -->
        <div class="budget-summary mt-3 d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <i class="bi bi-info-circle"></i> 
                    <strong>Total Categories:</strong> <?php echo count($expenseCategories); ?> 
                <span class="ms-3"><strong>With Budgets Set:</strong> 
                    <?php 
                    $setCount = 0;
                    foreach($budgetMap as $limit) {
                        if($limit > 0) $setCount++;
                    }
                    echo $setCount;
                    ?>
                </span>
            </div>
            <div>
                <a href="#" data-reset-budgets class="text-danger" style="font-size: 13px;">
                    <i class="bi bi-arrow-counterclockwise"></i> Clear all budgets (set to 0)
                </a>
            </div>
        </div>

    <?php else: ?>
        <!-- No Expense Categories -->
        <div class="card shadow-sm">
            <div class="card-body text-center py-5 empty-state">
                <i class="bi bi-tags fs-1 text-muted d-block mb-3"></i>
                <h6>No Expense Categories Found!</h6>
                <p class="text-muted">You need to create at least one <strong>EXPENSE</strong> category before setting budgets.</p>
                <a href="manage_categories.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Manage Categories
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Quick Info -->
    <div class="alert alert-info mt-3" role="alert">
        <i class="bi bi-lightbulb"></i> 
        <strong>How it works:</strong> 
        Once you set a budget, the system will <strong>automatically generate alerts</strong> when you spend <strong>80%</strong> and <strong>100%</strong> of your limit. 
        These alerts will appear on your dashboard!
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
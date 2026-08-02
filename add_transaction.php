<?php
include 'config.php';
$user_id = require_login();

// Ensure new or existing users have selectable starter categories.
$categoryCountStmt = $conn->prepare("SELECT COUNT(*) AS total FROM categories WHERE user_id = ?");
$categoryCountStmt->bind_param("i", $user_id);
$categoryCountStmt->execute();
$categoryCount = (int)$categoryCountStmt->get_result()->fetch_assoc()['total'];
$categoryCountStmt->close();

if ($categoryCount === 0) {
    $defaultCategories = [
        ['Salary', 'INCOME', 'bi-briefcase', '#159f6e', 'Regular income'],
        ['Other income', 'INCOME', 'bi-cash', '#20c997', 'Additional income'],
        ['Food', 'EXPENSE', 'bi-egg-fried', '#e34d5f', 'Meals and groceries'],
        ['Transport', 'EXPENSE', 'bi-bus-front', '#f08c46', 'Travel and transport'],
        ['Bills', 'EXPENSE', 'bi-phone', '#8f5cff', 'Utilities and subscriptions'],
        ['Shopping', 'EXPENSE', 'bi-cart', '#1464ff', 'Personal and household shopping']
    ];
    $defaultCategoryStmt = $conn->prepare("INSERT INTO categories (user_id, category_name, type, icon, color, description) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($defaultCategories as $defaultCategory) {
        $defaultCategoryStmt->bind_param("isssss", $user_id, $defaultCategory[0], $defaultCategory[1], $defaultCategory[2], $defaultCategory[3], $defaultCategory[4]);
        $defaultCategoryStmt->execute();
    }
    $defaultCategoryStmt->close();
}

// ==========================================
// Fetch ACCOUNTS for the dropdown
// ==========================================
$accountStmt = $conn->prepare("SELECT * FROM accounts WHERE user_id = ? ORDER BY account_name");
$accountStmt->bind_param("i", $user_id);
$accountStmt->execute();
$accountResult = $accountStmt->get_result();

// ==========================================
// Fetch CATEGORIES for the dropdown
// ==========================================
$catStmt = $conn->prepare("SELECT * FROM categories WHERE user_id = ? ORDER BY type, category_name");
$catStmt->bind_param("i", $user_id);
$catStmt->execute();
$catResult = $catStmt->get_result();
$categories = $catResult->fetch_all(MYSQLI_ASSOC);

$message = '';

// ==========================================
// HANDLE FORM SUBMISSION (CREATE)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $account_id   = (int)$_POST['account_id'];
    $category_id  = (int)$_POST['category_id'];
    $amount       = (float)$_POST['amount'];
    $date         = $_POST['transaction_date'];
    $method       = htmlspecialchars(trim($_POST['payment_method']));
    $note         = htmlspecialchars(trim($_POST['note']));

    // Validate: Amount must be positive
    if ($amount <= 0) {
        $message = "⚠️ Amount must be greater than 0.";
    } else {
        $accountCheckStmt = $conn->prepare("SELECT current_balance FROM accounts WHERE account_id = ? AND user_id = ? LIMIT 1");
        $accountCheckStmt->bind_param("ii", $account_id, $user_id);
        $accountCheckStmt->execute();
        $account = $accountCheckStmt->get_result()->fetch_assoc();
        $accountCheckStmt->close();

        $catTypeStmt = $conn->prepare("SELECT type FROM categories WHERE category_id = ? AND user_id = ? LIMIT 1");
        $catTypeStmt->bind_param("ii", $category_id, $user_id);
        $catTypeStmt->execute();
        $category = $catTypeStmt->get_result()->fetch_assoc();
        $catTypeStmt->close();

        if (!$account || !$category) {
            $message = "⚠️ Please select a valid account and category.";
        } elseif ($category['type'] === 'EXPENSE' && $amount > (float)$account['current_balance']) {
            $message = "⚠️ Insufficient funds. Available balance: LKR " . number_format((float)$account['current_balance'], 2) . ".";
        } else {
        // ==========================================
        // STEP 1: INSERT TRANSACTION (Prepared Statement)
        // ==========================================
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, account_id, category_id, amount, transaction_date, payment_method, note) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiidsss", $user_id, $account_id, $category_id, $amount, $date, $method, $note);
        
        if ($stmt->execute()) {
            // ==========================================
            // STEP 2: UPDATE ACCOUNT BALANCE
            // ==========================================
            $catType = $category['type'];
            
            if ($catType == 'INCOME') {
                $updateBalance = "UPDATE accounts SET current_balance = current_balance + ? WHERE account_id = ?";
            } else {
                $updateBalance = "UPDATE accounts SET current_balance = current_balance - ? WHERE account_id = ?";
            }
            $balanceStmt = $conn->prepare($updateBalance);
            $balanceStmt->bind_param("di", $amount, $account_id);
            $balanceStmt->execute();
            $balanceStmt->close();
            
            // ==========================================
            // STEP 3: CHECK FOR BUDGET ALERTS (if expense)
            // ==========================================
            if ($catType == 'EXPENSE') {
                $currentMonth = date('Y-m-01');
                $checkBudget = "SELECT b.monthly_limit, c.category_name 
                                FROM budgets b 
                                JOIN categories c ON b.category_id = c.category_id 
                                WHERE b.user_id = ? 
                                AND b.category_id = ? 
                                AND b.month_year = ?";
                $budgetStmt = $conn->prepare($checkBudget);
                $budgetStmt->bind_param("iis", $user_id, $category_id, $currentMonth);
                $budgetStmt->execute();
                $budgetResult = $budgetStmt->get_result();
                
                if ($budgetResult->num_rows > 0) {
                    $budget = $budgetResult->fetch_assoc();
                    $limit = $budget['monthly_limit'];
                    
                    // Get total spent so far this month
                    $spentStmt = $conn->prepare("SELECT SUM(amount) as total
                                                  FROM transactions
                                                  WHERE user_id = ?
                                                  AND category_id = ?
                                                  AND MONTH(transaction_date) = MONTH(CURRENT_DATE())
                                                  AND YEAR(transaction_date) = YEAR(CURRENT_DATE())");
                    $spentStmt->bind_param("ii", $user_id, $category_id);
                    $spentStmt->execute();
                    $spentResult = $spentStmt->get_result();
                    $totalSpent = $spentResult->fetch_assoc()['total'] ?? 0;
                    $spentStmt->close();
                    
                    $percentage = ($totalSpent / $limit) * 100;
                    
                    // ==========================================
                    // STEP 4: INSERT NOTIFICATION (FIXED - Using Prepared Statement)
                    // ==========================================
                    if ($percentage > 100) {
                        // Insert notification: OVERSENT
                        $notifMsg = "You have EXCEEDED your '{$budget['category_name']}' budget! Spent LKR " . number_format($totalSpent, 2) . " / LKR " . number_format($limit, 2);
                        $notifTitle = '🚨 Budget Overspent';
                        $notifType = 'WARNING';
                        
                        $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
                        $notifStmt->bind_param("isss", $user_id, $notifTitle, $notifMsg, $notifType);
                        $notifStmt->execute();
                        $notifStmt->close();
                        
                    } elseif ($percentage > 80) {
                        // Insert notification: NEARING LIMIT
                        $notifMsg = "You have used " . round($percentage) . "% of your '{$budget['category_name']}' budget. Limit: LKR " . number_format($limit, 2);
                        $notifTitle = '⚠️ Budget Alert';
                        $notifType = 'WARNING';
                        
                        $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
                        $notifStmt->bind_param("isss", $user_id, $notifTitle, $notifMsg, $notifType);
                        $notifStmt->execute();
                        $notifStmt->close();
                    }
                }
            }
            
            // Redirect to dashboard with success message
            header("Location: index.php?success=1");
            exit();
        } else {
            $message = "❌ Error: " . $stmt->error;
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
    <title>Add New Income and Expense</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- External CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
    
    <style>
        body { background: #f4f6f9; padding-bottom: 70px; }
        .form-label { font-weight: 600; }
        .card { border-radius: 15px; border: none; }
        .btn-success { border-radius: 50px; padding: 12px; font-weight: 700; }
        .back-btn { border-radius: 50px; padding: 8px 20px; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container mt-3">
    <!-- Header with Back Button -->
    <div class="d-flex align-items-center mb-4">
        <a href="index.php" class="btn btn-outline-secondary back-btn me-3">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <h5 class="fw-bold mb-0">➕ Add New Income and Expense</h5>
    </div>

    <!-- Error Messages -->
    <?php if($message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-circle-fill"></i> <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Form Card -->
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" id="transactionForm">
                
                <!-- 1. ACCOUNT SELECTION -->
                <div class="mb-3">
                    <label class="form-label"><i class="bi bi-wallet2"></i> Select Account</label>
                    <select name="account_id" class="form-select" required>
                        <option value="">-- Choose Account --</option>
                        <?php while($acc = $accountResult->fetch_assoc()): ?>
                            <option value="<?php echo $acc['account_id']; ?>">
                                <?php echo htmlspecialchars($acc['account_name']); ?> 
                                (LKR <?php echo number_format($acc['current_balance'], 2); ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <small class="text-muted">Which account did this transaction use?</small>
                </div>

                <!-- 2. CATEGORY SELECTION -->
                <div class="mb-3">
                    <label class="form-label"><i class="bi bi-tags"></i> Category</label>
                    <select name="category_id" class="form-select" required>
                        <option value="">-- Select Category --</option>
                        <optgroup label="💰 INCOME">
                            <?php foreach ($categories as $cat): ?>
                                <?php if ($cat['type'] === 'INCOME'): ?>
                                    <option value="<?php echo (int)$cat['category_id']; ?>">
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="💸 EXPENSE">
                            <?php foreach ($categories as $cat): ?>
                                <?php if ($cat['type'] === 'EXPENSE'): ?>
                                    <option value="<?php echo (int)$cat['category_id']; ?>">
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <!-- 3. AMOUNT -->
                <div class="mb-3">
                    <label class="form-label"><i class="bi bi-currency-rupee"></i> Amount (LKR)</label>
                    <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" min="0.01" required>
                </div>

                <!-- 4. DATE -->
                <div class="mb-3">
                    <label class="form-label"><i class="bi bi-calendar3"></i> Date</label>
                    <input type="date" name="transaction_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <!-- 5. PAYMENT METHOD -->
                <div class="mb-3">
                    <label class="form-label"><i class="bi bi-credit-card"></i> Payment Method</label>
                    <select name="payment_method" class="form-select">
                        <option value="Cash">💰 Cash</option>
                        <option value="Card">💳 Card</option>
                        <option value="Bank Transfer">🏦 Bank Transfer</option>
                    </select>
                </div>

                <!-- 6. NOTE (Optional) -->
                <div class="mb-3">
                    <label class="form-label"><i class="bi bi-pencil"></i> Note (Optional)</label>
                    <input type="text" name="note" class="form-control" placeholder="e.g., Lunch at canteen, Bus fare">
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn btn-success w-100">
                    <i class="bi bi-check-circle"></i> Save Transaction
                </button>
            </form>
        </div>
    </div>

    <!-- Quick Tips -->
    <div class="alert alert-info mt-3" role="alert">
        <i class="bi bi-lightbulb"></i> <strong>Pro Tip:</strong> 
        When you select an account, its balance will update automatically when you save a transaction!
    </div>
</div>

<!-- ========================================== -->
<!-- BOTTOM NAVIGATION (FIXED - Same as Home Page) -->
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
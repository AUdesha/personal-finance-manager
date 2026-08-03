<?php
include 'config.php';
$user_id = require_login();

// ==========================================
// CHECK IF ID IS PROVIDED
// ==========================================
if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$transaction_id = (int)$_GET['id'];

// ==========================================
// FETCH THE TRANSACTION TO EDIT
// ==========================================
$stmt = $conn->prepare("SELECT t.*, c.type as category_type 
                        FROM transactions t 
                        JOIN categories c ON t.category_id = c.category_id 
                        WHERE t.transaction_id = ? AND t.user_id = ?");
$stmt->bind_param("ii", $transaction_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header("Location: index.php");
    exit();
}
$transaction = $result->fetch_assoc();
$stmt->close();

// Store old values for balance reversal
$old_account_id = $transaction['account_id'];
$old_amount = $transaction['amount'];
$old_category_type = $transaction['category_type'];

// ==========================================
// FETCH ALL ACCOUNTS FOR DROPDOWN
// ==========================================
$accountStmt = $conn->prepare("SELECT * FROM accounts WHERE user_id = ? ORDER BY account_name");
$accountStmt->bind_param("i", $user_id);
$accountStmt->execute();
$accountResult = $accountStmt->get_result();

// ==========================================
// FETCH ALL CATEGORIES FOR DROPDOWN
// ==========================================
$catStmt = $conn->prepare("SELECT * FROM categories WHERE user_id = ? ORDER BY type, category_name");
$catStmt->bind_param("i", $user_id);
$catStmt->execute();
$catResult = $catStmt->get_result();

$message = '';

// ==========================================
// HANDLE FORM SUBMISSION (UPDATE)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_account_id   = (int)$_POST['account_id'];
    $new_category_id  = (int)$_POST['category_id'];
    $new_amount       = (float)$_POST['amount'];
    $new_date         = $_POST['transaction_date'];
    $new_method       = htmlspecialchars(trim($_POST['payment_method']));
    $new_note         = htmlspecialchars(trim($_POST['note']));

    // Validate: Amount must be positive
    if ($new_amount <= 0) {
        $message = "⚠️ Amount must be greater than 0.";
    } else {
        // ==========================================
        // STEP 1: VALIDATE NEW ACCOUNT AND CATEGORY OWNERSHIP
        // ==========================================
        $catTypeStmt = $conn->prepare("SELECT type FROM categories WHERE category_id = ? AND user_id = ? LIMIT 1");
        $catTypeStmt->bind_param("ii", $new_category_id, $user_id);
        $catTypeStmt->execute();
        $catTypeResult = $catTypeStmt->get_result();

        if ($catTypeResult->num_rows === 0) {
            $message = "⚠️ The selected category is invalid.";
            $catTypeStmt->close();
        } else {
            $new_category_type = $catTypeResult->fetch_assoc()['type'];
            $catTypeStmt->close();

            $accountTypeStmt = $conn->prepare("SELECT account_id FROM accounts WHERE account_id = ? AND user_id = ? LIMIT 1");
            $accountTypeStmt->bind_param("ii", $new_account_id, $user_id);
            $accountTypeStmt->execute();
            $accountTypeResult = $accountTypeStmt->get_result();
            $accountTypeStmt->close();

            if ($accountTypeResult->num_rows === 0) {
                $message = "⚠️ The selected account is invalid.";
            }
        }

        if ($message === '') {
            // ==========================================
            // STEP 2: REVERSE THE OLD TRANSACTION EFFECT
            // ==========================================
            if ($old_category_type == 'INCOME') {
                $reverseStmt = $conn->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE account_id = ? AND user_id = ?");
                $reverseStmt->bind_param("dii", $old_amount, $old_account_id, $user_id);
                $reverseStmt->execute();
                $reverseStmt->close();
            } else {
                $reverseStmt = $conn->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE account_id = ? AND user_id = ?");
                $reverseStmt->bind_param("dii", $old_amount, $old_account_id, $user_id);
                $reverseStmt->execute();
                $reverseStmt->close();
            }

            // ==========================================
            // STEP 3: APPLY THE NEW TRANSACTION EFFECT
            // ==========================================
            if ($new_category_type === 'INCOME') {
                $balanceStmt = $conn->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE account_id = ? AND user_id = ?");
                $balanceStmt->bind_param("dii", $new_amount, $new_account_id, $user_id);
            } else {
                $balanceStmt = $conn->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE account_id = ? AND user_id = ?");
                $balanceStmt->bind_param("dii", $new_amount, $new_account_id, $user_id);
            }
            $balanceStmt->execute();
            $balanceStmt->close();

            // ==========================================
            // STEP 4: UPDATE THE TRANSACTION RECORD
            // ==========================================
            $stmt = $conn->prepare("UPDATE transactions 
                                    SET account_id = ?, 
                                        category_id = ?, 
                                        amount = ?, 
                                        transaction_date = ?, 
                                        payment_method = ?, 
                                        note = ? 
                                    WHERE transaction_id = ? AND user_id = ?");
            $stmt->bind_param("iidsssii", $new_account_id, $new_category_id, $new_amount, $new_date, $new_method, $new_note, $transaction_id, $user_id);
            
            if ($stmt->execute()) {
                if ($new_category_type === 'EXPENSE') {
                    $currentMonth = date('Y-m-01');
                    $checkBudget = "SELECT b.monthly_limit, c.category_name 
                                    FROM budgets b 
                                    JOIN categories c ON b.category_id = c.category_id 
                                    WHERE b.user_id = ? 
                                    AND b.category_id = ? 
                                    AND b.month_year = ?";
                    $budgetStmt = $conn->prepare($checkBudget);
                    $budgetStmt->bind_param("iis", $user_id, $new_category_id, $currentMonth);
                    $budgetStmt->execute();
                    $budgetResult = $budgetStmt->get_result();
                    
                    if ($budgetResult->num_rows > 0) {
                        $budget = $budgetResult->fetch_assoc();
                        $limit = (float)$budget['monthly_limit'];
                        
                        $spentStmt = $conn->prepare("SELECT SUM(amount) as total
                                                      FROM transactions
                                                      WHERE user_id = ?
                                                      AND category_id = ?
                                                      AND MONTH(transaction_date) = MONTH(CURRENT_DATE())
                                                      AND YEAR(transaction_date) = YEAR(CURRENT_DATE())");
                        $spentStmt->bind_param("ii", $user_id, $new_category_id);
                        $spentStmt->execute();
                        $spentResult = $spentStmt->get_result();
                        $totalSpent = $spentResult->fetch_assoc()['total'] ?? 0;
                        $spentStmt->close();
                        
                        $percentage = $limit > 0 ? ($totalSpent / $limit) * 100 : ($totalSpent > 0 ? 101 : 0);
                        
                        if ($percentage > 100) {
                            $notifMsg = "You have EXCEEDED your '{$budget['category_name']}' budget! Spent LKR " . number_format($totalSpent, 2) . " / LKR " . number_format($limit, 2);
                            $notifTitle = '🚨 Budget Overspent';
                            $notifType = 'WARNING';
                        } elseif ($percentage > 80) {
                            $notifMsg = "You have used " . round($percentage) . "% of your '{$budget['category_name']}' budget. Limit: LKR " . number_format($limit, 2);
                            $notifTitle = '⚠️ Budget Alert';
                            $notifType = 'WARNING';
                        } else {
                            $notifMsg = '';
                        }

                        if ($notifMsg !== '') {
                            $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
                            $notifStmt->bind_param("isss", $user_id, $notifTitle, $notifMsg, $notifType);
                            $notifStmt->execute();
                            $notifStmt->close();
                        }
                    }
                    $budgetStmt->close();
                }

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
    <title>Edit Transaction</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- External CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f4f6f9; padding-bottom: 70px; }
        .form-label { font-weight: 600; }
        .card { border-radius: 15px; border: none; }
        .btn-primary { border-radius: 50px; padding: 12px; font-weight: 700; }
        .back-btn { border-radius: 50px; padding: 8px 20px; }
        .old-value { font-size: 12px; color: #6c757d; }
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
        <h5 class="fw-bold mb-0">✏️ Edit Transaction</h5>
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
            <form method="POST">
                
                <!-- 1. ACCOUNT SELECTION -->
                <div class="mb-3">
                    <label class="form-label"><i class="bi bi-wallet2"></i> Select Account</label>
                    <select name="account_id" class="form-select" required>
                        <option value="">-- Choose Account --</option>
                        <?php while($acc = $accountResult->fetch_assoc()): 
                            $selected = ($acc['account_id'] == $transaction['account_id']) ? 'selected' : '';
                        ?>
                            <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
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
                            <?php 
                            $catResult->data_seek(0);
                            while($cat = $catResult->fetch_assoc()): 
                                if($cat['type'] == 'INCOME'):
                                    $selected = ($cat['category_id'] == $transaction['category_id']) ? 'selected' : '';
                            ?>
                                <option value="<?php echo $cat['category_id']; ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endif; endwhile; ?>
                        </optgroup>
                        <optgroup label="💸 EXPENSE">
                            <?php 
                            $catResult->data_seek(0);
                            while($cat = $catResult->fetch_assoc()): 
                                if($cat['type'] == 'EXPENSE'):
                                    $selected = ($cat['category_id'] == $transaction['category_id']) ? 'selected' : '';
                            ?>
                                <option value="<?php echo $cat['category_id']; ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endif; endwhile; ?>
                        </optgroup>
                    </select>
                </div>

                <!-- 3. AMOUNT -->
                <div class="mb-3">
                    <label class="form-label"><i class="bi bi-currency-rupee"></i> Amount (LKR)</label>
                    <input type="number" step="0.01" name="amount" class="form-control" value="<?php echo $transaction['amount']; ?>" min="0.01" required>
                    <small class="old-value">Old amount: LKR <?php echo number_format($transaction['amount'], 2); ?></small>
                </div>

                <!-- 4. DATE -->
                <div class="mb-3">
                    <label class="form-label"><i class="bi bi-calendar3"></i> Date</label>
                    <input type="date" name="transaction_date" class="form-control" value="<?php echo $transaction['transaction_date']; ?>" required>
                </div>

                <!-- 5. PAYMENT METHOD -->
                <div class="mb-3">
                    <label class="form-label"><i class="bi bi-credit-card"></i> Payment Method</label>
                    <select name="payment_method" class="form-select">
                        <option value="Cash" <?php echo ($transaction['payment_method'] == 'Cash') ? 'selected' : ''; ?>>💰 Cash</option>
                        <option value="Card" <?php echo ($transaction['payment_method'] == 'Card') ? 'selected' : ''; ?>>💳 Card</option>
                        <option value="Bank Transfer" <?php echo ($transaction['payment_method'] == 'Bank Transfer') ? 'selected' : ''; ?>>🏦 Bank Transfer</option>
                    </select>
                </div>

                <!-- 6. NOTE (Optional) -->
                <div class="mb-3">
                    <label class="form-label"><i class="bi bi-pencil"></i> Note (Optional)</label>
                    <input type="text" name="note" class="form-control" value="<?php echo htmlspecialchars($transaction['note']); ?>" placeholder="e.g., Lunch at canteen">
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-check-circle"></i> Update Transaction
                </button>
            </form>
        </div>
    </div>

    <!-- Quick Tips -->
    <div class="alert alert-info mt-3" role="alert">
        <i class="bi bi-lightbulb"></i> <strong>How it works:</strong> 
        When you update, the old amount is reversed from the old account, and the new amount is applied to the new account. Balances update automatically!
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
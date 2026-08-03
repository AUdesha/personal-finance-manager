<?php
include 'config.php';
$user_id = require_login();

$message = '';
$messageType = '';

// ==========================================
// HANDLE ADD ACCOUNT
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_account'])) {
    $account_name = htmlspecialchars(trim($_POST['account_name']));
    $account_type = htmlspecialchars(trim($_POST['account_type']));
    
    if (empty($account_name)) {
        $message = "⚠️ Account name is required.";
        $messageType = "danger";
    } else {
        $stmt = $conn->prepare("INSERT INTO accounts (user_id, account_name, account_type, current_balance) VALUES (?, ?, ?, 0.00)");
        $stmt->bind_param("iss", $user_id, $account_name, $account_type);
        
        if ($stmt->execute()) {
            $message = "✅ Account '{$account_name}' added successfully!";
            $messageType = "success";
        } else {
            $message = "❌ Error: " . $stmt->error;
            $messageType = "danger";
        }
        $stmt->close();
    }
}

// ==========================================
// HANDLE EDIT ACCOUNT
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_account'])) {
    $account_id = (int)$_POST['account_id'];
    $account_name = htmlspecialchars(trim($_POST['account_name']));
    $account_type = htmlspecialchars(trim($_POST['account_type']));
    
    if (empty($account_name)) {
        $message = "⚠️ Account name is required.";
        $messageType = "danger";
    } else {
        $stmt = $conn->prepare("UPDATE accounts SET account_name = ?, account_type = ? WHERE account_id = ? AND user_id = ?");
        $stmt->bind_param("ssii", $account_name, $account_type, $account_id, $user_id);
        
        if ($stmt->execute()) {
            $message = "✅ Account updated successfully!";
            $messageType = "success";
        } else {
            $message = "❌ Error: " . $stmt->error;
            $messageType = "danger";
        }
        $stmt->close();
    }
}

// ==========================================
// HANDLE DELETE ACCOUNT
// ==========================================
if (isset($_GET['delete'])) {
    $account_id = (int)$_GET['delete'];
    
    // Check if there are any transactions linked to this account
    $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM transactions WHERE account_id = ?");
    $checkStmt->bind_param("i", $account_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $count = $checkResult->fetch_assoc()['count'];
    $checkStmt->close();

    // Check if there are any transfers linked to this account
    $transferStmt = $conn->prepare("SELECT COUNT(*) as count FROM transfers WHERE from_account_id = ? OR to_account_id = ?");
    $transferStmt->bind_param("ii", $account_id, $account_id);
    $transferStmt->execute();
    $transferResult = $transferStmt->get_result();
    $transferCount = $transferResult->fetch_assoc()['count'];
    $transferStmt->close();
    
    if ($count > 0 || $transferCount > 0) {
        $message = "❌ Cannot delete this account! It has {$count} transaction(s) and {$transferCount} transfer record(s) linked to it. Please remove those first.";
        $messageType = "danger";
    } else {
        // Safe to delete
        $stmt = $conn->prepare("DELETE FROM accounts WHERE account_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $account_id, $user_id);
        
        if ($stmt->execute()) {
            $message = "✅ Account deleted successfully!";
            $messageType = "success";
        } else {
            $message = "❌ Error: " . $stmt->error;
            $messageType = "danger";
        }
        $stmt->close();
    }
}

// ==========================================
// FETCH EDIT DATA (if edit parameter is set)
// ==========================================
$editData = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM accounts WHERE account_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $edit_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $editData = $result->fetch_assoc();
    }
    $stmt->close();
}

// ==========================================
// FETCH ALL ACCOUNTS FOR DISPLAY
// ==========================================
$accountsStmt = $conn->prepare("SELECT a.*,
                                (SELECT COUNT(*) FROM transactions WHERE account_id = a.account_id) as transaction_count
                                FROM accounts a
                                WHERE a.user_id = ?
                                ORDER BY a.account_name");
$accountsStmt->bind_param("i", $user_id);
$accountsStmt->execute();
$accountsResult = $accountsStmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Accounts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f4f6f9; padding-bottom: 70px; }
        .card { border-radius: 15px; border: none; }
        .back-btn { border-radius: 50px; padding: 8px 20px; }
        .btn-sm { border-radius: 30px; }
        .table th { font-weight: 600; color: #495057; }
        .account-row:hover { background-color: #f8f9fa; }
        .badge-type { padding: 5px 12px; border-radius: 20px; font-size: 12px; }
        .badge-income { background: #d4edda; color: #155724; }
        .badge-expense { background: #f8d7da; color: #721c24; }
        .badge-neutral { background: #e9ecef; color: #495057; }
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
        <h5 class="fw-bold mb-0"><i class="bi bi-wallet2"></i> Manage Accounts</h5>
    </div>

    <!-- Alert Messages -->
    <?php if($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ========================================== -->
    <!-- ADD / EDIT ACCOUNT FORM -->
    <!-- ========================================== -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white fw-bold">
            <?php echo ($editData) ? '✏️ Edit Account' : '➕ Add New Account'; ?>
        </div>
        <div class="card-body">
            <form method="POST">
                <?php if($editData): ?>
                    <input type="hidden" name="account_id" value="<?php echo $editData['account_id']; ?>">
                <?php endif; ?>
                
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label fw-bold">Account Name</label>
                        <input type="text" name="account_name" class="form-control" 
                               placeholder="e.g., Wallet, Fixed Deposit" 
                               value="<?php echo ($editData) ? htmlspecialchars($editData['account_name']) : ''; ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Account Type</label>
                        <select name="account_type" class="form-select">
                            <option value="Cash" <?php echo ($editData && $editData['account_type'] == 'Cash') ? 'selected' : ''; ?>>Cash</option>
                            <option value="Savings" <?php echo ($editData && $editData['account_type'] == 'Savings') ? 'selected' : ''; ?>>Savings</option>
                            <option value="Bank" <?php echo ($editData && $editData['account_type'] == 'Bank') ? 'selected' : ''; ?>>Bank</option>
                            <option value="Wallet" <?php echo ($editData && $editData['account_type'] == 'Wallet') ? 'selected' : ''; ?>>Wallet (e-Wallet)</option>
                            <option value="Fixed Deposit" <?php echo ($editData && $editData['account_type'] == 'Fixed Deposit') ? 'selected' : ''; ?>>Fixed Deposit</option>
                            <option value="Other" <?php echo ($editData && $editData['account_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <?php if($editData): ?>
                            <button type="submit" name="edit_account" class="btn btn-primary w-100">
                                <i class="bi bi-check-circle"></i> Update Account
                            </button>
                            <a href="manage_accounts.php" class="btn btn-outline-secondary ms-2">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                        <?php else: ?>
                            <button type="submit" name="add_account" class="btn btn-success w-100">
                                <i class="bi bi-plus-circle"></i> Add Account
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- ACCOUNT LIST -->
    <!-- ========================================== -->
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-bold">
            <i class="bi bi-list-ul"></i> Your Accounts
            <span class="badge bg-secondary rounded-pill ms-2"><?php echo $accountsResult->num_rows; ?></span>
        </div>
        <div class="card-body p-0">
            <?php if ($accountsResult->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Account Name</th>
                                <th>Type</th>
                                <th>Balance</th>
                                <th>Transactions</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $count = 1;
                            while($acc = $accountsResult->fetch_assoc()): 
                                // Badge color for type
                                $typeBadge = 'badge-neutral';
                                if($acc['account_type'] == 'Savings' || $acc['account_type'] == 'Fixed Deposit') {
                                    $typeBadge = 'badge-income';
                                } elseif($acc['account_type'] == 'Bank' || $acc['account_type'] == 'Wallet') {
                                    $typeBadge = 'badge-neutral';
                                }
                            ?>
                                <tr class="account-row">
                                    <td><?php echo $count++; ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($acc['account_name']); ?></td>
                                    <td><span class="badge-type <?php echo $typeBadge; ?>"><?php echo htmlspecialchars($acc['account_type']); ?></span></td>
                                    <td class="fw-bold <?php echo ($acc['current_balance'] >= 0) ? 'text-success' : 'text-danger'; ?>">
                                        LKR <?php echo number_format($acc['current_balance'], 2); ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo ($acc['transaction_count'] > 0) ? 'bg-primary' : 'bg-secondary'; ?>">
                                            <?php echo $acc['transaction_count']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <!-- Edit Button -->
                                        <a href="manage_accounts.php?edit=<?php echo $acc['account_id']; ?>" class="btn btn-sm btn-outline-primary me-1" title="Edit Account">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <!-- Delete Button -->
                                        <?php if($acc['transaction_count'] == 0): ?>
                                            <a href="manage_accounts.php?delete=<?php echo $acc['account_id']; ?>" class="btn btn-sm btn-outline-danger" 
                                               data-confirm="Are you sure you want to delete this account? This action cannot be undone." title="Delete Account">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled title="Cannot delete - has <?php echo $acc['transaction_count']; ?> transaction(s)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-wallet2 fs-1 d-block"></i>
                    <small>No accounts yet. Add your first account above!</small>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Info -->
    <div class="alert alert-info mt-3" role="alert">
        <i class="bi bi-info-circle"></i> 
        <strong>Note:</strong> You cannot delete an account if it has any transactions linked to it. 
        The <strong>"Transactions"</strong> column shows how many transactions are linked to each account.
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
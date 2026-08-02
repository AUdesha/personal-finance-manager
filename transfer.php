<?php
include 'config.php';
$user_id = require_login();

// Create the transfer ledger table if it has not been installed yet.
$createTransfersStmt = $conn->prepare("CREATE TABLE IF NOT EXISTS transfers (
    transfer_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    from_account_id INT NOT NULL,
    to_account_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    transfer_date DATE NOT NULL,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transfers_user_id (user_id),
    INDEX idx_transfers_from_account (from_account_id),
    INDEX idx_transfers_to_account (to_account_id),
    CONSTRAINT fk_transfers_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    CONSTRAINT fk_transfers_from_account FOREIGN KEY (from_account_id) REFERENCES accounts(account_id),
    CONSTRAINT fk_transfers_to_account FOREIGN KEY (to_account_id) REFERENCES accounts(account_id)
)");
$createTransfersStmt->execute();
$createTransfersStmt->close();

$message = '';
$messageType = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $from_account_id = (int)($_POST['from_account_id'] ?? 0);
    $to_account_id = (int)($_POST['to_account_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $transfer_date = $_POST['transfer_date'] ?? date('Y-m-d');
    $note = htmlspecialchars(trim($_POST['note'] ?? ''));

    if ($from_account_id === $to_account_id) {
        $message = "Please choose different source and destination accounts.";
        $messageType = "danger";
    } elseif ($amount <= 0) {
        $message = "Amount must be greater than 0.";
        $messageType = "danger";
    } else {
        $conn->begin_transaction();

        $accountsStmt = $conn->prepare("SELECT account_id, current_balance
                                        FROM accounts
                                        WHERE user_id = ? AND account_id IN (?, ?)
                                        FOR UPDATE");
        $accountsStmt->bind_param("iii", $user_id, $from_account_id, $to_account_id);
        $accountsStmt->execute();
        $accountsResult = $accountsStmt->get_result();
        $accounts = [];
        while ($account = $accountsResult->fetch_assoc()) {
            $accounts[(int)$account['account_id']] = $account;
        }
        $accountsStmt->close();

        if (count($accounts) !== 2) {
            $conn->rollback();
            $message = "Both accounts must belong to your account.";
            $messageType = "danger";
        } elseif ((float)$accounts[$from_account_id]['current_balance'] < $amount) {
            $conn->rollback();
            $message = "The source account does not have enough money for this transfer.";
            $messageType = "danger";
        } else {
            $debitStmt = $conn->prepare("UPDATE accounts
                                         SET current_balance = current_balance - ?
                                         WHERE account_id = ? AND user_id = ?");
            $debitStmt->bind_param("dii", $amount, $from_account_id, $user_id);
            $debitSuccess = $debitStmt->execute();
            $debitStmt->close();

            $creditStmt = $conn->prepare("UPDATE accounts
                                          SET current_balance = current_balance + ?
                                          WHERE account_id = ? AND user_id = ?");
            $creditStmt->bind_param("dii", $amount, $to_account_id, $user_id);
            $creditSuccess = $creditStmt->execute();
            $creditStmt->close();

            $transferStmt = $conn->prepare("INSERT INTO transfers
                                            (user_id, from_account_id, to_account_id, amount, transfer_date, note)
                                            VALUES (?, ?, ?, ?, ?, ?)");
            $transferStmt->bind_param("iiidss", $user_id, $from_account_id, $to_account_id, $amount, $transfer_date, $note);
            $transferSuccess = $transferStmt->execute();
            $transferStmt->close();

            if ($debitSuccess && $creditSuccess && $transferSuccess) {
                $conn->commit();
                header("Location: index.php?success=transfer");
                exit();
            }

            $conn->rollback();
            $message = "Unable to complete the transfer. No account balances were changed.";
            $messageType = "danger";
        }
    }
}

$accountsStmt = $conn->prepare("SELECT account_id, account_name, account_type, current_balance
                                FROM accounts
                                WHERE user_id = ?
                                ORDER BY account_name");
$accountsStmt->bind_param("i", $user_id);
$accountsStmt->execute();
$accountsResult = $accountsStmt->get_result();
$accountsList = [];
while ($account = $accountsResult->fetch_assoc()) {
    $accountsList[] = $account;
}
$accountsStmt->close();

if (isset($_GET['success'])) {
    $message = "Transfer completed successfully.";
    $messageType = "success";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Money</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f4f6f9; padding-bottom: 70px; }
        .card { border-radius: 15px; border: none; }
        .back-btn { border-radius: 50px; padding: 8px 20px; }
        .transfer-icon { font-size: 2rem; color: #1464ff; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>
<div class="container mt-3">
    <div class="d-flex align-items-center mb-4">
        <a href="index.php" class="btn btn-outline-secondary back-btn me-3">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <h5 class="fw-bold mb-0"><i class="bi bi-arrow-left-right"></i> Transfer Money</h5>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <i class="bi <?php echo $messageType === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="text-center mb-3">
                <i class="bi bi-arrow-left-right transfer-icon"></i>
                <p class="text-muted mb-0">Move money between your accounts without changing income or expense totals.</p>
            </div>
            <?php if (count($accountsList) < 2): ?>
                <div class="alert alert-info mb-0">Add at least two accounts before making a transfer.</div>
            <?php else: ?>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label" for="from_account_id"><i class="bi bi-box-arrow-right"></i> From account</label>
                        <select name="from_account_id" id="from_account_id" class="form-select" required>
                            <option value="">-- Choose source account --</option>
                            <?php foreach ($accountsList as $account): ?>
                                <option value="<?php echo $account['account_id']; ?>">
                                    <?php echo htmlspecialchars($account['account_name']); ?>
                                    (LKR <?php echo number_format($account['current_balance'], 2); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="to_account_id"><i class="bi bi-box-arrow-in-left"></i> To account</label>
                        <select name="to_account_id" id="to_account_id" class="form-select" required>
                            <option value="">-- Choose destination account --</option>
                            <?php foreach ($accountsList as $account): ?>
                                <option value="<?php echo $account['account_id']; ?>">
                                    <?php echo htmlspecialchars($account['account_name']); ?>
                                    (LKR <?php echo number_format($account['current_balance'], 2); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="amount"><i class="bi bi-currency-exchange"></i> Amount (LKR)</label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="amount" class="form-control" placeholder="0.00" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="transfer_date"><i class="bi bi-calendar3"></i> Date</label>
                        <input type="date" name="transfer_date" id="transfer_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="note"><i class="bi bi-pencil"></i> Note (Optional)</label>
                        <textarea name="note" id="note" class="form-control" rows="2" maxlength="255" placeholder="e.g. Bank withdrawal"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 rounded-pill">
                        <i class="bi bi-arrow-left-right"></i> Complete Transfer
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

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

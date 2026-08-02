<?php
include 'config.php';

// ==========================================
// CHECK IF ID IS PROVIDED
// ==========================================
if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$transaction_id = (int)$_GET['id'];
$user_id = require_login();

// ==========================================
// STEP 1: FETCH TRANSACTION DETAILS
// ==========================================
$stmt = $conn->prepare("SELECT t.account_id, t.amount, c.type as category_type 
                        FROM transactions t 
                        JOIN categories c ON t.category_id = c.category_id 
                        WHERE t.transaction_id = ? AND t.user_id = ?");
$stmt->bind_param("ii", $transaction_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    // Transaction not found or doesn't belong to this user
    header("Location: index.php");
    exit();
}

$transaction = $result->fetch_assoc();
$stmt->close();

$account_id = $transaction['account_id'];
$amount = $transaction['amount'];
$category_type = $transaction['category_type'];

// ==========================================
// STEP 2: REVERSE THE EFFECT ON ACCOUNT BALANCE
// ==========================================
if ($category_type == 'INCOME') {
    // Income was ADDED to the account, so SUBTRACT it back
    $updateBalance = "UPDATE accounts SET current_balance = current_balance - ? WHERE account_id = ?";
} else {
    // Expense was SUBTRACTED from the account, so ADD it back
    $updateBalance = "UPDATE accounts SET current_balance = current_balance + ? WHERE account_id = ?";
}

$balanceStmt = $conn->prepare($updateBalance);
$balanceStmt->bind_param("di", $amount, $account_id);
if (!$balanceStmt->execute()) {
    // If the balance update fails, log the error but still try to delete
    // You can add error handling here if needed
}
$balanceStmt->close();

// ==========================================
// STEP 3: DELETE THE TRANSACTION
// ==========================================
$stmt = $conn->prepare("DELETE FROM transactions WHERE transaction_id = ? AND user_id = ?");
$stmt->bind_param("ii", $transaction_id, $user_id);

if ($stmt->execute()) {
    // Success: Redirect with success message
    header("Location: index.php?success=deleted");
} else {
    // Error: Redirect without success message
    header("Location: index.php");
}

$stmt->close();
$conn->close();
exit();
?>
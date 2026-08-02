<?php
include 'config.php';
$user_id = require_login();
$current_month = date('Y-m-01');

// Get all budgets for current month
$budgetStmt = $conn->prepare("SELECT b.*, c.category_name
                              FROM budgets b
                              JOIN categories c ON b.category_id = c.category_id
                              WHERE b.user_id = ? AND b.month_year = ?");
$budgetStmt->bind_param("is", $user_id, $current_month);
$budgetStmt->execute();
$budgetResult = $budgetStmt->get_result();

$alerts = [];
while($budget = $budgetResult->fetch_assoc()) {
    // Get actual spending for this category this month
    $category_id = $budget['category_id'];
    $spendStmt = $conn->prepare("SELECT SUM(amount) as total
                                 FROM transactions t
                                 WHERE t.user_id = ?
                                 AND t.category_id = ?
                                 AND MONTH(t.transaction_date) = MONTH(CURRENT_DATE())
                                 AND YEAR(t.transaction_date) = YEAR(CURRENT_DATE())");
    $spendStmt->bind_param("ii", $user_id, $category_id);
    $spendStmt->execute();
    $spendResult = $spendStmt->get_result();
    $actual = $spendResult->fetch_assoc()['total'] ?? 0;
    $spendStmt->close();
    
    $percentage = ($actual / $budget['monthly_limit']) * 100;
    if ($percentage > 80) {
        $alerts[] = [
            'category' => $budget['category_name'],
            'limit' => $budget['monthly_limit'],
            'actual' => $actual,
            'percentage' => round($percentage)
        ];
    }
}
?>
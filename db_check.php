<?php
// db_check.php - quick database connectivity check for local debugging
// Usage: open http://localhost:3307/personal_finance_manager/db_check.php in your browser
// NOTE: Do not commit this file to production or share its output publicly.

require __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

if (!isset($conn)) {
    echo "ERROR: Database connection object is not available.\n";
    echo "Ensure config.php is correct and loaded.\n";
    exit(1);
}

if ($conn->connect_error) {
    echo "ERROR: Could not connect to the database.\n";
    echo "Reason: " . $conn->connect_error . "\n\n";
    echo "Checklist:\n";
    echo " - Is MySQL running in XAMPP? (Start MySQL in the XAMPP control panel)\n";
    echo " - Are DB host/port correct in .env or config.php? (DB_HOST, DB_PORT)\n";
    echo " - If using 127.0.0.1 try that instead of localhost:3307.\n";
    exit(1);
}

// Try a simple query
if ($res = $conn->query('SELECT 1')) {
    echo "OK: Database connection successful. Test query returned 1.\n";
    $res->free();
    exit(0);
}

echo "WARNING: Connected to DB, but test query failed.\n";
echo "Error: " . $conn->error . "\n";
exit(1);

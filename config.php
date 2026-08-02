<?php
session_start();

$host = "localhost";
$username = "root";
$password = "";
$database = "personal_finance_manager";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

function require_login(): int
{
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }

    return (int)$_SESSION['user_id'];
}
?>
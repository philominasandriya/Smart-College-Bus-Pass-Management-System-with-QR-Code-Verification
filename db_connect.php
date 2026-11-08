<?php
// Start session for authentication and tracking
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "sngce_bus_pass";

// Create connection using MySQLi
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// Set charset to UTF-8 for proper currency/symbol support
$conn->set_charset("utf8");
?>
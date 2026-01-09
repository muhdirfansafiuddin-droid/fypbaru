<?php
// Database configuration for XAMPP
$host = "localhost";
$username = "root";
$password = ""; // Default XAMPP password is empty
$database = "caams_fyp";

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset("utf8mb4");

// Set timezone to Malaysia
date_default_timezone_set('Asia/Kuala_Lumpur');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!-- Database connected successfully -->";
?>

<?php
// admin/check_auth.php
session_start();

// Untuk development - boleh disable di production
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Debug info (comment out in production)
echo "<!-- SESSION DEBUG START -->\n";
echo "<!-- Session ID: " . session_id() . " -->\n";
echo "<!-- Session Data: \n";
print_r($_SESSION);
echo " -->\n";
echo "<!-- SESSION DEBUG END -->\n";

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../index.php?error=session_expired');
    exit();
}

// Check if user is admin (gunakan 'role' dari login)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php?error=access_denied');
    exit();
}

// Set global variables
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['name'] ?? 'Admin';
$military_number = $_SESSION['military_number'] ?? '';
?>
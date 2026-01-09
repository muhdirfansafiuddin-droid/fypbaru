<?php
// admin_header.php - PASTIKAN ada di awal fail
session_start();

// Debug: Semak session
if (!isset($_SESSION['user_id'])) {
    die("Session user_id not set. Redirecting to login.");
}

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}
?>
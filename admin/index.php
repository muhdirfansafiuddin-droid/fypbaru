<?php
// public/admin/index.php - PROTECTION FILE
session_start();

// Jika belum login, pergi ke login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../index.php");
    exit();
}

// Jika sudah login tapi bukan admin, pergi ke unauthorized
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../unauthorized.php");
    exit();
}

// Jika dah admin dan dah login, pergi ke dashboard
header("Location: dashboard.php");
exit();
?>
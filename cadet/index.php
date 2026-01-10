<?php
// public/cadet/index.php - PROTECTION FILE
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../index.php");
    exit();
}

if ($_SESSION['role'] !== 'cadet') {
    header("Location: ../unauthorized.php");
    exit();
}

header("Location: dashboard.php");
exit();
?>
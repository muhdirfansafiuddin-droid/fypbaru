<?php
// public/rankholder/index.php - PROTECTION FILE
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../index.php");
    exit();
}

if ($_SESSION['role'] !== 'rankholder') {
    header("Location: ../unauthorized.php");
    exit();
}

header("Location: dashboard.php");
exit();
?>
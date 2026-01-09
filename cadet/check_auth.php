<?php
// cadet/check_auth.php
session_start();

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cadet') {
    header('Location: ../index.php?error=access_denied');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['name'] ?? 'Cadet';
$military_number = $_SESSION['military_number'] ?? '';
?>
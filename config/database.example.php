<?php
// config/database.php
// JANGAN ada session_start() di sini jika sudah ada di check_auth.php
// Hanya connection saja

$host = 'localhost';
$dbname = 'caams_fyp';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
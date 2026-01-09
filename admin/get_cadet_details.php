<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$cadet_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'cadet'");
$stmt->execute([$cadet_id]);
$cadet = $stmt->fetch(PDO::FETCH_ASSOC);

if ($cadet) {
    // Get attendance stats
    $attendance_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_attendance,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days
        FROM attendance 
        WHERE user_id = ?
    ");
    $attendance_stmt->execute([$cadet_id]);
    $attendance = $attendance_stmt->fetch(PDO::FETCH_ASSOC);
    
    $cadet['attendance_stats'] = $attendance;
    
    echo json_encode(['success' => true, 'cadet' => $cadet]);
} else {
    echo json_encode(['success' => false, 'message' => 'Cadet not found']);
}
?>
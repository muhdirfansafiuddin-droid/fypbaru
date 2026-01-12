<?php
// rankholder/update_attendance.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

// Set header untuk JSON
header('Content-Type: application/json');

// Check permission
RBAC::checkPermission('rankholder');

try {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    
    // Check if user is logged in
    if (!$user || $user['role'] !== 'rankholder') {
        throw new Exception('Unauthorized access');
    }
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        throw new Exception('Invalid CSRF token');
    }
    
    // Validate required fields
    if (!isset($_POST['attendance_id']) || !is_numeric($_POST['attendance_id'])) {
        throw new Exception('Invalid attendance ID');
    }
    
    if (!isset($_POST['status']) || !in_array($_POST['status'], ['present', 'absent', , 'excused'])) {
        throw new Exception('Invalid status value');
    }
    
    $attendance_id = (int)$_POST['attendance_id'];
    $status = $_POST['status'];
    $note = $_POST['note'] ?? '';
    $rankholder_id = $user['user_id'];
    
    $db = new Database();
    
    // Check if attendance exists and belongs to this rankholder
    $checkQuery = "SELECT a.attendance_id, a.verified_by 
                  FROM attendance a
                  WHERE a.attendance_id = ? AND a.checked_by = ?";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bind_param("ii", $attendance_id, $rankholder_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        throw new Exception('Attendance record not found or access denied');
    }
    
    $attendance = $checkResult->fetch_assoc();
    
    if ($attendance['verified_by']) {
        throw new Exception('Cannot edit verified attendance');
    }
    
    // Update attendance
    $updateQuery = "UPDATE attendance 
                   SET status = ?, note = ?, recorded_at = NOW()
                   WHERE attendance_id = ?";
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bind_param("ssi", $status, $note, $attendance_id);
    
    if ($updateStmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Attendance updated successfully'
        ]);
    } else {
        throw new Exception('Failed to update database');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
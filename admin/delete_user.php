<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $userId = $_POST['id'] ?? 0;
    
    if ($userId) {
        try {
            // Check if user exists
            $stmt = $pdo->prepare("SELECT role, name, military_number FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                echo json_encode(['success' => false, 'message' => 'User not found']);
                exit();
            }
            
            // Don't allow deleting admin accounts
            if ($user['role'] == 'admin') {
                echo json_encode(['success' => false, 'message' => 'Cannot delete admin accounts']);
                exit();
            }
            
            // Log the activity before deleting
            $activityStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type, description, related_id)
                VALUES (?, 'user_deleted', ?, ?)
            ");
            $activityStmt->execute([
                $_SESSION['user_id'],
                "Deleted user: {$user['name']} ({$user['military_number']})",
                $userId
            ]);
            
            // Delete user
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    }
}
?>
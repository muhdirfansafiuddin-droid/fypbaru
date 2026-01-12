<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $userId = $_POST['user_id'] ?? 0;
    $name = $_POST['name'] ?? '';
    $military_number = $_POST['military_number'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $service_type = $_POST['service_type'] ?? '';
    $rank_level = $_POST['rank_level'] ?? '';
    $join_date = $_POST['join_date'] ?? '';
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $address = $_POST['address'] ?? '';
    
    if ($userId && $name && $military_number) {
        try {
            // Check if military number already exists for another user
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE military_number = ? AND user_id != ?");
            $stmt->execute([$military_number, $userId]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Military number already exists for another user']);
                exit();
            }
            
            // Check if email already exists for another user
            if (!empty($email)) {
                $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                $stmt->execute([$email, $userId]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Email already exists for another user']);
                    exit();
                }
            }
            
            // Update user
            $stmt = $pdo->prepare("
                UPDATE users SET 
                name = ?, 
                military_number = ?, 
                email = ?, 
                phone = ?, 
                service_type = ?, 
                rank_level = ?, 
                join_date = ?, 
                date_of_birth = ?, 
                address = ?
                WHERE user_id = ?
            ");
            
            $stmt->execute([
                $name, $military_number, $email, $phone, 
                $service_type, $rank_level, $join_date, 
                $date_of_birth, $address, $userId
            ]);
            
            // Log the activity
            $activityStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type, description, related_id)
                VALUES (?, 'user_updated', ?, ?)
            ");
            $activityStmt->execute([
                $_SESSION['user_id'],
                "Updated user details: {$name} ({$military_number})",
                $userId
            ]);
            
            echo json_encode(['success' => true, 'message' => 'User updated successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Required fields missing']);
    }
}
?>
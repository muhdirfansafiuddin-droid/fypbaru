<?php
// app/controllers/UserController.php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Session.php';

class UserController extends Database {
    
    public function registerUser($data) {
        $errors = [];
        
        // Validate required fields
        if (empty($data['military_number'])) {
            $errors[] = "Military number is required";
        }
        
        if (empty($data['name'])) {
            $errors[] = "Full name is required";
        }
        
        if (empty($data['password'])) {
            $errors[] = "Password is required";
        } elseif (strlen($data['password']) < 6) {
            $errors[] = "Password must be at least 6 characters";
        }
        
        if (empty($data['role'])) {
            $errors[] = "Role is required";
        }
        
        // Validate role-specific fields
        if ($data['role'] != 'admin') {
            if (empty($data['service_type'])) {
                $errors[] = "Service type is required for " . $data['role'];
            }
            if (empty($data['rank_level'])) {
                $errors[] = "Rank level is required for " . $data['role'];
            }
        }
        
        // Check if military number already exists
        if (empty($errors)) {
            $checkSql = "SELECT user_id FROM users WHERE military_number = ?";
            $checkStmt = $this->prepare($checkSql);
            $checkStmt->bind_param("s", $data['military_number']);
            $checkStmt->execute();
            
            if ($checkStmt->get_result()->num_rows > 0) {
                $errors[] = "Military number already exists";
            }
        }
        
        // If no errors, insert into database
        if (empty($errors)) {
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            
            // Set service_type and rank_level to NULL for admin
            $serviceType = ($data['role'] == 'admin') ? NULL : $data['service_type'];
            $rankLevel = ($data['role'] == 'admin') ? NULL : $data['rank_level'];
            
            $sql = "INSERT INTO users (military_number, password, role, name, email, service_type, rank_level) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->prepare($sql);
            $stmt->bind_param(
                "sssssss", 
                $data['military_number'],
                $hashedPassword,
                $data['role'],
                $data['name'],
                $data['email'],
                $serviceType,
                $rankLevel
            );
            
            if ($stmt->execute()) {
                // Log the activity
                $this->logActivity(
                    Session::get('user_id'),
                    'user_registered',
                    "Registered new " . $data['role'] . ": " . $data['name'],
                    $stmt->insert_id
                );
                
                return [
                    'success' => true,
                    'message' => 'User registered successfully! User ID: ' . $stmt->insert_id
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Database error: ' . $stmt->error
                ];
            }
        }
        
        return [
            'success' => false,
            'message' => implode('<br>', $errors)
        ];
    }
    
    public function getCadetsByServiceRank($service = null, $rank = null) {
        $sql = "SELECT user_id, military_number, name, service_type, rank_level, created_at 
                FROM users 
                WHERE role = 'cadet'";
        
        $params = [];
        $types = "";
        
        if ($service) {
            $sql .= " AND service_type = ?";
            $params[] = $service;
            $types .= "s";
        }
        
        if ($rank) {
            $sql .= " AND rank_level = ?";
            $params[] = $rank;
            $types .= "s";
        }
        
        $sql .= " ORDER BY name ASC";
        
        $stmt = $this->prepare($sql);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        return $stmt->get_result();
    }
    
    private function logActivity($userId, $type, $description, $relatedId = null) {
        $sql = "INSERT INTO activity_logs (user_id, activity_type, description, related_id, ip_address) 
                VALUES (?, ?, ?, ?, ?)";
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $this->prepare($sql);
        $stmt->bind_param("issss", $userId, $type, $description, $relatedId, $ip);
        $stmt->execute();
    }
}
?>
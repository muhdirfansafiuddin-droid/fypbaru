<?php
// app/core/Auth.php - NEW FILE
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Session.php';

class Auth extends Database {
    
    public function login($military_number, $password) {
        $sql = "SELECT * FROM users WHERE military_number = ?";
        $stmt = $this->prepare($sql);
        
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error'];
        }
        
        $stmt->bind_param("s", $military_number);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return ['success' => false, 'message' => 'Nombor tentera tidak dijumpai'];
        }
        
        $user = $result->fetch_assoc();
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'message' => 'Kata laluan tidak betul'];
        }
        
        // Set session
        Session::set('user_id', $user['user_id']);
        Session::set('role', $user['role']);
        Session::set('name', $user['name']);
        Session::set('military_number', $user['military_number']);
        
        return [
            'success' => true,
            'user_id' => $user['user_id'],
            'role' => $user['role'],
            'name' => $user['name']
        ];
    }
    
    public function isLoggedIn() {
        return Session::isLoggedIn();
    }
    
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        $userId = Session::get('user_id');
        $sql = "SELECT * FROM users WHERE user_id = ?";
        $stmt = $this->prepare($sql);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }
    
    public function logout() {
        Session::destroy();
        return ['success' => true, 'message' => 'Logout successful'];
    }
}
?>
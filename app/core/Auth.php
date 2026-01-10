
<?php
// app/core/Auth.php - FIXED
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class Auth {
    private $db;
    
    public function __construct() {
        require_once __DIR__ . '/Database.php';
        $this->db = new Database();
    }
    
    public function login($military_number, $password) {
        $sql = "SELECT * FROM users WHERE military_number = ?";
        $stmt = $this->db->prepare($sql);
        
        if (!$stmt) {
            error_log("Prepare failed: " . $this->db->getConnection()->error);
            return false;
        }
        
        $stmt->bind_param("s", $military_number);
        
        if (!$stmt->execute()) {
            error_log("Execute failed: " . $stmt->error);
            return false;
        }
        
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                // Update last login
                $updateSql = "UPDATE users SET last_login = NOW() WHERE user_id = ?";
                $updateStmt = $this->db->prepare($updateSql);
                $updateStmt->bind_param("i", $user['user_id']);
                $updateStmt->execute();
                
                return $user;
            } else {
                error_log("Password verification FAILED for: $military_number");
                return false;
            }
        }
        
        return false;
    }
    
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            $this->logActivity($_SESSION['user_id'], 'user_logout', 'User logged out');
        }
        
        $_SESSION = array();
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
    
    public function getCurrentUser() {
        if ($this->isLoggedIn() && isset($_SESSION['user_id'])) {
            $sql = "SELECT * FROM users WHERE user_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                return $result->fetch_assoc();
            }
        }
        return null;
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header("Location: ../index.php");
            exit();
        }
    }
    
    public function requireRole($requiredRole) {
        $this->requireLogin();
        
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== $requiredRole) {
            header("Location: ../unauthorized.php");
            exit();
        }
    }
    
    private function logActivity($user_id, $activity_type, $description) {
        // Check if ip_address column exists
        try {
            // Try with ip_address first
            $sql = "INSERT INTO activity_logs (user_id, activity_type, description, ip_address) 
                    VALUES (?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            
            if ($stmt) {
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $stmt->bind_param("isss", $user_id, $activity_type, $description, $ip_address);
                $stmt->execute();
            }
        } catch (Exception $e) {
            // If ip_address column doesn't exist, use without it
            try {
                $sql = "INSERT INTO activity_logs (user_id, activity_type, description) 
                        VALUES (?, ?, ?)";
                $stmt = $this->db->prepare($sql);
                
                if ($stmt) {
                    $stmt->bind_param("iss", $user_id, $activity_type, $description);
                    $stmt->execute();
                }
            } catch (Exception $e2) {
                // Log error but don't break the flow
                error_log("Failed to log activity: " . $e2->getMessage());
            }
        }
    }
}
?>
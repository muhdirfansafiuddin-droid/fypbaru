<?php
// app/core/RBAC.php - FIXED
// REMOVE session_start() from here since it's already started in Auth.php

class RBAC {
    private static $permissions = [
        'admin' => ['admin', 'rankholder', 'cadet'],
        'rankholder' => ['rankholder', 'cadet'],
        'cadet' => ['cadet']
    ];
    
    public static function checkPermission($required_role) {
        // Check if session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['role'])) {
            header("Location: ../index.php");
            exit();
        }
        
        $user_role = $_SESSION['role'];
        
        // Check if user role has permission to access this page
        if (!isset(self::$permissions[$user_role]) || 
            !in_array($required_role, self::$permissions[$user_role])) {
            
            // Log unauthorized access
            self::logUnauthorizedAccess($user_role, $required_role);
            
            header("Location: ../unauthorized.php");
            exit();
        }
        
        return true;
    }
    
    // ... rest of the code remains the same ...
}
?>
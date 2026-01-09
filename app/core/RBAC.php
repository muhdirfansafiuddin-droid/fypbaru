<?php
// app/core/RBAC.php
require_once __DIR__ . '/Session.php';

class RBAC {
    public static function checkPermission($requiredRole) {
        if (!Session::isLoggedIn()) {
            header('Location: ../../auth/login.php');
            exit();
        }
        
        $userRole = Session::get('role');
        
        // Role hierarchy (Admin > Rankholder > Cadet)
        $roleHierarchy = [
            'admin' => 3,
            'rankholder' => 2,
            'cadet' => 1
        ];
        
        // Check if user has required role or higher
        if (!isset($roleHierarchy[$userRole]) || 
            $roleHierarchy[$userRole] < $roleHierarchy[$requiredRole]) {
            
            echo "Access Denied. You need $requiredRole privileges.";
            exit();
        }
        
        return true;
    }
    
    public static function redirectByRole() {
        $role = Session::get('role');
        
        switch($role) {
            case 'admin':
                header('Location: ../../admin/dashboard.php');
                break;
            case 'rankholder':
                header('Location: ../../rankholder/dashboard.php');
                break;
            case 'cadet':
                header('Location: ../../cadet/dashboard.php');
                break;
            default:
                header('Location: ../../auth/login.php');
        }
        exit();
    }
}
?>
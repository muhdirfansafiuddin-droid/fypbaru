<?php
// includes/auth.php - UPDATED VERSION
session_start();

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Get current user role
function getCurrentRole() {
    return $_SESSION['role'] ?? null;
}

// Check if user has specific role
function hasRole($role) {
    return isLoggedIn() && $_SESSION['role'] === $role;
}

// Require authentication
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: ../login.php');
        exit();
    }
}

// Require specific role
function requireRole($role) {
    requireAuth();
    
    if (!hasRole($role)) {
        // Redirect to unauthorized page or their own dashboard
        redirectToDashboard();
        exit();
    }
}

// NEW FUNCTION: Check if user can access page based on role
function canAccessPage($requiredRole) {
    if (!isLoggedIn()) return false;
    
    $userRole = $_SESSION['role'];
    
    // Role hierarchy (Admin > Rankholder > Cadet)
    $hierarchy = [
        'admin' => 3,
        'rankholder' => 2,
        'cadet' => 1
    ];
    
    return isset($hierarchy[$userRole]) && 
           isset($hierarchy[$requiredRole]) && 
           $hierarchy[$userRole] >= $hierarchy[$requiredRole];
}

// NEW FUNCTION: Redirect to appropriate dashboard
function redirectToDashboard() {
    if (!isLoggedIn()) {
        header('Location: ../login.php');
        exit();
    }
    
    $role = getCurrentRole();
    
    switch($role) {
        case 'admin':
            header('Location: admin/dashboard.php');
            exit();
        case 'rankholder':
            header('Location: rankholder/dashboard.php');
            exit();
        case 'cadet':
            header('Location: cadet/dashboard.php');
            exit();
        default:
            header('Location: ../login.php');
            exit();
    }
}

// NEW FUNCTION: Get dashboard URL for current user
function getDashboardUrl() {
    if (!isLoggedIn()) return 'login.php';
    
    $role = getCurrentRole();
    
    switch($role) {
        case 'admin':
            return 'admin/dashboard.php';
        case 'rankholder':
            return 'rankholder/dashboard.php';
        case 'cadet':
            return 'cadet/dashboard.php';
        default:
            return 'login.php';
    }
}

// Get current user data
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    
    return [
        'id' => $_SESSION['user_id'],
        'military_number' => $_SESSION['military_number'],
        'name' => $_SESSION['name'],
        'role' => $_SESSION['role'],
        'email' => $_SESSION['email'],
        'profile_image' => $_SESSION['profile_image']
    ];
}

// NEW FUNCTION: Check admin authentication specifically for register_user.php
function checkAdminAuth() {
    requireAuth();
    
    if (!hasRole('admin')) {
        // Instead of showing error, redirect to their dashboard
        redirectToDashboard();
        exit();
    }
}

// NEW FUNCTION: Check rankholder authentication
function checkRankholderAuth() {
    requireAuth();
    
    if (!hasRole('rankholder')) {
        redirectToDashboard();
        exit();
    }
}

// NEW FUNCTION: Check cadet authentication
function checkCadetAuth() {
    requireAuth();
    
    if (!hasRole('cadet')) {
        redirectToDashboard();
        exit();
    }
}
?>
<?php
// logout.php
session_start();

// Log activity if user was logged in
if (isset($_SESSION['user_id'])) {
    require_once 'includes/db_connect.php';
    
    $sql = "INSERT INTO activity_logs (user_id, activity_type, description) 
            VALUES (?, 'logout', 'User logged out')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
}

// Destroy session
session_destroy();

// Redirect to login
header('Location: login.php');
exit();
?>
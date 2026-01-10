<?php
session_start();
require_once 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $military_number = mysqli_real_escape_string($conn, $_POST['military_number']);
    $password = $_POST['password'];
    
    // Query untuk dapatkan user berdasarkan military_number
    $sql = "SELECT * FROM users WHERE military_number = ? AND user_id IS NOT NULL";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $military_number);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        if (password_verify($password, $row['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['military_number'] = $row['military_number'];
            $_SESSION['name'] = $row['name'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['rank_level'] = $row['rank_level'];
            
            // Log aktiviti login
            $log_sql = "INSERT INTO activity_logs (user_id, activity_type, description) 
                       VALUES (?, 'user_registered', 'User logged into system')";
            $log_stmt = mysqli_prepare($conn, $log_sql);
            mysqli_stmt_bind_param($log_stmt, "i", $row['user_id']);
            mysqli_stmt_execute($log_stmt);
            
            // Redirect berdasarkan role
            switch ($row['role']) {
                case 'admin':
                    header("Location: admin/dashboard.php");
                    break;
                    
                case 'rankholder':
                    header("Location: rankholder/dashboard.php");
                    break;
                    
                case 'cadet':
                    header("Location: cadet/dashboard.php");
                    break;
                    
                default:
                    header("Location: index.php?error=invalid_role");
                    break;
            }
            exit();
        } else {
            header("Location: index.php?error=invalid_password");
            exit();
        }
    } else {
        header("Location: index.php?error=user_not_found");
        exit();
    }
}
?>
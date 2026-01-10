<?php
// logout.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Log logout activity jika ada session
if (isset($_SESSION['user_id'])) {
    require_once 'config/database.php';
    
    // Check jika database connection ada
    if (isset($conn) && $conn) {
        try {
            $log_sql = "INSERT INTO activity_logs (user_id, activity_type, description) 
                       VALUES (?, 'user_registered', 'User logged out from system')";
            $log_stmt = mysqli_prepare($conn, $log_sql);
            if ($log_stmt) {
                mysqli_stmt_bind_param($log_stmt, "i", $_SESSION['user_id']);
                mysqli_stmt_execute($log_stmt);
                mysqli_stmt_close($log_stmt);
            }
        } catch (Exception $e) {
            // Continue dengan logout walaupun log gagal
            error_log("Logout activity log failed: " . $e->getMessage());
        }
    }
}

// Destroy semua session data
$_SESSION = array();

// Jika ingin destroy session cookie juga
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Akhir sekali destroy session
session_destroy();

// Redirect ke login page dengan absolute path
header("Location: index.php");
exit();
?>
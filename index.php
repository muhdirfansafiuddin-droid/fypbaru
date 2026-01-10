<?php
// index.php - SIMPLE WORKING VERSION
ob_start();
session_start();

// Debug info
echo "<!-- Starting login process -->\n";

// Include files
$auth_path = __DIR__ . '/app/core/Auth.php';
echo "<!-- Auth path: $auth_path -->\n";

if (!file_exists($auth_path)) {
    die("Auth.php not found at: $auth_path");
}

require_once $auth_path;

try {
    $auth = new Auth();
    $error = '';
    
    echo "<!-- Auth object created -->\n";
    
    // Check if already logged in
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        echo "<!-- Already logged in -->\n";
        if ($_SESSION['role'] === 'admin') {
            header("Location: admin/dashboard.php");
            exit();
        } elseif ($_SESSION['role'] === 'rankholder') {
            header("Location: rankholder/dashboard.php");
            exit();
        } elseif ($_SESSION['role'] === 'cadet') {
            header("Location: cadet/dashboard.php");
            exit();
        }
    }
    
    // Handle POST login
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $military_number = trim($_POST['military_number'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        echo "<!-- Attempting login for: $military_number -->\n";
        
        if (empty($military_number) || empty($password)) {
            $error = "Sila isi kedua-dua medan";
        } else {
            // Direct database connection for testing
            require_once __DIR__ . '/app/core/Database.php';
            $db = new Database();
            
            $sql = "SELECT * FROM users WHERE military_number = ?";
            $stmt = $db->prepare($sql);
            
            if ($stmt) {
                $stmt->bind_param("s", $military_number);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    
                    // Test with known password hash
                    $test_hash = '$2y$10$zBvyVvJX1VLdZ3YzwFoW2eTj63AJZ/Ad7.YJkybLoUwAb/2TZZi6q';
                    
                    if (password_verify($password, $user['password'])) {
                        echo "<!-- Login SUCCESS -->\n";
                        
                        // Set session
                        $_SESSION['user_id'] = $user['user_id'];
                        $_SESSION['military_number'] = $user['military_number'];
                        $_SESSION['name'] = $user['name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['service_type'] = $user['service_type'] ?? null;
                        $_SESSION['rank_level'] = $user['rank_level'] ?? null;
                        $_SESSION['logged_in'] = true;
                        
                        session_write_close();
                        
                        // Redirect
                        if ($user['role'] === 'admin') {
                            header("Location: admin/dashboard.php");
                            exit();
                        } elseif ($user['role'] === 'rankholder') {
                            header("Location: rankholder/dashboard.php");
                            exit();
                        } else {
                            header("Location: cadet/dashboard.php");
                            exit();
                        }
                    } else {
                        $error = "Kata laluan tidak sah";
                        echo "<!-- Password verification failed -->\n";
                    }
                } else {
                    $error = "Nombor tentera tidak dijumpai";
                    echo "<!-- User not found -->\n";
                }
            } else {
                $error = "System error. Sila cuba lagi.";
                echo "<!-- Database prepare failed -->\n";
            }
        }
    }
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CAAMS - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            width: 100%;
            max-width: 450px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .login-header {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .system-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }
        
        .system-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
        }
        
        .login-body {
            padding: 40px 30px;
        }
        
        .error-alert {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 5px solid #f56565;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d3748;
        }
        
        .input-group {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
        }
        
        input {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        input:focus {
            border-color: #3182ce;
            outline: none;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 15px;
            background: #3182ce;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn:hover {
            background: #2c5282;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .login-footer {
            text-align: center;
            padding: 20px;
            border-top: 1px solid #e2e8f0;
            color: #718096;
            font-size: 0.9rem;
        }
        
        @media (max-width: 480px) {
            .login-container {
                max-width: 100%;
            }
            
            .login-header {
                padding: 30px 20px;
            }
            
            .login-body {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1 class="system-title">CAAMS</h1>
            <p class="system-subtitle">Centralized Attendance & Allowance Management System</p>
        </div>
        
        <div class="login-body">
            <?php if (!empty($error)): ?>
                <div class="error-alert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="military_number">Nombor Tentera</label>
                    <div class="input-group">
                        <i class="fas fa-id-card input-icon"></i>
                        <input type="text" 
                               id="military_number" 
                               name="military_number" 
                               placeholder="Contoh: ADM001, RH001, CD001"
                               required
                               autofocus
                               value="ADM001">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Kata Laluan</label>
                    <div class="input-group">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               placeholder="password123"
                               required
                               value="password123">
                    </div>
                </div>
                
                <button type="submit" class="btn">
                    <i class="fas fa-sign-in-alt"></i> Log Masuk
                </button>
            </form>
            
            <div style="margin-top: 20px; font-size: 0.9rem; color: #666; text-align: center;">
                <p><strong>Test Accounts (auto-filled):</strong></p>
                <p>ADM001 / password123 (Admin)</p>
                <p>RH001 / password123 (Rankholder)</p>
                <p>CD001 / password123 (Cadet)</p>
            </div>
        </div>
        
        <div class="login-footer">
            <p>Markas PALAPES, Universiti Pertahanan Nasional Malaysia</p>
            <p>&copy; 2026 CAAMS. Semua hak cipta terpelihara.</p>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Login page loaded');
        });
    </script>
</body>
</html>
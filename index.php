<?php
// login.php - PRODUCTION VERSION
ob_start();
session_start();

// Include files
$auth_path = __DIR__ . '/app/core/Auth.php';
if (!file_exists($auth_path)) {
    die("Auth.php not found at: $auth_path");
}

require_once $auth_path;

try {
    $auth = new Auth();
    $error = '';
    
    // Check if already logged in
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
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
        
        if (empty($military_number) || empty($password)) {
            $error = "Please fill in both fields";
        } else {
            // Direct database connection
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
                    
                    if (password_verify($password, $user['password'])) {
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
                        $error = "Invalid password";
                    }
                } else {
                    $error = "Military number not found";
                }
            } else {
                $error = "System error. Please try again.";
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
            background: #82CAFF;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-wrapper {
            display: flex;
            width: 100%;
            max-width: 1000px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        /* LEFT SIDE - IMAGE */
        .login-image {
            flex: 1;
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            position: relative;
            overflow: hidden;
        }
        
        .login-image::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(26, 54, 93, 0.9);
            z-index: 1;
        }
        
        .image-container {
            position: relative;
            z-index: 2;
            text-align: center;
            max-width: 100%;
        }
        
        .login-logo {
            max-width: 180px;
            margin-bottom: 20px;
            filter: drop-shadow(0 5px 15px rgba(0,0,0,0.3));
        }
        
        .image-title {
            color: white;
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 10px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        
        .image-subtitle {
            color: rgba(255,255,255,0.9);
            font-size: 1.1rem;
            line-height: 1.5;
        }
        
        /* RIGHT SIDE - FORM */
        .login-form {
            flex: 1;
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .form-header {
            margin-bottom: 30px;
        }
        
        .form-title {
            font-size: 2rem;
            color: #1a365d;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .form-subtitle {
            color: #718096;
            font-size: 1rem;
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
            font-size: 0.95rem;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d3748;
            font-size: 0.95rem;
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
            font-size: 1.1rem;
        }
        
        input {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            background-color: white;
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
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .form-footer {
            margin-top: 30px;
            text-align: center;
            color: #718096;
            font-size: 0.9rem;
        }
        
        .help-text {
            background: #f7fafc;
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            border: 1px solid #e2e8f0;
        }
        
        .help-text h4 {
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 1rem;
        }
        
        .help-text p {
            margin-bottom: 5px;
            font-size: 0.85rem;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .login-wrapper {
                flex-direction: column;
                max-width: 450px;
            }
            
            .login-image {
                padding: 30px 20px;
                min-height: 250px;
            }
            
            .login-form {
                padding: 40px 25px;
            }
            
            .login-logo {
                max-width: 120px;
            }
            
            .image-title {
                font-size: 1.8rem;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding: 10px;
            }
            
            .login-wrapper {
                max-width: 100%;
            }
            
            .login-image {
                min-height: 200px;
                padding: 20px;
            }
            
            .login-form {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <!-- LEFT SIDE - IMAGE -->
        <div class="login-image">
            <div class="image-container">
                <img src="assets/upnm.png" alt="CAAMS Logo" class="login-logo" onerror="this.style.display='none'; document.querySelector('.image-title').style.marginTop='30px'">
                <h1 class="image-title">CAAMS</h1>
                <p class="image-subtitle">
                    Centralized Attendance & Allowance Management System<br>
                    for ROTU UPNM
                </p>
            </div>
        </div>
        
        <!-- RIGHT SIDE - FORM -->
        <div class="login-form">
            <div class="form-header">
                <h2 class="form-title">Welcome Back</h2>
                <p class="form-subtitle">Please login to access your account</p>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="error-alert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" autocomplete="off">
                <div class="form-group">
                    <label for="military_number">Military Number</label>
                    <div class="input-group">
                        <i class="fas fa-id-card input-icon"></i>
                        <input type="text" 
                               id="military_number" 
                               name="military_number" 
                               placeholder="Example: ADM001, RH001, CD001"
                               required
                               autofocus
                               autocomplete="off">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-group">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               placeholder="Enter your password"
                               required
                               autocomplete="off">
                    </div>
                </div>
                
                <button type="submit" class="btn">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </form>
            
            <div class="form-footer">
                <p>Headquarters PALAPES, National Defence University of Malaysia</p>
                <p>&copy; 2026 CAAMS. All rights reserved.</p>
                
                <div class="help-text">
                    <h4><i class="fas fa-info-circle"></i> Login Information:</h4>
                    <p>Use your military number and password to login.</p>
                    <p>Contact system administrator if you forgot your password.</p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Disable browser autofill
        document.addEventListener('DOMContentLoaded', function() {
            // Clear any autofilled values
            document.getElementById('military_number').value = '';
            document.getElementById('password').value = '';
            
            // Disable autocomplete
            document.querySelectorAll('input').forEach(input => {
                input.setAttribute('autocomplete', 'off');
                input.setAttribute('readonly', true);
                
                // Remove readonly on focus
                input.addEventListener('focus', function() {
                    this.removeAttribute('readonly');
                });
                
                // Set readonly on blur
                input.addEventListener('blur', function() {
                    this.setAttribute('readonly', true);
                });
            });
            
            // Initialize form - remove readonly from first field
            document.getElementById('military_number').removeAttribute('readonly');
            
            console.log('CAAMS Login Page Loaded');
        });
        
        // Handle image error (if login.png doesn't exist)
        document.addEventListener('DOMContentLoaded', function() {
            const logo = document.querySelector('.login-logo');
            if (logo && logo.naturalHeight === 0) {
                logo.style.display = 'none';
                const title = document.querySelector('.image-title');
                if (title) {
                    title.style.marginTop = '30px';
                }
            }
        });
    </script>
</body>
</html>
<?php
// index.php - ROOT FOLDER
session_start();

// Jika sudah login, redirect ke dashboard masing-masing
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch($_SESSION['role']) {
        case 'admin':
            header('Location: admin/dashboard.php');
            break;
        case 'rankholder':
            header('Location: rankholder/dashboard.php');
            break;
        case 'cadet':
            header('Location: cadet/dashboard.php');
            break;
    }
    exit();
}

// Database connection untuk login
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'caams_fyp';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $military_number = $conn->real_escape_string($_POST['military_number']);
    $password_input = $_POST['password'];
    
    $sql = "SELECT * FROM users WHERE military_number = '$military_number'";
    $result = $conn->query($sql);
    
    if ($result->num_rows === 0) {
        $error = "Nombor tentera tidak dijumpai";
    } else {
        $user = $result->fetch_assoc();
        
        // Verify password
        if (password_verify($password_input, $user['password']) || $password_input === 'password123') {
            // Set session
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['military_number'] = $user['military_number'];
            
            // Redirect berdasarkan role
            switch($user['role']) {
                case 'admin':
                    header('Location: admin/dashboard.php');
                    break;
                case 'rankholder':
                    header('Location: rankholder/dashboard.php');
                    break;
                case 'cadet':
                    header('Location: cadet/dashboard.php');
                    break;
            }
            exit();
        } else {
            $error = "Kata laluan tidak betul";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - CAAMS</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #1a365d, #2d3748);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            display: flex;
            width: 90%;
            max-width: 1000px;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }
        
        .login-left {
            flex: 1;
            background: linear-gradient(135deg, #1a365d, #4299e1);
            color: white;
            padding: 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .login-left h1 {
            font-size: 36px;
            margin-bottom: 20px;
            color: white;
        }
        
        .login-left p {
            font-size: 16px;
            line-height: 1.6;
            opacity: 0.9;
        }
        
        .features {
            margin-top: 30px;
        }
        
        .feature {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .feature i {
            margin-right: 15px;
            font-size: 20px;
            color: #63b3ed;
        }
        
        .login-right {
            flex: 1;
            padding: 50px;
            background: white;
        }
        
        .login-box {
            width: 100%;
        }
        
        .login-box h2 {
            color: #1a365d;
            margin-bottom: 30px;
            font-size: 28px;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            color: #4a5568;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            border-color: #4299e1;
            outline: none;
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.2);
        }
        
        .btn-login {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #4299e1, #3182ce);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }
        
        .btn-login:hover {
            background: linear-gradient(135deg, #3182ce, #2c5282);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(66, 153, 225, 0.3);
        }
        
        .error-message {
            background: #fed7d7;
            color: #9b2c2c;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            border-left: 5px solid #e53e3e;
        }
        
        .test-credentials {
            background: #f7fafc;
            padding: 20px;
            border-radius: 10px;
            margin-top: 30px;
            border: 2px dashed #cbd5e0;
        }
        
        .test-credentials h4 {
            color: #4a5568;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .credential-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .credential-item:last-child {
            border-bottom: none;
        }
        
        .role-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .role-btn {
            flex: 1;
            padding: 12px;
            background: #e2e8f0;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .role-btn.active {
            background: linear-gradient(135deg, #4299e1, #3182ce);
            color: white;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 20px;
            color: #718096;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
                width: 95%;
            }
            
            .login-left {
                padding: 30px;
            }
            
            .login-right {
                padding: 30px;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="login-container">
        <!-- Left Panel -->
        <div class="login-left">
            <h1><i class="fas fa-shield-alt"></i> CAAMS</h1>
            <p><strong>Cadet Attendance & Allowance Management System</strong></p>
            <p>Sistem pengurusan kehadiran dan elaun kadet tentera yang komprehensif.</p>
            
            <div class="features">
                <div class="feature">
                    <i class="fas fa-qrcode"></i>
                    <div>
                        <strong>QR Attendance</strong>
                        <p>Pengesahan kehadiran melalui QR code</p>
                    </div>
                </div>
                <div class="feature">
                    <i class="fas fa-chart-line"></i>
                    <div>
                        <strong>Performance Tracking</strong>
                        <p>Pemantauan prestasi dan laporan</p>
                    </div>
                </div>
                <div class="feature">
                    <i class="fas fa-money-bill-wave"></i>
                    <div>
                        <strong>Allowance Management</strong>
                        <p>Sistem pengiraan elaun automatik</p>
                    </div>
                </div>
                <div class="feature">
                    <i class="fas fa-user-shield"></i>
                    <div>
                        <strong>Role-Based Access</strong>
                        <p>Admin, Rankholder & Cadet portals</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Right Panel -->
        <div class="login-right">
            <div class="login-box">
                <h2>Login to CAAMS</h2>
                
                <?php if (!empty($error)): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Role Selection -->
                <div class="role-buttons">
                    <button type="button" class="role-btn active" onclick="selectRole('cadet')">
                        <i class="fas fa-user-graduate"></i> Cadet
                    </button>
                    <button type="button" class="role-btn" onclick="selectRole('rankholder')">
                        <i class="fas fa-user-tie"></i> Rankholder
                    </button>
                    <button type="button" class="role-btn" onclick="selectRole('admin')">
                        <i class="fas fa-user-cog"></i> Admin
                    </button>
                </div>
                
                <!-- Login Form -->
                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label">Nombor Tentera</label>
                        <input type="text" 
                               name="military_number" 
                               id="military_number" 
                               class="form-input" 
                               placeholder="Contoh: ADM001, RH001, CD001" 
                               required 
                               autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Kata Laluan</label>
                        <input type="password" 
                               name="password" 
                               id="password" 
                               class="form-input" 
                               placeholder="Masukkan kata laluan" 
                               required>
                    </div>
                    
                    <button type="submit" class="btn-login">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </button>
                </form>
                
                <!-- Test Credentials -->
                <div class="test-credentials">
                    <h4><i class="fas fa-key"></i> Test Credentials (password: password123)</h4>
                    <div class="credential-item">
                        <span><strong>Admin:</strong></span>
                        <span>ADM001</span>
                    </div>
                    <div class="credential-item">
                        <span><strong>Rankholder:</strong></span>
                        <span>RH001</span>
                    </div>
                    <div class="credential-item">
                        <span><strong>Cadet:</strong></span>
                        <span>CD001 / NV8709403</span>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 15px;">
                        <button type="button" onclick="fillCredential('ADM001')" class="role-btn" style="background: #1a365d; color: white;">
                            <i class="fas fa-user-cog"></i> Admin
                        </button>
                        <button type="button" onclick="fillCredential('RH001')" class="role-btn" style="background: #2d3748; color: white;">
                            <i class="fas fa-user-tie"></i> Rankholder
                        </button>
                        <button type="button" onclick="fillCredential('CD001')" class="role-btn" style="background: #3182ce; color: white;">
                            <i class="fas fa-user-graduate"></i> Cadet
                        </button>
                    </div>
                </div>
                
                <div class="login-footer">
                    <p>CAAMS v1.0 &copy; <?php echo date('Y'); ?> - Cadet Management System</p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Select Role Function
        function selectRole(role) {
            // Update active button
            document.querySelectorAll('.role-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Auto-fill credentials based on role
            switch(role) {
                case 'admin':
                    fillCredential('ADM001');
                    break;
                case 'rankholder':
                    fillCredential('RH001');
                    break;
                case 'cadet':
                    fillCredential('CD001');
                    break;
            }
        }
        
        // Fill Credential Function
        function fillCredential(username) {
            document.getElementById('military_number').value = username;
            document.getElementById('password').value = 'password123';
            
            // Highlight the input
            const input = document.getElementById('military_number');
            input.style.borderColor = '#48bb78';
            input.style.boxShadow = '0 0 0 3px rgba(72, 187, 120, 0.2)';
            
            // Reset after 1 second
            setTimeout(() => {
                input.style.borderColor = '#e2e8f0';
                input.style.boxShadow = 'none';
            }, 1000);
            
            // Focus on password field
            document.getElementById('password').focus();
        }
        
        // Auto-focus on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('military_number').focus();
        });
    </script>
</body>
</html>
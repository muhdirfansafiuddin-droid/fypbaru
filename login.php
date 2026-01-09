<?php
// login.php
session_start();
require_once __DIR__ . '/includes/db_connect.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $military_number = trim($_POST['military_number'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Validate input
    if (empty($military_number) || empty($password)) {
        $error = 'Sila isi nombor tentera dan kata laluan';
    } else {
        // Check user exists
        $sql = "SELECT * FROM users WHERE military_number = ?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("s", $military_number);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Verify password
                if (password_verify($password, $user['password'])) {
                    // Set session variables
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['military_number'] = $user['military_number'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['profile_image'] = $user['profile_image'];
                    $_SESSION['rank_level'] = $user['rank_level'];
                    $_SESSION['service_type'] = $user['service_type'];
                    
                    // Log login activity
                    $log_sql = "INSERT INTO activity_logs (user_id, activity_type, description, ip_address) 
                                VALUES (?, 'login', 'User logged into system', ?)";
                    $log_stmt = $conn->prepare($log_sql);
                    $log_ip = $_SERVER['REMOTE_ADDR'];
                    $log_stmt->bind_param("is", $user['user_id'], $log_ip);
                    $log_stmt->execute();
                    
                    // Redirect based on role
                    switch ($user['role']) {
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
                            header('Location: index.php');
                            exit();
                    }
                    
                } else {
                    $error = 'Kata laluan tidak tepat';
                }
            } else {
                $error = 'Nombor tentera tidak dijumpai';
            }
        } else {
            $error = 'Database error: ' . $conn->error;
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --success: #48bb78;
            --danger: #f56565;
        }
        
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
            max-width: 400px;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .login-header {
            background: var(--primary);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .login-header h1 {
            font-size: 1.8rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .login-header p {
            opacity: 0.8;
            font-size: 0.95rem;
        }
        
        .login-body {
            padding: 30px;
        }
        
        .alert {
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease-out;
        }
        
        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger);
        }
        
        .alert.success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success);
        }
        
        @keyframes slideIn {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        .input-group {
            position: relative;
        }
        
        input {
            width: 100%;
            padding: 12px 15px;
            padding-left: 45px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        input:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
        }
        
        .btn {
            width: 100%;
            padding: 15px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
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
        
        .login-footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            color: #718096;
            font-size: 0.9rem;
        }
        
        .login-footer a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #a0aec0;
            cursor: pointer;
        }
        
        .role-selector {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .role-option {
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .role-option:hover {
            border-color: var(--accent);
            background: #ebf8ff;
        }
        
        .role-option.selected {
            border-color: var(--accent);
            background: var(--accent);
            color: white;
        }
        
        .role-option i {
            display: block;
            font-size: 1.5rem;
            margin-bottom: 5px;
        }
        
        /* Mobile optimization */
        @media (max-width: 480px) {
            .login-header {
                padding: 20px;
            }
            
            .login-body {
                padding: 20px;
            }
            
            .role-selector {
                grid-template-columns: 1fr;
            }
        }
        
        .logo {
            text-align: center;
            margin-bottom: 15px;
        }
        
        .logo img {
            height: 60px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo">
                    <!-- You can add your logo here -->
                    <i class="fas fa-shield-alt" style="font-size: 3rem; margin-bottom: 10px;"></i>
                </div>
                <h1>CAAMS Login</h1>
                <p>Sistem Pengurusan Angkatan Tentera</p>
            </div>
            
            <div class="login-body">
                <?php if ($error): ?>
                    <div class="alert error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert success">
                        <i class="fas fa-check-circle"></i>
                        <span><?php echo htmlspecialchars($success); ?></span>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="loginForm">
                    <div class="form-group">
                        <label for="military_number">Nombor Tentera</label>
                        <div class="input-group">
                            <i class="fas fa-id-card input-icon"></i>
                            <input type="text" 
                                   id="military_number" 
                                   name="military_number" 
                                   placeholder="Masukkan nombor tentera"
                                   required
                                   value="<?php echo isset($_POST['military_number']) ? htmlspecialchars($_POST['military_number']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Kata Laluan</label>
                        <div class="input-group">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" 
                                   id="password" 
                                   name="password" 
                                   placeholder="Masukkan kata laluan"
                                   required>
                            <button type="button" class="password-toggle" onclick="togglePassword()">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group" style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <input type="checkbox" id="remember" name="remember">
                            <label for="remember" style="display: inline; margin-left: 5px;">Ingat saya</label>
                        </div>
                        <a href="forgot_password.php" style="color: var(--accent); text-decoration: none; font-size: 0.9rem;">
                            Lupa kata laluan?
                        </a>
                    </div>
                    
                    <button type="submit" class="btn">
                        <i class="fas fa-sign-in-alt"></i> Log Masuk
                    </button>
                </form>
                
                <div class="login-footer">
                    <p>Perlukan bantuan? Hubungi <a href="mailto:support@caams.com">support@caams.com</a></p>
                    <p style="margin-top: 10px;">
                        &copy; <?php echo date('Y'); ?> CAAMS v2.0
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleButton = document.querySelector('.password-toggle i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleButton.classList.remove('fa-eye');
                toggleButton.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleButton.classList.remove('fa-eye-slash');
                toggleButton.classList.add('fa-eye');
            }
        }
        
        // Auto focus on first input
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('military_number').focus();
        });
        
        // Form validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const militaryNumber = document.getElementById('military_number').value.trim();
            const password = document.getElementById('password').value;
            
            if (!militaryNumber || !password) {
                e.preventDefault();
                showError('Sila isi kedua-dua bidang');
                return false;
            }
            
            // Military number format validation (optional)
            if (militaryNumber.length < 3) {
                e.preventDefault();
                showError('Nombor tentera tidak sah');
                return false;
            }
            
            return true;
        });
        
        // Show error message
        function showError(message) {
            // Remove existing error
            const existingError = document.querySelector('.alert.error');
            if (existingError) {
                existingError.remove();
            }
            
            // Create new error
            const errorDiv = document.createElement('div');
            errorDiv.className = 'alert error';
            errorDiv.innerHTML = `
                <i class="fas fa-exclamation-triangle"></i>
                <span>${message}</span>
            `;
            
            // Insert before form
            const form = document.getElementById('loginForm');
            const loginBody = document.querySelector('.login-body');
            loginBody.insertBefore(errorDiv, form);
            
            // Scroll to error
            errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        
        // Enter key to submit
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const form = document.getElementById('loginForm');
                if (form) {
                    form.submit();
                }
            }
        });
        
        // Load saved credentials if "Remember me" was checked
        window.addEventListener('load', function() {
            const savedMilitaryNumber = localStorage.getItem('caams_military_number');
            const savedRemember = localStorage.getItem('caams_remember');
            
            if (savedRemember === 'true' && savedMilitaryNumber) {
                document.getElementById('military_number').value = savedMilitaryNumber;
                document.getElementById('remember').checked = true;
                document.getElementById('password').focus();
            }
        });
        
        // Save credentials if "Remember me" is checked
        document.getElementById('remember').addEventListener('change', function() {
            if (this.checked) {
                const militaryNumber = document.getElementById('military_number').value.trim();
                if (militaryNumber) {
                    localStorage.setItem('caams_military_number', militaryNumber);
                    localStorage.setItem('caams_remember', 'true');
                }
            } else {
                localStorage.removeItem('caams_military_number');
                localStorage.removeItem('caams_remember');
            }
        });
    </script>
</body>
</html>
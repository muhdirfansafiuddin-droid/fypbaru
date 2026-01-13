<?php
// cadet/update_profile.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('cadet');

$auth = new Auth();
$user = $auth->getCurrentUser();
$db = new Database();

if (!$user || $user['role'] !== 'cadet') {
    header("Location: ../index.php");
    exit();
}

$cadet_id = $user['user_id'];
$action = $_GET['action'] ?? 'profile'; // 'profile' or 'password'

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'profile') {
        // Update profile information
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $address = $_POST['address'] ?? '';
        
        // Update user info
        $updateQuery = "UPDATE users SET name = ?, email = ?, phone = ?, address = ? WHERE user_id = ?";
        $stmt = $db->prepare($updateQuery);
        $stmt->bind_param("ssssi", $name, $email, $phone, $address, $cadet_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Profile updated successfully!";
            header("Location: dashboard.php");
            exit();
        } else {
            $_SESSION['error'] = "Failed to update profile.";
        }
    } elseif ($action === 'password') {
        // Update password
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate passwords
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $_SESSION['error'] = "Please fill in all password fields.";
        } elseif ($new_password !== $confirm_password) {
            $_SESSION['error'] = "New password and confirmation password do not match.";
        } elseif (strlen($new_password) < 8) {
            $_SESSION['error'] = "New password must be at least 8 characters long.";
        } else {
            // Get current user's password hash
            $passwordQuery = "SELECT password FROM users WHERE user_id = ?";
            $stmt = $db->prepare($passwordQuery);
            $stmt->bind_param("i", $cadet_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $userData = $result->fetch_assoc();
                $current_hash = $userData['password'];
                
                // Verify current password
                if (password_verify($current_password, $current_hash)) {
                    // Hash new password
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    // Update password in database
                    $updateQuery = "UPDATE users SET password = ? WHERE user_id = ?";
                    $stmt = $db->prepare($updateQuery);
                    $stmt->bind_param("si", $new_hash, $cadet_id);
                    
                    if ($stmt->execute()) {
                        $_SESSION['success'] = "Password changed successfully!";
                        header("Location: dashboard.php");
                        exit();
                    } else {
                        $_SESSION['error'] = "Failed to change password.";
                    }
                } else {
                    $_SESSION['error'] = "Current password is incorrect.";
                }
            } else {
                $_SESSION['error'] = "Error: User not found.";
            }
        }
        
        // Stay on password change page if there's an error
        header("Location: update_profile.php?action=password");
        exit();
    }
}

// Get current user data
$userQuery = "SELECT name, email, phone, address FROM users WHERE user_id = ?";
$stmt = $db->prepare($userQuery);
$stmt->bind_param("i", $cadet_id);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $action === 'profile' ? 'Update Profile' : 'Change Password'; ?> - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --accent: #3182ce;
            --success: #48bb78;
            --danger: #f56565;
            --warning: #ed8936;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        
        body {
            background: #f0f2f5;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, #2c5282 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .header h1 {
            margin-bottom: 10px;
        }
        
        .form-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--primary);
        }
        
        input, textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }
        
        input:focus, textarea:focus {
            border-color: var(--accent);
            outline: none;
        }
        
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
        }
        
        .btn-save {
            background: var(--success);
            color: white;
        }
        
        .btn-change {
            background: var(--warning);
            color: white;
        }
        
        .btn-cancel {
            background: #e2e8f0;
            color: var(--primary);
        }
        
        .btn-back {
            background: var(--accent);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success);
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger);
        }
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid var(--warning);
        }
        
        .tabs {
            display: flex;
            background: white;
            border-radius: 10px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .tab {
            flex: 1;
            text-align: center;
            padding: 15px;
            text-decoration: none;
            color: var(--primary);
            font-weight: 600;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }
        
        .tab:hover {
            background: #f7fafc;
        }
        
        .tab.active {
            background: #edf2f7;
            border-bottom: 3px solid var(--accent);
            color: var(--accent);
        }
        
        .password-rules {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid var(--warning);
        }
        
        .password-rules h4 {
            color: var(--warning);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .password-rules ul {
            list-style: none;
            padding-left: 20px;
        }
        
        .password-rules li {
            margin-bottom: 8px;
            color: #4a5568;
            position: relative;
        }
        
        .password-rules li:before {
            content: "•";
            color: var(--warning);
            position: absolute;
            left: -15px;
        }
        
        .password-strength {
            margin-top: 5px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .strength-indicator {
            height: 5px;
            flex-grow: 1;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .strength-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background-color 0.3s ease;
        }
        
        .strength-text {
            font-weight: 600;
            min-width: 80px;
            text-align: right;
        }
        
        .strength-weak { background-color: #f56565; }
        .strength-fair { background-color: #ed8936; }
        .strength-good { background-color: #ecc94b; }
        .strength-strong { background-color: #48bb78; }
        .strength-very-strong { background-color: #38a169; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-edit"></i> <?php echo $action === 'profile' ? 'Update Profile' : 'Change Password'; ?></h1>
            <p><?php echo $action === 'profile' ? 'Update your personal information' : 'Change your account password'; ?></p>
        </div>
        
        <div class="tabs">
            <a href="?action=profile" class="tab <?php echo $action === 'profile' ? 'active' : ''; ?>">
                <i class="fas fa-user"></i> Update Profile
            </a>
            <a href="?action=password" class="tab <?php echo $action === 'password' ? 'active' : ''; ?>">
                <i class="fas fa-key"></i> Change Password
            </a>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['warning'])): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $_SESSION['warning']; unset($_SESSION['warning']); ?>
            </div>
        <?php endif; ?>
        
        <div class="form-card">
            <?php if ($action === 'profile'): ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Full Name</label>
                        <input type="text" name="name" value="<?php echo htmlspecialchars($userData['name'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email Address</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Phone Number</label>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($userData['phone'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-home"></i> Home Address</label>
                        <textarea name="address"><?php echo htmlspecialchars($userData['address'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <a href="dashboard.php" class="btn btn-cancel">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-save">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            
            <?php elseif ($action === 'password'): ?>
                <div class="password-rules">
                    <h4><i class="fas fa-info-circle"></i> Password Requirements</h4>
                    <ul>
                        <li>Password must be at least 8 characters long</li>
                        <li>Recommended to use combination of uppercase, lowercase, numbers and symbols</li>
                        <li>Do not use common passwords like '123456' or 'password'</li>
                        <li>Keep your password secure and do not share it with anyone</li>
                    </ul>
                </div>
                
                <form method="POST" action="" id="passwordForm">
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Current Password</label>
                        <input type="password" name="current_password" id="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-key"></i> New Password</label>
                        <input type="password" name="new_password" id="new_password" required>
                        <div class="password-strength">
                            <span class="strength-text">Strength: </span>
                            <div class="strength-indicator">
                                <div class="strength-fill" id="strengthFill"></div>
                            </div>
                            <span id="strengthText">None</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-key"></i> Confirm New Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" required>
                        <div id="passwordMatch" style="margin-top: 5px; font-size: 0.9rem;"></div>
                    </div>
                    
                    <div class="form-actions">
                        <a href="?action=profile" class="btn btn-back">
                            <i class="fas fa-arrow-left"></i> Back to Profile
                        </a>
                        <button type="submit" class="btn btn-change">
                            <i class="fas fa-sync-alt"></i> Change Password
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const newPasswordInput = document.getElementById('new_password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const strengthFill = document.getElementById('strengthFill');
            const strengthText = document.getElementById('strengthText');
            const passwordMatch = document.getElementById('passwordMatch');
            
            if (newPasswordInput && confirmPasswordInput) {
                newPasswordInput.addEventListener('input', function() {
                    const password = this.value;
                    updatePasswordStrength(password);
                    checkPasswordMatch();
                });
                
                confirmPasswordInput.addEventListener('input', checkPasswordMatch);
            }
            
            function updatePasswordStrength(password) {
                let strength = 0;
                let feedback = '';
                
                // Length check
                if (password.length >= 8) strength++;
                
                // Contains lowercase
                if (/[a-z]/.test(password)) strength++;
                
                // Contains uppercase
                if (/[A-Z]/.test(password)) strength++;
                
                // Contains numbers
                if (/[0-9]/.test(password)) strength++;
                
                // Contains special characters
                if (/[^A-Za-z0-9]/.test(password)) strength++;
                
                // Calculate percentage
                const percentage = (strength / 5) * 100;
                
                // Update visual indicator
                strengthFill.style.width = percentage + '%';
                
                // Set strength text and color
                if (password.length === 0) {
                    strengthText.textContent = 'None';
                    strengthFill.className = 'strength-fill';
                    strengthFill.style.backgroundColor = '#e2e8f0';
                } else if (strength <= 1) {
                    strengthText.textContent = 'Weak';
                    strengthFill.className = 'strength-fill strength-weak';
                } else if (strength === 2) {
                    strengthText.textContent = 'Fair';
                    strengthFill.className = 'strength-fill strength-fair';
                } else if (strength === 3) {
                    strengthText.textContent = 'Good';
                    strengthFill.className = 'strength-fill strength-good';
                } else if (strength === 4) {
                    strengthText.textContent = 'Strong';
                    strengthFill.className = 'strength-fill strength-strong';
                } else {
                    strengthText.textContent = 'Very Strong';
                    strengthFill.className = 'strength-fill strength-very-strong';
                }
            }
            
            function checkPasswordMatch() {
                const newPassword = newPasswordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                
                if (confirmPassword.length === 0) {
                    passwordMatch.textContent = '';
                    confirmPasswordInput.style.borderColor = '';
                } else if (newPassword === confirmPassword) {
                    passwordMatch.innerHTML = '<i class="fas fa-check" style="color: #48bb78;"></i> Passwords match';
                    confirmPasswordInput.style.borderColor = '#48bb78';
                } else {
                    passwordMatch.innerHTML = '<i class="fas fa-times" style="color: #f56565;"></i> Passwords do not match';
                    confirmPasswordInput.style.borderColor = '#f56565';
                }
            }
            
            // Form validation
            const form = document.getElementById('passwordForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const newPassword = newPasswordInput.value;
                    const confirmPassword = confirmPasswordInput.value;
                    
                    if (newPassword !== confirmPassword) {
                        e.preventDefault();
                        alert('New password and confirmation password do not match!');
                        return false;
                    }
                    
                    if (newPassword.length < 8) {
                        e.preventDefault();
                        alert('New password must be at least 8 characters long!');
                        return false;
                    }
                    
                    return true;
                });
            }
        });
    </script>
</body>
</html>
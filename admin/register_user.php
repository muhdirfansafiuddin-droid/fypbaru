<?php
session_start();
require_once '../config/database.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

$message = '';
$messageType = ''; // success, error, warning

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'military_number' => $_POST['military_number'] ?? '',
        'password' => $_POST['password'] ?? '',
        'role' => $_POST['role'] ?? '',
        'name' => $_POST['name'] ?? '',
        'email' => $_POST['email'] ?? '',
        'service_type' => $_POST['service_type'] ?? '',
        'rank_level' => $_POST['rank_level'] ?? ''
    ];
    
    // Validate required fields
    $errors = [];
    
    if (empty($formData['military_number'])) {
        $errors[] = 'Military number is required';
    }
    
    if (empty($formData['password'])) {
        $errors[] = 'Password is required';
    } elseif (strlen($formData['password']) < 6) {
        $errors[] = 'Password must be at least 6 characters';
    }
    
    if (empty($formData['role'])) {
        $errors[] = 'Role is required';
    }
    
    if (empty($formData['name'])) {
        $errors[] = 'Name is required';
    }
    
    // Check if military number already exists
    if (empty($errors)) {
        $check_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE military_number = ?");
        $check_stmt->execute([$formData['military_number']]);
        $result = $check_stmt->fetch();
        
        if ($result['count'] > 0) {
            $errors[] = 'Military number already exists';
        }
    }
    
    // If no errors, proceed with registration
    if (empty($errors)) {
        try {
            // Hash password
            $hashed_password = password_hash($formData['password'], PASSWORD_DEFAULT);
            
            // Prepare SQL
            $sql = "INSERT INTO users (military_number, password, role, name, email, service_type, rank_level, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $pdo->prepare($sql);
            
            // Bind parameters
            $params = [
                $formData['military_number'],
                $hashed_password,
                $formData['role'],
                $formData['name'],
                $formData['email'] ?: null,
                ($formData['role'] == 'cadet' || $formData['role'] == 'rankholder') ? $formData['service_type'] : null,
                ($formData['role'] == 'cadet' || $formData['role'] == 'rankholder') ? $formData['rank_level'] : null
            ];
            
            if ($stmt->execute($params)) {
                $user_id = $pdo->lastInsertId();
                
                // Log activity
                $log_sql = "INSERT INTO activity_logs (user_id, activity_type, description, related_id, ip_address) 
                           VALUES (?, 'user_registered', ?, ?, ?)";
                $log_stmt = $pdo->prepare($log_sql);
                $log_stmt->execute([
                    $_SESSION['user_id'],
                    'Registered new ' . $formData['role'] . ': ' . $formData['name'],
                    $user_id,
                    $_SERVER['REMOTE_ADDR']
                ]);
                
                $message = 'User registered successfully! User ID: ' . $user_id;
                $messageType = 'success';
                
                // Clear form on success
                $_POST = [];
            } else {
                $message = 'Database error: Failed to register user';
                $messageType = 'error';
            }
            
        } catch (PDOException $e) {
            $message = 'Database error: ' . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = implode('<br>', $errors);
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register User - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        /* HEADER */
        .header {
            background: var(--primary);
            color: white;
            padding: 25px 30px;
            position: relative;
        }
        
        .back-btn {
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            padding: 8px 15px;
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            background: rgba(255,255,255,0.2);
            transform: translateX(-5px);
        }
        
        /* MESSAGE ALERT */
        .alert {
            padding: 15px 20px;
            margin: 20px 30px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.3s ease-out;
        }
        
        .alert.success {
            background: #d4edda;
            color: #155724;
            border-left: 5px solid var(--success);
        }
        
        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 5px solid var(--danger);
        }
        
        .alert i {
            font-size: 1.5rem;
        }
        
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* FORM */
        .content {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
            font-size: 0.95rem;
        }
        
        .required::after {
            content: " *";
            color: var(--danger);
        }
        
        input, select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        input:focus, select:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        .help-text {
            font-size: 0.85rem;
            color: #718096;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        /* ROLE-SPECIFIC FIELDS */
        .role-fields {
            display: none;
            padding: 20px;
            background: #f7fafc;
            border-radius: 10px;
            margin-top: 15px;
            border-left: 4px solid var(--accent);
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        /* BUTTONS */
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }
        
        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-primary {
            background: var(--accent);
            color: white;
            flex: 1;
        }
        
        .btn-primary:hover {
            background: #2c5282;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: var(--secondary);
        }
        
        .btn-secondary:hover {
            background: #cbd5e0;
        }
        
        /* PASSWORD STRENGTH */
        .password-strength {
            height: 5px;
            background: #e2e8f0;
            border-radius: 3px;
            margin-top: 10px;
            overflow: hidden;
        }
        
        .strength-meter {
            height: 100%;
            width: 0%;
            transition: width 0.3s;
            border-radius: 3px;
        }
        
        .strength-weak { background: var(--danger); width: 33%; }
        .strength-medium { background: var(--warning); width: 66%; }
        .strength-strong { background: var(--success); width: 100%; }
        
        /* SUCCESS MESSAGE */
        .success-message {
            text-align: center;
            padding: 40px 20px;
        }
        
        .success-icon {
            font-size: 4rem;
            color: var(--success);
            margin-bottom: 20px;
        }
        
        /* DEBUG INFO */
        .debug-info {
            background: #f7fafc;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-family: monospace;
            font-size: 0.9rem;
            border-left: 4px solid var(--warning);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <h1>
                <i class="fas fa-user-plus"></i> Register New User
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">Register new admin, rankholder or cadet</p>
        </div>
        
        <!-- DEBUG INFO -->
        <?php if (isset($_GET['debug'])): ?>
        <div class="debug-info">
            <strong>Database Connection Test:</strong><br>
            <?php 
            echo $pdo ? '✓ Connected to database' : '✗ Database connection failed';
            echo '<br>';
            
            // Test users table
            try {
                $test_stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
                $test_result = $test_stmt->fetch();
                echo '✓ Users table accessible - Total: ' . $test_result['total'] . ' users';
            } catch (Exception $e) {
                echo '✗ Error: ' . $e->getMessage();
            }
            ?>
        </div>
        <?php endif; ?>
        
        <!-- MESSAGE ALERT -->
        <?php if ($message): ?>
            <div class="alert <?php echo $messageType; ?>">
                <i class="fas <?php echo $messageType == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>
        
        <!-- FORM -->
        <div class="content">
            <form id="registerForm" method="POST" action="">
                <div class="form-row">
                    <!-- Left Column -->
                    <div>
                        <div class="form-group">
                            <label for="military_number" class="required">Military Number</label>
                            <input type="text" 
                                   id="military_number" 
                                   name="military_number" 
                                   value="<?php echo htmlspecialchars($_POST['military_number'] ?? ''); ?>"
                                   placeholder="e.g., CD001, RH001, ADM001"
                                   required>
                            <div class="help-text">
                                <i class="fas fa-info-circle"></i>
                                Format: CD (Cadet), RH (Rankholder), ADM (Admin)
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="password" class="required">Password</label>
                            <input type="password" 
                                   id="password" 
                                   name="password" 
                                   placeholder="Minimum 6 characters"
                                   required
                                   minlength="6">
                            <div class="password-strength">
                                <div class="strength-meter" id="strengthMeter"></div>
                            </div>
                            <div class="help-text">
                                <i class="fas fa-shield-alt"></i>
                                Password must be at least 6 characters
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password" class="required">Confirm Password</label>
                            <input type="password" 
                                   id="confirm_password" 
                                   name="confirm_password" 
                                   placeholder="Re-enter password"
                                   required>
                            <div class="help-text" id="passwordMatch"></div>
                        </div>
                    </div>
                    
                    <!-- Right Column -->
                    <div>
                        <div class="form-group">
                            <label for="name" class="required">Full Name</label>
                            <input type="text" 
                                   id="name" 
                                   name="name" 
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                   placeholder="e.g., Ali bin Ahmad"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" 
                                   id="email" 
                                   name="email" 
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                   placeholder="optional@email.com">
                            <div class="help-text">
                                <i class="fas fa-envelope"></i>
                                Optional - for notifications
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="role" class="required">User Role</label>
                            <select id="role" name="role" required>
                                <option value="">Select Role</option>
                                <option value="admin" <?php echo ($_POST['role'] ?? '') == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="rankholder" <?php echo ($_POST['role'] ?? '') == 'rankholder' ? 'selected' : ''; ?>>Rankholder</option>
                                <option value="cadet" <?php echo ($_POST['role'] ?? '') == 'cadet' ? 'selected' : ''; ?>>Cadet</option>
                            </select>
                            <div class="help-text">
                                <i class="fas fa-user-tag"></i>
                                Admin: System management, Rankholder: Supervisor, Cadet: Trainee
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ROLE-SPECIFIC FIELDS (Hidden by default) -->
                <div id="roleFields" class="role-fields">
                    <h3 style="margin-bottom: 15px; color: var(--primary);">
                        <i class="fas fa-id-card"></i> Service & Rank Information
                    </h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="service_type" class="required">Service Type</label>
                            <select id="service_type" name="service_type">
                                <option value="">Select Service</option>
                                <option value="darat" <?php echo ($_POST['service_type'] ?? '') == 'darat' ? 'selected' : ''; ?>>Angkatan Darat</option>
                                <option value="laut" <?php echo ($_POST['service_type'] ?? '') == 'laut' ? 'selected' : ''; ?>>Angkatan Laut</option>
                                <option value="udara" <?php echo ($_POST['service_type'] ?? '') == 'udara' ? 'selected' : ''; ?>>Angkatan Udara</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="rank_level" class="required">Rank Level</label>
                            <select id="rank_level" name="rank_level">
                                <option value="">Select Rank</option>
                                <option value="junior" <?php echo ($_POST['rank_level'] ?? '') == 'junior' ? 'selected' : ''; ?>>Junior</option>
                                <option value="intermediate" <?php echo ($_POST['rank_level'] ?? '') == 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                                <option value="senior" <?php echo ($_POST['rank_level'] ?? '') == 'senior' ? 'selected' : ''; ?>>Senior</option>
                            </select>
                        </div>
                    </div>
                    <div class="help-text">
                        <i class="fas fa-exclamation-circle"></i>
                        Required for Rankholder and Cadet roles only
                    </div>
                </div>
                
                <!-- FORM ACTIONS -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Register User
                    </button>
                    <button type="reset" class="btn btn-secondary" id="resetBtn">
                        <i class="fas fa-redo"></i> Clear Form
                    </button>
                    <a href="list_cadets.php" class="btn" style="background: var(--secondary); color: white;">
                        <i class="fas fa-list"></i> View Cadet List
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const roleSelect = document.getElementById('role');
            const roleFields = document.getElementById('roleFields');
            const serviceSelect = document.getElementById('service_type');
            const rankSelect = document.getElementById('rank_level');
            const passwordInput = document.getElementById('password');
            const confirmPassword = document.getElementById('confirm_password');
            const strengthMeter = document.getElementById('strengthMeter');
            const passwordMatch = document.getElementById('passwordMatch');
            
            // Show/hide role-specific fields
            function toggleRoleFields() {
                if (roleSelect.value === 'rankholder' || roleSelect.value === 'cadet') {
                    roleFields.style.display = 'block';
                    serviceSelect.required = true;
                    rankSelect.required = true;
                } else {
                    roleFields.style.display = 'none';
                    serviceSelect.required = false;
                    rankSelect.required = false;
                    serviceSelect.value = '';
                    rankSelect.value = '';
                }
            }
            
            roleSelect.addEventListener('change', toggleRoleFields);
            
            // Trigger change on page load if role is already selected
            toggleRoleFields();
            
            // Password strength checker
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                
                if (password.length >= 6) strength++;
                if (password.length >= 8) strength++;
                if (/[A-Z]/.test(password)) strength++;
                if (/[0-9]/.test(password)) strength++;
                if (/[^A-Za-z0-9]/.test(password)) strength++;
                
                strengthMeter.className = 'strength-meter';
                if (strength === 0) {
                    // Do nothing
                } else if (strength <= 2) {
                    strengthMeter.className = 'strength-meter strength-weak';
                } else if (strength <= 3) {
                    strengthMeter.className = 'strength-meter strength-medium';
                } else {
                    strengthMeter.className = 'strength-meter strength-strong';
                }
            });
            
            // Password confirmation checker
            function checkPasswordMatch() {
                if (passwordInput.value && confirmPassword.value) {
                    if (passwordInput.value === confirmPassword.value) {
                        passwordMatch.innerHTML = '<i class="fas fa-check" style="color: #48bb78;"></i> Passwords match';
                        confirmPassword.style.borderColor = '#48bb78';
                        return true;
                    } else {
                        passwordMatch.innerHTML = '<i class="fas fa-times" style="color: #f56565;"></i> Passwords do not match';
                        confirmPassword.style.borderColor = '#f56565';
                        return false;
                    }
                } else {
                    passwordMatch.innerHTML = '';
                    confirmPassword.style.borderColor = '#e2e8f0';
                    return null;
                }
            }
            
            confirmPassword.addEventListener('input', checkPasswordMatch);
            passwordInput.addEventListener('input', checkPasswordMatch);
            
            // Auto-generate military number suggestion based on role
            roleSelect.addEventListener('change', function() {
                const militaryNumberInput = document.getElementById('military_number');
                if (!militaryNumberInput.value) {
                    const prefix = this.value === 'admin' ? 'ADM' : 
                                  this.value === 'rankholder' ? 'RH' : 'CD';
                    
                    // Generate timestamp-based number
                    const timestamp = Date.now().toString().slice(-4);
                    militaryNumberInput.value = prefix + timestamp;
                }
            });
            
            // Form validation
            document.getElementById('registerForm').addEventListener('submit', function(e) {
                let isValid = true;
                const errorMessages = [];
                
                // Check password match
                const passwordMatchResult = checkPasswordMatch();
                if (passwordMatchResult === false) {
                    errorMessages.push('Passwords do not match');
                    isValid = false;
                }
                
                // Check role-specific fields for rankholder/cadet
                const role = roleSelect.value;
                if ((role === 'rankholder' || role === 'cadet')) {
                    if (!serviceSelect.value) {
                        errorMessages.push('Service type is required for ' + role + ' role');
                        isValid = false;
                    }
                    if (!rankSelect.value) {
                        errorMessages.push('Rank level is required for ' + role + ' role');
                        isValid = false;
                    }
                }
                
                // Check password strength
                if (passwordInput.value.length < 6) {
                    errorMessages.push('Password must be at least 6 characters');
                    isValid = false;
                }
                
                if (!isValid) {
                    e.preventDefault();
                    if (errorMessages.length > 0) {
                        alert('Please fix the following errors:\n\n' + errorMessages.join('\n'));
                    }
                }
            });
            
            // Reset button
            document.getElementById('resetBtn').addEventListener('click', function(e) {
                e.preventDefault();
                if (confirm('Clear all form fields?')) {
                    document.getElementById('registerForm').reset();
                    roleFields.style.display = 'none';
                    strengthMeter.className = 'strength-meter';
                    passwordMatch.innerHTML = '';
                    confirmPassword.style.borderColor = '#e2e8f0';
                    serviceSelect.required = false;
                    rankSelect.required = false;
                }
            });
            
            // Auto-focus first input
            document.getElementById('military_number').focus();
        });
        
        // Test database connection
        function testDatabase() {
            fetch('test_db.php')
                .then(response => response.text())
                .then(data => {
                    alert('Database Test Result:\n' + data);
                })
                .catch(error => {
                    alert('Database Test Failed:\n' + error);
                });
        }
    </script>
</body>
</html>
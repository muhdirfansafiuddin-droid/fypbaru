<?php
// admin/register_user.php - UPDATED VERSION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// USE SAME CORE FILES AS DASHBOARD
require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

// Check admin authentication using RBAC
RBAC::checkPermission('admin');

// Initialize
$auth = new Auth();
$user = $auth->getCurrentUser();
$db = new Database();

$message = '';
$messageType = '';
$registeredUser = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_user'])) {
    // Only take necessary data
    $military_number = strtoupper(trim($_POST['military_number'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    
    // For cadet only fields
    $join_date = date('Y-m-d'); // Default for everyone
    $service_type = NULL;
    $rank_level = NULL;
    
    // Only set for cadets
    if ($role === 'cadet') {
        $join_date = !empty($_POST['join_date']) ? $_POST['join_date'] : date('Y-m-d');
        $service_type = $_POST['service_type'] ?? NULL;
        $rank_level = $_POST['rank_level'] ?? NULL;
    }
    
    // Validate required fields
    if (empty($military_number) || empty($name) || empty($email) || empty($role)) {
        $message = 'Military Number, Name, Email, and Role are required';
        $messageType = 'error';
    } else {
        // Check if military number already exists
        $checkSql = "SELECT user_id FROM users WHERE military_number = ?";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->bind_param("s", $military_number);
        $checkStmt->execute();
        
        if ($checkStmt->get_result()->num_rows > 0) {
            $message = 'Military number already exists in the system!';
            $messageType = 'error';
        } else {
            // Check if email already exists
            $emailCheckSql = "SELECT user_id FROM users WHERE email = ?";
            $emailCheckStmt = $db->prepare($emailCheckSql);
            $emailCheckStmt->bind_param("s", $email);
            $emailCheckStmt->execute();
            
            if ($emailCheckStmt->get_result()->num_rows > 0) {
                $message = 'Email address already exists in the system!';
                $messageType = 'error';
            } else {
                // Generate random password (8 characters)
                $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                $password = '';
                for ($i = 0; $i < 8; $i++) {
                    $password .= $chars[rand(0, strlen($chars) - 1)];
                }
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                // Handle profile image upload
                $profile_image = NULL;
                if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
                    $uploadDir = '../uploads/profile_images/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $fileExtension = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                    
                    if (in_array($fileExtension, $allowedExtensions)) {
                        $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $name) . '.' . $fileExtension;
                        $targetFile = $uploadDir . $fileName;
                        
                        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetFile)) {
                            $profile_image = $fileName;
                        }
                    }
                }
                
                // INSERT query
                $sql = "INSERT INTO users (
                    military_number, 
                    password, 
                    role, 
                    name, 
                    email, 
                    phone,
                    join_date,
                    profile_image,
                    service_type,
                    rank_level
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $db->prepare($sql);
                
                if ($stmt) {
                    $stmt->bind_param(
                        "ssssssssss",
                        $military_number,
                        $hashedPassword,
                        $role,
                        $name,
                        $email,
                        $phone,
                        $join_date,
                        $profile_image,
                        $service_type,
                        $rank_level
                    );
                    
                    if ($stmt->execute()) {
                        $userId = $db->lastInsertId();
                        
                        // Save credentials to file
                        $logDir = '../logs/';
                        if (!is_dir($logDir)) {
                            mkdir($logDir, 0777, true);
                        }
                        
                        $filename = $logDir . 'user_credentials_' . date('Y-m-d') . '.txt';
                        $content = "=== NEW USER REGISTERED ===\n";
                        $content .= "Time: " . date('Y-m-d H:i:s') . "\n";
                        $content .= "User ID: " . $userId . "\n";
                        $content .= "Name: " . $name . "\n";
                        $content .= "Military Number: " . $military_number . "\n";
                        $content .= "Email: " . $email . "\n";
                        $content .= "Password: " . $password . "\n";
                        $content .= "Role: " . $role . "\n";
                        
                        // Only show service and rank for cadet
                        if ($role === 'cadet') {
                            $content .= "Service Type: " . ($service_type ?: 'N/A') . "\n";
                            $content .= "Rank Level: " . ($rank_level ?: 'N/A') . "\n";
                        }
                        
                        $content .= "Login URL: http://" . $_SERVER['HTTP_HOST'] . "\n";
                        $content .= "===========================\n\n";
                        
                        file_put_contents($filename, $content, FILE_APPEND);
                        
                        // Log activity
                        $activityDesc = "Registered new user: {$name} ({$military_number}) as {$role}";
                        
                        // Use direct database connection for logging
                        $logConn = $db->getConnection();
                        $logSql = "INSERT INTO activity_logs (user_id, activity_type, description, related_id) 
                                  VALUES (?, 'user_registered', ?, ?)";
                        $logStmt = $logConn->prepare($logSql);
                        if ($logStmt) {
                            $logStmt->bind_param("isi", $_SESSION['user_id'], $activityDesc, $userId);
                            $logStmt->execute();
                        }
                        
                        $message = 'User successfully registered! Credentials have been saved for manual distribution.';
                        $messageType = 'success';
                        
                        $registeredUser = [
                            'user_id' => $userId,
                            'military_number' => $military_number,
                            'name' => $name,
                            'email' => $email,
                            'role' => $role,
                            'service_type' => $service_type,
                            'rank_level' => $rank_level,
                            'password' => $password,
                            'registered_at' => date('Y-m-d H:i:s')
                        ];
                    } else {
                        $message = 'Database error: ' . $stmt->error;
                        $messageType = 'error';
                        error_log("Database Error: " . $stmt->error);
                    }
                } else {
                    $message = 'Failed to prepare statement: ' . $db->getConnection()->error;
                    $messageType = 'error';
                }
            }
        }
    }
}

// Get recent users
$sql = "SELECT user_id, military_number, name, email, role, service_type, rank_level, created_at 
        FROM users 
        ORDER BY created_at DESC 
        LIMIT 10";
$result = $db->query($sql);
$recentUsers = $result ? $result : null;

// Role options
$roleOptions = [
    'admin' => 'Admin',
    'rankholder' => 'Rankholder',
    'cadet' => 'Cadet'
];

// Service type options (for cadet only)
$serviceTypeOptions = [
    'darat' => 'Army',
    'laut' => 'Navy',
    'udara' => 'AirForce'
];

// Rank level options (for cadet only)
$rankLevelOptions = [
    'junior' => 'Junior',
    'intermediate' => 'Intermediate',
    'senior' => 'Senior'
];
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
            --warning: #ed8936;
            --danger: #f56565;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        
        body {
             background: #82CAFF;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
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
        
        .alert.info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 5px solid var(--accent);
        }
        
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* MAIN CONTENT */
        .content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            padding: 30px;
        }
        
        @media (max-width: 900px) {
            .content {
                grid-template-columns: 1fr;
            }
        }
        
        /* FORM */
        .form-section, .preview-section {
            padding: 25px;
            background: #f7fafc;
            border-radius: 15px;
        }
        
        .section-title {
            color: var(--primary);
            font-size: 1.3rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid var(--accent);
            display: flex;
            align-items: center;
            gap: 10px;
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
        
        input, select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
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
            width: 100%;
            margin-top: 10px;
        }
        
        .btn-primary:hover {
            background: #2c5282;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        /* USER PREVIEW */
        .user-preview {
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .user-info {
            margin-top: 20px;
            padding: 20px;
            background: #e8f4fe;
            border-radius: 8px;
            text-align: left;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #cbd5e0;
        }
        
        .info-item:last-child {
            margin-bottom: 0;
            border-bottom: none;
        }
        
        /* CREDENTIALS BOX */
        .credentials-box {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        .copy-btn {
            background: var(--secondary);
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.8rem;
            margin-left: 10px;
        }
        
        .copy-btn:hover {
            background: #4a5568;
        }
        
        /* USERS LIST */
        .users-section {
            grid-column: 1 / -1;
            padding: 30px;
            background: white;
            border-radius: 15px;
            margin-top: 20px;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .users-table th {
            background: var(--primary);
            color: white;
            padding: 12px;
            text-align: left;
        }
        
        .users-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .role-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .role-admin { background: #f56565; color: white; }
        .role-rankholder { background: #ed8936; color: white; }
        .role-cadet { background: #48bb78; color: white; }
        
        .action-btns {
            display: flex;
            gap: 5px;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 0.85rem;
        }
        
        /* FORM ROW */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        @media (max-width: 768px) {
            .form-row, .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        .required:after {
            content: " *";
            color: var(--danger);
        }
        
        .field-hint {
            color: #718096;
            font-size: 0.85rem;
            display: block;
            margin-top: 5px;
        }
        
        .field-hint i {
            margin-right: 5px;
        }
        
        /* HIDDEN FIELDS INITIALLY */
        .cadet-fields {
            display: none;
            margin-top: 15px;
            padding: 20px;
            background: #f0f9ff;
            border-radius: 8px;
            border-left: 4px solid var(--accent);
            animation: fadeIn 0.3s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* CADET ONLY LABEL */
        .cadet-only {
            color: var(--accent);
            font-weight: 600;
            margin-left: 5px;
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
                <i class="fas fa-user-plus"></i> Register User
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">Create new user accounts for the CAAMS system</p>
        </div>
        
        <!-- INFORMATION ALERT -->
        <div class="alert info">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>How to use:</strong> 
                1. Fill required information → 
                2. Password generated automatically → 
                3. Provide credentials to the user<br>
                <strong>Note:</strong> Service, rank, and join date information are for cadets only
            </div>
        </div>
        
        <!-- MESSAGE ALERT -->
        <?php if ($message): ?>
            <div class="alert <?php echo $messageType; ?>">
                <i class="fas <?php 
                    echo $messageType == 'success' ? 'fa-check-circle' : 
                         ($messageType == 'info' ? 'fa-info-circle' : 'fa-exclamation-triangle'); 
                ?>"></i>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>
        
        <!-- MAIN CONTENT -->
        <div class="content">
            <!-- LEFT: FORM -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="fas fa-plus-circle"></i> Register New User
                </h2>
                
                <form method="POST" action="" enctype="multipart/form-data" id="userForm">
                    <input type="hidden" name="register_user" value="1">
                    
                    <div class="form-group">
                        <label for="military_number" class="required">Military Number *</label>
                        <input type="text" 
                               id="military_number" 
                               name="military_number" 
                               placeholder="Example: CD001, RH001, ADM001"
                               required
                               value="<?php echo isset($_POST['military_number']) ? htmlspecialchars($_POST['military_number']) : ''; ?>">
                        <span class="field-hint">
                            <i class="fas fa-info-circle"></i> 
                            Format: CD for cadet, RH for rankholder, ADM for admin
                        </span>
                    </div>
                    
                    <div class="form-group">
                        <label for="name" class="required">Full Name *</label>
                        <input type="text" 
                               id="name" 
                               name="name" 
                               placeholder="Example: Ahmad bin Abdullah"
                               required
                               value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="email" class="required">Email *</label>
                            <input type="email" 
                                   id="email" 
                                   name="email" 
                                   placeholder="Example: user@example.com"
                                   required
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" 
                                   id="phone" 
                                   name="phone" 
                                   placeholder="Example: 0123456789"
                                   value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="role" class="required">Role *</label>
                        <select id="role" name="role" required onchange="toggleCadetFields()">
                            <option value="">Select Role</option>
                            <?php foreach ($roleOptions as $value => $label): ?>
                                <option value="<?php echo $value; ?>" <?php echo (isset($_POST['role']) && $_POST['role'] == $value) ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-hint">
                            <i class="fas fa-info-circle"></i> 
                            Select "Cadet" to show additional information
                        </span>
                    </div>
                    
                    <!-- CADET ONLY FIELDS (Hidden by default) -->
                    <div id="cadetFields" class="cadet-fields">
                        <h4 style="color: var(--accent); margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-user-graduate"></i> Cadet Information
                            <span class="cadet-only">(Cadets Only)</span>
                        </h4>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="service_type">Service</label>
                                <select id="service_type" name="service_type">
                                    <option value="">Select Type</option>
                                    <?php foreach ($serviceTypeOptions as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo (isset($_POST['service_type']) && $_POST['service_type'] == $value) ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="rank_level">Rank </label>
                                <select id="rank_level" name="rank_level">
                                    <option value="">Select Level</option>
                                    <?php foreach ($rankLevelOptions as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo (isset($_POST['rank_level']) && $_POST['rank_level'] == $value) ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="join_date">Join Date</label>
                            <input type="date" 
                                   id="join_date" 
                                   name="join_date"
                                   value="<?php echo isset($_POST['join_date']) ? htmlspecialchars($_POST['join_date']) : date('Y-m-d'); ?>">
                            <span class="field-hint">
                                <i class="fas fa-info-circle"></i> 
                                Date the cadet started service
                            </span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="profile_image">Profile Image (Optional)</label>
                        <input type="file" 
                               id="profile_image" 
                               name="profile_image" 
                               accept="image/*">
                        <span class="field-hint">
                            <i class="fas fa-info-circle"></i> 
                            Format: JPG, PNG, GIF (Max: 2MB)
                        </span>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Register User
                    </button>
                </form>
            </div>
            
            <!-- RIGHT: USER PREVIEW -->
            <div class="preview-section">
                <h2 class="section-title">
                    <i class="fas fa-eye"></i> User Preview
                </h2>
                
                <div class="user-preview">
                    <?php if ($registeredUser): ?>
                        <!-- SUCCESS MESSAGE -->
                        <div style="text-align: center; margin-bottom: 20px;">
                            <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--success); margin-bottom: 10px;"></i>
                            <h3 style="color: var(--success);">User Registered!</h3>
                            <p>Provide the credentials below to the user:</p>
                        </div>
                        
                        <!-- CREDENTIALS BOX -->
                        <div class="credentials-box">
                            <strong><i class="fas fa-key"></i> Login Credentials:</strong>
                            <div style="margin-top: 10px;">
                                <div style="margin-bottom: 10px;">
                                    <strong>Military Number:</strong>
                                    <div style="font-family: monospace; font-size: 1.1rem; margin-top: 5px;">
                                        <?php echo htmlspecialchars($registeredUser['military_number']); ?>
                                    </div>
                                    <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($registeredUser['military_number']); ?>')">
                                        <i class="fas fa-copy"></i> Copy
                                    </button>
                                </div>
                                
                                <div style="margin-bottom: 10px;">
                                    <strong>Password:</strong>
                                    <div style="font-family: monospace; font-size: 1.1rem; margin-top: 5px;">
                                        <?php echo htmlspecialchars($registeredUser['password']); ?>
                                    </div>
                                    <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($registeredUser['password']); ?>')">
                                        <i class="fas fa-copy"></i> Copy
                                    </button>
                                </div>
                            </div>
                            <small style="display: block; margin-top: 10px; opacity: 0.9;">
                                <i class="fas fa-share-alt"></i> Credentials have been saved to file for reference
                            </small>
                        </div>
                        
                        <!-- USER INFORMATION -->
                        <div class="user-info">
                            <div class="info-item">
                                <span>User ID:</span>
                                <strong>#<?php echo htmlspecialchars($registeredUser['user_id']); ?></strong>
                            </div>
                            <div class="info-item">
                                <span>Name:</span>
                                <strong><?php echo htmlspecialchars($registeredUser['name']); ?></strong>
                            </div>
                            <div class="info-item">
                                <span>Email:</span>
                                <strong><?php echo htmlspecialchars($registeredUser['email']); ?></strong>
                            </div>
                            <div class="info-item">
                                <span>Role:</span>
                                <strong><?php echo $roleOptions[$registeredUser['role']] ?? $registeredUser['role']; ?></strong>
                            </div>
                            
                            <!-- Only show service, rank, join date for cadet -->
                            <?php if ($registeredUser['role'] === 'cadet'): ?>
                                <?php if ($registeredUser['service_type']): ?>
                                    <div class="info-item">
                                        <span>Service:</span>
                                        <strong><?php echo $serviceTypeOptions[$registeredUser['service_type']] ?? $registeredUser['service_type']; ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if ($registeredUser['rank_level']): ?>
                                    <div class="info-item">
                                        <span>Rank Level:</span>
                                        <strong><?php echo $rankLevelOptions[$registeredUser['rank_level']] ?? $registeredUser['rank_level']; ?></strong>
                                    </div>
                                <?php endif; ?>
                                <div class="info-item">
                                    <span>Join Date:</span>
                                    <strong><?php echo date('d/m/Y', strtotime($registeredUser['registered_at'])); ?></strong>
                                </div>
                            <?php endif; ?>
                            
                            <div class="info-item">
                                <span>Registered at:</span>
                                <strong><?php echo date('d/m/Y H:i:s', strtotime($registeredUser['registered_at'])); ?></strong>
                            </div>
                        </div>
                        
                        <!-- ACTION BUTTONS -->
                        <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
                            <button onclick="printCredentials()" 
                                    class="btn" style="background: var(--accent); color: white;">
                                <i class="fas fa-print"></i> Print Credentials
                            </button>
                            <button onclick="window.location.href='manage_users.php'" 
                                    class="btn" style="background: var(--success); color: white;">
                                <i class="fas fa-users"></i> Manage Users
                            </button>
                            <button onclick="window.location.href='register_user.php'" 
                                    class="btn" style="background: var(--secondary); color: white;">
                                <i class="fas fa-user-plus"></i> Register New
                            </button>
                        </div>
                        
                    <?php else: ?>
                        <div style="padding: 40px 20px; text-align: center; color: var(--secondary);">
                            <i class="fas fa-user-plus" style="font-size: 3rem; opacity: 0.3; margin-bottom: 15px;"></i>
                            <h3 style="margin-bottom: 10px;">No User Registered</h3>
                            <p>Fill out the form on the left to register a new user</p>
                            <small style="display: block; margin-top: 15px; color: #a0aec0;">
                                <i class="fas fa-info-circle"></i> Password will be generated automatically and saved to file
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- RECENT USERS -->
        <div class="users-section">
            <h2 class="section-title">
                <i class="fas fa-history"></i> Recent Users
            </h2>
            
            <?php if ($recentUsers && $recentUsers->num_rows > 0): ?>
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Military Number</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Service </th>
                            <th>Rank </th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($user = $recentUsers->fetch_assoc()): ?>
                            <tr>
                                <td>#<?php echo $user['user_id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($user['military_number']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <span class="role-badge role-<?php echo $user['role']; ?>">
                                        <?php echo strtoupper($user['role']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user['service_type']): ?>
                                        <?php echo $serviceTypeOptions[$user['service_type']] ?? $user['service_type']; ?>
                                    <?php else: ?>
                                        <span style="color: #a0aec0;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['rank_level']): ?>
                                        <?php echo $rankLevelOptions[$user['rank_level']] ?? $user['rank_level']; ?>
                                    <?php else: ?>
                                        <span style="color: #a0aec0;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo date('d/m/Y', strtotime($user['created_at'])); ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="text-align: center; padding: 30px; color: var(--secondary);">
                    <i class="fas fa-user-slash" style="font-size: 2rem; opacity: 0.3; margin-bottom: 10px;"></i>
                    <p>No users have been registered yet</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Toggle cadet fields
        function toggleCadetFields() {
            const roleSelect = document.getElementById('role');
            const cadetFields = document.getElementById('cadetFields');
            
            if (roleSelect.value === 'cadet') {
                cadetFields.style.display = 'block';
            } else {
                cadetFields.style.display = 'none';
                
                // Clear cadet fields when not selected
                document.getElementById('service_type').value = '';
                document.getElementById('rank_level').value = '';
                document.getElementById('join_date').value = '';
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleCadetFields(); // Set initial state
            
            // Set join date to today if cadet is selected
            const roleSelect = document.getElementById('role');
            const joinDateField = document.getElementById('join_date');
            
            if (roleSelect.value === 'cadet' && (!joinDateField.value || joinDateField.value === '')) {
                const today = new Date();
                const yyyy = today.getFullYear();
                const mm = String(today.getMonth() + 1).padStart(2, '0');
                const dd = String(today.getDate()).padStart(2, '0');
                joinDateField.value = `${yyyy}-${mm}-${dd}`;
            }
        });
        
        // Copy to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                showToast('Copied successfully!', 'success');
            }).catch(err => {
                // Fallback method
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                showToast('Copied successfully!', 'success');
            });
        }
        
        // Print credentials
        function printCredentials() {
            <?php if ($registeredUser): ?>
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>CAAMS - User Credentials</title>
                    <style>
                        body { font-family: Arial; padding: 20px; }
                        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 15px; }
                        .credentials { background: #f0f0f0; padding: 15px; margin: 15px 0; border-radius: 5px; }
                        .warning { background: #fff3cd; padding: 10px; border-radius: 5px; margin: 15px 0; }
                        .info { margin: 10px 0; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h2>CAAMS - Login Credentials</h2>
                        <p>For: <?php echo htmlspecialchars($registeredUser['name']); ?></p>
                    </div>
                    
                    <div class="info">
                        <p><strong>User ID:</strong> #<?php echo $registeredUser['user_id']; ?></p>
                        <p><strong>Registered at:</strong> <?php echo date('d/m/Y H:i:s', strtotime($registeredUser['registered_at'])); ?></p>
                    </div>
                    
                    <div class="credentials">
                        <h3>Login Information:</h3>
                        <p><strong>System URL:</strong> http://<?php echo $_SERVER['HTTP_HOST']; ?></p>
                        <p><strong>Military Number:</strong> <?php echo htmlspecialchars($registeredUser['military_number']); ?></p>
                        <p><strong>Password:</strong> <?php echo htmlspecialchars($registeredUser['password']); ?></p>
                    </div>
                    
                    <?php if ($registeredUser['role'] === 'cadet'): ?>
                    <div class="info">
                        <h3>Cadet Information:</h3>
                        <?php if ($registeredUser['service_type']): ?>
                        <p><strong>Service Type:</strong> <?php echo $serviceTypeOptions[$registeredUser['service_type']] ?? $registeredUser['service_type']; ?></p>
                        <?php endif; ?>
                        <?php if ($registeredUser['rank_level']): ?>
                        <p><strong>Rank Level:</strong> <?php echo $rankLevelOptions[$registeredUser['rank_level']] ?? $registeredUser['rank_level']; ?></p>
                        <?php endif; ?>
                        <p><strong>Join Date:</strong> <?php echo date('d/m/Y', strtotime($registeredUser['registered_at'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <div class="warning">
                        <h4><i class="fas fa-exclamation-triangle"></i> Important Instructions:</h4>
                        <ul>
                            <li>Use the above credentials for first login</li>
                            <li>Change password after first login</li>
                            <li>Do not share credentials with anyone</li>
                            <li>Contact admin if there are login issues</li>
                        </ul>
                    </div>
                    
                    <div class="info">
                        <p><small><i>Printed on: <?php echo date('d/m/Y H:i:s'); ?></i></small></p>
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
            <?php endif; ?>
        }
        
        // Toast notification
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                background: ${type === 'success' ? '#48bb78' : '#f56565'};
                color: white;
                border-radius: 8px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
                z-index: 1000;
                animation: slideInRight 0.3s ease-out;
            `;
            
            toast.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i>
                ${message}
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOutRight 0.3s ease-out';
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 3000);
        }
        
        // Add CSS animations for toast
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
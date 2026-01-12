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

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $address = $_POST['address'] ?? '';
    
    // Update user info
    $updateQuery = "UPDATE users SET name = ?, email = ?, phone = ?, address = ? WHERE user_id = ?";
    $stmt = $db->prepare($updateQuery);
    $stmt->bind_param("ssssi", $name, $email, $phone, $address, $cadet_id);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Profil berjaya dikemas kini!";
        header("Location: dashboard.php");
        exit();
    } else {
        $_SESSION['error'] = "Gagal mengemas kini profil.";
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
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kemas Kini Profil - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
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
        }
        
        .btn-save {
            background: var(--success);
            color: white;
        }
        
        .btn-cancel {
            background: #e2e8f0;
            color: var(--primary);
            text-decoration: none;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-edit"></i> Kemas Kini Profil</h1>
            <p>Kemaskini maklumat peribadi anda</p>
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
        
        <div class="form-card">
            <form method="POST" action="">
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Nama Penuh</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($userData['name'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Alamat Emel</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Nombor Telefon</label>
                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($userData['phone'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-home"></i> Alamat Rumah</label>
                    <textarea name="address"><?php echo htmlspecialchars($userData['address'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-actions">
                    <a href="dashboard.php" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Batal
                    </a>
                    <button type="submit" class="btn btn-save">
                        <i class="fas fa-save"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
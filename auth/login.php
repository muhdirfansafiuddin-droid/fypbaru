<?php
// auth/login_simple.php - FOR TESTING
session_start();

// Direct connection untuk testing
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'caams_fyp';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $military_number = $conn->real_escape_string($_POST['military_number']);
    $password_input = $_POST['password'];
    
    $sql = "SELECT * FROM users WHERE military_number = '$military_number'";
    $result = $conn->query($sql);
    
    if ($result->num_rows === 0) {
        $error = "Nombor tentera tidak dijumpai";
    } else {
        $user = $result->fetch_assoc();
        
        // Verify password (password123 adalah default)
        if (password_verify($password_input, $user['password']) || $password_input === 'password123') {
            // Set session
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['military_number'] = $user['military_number'];
            
            // Redirect based on role
            switch($user['role']) {
                case 'admin':
                    header('Location: ../admin/dashboard.php');
                    break;
                case 'rankholder':
                    header('Location: ../rankholder/dashboard.php');
                    break;
                case 'cadet':
                    header('Location: ../cadet/dashboard.php');
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
        body { font-family: Arial; background: #f4f4f4; padding: 20px; }
        .login-box { max-width: 400px; margin: 50px auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        h2 { color: #333; text-align: center; margin-bottom: 30px; }
        .input-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; color: #555; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
        button { width: 100%; padding: 12px; background: #1a365d; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
        button:hover { background: #2c5282; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
        .test-creds { background: #d4edda; padding: 15px; border-radius: 5px; margin-top: 20px; }
        .test-creds h4 { margin-top: 0; color: #155724; }
        .role-buttons { display: flex; gap: 10px; margin-bottom: 20px; }
        .role-btn { flex: 1; padding: 10px; background: #e2e8f0; border: none; border-radius: 5px; cursor: pointer; }
        .role-btn.active { background: #1a365d; color: white; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>CAAMS Login</h2>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="role-buttons">
            <button class="role-btn active" onclick="setRole('cadet')">Cadet</button>
            <button class="role-btn" onclick="setRole('rankholder')">Rankholder</button>
            <button class="role-btn" onclick="setRole('admin')">Admin</button>
        </div>
        
        <form method="POST" action="">
            <div class="input-group">
                <label>Nombor Tentera</label>
                <input type="text" name="military_number" id="military_number" required autofocus>
            </div>
            
            <div class="input-group">
                <label>Kata Laluan</label>
                <input type="password" name="password" required>
            </div>
            
            <button type="submit">Login</button>
        </form>
        
        <div class="test-creds">
            <h4>Test Credentials:</h4>
            <p><strong>Admin:</strong> ADM001 / password123</p>
            <p><strong>Rankholder:</strong> RH001 / password123</p>
            <p><strong>Cadet:</strong> CD001 / password123</p>
            <p><strong>Cadet (baru):</strong> NV8709403 / password123</p>
            
            <div style="margin-top: 15px;">
                <button type="button" onclick="fillCredentials('ADM001')" style="background: #1a365d; color: white; padding: 8px; border: none; border-radius: 5px; margin-right: 5px; cursor: pointer;">Admin</button>
                <button type="button" onclick="fillCredentials('RH001')" style="background: #2d3748; color: white; padding: 8px; border: none; border-radius: 5px; margin-right: 5px; cursor: pointer;">Rankholder</button>
                <button type="button" onclick="fillCredentials('CD001')" style="background: #3182ce; color: white; padding: 8px; border: none; border-radius: 5px; cursor: pointer;">Cadet</button>
            </div>
        </div>
    </div>
    
    <script>
        function setRole(role) {
            // Update button styles
            document.querySelectorAll('.role-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Auto-fill based on role
            switch(role) {
                case 'admin':
                    fillCredentials('ADM001');
                    break;
                case 'rankholder':
                    fillCredentials('RH001');
                    break;
                case 'cadet':
                    fillCredentials('CD001');
                    break;
            }
        }
        
        function fillCredentials(username) {
            document.getElementById('military_number').value = username;
            document.querySelector('input[name="password"]').value = 'password123';
            
            // Highlight input
            document.getElementById('military_number').style.borderColor = '#48bb78';
            document.getElementById('military_number').style.boxShadow = '0 0 0 2px rgba(72, 187, 120, 0.3)';
            
            setTimeout(() => {
                document.getElementById('military_number').style.borderColor = '#ddd';
                document.getElementById('military_number').style.boxShadow = 'none';
            }, 1000);
        }
        
        // Auto-focus
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('military_number').focus();
        });
    </script>
</body>
</html>
<?php
// public/unauthorized.php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - CAAMS</title>
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
        
        .error-container {
            text-align: center;
            padding: 50px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
        }
        
        .error-icon {
            font-size: 5rem;
            color: #f56565;
            margin-bottom: 20px;
        }
        
        h1 {
            color: #1a365d;
            margin-bottom: 15px;
        }
        
        p {
            color: #718096;
            margin-bottom: 25px;
            line-height: 1.6;
        }
        
        .user-info {
            background: #f7fafc;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: left;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #3182ce;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            margin: 5px;
        }
        
        .btn:hover {
            background: #2c5282;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #718096;
        }
        
        .btn-secondary:hover {
            background: #4a5568;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="fas fa-ban"></i>
        </div>
        
        <h1>Access Denied</h1>
        <p>You don't have permission to access this page with your current role.</p>
        
        <?php if (isset($_SESSION['name'])): ?>
        <div class="user-info">
            <p><strong>Logged in as:</strong> <?php echo htmlspecialchars($_SESSION['name']); ?></p>
            <p><strong>Role:</strong> <?php echo isset($_SESSION['role']) ? ucfirst($_SESSION['role']) : 'Unknown'; ?></p>
        </div>
        <?php endif; ?>
        
        <div>
            <?php if (isset($_SESSION['user_id'])): ?>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="/fypbaru/admin/dashboard.php" class="btn">
                        <i class="fas fa-tachometer-alt"></i> Back to Dashboard
                    </a>
                <?php elseif ($_SESSION['role'] === 'rankholder'): ?>
                    <a href="/fypbaru/rankholder/dashboard.php" class="btn">
                        <i class="fas fa-tachometer-alt"></i> Back to Dashboard
                    </a>
                <?php else: ?>
                    <a href="/fypbaru/cadet/dashboard.php" class="btn">
                        <i class="fas fa-tachometer-alt"></i> Back to Dashboard
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            
            <a href="/fypbaru/logout.php" class="btn btn-secondary">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</body>
</html>
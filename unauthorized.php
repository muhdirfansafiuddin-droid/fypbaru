<?php
// unauthorized.php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Access Denied</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
        }
        .error-container {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
        }
        .error-container i {
            font-size: 4rem;
            color: #f56565;
            margin-bottom: 20px;
        }
        .error-container h1 {
            color: #1a365d;
            margin-bottom: 10px;
        }
        .error-container p {
            color: #718096;
            margin-bottom: 25px;
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
        }
        .btn:hover {
            background: #2c5282;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="error-container">
        <i class="fas fa-ban"></i>
        <h1>Access Denied</h1>
        <p>You don't have permission to access this page.</p>
        <a href="<?php echo isset($_SESSION['user_id']) ? getDashboardUrl() : 'login.php'; ?>" class="btn">
            <?php echo isset($_SESSION['user_id']) ? 'Back to Dashboard' : 'Go to Login'; ?>
        </a>
    </div>
</body>
</html>
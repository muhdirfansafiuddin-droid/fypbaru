<?php
require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
RBAC::checkPermission('admin');
$user = (new Auth())->getCurrentUser();
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo ucfirst(str_replace('_', ' ', basename(__FILE__, '.php'))); ?> - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .header { background: #1a365d; color: white; padding: 25px 30px; }
        .content { padding: 30px; }
        .back-btn { color: white; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            <h1><i class="fas fa-tools"></i> <?php echo ucfirst(str_replace('_', ' ', basename(__FILE__, '.php'))); ?></h1>
        </div>
        <div class="content">
            <h3>Under Development</h3>
            <p>This feature is currently being developed. Check back soon!</p>
            <div style="background: #f7fafc; padding: 20px; border-radius: 10px; margin-top: 20px;">
                <h4>Coming Features:</h4>
                <ul>
                    <li>Real-time data processing</li>
                    <li>Interactive interface</li>
                    <li>Export functionality</li>
                    <li>Advanced filtering</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>

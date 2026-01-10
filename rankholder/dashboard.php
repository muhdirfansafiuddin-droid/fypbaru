<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is rankholder
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'rankholder') {
    header("Location: ../login.php");
    exit();
}

// Get rankholder info
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get today's attendance count
$today = date('Y-m-d');
$attendance_stmt = $pdo->prepare("
    SELECT COUNT(*) as total_cadets 
    FROM attendance a 
    JOIN users u ON a.user_id = u.user_id 
    WHERE u.role = 'cadet' AND DATE(a.date) = ?
");
$attendance_stmt->execute([$today]);
$attendance_data = $attendance_stmt->fetch();
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Rankholder</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Mobile-first responsive dashboard */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 250px;
            background: linear-gradient(180deg, #1a237e 0%, #283593 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s;
            z-index: 1000;
        }
        
        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header h2 {
            font-size: 1.2rem;
            margin-bottom: 5px;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            padding: 15px;
            background: rgba(255,255,255,0.1);
            margin: 15px;
            border-radius: 10px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: #1a237e;
            font-size: 1.5rem;
        }
        
        .user-info h4 {
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        .user-info p {
            font-size: 0.8rem;
            opacity: 0.8;
        }
        
        .nav-menu {
            list-style: none;
            padding: 0 15px;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .logout-btn {
            margin: 20px 15px;
            display: block;
            width: calc(100% - 30px);
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 20px;
            transition: margin-left 0.3s;
        }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #1a237e;
            cursor: pointer;
        }
        
        .page-title h1 {
            color: #1a237e;
            font-size: 1.5rem;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            font-size: 1.8rem;
        }
        
        .stat-info h3 {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 5px;
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            color: #1a237e;
        }
        
        /* Quick Actions */
        .quick-actions {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .quick-actions h2 {
            color: #1a237e;
            margin-bottom: 20px;
            font-size: 1.3rem;
        }
        
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .action-btn {
            display: flex;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border: none;
            border-radius: 10px;
            color: #333;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .action-btn:hover {
            background: #1a237e;
            color: white;
            transform: translateY(-2px);
        }
        
        .action-btn i {
            margin-right: 10px;
            font-size: 1.2rem;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .mobile-menu-btn {
                display: block;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .page-title {
                margin-top: 10px;
            }
        }
        
        @media (min-width: 769px) and (max-width: 1024px) {
            .sidebar {
                width: 220px;
            }
            
            .main-content {
                margin-left: 220px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-shield-alt"></i> CAAMS</h2>
                <p>Rankholder Panel</p>
            </div>
            
            <div class="user-profile">
                <div class="user-avatar">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($user['name']); ?></h4>
                    <p><?php echo htmlspecialchars($user['military_number']); ?></p>
                    <p><i class="fas fa-star"></i> <?php echo htmlspecialchars($user['rank_level'] ?? 'N/A'); ?></p>
                </div>
            </div>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link active">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="cadet_leave.php" class="nav-link">
                        <i class="fas fa-file-upload"></i> Cadet Leave
                    </a>
                </li>
                <li class="nav-item">
                    <a href="view_attendance.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i> View Attendance
                    </a>
                </li>
                <li class="nav-item">
                    <a href="reports.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i> Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a href="profile.php" class="nav-link">
                        <i class="fas fa-user-cog"></i> Profile
                    </a>
                </li>
            </ul>
            
            <a href="../logout.php" class="btn btn-danger logout-btn">
                <i class="fas fa-sign-out-alt"></i> Log Keluar
            </a>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="top-bar">
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>Dashboard Rankholder</h1>
                    <p>Selamat datang kembali, <?php echo htmlspecialchars($user['name']); ?></p>
                </div>
                <div class="date-time">
                    <p><?php echo date('d/m/Y'); ?> | <span id="currentTime"></span></p>
                </div>
            </div>
            
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #e3f2fd; color: #1976d2;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Cadets</h3>
                        <div class="stat-number">15</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #e8f5e9; color: #388e3c;">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Today's Attendance</h3>
                        <div class="stat-number"><?php echo $attendance_data['total_cadets'] ?? '0'; ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fff3e0; color: #f57c00;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Pending Leaves</h3>
                        <div class="stat-number">3</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fce4ec; color: #c2185b;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Avg Performance</h3>
                        <div class="stat-number">82%</div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="quick-actions">
                <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                <div class="action-buttons">
                    <a href="cadet_leave.php" class="action-btn">
                        <i class="fas fa-file-upload"></i>
                        <div>
                            <strong>Upload Leave Proof</strong>
                            <small>Cadet absence documentation</small>
                        </div>
                    </a>
                    
                    <a href="view_attendance.php" class="action-btn">
                        <i class="fas fa-eye"></i>
                        <div>
                            <strong>View Attendance</strong>
                            <small>Check cadet attendance records</small>
                        </div>
                    </a>
                    
                    <a href="reports.php" class="action-btn">
                        <i class="fas fa-download"></i>
                        <div>
                            <strong>Generate Reports</strong>
                            <small>Export attendance data</small>
                        </div>
                    </a>
                    
                    <a href="profile.php" class="action-btn">
                        <i class="fas fa-user-edit"></i>
                        <div>
                            <strong>Update Profile</strong>
                            <small>Edit your information</small>
                        </div>
                    </a>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div class="quick-actions" style="margin-top: 30px;">
                <h2><i class="fas fa-history"></i> Recent Activity</h2>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa;">
                                <th style="padding: 12px; text-align: left;">Date</th>
                                <th style="padding: 12px; text-align: left;">Activity</th>
                                <th style="padding: 12px; text-align: left;">Cadet</th>
                                <th style="padding: 12px; text-align: left;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;">09/01/2025</td>
                                <td style="padding: 12px;">Leave Application</td>
                                <td style="padding: 12px;">CD001 - Ahmad Lee</td>
                                <td style="padding: 12px;"><span style="color: #f57c00;">Pending</span></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;">08/01/2025</td>
                                <td style="padding: 12px;">Attendance Update</td>
                                <td style="padding: 12px;">CD002 - Siti Sarah</td>
                                <td style="padding: 12px;"><span style="color: #388e3c;">Approved</span></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;">07/01/2025</td>
                                <td style="padding: 12px;">Performance Review</td>
                                <td style="padding: 12px;">CD003 - Raju Kumar</td>
                                <td style="padding: 12px;"><span style="color: #1976d2;">Completed</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });
        
        // Update current time
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('ms-MY', { 
                hour: '2-digit', 
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('currentTime').textContent = timeString;
        }
        
        setInterval(updateTime, 1000);
        updateTime();
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileBtn = document.getElementById('mobileMenuBtn');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !mobileBtn.contains(event.target) &&
                sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
            }
        });
    </script>
</body>
</html>
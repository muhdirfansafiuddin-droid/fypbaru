<?php
// rankholder/dashboard.php - UPDATED
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start output buffering
ob_start();

// Use the same core files as admin
require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

// Check permission - MUST BE rankholder
RBAC::checkPermission('rankholder');

try {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    $db = new Database();
    
    // Check if user is logged in
    if (!$user) {
        header("Location: ../index.php");
        exit();
    }
    
    // Double check role (extra security)
    if ($user['role'] !== 'rankholder') {
        header("Location: ../unauthorized.php");
        exit();
    }
    
} catch (Exception $e) {
    die("Error initializing system: " . $e->getMessage());
}

// Get today's attendance count
$attendance_today = 0;
try {
    $today = date('Y-m-d');
    $attendance_sql = "SELECT COUNT(DISTINCT a.user_id) as total_cadets 
                       FROM attendance a 
                       JOIN users u ON a.user_id = u.user_id 
                       WHERE u.role = 'cadet' 
                       AND DATE(a.date) = ?
                       AND a.status = 'present'";
    $attendance_stmt = $db->prepare($attendance_sql);
    $attendance_stmt->bind_param("s", $today);
    $attendance_stmt->execute();
    $result = $attendance_stmt->get_result();
    $attendance_data = $result->fetch_assoc();
    $attendance_today = $attendance_data['total_cadets'] ?? 0;
} catch (Exception $e) {
    $attendance_today = 0;
}

// Get total cadets under this rankholder's service
$total_cadets = 0;
try {
    $service_type = $user['service_type'] ?? null;
    if ($service_type) {
        $cadets_sql = "SELECT COUNT(*) as total FROM users 
                      WHERE role = 'cadet' AND service_type = ?";
        $cadets_stmt = $db->prepare($cadets_sql);
        $cadets_stmt->bind_param("s", $service_type);
        $cadets_stmt->execute();
        $result = $cadets_stmt->get_result();
        $row = $result->fetch_assoc();
        $total_cadets = $row['total'] ?? 0;
    }
} catch (Exception $e) {
    $total_cadets = 0;
}

// Get pending leaves count
$pending_leaves = 0;
try {
    $leaves_sql = "SELECT COUNT(*) as total FROM attendance 
                  WHERE is_leave = 1 AND (status = 'excused' OR status IS NULL) 
                  AND checked_by IS NULL";
    $leaves_stmt = $db->prepare($leaves_sql);
    $leaves_stmt->execute();
    $result = $leaves_stmt->get_result();
    $row = $result->fetch_assoc();
    $pending_leaves = $row['total'] ?? 0;
} catch (Exception $e) {
    $pending_leaves = 0;
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Rankholder</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            width: calc(100% - 30px);
            margin: 20px 15px;
            padding: 12px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .logout-btn:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        
        .logout-btn i {
            margin-right: 8px;
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
        
        /* Recent Activity */
        .recent-activity {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-top: 30px;
        }
        
        .recent-activity h2 {
            color: #1a237e;
            margin-bottom: 20px;
            font-size: 1.3rem;
        }
        
        .activity-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .activity-table th {
            background: #f8f9fa;
            color: #333;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        
        .activity-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        .status-pending { color: #f57c00; font-weight: 600; }
        .status-approved { color: #388e3c; font-weight: 600; }
        .status-completed { color: #1976d2; font-weight: 600; }
        
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
                    <p><i class="fas fa-flag"></i> <?php echo strtoupper(htmlspecialchars($user['service_type'] ?? 'N/A')); ?></p>
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
            
            <a href="../logout.php" class="logout-btn" onclick="return confirm('Log out dari sistem?')">
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
                        <div class="stat-number"><?php echo $total_cadets; ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #e8f5e9; color: #388e3c;">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Today's Attendance</h3>
                        <div class="stat-number"><?php echo $attendance_today; ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fff3e0; color: #f57c00;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Pending Leaves</h3>
                        <div class="stat-number"><?php echo $pending_leaves; ?></div>
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
            <div class="recent-activity">
                <h2><i class="fas fa-history"></i> Recent Activity</h2>
                <table class="activity-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Activity</th>
                            <th>Cadet</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>09/01/2025</td>
                            <td>Leave Application</td>
                            <td>CD001 - Ahmad Lee</td>
                            <td class="status-pending">Pending</td>
                        </tr>
                        <tr>
                            <td>08/01/2025</td>
                            <td>Attendance Update</td>
                            <td>CD002 - Siti Sarah</td>
                            <td class="status-approved">Approved</td>
                        </tr>
                        <tr>
                            <td>07/01/2025</td>
                            <td>Performance Review</td>
                            <td>CD003 - Raju Kumar</td>
                            <td class="status-completed">Completed</td>
                        </tr>
                        <tr>
                            <td>06/01/2025</td>
                            <td>Upload Medical Certificate</td>
                            <td>CD004 - Mei Ling</td>
                            <td class="status-approved">Approved</td>
                        </tr>
                    </tbody>
                </table>
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
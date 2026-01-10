<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is cadet
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'cadet') {
    header("Location: ../login.php");
    exit();
}

// Get cadet info
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$cadet = $stmt->fetch();

// Get cadet attendance summary for current month
$current_month = date('Y-m');
$attendance_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_sessions,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count
    FROM attendance 
    WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
");
$attendance_stmt->execute([$user_id, $current_month]);
$attendance_summary = $attendance_stmt->fetch();

// Get latest performance grade
$performance_stmt = $pdo->prepare("
    SELECT performance_grade, attendance_score, discipline_score, skill_score
    FROM users WHERE user_id = ?
");
$performance_stmt->execute([$user_id]);
$performance = $performance_stmt->fetch();

// Get latest allowance
$allowance_stmt = $pdo->prepare("
    SELECT month_year, total_amount 
    FROM allowance_calculations 
    WHERE user_id = ? 
    ORDER BY calculated_at DESC 
    LIMIT 1
");
$allowance_stmt->execute([$user_id]);
$latest_allowance = $allowance_stmt->fetch();

// Get pending leaves
$leaves_stmt = $pdo->prepare("
    SELECT COUNT(*) as pending_leaves 
    FROM attendance 
    WHERE user_id = ? AND status = 'excused' AND is_leave = 1 AND verified_by IS NULL
");
$leaves_stmt->execute([$user_id]);
$pending_leaves = $leaves_stmt->fetch();

// Get upcoming training sessions
$upcoming_stmt = $pdo->prepare("
    SELECT ts.training_date, ts.location, ts.training_type, ts.session_time
    FROM training_sessions ts
    WHERE ts.training_date >= CURDATE() 
    AND ts.is_active = 1
    ORDER BY ts.training_date ASC
    LIMIT 3
");
$upcoming_stmt->execute();
$upcoming_sessions = $upcoming_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Cadet</title>
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
            padding: 12px;
            background: rgba(255,255,255,0.1);
            border: none;
            border-radius: 8px;
            color: white;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .logout-btn:hover {
            background: rgba(255,255,255,0.2);
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
        
        .welcome-banner {
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 150px;
            height: 150px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }
        
        .welcome-banner::after {
            content: '';
            position: absolute;
            bottom: -30px;
            right: 20px;
            width: 100px;
            height: 100px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
        }
        
        .welcome-banner h2 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }
        
        .welcome-banner p {
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        
        .attendance-icon { background: #e8f5e9; color: #388e3c; }
        .performance-icon { background: #fff3e0; color: #f57c00; }
        .allowance-icon { background: #e3f2fd; color: #1976d2; }
        .leaves-icon { background: #fce4ec; color: #c2185b; }
        
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
        
        .stat-details {
            font-size: 0.8rem;
            color: #888;
            margin-top: 5px;
        }
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .content-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .card-header {
            padding: 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            color: #1a237e;
            font-size: 1.1rem;
        }
        
        .card-header a {
            color: #1a237e;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Attendance Chart */
        .attendance-chart {
            display: flex;
            align-items: flex-end;
            height: 150px;
            gap: 10px;
            margin-top: 15px;
        }
        
        .chart-bar {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .bar-value {
            width: 100%;
            background: linear-gradient(to top, #4caf50, #8bc34a);
            border-radius: 5px 5px 0 0;
            min-height: 5px;
        }
        
        .bar-label {
            margin-top: 8px;
            font-size: 0.8rem;
            color: #666;
        }
        
        /* Upcoming Sessions */
        .session-item {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .session-item:last-child {
            border-bottom: none;
        }
        
        .session-date {
            width: 50px;
            height: 50px;
            background: #f5f5f5;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        
        .session-day {
            font-size: 1.2rem;
            font-weight: bold;
            color: #1a237e;
        }
        
        .session-month {
            font-size: 0.7rem;
            color: #666;
        }
        
        .session-info h4 {
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        
        .session-info p {
            font-size: 0.8rem;
            color: #888;
        }
        
        .session-time {
            margin-left: auto;
            background: #e8f5e9;
            color: #388e3c;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        
        /* Quick Actions */
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
        }
        
        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 15px;
            background: #f8f9fa;
            border: none;
            border-radius: 12px;
            color: #333;
            text-decoration: none;
            transition: all 0.3s;
            text-align: center;
        }
        
        .action-btn:hover {
            background: #1a237e;
            color: white;
            transform: translateY(-3px);
        }
        
        .action-btn i {
            font-size: 1.8rem;
            margin-bottom: 10px;
            color: inherit;
        }
        
        .action-btn span {
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        /* Performance Grades */
        .grade-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .grade-item:last-child {
            border-bottom: none;
        }
        
        .grade-label {
            font-size: 0.9rem;
            color: #666;
        }
        
        .grade-value {
            font-weight: bold;
            color: #1a237e;
        }
        
        .grade-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .grade-a { background: #e8f5e9; color: #388e3c; }
        .grade-b { background: #fff3e0; color: #f57c00; }
        .grade-c { background: #ffebee; color: #c62828; }
        
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
            
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .page-title {
                margin-top: 10px;
            }
            
            .welcome-banner {
                padding: 20px;
            }
            
            .welcome-banner h2 {
                font-size: 1.3rem;
            }
        }
        
        @media (min-width: 769px) and (max-width: 1024px) {
            .sidebar {
                width: 220px;
            }
            
            .main-content {
                margin-left: 220px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
                <p>Cadet Panel</p>
            </div>
            
            <div class="user-profile">
                <div class="user-avatar">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($cadet['name']); ?></h4>
                    <p><?php echo htmlspecialchars($cadet['military_number']); ?></p>
                    <p><i class="fas fa-star"></i> <?php echo htmlspecialchars($cadet['rank_level'] ?? 'Junior'); ?></p>
                    <p><i class="fas fa-crosshairs"></i> <?php echo htmlspecialchars($cadet['service_type'] ?? 'N/A'); ?></p>
                </div>
            </div>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link active">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="attendance.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i> Attendance
                    </a>
                </li>
                <li class="nav-item">
                    <a href="performance.php" class="nav-link">
                        <i class="fas fa-chart-line"></i> Performance
                    </a>
                </li>
                <li class="nav-item">
                    <a href="allowance.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i> Allowance
                    </a>
                </li>
                <li class="nav-item">
                    <a href="leave_status.php" class="nav-link">
                        <i class="fas fa-clipboard-list"></i> Leave Status
                    </a>
                </li>
                <li class="nav-item">
                    <a href="profile.php" class="nav-link">
                        <i class="fas fa-user-cog"></i> Profile
                    </a>
                </li>
            </ul>
            
            <a href="../logout.php" class="logout-btn">
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
                    <h1>Dashboard Kadet</h1>
                    <p>Selamat datang, <?php echo htmlspecialchars($cadet['name']); ?></p>
                </div>
                <div class="date-time">
                    <p><?php echo date('d/m/Y'); ?> | <span id="currentTime"></span></p>
                </div>
            </div>
            
            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <h2><i class="fas fa-graduation-cap"></i> Selamat Datang, Kadet!</h2>
                <p>Pantau prestasi, kehadiran, dan elaun anda di satu tempat. Sentiasa berdisiplin dan berusaha untuk kecemerlangan.</p>
            </div>
            
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon attendance-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Attendance Rate</h3>
                        <div class="stat-number">
                            <?php 
                            if ($attendance_summary && $attendance_summary['total_sessions'] > 0) {
                                $attendance_rate = ($attendance_summary['present_count'] / $attendance_summary['total_sessions']) * 100;
                                echo round($attendance_rate, 1) . '%';
                            } else {
                                echo '0%';
                            }
                            ?>
                        </div>
                        <div class="stat-details">
                            <?php echo $attendance_summary['present_count'] ?? '0'; ?> of <?php echo $attendance_summary['total_sessions'] ?? '0'; ?> sessions
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon performance-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Performance Grade</h3>
                        <div class="stat-number">
                            <?php echo htmlspecialchars($performance['performance_grade'] ?? 'N/A'); ?>
                        </div>
                        <div class="stat-details">
                            Overall Score: <?php echo round($performance['attendance_score'] ?? 0, 1); ?>%
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon allowance-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Latest Allowance</h3>
                        <div class="stat-number">
                            RM <?php echo number_format($latest_allowance['total_amount'] ?? 0, 2); ?>
                        </div>
                        <div class="stat-details">
                            <?php echo htmlspecialchars($latest_allowance['month_year'] ?? 'N/A'); ?>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon leaves-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Pending Leaves</h3>
                        <div class="stat-number">
                            <?php echo $pending_leaves['pending_leaves'] ?? '0'; ?>
                        </div>
                        <div class="stat-details">
                            Waiting for approval
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Upcoming Sessions -->
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-calendar-alt"></i> Upcoming Training</h3>
                        <a href="attendance.php">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($upcoming_sessions)): ?>
                            <?php foreach ($upcoming_sessions as $session): ?>
                                <div class="session-item">
                                    <div class="session-date">
                                        <div class="session-day"><?php echo date('d', strtotime($session['training_date'])); ?></div>
                                        <div class="session-month"><?php echo date('M', strtotime($session['training_date'])); ?></div>
                                    </div>
                                    <div class="session-info">
                                        <h4><?php echo htmlspecialchars($session['training_type']); ?></h4>
                                        <p><?php echo htmlspecialchars($session['location']); ?></p>
                                    </div>
                                    <div class="session-time">
                                        <?php echo htmlspecialchars($session['session_time']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align: center; color: #888; padding: 20px 0;">No upcoming sessions</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Performance Overview -->
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Performance Breakdown</h3>
                        <a href="performance.php">Details</a>
                    </div>
                    <div class="card-body">
                        <div class="grade-item">
                            <span class="grade-label">Attendance Score</span>
                            <span class="grade-value"><?php echo round($performance['attendance_score'] ?? 0, 1); ?>%</span>
                        </div>
                        <div class="grade-item">
                            <span class="grade-label">Discipline Score</span>
                            <span class="grade-value"><?php echo round($performance['discipline_score'] ?? 0, 1); ?>%</span>
                        </div>
                        <div class="grade-item">
                            <span class="grade-label">Skill Score</span>
                            <span class="grade-value"><?php echo round($performance['skill_score'] ?? 0, 1); ?>%</span>
                        </div>
                        <div class="grade-item">
                            <span class="grade-label">Overall Grade</span>
                            <span class="grade-value grade-badge <?php 
                                $grade = $performance['performance_grade'] ?? 'C';
                                echo 'grade-' . strtolower(substr($grade, 0, 1));
                            ?>">
                                <?php echo htmlspecialchars($performance['performance_grade'] ?? 'C'); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                </div>
                <div class="card-body">
                    <div class="action-buttons">
                        <a href="attendance.php" class="action-btn">
                            <i class="fas fa-calendar-check"></i>
                            <span>View Attendance</span>
                        </a>
                        
                        <a href="performance.php" class="action-btn">
                            <i class="fas fa-chart-line"></i>
                            <span>Check Performance</span>
                        </a>
                        
                        <a href="allowance.php" class="action-btn">
                            <i class="fas fa-money-bill-wave"></i>
                            <span>Allowance Details</span>
                        </a>
                        
                        <a href="leave_status.php" class="action-btn">
                            <i class="fas fa-clipboard-list"></i>
                            <span>Leave Status</span>
                        </a>
                        
                        <a href="profile.php" class="action-btn">
                            <i class="fas fa-user-edit"></i>
                            <span>Update Profile</span>
                        </a>
                        
                        <a href="../logout.php" class="action-btn">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Attendance Chart -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-bar"></i> Monthly Attendance</h3>
                    <a href="attendance.php">Full History</a>
                </div>
                <div class="card-body">
                    <div class="attendance-chart">
                        <?php
                        // Simulated attendance data for the week
                        $week_data = [
                            ['day' => 'Mon', 'value' => 85],
                            ['day' => 'Tue', 'value' => 90],
                            ['day' => 'Wed', 'value' => 75],
                            ['day' => 'Thu', 'value' => 95],
                            ['day' => 'Fri', 'value' => 80],
                            ['day' => 'Sat', 'value' => 70],
                            ['day' => 'Sun', 'value' => 65],
                        ];
                        
                        foreach ($week_data as $day_data):
                        ?>
                        <div class="chart-bar">
                            <div class="bar-value" style="height: <?php echo $day_data['value']; ?>%;"></div>
                            <div class="bar-label"><?php echo $day_data['day']; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-top: 15px; font-size: 0.8rem; color: #666;">
                        <div><span style="display: inline-block; width: 12px; height: 12px; background: #4caf50; border-radius: 2px; margin-right: 5px;"></span> Present</div>
                        <div>Weekly Average: 80%</div>
                    </div>
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
        
        // Add animation to stat cards on load
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(20px)';
                    card.style.transition = 'opacity 0.5s, transform 0.5s';
                    
                    setTimeout(() => {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, 50);
                }, index * 100);
            });
        });
    </script>
</body>
</html>
<?php
// rankholder/dashboard.php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';

// Check rankholder authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'rankholder') {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get rankholder information
$user_sql = "SELECT u.*, 
            (SELECT COUNT(*) FROM users WHERE role = 'cadet' AND created_by = u.user_id) as total_cadets
            FROM users u a
            WHERE u.user_id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$rankholder = $user_result->fetch_assoc();

// Get recent cadets
$cadets_sql = "SELECT user_id, military_number, name, email, rank_level, service_type, 
               attendance_score, discipline_score, skill_score, created_at
               FROM users 
               WHERE role = 'cadet' AND created_by = ?
               ORDER BY created_at DESC 
               LIMIT 5";
$cadets_stmt = $conn->prepare($cadets_sql);
$cadets_stmt->bind_param("i", $user_id);
$cadets_stmt->execute();
$cadets_result = $cadets_stmt->get_result();

// Get recent attendance records
$attendance_sql = "SELECT a.*, u.name, u.military_number 
                   FROM attendance a
                   JOIN users u ON a.user_id = u.user_id
                   WHERE u.created_by = ?
                   ORDER BY a.date DESC, a.check_in_time DESC
                   LIMIT 5";
$attendance_stmt = $conn->prepare($attendance_sql);
$attendance_stmt->bind_param("i", $user_id);
$attendance_stmt->execute();
$attendance_result = $attendance_stmt->get_result();

// Get performance statistics
$stats_sql = "SELECT 
                COUNT(*) as total_cadets,
                AVG(attendance_score) as avg_attendance,
                AVG(discipline_score) as avg_discipline,
                AVG(skill_score) as avg_skill,
                SUM(CASE WHEN rank_level = 'junior' THEN 1 ELSE 0 END) as junior_count,
                SUM(CASE WHEN rank_level = 'intermediate' THEN 1 ELSE 0 END) as intermediate_count,
                SUM(CASE WHEN rank_level = 'senior' THEN 1 ELSE 0 END) as senior_count
              FROM users 
              WHERE role = 'cadet' AND created_by = ?";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

// Get upcoming trainings
$trainings_sql = "SELECT t.*, u.name as trainer_name, u.military_number as trainer_number
                  FROM trainings t
                  LEFT JOIN users u ON t.trainer_id = u.user_id
                  WHERE t.created_by = ? AND t.training_date >= CURDATE()
                  ORDER BY t.training_date ASC
                  LIMIT 5";
$trainings_stmt = $conn->prepare($trainings_sql);
$trainings_stmt->bind_param("i", $user_id);
$trainings_stmt->execute();
$trainings_result = $trainings_stmt->get_result();

// Service type mapping
$serviceTypeOptions = [
    'darat' => 'Darat',
    'laut' => 'Laut',
    'udara' => 'Udara'
];

// Rank level mapping
$rankLevelOptions = [
    'junior' => 'Junior',
    'intermediate' => 'Intermediate',
    'senior' => 'Senior'
];

// Calculate overall performance score
$overall_score = 0;
if ($stats['total_cadets'] > 0) {
    $overall_score = (
        $stats['avg_attendance'] + 
        $stats['avg_discipline'] + 
        $stats['avg_skill']
    ) / 3;
}

// Format date for display
function formatDate($date) {
    return date('d/m/Y', strtotime($date));
}

// Format time for display
function formatTime($time) {
    return date('h:i A', strtotime($time));
}

// Get badge color based on score
function getScoreColor($score) {
    if ($score >= 80) return 'success';
    if ($score >= 60) return 'warning';
    return 'danger';
}

// Get badge color based on rank
function getRankColor($rank) {
    switch($rank) {
        case 'junior': return 'primary';
        case 'intermediate': return 'warning';
        case 'senior': return 'success';
        default: return 'secondary';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Rankholder - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --success: #48bb78;
            --warning: #ed8936;
            --danger: #f56565;
            --info: #4299e1;
            --light: #f7fafc;
            --dark: #1a202c;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        
        body {
            background: #f0f2f5;
            color: var(--dark);
            min-height: 100vh;
        }
        
        /* MOBILE FIRST APPROACH */
        
        /* TOP NAVIGATION - MOBILE */
        .mobile-nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: var(--primary);
            color: white;
            padding: 15px;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .menu-toggle {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 5px;
        }
        
        .mobile-nav-title {
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
        }
        
        /* SIDEBAR - MOBILE */
        .sidebar {
            position: fixed;
            top: 70px;
            left: -280px;
            bottom: 0;
            width: 280px;
            background: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            z-index: 999;
            transition: left 0.3s ease;
            overflow-y: auto;
        }
        
        .sidebar.active {
            left: 0;
        }
        
        .sidebar-header {
            padding: 20px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            text-align: center;
        }
        
        .sidebar-user {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        
        .sidebar-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: bold;
            border: 4px solid white;
        }
        
        .sidebar-menu {
            padding: 20px 0;
        }
        
        .sidebar-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 20px;
            color: var(--secondary);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 4px solid transparent;
        }
        
        .sidebar-item:hover, .sidebar-item.active {
            background: var(--light);
            border-left-color: var(--accent);
            color: var(--accent);
        }
        
        .sidebar-item i {
            width: 20px;
            text-align: center;
        }
        
        /* MAIN CONTENT */
        .main-content {
            margin-top: 70px;
            padding: 20px;
            min-height: calc(100vh - 70px);
        }
        
        /* WELCOME BANNER */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .welcome-banner h1 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }
        
        .welcome-banner p {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        /* STATS CARDS */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }
        
        .stat-icon.primary { background: var(--primary); }
        .stat-icon.success { background: var(--success); }
        .stat-icon.warning { background: var(--warning); }
        .stat-icon.danger { background: var(--danger); }
        .stat-icon.info { background: var(--info); }
        
        .stat-info h3 {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }
        
        .stat-info p {
            color: #718096;
            font-size: 0.9rem;
        }
        
        /* SECTIONS */
        .dashboard-section {
            background: white;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .section-header {
            padding: 15px 20px;
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-header h2 {
            font-size: 1.2rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-header .btn {
            padding: 8px 15px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .section-content {
            padding: 20px;
        }
        
        /* TABLES */
        .table-responsive {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background: var(--light);
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: var(--secondary);
            border-bottom: 2px solid #e2e8f0;
        }
        
        .data-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .data-table tr:hover {
            background: #f8fafc;
        }
        
        /* BADGES */
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-primary { background: #bee3f8; color: #2c5282; }
        .badge-success { background: #c6f6d5; color: #276749; }
        .badge-warning { background: #feebc8; color: #975a16; }
        .badge-danger { background: #fed7d7; color: #c53030; }
        .badge-info { background: #bee3f8; color: #2c5282; }
        .badge-secondary { background: #e2e8f0; color: #4a5568; }
        
        /* EMPTY STATE */
        .empty-state {
            padding: 40px 20px;
            text-align: center;
            color: #a0aec0;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.3;
        }
        
        /* PROGRESS BARS */
        .progress-container {
            margin: 20px 0;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--success);
            border-radius: 4px;
            transition: width 0.3s;
        }
        
        /* QUICK ACTIONS */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        @media (min-width: 768px) {
            .quick-actions {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        .action-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        
        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            background: var(--light);
        }
        
        .action-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--accent);
        }
        
        .action-card h3 {
            font-size: 1rem;
            color: var(--secondary);
        }
        
        /* OVERLAY */
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 998;
            display: none;
        }
        
        .overlay.active {
            display: block;
        }
        
        /* DESKTOP STYLES */
        @media (min-width: 1024px) {
            .mobile-nav {
                display: none;
            }
            
            .sidebar {
                position: fixed;
                left: 0;
                top: 0;
                bottom: 0;
                width: 250px;
            }
            
            .main-content {
                margin-top: 0;
                margin-left: 250px;
                padding: 30px;
            }
            
            .overlay {
                display: none !important;
            }
            
            .welcome-banner {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 30px;
            }
            
            .welcome-banner h1 {
                font-size: 2rem;
            }
        }
        
        /* NOTIFICATION */
        .notification-bell {
            position: relative;
            margin-right: 15px;
        }
        
        .notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: white;
            font-size: 0.7rem;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* PERFORMANCE SCORE */
        .performance-score {
            text-align: center;
            padding: 20px;
        }
        
        .score-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: conic-gradient(var(--accent) <?php echo $overall_score; ?>%, #e2e8f0 0%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            position: relative;
        }
        
        .score-inner {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: bold;
        }
        
        /* RANK DISTRIBUTION */
        .rank-distribution {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .rank-bar {
            flex: 1;
            text-align: center;
        }
        
        .rank-bar-value {
            height: 100px;
            background: var(--light);
            border-radius: 5px;
            position: relative;
            overflow: hidden;
        }
        
        .rank-fill {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--accent);
            transition: height 0.3s;
        }
        
        .rank-bar-label {
            margin-top: 10px;
            font-size: 0.9rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <!-- MOBILE NAVIGATION -->
    <nav class="mobile-nav">
        <button class="menu-toggle" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <div class="mobile-nav-title">
            <i class="fas fa-chart-line"></i> Dashboard
        </div>
        <div style="display: flex; align-items: center; gap: 15px;">
            <div class="notification-bell">
                <i class="fas fa-bell"></i>
                <span class="notification-count">3</span>
            </div>
            <div class="user-avatar">
                <?php echo strtoupper(substr($rankholder['name'], 0, 1)); ?>
            </div>
        </div>
    </nav>
    
    <!-- OVERLAY -->
    <div class="overlay" onclick="toggleSidebar()"></div>
    
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-user">
                <div class="sidebar-avatar">
                    <?php echo strtoupper(substr($rankholder['name'], 0, 1)); ?>
                </div>
                <div style="text-align: center;">
                    <h3><?php echo htmlspecialchars($rankholder['name']); ?></h3>
                    <p style="opacity: 0.8; font-size: 0.9rem;">
                        <?php echo htmlspecialchars($rankholder['military_number']); ?>
                    </p>
                    <span class="badge badge-warning" style="margin-top: 5px;">
                        <?php echo $rankLevelOptions[$rankholder['rank_level']] ?? 'Rankholder'; ?>
                    </span>
                </div>
            </div>
        </div>
        
        <div class="sidebar-menu">
            <a href="dashboard.php" class="sidebar-item active">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="manage_cadets.php" class="sidebar-item">
                <i class="fas fa-users"></i> Urus Kadet
            </a>
            <a href="attendance.php" class="sidebar-item">
                <i class="fas fa-clipboard-check"></i> Kehadiran
            </a>
            <a href="trainings.php" class="sidebar-item">
                <i class="fas fa-dumbbell"></i> Latihan
            </a>
            <a href="reports.php" class="sidebar-item">
                <i class="fas fa-chart-bar"></i> Laporan
            </a>
            <a href="messages.php" class="sidebar-item">
                <i class="fas fa-envelope"></i> Mesej
            </a>
            <a href="profile.php" class="sidebar-item">
                <i class="fas fa-user-cog"></i> Profil
            </a>
            <a href="../logout.php" class="sidebar-item" style="color: var(--danger);">
                <i class="fas fa-sign-out-alt"></i> Log Keluar
            </a>
        </div>
        
        <div style="padding: 20px; margin-top: auto; border-top: 1px solid var(--light);">
            <div class="performance-score">
                <div class="score-circle">
                    <div class="score-inner">
                        <?php echo round($overall_score, 1); ?>%
                    </div>
                </div>
                <p style="color: var(--secondary); font-weight: 600;">Prestasi Keseluruhan</p>
            </div>
        </div>
    </aside>
    
    <!-- MAIN CONTENT -->
    <main class="main-content">
        <!-- WELCOME BANNER -->
        <div class="welcome-banner">
            <div>
                <h1>Selamat Datang, <?php echo htmlspecialchars($rankholder['name']); ?>!</h1>
                <p>
                    <i class="fas fa-calendar-alt"></i> 
                    <?php echo date('l, d F Y'); ?> 
                    • 
                    <i class="fas fa-clock"></i> 
                    <?php echo date('h:i A'); ?>
                </p>
            </div>
            <div style="text-align: right;">
                <p style="font-size: 1.2rem; font-weight: bold;">
                    <?php echo $stats['total_cadets'] ?? 0; ?> Kadet
                </p>
                <p style="opacity: 0.8;">Di bawah seliaan anda</p>
            </div>
        </div>
        
        <!-- QUICK ACTIONS -->
        <div class="quick-actions">
            <div class="action-card" onclick="window.location.href='manage_cadets.php'">
                <div class="action-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <h3>Tambah Kadet</h3>
            </div>
            <div class="action-card" onclick="window.location.href='attendance.php?action=mark'">
                <div class="action-icon">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <h3>Tanda Kehadiran</h3>
            </div>
            <div class="action-card" onclick="window.location.href='trainings.php?action=add'">
                <div class="action-icon">
                    <i class="fas fa-dumbbell"></i>
                </div>
                <h3>Jadual Latihan</h3>
            </div>
            <div class="action-card" onclick="window.location.href='reports.php'">
                <div class="action-icon">
                    <i class="fas fa-file-export"></i>
                </div>
                <h3>Hasilkan Laporan</h3>
            </div>
        </div>
        
        <!-- STATS CARDS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_cadets'] ?? 0; ?></h3>
                    <p>Jumlah Kadet</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo round($stats['avg_attendance'] ?? 0, 1); ?>%</h3>
                    <p>Purata Kehadiran</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-medal"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo round($overall_score, 1); ?>%</h3>
                    <p>Skor Prestasi</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo date('d/m'); ?></h3>
                    <p>Hari Ini</p>
                </div>
            </div>
        </div>
        
        <!-- TWO COLUMN LAYOUT FOR DESKTOP -->
        <div style="display: grid; grid-template-columns: 1fr; gap: 20px;">
            
            <!-- LEFT COLUMN -->
            <div>
                <!-- RECENT CADETS -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2><i class="fas fa-users"></i> Kadet Terkini</h2>
                        <a href="manage_cadets.php" class="btn">Lihat Semua</a>
                    </div>
                    <div class="section-content">
                        <?php if ($cadets_result->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Nombor Tentera</th>
                                            <th>Nama</th>
                                            <th>Pangkat</th>
                                            <th>Skor</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($cadet = $cadets_result->fetch_assoc()): 
                                            $total_score = ($cadet['attendance_score'] + $cadet['discipline_score'] + $cadet['skill_score']) / 3;
                                        ?>
                                            <tr onclick="window.location.href='view_cadet.php?id=<?php echo $cadet['user_id']; ?>'" style="cursor: pointer;">
                                                <td>
                                                    <strong><?php echo htmlspecialchars($cadet['military_number']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($cadet['name']); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo getRankColor($cadet['rank_level']); ?>">
                                                        <?php echo $rankLevelOptions[$cadet['rank_level']] ?? $cadet['rank_level']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo getScoreColor($total_score); ?>">
                                                        <?php echo round($total_score, 1); ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-user-slash"></i>
                                <p>Tiada kadet didaftarkan</p>
                                <button onclick="window.location.href='manage_cadets.php'" class="btn" style="margin-top: 15px;">
                                    <i class="fas fa-user-plus"></i> Daftar Kadet Pertama
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- UPCOMING TRAININGS -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2><i class="fas fa-dumbbell"></i> Latihan Akan Datang</h2>
                        <a href="trainings.php" class="btn">Lihat Semua</a>
                    </div>
                    <div class="section-content">
                        <?php if ($trainings_result->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Tarikh</th>
                                            <th>Jenis</th>
                                            <th>Masa</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($training = $trainings_result->fetch_assoc()): ?>
                                            <tr onclick="window.location.href='view_training.php?id=<?php echo $training['training_id']; ?>'" style="cursor: pointer;">
                                                <td><?php echo formatDate($training['training_date']); ?></td>
                                                <td><?php echo htmlspecialchars($training['training_type']); ?></td>
                                                <td><?php echo formatTime($training['start_time']); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo strtotime($training['training_date']) == strtotime(date('Y-m-d')) ? 'warning' : 'info'; ?>">
                                                        <?php echo strtotime($training['training_date']) == strtotime(date('Y-m-d')) ? 'Hari Ini' : 'Akan Datang'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-dumbbell"></i>
                                <p>Tiada latihan dijadualkan</p>
                                <button onclick="window.location.href='trainings.php?action=add'" class="btn" style="margin-top: 15px;">
                                    <i class="fas fa-plus"></i> Jadual Latihan
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- RIGHT COLUMN -->
            <div>
                <!-- ATTENDANCE RECORDS -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2><i class="fas fa-clipboard-check"></i> Rekod Kehadiran Terkini</h2>
                        <a href="attendance.php" class="btn">Lihat Semua</a>
                    </div>
                    <div class="section-content">
                        <?php if ($attendance_result->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Nama</th>
                                            <th>Tarikh</th>
                                            <th>Masa Masuk</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($attendance = $attendance_result->fetch_assoc()): ?>
                                            <tr onclick="window.location.href='attendance_details.php?id=<?php echo $attendance['attendance_id']; ?>'" style="cursor: pointer;">
                                                <td><?php echo htmlspecialchars($attendance['name']); ?></td>
                                                <td><?php echo formatDate($attendance['date']); ?></td>
                                                <td><?php echo formatTime($attendance['check_in_time']); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $attendance['status'] == 'present' ? 'success' : ($attendance['status'] == 'late' ? 'warning' : 'danger'); ?>">
                                                        <?php 
                                                            echo $attendance['status'] == 'present' ? 'Hadir' : 
                                                                 ($attendance['status'] == 'late' ? 'Lewat' : 'Tidak Hadir'); 
                                                        ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-clipboard"></i>
                                <p>Tiada rekod kehadiran</p>
                                <button onclick="window.location.href='attendance.php?action=mark'" class="btn" style="margin-top: 15px;">
                                    <i class="fas fa-check"></i> Tanda Kehadiran
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- PERFORMANCE SUMMARY -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2><i class="fas fa-chart-pie"></i> Ringkasan Prestasi</h2>
                    </div>
                    <div class="section-content">
                        <!-- SCORE BREAKDOWN -->
                        <div class="progress-container">
                            <div class="progress-label">
                                <span>Kehadiran</span>
                                <span><?php echo round($stats['avg_attendance'] ?? 0, 1); ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $stats['avg_attendance'] ?? 0; ?>%; background: var(--success);"></div>
                            </div>
                        </div>
                        
                        <div class="progress-container">
                            <div class="progress-label">
                                <span>Disiplin</span>
                                <span><?php echo round($stats['avg_discipline'] ?? 0, 1); ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $stats['avg_discipline'] ?? 0; ?>%; background: var(--info);"></div>
                            </div>
                        </div>
                        
                        <div class="progress-container">
                            <div class="progress-label">
                                <span>Kemahiran</span>
                                <span><?php echo round($stats['avg_skill'] ?? 0, 1); ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $stats['avg_skill'] ?? 0; ?>%; background: var(--warning);"></div>
                            </div>
                        </div>
                        
                        <!-- RANK DISTRIBUTION -->
                        <h4 style="margin-top: 25px; margin-bottom: 15px; color: var(--secondary);">
                            <i class="fas fa-layer-group"></i> Taburan Pangkat
                        </h4>
                        <div class="rank-distribution">
                            <div class="rank-bar">
                                <div class="rank-bar-value">
                                    <?php 
                                        $junior_percent = $stats['total_cadets'] > 0 ? 
                                            ($stats['junior_count'] / $stats['total_cadets']) * 100 : 0;
                                    ?>
                                    <div class="rank-fill" style="height: <?php echo $junior_percent; ?>%; background: var(--primary);"></div>
                                </div>
                                <div class="rank-bar-label">Junior</div>
                                <div style="font-size: 0.9rem; color: #718096;">
                                    <?php echo $stats['junior_count'] ?? 0; ?>
                                </div>
                            </div>
                            
                            <div class="rank-bar">
                                <div class="rank-bar-value">
                                    <?php 
                                        $intermediate_percent = $stats['total_cadets'] > 0 ? 
                                            ($stats['intermediate_count'] / $stats['total_cadets']) * 100 : 0;
                                    ?>
                                    <div class="rank-fill" style="height: <?php echo $intermediate_percent; ?>%; background: var(--warning);"></div>
                                </div>
                                <div class="rank-bar-label">Intermediate</div>
                                <div style="font-size: 0.9rem; color: #718096;">
                                    <?php echo $stats['intermediate_count'] ?? 0; ?>
                                </div>
                            </div>
                            
                            <div class="rank-bar">
                                <div class="rank-bar-value">
                                    <?php 
                                        $senior_percent = $stats['total_cadets'] > 0 ? 
                                            ($stats['senior_count'] / $stats['total_cadets']) * 100 : 0;
                                    ?>
                                    <div class="rank-fill" style="height: <?php echo $senior_percent; ?>%; background: var(--success);"></div>
                                </div>
                                <div class="rank-bar-label">Senior</div>
                                <div style="font-size: 0.9rem; color: #718096;">
                                    <?php echo $stats['senior_count'] ?? 0; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- QUICK LINKS -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2><i class="fas fa-link"></i> Pautan Pantas</h2>
                    </div>
                    <div class="section-content" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
                        <a href="manage_cadets.php" class="action-card" style="text-decoration: none;">
                            <div class="action-icon">
                                <i class="fas fa-user-edit"></i>
                            </div>
                            <h3>Edit Kadet</h3>
                        </a>
                        <a href="reports.php?type=attendance" class="action-card" style="text-decoration: none;">
                            <div class="action-icon">
                                <i class="fas fa-file-contract"></i>
                            </div>
                            <h3>Laporan Kehadiran</h3>
                        </a>
                        <a href="trainings.php?view=calendar" class="action-card" style="text-decoration: none;">
                            <div class="action-icon">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <h3>Kalendar</h3>
                        </a>
                        <a href="messages.php" class="action-card" style="text-decoration: none;">
                            <div class="action-icon">
                                <i class="fas fa-comments"></i>
                            </div>
                            <h3>Mesej</h3>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- BOTTOM NAVIGATION FOR MOBILE -->
    <nav class="mobile-nav" style="position: fixed; bottom: 0; top: auto; display: none;">
        <div style="display: flex; justify-content: space-around; width: 100%;">
            <a href="dashboard.php" style="color: white; text-align: center;">
                <i class="fas fa-home"></i>
                <div style="font-size: 0.8rem;">Utama</div>
            </a>
            <a href="manage_cadets.php" style="color: white; text-align: center;">
                <i class="fas fa-users"></i>
                <div style="font-size: 0.8rem;">Kadet</div>
            </a>
            <a href="attendance.php" style="color: white; text-align: center;">
                <i class="fas fa-clipboard-check"></i>
                <div style="font-size: 0.8rem;">Kehadiran</div>
            </a>
            <a href="trainings.php" style="color: white; text-align: center;">
                <i class="fas fa-dumbbell"></i>
                <div style="font-size: 0.8rem;">Latihan</div>
            </a>
        </div>
    </nav>
    
    <script>
        // Toggle sidebar on mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.overlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.overlay');
            const menuToggle = document.querySelector('.menu-toggle');
            
            if (window.innerWidth < 1024) {
                if (!sidebar.contains(event.target) && 
                    !menuToggle.contains(event.target) && 
                    sidebar.classList.contains('active')) {
                    toggleSidebar();
                }
            }
        });
        
        // Show/hide bottom nav based on screen size
        function toggleBottomNav() {
            const bottomNav = document.querySelector('.mobile-nav:nth-child(3)');
            if (window.innerWidth < 768) {
                bottomNav.style.display = 'flex';
            } else {
                bottomNav.style.display = 'none';
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleBottomNav();
            
            // Auto-refresh stats every 30 seconds
            setInterval(() => {
                // You can implement AJAX refresh here
                console.log('Auto-refreshing dashboard...');
            }, 30000);
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            toggleBottomNav();
            
            // Auto-close sidebar on desktop
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.overlay');
            if (window.innerWidth >= 1024) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            }
        });
        
        // Theme switcher (optional)
        const themeToggle = document.createElement('button');
        themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
        themeToggle.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            border: none;
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
            cursor: pointer;
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        `;
        
        themeToggle.onclick = function() {
            document.body.classList.toggle('dark-mode');
            themeToggle.innerHTML = document.body.classList.contains('dark-mode') ? 
                '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
            
            // Save theme preference
            localStorage.setItem('theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
        };
        
        // Check for saved theme preference
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }
        
        document.body.appendChild(themeToggle);
        
        // Dark mode styles
        const darkModeStyles = document.createElement('style');
        darkModeStyles.textContent = `
            body.dark-mode {
                background: #1a202c;
                color: #e2e8f0;
            }
            
            body.dark-mode .dashboard-section,
            body.dark-mode .stat-card,
            body.dark-mode .action-card {
                background: #2d3748;
                color: #e2e8f0;
            }
            
            body.dark-mode .data-table th {
                background: #4a5568;
                color: #e2e8f0;
            }
            
            body.dark-mode .data-table td {
                border-color: #4a5568;
            }
            
            body.dark-mode .sidebar-item:hover,
            body.dark-mode .sidebar-item.active {
                background: #4a5568;
            }
            
            body.dark-mode .progress-bar {
                background: #4a5568;
            }
        `;
        document.head.appendChild(darkModeStyles);
    </script>
</body>
</html>
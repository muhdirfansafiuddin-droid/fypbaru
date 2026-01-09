<?php
// cadet/dashboard.php - DENGAN GRADING SYSTEM YANG BETUL
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('cadet');
$user = (new Auth())->getCurrentUser();
$db = new Database();

// 1. Get monthly attendance stats
$monthlySql = "SELECT 
    COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_days,
    COUNT(CASE WHEN a.status = 'excused' THEN 1 END) as excused_days,
    COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent_days,
    COUNT(*) as total_days
    FROM attendance a
    WHERE a.user_id = ? 
    AND MONTH(a.date) = MONTH(CURDATE())
    AND YEAR(a.date) = YEAR(CURDATE())";

$statsStmt = $db->prepare($monthlySql);
$statsStmt->bind_param("i", $user['user_id']);
$statsStmt->execute();
$monthlyStats = $statsStmt->get_result()->fetch_assoc();

// Calculate attendance rate
$presentDays = $monthlyStats['present_days'] ?? 0;
$totalDays = $monthlyStats['total_days'] ?? 0;
$attendanceRate = $totalDays > 0 ? round(($presentDays / $totalDays) * 100) : 0;

// 2. Get latest allowance
$allowanceSql = "SELECT * FROM allowance_calculations 
    WHERE user_id = ? 
    ORDER BY month_year DESC LIMIT 1";
$allowanceStmt = $db->prepare($allowanceSql);
$allowanceStmt->bind_param("i", $user['user_id']);
$allowanceStmt->execute();
$latestAllowance = $allowanceStmt->get_result()->fetch_assoc();

// 3. Get today's activities
$todaySql = "SELECT ts.* 
    FROM training_sessions ts
    WHERE ts.training_date = CURDATE()
    AND ts.is_active = 1
    AND ts.expires_at > NOW()
    ORDER BY FIELD(session_time, 'pagi', 'tengah hari', 'petang', 'malam')";
$todayStmt = $db->prepare($todaySql);
$todayStmt->execute();
$todayActivities = $todayStmt->get_result();

// 4. Get pending leaves count
$leavesSql = "SELECT COUNT(*) as pending_count
    FROM attendance a
    WHERE a.user_id = ?
    AND a.status = 'excused'
    AND a.checked_by IS NULL
    AND a.date >= CURDATE()";
$leavesStmt = $db->prepare($leavesSql);
$leavesStmt->bind_param("i", $user['user_id']);
$leavesStmt->execute();
$pendingLeaves = $leavesStmt->get_result()->fetch_assoc()['pending_count'] ?? 0;

// 5. Calculate performance grade berdasarkan attendance rate
function calculateGrade($attendanceRate) {
    if ($attendanceRate >= 90) return ['A+', 'Cemerlang Tertinggi', 4.00, '#155724', '#d4edda'];
    if ($attendanceRate >= 80) return ['A', 'Cemerlang', 4.00, '#0f5132', '#d1e7dd'];
    if ($attendanceRate >= 75) return ['A-', 'Kepujian Tinggi', 3.67, '#0c4128', '#badbcc'];
    if ($attendanceRate >= 70) return ['B+', 'Kepujian', 3.33, '#38761d', '#d9ead3'];
    if ($attendanceRate >= 65) return ['B', 'Kepujian', 3.00, '#274e13', '#cfe2b5'];
    if ($attendanceRate >= 60) return ['B-', 'Lulus Baik', 2.67, '#1c3b0a', '#b6d7a8'];
    if ($attendanceRate >= 55) return ['C+', 'Lulus', 2.33, '#783f04', '#fce5cd'];
    if ($attendanceRate >= 50) return ['C', 'Lulus Minimum', 2.00, '#674ea7', '#d9d2e9'];
    if ($attendanceRate >= 45) return ['C-', 'Lulus Bersyarat', 1.67, '#351c75', '#c9c2e6'];
    if ($attendanceRate >= 40) return ['D+', 'Lulus Lemah', 1.33, '#741b47', '#f4cccc'];
    if ($attendanceRate >= 35) return ['D', 'Lulus Lemah', 1.00, '#5b0f00', '#ea9999'];
    return ['E/F', 'Gagal', 0.00, '#660000', '#f8d7da'];
}

$gradeInfo = calculateGrade($attendanceRate);
$gradeLetter = $gradeInfo[0];
$gradeDescription = $gradeInfo[1];
$gradePoint = $gradeInfo[2];
$gradeColor = $gradeInfo[3];
$gradeBgColor = $gradeInfo[4];

// Session time labels
$sessionTimeLabels = [
    'pagi' => 'Pagi',
    'tengah hari' => 'Tengah Hari', 
    'petang' => 'Petang',
    'malam' => 'Malam'
];

// FIXED: Helper functions to prevent deprecated warnings
function safeHtmlSpecialChars($string) {
    return $string !== null ? htmlspecialchars($string, ENT_QUOTES, 'UTF-8') : '';
}

function safeUcfirst($string) {
    return $string !== null ? ucfirst($string) : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cadet Dashboard - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --success: #48bb78;
            --warning: #ed8936;
            --danger: #f56565;
            --darat: #48bb78;
            --laut: #4299e1;
            --udara: #9f7aea;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        body {
            background: #f0f2f5;
            color: #2d3748;
            line-height: 1.6;
            -webkit-tap-highlight-color: transparent;
        }
        
        /* MOBILE CONTAINER */
        .mobile-container {
            max-width: 100%;
            min-height: 100vh;
            padding-bottom: 70px; /* Space for footer */
        }
        
        /* HEADER */
        .mobile-header {
            background: linear-gradient(135deg, var(--primary) 0%, #2c5282 100%);
            color: white;
            padding: 20px 16px;
            position: relative;
            overflow: hidden;
        }
        
        .header-content {
            position: relative;
            z-index: 2;
        }
        
        .greeting {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        
        .user-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .service-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .service-darat { background: rgba(72, 187, 120, 0.2); color: #48bb78; }
        .service-laut { background: rgba(66, 153, 225, 0.2); color: #4299e1; }
        .service-udara { background: rgba(159, 122, 234, 0.2); color: #9f7aea; }
        
        /* LOGOUT BUTTON */
        .logout-btn {
            position: absolute;
            top: 20px;
            right: 16px;
            background: rgba(255,255,255,0.15);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
            cursor: pointer;
            z-index: 3;
        }
        
        /* STATS CARDS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            padding: 16px;
            margin-top: -30px;
            position: relative;
            z-index: 2;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transition: transform 0.2s;
        }
        
        .stat-card:active {
            transform: scale(0.98);
        }
        
        .stat-icon {
            font-size: 1.5rem;
            margin-bottom: 8px;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 5px 0;
        }
        
        .stat-attendance .stat-icon { color: var(--success); }
        .stat-allowance .stat-icon { color: var(--warning); }
        .stat-grade .stat-icon { color: var(--udara); }
        
        .stat-label {
            font-size: 0.8rem;
            color: #718096;
        }
        
        /* GRADE CARD */
        .grade-display {
            margin: 16px;
            padding: 20px;
            border-radius: 12px;
            background: <?php echo $gradeBgColor; ?>;
            color: <?php echo $gradeColor; ?>;
            text-align: center;
            border: 2px solid <?php echo $gradeColor; ?>;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .grade-letter {
            font-size: 3rem;
            font-weight: 900;
            margin-bottom: 5px;
        }
        
        .grade-description {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .grade-details {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 15px;
        }
        
        .grade-item {
            background: rgba(255,255,255,0.8);
            padding: 10px;
            border-radius: 8px;
        }
        
        .grade-item-label {
            font-size: 0.8rem;
            opacity: 0.8;
        }
        
        .grade-item-value {
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        /* QUICK ACTIONS */
        .quick-actions {
            padding: 16px;
        }
        
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .action-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        .action-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        
        .action-card:active {
            transform: scale(0.98);
            border-color: var(--accent);
        }
        
        .action-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--accent);
        }
        
        .action-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--primary);
        }
        
        .action-desc {
            font-size: 0.85rem;
            color: #718096;
        }
        
        /* TODAY'S ACTIVITIES */
        .activities-section {
            padding: 16px;
        }
        
        .activities-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .activity-item {
            background: white;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-left: 4px solid var(--accent);
        }
        
        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .activity-title {
            font-weight: 600;
            color: var(--primary);
            font-size: 1rem;
        }
        
        .activity-time {
            background: var(--light);
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            color: var(--accent);
            font-weight: 600;
        }
        
        .activity-details {
            font-size: 0.9rem;
            color: #718096;
        }
        
        .activity-details div {
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .no-activity {
            text-align: center;
            padding: 30px;
            color: #a0aec0;
        }
        
        .no-activity i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        /* PENDING LEAVES */
        .pending-section {
            padding: 16px;
        }
        
        .pending-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-left: 4px solid var(--warning);
        }
        
        .pending-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .pending-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #fff3cd;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--warning);
            font-size: 1.2rem;
        }
        
        .pending-count {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--warning);
        }
        
        /* FOOTER NAV */
        .mobile-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            padding: 10px 16px;
            display: flex;
            justify-content: space-around;
            border-top: 1px solid #e2e8f0;
            z-index: 100;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #718096;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.2s;
            min-width: 60px;
        }
        
        .nav-item.active {
            color: var(--accent);
            background: rgba(49, 130, 206, 0.1);
        }
        
        .nav-icon {
            font-size: 1.2rem;
            margin-bottom: 4px;
        }
        
        .nav-label {
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        /* LOADING */
        .loading {
            display: flex;
            justify-content: center;
            padding: 40px;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #e2e8f0;
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* RESPONSIVE */
        @media (min-width: 768px) {
            .mobile-container {
                max-width: 500px;
                margin: 0 auto;
                box-shadow: 0 0 30px rgba(0,0,0,0.1);
            }
            
            .mobile-footer {
                max-width: 500px;
                left: 50%;
                transform: translateX(-50%);
            }
        }
        
        @media (max-width: 380px) {
            .action-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .grade-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- MOBILE CONTAINER -->
    <div class="mobile-container">
        <!-- HEADER -->
        <header class="mobile-header">
            <button class="logout-btn" onclick="logout()">
                <i class="fas fa-sign-out-alt"></i>
            </button>
            
            <div class="header-content">
                <div class="greeting">
                    <i class="fas fa-sun"></i> Selamat 
                    <?php
                    $hour = date('H');
                    if ($hour < 12) echo 'Pagi';
                    elseif ($hour < 15) echo 'Tengah Hari';
                    elseif ($hour < 19) echo 'Petang';
                    else echo 'Malam';
                    ?>
                </div>
                <h1 class="user-name"><?php echo safeHtmlSpecialChars($user['name']); ?></h1>
                <div class="user-info">
                    <span><i class="fas fa-id-card"></i> <?php echo safeHtmlSpecialChars($user['military_number'] ?? ''); ?></span>
                    <span class="service-badge service-<?php echo safeHtmlSpecialChars($user['service_type'] ?? ''); ?>">
                        <i class="fas fa-<?php 
                            echo ($user['service_type'] ?? 'darat') == 'darat' ? 'mountain' : 
                                 (($user['service_type'] ?? 'darat') == 'laut' ? 'anchor' : 'plane'); 
                        ?>"></i>
                        <?php echo strtoupper(safeHtmlSpecialChars($user['service_type'] ?? '')); ?>
                    </span>
                    <span><i class="fas fa-graduation-cap"></i> <?php echo safeUcfirst($user['rank_level'] ?? ''); ?> Cadet</span>
                </div>
            </div>
        </header>
        
        <!-- STATS CARDS -->
        <div class="stats-grid">
            <div class="stat-card stat-attendance">
                <div class="stat-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-number"><?php echo $attendanceRate; ?>%</div>
                <div class="stat-label">Kehadiran</div>
            </div>
            
            <div class="stat-card stat-allowance">
                <div class="stat-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-number">
                    <?php if ($latestAllowance): ?>
                        RM<?php echo number_format($latestAllowance['total_amount'], 0); ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </div>
                <div class="stat-label">Elaun Terkini</div>
            </div>
            
            <div class="stat-card stat-grade">
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-number"><?php echo $gradeLetter; ?></div>
                <div class="stat-label">Gred</div>
            </div>
        </div>
        
        <!-- GRADE DISPLAY -->
        <div class="grade-display">
            <div class="grade-letter"><?php echo $gradeLetter; ?></div>
            <div class="grade-description"><?php echo $gradeDescription; ?></div>
            <div class="grade-details">
                <div class="grade-item">
                    <div class="grade-item-label">Markah</div>
                    <div class="grade-item-value"><?php echo $attendanceRate; ?>%</div>
                </div>
                <div class="grade-item">
                    <div class="grade-item-label">Gred Point</div>
                    <div class="grade-item-value"><?php echo $gradePoint; ?></div>
                </div>
                <div class="grade-item">
                    <div class="grade-item-label">Status</div>
                    <div class="grade-item-value">
                        <?php 
                        if ($gradePoint >= 2.0) echo 'LULUS';
                        elseif ($gradePoint >= 1.0) echo 'LULUS LEMAH';
                        else echo 'GAGAL';
                        ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- QUICK ACTIONS -->
        <div class="quick-actions">
            <h2 class="section-title">
                <i class="fas fa-bolt"></i> Menu Pantas
            </h2>
            
            <div class="action-grid">
                <a href="profile.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <div class="action-title">Profil</div>
                    <div class="action-desc">Lihat maklumat peribadi</div>
                </a>
                
                <a href="performance.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="action-title">Prestasi</div>
                    <div class="action-desc">Lihat laporan prestasi</div>
                </a>
                
                <a href="allowance.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-money-bill"></i>
                    </div>
                    <div class="action-title">Elaun</div>
                    <div class="action-desc">Lihat rekod elaun</div>
                </a>
                
                <a href="attendance_history.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <div class="action-title">Sejarah</div>
                    <div class="action-desc">Rekod kehadiran</div>
                </a>
            </div>
        </div>
        
        <!-- TODAY'S ACTIVITIES -->
        <div class="activities-section">
            <h2 class="section-title">
                <i class="fas fa-calendar-day"></i> Aktiviti Hari Ini
            </h2>
            
            <div class="activities-list">
                <?php if ($todayActivities->num_rows > 0): ?>
                    <?php while($activity = $todayActivities->fetch_assoc()): ?>
                        <div class="activity-item">
                            <div class="activity-header">
                                <div class="activity-title"><?php echo safeHtmlSpecialChars($activity['training_type']); ?></div>
                                <span class="activity-time">
                                    <?php echo $sessionTimeLabels[$activity['session_time']] ?? $activity['session_time']; ?>
                                </span>
                            </div>
                            
                            <div class="activity-details">
                                <div>
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo safeHtmlSpecialChars($activity['location'] ?? ''); ?>
                                </div>
                                <div>
                                    <i class="fas fa-clock"></i>
                                    Masa: 
                                    <?php
                                    $sessionTimes = [
                                        'pagi' => '06:00 - 10:00',
                                        'tengah hari' => '10:00 - 14:00',
                                        'petang' => '14:00 - 18:00',
                                        'malam' => '18:00 - 22:00'
                                    ];
                                    echo $sessionTimes[$activity['session_time']] ?? '-';
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="no-activity">
                        <i class="fas fa-calendar-check"></i>
                        <p>Tiada aktiviti untuk hari ini</p>
                        <small style="display: block; margin-top: 5px;">Rehat dan persiapan untuk aktiviti seterusnya</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- PENDING LEAVES -->
        <?php if ($pendingLeaves > 0): ?>
        <div class="pending-section">
            <div class="pending-card">
                <div class="pending-header">
                    <div class="pending-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <div class="pending-count"><?php echo $pendingLeaves; ?></div>
                        <div style="font-size: 0.9rem; color: var(--warning); font-weight: 600;">
                            Permohonan Pelepasan Menunggu
                        </div>
                    </div>
                </div>
                <p style="font-size: 0.9rem; color: #718096;">
                    Anda mempunyai <?php echo $pendingLeaves; ?> permohonan pelepasan yang sedang menunggu kelulusan rankholder.
                </p>
                <a href="leave_status.php" style="display: inline-block; margin-top: 10px; color: var(--accent); font-weight: 600; text-decoration: none;">
                    <i class="fas fa-external-link-alt"></i> Lihat Status
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- FOOTER NAV -->
        <nav class="mobile-footer">
            <a href="dashboard.php" class="nav-item active">
                <div class="nav-icon">
                    <i class="fas fa-home"></i>
                </div>
                <div class="nav-label">Utama</div>
            </a>
            
            <a href="performance.php" class="nav-item">
                <div class="nav-icon">
                    <i class="fas fa-chart-bar"></i>
                </div>
                <div class="nav-label">Prestasi</div>
            </a>
            
            <a href="attendance_history.php" class="nav-item">
                <div class="nav-icon">
                    <i class="fas fa-history"></i>
                </div>
                <div class="nav-label">Kehadiran</div>
            </a>
            
            <a href="allowance.php" class="nav-item">
                <div class="nav-icon">
                    <i class="fas fa-money-bill"></i>
                </div>
                <div class="nav-label">Elaun</div>
            </a>
            
            <a href="profile.php" class="nav-item">
                <div class="nav-icon">
                    <i class="fas fa-user"></i>
                </div>
                <div class="nav-label">Profil</div>
            </a>
        </nav>
    </div>
    
    <!-- JAVASCRIPT -->
    <script>
        // Logout function
        function logout() {
            if (confirm('Logout dari sistem?')) {
                window.location.href = '../auth/logout.php';
            }
        }
        
        // Refresh dashboard every 60 seconds
        setInterval(() => {
            if (!document.hidden) {
                window.location.reload();
            }
        }, 60000);
        
        // Pull-to-refresh
        let startY = 0;
        
        document.addEventListener('touchstart', function(e) {
            startY = e.touches[0].pageY;
        });
        
        document.addEventListener('touchmove', function(e) {
            const touchY = e.touches[0].pageY;
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            
            if (scrollTop === 0 && touchY > startY + 100) {
                e.preventDefault();
                showRefreshAnimation();
            }
        });
        
        function showRefreshAnimation() {
            const refreshDiv = document.createElement('div');
            refreshDiv.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                background: var(--accent);
                color: white;
                padding: 15px;
                text-align: center;
                z-index: 1000;
                animation: slideDown 0.3s ease-out;
            `;
            refreshDiv.innerHTML = `
                <i class="fas fa-redo fa-spin"></i> Menyegarkan...
            `;
            document.body.appendChild(refreshDiv);
            
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }
        
        // Toast notification
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                left: 16px;
                right: 16px;
                padding: 15px;
                background: ${type === 'success' ? '#48bb78' : '#f56565'};
                color: white;
                border-radius: 10px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
                z-index: 1000;
                text-align: center;
                animation: slideDown 0.3s ease-out;
            `;
            
            toast.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i>
                ${message}
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideUp 0.3s ease-out';
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 3000);
        }
        
        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideDown {
                from { transform: translateY(-100%); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
            @keyframes slideUp {
                from { transform: translateY(0); opacity: 1; }
                to { transform: translateY(-100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
        
        // Vibration on tap (if supported)
        document.addEventListener('DOMContentLoaded', () => {
            const cards = document.querySelectorAll('.action-card, .stat-card');
            cards.forEach(card => {
                card.addEventListener('touchstart', () => {
                    if ('vibrate' in navigator) {
                        navigator.vibrate(10);
                    }
                });
            });
            
            // Keep screen awake
            if ('wakeLock' in navigator) {
                navigator.wakeLock.request('screen');
            }
        });
    </script>
</body>
</html>
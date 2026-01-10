<?php
// rankholder/dashboard.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

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
    if (!$user || $user['role'] !== 'rankholder') {
        header("Location: ../index.php");
        exit();
    }
    
    $rankholder_id = $user['user_id'];
    $service_type = $user['service_type'] ?? null;
    $today = date('Y-m-d');
    $user_name = $user['name'] ?? 'Rankholder';
    
    // 1. STATISTIK KEHADIRAN HARI INI
    $todayStatsQuery = "SELECT 
                        COUNT(*) as total_today,
                        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_today,
                        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_today,
                        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_today,
                        SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) as excused_today
                    FROM attendance a
                    JOIN users u ON a.user_id = u.user_id
                    WHERE a.checked_by = ?
                    AND u.service_type = ?
                    AND DATE(a.date) = ?";
    
    $todayStmt = $db->prepare($todayStatsQuery);
    $todayStmt->bind_param("iss", $rankholder_id, $service_type, $today);
    $todayStmt->execute();
    $todayResult = $todayStmt->get_result();
    $todayStats = $todayResult->fetch_assoc();
    
    // Jika tidak ada data hari ini, set default values
    if (!$todayStats || $todayStats['total_today'] === null) {
        $todayStats = [
            'total_today' => 0,
            'present_today' => 0,
            'absent_today' => 0,
            'late_today' => 0,
            'excused_today' => 0
        ];
    }
    
    // 2. PURATA KEHADIRAN 7 HARI TERAKHIR
    $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
    $avgStatsQuery = "SELECT 
                        COUNT(DISTINCT DATE(a.date)) as days_count,
                        COUNT(*) as total_records,
                        ROUND(AVG(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100, 1) as avg_present_percent
                    FROM attendance a
                    JOIN users u ON a.user_id = u.user_id
                    WHERE a.checked_by = ?
                    AND u.service_type = ?
                    AND a.date BETWEEN ? AND ?";
    
    $avgStmt = $db->prepare($avgStatsQuery);
    $avgStmt->bind_param("isss", $rankholder_id, $service_type, $sevenDaysAgo, $today);
    $avgStmt->execute();
    $avgResult = $avgStmt->get_result();
    $avgStats = $avgResult->fetch_assoc();
    
    if (!$avgStats || $avgStats['days_count'] == 0) {
        $avgStats = [
            'days_count' => 0,
            'total_records' => 0,
            'avg_present_percent' => 0
        ];
    }
    
    // 3. PERMOHONAN PELEPASAN - GANTI DENGAN QUERY YANG AMAN
    $leavesStats = [
        'total_leaves' => 0,
        'pending_leaves' => 0,
        'approved_leaves' => 0,
        'rejected_leaves' => 0
    ];
    
    try {
        // Coba check jika table 'leave_requests' wujud
        $checkTable = $db->query("SHOW TABLES LIKE 'leave_requests'");
        if ($checkTable && $checkTable->num_rows > 0) {
            // Gunakan table leave_requests
            $leavesQuery = "SELECT 
                                COUNT(*) as total_leaves,
                                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_leaves,
                                COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_leaves,
                                COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_leaves
                            FROM leave_requests
                            WHERE service_type = ?
                            AND checked_by = ?
                            AND DATE(leave_date) >= ?";
            
            $leavesStmt = $db->prepare($leavesQuery);
            $leavesStmt->bind_param("sis", $service_type, $rankholder_id, $today);
            $leavesStmt->execute();
            $leavesResult = $leavesStmt->get_result();
            $tempStats = $leavesResult->fetch_assoc();
            if ($tempStats) {
                $leavesStats = $tempStats;
            }
        } 
        // Jika tidak, coba table 'excuses'
        else {
            $checkTable2 = $db->query("SHOW TABLES LIKE 'excuses'");
            if ($checkTable2 && $checkTable2->num_rows > 0) {
                $leavesQuery = "SELECT 
                                    COUNT(*) as total_leaves,
                                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_leaves,
                                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_leaves,
                                    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_leaves
                                FROM excuses
                                WHERE service_type = ?
                                AND checked_by = ?
                                AND DATE(excuse_date) >= ?";
                
                $leavesStmt = $db->prepare($leavesQuery);
                $leavesStmt->bind_param("sis", $service_type, $rankholder_id, $today);
                $leavesStmt->execute();
                $leavesResult = $leavesStmt->get_result();
                $tempStats = $leavesResult->fetch_assoc();
                if ($tempStats) {
                    $leavesStats = $tempStats;
                }
            }
        }
    } catch (Exception $e) {
        // Jika ada error, teruskan dengan default values
        error_log("Leaves query error: " . $e->getMessage());
    }
    
    // 4. STATISTIK MENGIKUT SERVICE TYPE (untuk rankholder ini saja)
    $serviceStatsQuery = "SELECT 
                            u.service_type,
                            COUNT(*) as total,
                            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
                            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
                            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late,
                            SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) as excused
                        FROM attendance a
                        JOIN users u ON a.user_id = u.user_id
                        WHERE a.checked_by = ?
                        AND DATE(a.date) = ?
                        GROUP BY u.service_type
                        ORDER BY u.service_type";
    
    $serviceStmt = $db->prepare($serviceStatsQuery);
    $serviceStmt->bind_param("is", $rankholder_id, $today);
    $serviceStmt->execute();
    $serviceResult = $serviceStmt->get_result();
    
    // 5. STATISTIK MENGIKUT PANGKAT
    $rankStatsQuery = "SELECT 
                        u.rank_level,
                        COUNT(*) as total,
                        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
                        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
                        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late,
                        SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) as excused
                    FROM attendance a
                    JOIN users u ON a.user_id = u.user_id
                    WHERE a.checked_by = ?
                    AND u.service_type = ?
                    AND DATE(a.date) = ?
                    GROUP BY u.rank_level
                    ORDER BY 
                        CASE u.rank_level
                            WHEN 'officer' THEN 1
                            WHEN 'senior' THEN 2
                            WHEN 'junior' THEN 3
                            ELSE 4
                        END";
    
    $rankStmt = $db->prepare($rankStatsQuery);
    $rankStmt->bind_param("iss", $rankholder_id, $service_type, $today);
    $rankStmt->execute();
    $rankResult = $rankStmt->get_result();
    
    // 6. KEHA DIRAN TERKINI (5 rekod terbaru)
    $recentQuery = "SELECT 
                        a.attendance_id,
                        a.date,
                        a.status,
                        a.recorded_at,
                        u.name as cadet_name,
                        u.military_number,
                        u.rank_level,
                        u.service_type,
                        ts.training_type
                    FROM attendance a
                    JOIN users u ON a.user_id = u.user_id
                    JOIN training_sessions ts ON a.session_id = ts.session_id
                    WHERE a.checked_by = ?
                    AND u.service_type = ?
                    ORDER BY a.recorded_at DESC
                    LIMIT 5";
    
    $recentStmt = $db->prepare($recentQuery);
    $recentStmt->bind_param("is", $rankholder_id, $service_type);
    $recentStmt->execute();
    $recentResult = $recentStmt->get_result();
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --light: #f7fafc;
            --success: #48bb78;
            --warning: #ed8936;
            --danger: #f56565;
            --purple: #9f7aea;
            --blue: #4299e1;
            --gray: #718096;
            --teal: #38b2ac;
            --pink: #ed64a6;
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
            padding-bottom: 60px;
        }
        
        .container {
            max-width: 100%;
            padding: 15px;
        }
        
        /* HEADER */
        .header {
            background: var(--primary);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        
        .header h1 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
        }
        
        .user-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }
        
        .user-details {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .user-text h3 {
            font-size: 1rem;
            margin-bottom: 2px;
        }
        
        .user-text p {
            font-size: 0.8rem;
            opacity: 0.9;
        }
        
        .logout-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            cursor: pointer;
        }
        
        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        /* WELCOME CARD */
        .welcome-card {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .welcome-card h2 {
            font-size: 1.2rem;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .welcome-card p {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        /* STATS GRID - 3 KOLOM SAHAJA */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(1, 1fr);
            }
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-card.today {
            border-left: 4px solid var(--accent);
        }
        
        .stat-card.avg {
            border-left: 4px solid var(--success);
        }
        
        .stat-card.leaves {
            border-left: 4px solid var(--warning);
        }
        
        .stat-icon {
            font-size: 1.5rem;
            margin-bottom: 10px;
            opacity: 0.8;
        }
        
        .today .stat-icon { color: var(--accent); }
        .avg .stat-icon { color: var(--success); }
        .leaves .stat-icon { color: var(--warning); }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 5px 0;
            line-height: 1;
        }
        
        .today .stat-number { color: var(--accent); }
        .avg .stat-number { color: var(--success); }
        .leaves .stat-number { color: var(--warning); }
        
        .stat-label {
            color: var(--secondary);
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .stat-subtext {
            color: var(--gray);
            font-size: 0.75rem;
            margin-top: 5px;
        }
        
        /* DETAILED STATS - MOBILE FRIENDLY */
        .detailed-stats {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .section-title {
            color: var(--primary);
            font-size: 1.1rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .stats-container {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .stats-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
        }
        
        .stats-section h4 {
            color: var(--secondary);
            font-size: 0.9rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* MOBILE-FRIENDLY STATS CARDS */
        .stats-cards {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .stats-card {
            background: white;
            border-radius: 8px;
            padding: 12px;
            border-left: 4px solid var(--accent);
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .stats-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .stats-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--primary);
        }
        
        .service-badge, .rank-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-army { background: rgba(66, 153, 225, 0.1); color: var(--blue); }
        .badge-navy { background: rgba(56, 178, 172, 0.1); color: var(--teal); }
        .badge-airforce { background: rgba(237, 100, 166, 0.1); color: var(--pink); }
        .badge-other { background: rgba(159, 122, 234, 0.1); color: var(--purple); }
        
        .badge-officer { background: rgba(72, 187, 120, 0.1); color: var(--success); }
        .badge-senior { background: rgba(237, 137, 54, 0.1); color: var(--warning); }
        .badge-junior { background: rgba(159, 122, 234, 0.1); color: var(--purple); }
        .badge-other-rank { background: rgba(102, 126, 234, 0.1); color: #667eea; }
        
        .stats-numbers {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            text-align: center;
        }
        
        @media (max-width: 480px) {
            .stats-numbers {
                grid-template-columns: repeat(3, 1fr);
                gap: 6px;
            }
        }
        
        .stat-item {
            padding: 8px 5px;
            border-radius: 6px;
        }
        
        .stat-total { background: rgba(49, 130, 206, 0.05); }
        .stat-present { background: rgba(72, 187, 120, 0.05); }
        .stat-absent { background: rgba(245, 101, 101, 0.05); }
        .stat-late { background: rgba(237, 137, 54, 0.05); }
        .stat-excused { background: rgba(159, 122, 234, 0.05); }
        
        .stat-value {
            font-size: 1.2rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 3px;
        }
        
        .stat-total .stat-value { color: var(--accent); }
        .stat-present .stat-value { color: var(--success); }
        .stat-absent .stat-value { color: var(--danger); }
        .stat-late .stat-value { color: var(--warning); }
        .stat-excused .stat-value { color: var(--purple); }
        
        .stat-label-small {
            font-size: 0.7rem;
            color: var(--gray);
            font-weight: 600;
        }
        
        /* TABLET VIEW */
        @media (min-width: 768px) {
            .stats-container {
                flex-direction: row;
            }
            
            .stats-section {
                flex: 1;
            }
            
            .stats-numbers {
                grid-template-columns: repeat(5, 1fr);
            }
        }
        
        /* QUICK ACTIONS */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }
        
        @media (min-width: 768px) {
            .quick-actions {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        .action-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            text-decoration: none;
            color: var(--primary);
            transition: all 0.3s ease;
        }
        
        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        
        .action-icon {
            font-size: 1.8rem;
            margin-bottom: 10px;
            color: var(--accent);
        }
        
        .action-label {
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        /* RECENT ATTENDANCE */
        .recent-attendance {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .attendance-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .attendance-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .attendance-item:last-child {
            border-bottom: none;
        }
        
        .cadet-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .cadet-avatar {
            width: 35px;
            height: 35px;
            background: var(--accent);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .cadet-details h4 {
            color: var(--primary);
            margin-bottom: 2px;
            font-size: 0.95rem;
        }
        
        .cadet-details p {
            color: #718096;
            font-size: 0.8rem;
        }
        
        .attendance-status {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-present { background: rgba(72, 187, 120, 0.1); color: var(--success); }
        .status-absent { background: rgba(245, 101, 101, 0.1); color: var(--danger); }
        .status-late { background: rgba(237, 137, 54, 0.1); color: var(--warning); }
        .status-excused { background: rgba(159, 122, 234, 0.1); color: var(--purple); }
        
        .attendance-time {
            color: var(--gray);
            font-size: 0.75rem;
            text-align: right;
        }
        
        /* MOBILE NAV */
        .mobile-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--primary);
            display: flex;
            justify-content: space-around;
            padding: 10px;
            z-index: 1000;
        }
        
        .mobile-nav-item {
            color: white;
            text-decoration: none;
            text-align: center;
            flex: 1;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .mobile-nav-item.active {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }
        
        .mobile-nav-item.active .mobile-nav-icon {
            color: #fff;
        }
        
        .mobile-nav-item.active .mobile-nav-label {
            color: #fff;
            font-weight: 600;
        }
        
        .mobile-nav-icon {
            font-size: 1.2rem;
            margin-bottom: 3px;
            color: rgba(255, 255, 255, 0.8);
            transition: color 0.3s ease;
        }
        
        .mobile-nav-label {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.8);
            transition: color 0.3s ease;
        }
        
        /* NO DATA */
        .no-data {
            text-align: center;
            padding: 20px;
            color: #718096;
        }
        
        .no-data i {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.3;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <h1>
                <i class="fas fa-tachometer-alt"></i>
                Dashboard Rankholder
            </h1>
            <div class="user-info">
                <div class="user-details">
                </div>
                <button class="logout-btn" onclick="logout()">
                    <i class="fas fa-sign-out-alt"></i> Log Keluar
                </button>
            </div>
        </div>
    
        
        <!-- STATS GRID (3 KAD SAHAJA) -->
        <div class="stats-grid">
            <!-- Kehadiran Hari Ini -->
            <div class="stat-card today">
                <div class="stat-icon">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-number"><?php echo $todayStats['total_today']; ?></div>
                <div class="stat-label">Kehadiran Hari Ini</div>
                <div class="stat-subtext"><?php echo date('d/m/Y'); ?></div>
            </div>
            
            <!-- Purata Kehadiran -->
            <div class="stat-card avg">
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-number"><?php echo $avgStats['avg_present_percent']; ?>%</div>
                <div class="stat-label">Purata Hadir</div>
                <div class="stat-subtext">7 hari terakhir</div>
            </div>
            
            <!-- Permohonan Pelepasan -->
            <div class="stat-card leaves">
                <div class="stat-icon">
                    <i class="fas fa-file-import"></i>
                </div>
                <div class="stat-number"><?php echo $leavesStats['pending_leaves']; ?></div>
                <div class="stat-label">Pelepasan Belum Sah</div>
                <div class="stat-subtext">Daripada <?php echo $leavesStats['total_leaves']; ?> permohonan</div>
            </div>
            <!-- BUANG KAD LOG KELUAR -->
        </div>
        
        <!-- DETAILED STATS - MOBILE FRIENDLY -->
        <div class="detailed-stats">
            <h3 class="section-title">
                <i class="fas fa-chart-pie"></i>
                Statistik Terperinci - Hari Ini
            </h3>
            
            <div class="stats-container">
                <!-- STATISTIK MENGIKUT SERVICE -->
                <div class="stats-section">
                    <h4><i class="fas fa-building"></i> Mengikut Servis</h4>
                    
                    <?php if ($serviceResult->num_rows > 0): ?>
                    <div class="stats-cards">
                        <?php while($service = $serviceResult->fetch_assoc()): 
                            $serviceClass = 'badge-' . strtolower($service['service_type']);
                        ?>
                        <div class="stats-card">
                            <div class="stats-card-header">
                                <div class="stats-title">
                                    <span class="service-badge <?php echo $serviceClass; ?>">
                                        <?php echo strtoupper($service['service_type']); ?>
                                    </span>
                                </div>
                                <div style="font-size: 0.8rem; color: var(--gray);">
                                    ID: <?php echo substr(strtoupper($service['service_type']), 0, 3); ?>
                                </div>
                            </div>
                            <div class="stats-numbers">
                                <div class="stat-item stat-total">
                                    <div class="stat-value"><?php echo $service['total']; ?></div>
                                    <div class="stat-label-small">TOTAL</div>
                                </div>
                                <div class="stat-item stat-present">
                                    <div class="stat-value"><?php echo $service['present']; ?></div>
                                    <div class="stat-label-small">HADIR</div>
                                </div>
                                <div class="stat-item stat-absent">
                                    <div class="stat-value"><?php echo $service['absent']; ?></div>
                                    <div class="stat-label-small">TIDAK</div>
                                </div>
                                <div class="stat-item stat-late">
                                    <div class="stat-value"><?php echo $service['late']; ?></div>
                                    <div class="stat-label-small">LEWAT</div>
                                </div>
                                <div class="stat-item stat-excused">
                                    <div class="stat-value"><?php echo $service['excused']; ?></div>
                                    <div class="stat-label-small">CUTI</div>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php else: ?>
                    <div class="no-data" style="padding: 10px;">
                        <i class="fas fa-building"></i>
                        <p>Tiada data servis untuk hari ini</p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- STATISTIK MENGIKUT PANGKAT -->
                <div class="stats-section">
                    <h4><i class="fas fa-ranking-star"></i> Mengikut Pangkat</h4>
                    
                    <?php if ($rankResult->num_rows > 0): ?>
                    <div class="stats-cards">
                        <?php while($rank = $rankResult->fetch_assoc()): 
                            $rankClass = 'badge-' . strtolower($rank['rank_level']);
                            $rankName = ucfirst($rank['rank_level']);
                        ?>
                        <div class="stats-card">
                            <div class="stats-card-header">
                                <div class="stats-title">
                                    <span class="rank-badge <?php echo $rankClass; ?>">
                                        <?php echo $rankName; ?>
                                    </span>
                                </div>
                                <div style="font-size: 0.8rem; color: var(--gray);">
                                    Lvl: <?php echo substr(strtoupper($rank['rank_level']), 0, 1); ?>
                                </div>
                            </div>
                            <div class="stats-numbers">
                                <div class="stat-item stat-total">
                                    <div class="stat-value"><?php echo $rank['total']; ?></div>
                                    <div class="stat-label-small">TOTAL</div>
                                </div>
                                <div class="stat-item stat-present">
                                    <div class="stat-value"><?php echo $rank['present']; ?></div>
                                    <div class="stat-label-small">HADIR</div>
                                </div>
                                <div class="stat-item stat-absent">
                                    <div class="stat-value"><?php echo $rank['absent']; ?></div>
                                    <div class="stat-label-small">TIDAK</div>
                                </div>
                                <div class="stat-item stat-late">
                                    <div class="stat-value"><?php echo $rank['late']; ?></div>
                                    <div class="stat-label-small">LEWAT</div>
                                </div>
                                <div class="stat-item stat-excused">
                                    <div class="stat-value"><?php echo $rank['excused']; ?></div>
                                    <div class="stat-label-small">CUTI</div>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php else: ?>
                    <div class="no-data" style="padding: 10px;">
                        <i class="fas fa-ranking-star"></i>
                        <p>Tiada data pangkat untuk hari ini</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        
        <!-- RECENT ATTENDANCE -->
        <div class="recent-attendance">
            <h3 class="section-title">
                <i class="fas fa-history"></i>
                Kehadiran Terkini
            </h3>
            
            <?php if ($recentResult->num_rows > 0): ?>
            <div class="attendance-list">
                <?php while($row = $recentResult->fetch_assoc()): 
                    $statusClass = 'status-' . $row['status'];
                    $rankClass = 'badge-' . strtolower($row['rank_level']);
                ?>
                <div class="attendance-item">
                    <div class="cadet-info">
                        <div class="cadet-avatar">
                            <?php echo strtoupper(substr($row['cadet_name'], 0, 1)); ?>
                        </div>
                        <div class="cadet-details">
                            <h4><?php echo htmlspecialchars($row['cadet_name']); ?></h4>
                            <p>
                                <span class="rank-badge <?php echo $rankClass; ?>" style="font-size: 0.7rem;">
                                    <?php echo ucfirst($row['rank_level']); ?>
                                </span>
                                • <?php echo $row['military_number']; ?>
                            </p>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div class="attendance-status <?php echo $statusClass; ?>">
                            <?php echo strtoupper($row['status']); ?>
                        </div>
                        <div class="attendance-time">
                            <?php echo date('h:i A', strtotime($row['recorded_at'])); ?>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-history"></i>
                <p>Tiada rekod kehadiran terkini</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- MOBILE NAVIGATION -->
    <nav class="mobile-nav">
        <a href="dashboard.php" class="mobile-nav-item active">
            <div class="mobile-nav-icon">
                <i class="fas fa-home"></i>
            </div>
            <div class="mobile-nav-label">Dashboard</div>
        </a>
        
        <a href="take_attendance.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-qrcode"></i>
            </div>
            <div class="mobile-nav-label">Ambil</div>
        </a>
        
        <a href="view_attendance.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-list"></i>
            </div>
            <div class="mobile-nav-label">Lihat</div>
        </a>
        
        <a href="manage_leaves.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-file-upload"></i>
            </div>
            <div class="mobile-nav-label">Pelepasan</div>
        </a>
    </nav>
    
    <script>
        function logout() {
            if (confirm('Adakah anda pasti ingin log keluar?')) {
                window.location.href = '../logout.php';
            }
        }
        
        // Refresh dashboard setiap 60 saat
        setInterval(() => {
            window.location.reload();
        }, 60000);
        
        // Add ripple effect to mobile nav items
        document.addEventListener('DOMContentLoaded', function() {
            const navItems = document.querySelectorAll('.mobile-nav-item');
            navItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    // Remove active class from all items
                    navItems.forEach(navItem => {
                        navItem.classList.remove('active');
                    });
                    // Add active class to clicked item
                    this.classList.add('active');
                });
            });
        });
    </script>
</body>
</html>
<?php
// cadet/dashboard.php - ENGLISH VERSION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

// Check permission - MUST BE cadet
RBAC::checkPermission('cadet');

try {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    $db = new Database();
    
    // Check if user is logged in
    if (!$user || $user['role'] !== 'cadet') {
        header("Location: ../index.php");
        exit();
    }
    
    $cadet_id = $user['user_id'];
    $service_type = $user['service_type'] ?? null;
    $rank_level = $user['rank_level'] ?? null;
    $today = date('Y-m-d');
    $current_month = date('Y-m');
    $current_year = date('Y');
    $user_name = $user['name'] ?? 'Cadet';
    
    // Get attendance period from URL or default to 'week'
    $period = $_GET['period'] ?? 'week';
    
    // Set date range based on period
    switch($period) {
        case 'month':
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
            $period_label = 'This Month';
            break;
        case 'year':
            $start_date = date('Y-01-01');
            $end_date = date('Y-12-31');
            $period_label = 'This Year';
            break;
        case 'week':
        default:
            $start_date = date('Y-m-d', strtotime('-6 days'));
            $end_date = date('Y-m-d');
            $period_label = 'This Week';
            break;
    }
    
    // 1. ATTENDANCE STATISTICS BASED ON PERIOD
    $periodStatsQuery = "SELECT 
                        COUNT(*) as total_period,
                        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_period,
                        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_period,
                        SUM(CASE WHEN is_excuse = 1 THEN 1 ELSE 0 END) as excuse_period,
                        CASE 
                            WHEN COUNT(*) > 0 THEN 
                                ROUND((SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1)
                            ELSE 0 
                        END as attendance_rate_period
                    FROM attendance 
                    WHERE user_id = ?
                    AND date BETWEEN ? AND ?";
    
    $periodStmt = $db->prepare($periodStatsQuery);
    $periodStmt->bind_param("iss", $cadet_id, $start_date, $end_date);
    $periodStmt->execute();
    $periodResult = $periodStmt->get_result();
    $periodStats = $periodResult->fetch_assoc();
    
    // If no data for this period, set default values
    if (!$periodStats || $periodStats['total_period'] === null) {
        $periodStats = [
            'total_period' => 0,
            'present_period' => 0,
            'absent_period' => 0,
            'excuse_period' => 0,
            'attendance_rate_period' => 0
        ];
    }
    
    // 2. OVERALL ATTENDANCE PERCENTAGE
    $overallRateQuery = "SELECT 
                            COUNT(*) as total_all,
                            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_all,
                            CASE 
                                WHEN COUNT(*) > 0 THEN 
                                    ROUND((SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1)
                                ELSE 0 
                            END as attendance_rate
                        FROM attendance 
                        WHERE user_id = ?";
    
    $rateStmt = $db->prepare($overallRateQuery);
    $rateStmt->bind_param("i", $cadet_id);
    $rateStmt->execute();
    $rateResult = $rateStmt->get_result();
    $rateStats = $rateResult->fetch_assoc();
    
    if (!$rateStats) {
        $rateStats = [
            'total_all' => 0,
            'present_all' => 0,
            'attendance_rate' => 0
        ];
    }
    
    // 3. LATEST ALLOWANCE DATA
    $allowanceQuery = "SELECT 
                        month_year,
                        total_amount,
                        calculated_at
                    FROM allowance_calculations 
                    WHERE user_id = ?
                    ORDER BY calculated_at DESC
                    LIMIT 1";
    
    $allowanceStmt = $db->prepare($allowanceQuery);
    $allowanceStmt->bind_param("i", $cadet_id);
    $allowanceStmt->execute();
    $allowanceResult = $allowanceStmt->get_result();
    $allowanceData = $allowanceResult->fetch_assoc();
    
    // 4. PERFORMANCE DATA
    $performanceQuery = "SELECT 
                            performance_grade,
                            attendance_score,
                            discipline_score,
                            skill_score
                        FROM users 
                        WHERE user_id = ?";
    
    $performanceStmt = $db->prepare($performanceQuery);
    $performanceStmt->bind_param("i", $cadet_id);
    $performanceStmt->execute();
    $performanceResult = $performanceStmt->get_result();
    $performanceData = $performanceResult->fetch_assoc();
    
    // 5. DETAILED ATTENDANCE BASED ON PERIOD
    $attendanceDetailQuery = "SELECT 
                                a.date,
                                a.status,
                                a.reason,
                                a.is_excuse,
                                ts.training_type,
                                ts.location,
                                ts.session_time,
                                a.recorded_at
                            FROM attendance a
                            JOIN training_sessions ts ON a.session_id = ts.session_id
                            WHERE a.user_id = ?
                            AND a.date BETWEEN ? AND ?
                            ORDER BY a.date DESC
                            LIMIT 10";
    
    $detailStmt = $db->prepare($attendanceDetailQuery);
    $detailStmt->bind_param("iss", $cadet_id, $start_date, $end_date);
    $detailStmt->execute();
    $detailResult = $detailStmt->get_result();
    
    // 6. LAST 7 DAYS ATTENDANCE FOR CALENDAR
    $last7DaysQuery = "SELECT 
                        DATE(date) as attendance_date,
                        status,
                        is_excuse
                    FROM attendance 
                    WHERE user_id = ?
                    AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    ORDER BY date DESC";
    
    $last7DaysStmt = $db->prepare($last7DaysQuery);
    $last7DaysStmt->bind_param("i", $cadet_id);
    $last7DaysStmt->execute();
    $last7DaysResult = $last7DaysStmt->get_result();
    
    // 7. CADET INFO
    $cadetInfo = [
        'name' => $user['name'],
        'military_number' => $user['military_number'],
        'service_type' => $user['service_type'],
        'rank_level' => $user['rank_level'],
        'join_date' => $user['join_date'],
        'email' => $user['email'],
        'phone' => $user['phone']
    ];
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Helper functions
function getServiceLabel($type) {
    $labels = [
        'darat' => 'Land',
        'laut' => 'Sea', 
        'udara' => 'Air'
    ];
    return $labels[$type] ?? $type;
}

function getRankLabel($rank) {
    $labels = [
        'junior' => 'Junior',
        'intermediate' => 'Intermediate',
        'senior' => 'Senior'
    ];
    return $labels[$rank] ?? $rank;
}

function getStatusBadge($status, $is_excuse = null) {
    if ($status === 'present') {
        return '<span class="status-badge present">Present</span>';
    } elseif ($status === 'absent') {
        if ($is_excuse == 1) {
            return '<span class="status-badge excuse">Excused</span>';
        } else {
            return '<span class="status-badge absent">Absent</span>';
        }
    }
    return '<span class="status-badge unknown">No Data</span>';
}

function getPerformanceColor($grade) {
    switch($grade) {
        case 'A+': case 'A': return '#48bb78';
        case 'B+': case 'B': return '#4299e1';
        case 'C+': case 'C': return '#ed8936';
        case 'D': case 'E': return '#f56565';
        default: return '#a0aec0';
    }
}

function formatDate($dateString) {
    if (empty($dateString)) return '';
    try {
        $date = strtotime($dateString);
        return $date ? date('d/m/Y', $date) : '';
    } catch (Exception $e) {
        return '';
    }
}

function formatTime($timeString) {
    if (empty($timeString)) return '';
    try {
        $time = strtotime($timeString);
        return $time ? date('h:i A', $time) : '';
    } catch (Exception $e) {
        return '';
    }
}

function getSessionTimeLabel($time) {
    $labels = [
        'pagi' => 'Morning',
        'tengah hari' => 'Afternoon',
        'petang' => 'Evening',
        'malam' => 'Night'
    ];
    return $labels[$time] ?? ucfirst($time);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadet Dashboard - CAAMS</title>
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
            --excuse: #68d391;
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
            background: linear-gradient(135deg, var(--primary) 0%, #2c5282 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        
        .header h1 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
            position: relative;
            z-index: 1;
        }
        
        .user-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            position: relative;
            z-index: 1;
        }
        
        .user-details {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--accent) 0%, #2c5282 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            border: 3px solid rgba(255, 255, 255, 0.3);
        }
        
        .user-text h3 {
            font-size: 1.1rem;
            margin-bottom: 2px;
        }
        
        .user-text p {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        
        .user-badges {
            display: flex;
            gap: 5px;
            margin-top: 5px;
        }
        
        .service-badge {
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.2);
        }
        
        .rank-badge {
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.2);
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
            transition: all 0.3s ease;
        }
        
        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }
        
        /* PERIOD FILTER */
        .period-filter {
            background: white;
            border-radius: 10px;
            padding: 10px 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .filter-label {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .filter-options {
            display: flex;
            gap: 5px;
        }
        
        .filter-btn {
            padding: 6px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            background: white;
            color: var(--gray);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
        }
        
        .filter-btn.active {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }
        
        /* PERSONAL INFO & ATTENDANCE SECTION */
        .combined-section {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .section-title {
            color: var(--primary);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* UPDATE PROFILE BUTTON */
        .update-profile-btn {
            background: var(--success);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .update-profile-btn:hover {
            background: #38a169;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(56, 161, 105, 0.3);
        }
        
        /* INFO & STATS GRID */
        .info-stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }
        
        @media (min-width: 768px) {
            .info-stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        /* PERSONAL INFO CARD */
        .personal-info-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            position: relative;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 0.75rem;
            color: var(--gray);
            margin-bottom: 3px;
        }
        
        .info-value {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        /* ATTENDANCE STATS CARD */
        .attendance-stats-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
        }
        
        .period-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 15px;
        }
        
        @media (max-width: 768px) {
            .period-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        .period-stat-item {
            background: white;
            border-radius: 6px;
            padding: 10px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .period-stat-value {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 3px;
        }
        
        .period-stat-label {
            font-size: 0.7rem;
            color: var(--gray);
            font-weight: 600;
        }
        
        .total-stat .period-stat-value { color: var(--accent); }
        .present-stat .period-stat-value { color: var(--success); }
        .absent-stat .period-stat-value { color: var(--danger); }
        .excuse-stat .period-stat-value { color: var(--excuse); }
        
        /* PROGRESS BAR */
        .progress-container {
            margin-top: 10px;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .progress-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .progress-percent {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--accent);
        }
        
        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--accent);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        .progress-text {
            font-size: 0.75rem;
            color: var(--gray);
            text-align: center;
        }
        
        /* STATS GRID */
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
            border-top: 4px solid var(--accent);
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        
        .stat-card.attendance {
            border-top-color: var(--accent);
        }
        
        .stat-card.allowance {
            border-top-color: var(--success);
        }
        
        .stat-card.performance {
            border-top-color: var(--purple);
        }
        
        .stat-icon {
            font-size: 1.5rem;
            margin-bottom: 10px;
            opacity: 0.8;
        }
        
        .attendance .stat-icon { color: var(--accent); }
        .allowance .stat-icon { color: var(--success); }
        .performance .stat-icon { color: var(--purple); }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 5px 0;
            line-height: 1;
        }
        
        .attendance .stat-number { color: var(--accent); }
        .allowance .stat-number { color: var(--success); }
        .performance .stat-number { color: var(--purple); }
        
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
        
        /* DETAILED ATTENDANCE */
        .detailed-attendance {
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
        
        .attendance-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .attendance-icon {
            width: 35px;
            height: 35px;
            background: #f8fafc;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            font-size: 1rem;
        }
        
        .attendance-details h4 {
            color: var(--primary);
            margin-bottom: 2px;
            font-size: 0.95rem;
        }
        
        .attendance-details p {
            color: #718096;
            font-size: 0.8rem;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-right: 5px;
        }
        
        .status-badge.present {
            background: rgba(72, 187, 120, 0.1);
            color: var(--success);
        }
        
        .status-badge.absent {
            background: rgba(245, 101, 101, 0.1);
            color: var(--danger);
        }
        
        .status-badge.excuse {
            background: rgba(104, 211, 145, 0.1);
            color: var(--excuse);
        }
        
        .attendance-time {
            color: var(--gray);
            font-size: 0.75rem;
            text-align: right;
        }
        
        /* ATTENDANCE CALENDAR */
        .attendance-calendar {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            margin-top: 10px;
        }
        
        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            position: relative;
        }
        
        .calendar-day.present {
            background: rgba(72, 187, 120, 0.2);
            color: var(--success);
        }
        
        .calendar-day.absent {
            background: rgba(245, 101, 101, 0.2);
            color: var(--danger);
        }
        
        .calendar-day.excuse {
            background: rgba(104, 211, 145, 0.2);
            color: var(--excuse);
        }
        
        .calendar-day.today {
            border: 2px solid var(--accent);
        }
        
        .day-number {
            font-size: 0.9rem;
        }
        
        .calendar-legend {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.75rem;
            color: var(--gray);
        }
        
        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 3px;
        }
        
        .legend-present { background: rgba(72, 187, 120, 0.2); }
        .legend-absent { background: rgba(245, 101, 101, 0.2); }
        .legend-excuse { background: rgba(104, 211, 145, 0.2); }
        
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
            border-top: 1px solid rgba(255, 255, 255, 0.1);
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
                Cadet Dashboard
            </h1>
            <div class="user-info">
                <div class="user-details">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($cadetInfo['name'], 0, 1)); ?>
                    </div>
                    <div class="user-text">
                        <h3><?php echo htmlspecialchars($cadetInfo['name']); ?></h3>
                        <p><?php echo $cadetInfo['military_number']; ?></p>
                        <div class="user-badges">
                            <span class="service-badge"><?php echo getServiceLabel($cadetInfo['service_type']); ?></span>
                            <span class="rank-badge"><?php echo getRankLabel($cadetInfo['rank_level']); ?></span>
                        </div>
                    </div>
                </div>
                <button class="logout-btn" onclick="logout()">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>
        
        <!-- PERIOD FILTER -->
        <div class="period-filter">
            <div class="filter-label">
                <i class="fas fa-calendar-alt"></i>
                Attendance <?php echo $period_label; ?>
            </div>
            <div class="filter-options">
                <button class="filter-btn <?php echo $period === 'week' ? 'active' : ''; ?>" 
                        onclick="changePeriod('week')">
                    Week
                </button>
                <button class="filter-btn <?php echo $period === 'month' ? 'active' : ''; ?>" 
                        onclick="changePeriod('month')">
                    Month
                </button>
                <button class="filter-btn <?php echo $period === 'year' ? 'active' : ''; ?>" 
                        onclick="changePeriod('year')">
                    Year
                </button>
            </div>
        </div>
        
        <!-- PERSONAL INFO & ATTENDANCE STATS -->
        <div class="combined-section">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-user-circle"></i>
                    Personal Info & Attendance
                </h3>
                <a href="update_profile.php" class="update-profile-btn">
                    <i class="fas fa-user-edit"></i> Update Profile
                </a>
            </div>
            
            <div class="info-stats-grid">
                <!-- PERSONAL INFO -->
                <div class="personal-info-card">
                    <h4 style="color: var(--primary); margin-bottom: 15px; font-size: 0.95rem;">
                        <i class="fas fa-id-card"></i> Personal Information
                    </h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($cadetInfo['name']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Military No.</div>
                            <div class="info-value"><?php echo $cadetInfo['military_number']; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Service</div>
                            <div class="info-value"><?php echo getServiceLabel($cadetInfo['service_type']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Rank</div>
                            <div class="info-value"><?php echo getRankLabel($cadetInfo['rank_level']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Join Date</div>
                            <div class="info-value"><?php echo formatDate($cadetInfo['join_date']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Email</div>
                            <div class="info-value"><?php echo $cadetInfo['email'] ?? 'N/A'; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Phone</div>
                            <div class="info-value"><?php echo $cadetInfo['phone'] ?? 'N/A'; ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- ATTENDANCE STATS -->
                <div class="attendance-stats-card">
                    <h4 style="color: var(--primary); margin-bottom: 15px; font-size: 0.95rem;">
                        <i class="fas fa-chart-bar"></i> Statistics <?php echo $period_label; ?>
                    </h4>
                    
                    <div class="period-stats">
                        <div class="period-stat-item total-stat">
                            <div class="period-stat-value"><?php echo $periodStats['total_period']; ?></div>
                            <div class="period-stat-label">TOTAL</div>
                        </div>
                        <div class="period-stat-item present-stat">
                            <div class="period-stat-value"><?php echo $periodStats['present_period']; ?></div>
                            <div class="period-stat-label">PRESENT</div>
                        </div>
                        <div class="period-stat-item absent-stat">
                            <div class="period-stat-value"><?php echo $periodStats['absent_period']; ?></div>
                            <div class="period-stat-label">ABSENT</div>
                        </div>
                        <div class="period-stat-item excuse-stat">
                            <div class="period-stat-value"><?php echo $periodStats['excuse_period']; ?></div>
                            <div class="period-stat-label">EXCUSED</div>
                        </div>
                    </div>
                    
                    <!-- Progress Bar -->
                    <div class="progress-container">
                        <div class="progress-header">
                            <div class="progress-title">Attendance Rate <?php echo $period_label; ?></div>
                            <div class="progress-percent"><?php echo $periodStats['attendance_rate_period']; ?>%</div>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $periodStats['attendance_rate_period']; ?>%"></div>
                        </div>
                        <div class="progress-text">
                            <?php echo $periodStats['present_period']; ?> out of <?php echo $periodStats['total_period']; ?> sessions
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- STATS CARDS -->
        <div class="stats-grid">
            <div class="stat-card attendance">
                <div class="stat-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-number"><?php echo $rateStats['attendance_rate']; ?>%</div>
                <div class="stat-label">Overall Attendance Rate</div>
                <div class="stat-subtext">
                    <?php echo $rateStats['present_all']; ?> out of <?php echo $rateStats['total_all']; ?> sessions
                </div>
            </div>
            
            <div class="stat-card allowance">
                <div class="stat-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-number">
                    <?php 
                    if ($allowanceData) {
                        echo 'RM ' . number_format($allowanceData['total_amount'], 2);
                    } else {
                        echo 'RM 0.00';
                    }
                    ?>
                </div>
                <div class="stat-label">Total Allowance</div>
                <div class="stat-subtext">
                    <?php 
                    if ($allowanceData) {
                        echo 'Month: ' . date('F Y', strtotime($allowanceData['month_year'] . '-01'));
                    } else {
                        echo 'No allowance data';
                    }
                    ?>
                </div>
            </div>
            
            <div class="stat-card performance">
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-number">
                    <?php echo $performanceData['performance_grade'] ?? 'N/A'; ?>
                </div>
                <div class="stat-label">Performance Grade</div>
                <div class="stat-subtext">
                    Attendance: <?php echo $performanceData['attendance_score'] ?? '0'; ?>% • 
                    Discipline: <?php echo $performanceData['discipline_score'] ?? '0'; ?>%
                </div>
            </div>
        </div>
        
        <!-- DETAILED ATTENDANCE -->
        <div class="detailed-attendance">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-list-check"></i>
                    Attendance Records <?php echo $period_label; ?>
                </h3>
                <span style="font-size: 0.8rem; color: var(--gray);">
                    <?php echo formatDate($start_date); ?> - <?php echo formatDate($end_date); ?>
                </span>
            </div>
            
            <?php if ($detailResult->num_rows > 0): ?>
            <div class="attendance-list">
                <?php while($row = $detailResult->fetch_assoc()): ?>
                <div class="attendance-item">
                    <div class="attendance-info">
                        <div class="attendance-icon">
                            <i class="fas fa-dumbbell"></i>
                        </div>
                        <div class="attendance-details">
                            <h4><?php echo htmlspecialchars($row['training_type']); ?></h4>
                            <p>
                                <?php echo formatDate($row['date']); ?> • 
                                <?php echo htmlspecialchars($row['location']); ?> • 
                                <?php echo getSessionTimeLabel($row['session_time']); ?>
                            </p>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div>
                            <?php echo getStatusBadge($row['status'], $row['is_excuse']); ?>
                        </div>
                        <div class="attendance-time">
                            <?php echo formatTime($row['recorded_at']); ?>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-calendar-times"></i>
                <p>No attendance recorded for <?php echo strtolower($period_label); ?></p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- ATTENDANCE CALENDAR (7 DAYS) -->
        <div class="attendance-calendar">
            <h3 class="section-title">
                <i class="fas fa-calendar-week"></i>
                Last 7 Days
            </h3>
            
            <?php 
            $last7Days = [];
            while($row = $last7DaysResult->fetch_assoc()) {
                $last7Days[$row['attendance_date']] = [
                    'status' => $row['status'],
                    'is_excuse' => $row['is_excuse']
                ];
            }
            
            if (!empty($last7Days)):
            ?>
            <div class="calendar-days">
                <?php 
                // Generate last 7 days
                for ($i = 6; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $dayNum = date('j', strtotime($date));
                    $isToday = $date === $today;
                    
                    $status = $last7Days[$date]['status'] ?? null;
                    $is_excuse = $last7Days[$date]['is_excuse'] ?? null;
                    
                    $dayClass = '';
                    if ($status === 'present') {
                        $dayClass = 'present';
                    } elseif ($status === 'absent') {
                        if ($is_excuse == 1) {
                            $dayClass = 'excuse';
                        } else {
                            $dayClass = 'absent';
                        }
                    }
                    
                    if ($isToday) {
                        $dayClass .= ' today';
                    }
                ?>
                <div class="calendar-day <?php echo $dayClass; ?>">
                    <div class="day-number"><?php echo $dayNum; ?></div>
                </div>
                <?php } ?>
            </div>
            
            <div class="calendar-legend">
                <div class="legend-item">
                    <div class="legend-color legend-present"></div>
                    <span>Present</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color legend-absent"></div>
                    <span>Absent</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color legend-excuse"></div>
                    <span>Excused</span>
                </div>
            </div>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-calendar-alt"></i>
                <p>No attendance recorded in the last 7 days</p>
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
        
        <a href="view_allowance.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-money-bill"></i>
            </div>
            <div class="mobile-nav-label">Allowance</div>
        </a>
        
        <a href="apply_excuse.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-file-medical"></i>
            </div>
            <div class="mobile-nav-label">Excuse</div>
        </a>
        
        <a href="view_performance.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="mobile-nav-label">Performance</div>
        </a>
    </nav>
    
    <script>
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }
        
        function changePeriod(period) {
            window.location.href = 'dashboard.php?period=' + period;
        }
        
        // Refresh dashboard every 60 seconds
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
            
            // Add animation to stats cards on load
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            // Add animation to filter buttons
            const filterBtns = document.querySelectorAll('.filter-btn');
            filterBtns.forEach((btn, index) => {
                setTimeout(() => {
                    btn.style.transform = 'scale(1)';
                    btn.style.opacity = '1';
                }, index * 50 + 300);
            });
        });
        
        // Set initial opacity for animation
        document.querySelectorAll('.stat-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        });
        
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.style.transform = 'scale(0.9)';
            btn.style.opacity = '0';
            btn.style.transition = 'opacity 0.3s ease, transform 0.3s ease, background-color 0.3s ease';
        });
    </script>
</body>
</html>
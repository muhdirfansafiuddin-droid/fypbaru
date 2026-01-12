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
    
    // 1. TODAY'S ATTENDANCE STATISTICS
    $todayStatsQuery = "SELECT 
                        COUNT(*) as total_today,
                        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_today,
                        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_today,
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
    
    // Set default values if no data today
    if (!$todayStats || $todayStats['total_today'] === null) {
        $todayStats = [
            'total_today' => 0,
            'present_today' => 0,
            'absent_today' => 0,
            'excused_today' => 0
        ];
    }
    
    // 2. AVERAGE ATTENDANCE LAST 7 DAYS
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
    
    // 3. LEAVE REQUESTS - WITH SAFE QUERY
    $leavesStats = [
        'total_leaves' => 0,
        'pending_leaves' => 0,
        'approved_leaves' => 0,
        'rejected_leaves' => 0
    ];
    
    try {
        // Try to check if 'leave_requests' table exists
        $checkTable = $db->query("SHOW TABLES LIKE 'leave_requests'");
        if ($checkTable && $checkTable->num_rows > 0) {
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
        // If not, try 'excuses' table
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
        // Continue with default values if error
        error_log("Leaves query error: " . $e->getMessage());
    }
    
    // 4. STATISTICS BY SERVICE TYPE (for this rankholder only)
    $serviceStatsQuery = "SELECT 
                            u.service_type,
                            COUNT(*) as total,
                            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
                            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
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
    
    // 5. STATISTICS BY RANK
    $rankStatsQuery = "SELECT 
                        u.rank_level,
                        COUNT(*) as total,
                        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
                        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
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
    
    // 6. RECENT ATTENDANCE (5 latest records)
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
    
    // 7. FILTERABLE ATTENDANCE SECTION
    // Get filter parameters
    $filter_date = $_GET['date'] ?? date('Y-m-d');
    $filter_service = $_GET['service'] ?? 'all';
    $filter_rank = $_GET['rank'] ?? 'all';
    
    // Build query with filters for attendance list
    $attendanceQuery = "SELECT 
                            a.attendance_id,
                            a.date,
                            a.status,
                            a.recorded_at,
                            u.name as cadet_name,
                            u.military_number,
                            u.rank_level,
                            u.service_type,
                            ts.training_type,
                            ts.location
                        FROM attendance a
                        JOIN users u ON a.user_id = u.user_id
                        JOIN training_sessions ts ON a.session_id = ts.session_id
                        WHERE a.checked_by = ?";
    
    $params = [$rankholder_id];
    $param_types = "i";
    
    // Add service filter
    if ($filter_service !== 'all') {
        $attendanceQuery .= " AND u.service_type = ?";
        $params[] = $filter_service;
        $param_types .= "s";
    }
    
    // Add date filter
    $attendanceQuery .= " AND DATE(a.date) = ?";
    $params[] = $filter_date;
    $param_types .= "s";
    
    // Add rank filter
    if ($filter_rank !== 'all') {
        $attendanceQuery .= " AND u.rank_level = ?";
        $params[] = $filter_rank;
        $param_types .= "s";
    }
    
    $attendanceQuery .= " ORDER BY a.recorded_at DESC LIMIT 20";
    
    // Prepare and execute attendance query
    $attendanceStmt = $db->prepare($attendanceQuery);
    $attendanceStmt->bind_param($param_types, ...$params);
    $attendanceStmt->execute();
    $attendanceResult = $attendanceStmt->get_result();
    
    // Get distinct services for filter dropdown
    $servicesQuery = "SELECT DISTINCT service_type FROM users WHERE service_type IS NOT NULL";
    $servicesResult = $db->query($servicesQuery);
    
    // Get distinct ranks for filter dropdown
    $ranksQuery = "SELECT DISTINCT rank_level FROM users WHERE rank_level IS NOT NULL";
    $ranksResult = $db->query($ranksQuery);
    
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
        
        /* DETAILED STATS */
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
        
        .badge-darat { background: rgba(66, 153, 225, 0.1); color: var(--army); }
        .badge-laut { background: rgba(56, 178, 172, 0.1); color: var(--navy); }
        .badge-udara { background: rgba(237, 100, 166, 0.1); color: var(--airforce); }
        .badge-other { background: rgba(159, 122, 234, 0.1); color: var(--purple); }
        
       
        
        .stats-numbers {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            text-align: center;
        }
        
        @media (max-width: 480px) {
            .stats-numbers {
                grid-template-columns: repeat(2, 1fr);
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
        .stat-excused .stat-value { color: var(--purple); }
        
        .stat-label-small {
            font-size: 0.7rem;
            color: var(--gray);
            font-weight: 600;
        }
        
        /* FILTER SECTION */
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
        }
        
        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
        }
        
        @media (max-width: 480px) {
            .filter-form {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .form-label {
            color: var(--secondary);
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .form-select, .form-input {
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9rem;
            background: white;
        }
        
        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: var(--accent);
            color: white;
        }
        
        .btn-primary:hover {
            background: #2c5282;
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: var(--secondary);
        }
        
        .btn-secondary:hover {
            background: #cbd5e0;
        }
        
        /* ATTENDANCE LIST SECTION */
        .attendance-section {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .stats-summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }
        
        @media (max-width: 768px) {
            .stats-summary {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        .summary-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
        }
        
        .summary-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .summary-label {
            font-size: 0.8rem;
            color: var(--gray);
            font-weight: 600;
        }
        
        .summary-total .summary-value { color: var(--accent); }
        .summary-present .summary-value { color: var(--success); }
        .summary-absent .summary-value { color: var(--danger); }
        .summary-excused .summary-value { color: var(--purple); }
        
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .attendance-table th {
            background: #f1f5f9;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: var(--secondary);
            font-size: 0.9rem;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .attendance-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }
        
        .attendance-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-present { background: rgba(72, 187, 120, 0.1); color: var(--success); }
        .badge-absent { background: rgba(245, 101, 101, 0.1); color: var(--danger); }
        .badge-excused { background: rgba(159, 122, 234, 0.1); color: #ff0; }
        
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
        .status-excused { background: rgba(159, 122, 234, 0.1); color: var(--purple); }
        
        .attendance-time {
            color: var(--gray);
            font-size: 0.75rem;
            text-align: right;
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
        
        /* RESPONSIVE TABLE */
        @media (max-width: 768px) {
            .attendance-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            
            .attendance-table th,
            .attendance-table td {
                min-width: 120px;
            }
        }
        
        /* TABLET VIEW */
        @media (min-width: 768px) {
            .stats-container {
                flex-direction: row;
            }
            
            .stats-section {
                flex: 1;
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
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <h1>
                <i class="fas fa-tachometer-alt"></i>
                Rankholder Dashboard
            </h1>
            <div class="user-info">
                <div class="user-details">
                    <div class="user-avatar">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="user-text">
                        <h3><?php echo htmlspecialchars($user_name); ?></h3>
                        <p>Rankholder • <?php echo strtoupper($service_type); ?></p>
                    </div>
                </div>
                <button class="logout-btn" onclick="logout()">
                    <i class="fas fa-sign-out-alt"></i> Log Out
                </button>
            </div>
        </div>
        
        <!-- WELCOME CARD -->
        <div class="welcome-card">
            <h2>
                <i class="fas fa-chart-line"></i>
                Attendance Overview
            </h2>
            <p>Monitor and manage cadet attendance in real-time</p>
        </div>
    
        <!-- STATS GRID -->
        <div class="stats-grid">
            <!-- Today's Attendance -->
            <div class="stat-card today">
                <div class="stat-icon">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-number"><?php echo $todayStats['total_today']; ?></div>
                <div class="stat-label">Today's Attendance</div>
                <div class="stat-subtext"><?php echo date('d/m/Y'); ?></div>
            </div>
            
            <!-- Average Attendance -->
            <div class="stat-card avg">
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-number"><?php echo $avgStats['avg_present_percent']; ?>%</div>
                <div class="stat-label">Average Present</div>
                <div class="stat-subtext">Last 7 days</div>
            </div>
            
            <!-- Leave Requests -->
            <div class="stat-card leaves">
                <div class="stat-icon">
                    <i class="fas fa-file-import"></i>
                </div>
                <div class="stat-number"><?php echo $leavesStats['pending_leaves']; ?></div>
                <div class="stat-label">Pending Leave</div>
                <div class="stat-subtext">From <?php echo $leavesStats['total_leaves']; ?> requests</div>
            </div>
        </div>
        
        <!-- DETAILED STATS -->
        <div class="detailed-stats">
            <h3 class="section-title">
                <i class="fas fa-chart-pie"></i>
                Detailed Statistics - Today
            </h3>
            
            <div class="stats-container">
                <!-- STATISTICS BY SERVICE -->
                <div class="stats-section">
                    <h4><i class="fas fa-building"></i> By Service</h4>
                    
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
                                    <div class="stat-label-small">PRESENT</div>
                                </div>
                                <div class="stat-item stat-absent">
                                    <div class="stat-value"><?php echo $service['absent']; ?></div>
                                    <div class="stat-label-small">ABSENT</div>
                                </div>
                                <div class="stat-item stat-excused">
                                    <div class="stat-value"><?php echo $service['excused']; ?></div>
                                    <div class="stat-label-small">EXCUSED</div>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php else: ?>
                    <div class="no-data" style="padding: 10px;">
                        <i class="fas fa-building"></i>
                        <p>No service data for today</p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- STATISTICS BY RANK -->
                <div class="stats-section">
                    <h4><i class="fas fa-ranking-star"></i> By Rank</h4>
                    
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
                                    <div class="stat-label-small">PRESENT</div>
                                </div>
                                <div class="stat-item stat-absent">
                                    <div class="stat-value"><?php echo $rank['absent']; ?></div>
                                    <div class="stat-label-small">ABSENT</div>
                                </div>
                                <div class="stat-item stat-excused">
                                    <div class="stat-value"><?php echo $rank['excused']; ?></div>
                                    <div class="stat-label-small">EXCUSED</div>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php else: ?>
                    <div class="no-data" style="padding: 10px;">
                        <i class="fas fa-ranking-star"></i>
                        <p>No rank data for today</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- FILTER SECTION -->
        <div class="filter-section">
            <h3 class="section-title">
                <i class="fas fa-filter"></i>
                Filter Attendance Records
            </h3>
            
            <form method="GET" action="dashboard.php" class="filter-form">
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="date" class="form-input" value="<?php echo $filter_date; ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Service Type</label>
                    <select name="service" class="form-select">
                        <option value="all">All Services</option>
                        <?php while($service = $servicesResult->fetch_assoc()): ?>
                        <option value="<?php echo $service['service_type']; ?>" 
                            <?php echo $filter_service == $service['service_type'] ? 'selected' : ''; ?>>
                            <?php echo ucfirst($service['service_type']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Rank Level</label>
                    <select name="rank" class="form-select">
                        <option value="all">All Ranks</option>
                        <?php while($rank = $ranksResult->fetch_assoc()): ?>
                        <option value="<?php echo $rank['rank_level']; ?>" 
                            <?php echo $filter_rank == $rank['rank_level'] ? 'selected' : ''; ?>>
                            <?php echo ucfirst($rank['rank_level']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="filter-buttons">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- FILTERED ATTENDANCE LIST -->
        <div class="attendance-section">
            <h3 class="section-title">
                <i class="fas fa-clipboard-list"></i>
                Filtered Attendance Records
                <span style="font-size: 0.9rem; color: var(--gray); margin-left: auto;">
                    <?php echo date('d/m/Y', strtotime($filter_date)); ?>
                </span>
            </h3>
            
            <?php 
            // Calculate summary statistics for filtered data
            $total_filtered = 0;
            $present_filtered = 0;
            $absent_filtered = 0;
            $excused_filtered = 0;
            
            // Clone result to count and also display
            $filtered_data = [];
            while($row = $attendanceResult->fetch_assoc()) {
                $filtered_data[] = $row;
                $total_filtered++;
                switch($row['status']) {
                    case 'present': $present_filtered++; break;
                    case 'absent': $absent_filtered++; break;
                    case 'excused': $excused_filtered++; break;
                }
            }
            ?>
            
            <div class="stats-summary">
                <div class="summary-card summary-total">
                    <div class="summary-value"><?php echo $total_filtered; ?></div>
                    <div class="summary-label">TOTAL</div>
                </div>
                <div class="summary-card summary-present">
                    <div class="summary-value"><?php echo $present_filtered; ?></div>
                    <div class="summary-label">PRESENT</div>
                </div>
                <div class="summary-card summary-absent">
                    <div class="summary-value"><?php echo $absent_filtered; ?></div>
                    <div class="summary-label">ABSENT</div>
                </div>
                <div class="summary-card summary-excused">
                    <div class="summary-value"><?php echo $excused_filtered; ?></div>
                    <div class="summary-label">EXCUSED</div>
                </div>
            </div>
            
            <?php if (count($filtered_data) > 0): ?>
            <div style="overflow-x: auto;">
                <table class="attendance-table">
                    <thead>
                        <tr>
                            <th>Cadet Name</th>
                            <th>Military No.</th>
                            <th>Service</th>
                            <th>Rank</th>
                            <th>Training</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($filtered_data as $row): 
                            $statusClass = 'badge-' . $row['status'];
                            $serviceClass = 'badge-' . strtolower($row['service_type']);
                            $rankClass = 'badge-' . strtolower($row['rank_level']);
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight: 600; color: var(--primary);">
                                    <?php echo htmlspecialchars($row['cadet_name']); ?>
                                </div>
                            </td>
                            <td><?php echo $row['military_number']; ?></td>
                            <td>
                                <span class="service-badge <?php echo $serviceClass; ?>">
                                    <?php echo ucfirst($row['service_type']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="rank-badge <?php echo $rankClass; ?>">
                                    <?php echo ucfirst($row['rank_level']); ?>
                                </span>
                            </td>
                            <td><?php echo $row['training_type']; ?></td>
                            <td><?php echo $row['location']; ?></td>
                            <td>
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php echo strtoupper($row['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo date('h:i A', strtotime($row['recorded_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-clipboard-list"></i>
                <p>No attendance records found for the selected filters</p>
                <p style="font-size: 0.9rem;">Try changing your filter criteria</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- RECENT ATTENDANCE -->
        <div class="recent-attendance">
            <h3 class="section-title">
                <i class="fas fa-history"></i>
                Recent Attendance
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
                <p>No recent attendance records</p>
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
            <div class="mobile-nav-label">Take</div>
        </a>
        
        <a href="view_attendance.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-list"></i>
            </div>
            <div class="mobile-nav-label">View</div>
        </a>
        
    </nav>
    
    <script>
        function logout() {
            if (confirm('Are you sure you want to log out?')) {
                window.location.href = '../logout.php';
            }
        }
        
        // Set default date to today if empty
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.querySelector('input[type="date"]');
            if (dateInput && !dateInput.value) {
                const today = new Date().toISOString().split('T')[0];
                dateInput.value = today;
            }
            
            // Add ripple effect to mobile nav items
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
        
        // Refresh dashboard every 60 seconds
        setInterval(() => {
            window.location.reload();
        }, 60000);
    </script>
</body>
</html>
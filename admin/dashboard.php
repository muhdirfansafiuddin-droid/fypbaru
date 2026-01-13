<?php
// admin/dashboard.php - DESKTOP OPTIMIZED VERSION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start output buffering
ob_start();

// UPDATE PATH INI:
require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

// Check permission
RBAC::checkPermission('admin');

try {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    $db = new Database();
    
    // Check if user is logged in
    if (!$user) {
        header("Location: ../index.php");
        exit();
    }
    
} catch (Exception $e) {
    die("Error initializing system: " . $e->getMessage());
}

// Helper function to format time
function timeAgo($timestamp) {
    if (empty($timestamp) || $timestamp === null) return "No data";
    
    try {
        $time = strtotime($timestamp);
        if ($time === false) return "Invalid date";
        
        $timeDiff = time() - $time;
        
        if ($timeDiff < 60) {
            return "Just now";
        } elseif ($timeDiff < 3600) {
            $mins = floor($timeDiff / 60);
            return "$mins minute" . ($mins > 1 ? "s" : "") . " ago";
        } elseif ($timeDiff < 86400) {
            $hours = floor($timeDiff / 3600);
            return "$hours hour" . ($hours > 1 ? "s" : "") . " ago";
        } elseif ($timeDiff < 604800) {
            $days = floor($timeDiff / 86400);
            return "$days day" . ($days > 1 ? "s" : "") . " ago";
        } else {
            return date('d M Y, h:i A', $time);
        }
    } catch (Exception $e) {
        return "Invalid date";
    }
}

// Fetch statistics with error handling - THESE ARE REAL-TIME FROM DATABASE
$stats = [
    'cadets' => 0,
    'sessions' => 0,
    'pending_leaves' => 0,
    'avg_attendance' => 0,
    'attendance_today' => 0
];

// 1. Total cadets - REAL-TIME QUERY
try {
    $sql1 = "SELECT COUNT(*) as total FROM users WHERE role = 'cadet'";
    $stmt1 = $db->prepare($sql1);
    if ($stmt1) {
        $stmt1->execute();
        $result1 = $stmt1->get_result();
        $row1 = $result1->fetch_assoc();
        $stats['cadets'] = $row1['total'] ?? 0;
    }
} catch (Exception $e) {
    $stats['cadets'] = 0;
}

// 2. Total training sessions this month - REAL-TIME QUERY
try {
    $sql2 = "SELECT COUNT(*) as total FROM training_sessions 
            WHERE MONTH(training_date) = MONTH(CURRENT_DATE()) 
            AND YEAR(training_date) = YEAR(CURRENT_DATE()) 
            AND is_active = 1";
    $stmt2 = $db->prepare($sql2);
    if ($stmt2) {
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        $row2 = $result2->fetch_assoc();
        $stats['sessions'] = $row2['total'] ?? 0;
    }
} catch (Exception $e) {
    $stats['sessions'] = 0;
}

// 3. Pending leave requests - REAL-TIME QUERY
try {
    $sql3 = "SELECT COUNT(*) as total FROM attendance 
            WHERE is_leave = 1 AND (status = 'excused' OR status IS NULL) 
            AND checked_by IS NULL";
    $stmt3 = $db->prepare($sql3);
    if ($stmt3) {
        $stmt3->execute();
        $result3 = $stmt3->get_result();
        $row3 = $result3->fetch_assoc();
        $stats['pending_leaves'] = $row3['total'] ?? 0;
    }
} catch (Exception $e) {
    $stats['pending_leaves'] = 0;
}

// 4. Average attendance rate - REAL-TIME QUERY
try {
    $sql4 = "SELECT ROUND(AVG(attendance_rate), 1) as avg FROM allowance_calculations 
            WHERE month_year = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')";
    $stmt4 = $db->prepare($sql4);
    if ($stmt4) {
        $stmt4->execute();
        $result4 = $stmt4->get_result();
        $row4 = $result4->fetch_assoc();
        $stats['avg_attendance'] = $row4['avg'] ?? 0;
    }
} catch (Exception $e) {
    $stats['avg_attendance'] = 0;
}

// 5. Total attendance today - REAL-TIME QUERY
try {
    $sql5 = "SELECT COUNT(*) as total FROM attendance a
            JOIN training_sessions ts ON a.session_id = ts.session_id
            WHERE DATE(a.date) = CURDATE() 
            AND a.status = 'present'
            AND ts.training_date = CURDATE()";
    $stmt5 = $db->prepare($sql5);
    if ($stmt5) {
        $stmt5->execute();
        $result5 = $stmt5->get_result();
        $row5 = $result5->fetch_assoc();
        $stats['attendance_today'] = $row5['total'] ?? 0;
    }
} catch (Exception $e) {
    $stats['attendance_today'] = 0;
}

// Fetch latest activities with error handling
$latestCadet = null;
$latestSession = null;
$latestAttendance = null;
$latestLeave = null;
$latestAllowance = null;

// 1. Latest registered cadets - REAL-TIME QUERY
try {
    $sql6 = "SELECT name, created_at FROM users 
            WHERE role = 'cadet' 
            ORDER BY created_at DESC LIMIT 1";
    $stmt6 = $db->prepare($sql6);
    if ($stmt6) {
        $stmt6->execute();
        $result6 = $stmt6->get_result();
        $latestCadet = $result6->fetch_assoc();
    }
} catch (Exception $e) {
    $latestCadet = null;
}

// 2. Latest training sessions - REAL-TIME QUERY
try {
    $sql7 = "SELECT location, training_type, created_at 
            FROM training_sessions 
            WHERE is_active = 1
            ORDER BY created_at DESC LIMIT 1";
    $stmt7 = $db->prepare($sql7);
    if ($stmt7) {
        $stmt7->execute();
        $result7 = $stmt7->get_result();
        $latestSession = $result7->fetch_assoc();
    }
} catch (Exception $e) {
    $latestSession = null;
}

// 3. Latest attendance updates - REAL-TIME QUERY
try {
    $sql8 = "SELECT a.recorded_at, u.name, ts.training_type, ts.location
            FROM attendance a
            JOIN users u ON a.user_id = u.user_id
            JOIN training_sessions ts ON a.session_id = ts.session_id
            WHERE a.checked_by IS NOT NULL
            ORDER BY a.recorded_at DESC LIMIT 1";
    $stmt8 = $db->prepare($sql8);
    if ($stmt8) {
        $stmt8->execute();
        $result8 = $stmt8->get_result();
        $latestAttendance = $result8->fetch_assoc();
    }
} catch (Exception $e) {
    $latestAttendance = null;
}

// 4. Latest leave approvals - REAL-TIME QUERY
try {
    $sql9 = "SELECT a.reason, a.checked_at, u.name, a.status
            FROM attendance a
            JOIN users u ON a.user_id = u.user_id
            WHERE a.status = 'excused' AND a.checked_by IS NOT NULL
            ORDER BY a.checked_at DESC LIMIT 1";
    $stmt9 = $db->prepare($sql9);
    if ($stmt9) {
        $stmt9->execute();
        $result9 = $stmt9->get_result();
        $latestLeave = $result9->fetch_assoc();
    }
} catch (Exception $e) {
    $latestLeave = null;
}

// 5. Latest allowance calculations - REAL-TIME QUERY
try {
    $sql10 = "SELECT ac.calculated_at, u.name, ac.total_amount 
             FROM allowance_calculations ac
             JOIN users u ON ac.user_id = u.user_id
             ORDER BY ac.calculated_at DESC LIMIT 1";
    $stmt10 = $db->prepare($sql10);
    if ($stmt10) {
        $stmt10->execute();
        $result10 = $stmt10->get_result();
        $latestAllowance = $result10->fetch_assoc();
    }
} catch (Exception $e) {
    $latestAllowance = null;
}

// Get service type distribution - REAL-TIME QUERY
$serviceStats = ['darat' => 0, 'laut' => 0, 'udara' => 0];
try {
    $serviceSql = "SELECT service_type, COUNT(*) as count FROM users 
                  WHERE role = 'cadet' AND service_type IS NOT NULL 
                  AND service_type IN ('darat', 'laut', 'udara')
                  GROUP BY service_type";
    $serviceStmt = $db->prepare($serviceSql);
    if ($serviceStmt) {
        $serviceStmt->execute();
        $serviceResult = $serviceStmt->get_result();
        while($row = $serviceResult->fetch_assoc()) {
            $serviceStats[$row['service_type']] = $row['count'];
        }
    }
} catch (Exception $e) {
    // Keep default values
}

// Get today's activities with full details - REAL-TIME QUERY
$todayActivitiesResult = null;
try {
    $todayActivitiesSql = "SELECT ts.training_type, ts.location, ts.session_time, 
                          ts.training_date, u.name as creator,
                          (SELECT COUNT(*) FROM attendance a WHERE a.session_id = ts.session_id AND a.status = 'present') as present_count,
                          (SELECT COUNT(*) FROM attendance a WHERE a.session_id = ts.session_id) as total_count
                          FROM training_sessions ts
                          JOIN users u ON ts.created_by = u.user_id
                          WHERE DATE(ts.training_date) = CURDATE() 
                          AND ts.is_active = 1
                          ORDER BY 
                          CASE ts.session_time 
                              WHEN 'pagi' THEN 1
                              WHEN 'tengah hari' THEN 2
                              WHEN 'petang' THEN 3
                              WHEN 'malam' THEN 4
                          END";
    $todayStmt = $db->prepare($todayActivitiesSql);
    if ($todayStmt) {
        $todayStmt->execute();
        $todayActivitiesResult = $todayStmt->get_result();
    }
} catch (Exception $e) {
    $todayActivitiesResult = null;
}

// Get pending actions count - REAL-TIME QUERY
$pendingActions = 0;
$pendingActions += $stats['pending_leaves'];

// Check if there are attendance records pending verification - REAL-TIME QUERY
$pendingAttendance = 0;
try {
    $pendingAttendanceSql = "SELECT COUNT(*) as count FROM attendance a
                            JOIN training_sessions ts ON a.session_id = ts.session_id
                            WHERE a.checked_by IS NULL 
                            AND a.status != 'excused' 
                            AND DATE(ts.training_date) <= CURDATE()";
    $pendingAttStmt = $db->prepare($pendingAttendanceSql);
    if ($pendingAttStmt) {
        $pendingAttStmt->execute();
        $pendingAttResult = $pendingAttStmt->get_result();
        $pendingRow = $pendingAttResult->fetch_assoc();
        $pendingAttendance = $pendingRow['count'] ?? 0;
        $pendingActions += $pendingAttendance;
    }
} catch (Exception $e) {
    $pendingAttendance = 0;
}

// Format numbers
function formatNumber($num) {
    if ($num >= 1000) {
        return number_format($num / 1000, 1) . 'K';
    }
    return $num;
}

// Safe display function
function safeDisplay($value, $default = 'No data') {
    return !empty($value) ? htmlspecialchars($value) : $default;
}

// Safe number format
function safeNumber($value, $decimals = 0) {
    return is_numeric($value) ? number_format($value, $decimals) : '0';
}

// End output buffering and send to browser
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* OPTIMIZED FOR DESKTOP - ALL IN ONE VIEW */
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --light: #f7fafc;
            --success: #48bb78;
            --warning: #ed8936;
            --danger: #f56565;
            --purple: #9f7aea;
            --indigo: #667eea;
            --pink: #ed64a6;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        
        body {
            background: #82CAFF;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        .dashboard-container {
            display: grid;
            grid-template-rows: auto 1fr auto;
            min-height: 100vh;
            max-width: 100%;
            margin: 0;
        }
        
        /* HEADER - COMPACT */
        .dashboard-header {
            background: var(--primary);
            color: white;
            padding: 15px 25px;
            position: sticky;
            top: 0;
            z-index: 100;
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .system-titles h1 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 2px;
        }
        
        .system-titles p {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .logo-circle {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            overflow: hidden;
            transition: transform 0.3s;
        }
        
        .logo-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(255,255,255,0.1);
            padding: 10px 15px;
            border-radius: 8px;
        }
        
        .user-details h3 {
            font-size: 1rem;
            margin-bottom: 2px;
        }
        
        .user-details p {
            font-size: 0.8rem;
            opacity: 0.9;
        }
        
        .logout-btn {
            background: var(--accent);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
        }
        
        .logout-btn:hover {
            background: #2c5282;
            transform: translateY(-1px);
        }
        
        /* STATISTICS - COMPACT GRID */
        .stats-section {
            background: white;
            padding: 15px 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
        }
        
        .stat-card {
            background: #f8fafc;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            transition: transform 0.3s;
            border-left: 4px solid;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .stat-card.cadets { border-color: var(--accent); }
        .stat-card.sessions { border-color: var(--warning); }
        .stat-card.pending { border-color: var(--danger); }
        .stat-card.attendance { border-color: var(--success); }
        .stat-card.rate { border-color: var(--purple); }
        
        .stat-icon {
            font-size: 1.5rem;
            margin-bottom: 8px;
            color: inherit;
        }
        
        .stat-card.cadets { color: var(--accent); }
        .stat-card.sessions { color: var(--warning); }
        .stat-card.pending { color: var(--danger); }
        .stat-card.attendance { color: var(--success); }
        .stat-card.rate { color: var(--purple); }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 5px 0;
        }
        
        .stat-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 3px;
        }
        
        .stat-subtext {
            font-size: 0.7rem;
            color: #718096;
            opacity: 0.8;
        }
        
        /* MAIN DASHBOARD CONTENT - ALL IN ONE VIEW */
        .dashboard-main {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 15px;
            padding: 15px;
            flex: 1;
            overflow: hidden;
            height: calc(100vh - 180px); /* Adjust based on header height */
        }
        
        /* LEFT PANEL - FUNCTIONS */
        .left-panel {
            display: grid;
            grid-template-rows: auto 1fr;
            gap: 15px;
            overflow: hidden;
        }
        
        /* FUNCTIONS GRID - COMPACT */
        .functions-section {
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .section-title {
            color: var(--primary);
            font-size: 1.2rem;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--accent);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .functions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            max-height: 220px;
            overflow-y: auto;
            padding-right: 5px;
        }
        
        .functions-grid::-webkit-scrollbar {
            width: 6px;
        }
        
        .functions-grid::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .functions-grid::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        .function-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            transition: all 0.3s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
            border: 1px solid #e2e8f0;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .function-card:hover {
            transform: translateY(-3px);
            border-color: var(--accent);
            box-shadow: 0 5px 15px rgba(49, 130, 206, 0.1);
        }
        
        .card-icon {
            font-size: 1.5rem;
            color: var(--accent);
            margin-bottom: 10px;
        }
        
        .card-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 6px;
            line-height: 1.3;
        }
        
        .card-desc {
            color: var(--secondary);
            font-size: 0.8rem;
            line-height: 1.4;
            flex: 1;
        }
        
        /* TODAY'S ACTIVITIES - COMPACT */
        .todays-activities {
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        .activities-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .date-badge {
            background: var(--accent);
            color: white;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .activity-list {
            flex: 1;
            overflow-y: auto;
            padding-right: 5px;
        }
        
        .activity-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            border-left: 3px solid var(--accent);
            transition: transform 0.3s;
            cursor: pointer;
        }
        
        .activity-card:hover {
            transform: translateX(3px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }
        
        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .activity-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .activity-time-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(49, 130, 206, 0.1);
            color: var(--accent);
            white-space: nowrap;
        }
        
        .activity-details {
            font-size: 0.8rem;
            color: #718096;
            margin-bottom: 8px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .activity-stats {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .stat-pill {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            background: white;
            border-radius: 12px;
            font-size: 0.75rem;
            color: var(--secondary);
            border: 1px solid #e2e8f0;
        }
        
        .no-activities {
            text-align: center;
            padding: 20px;
            color: var(--secondary);
        }
        
        /* RIGHT PANEL - ACTIVITY & DISTRIBUTION */
        .right-panel {
            display: grid;
            grid-template-rows: auto 1fr;
            gap: 15px;
            overflow: hidden;
        }
        
        /* ACTIVITY FEED - COMPACT */
        .activity-feed-section {
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
        }
        
        .activity-feed {
            flex: 1;
            overflow-y: auto;
            max-height: 200px;
            padding-right: 5px;
        }
        
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        
        .activity-content h4 {
            font-size: 0.85rem;
            color: var(--primary);
            margin-bottom: 2px;
            line-height: 1.3;
        }
        
        .activity-time {
            font-size: 0.75rem;
            color: #718096;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        /* SERVICE DISTRIBUTION - COMPACT */
        .service-distribution {
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .service-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .service-icon {
            width: 35px;
            height: 35px;
            background: var(--accent);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
        }
        
        .service-title {
            font-size: 1.1rem;
            color: var(--primary);
            font-weight: 600;
        }
        
        .service-list {
            margin-top: 10px;
        }
        
        .service-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .service-item:last-child {
            border-bottom: none;
        }
        
        .service-name {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .service-badge {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        .service-darat { background: var(--accent); }
        .service-laut { background: var(--success); }
        .service-udara { background: var(--warning); }
        
        .service-label {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--secondary);
        }
        
        .service-count {
            font-weight: 600;
            color: var(--primary);
            font-size: 1rem;
        }
        
        /* FOOTER - COMPACT */
        .dashboard-footer {
            background: var(--secondary);
            color: white;
            padding: 12px 25px;
            text-align: center;
            font-size: 0.85rem;
            border-top: 3px solid var(--accent);
        }
        
        /* RESPONSIVE ADJUSTMENTS */
        @media (max-width: 1400px) {
            .functions-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }
        }
        
        @media (max-width: 1200px) {
            .dashboard-main {
                grid-template-columns: 1fr;
                height: auto;
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 992px) {
            .dashboard-header {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 10px;
            }
            
            .user-info {
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-main {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .functions-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            }
            
            .activity-stats {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        
        @media (max-width: 480px) {
            .functions-grid {
                grid-template-columns: 1fr;
            }
            
            .activity-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .date-badge {
                align-self: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- COMPACT HEADER -->
        <header class="dashboard-header">
            
    
            <div class="system-titles">
                <h1>CAAMS</h1>
                <p>Centralized Attendance & Allowance Management System for ROTU UPNM</p>
            </div>
            <div> <div class="logo-circle" title="UPNM Logo">
                    <img src="../assets/upnm.png" alt="UPNM Logo" onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\"fas fa-university\" style=\"color:#3182ce; font-size:1.5rem; line-height:70px;\"></i>'">
                </div>
            </div>
            <div class="user-info">
                <div class="user-details">
                    <h3><?php echo safeDisplay($user['name'] ?? 'Admin'); ?></h3>
                    <p>Admin ID: <?php echo safeDisplay($user['military_number'] ?? 'ADMIN001'); ?></p>
                </div>
               
            
            <div>
                <a href="../logout.php" class="logout-btn" onclick="return confirm('Log out of system?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </header>
        
        <!-- COMPACT STATISTICS -->
        <section class="stats-section">
            <div class="stats-grid">
                <div class="stat-card cadets">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-number"><?php echo safeNumber($stats['cadets']); ?></div>
                    <div class="stat-label">Total Cadets</div>
                    <div class="stat-subtext">Live database</div>
                </div>
                
                <div class="stat-card sessions">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-number"><?php echo safeNumber($stats['sessions']); ?></div>
                    <div class="stat-label">Monthly Sessions</div>
                    <div class="stat-subtext">This month</div>
                </div>
                
                <div class="stat-card pending">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-number"><?php echo safeNumber($pendingActions); ?></div>
                    <div class="stat-label">Pending Actions</div>
                    <div class="stat-subtext">Require attention</div>
                </div>
                
                <div class="stat-card attendance">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="stat-number"><?php echo safeNumber($stats['attendance_today']); ?></div>
                    <div class="stat-label">Today's Attendance</div>
                    <div class="stat-subtext">Present today</div>
                </div>
                
                <div class="stat-card rate">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-number"><?php echo safeNumber($stats['avg_attendance'], 1); ?>%</div>
                    <div class="stat-label">Avg. Attendance</div>
                    <div class="stat-subtext">This month</div>
                </div>
            </div>
        </section>
        
        <!-- MAIN DASHBOARD CONTENT - ALL IN ONE VIEW -->
        <main class="dashboard-main">
            <!-- LEFT PANEL: Functions & Today's Activities -->
            <div class="left-panel">
                <!-- FUNCTIONS GRID -->
                <section class="functions-section">
                    <h2 class="section-title">
                        <i class="fas fa-sliders-h"></i> Admin Functions
                    </h2>
                    
                    <div class="functions-grid">
                        <a href="register_user.php" class="function-card">
                            <div class="card-icon">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <h3 class="card-title">Register User</h3>
                            <p class="card-desc">Add new users and manage roles</p>
                        </a>

                        <a href="list_cadets.php" class="function-card">
                            <div class="card-icon">
                                <i class="fas fa-list-ol"></i>
                            </div>
                            <h3 class="card-title">Cadets & Rankholders</h3>
                            <p class="card-desc">View and manage cadets list</p>
                        </a>
                        
                        <a href="jana_aktiviti.php" class="function-card">
                            <div class="card-icon">
                                <i class="fas fa-calendar-plus"></i>
                            </div>
                            <h3 class="card-title">Generate Activity</h3>
                            <p class="card-desc">Create training sessions</p>
                        </a>
                        
                        <a href="manage_attendance.php" class="function-card">
                            <div class="card-icon">
                                <i class="fas fa-clipboard-check"></i>
                            </div>
                            <h3 class="card-title">Manage Attendance</h3>
                            <p class="card-desc">Verify attendance records</p>
                        </a>
                        
                        <a href="manage_excuses.php" class="function-card">
                            <div class="card-icon">
                                <i class="fas fa-file-medical"></i>
                            </div>
                            <h3 class="card-title">Manage Excuses</h3>
                            <p class="card-desc">Approve leave requests</p>
                        </a>
                        
                        <a href="manage_allowance.php" class="function-card">
                            <div class="card-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <h3 class="card-title">Manage Allowance</h3>
                            <p class="card-desc">Calculate cadet allowances</p>
                        </a>
                        
                        <a href="reports.php" class="function-card">
                            <div class="card-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <h3 class="card-title">Final Reports</h3>
                            <p class="card-desc">Generate reports & exports</p>
                        </a>
                    </div>
                </section>
                
                <!-- TODAY'S ACTIVITIES -->
                <section class="todays-activities">
                    <div class="activities-header">
                        <h3 class="section-title" style="font-size: 1.1rem; margin-bottom: 0; border-bottom: none;">
                            <i class="fas fa-calendar-day"></i> Today's Activities
                        </h3>
                        <div class="date-badge">
                            <?php echo date('d F Y'); ?>
                        </div>
                    </div>
                    
                    <div class="activity-list">
                        <?php if ($todayActivitiesResult && $todayActivitiesResult->num_rows > 0): 
                            while($activity = $todayActivitiesResult->fetch_assoc()): 
                                $sessionLabels = [
                                    'pagi' => 'Morning',
                                    'tengah hari' => 'Noon',
                                    'petang' => 'Afternoon',
                                    'malam' => 'Night'
                                ];
                                
                                $attendancePercent = ($activity['total_count'] > 0) 
                                    ? round(($activity['present_count'] / $activity['total_count']) * 100, 1) 
                                    : 0;
                        ?>
                        <div class="activity-card" onclick="window.location.href='manage_attendance.php'">
                            <div class="activity-header">
                                <div class="activity-title">
                                    <?php echo safeDisplay($activity['training_type']); ?>
                                </div>
                                <div class="activity-time-badge">
                                    <?php echo $sessionLabels[$activity['session_time']] ?? ucfirst($activity['session_time']); ?>
                                </div>
                            </div>
                            
                            <div class="activity-details">
                                <span title="Location">
                                    <i class="fas fa-map-marker-alt"></i> 
                                    <?php echo safeDisplay($activity['location']); ?>
                                </span>
                                <span title="Created by">
                                    <i class="fas fa-user-tie"></i> 
                                    <?php echo safeDisplay($activity['creator']); ?>
                                </span>
                            </div>
                            
                            <div class="activity-stats">
                                <div class="stat-pill">
                                    <i class="fas fa-user-check"></i>
                                    <?php echo safeNumber($activity['present_count']); ?> present
                                </div>
                                <div class="stat-pill">
                                    <i class="fas fa-chart-line"></i>
                                    <?php echo safeNumber($attendancePercent, 1); ?>%
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                        
                        <?php else: ?>
                        <div class="no-activities">
                            <i class="fas fa-calendar-times" style="font-size: 1.5rem;"></i>
                            <p style="margin-top: 8px;">No training sessions today</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
            
            <!-- RIGHT PANEL: Activity Feed & Service Distribution -->
            <div class="right-panel">
                <!-- ACTIVITY FEED -->
                <section class="activity-feed-section">
                    <h2 class="section-title">
                        <i class="fas fa-history"></i> Recent Activity
                    </h2>
                    
                    <div class="activity-feed">
                        <!-- Activity 1: Latest cadet registered -->
                        <div class="activity-item">
                            <div class="activity-icon" style="background: #4299e1;">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div class="activity-content">
                                <h4>
                                    <?php if ($latestCadet): ?>
                                        Cadet <strong><?php echo safeDisplay($latestCadet['name']); ?></strong> registered
                                    <?php else: ?>
                                        No cadets registered
                                    <?php endif; ?>
                                </h4>
                                <p class="activity-time">
                                    <i class="far fa-clock"></i>
                                    <?php echo timeAgo($latestCadet['created_at'] ?? null); ?>
                                </p>
                            </div>
                        </div>
                        
                        <!-- Activity 2: Latest training session created -->
                        <div class="activity-item">
                            <div class="activity-icon" style="background: #ed8936;">
                                <i class="fas fa-calendar-plus"></i>
                            </div>
                            <div class="activity-content">
                                <h4>
                                    <?php if ($latestSession): ?>
                                        <strong><?php echo safeDisplay($latestSession['training_type']); ?></strong> session created
                                    <?php else: ?>
                                        No training sessions
                                    <?php endif; ?>
                                </h4>
                                <p class="activity-time">
                                    <i class="far fa-clock"></i>
                                    <?php echo timeAgo($latestSession['created_at'] ?? null); ?>
                                </p>
                            </div>
                        </div>
                        
                        <!-- Activity 3: Latest attendance update -->
                        <div class="activity-item">
                            <div class="activity-icon" style="background: #48bb78;">
                                <i class="fas fa-clipboard-check"></i>
                            </div>
                            <div class="activity-content">
                                <h4>
                                    <?php if ($latestAttendance): ?>
                                        <strong><?php echo safeDisplay($latestAttendance['name']); ?></strong>'s attendance updated
                                    <?php else: ?>
                                        No attendance updated
                                    <?php endif; ?>
                                </h4>
                                <p class="activity-time">
                                    <i class="far fa-clock"></i>
                                    <?php echo timeAgo($latestAttendance['recorded_at'] ?? null); ?>
                                </p>
                            </div>
                        </div>
                        
                        <!-- Activity 4: Latest leave approval -->
                        <div class="activity-item">
                            <div class="activity-icon" style="background: #9f7aea;">
                                <i class="fas fa-file-medical"></i>
                            </div>
                            <div class="activity-content">
                                <h4>
                                    <?php if ($latestLeave): ?>
                                        <strong><?php echo safeDisplay($latestLeave['name']); ?></strong>'s leave approved
                                    <?php else: ?>
                                        No leaves approved
                                    <?php endif; ?>
                                </h4>
                                <p class="activity-time">
                                    <i class="far fa-clock"></i>
                                    <?php echo timeAgo($latestLeave['checked_at'] ?? null); ?>
                                </p>
                            </div>
                        </div>
                        
                        <!-- Activity 5: Latest allowance calculation -->
                        <div class="activity-item">
                            <div class="activity-icon" style="background: #f56565;">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="activity-content">
                                <h4>
                                    <?php if ($latestAllowance): ?>
                                        <strong><?php echo safeDisplay($latestAllowance['name']); ?></strong>'s allowance: RM <?php echo safeNumber($latestAllowance['total_amount'] ?? 0, 2); ?>
                                    <?php else: ?>
                                        No allowances
                                    <?php endif; ?>
                                </h4>
                                <p class="activity-time">
                                    <i class="far fa-clock"></i>
                                    <?php echo timeAgo($latestAllowance['calculated_at'] ?? null); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
                
                <!-- SERVICE DISTRIBUTION -->
                <section class="service-distribution">
                    <div class="service-header">
                        <div class="service-icon">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <h3 class="service-title">Service Distribution</h3>
                    </div>
                    
                    <div class="service-list">
                        <div class="service-item">
                            <div class="service-name">
                                <div class="service-badge service-darat"></div>
                                <span class="service-label">Army</span>
                            </div>
                            <div class="service-count"><?php echo safeNumber($serviceStats['darat']); ?></div>
                        </div>
                        
                        <div class="service-item">
                            <div class="service-name">
                                <div class="service-badge service-laut"></div>
                                <span class="service-label">Navy</span>
                            </div>
                            <div class="service-count"><?php echo safeNumber($serviceStats['laut']); ?></div>
                        </div>
                        
                        <div class="service-item">
                            <div class="service-name">
                                <div class="service-badge service-udara"></div>
                                <span class="service-label">Air Force</span>
                            </div>
                            <div class="service-count"><?php echo safeNumber($serviceStats['udara']); ?></div>
                        </div>
                    </div>
                </section>
            </div>
        </main>
        
        <!-- COMPACT FOOTER -->
        <footer class="dashboard-footer">
            <p class="footer-text">
                CAAMS Admin Dashboard | PALAPES Headquarters, National Defence University of Malaysia
                <br>&copy; 2026 Centralized Attendance & Allowance Management System
            </p>
        </footer>
    </div>
    
    <script>
        // Auto-refresh activity feed every 60 seconds
        setInterval(() => {
            location.reload();
        }, 60000);
        
        // Card hover effects
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.function-card, .activity-card, .stat-card');
            
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    if (this.classList.contains('function-card')) {
                        this.style.transform = 'translateY(-3px)';
                    } else if (this.classList.contains('activity-card')) {
                        this.style.transform = 'translateX(3px)';
                    } else if (this.classList.contains('stat-card')) {
                        this.style.transform = 'translateY(-2px)';
                    }
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = '';
                });
            });
            
            // Update current time in header
            function updateTime() {
                const now = new Date();
                const timeElement = document.querySelector('.user-details');
                if (timeElement && timeElement.children.length > 1) {
                    timeElement.children[1].innerHTML = 
                        'Last update: ' + now.toLocaleTimeString('en-US', {hour: '2-digit', minute:'2-digit'});
                }
            }
            
            // Update time every minute
            updateTime();
            setInterval(updateTime, 60000);
            
            // Smooth scroll for all scrollable areas
            const scrollableElements = document.querySelectorAll('.activity-list, .activity-feed, .functions-grid');
            scrollableElements.forEach(element => {
                element.addEventListener('wheel', function(e) {
                    e.preventDefault();
                    this.scrollTop += e.deltaY;
                });
            });
            
            // Add click effect to function cards
            document.querySelectorAll('.function-card').forEach(card => {
                card.addEventListener('click', function() {
                    this.style.backgroundColor = '#e2e8f0';
                    setTimeout(() => {
                        this.style.backgroundColor = '';
                    }, 200);
                });
            });
            
            // Logo hover effect
            const logoCircle = document.querySelector('.logo-circle');
            if (logoCircle) {
                logoCircle.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.1)';
                });
                
                logoCircle.addEventListener('mouseleave', function() {
                    this.style.transform = '';
                });
            }
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Refresh dashboard with F5
                if (e.key === 'F5') {
                    e.preventDefault();
                    location.reload();
                }
                // Escape to go back
                if (e.key === 'Escape') {
                    window.history.back();
                }
            });
            
            // Highlight stats that need attention
            const pendingStat = document.querySelector('.stat-card.pending .stat-number');
            if (pendingStat && parseInt(pendingStat.textContent) > 0) {
                pendingStat.style.animation = 'pulse 2s infinite';
                document.querySelector('.stat-card.pending').style.boxShadow = '0 0 0 2px rgba(245, 101, 101, 0.3)';
            }
            
            // Add CSS animation for pulse effect
            const style = document.createElement('style');
            style.textContent = `
                @keyframes pulse {
                    0% { opacity: 1; }
                    50% { opacity: 0.7; }
                    100% { opacity: 1; }
                }
            `;
            document.head.appendChild(style);
        });
    </script>
</body>
</html>
<?php
// admin/dashboard.php - FIXED VERSION
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
    if (empty($timestamp) || $timestamp === null) return "Tiada data";
    
    try {
        $time = strtotime($timestamp);
        if ($time === false) return "Tarikh tidak sah";
        
        $timeDiff = time() - $time;
        
        if ($timeDiff < 60) {
            return "Baru sahaja";
        } elseif ($timeDiff < 3600) {
            $mins = floor($timeDiff / 60);
            return "$mins minit" . ($mins > 1 ? "" : "") . " lepas";
        } elseif ($timeDiff < 86400) {
            $hours = floor($timeDiff / 3600);
            return "$hours jam" . ($hours > 1 ? "" : "") . " lepas";
        } elseif ($timeDiff < 604800) {
            $days = floor($timeDiff / 86400);
            return "$days hari" . ($days > 1 ? "" : "") . " lepas";
        } else {
            return date('d M Y, h:i A', $time);
        }
    } catch (Exception $e) {
        return "Tarikh tidak sah";
    }
}

// Fetch statistics dengan error handling
$stats = [
    'cadets' => 0,
    'sessions' => 0,
    'pending_leaves' => 0,
    'avg_attendance' => 0,
    'attendance_today' => 0
];

// 1. Total cadets
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

// 2. Total training sessions this month
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

// 3. Pending leave requests
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

// 4. Average attendance rate
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

// 5. Total attendance today
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

// Fetch latest activities dengan error handling
$latestCadet = null;
$latestSession = null;
$latestAttendance = null;
$latestLeave = null;
$latestAllowance = null;

// 1. Latest registered cadets
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

// 2. Latest training sessions
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

// 3. Latest attendance updates
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

// 4. Latest leave approvals
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

// 5. Latest allowance calculations
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

// Get service type distribution
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

// Get today's activities with full details
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

// Get pending actions count
$pendingActions = 0;
$pendingActions += $stats['pending_leaves'];

// Check if there are attendance records pending verification
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
function safeDisplay($value, $default = 'Tiada data') {
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
        /* PASTE SEMUA CSS ANDA YANG SAMA DI SINI */
        /* CSS TIDAK DIUBAH - SAMA SEPERTI SEBELUM */
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        /* HEADER */
        .dashboard-header {
            background: var(--primary);
            color: white;
            padding: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .dashboard-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }
        
        .system-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
            letter-spacing: 1px;
        }
        
        .system-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-bottom: 20px;
        }
        
        .user-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255,255,255,0.1);
            padding: 15px 20px;
            border-radius: 10px;
            backdrop-filter: blur(10px);
        }
        
        .user-details h3 {
            margin-bottom: 5px;
            font-size: 1.3rem;
        }
        
        .user-details p {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .logout-btn {
            background: var(--accent);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .logout-btn:hover {
            background: #2c5282;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        /* STATISTICS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            padding: 0 30px;
            margin-top: -25px;
            position: relative;
            z-index: 1;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px 20px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            border-top: 5px solid;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.cadets { border-color: var(--accent); }
        .stat-card.sessions { border-color: var(--warning); }
        .stat-card.pending { border-color: var(--danger); }
        .stat-card.attendance { border-color: var(--success); }
        .stat-card.rate { border-color: var(--purple); }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        
        .stat-card.cadets .stat-icon { color: var(--accent); }
        .stat-card.sessions .stat-icon { color: var(--warning); }
        .stat-card.pending .stat-icon { color: var(--danger); }
        .stat-card.attendance .stat-icon { color: var(--success); }
        .stat-card.rate .stat-icon { color: var(--purple); }
        
        .stat-number {
            font-size: 2.8rem;
            font-weight: 700;
            color: var(--primary);
            margin: 10px 0;
        }
        
        .stat-label {
            color: var(--secondary);
            font-size: 0.95rem;
            font-weight: 600;
        }
        
        /* MAIN CONTENT */
        .dashboard-main {
            padding: 60px 30px 40px;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 40px;
        }
        
        .section-title {
            color: var(--primary);
            font-size: 1.8rem;
            margin-bottom: 25px;
            padding-bottom: 10px;
            border-bottom: 3px solid var(--accent);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: var(--accent);
        }
        
        /* FUNCTIONS GRID */
        .functions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .function-card {
            background: var(--light);
            border-radius: 15px;
            padding: 25px;
            transition: all 0.3s;
            border: 2px solid transparent;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .function-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .card-icon {
            font-size: 2.5rem;
            color: var(--accent);
            margin-bottom: 15px;
        }
        
        .card-title {
            font-size: 1.3rem;
            color: var(--primary);
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .card-desc {
            color: var(--secondary);
            line-height: 1.5;
            font-size: 0.95rem;
        }
        
        /* ACTIVITY FEED */
        .activity-feed {
            background: var(--light);
            border-radius: 15px;
            padding: 25px;
            height: fit-content;
            max-height: 400px;
            overflow-y: auto;
            margin-bottom: 30px;
        }
        
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .activity-content h4 {
            color: var(--primary);
            margin-bottom: 3px;
            font-size: 0.95rem;
        }
        
        .activity-time {
            color: #718096;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .activity-time i {
            font-size: 0.7rem;
        }
        
        /* SERVICE DISTRIBUTION - NOW IN RIGHT COLUMN */
        .service-distribution {
            background: var(--light);
            border-radius: 15px;
            padding: 20px;
        }
        
        .service-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .service-icon {
            width: 40px;
            height: 40px;
            background: var(--accent);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }
        
        .service-title {
            font-size: 1.3rem;
            color: var(--primary);
            font-weight: 600;
        }
        
        .service-list {
            margin-top: 15px;
        }
        
        .service-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .service-item:last-child {
            border-bottom: none;
        }
        
        .service-name {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .service-badge {
            width: 15px;
            height: 15px;
            border-radius: 50%;
        }
        
        .service-darat { background: var(--accent); }
        .service-laut { background: var(--success); }
        .service-udara { background: var(--warning); }
        
        .service-label {
            font-weight: 500;
            color: var(--secondary);
        }
        
        .service-count {
            font-weight: 600;
            color: var(--primary);
            font-size: 1.1rem;
        }
        
        /* TODAY'S ACTIVITIES */
        .todays-activities {
            background: var(--light);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .activities-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .date-badge {
            background: var(--accent);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .activity-list {
            margin-top: 15px;
        }
        
        .activity-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 5px solid var(--accent);
            transition: transform 0.3s;
            cursor: pointer;
        }
        
        .activity-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .activity-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .activity-time-badge {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.85rem;
            font-weight: 600;
            background: rgba(49, 130, 206, 0.1);
            color: var(--accent);
            white-space: nowrap;
        }
        
        .activity-details {
            color: #718096;
            font-size: 0.9rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .activity-location {
            color: var(--accent);
        }
        
        .activity-creator {
            color: var(--secondary);
            font-weight: 500;
        }
        
        .activity-stats {
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }
        
        .stat-pill {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            background: #f7fafc;
            border-radius: 15px;
            font-size: 0.85rem;
            color: var(--secondary);
        }
        
        .stat-pill i {
            font-size: 0.8rem;
        }
        
        .no-activities {
            text-align: center;
            padding: 30px 20px;
            color: var(--secondary);
        }
        
        .no-activities i {
            font-size: 2.5rem;
            opacity: 0.3;
            margin-bottom: 10px;
        }
        
        /* FOOTER */
        .dashboard-footer {
            background: var(--secondary);
            color: white;
            padding: 25px 30px;
            text-align: center;
            border-top: 5px solid var(--accent);
        }
        
        .footer-text {
            opacity: 0.9;
            line-height: 1.6;
        }
        
        /* RESPONSIVE */
        @media (max-width: 1100px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 992px) {
            .dashboard-main {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .activity-feed {
                max-height: 300px;
            }
        }
        
        @media (max-width: 768px) {
            .functions-grid {
                grid-template-columns: 1fr;
            }
            
            .user-info {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .system-title {
                font-size: 2rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .activities-header {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
            
            .activity-header {
                flex-direction: column;
                gap: 10px;
            }
            
            .activity-stats {
                flex-wrap: wrap;
            }
            
            .service-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- HEADER -->
        <header class="dashboard-header">
            <h1 class="system-title">CAAMS</h1>
            <p class="system-subtitle">Centralized Attendance & Allowance Management System</p>
            
            <div class="user-info">
                <div class="user-details">
                    <h3>Welcome, <?php echo safeDisplay($user['name'] ?? 'Admin'); ?></h3>
                    <p>Admin ID: <?php echo safeDisplay($user['military_number'] ?? 'ADMIN001'); ?> | Role: Administrator</p>
                    <p style="font-size: 0.8rem; opacity: 0.7; margin-top: 5px;">
                        <i class="far fa-calendar"></i> <?php echo date('l, d F Y'); ?>
                    </p>
                </div>
                <a href="../logout.php" class="logout-btn" onclick="return confirm('Log out dari sistem?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </header>
        
        <!-- STATISTICS -->
        <div class="stats-grid">
            <div class="stat-card cadets">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number"><?php echo safeNumber($stats['cadets']); ?></div>
                <div class="stat-label">Total Kadet</div>
            </div>
            
            <div class="stat-card sessions">
                <div class="stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-number"><?php echo safeNumber($stats['sessions']); ?></div>
                <div class="stat-label">Sesi Bulan Ini</div>
            </div>
            
            <div class="stat-card pending">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-number"><?php echo safeNumber($pendingActions); ?></div>
                <div class="stat-label">Tindakan Tunggu</div>
            </div>
            
            <div class="stat-card attendance">
                <div class="stat-icon">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div class="stat-number"><?php echo safeNumber($stats['attendance_today']); ?></div>
                <div class="stat-label">Kehadiran Hari Ini</div>
            </div>
            
            <div class="stat-card rate">
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-number"><?php echo safeNumber($stats['avg_attendance'], 1); ?>%</div>
                <div class="stat-label">Purata Kehadiran</div>
            </div>
        </div>
        
        <!-- MAIN CONTENT -->
        <main class="dashboard-main">
            <!-- LEFT COLUMN: Functions & Today's Activities -->
            <div class="functions-section">
                <h2 class="section-title">
                    <i class="fas fa-sliders-h"></i> Dashboard
                </h2>
                
                <!-- Functions Grid -->
                <div class="functions-grid">
                    <a href="register_user.php" class="function-card">
                        <div class="card-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h3 class="card-title">Daftar Pengguna</h3>
                        <p class="card-desc">Register new users, update user information, and manage user roles and permissions.</p>
                    </a>

                    <a href="list_cadets.php" class="function-card">
                        <div class="card-icon">
                            <i class="fas fa-list-ol"></i>
                        </div>
                        <h3 class="card-title">Senarai Kadet</h3>
                        <p class="card-desc">View and filter cadets by service type and rank level with statistics.</p>
                    </a>
                    
                    <a href="jana_aktiviti.php" class="function-card">
                        <div class="card-icon">
                            <i class="fas fa-calendar-plus"></i>
                        </div>
                        <h3 class="card-title">Jana Aktiviti</h3>
                        <p class="card-desc">Create new training sessions and manage training activities.</p>
                    </a>
                    
                    <a href="manage_attendance.php" class="function-card">
                        <div class="card-icon">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <h3 class="card-title">Urus Kehadiran</h3>
                        <p class="card-desc">View, verify, and manage cadet attendance records from all training sessions.</p>
                    </a>
                    
                    <a href="manage_leave.php" class="function-card">
                        <div class="card-icon">
                            <i class="fas fa-file-medical"></i>
                        </div>
                        <h3 class="card-title">Urus Pelepasan</h3>
                        <p class="card-desc">Review and approve/reject cadet leave requests with proof documentation.</p>
                    </a>
                    
                    <a href="manage_allowance.php" class="function-card">
                        <div class="card-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <h3 class="card-title">Urus Elaun</h3>
                        <p class="card-desc">Calculate and manage cadet allowances based on attendance and performance.</p>
                    </a>
                    
                    <a href="reports.php" class="function-card">
                        <div class="card-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h3 class="card-title">Laporan Akhir</h3>
                        <p class="card-desc">Generate comprehensive reports and export data for analysis and record-keeping.</p>
                    </a>
                </div>
                
                <!-- TODAY'S ACTIVITIES -->
                <div class="todays-activities">
                    <div class="activities-header">
                        <h3 class="section-title" style="font-size: 1.5rem; margin-bottom: 0; border-bottom: none;">
                            <i class="fas fa-calendar-day"></i> Aktiviti Hari Ini
                        </h3>
                        <div class="date-badge">
                            <?php echo date('d F Y'); ?>
                        </div>
                    </div>
                    
                    <div class="activity-list">
                        <?php if ($todayActivitiesResult && $todayActivitiesResult->num_rows > 0): 
                            while($activity = $todayActivitiesResult->fetch_assoc()): 
                                $sessionLabels = [
                                    'pagi' => 'Pagi (06:00-10:00)',
                                    'tengah hari' => 'Tengah Hari (10:00-14:00)',
                                    'petang' => 'Petang (14:00-18:00)',
                                    'malam' => 'Malam (18:00-22:00)'
                                ];
                                
                                $attendancePercent = ($activity['total_count'] > 0) 
                                    ? round(($activity['present_count'] / $activity['total_count']) * 100, 1) 
                                    : 0;
                        ?>
                        <div class="activity-card">
                            <div class="activity-header">
                                <div class="activity-title">
                                    <?php echo safeDisplay($activity['training_type']); ?>
                                </div>
                                <div class="activity-time-badge">
                                    <?php echo $sessionLabels[$activity['session_time']] ?? ucfirst($activity['session_time']); ?>
                                </div>
                            </div>
                            
                            <div class="activity-details">
                                <span class="activity-location">
                                    <i class="fas fa-map-marker-alt"></i> 
                                    <?php echo safeDisplay($activity['location']); ?>
                                </span>
                                <span class="activity-creator">
                                    <i class="fas fa-user-tie"></i> 
                                    <?php echo safeDisplay($activity['creator']); ?>
                                </span>
                            </div>
                            
                            <div class="activity-stats">
                                <div class="stat-pill">
                                    <i class="fas fa-user-check"></i>
                                    <?php echo safeNumber($activity['present_count']); ?> hadir
                                </div>
                                <div class="stat-pill">
                                    <i class="fas fa-users"></i>
                                    <?php echo safeNumber($activity['total_count']); ?> total
                                </div>
                                <div class="stat-pill" style="color: var(--accent); font-weight: 600;">
                                    <i class="fas fa-chart-line"></i>
                                    <?php echo safeNumber($attendancePercent, 1); ?>%
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                        
                        <?php else: ?>
                        <div class="no-activities">
                            <i class="fas fa-calendar-times"></i>
                            <h4>Tiada Aktiviti Hari Ini</h4>
                            <p>Tiada sesi latihan dijadualkan untuk hari ini.</p>
                            <a href="jana_aktiviti.php" class="btn" style="background: var(--accent); color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; display: inline-block; margin-top: 15px;">
                                <i class="fas fa-plus-circle"></i> Jana Aktiviti
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- RIGHT COLUMN: Activity Feed & Service Distribution -->
            <div class="activity-section">
                <h2 class="section-title">
                    <i class="fas fa-history"></i> Aktiviti Terkini
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
                                    Kadet <strong><?php echo safeDisplay($latestCadet['name']); ?></strong> didaftarkan
                                <?php else: ?>
                                    Tiada kadet didaftarkan
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
                                    Sesi <strong><?php echo safeDisplay($latestSession['training_type']); ?></strong>
                                    <?php if ($latestSession['location']): ?>
                                        di <strong><?php echo safeDisplay($latestSession['location']); ?></strong>
                                    <?php endif; ?>
                                    dijana
                                <?php else: ?>
                                    Tiada sesi latihan dijana
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
                                    Kehadiran <strong><?php echo safeDisplay($latestAttendance['name']); ?></strong> 
                                    <?php if ($latestAttendance['training_type']): ?>
                                        untuk <strong><?php echo safeDisplay($latestAttendance['training_type']); ?></strong>
                                    <?php endif; ?>
                                    dikemas kini
                                <?php else: ?>
                                    Tiada kehadiran dikemas kini
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
                                    Pelepasan <strong><?php echo safeDisplay($latestLeave['name']); ?></strong> diluluskan
                                    <?php if ($latestLeave['reason']): ?>
                                        <br><small><?php echo safeDisplay(substr($latestLeave['reason'], 0, 50)); ?><?php echo strlen($latestLeave['reason']) > 50 ? '...' : ''; ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Tiada pelepasan diluluskan
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
                                    Elaun <strong><?php echo safeDisplay($latestAllowance['name']); ?></strong> dikira: 
                                    <strong>RM <?php echo safeNumber($latestAllowance['total_amount'] ?? 0, 2); ?></strong>
                                <?php else: ?>
                                    Tiada elaun dikira
                                <?php endif; ?>
                            </h4>
                            <p class="activity-time">
                                <i class="far fa-clock"></i>
                                <?php echo timeAgo($latestAllowance['calculated_at'] ?? null); ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- SERVICE DISTRIBUTION - NOW AT BOTTOM OF RIGHT COLUMN -->
                <div class="service-distribution">
                    <div class="service-header">
                        <div class="service-icon">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <h3 class="service-title">Taburan Perkhidmatan Kadet</h3>
                    </div>
                    
                    <div class="service-list">
                        <div class="service-item">
                            <div class="service-name">
                                <div class="service-badge service-darat"></div>
                                <span class="service-label">Darat</span>
                            </div>
                            <div class="service-count"><?php echo safeNumber($serviceStats['darat']); ?></div>
                        </div>
                        
                        <div class="service-item">
                            <div class="service-name">
                                <div class="service-badge service-laut"></div>
                                <span class="service-label">Laut</span>
                            </div>
                            <div class="service-count"><?php echo safeNumber($serviceStats['laut']); ?></div>
                        </div>
                        
                        <div class="service-item">
                            <div class="service-name">
                                <div class="service-badge service-udara"></div>
                                <span class="service-label">Udara</span>
                            </div>
                            <div class="service-count"><?php echo safeNumber($serviceStats['udara']); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        
        <!-- FOOTER -->
        <footer class="dashboard-footer">
            <p class="footer-text">
                CAAMS Dashboard Admin<br>
                Markas PALAPES, Universiti Pertahanan Nasional Malaysia<br>
                &copy; 2026 Centralized Attendance & Allowance Management System
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
            const cards = document.querySelectorAll('.function-card, .activity-card');
            
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = this.classList.contains('function-card') 
                        ? 'translateY(-5px)' 
                        : 'translateX(5px)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
            
            // Update current time
            function updateTime() {
                const now = new Date();
                const timeElement = document.querySelector('.user-details p:last-child');
                if (timeElement) {
                    const options = { 
                        weekday: 'long', 
                        year: 'numeric', 
                        month: 'long', 
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: true
                    };
                    timeElement.innerHTML = `<i class="far fa-calendar"></i> ${now.toLocaleDateString('en-MY', options)}`;
                }
            }
            
            // Update time every minute
            updateTime();
            setInterval(updateTime, 60000);
            
            // Logout confirmation
            const logoutBtn = document.querySelector('.logout-btn');
            if (logoutBtn) {
                logoutBtn.addEventListener('click', function(e) {
                    if (!confirm('Adakah anda pasti ingin log keluar?')) {
                        e.preventDefault();
                    }
                });
            }
            
            // Add loading animation to function cards when clicked
            document.querySelectorAll('.function-card').forEach(card => {
                card.addEventListener('click', function(e) {
                    this.style.opacity = '0.7';
                    setTimeout(() => {
                        this.style.opacity = '1';
                    }, 500);
                });
            });
            
            // Click on activity cards to go to attendance management
            document.querySelectorAll('.activity-card').forEach(card => {
                card.addEventListener('click', function() {
                    window.location.href = 'manage_attendance.php';
                });
            });
            
            // Smooth scroll for activity feed
            const activityFeed = document.querySelector('.activity-feed');
            if (activityFeed) {
                activityFeed.addEventListener('wheel', function(e) {
                    if (e.deltaY > 0) {
                        this.scrollTop += 50;
                    } else {
                        this.scrollTop -= 50;
                    }
                });
            }
        });
    </script>
</body>
</html>
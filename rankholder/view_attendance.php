<?php
// rankholder/view_attendance.php - SIMPLIFIED VERSION (ENGLISH)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

// Check permission - MUST BE rankholder
RBAC::checkPermission('rankholder');

try {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    $db = new Database();
    
    if (!$user || $user['role'] !== 'rankholder') {
        header("Location: ../index.php");
        exit();
    }
    
    $service_type = $user['service_type'] ?? null;
    $rankholder_id = $user['user_id'];
    
    // Get filter parameters - DEFAULT: ALL
    $date = $_GET['date'] ?? date('Y-m-d');
    $status = $_GET['status'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $session_filter = $_GET['session'] ?? 'all';
    $service_filter = $_GET['service'] ?? 'all';
    $rank_filter = $_GET['rank'] ?? 'all';
    
    // Build query for attendance records
    $query = "SELECT 
                a.attendance_id,
                a.date,
                a.status,
                a.recorded_at,
                a.reason,
                a.absent_type,
                u.user_id,
                u.military_number,
                u.name as cadet_name,
                u.rank_level,
                u.service_type,
                ts.training_type,
                ts.location,
                ts.session_time,
                ts.training_date
            FROM attendance a
            JOIN users u ON a.user_id = u.user_id
            JOIN training_sessions ts ON a.session_id = ts.session_id
            WHERE a.checked_by = ?";
    
    $params = [$rankholder_id];
    $types = "i";
    
    // Add date filter
    if (!empty($date)) {
        $query .= " AND DATE(a.date) = ?";
        $params[] = $date;
        $types .= "s";
    }
    
    // Add status filter
    if ($status !== 'all') {
        $query .= " AND a.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    // Add service filter
    if ($service_filter !== 'all') {
        $query .= " AND u.service_type = ?";
        $params[] = $service_filter;
        $types .= "s";
    }
    
    // Add rank filter
    if ($rank_filter !== 'all') {
        $query .= " AND u.rank_level = ?";
        $params[] = $rank_filter;
        $types .= "s";
    }
    
    // Add search filter
    if (!empty($search)) {
        $query .= " AND (u.military_number LIKE ? OR u.name LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "ss";
    }
    
    // Add session filter
    if ($session_filter !== 'all') {
        $query .= " AND ts.session_time = ?";
        $params[] = $session_filter;
        $types .= "s";
    }
    
    // Order by name ASC
    $query .= " ORDER BY u.name ASC LIMIT 200";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $attendanceResult = $stmt->get_result();
    $totalRecords = $attendanceResult->num_rows;
    
    // Get attendance summary
    $summaryQuery = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused
                FROM attendance a
                JOIN users u ON a.user_id = u.user_id
                WHERE a.checked_by = ?
                AND DATE(a.date) = ?";
    
    $summaryParams = [$rankholder_id, $date];
    
    // Add service and rank filters to summary if active
    if ($service_filter !== 'all') {
        $summaryQuery .= " AND u.service_type = ?";
        $summaryParams[] = $service_filter;
    }
    
    if ($rank_filter !== 'all') {
        $summaryQuery .= " AND u.rank_level = ?";
        $summaryParams[] = $rank_filter;
    }
    
    $summaryStmt = $db->prepare($summaryQuery);
    
    if (count($summaryParams) == 2) {
        $summaryStmt->bind_param("is", ...$summaryParams);
    } elseif (count($summaryParams) == 3) {
        $summaryStmt->bind_param("iss", ...$summaryParams);
    } else {
        $summaryStmt->bind_param("isss", ...$summaryParams);
    }
    
    $summaryStmt->execute();
    $summaryResult = $summaryStmt->get_result();
    $summary = $summaryResult->fetch_assoc();
    
    // Get dates for filter (last 30 days)
    $datesQuery = "SELECT DISTINCT DATE(date) as date FROM attendance 
                  WHERE checked_by = ? 
                  ORDER BY date DESC LIMIT 30";
    $datesStmt = $db->prepare($datesQuery);
    $datesStmt->bind_param("i", $rankholder_id);
    $datesStmt->execute();
    $datesResult = $datesStmt->get_result();
    
    // Get sessions for filter
    $sessionsQuery = "SELECT DISTINCT ts.session_time 
                     FROM attendance a
                     JOIN training_sessions ts ON a.session_id = ts.session_id
                     WHERE a.checked_by = ?
                     AND DATE(a.date) = ?
                     ORDER BY ts.session_time";
    $sessionsStmt = $db->prepare($sessionsQuery);
    $sessionsStmt->bind_param("is", $rankholder_id, $date);
    $sessionsStmt->execute();
    $sessionsResult = $sessionsStmt->get_result();
    
    // Get service types for filter
    $servicesQuery = "SELECT DISTINCT u.service_type 
                     FROM attendance a
                     JOIN users u ON a.user_id = u.user_id
                     WHERE a.checked_by = ?
                     AND DATE(a.date) = ?
                     AND u.service_type IS NOT NULL
                     ORDER BY u.service_type";
    $servicesStmt = $db->prepare($servicesQuery);
    $servicesStmt->bind_param("is", $rankholder_id, $date);
    $servicesStmt->execute();
    $servicesResult = $servicesStmt->get_result();
    
    // Get rank levels for filter
    $ranksQuery = "SELECT DISTINCT u.rank_level 
                  FROM attendance a
                  JOIN users u ON a.user_id = u.user_id
                  WHERE a.checked_by = ?
                  AND DATE(a.date) = ?
                  AND u.rank_level IS NOT NULL
                  ORDER BY u.rank_level";
    $ranksStmt = $db->prepare($ranksQuery);
    $ranksStmt->bind_param("is", $rankholder_id, $date);
    $ranksStmt->execute();
    $ranksResult = $ranksStmt->get_result();
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Helper functions
$sessionTimeLabels = [
    'pagi' => 'Morning',
    'tengah hari' => 'Afternoon',
    'petang' => 'Evening',
    'malam' => 'Night'
];

function getServiceLabel($type) {
    $labels = [
        'darat' => 'Army',
        'laut' => 'Navy', 
        'udara' => 'Air Force'
    ];
    return $labels[$type] ?? $type;
}

function getServiceBadge($type) {
    switch($type) {
        case 'darat': return 'service-badge-darat';
        case 'laut': return 'service-badge-laut';
        case 'udara': return 'service-badge-udara';
        default: return 'service-badge-default';
    }
}

function getSessionTimeLabel($time) {
    global $sessionTimeLabels;
    return $sessionTimeLabels[$time] ?? ucfirst($time);
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Attendance - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --success: #48bb78;
            --warning: #ed8936;
            --danger: #f56565;
            --purple: #9f7aea;
            --darat: #38a169;
            --laut: #3182ce;
            --udara: #9f7aea;
            --light: #f7fafc;
            --gray-100: #f7fafc;
            --gray-200: #edf2f7;
            --gray-300: #e2e8f0;
            --gray-400: #cbd5e0;
            --gray-500: #a0aec0;
            --gray-600: #718096;
            --gray-700: #4a5568;
            --gray-800: #2d3748;
            --gray-900: #1a202c;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        
        body {
            background: var(--gray-100);
            color: var(--gray-800);
            min-height: 100vh;
            padding-bottom: 60px;
        }
        
        .container {
            max-width: 100%;
            padding: 15px;
        }
        
        /* HEADER */
        .main-header {
            background: linear-gradient(135deg, var(--primary) 0%, #2c5282 100%);
            color: white;
            padding: 20px 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
        }
        
        .header-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        /* STATS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-top: 15px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: 8px;
            padding: 12px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 3px;
        }
        
        .stat-label {
            font-size: 0.75rem;
            opacity: 0.9;
            font-weight: 600;
        }
        
        /* FILTER SECTION */
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }
        
        @media (min-width: 768px) {
            .filter-form {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 1024px) {
            .filter-form {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        .filter-row {
            display: contents;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-label {
            margin-bottom: 6px;
            color: var(--secondary);
            font-weight: 500;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .filter-select, .filter-input {
            padding: 12px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 0.95rem;
            width: 100%;
            background: white;
            cursor: pointer;
        }
        
        .filter-input:focus, .filter-select:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        .search-group {
            grid-column: 1 / -1;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            grid-column: 1 / -1;
            margin-top: 10px;
        }
        
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex: 1;
        }
        
        .btn-primary {
            background: var(--accent);
            color: white;
        }
        
        .btn-secondary {
            background: var(--gray-300);
            color: var(--secondary);
        }
        
        /* ATTENDANCE TABLE */
        .attendance-section {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
        }
        
        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }
        
        th {
            background: var(--gray-100);
            color: var(--primary);
            font-weight: 600;
            text-align: left;
            padding: 12px 15px;
            border-bottom: 2px solid var(--gray-300);
            white-space: nowrap;
        }
        
        td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--gray-200);
            vertical-align: middle;
        }
        
        tr:hover {
            background: var(--gray-100);
        }
        
        /* CADET INFO */
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
            flex-shrink: 0;
        }
        
        .cadet-details h4 {
            color: var(--primary);
            margin-bottom: 2px;
            font-size: 0.95rem;
        }
        
        .cadet-details p {
            color: var(--gray-600);
            font-size: 0.85rem;
        }
        
        /* SERVICE BADGES */
        .service-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-right: 6px;
        }
        
        .service-badge-darat {
            background: rgba(56, 161, 105, 0.1);
            color: var(--darat);
        }
        
        .service-badge-laut {
            background: rgba(49, 130, 206, 0.1);
            color: var(--laut);
        }
        
        .service-badge-udara {
            background: rgba(159, 122, 234, 0.1);
            color: var(--udara);
        }
        
        /* STATUS BADGES */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-present { 
            background: rgba(72, 187, 120, 0.1); 
            color: var(--success);
            border: 1px solid rgba(72, 187, 120, 0.3);
        }
        
        .status-absent { 
            background: rgba(245, 101, 101, 0.1); 
            color: var(--danger);
            border: 1px solid rgba(245, 101, 101, 0.3);
        }
        
        .status-excused {
            background: rgba(159, 122, 234, 0.1);
            color: var(--purple);
            border: 1px solid rgba(159, 122, 234, 0.3);
        }
        
        /* SESSION BADGE */
        .session-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            background: rgba(49, 130, 206, 0.1);
            color: var(--accent);
        }
        
        /* REASON TEXT */
        .reason-text {
            font-size: 0.8rem;
            color: var(--gray-600);
            margin-top: 5px;
            padding: 5px;
            background: var(--gray-100);
            border-radius: 4px;
            border-left: 3px solid var(--warning);
        }
        
        .absent-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .absent-type-sakit {
            background: rgba(246, 173, 85, 0.1);
            color: #d69e2e;
        }
        
        .absent-type-excuse {
            background: rgba(104, 211, 145, 0.1);
            color: #38a169;
        }
        
        /* NO DATA */
        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-500);
        }
        
        .no-data i {
            font-size: 3rem;
            margin-bottom: 15px;
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
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .mobile-nav-item {
            color: white;
            text-decoration: none;
            text-align: center;
            flex: 1;
            padding: 8px 5px;
            border-radius: 6px;
            transition: background 0.2s;
        }
        
        .mobile-nav-item.active {
            background: rgba(255, 255, 255, 0.15);
        }
        
        .mobile-nav-icon {
            font-size: 1.2rem;
            margin-bottom: 3px;
            display: block;
        }
        
        .mobile-nav-label {
            font-size: 0.7rem;
            opacity: 0.9;
            display: block;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            table {
                min-width: 600px;
            }
            
            th, td {
                padding: 8px 10px;
                font-size: 0.85rem;
            }
            
            .cadet-avatar {
                width: 30px;
                height: 30px;
                font-size: 0.8rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        /* CARD STYLE FOR MOBILE */
        .attendance-card {
            display: none;
        }
        
        @media (max-width: 767px) {
            .table-responsive {
                display: none;
            }
            
            .attendance-card {
                display: block;
            }
            
            .attendance-item {
                background: var(--gray-100);
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 10px;
                border-left: 4px solid var(--accent);
            }
            
            .card-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 10px;
            }
            
            .card-avatar {
                width: 40px;
                height: 40px;
                background: var(--accent);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-weight: 600;
                font-size: 1rem;
            }
            
            .card-info h4 {
                color: var(--primary);
                margin-bottom: 2px;
                font-size: 1rem;
            }
            
            .card-info p {
                color: var(--gray-600);
                font-size: 0.85rem;
            }
            
            .card-details {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                margin-bottom: 10px;
            }
            
            .detail-item {
                font-size: 0.85rem;
            }
            
            .detail-label {
                color: var(--gray-500);
                font-size: 0.75rem;
                margin-bottom: 2px;
            }
            
            .detail-value {
                color: var(--gray-800);
                font-weight: 500;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="main-header">
            <div class="header-title">
                <h1><i class="fas fa-clipboard-list"></i> View Attendance</h1>
                <p style="opacity: 0.9; font-size: 0.9rem;">All attendance records that have been recorded</p>
            </div>
            
            <!-- STATS -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $totalRecords; ?></div>
                    <div class="stat-label">TOTAL</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $summary['present'] ?? 0; ?></div>
                    <div class="stat-label">PRESENT</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $summary['absent'] ?? 0; ?></div>
                    <div class="stat-label">ABSENT</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $summary['excused'] ?? 0; ?></div>
                    <div class="stat-label">EXCUSED</div>
                </div>
            </div>
        </div>
        
        <!-- FILTER SECTION -->
        <div class="filter-section">
            <form method="GET" action="" class="filter-form">
                <!-- Row 1: Date & Status -->
                <div class="filter-group">
                    <label class="filter-label"><i class="fas fa-calendar"></i> Date</label>
                    <input type="date" 
                           name="date" 
                           class="filter-input" 
                           value="<?php echo $date; ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="filter-group">
                    <label class="filter-label"><i class="fas fa-filter"></i> Status</label>
                    <select name="status" class="filter-select">
                        <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="present" <?php echo $status == 'present' ? 'selected' : ''; ?>>Present</option>
                        <option value="absent" <?php echo $status == 'absent' ? 'selected' : ''; ?>>Absent</option>
                        <option value="excused" <?php echo $status == 'excused' ? 'selected' : ''; ?>>Excused</option>
                    </select>
                </div>
                
                <!-- Row 2: Service & Rank -->
                <div class="filter-group">
                    <label class="filter-label"><i class="fas fa-users"></i> Service</label>
                    <select name="service" class="filter-select">
                        <option value="all" <?php echo $service_filter == 'all' ? 'selected' : ''; ?>>All Services</option>
                        <?php 
                        $servicesResult->data_seek(0);
                        while($service = $servicesResult->fetch_assoc()): ?>
                            <option value="<?php echo $service['service_type']; ?>" 
                                <?php echo $service_filter == $service['service_type'] ? 'selected' : ''; ?>>
                                <?php echo getServiceLabel($service['service_type']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label"><i class="fas fa-star"></i> Rank</label>
                    <select name="rank" class="filter-select">
                        <option value="all" <?php echo $rank_filter == 'all' ? 'selected' : ''; ?>>All Ranks</option>
                        <option value="Junior" <?php echo $rank_filter == 'Junior' ? 'selected' : ''; ?>>Junior</option>
                        <option value="Intermediate" <?php echo $rank_filter == 'Intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                        <option value="Senior" <?php echo $rank_filter == 'Senior' ? 'selected' : ''; ?>>Senior</option>
                    </select>
                </div>
                
                <!-- Row 3: Session & Search -->
                <div class="filter-group">
                    <label class="filter-label"><i class="fas fa-clock"></i> Session</label>
                    <select name="session" class="filter-select">
                        <option value="all" <?php echo $session_filter == 'all' ? 'selected' : ''; ?>>All Sessions</option>
                        <?php 
                        $sessionsResult->data_seek(0);
                        while($session = $sessionsResult->fetch_assoc()): ?>
                            <option value="<?php echo $session['session_time']; ?>" 
                                <?php echo $session_filter == $session['session_time'] ? 'selected' : ''; ?>>
                                <?php echo getSessionTimeLabel($session['session_time']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="filter-group search-group">
                    <label class="filter-label"><i class="fas fa-search"></i> Search Cadet</label>
                    <input type="text" 
                           name="search" 
                           class="filter-input" 
                           placeholder="Name or Military Number..."
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <!-- Action Buttons -->
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="view_attendance.php" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- ATTENDANCE TABLE -->
        <div class="attendance-section">
            <div class="section-title">
                <i class="fas fa-list"></i> Attendance List
                <span style="font-size: 0.9rem; color: var(--gray-500); margin-left: 6px;">
                    (<?php echo $totalRecords; ?> records)
                </span>
            </div>
            
            <!-- Desktop Table -->
            <div class="table-responsive">
                <?php if ($totalRecords > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>CADET</th>
                            <th>SERVICE & RANK</th>
                            <th>TRAINING SESSION</th>
                            <th>DATE/TIME RECORDED</th>
                            <th>STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $attendanceResult->fetch_assoc()): 
                            $statusClass = '';
                            $statusLabel = '';
                            $absentTypeBadge = '';
                            
                            if ($row['status'] == 'present') {
                                $statusClass = 'status-present';
                                $statusLabel = 'PRESENT';
                            } elseif ($row['status'] == 'absent') {
                                $statusClass = 'status-absent';
                                $statusLabel = 'ABSENT';
                                
                                if ($row['absent_type'] == 'cuti') {
                                    $absentTypeBadge = '<span class="absent-type absent-type-sakit">C (Sick Leave)</span>';
                                } elseif ($row['absent_type'] == 'excuse') {
                                    $absentTypeBadge = '<span class="absent-type absent-type-excuse">Excuse</span>';
                                }
                            } elseif ($row['status'] == 'excused') {
                                $statusClass = 'status-excused';
                                $statusLabel = 'EXCUSED';
                            }
                        ?>
                        <tr>
                            <td>
                                <div class="cadet-info">
                                    <div class="cadet-avatar" style="
                                        <?php 
                                        if ($row['status'] == 'present') echo 'background: var(--success);';
                                        elseif ($row['status'] == 'absent') echo 'background: var(--danger);';
                                        else echo 'background: var(--purple);';
                                        ?>">
                                        <?php echo strtoupper(substr($row['cadet_name'], 0, 1)); ?>
                                    </div>
                                    <div class="cadet-details">
                                        <h4><?php echo htmlspecialchars($row['cadet_name']); ?></h4>
                                        <p><?php echo $row['military_number']; ?></p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <span class="service-badge <?php echo getServiceBadge($row['service_type']); ?>">
                                        <?php echo getServiceLabel($row['service_type']); ?>
                                    </span>
                                    <span style="font-size: 0.8rem; color: var(--gray-600); margin-left: 5px;">
                                        <?php echo $row['rank_level']; ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($row['training_type']); ?></strong>
                                    <div style="font-size: 0.85rem; color: var(--gray-600); margin-top: 5px;">
                                        <div><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($row['location']); ?></div>
                                        <div style="margin-top: 3px;">
                                            <span class="session-badge">
                                                <?php echo getSessionTimeLabel($row['session_time']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <strong><?php echo date('d/m/Y', strtotime($row['date'])); ?></strong>
                                    <div style="font-size: 0.85rem; color: var(--gray-600);">
                                        <i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($row['recorded_at'])); ?>
                                    </div>
                                    <?php if (!empty($row['reason'])): ?>
                                    <div class="reason-text">
                                        <i class="fas fa-sticky-note"></i> <?php echo htmlspecialchars($row['reason']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php echo $statusLabel; ?>
                                </span>
                                <?php echo $absentTypeBadge; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>No Attendance Records</h3>
                    <p>No attendance records found for the selected date and filters.</p>
                    <a href="take_attendance.php" class="btn" style="
                        background: var(--accent); 
                        color: white; 
                        margin-top: 15px; 
                        display: inline-block;
                        text-decoration: none;
                        padding: 10px 20px;
                        border-radius: 8px;">
                        <i class="fas fa-plus-circle"></i> Take Attendance
                    </a>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Mobile Card View -->
            <div class="attendance-card">
                <?php if ($totalRecords > 0): 
                    // Reset pointer for second loop
                    $attendanceResult->data_seek(0);
                    while($row = $attendanceResult->fetch_assoc()): 
                        $statusClass = '';
                        $statusLabel = '';
                        $absentTypeBadge = '';
                        
                        if ($row['status'] == 'present') {
                            $statusClass = 'status-present';
                            $statusLabel = 'PRESENT';
                        } elseif ($row['status'] == 'absent') {
                            $statusClass = 'status-absent';
                            $statusLabel = 'ABSENT';
                            
                            if ($row['absent_type'] == 'cuti') {
                                $absentTypeBadge = '<span class="absent-type absent-type-sakit">C (Sick Leave)</span>';
                            } elseif ($row['absent_type'] == 'excuse') {
                                $absentTypeBadge = '<span class="absent-type absent-type-excuse">Excuse</span>';
                            }
                        } elseif ($row['status'] == 'excused') {
                            $statusClass = 'status-excused';
                            $statusLabel = 'EXCUSED';
                        }
                ?>
                <div class="attendance-item">
                    <div class="card-header">
                        <div class="card-avatar" style="
                            <?php 
                            if ($row['status'] == 'present') echo 'background: var(--success);';
                            elseif ($row['status'] == 'absent') echo 'background: var(--danger);';
                            else echo 'background: var(--purple);';
                            ?>">
                            <?php echo strtoupper(substr($row['cadet_name'], 0, 1)); ?>
                        </div>
                        <div class="card-info">
                            <h4><?php echo htmlspecialchars($row['cadet_name']); ?></h4>
                            <p><?php echo $row['military_number']; ?></p>
                            <span class="service-badge <?php echo getServiceBadge($row['service_type']); ?>">
                                <?php echo getServiceLabel($row['service_type']); ?>
                            </span>
                            <span style="font-size: 0.75rem; color: var(--gray-600);">
                                <?php echo $row['rank_level']; ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="card-details">
                        <div class="detail-item">
                            <div class="detail-label">Training</div>
                            <div class="detail-value"><?php echo htmlspecialchars($row['training_type']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Session</div>
                            <div class="detail-value"><?php echo getSessionTimeLabel($row['session_time']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Date</div>
                            <div class="detail-value"><?php echo date('d/m/Y', strtotime($row['date'])); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Time</div>
                            <div class="detail-value"><?php echo date('h:i A', strtotime($row['recorded_at'])); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Location</div>
                            <div class="detail-value"><?php echo htmlspecialchars($row['location']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Status</div>
                            <div class="detail-value">
                                <span class="status-badge <?php echo $statusClass; ?>" style="font-size: 0.75rem;">
                                    <?php echo $statusLabel; ?>
                                </span>
                                <?php echo $absentTypeBadge; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($row['reason'])): ?>
                    <div class="reason-text" style="margin-top: 10px;">
                        <i class="fas fa-sticky-note"></i> <?php echo htmlspecialchars($row['reason']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>No Attendance Records</h3>
                    <p>No attendance records found for the selected date and filters.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- MOBILE NAVIGATION -->
    <nav class="mobile-nav">
        <a href="dashboard.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-home"></i>
            </div>
            <div class="mobile-nav-label">Dashboard</div>
        </a>
        
        <a href="take_attendance.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-clipboard-check"></i>
            </div>
            <div class="mobile-nav-label">Take</div>
        </a>
        
        <a href="view_attendance.php" class="mobile-nav-item active">
            <div class="mobile-nav-icon">
                <i class="fas fa-list"></i>
            </div>
            <div class="mobile-nav-label">View</div>
        </a>
    </nav>
    
    <script>
        // Set filter date to today if empty
        document.addEventListener('DOMContentLoaded', function() {
            const filterDate = document.querySelector('input[name="date"]');
            if (!filterDate.value) {
                filterDate.value = new Date().toISOString().split('T')[0];
            }
            
            // Auto-submit on filter change
            const filterSelects = document.querySelectorAll('.filter-select');
            const filterDateInput = document.querySelector('input[name="date"]');
            
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
            
            filterDateInput.addEventListener('change', function() {
                this.form.submit();
            });
        });
        
        // Auto-refresh every 60 seconds
        setInterval(() => {
            location.reload();
        }, 60000);
    </script>
</body>
</html>
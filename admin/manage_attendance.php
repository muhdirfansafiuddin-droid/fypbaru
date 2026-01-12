<?php
// admin/manage_attendance.php - FIXED VERSION (NO VERIFY FUNCTION)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('admin');
$auth = new Auth();
$user = $auth->getCurrentUser();
$db = new Database();

$message = '';
$messageType = '';

// Get filter parameters with sorting
$filter_service = $_GET['service_type'] ?? '';
$filter_rank = $_GET['rank'] ?? '';
$date_filter_type = $_GET['date_filter'] ?? 'specific'; // 'all', 'month', 'specific'
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_training = $_GET['training'] ?? '';
$filter_status = $_GET['status'] ?? 'all';
$sort_by = $_GET['sort'] ?? 'date_desc';

// Handle attendance status update by admin (EDIT STATUS ONLY)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $attendance_id = intval($_POST['attendance_id']);
    $status = $_POST['status'];
    
    $updateSql = "UPDATE attendance SET status = ? WHERE attendance_id = ?";
    $updateStmt = $db->prepare($updateSql);
    $updateStmt->bind_param("si", $status, $attendance_id);
    
    if ($updateStmt->execute()) {
        $message = 'Attendance status successfully updated!';
        $messageType = 'success';
        
        $logQuery = "INSERT INTO activity_logs (user_id, activity_type, description, related_id) 
                    VALUES (?, 'edit_attendance', 'Admin edited attendance status to $status', ?)";
        $logStmt = $db->prepare($logQuery);
        $logStmt->bind_param("ii", $user['user_id'], $attendance_id);
        $logStmt->execute();
    } else {
        $message = 'Database error: ' . $updateStmt->error;
        $messageType = 'error';
    }
}

// Handle export functionality
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_export_' . date('Y-m-d_H-i-s') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'ID', 'Military Number', 'Name', 'Service', 'Rank', 
        'Training Type', 'Location', 'Session', 'Date', 'Status', 
        'Reason', 'Recorded By', 'Recorded At'
    ]);
    
    $exportSql = "SELECT 
                    a.attendance_id,
                    a.user_id,
                    a.status,
                    a.reason,
                    a.recorded_at,
                    u.military_number,
                    u.name, 
                    u.service_type,
                    u.rank_level,
                    ts.location,
                    ts.training_type,
                    ts.session_time,
                    ts.training_date,
                    checked_by_user.name as checked_by_name
                FROM attendance a 
                JOIN users u ON a.user_id = u.user_id 
                JOIN training_sessions ts ON a.session_id = ts.session_id
                LEFT JOIN users checked_by_user ON a.checked_by = checked_by_user.user_id
                WHERE 1=1";
    
    $exportParams = [];
    $exportTypes = "";
    
    // Apply date filtering
    if ($date_filter_type === 'specific') {
        $exportSql .= " AND DATE(a.date) = ?";
        $exportParams[] = $filter_date;
        $exportTypes .= "s";
    } elseif ($date_filter_type === 'month') {
        $exportSql .= " AND DATE_FORMAT(a.date, '%Y-%m') = ?";
        $exportParams[] = $filter_month;
        $exportTypes .= "s";
    }
    
    if (!empty($filter_service)) {
        $exportSql .= " AND u.service_type = ?";
        $exportParams[] = $filter_service;
        $exportTypes .= "s";
    }
    
    if (!empty($filter_rank)) {
        $exportSql .= " AND u.rank_level = ?";
        $exportParams[] = $filter_rank;
        $exportTypes .= "s";
    }
    
    if (!empty($filter_training)) {
        $exportSql .= " AND ts.training_type = ?";
        $exportParams[] = $filter_training;
        $exportTypes .= "s";
    }
    
    if ($filter_status !== 'all') {
        $exportSql .= " AND a.status = ?";
        $exportParams[] = $filter_status;
        $exportTypes .= "s";
    }
    
    $exportSql .= " ORDER BY u.name ASC, a.date DESC";
    
    $exportStmt = $db->prepare($exportSql);
    if ($exportParams) {
        $exportStmt->bind_param($exportTypes, ...$exportParams);
    }
    $exportStmt->execute();
    $exportResult = $exportStmt->get_result();
    
    while ($row = $exportResult->fetch_assoc()) {
        fputcsv($output, [
            $row['attendance_id'],
            $row['military_number'],
            $row['name'],
            $serviceLabels[$row['service_type']] ?? $row['service_type'],
            $row['rank_level'],
            $row['training_type'],
            $row['location'],
            $row['session_time'],
            date('d/m/Y', strtotime($row['training_date'])),
            $row['status'],
            $row['reason'] ?? '',
            $row['checked_by_name'] ?? 'System',
            date('H:i', strtotime($row['recorded_at']))
        ]);
    }
    
    fclose($output);
    exit();
}

// Build query for attendance records
$sql = "SELECT 
            a.attendance_id,
            a.user_id,
            a.session_id,
            a.date,
            a.status,
            a.reason,
            a.checked_by,
            a.recorded_at,
            u.military_number,
            u.name, 
            u.service_type,
            u.rank_level,
            ts.location,
            ts.training_type,
            ts.session_time,
            ts.training_date,
            checked_by_user.name as checked_by_name
        FROM attendance a 
        JOIN users u ON a.user_id = u.user_id 
        JOIN training_sessions ts ON a.session_id = ts.session_id
        LEFT JOIN users checked_by_user ON a.checked_by = checked_by_user.user_id
        WHERE 1=1";
    
$params = [];
$types = "";

// Apply date filtering
if ($date_filter_type === 'specific') {
    $sql .= " AND DATE(a.date) = ?";
    $params[] = $filter_date;
    $types .= "s";
} elseif ($date_filter_type === 'month') {
    $sql .= " AND DATE_FORMAT(a.date, '%Y-%m') = ?";
    $params[] = $filter_month;
    $types .= "s";
}

// Filter service
if (!empty($filter_service)) {
    $sql .= " AND u.service_type = ?";
    $params[] = $filter_service;
    $types .= "s";
}

// Filter rank
if (!empty($filter_rank)) {
    $sql .= " AND u.rank_level = ?";
    $params[] = $filter_rank;
    $types .= "s";
}

// Filter training type
if (!empty($filter_training)) {
    $sql .= " AND ts.training_type = ?";
    $params[] = $filter_training;
    $types .= "s";
}

// Filter attendance status
if ($filter_status !== 'all') {
    $sql .= " AND a.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

// Add sorting based on selection
switch ($sort_by) {
    case 'service_asc':
        $sql .= " ORDER BY u.service_type ASC, u.name ASC";
        break;
    case 'service_desc':
        $sql .= " ORDER BY u.service_type DESC, u.name ASC";
        break;
    case 'rank_asc':
        $sql .= " ORDER BY u.rank_level ASC, u.name ASC";
        break;
    case 'rank_desc':
        $sql .= " ORDER BY u.rank_level DESC, u.name ASC";
        break;
    case 'training_asc':
        $sql .= " ORDER BY ts.training_type ASC, u.name ASC";
        break;
    case 'training_desc':
        $sql .= " ORDER BY ts.training_type DESC, u.name ASC";
        break;
    case 'name_asc':
        $sql .= " ORDER BY u.name ASC";
        break;
    case 'name_desc':
        $sql .= " ORDER BY u.name DESC";
        break;
    case 'status_asc':
        $sql .= " ORDER BY a.status ASC, u.name ASC";
        break;
    case 'status_desc':
        $sql .= " ORDER BY a.status DESC, u.name ASC";
        break;
    case 'date_asc':
        $sql .= " ORDER BY a.date ASC, u.name ASC";
        break;
    case 'date_desc':
    default:
        $sql .= " ORDER BY a.date DESC, u.name ASC";
        break;
}

$stmt = $db->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$attendance_records = $result->fetch_all(MYSQLI_ASSOC);

// Get statistics
$statsSql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent
            FROM attendance a 
            JOIN users u ON a.user_id = u.user_id
            JOIN training_sessions ts ON a.session_id = ts.session_id
            WHERE 1=1";
            
$statsParams = [];
$statsTypes = "";

if ($date_filter_type === 'specific') {
    $statsSql .= " AND DATE(a.date) = ?";
    $statsParams[] = $filter_date;
    $statsTypes .= "s";
} elseif ($date_filter_type === 'month') {
    $statsSql .= " AND DATE_FORMAT(a.date, '%Y-%m') = ?";
    $statsParams[] = $filter_month;
    $statsTypes .= "s";
}

if (!empty($filter_service)) {
    $statsSql .= " AND u.service_type = ?";
    $statsParams[] = $filter_service;
    $statsTypes .= "s";
}

if (!empty($filter_rank)) {
    $statsSql .= " AND u.rank_level = ?";
    $statsParams[] = $filter_rank;
    $statsTypes .= "s";
}

if (!empty($filter_training)) {
    $statsSql .= " AND ts.training_type = ?";
    $statsParams[] = $filter_training;
    $statsTypes .= "s";
}

if ($filter_status !== 'all') {
    $statsSql .= " AND a.status = ?";
    $statsParams[] = $filter_status;
    $statsTypes .= "s";
}

$statsStmt = $db->prepare($statsSql);
if ($statsParams) {
    $statsStmt->bind_param($statsTypes, ...$statsParams);
}
$statsStmt->execute();
$statsResult = $statsStmt->get_result();
$stats = $statsResult->fetch_assoc();

// Get distinct service types
$serviceStmt = $db->query("SELECT DISTINCT service_type FROM users WHERE service_type IS NOT NULL ORDER BY service_type");
$serviceTypes = $serviceStmt->fetch_all(MYSQLI_ASSOC);

// Get distinct rank levels
$rankStmt = $db->query("SELECT DISTINCT rank_level FROM users WHERE rank_level IS NOT NULL ORDER BY rank_level");
$rankLevels = $rankStmt->fetch_all(MYSQLI_ASSOC);

// Get distinct training types
$trainingStmt = $db->query("SELECT DISTINCT training_type FROM training_sessions WHERE training_type IS NOT NULL ORDER BY training_type");
$trainingTypes = $trainingStmt->fetch_all(MYSQLI_ASSOC);

$statusLabels = [
    'present' => 'Present',
    'absent' => 'Absent'
];

$serviceLabels = [
    'darat' => '<span class="service-option service-army"><i class="fas fa-truck"></i> Army</span>',
    'laut' => '<span class="service-option service-navy"><i class="fas fa-ship"></i> Navy</span>',
    'udara' => '<span class="service-option service-airforce"><i class="fas fa-fighter-jet"></i> Air Force</span>'
];

$sessionLabels = [
    'pagi' => 'Morning',
    'tengah hari' => 'Midday',
    'petang' => 'Evening',
    'malam' => 'Night'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management - CAAMS</title>
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
            --navy: #2c5282;
            --army-green: #276749;
            --airforce-blue: #2b6cb0;
            --export: #805ad5;
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
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        /* HEADER */
        .header {
            background: var(--primary);
            color: white;
            padding: 25px 30px;
        }
        
        .back-btn {
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            padding: 8px 15px;
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            background: rgba(255,255,255,0.2);
            transform: translateX(-5px);
        }
        
        .header h1 {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }
        
        /* MAIN CONTENT */
        .content {
            padding: 30px;
        }
        
        .section-title {
            color: var(--primary);
            font-size: 1.5rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid var(--accent);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* DASHBOARD STATS */
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            text-align: center;
            border-top: 5px solid var(--accent);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.total { border-top-color: var(--primary); }
        .stat-card.present { border-top-color: var(--success); }
        .stat-card.absent { border-top-color: var(--danger); }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        
        .stat-number {
            font-size: 2.2rem;
            font-weight: bold;
            margin: 10px 0;
            color: var(--primary);
        }
        
        .stat-label {
            color: var(--secondary);
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* FILTER SECTION */
        .filter-section {
            background: #f7fafc;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        input, select {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        input:focus, select:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        /* Custom styled options for service filter */
        .service-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 0;
        }
        
        .service-army {
            color: var(--army-green);
        }
        
        .service-navy {
            color: var(--navy);
        }
        
        .service-airforce {
            color: var(--airforce-blue);
        }
        
        .filter-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-primary {
            background: var(--accent);
            color: white;
        }
        
        .btn-primary:hover {
            background: #2c5282;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .btn-secondary {
            background: #edf2f7;
            color: var(--secondary);
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .btn-export {
            background: var(--export);
            color: white;
        }
        
        .btn-export:hover {
            background: #6b46c1;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        /* DATE FILTER ROW */
        .date-filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }
        
        /* ATTENDANCE TABLE */
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .attendance-table th {
            background: var(--primary);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .attendance-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .attendance-table tr:hover {
            background: #f7fafc;
        }
        
        /* CADET INFO */
        .cadet-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .cadet-avatar {
            width: 40px;
            height: 40px;
            background: var(--accent);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
        }
        
        .cadet-name {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 2px;
        }
        
        .cadet-number {
            font-size: 0.9rem;
            color: var(--secondary);
        }
        
        /* SERVICE BADGES */
        .service-badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-army { 
            background: #c6f6d5; 
            color: var(--army-green);
        }
        
        .badge-navy { 
            background: #bee3f8; 
            color: var(--navy);
        }
        
        .badge-airforce { 
            background: #e9d8fd; 
            color: var(--airforce-blue);
        }
        
        /* STATUS BADGES */
        .status-badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-present { 
            background: #c6f6d5; 
            color: var(--success);
        }
        
        .status-absent { 
            background: #fed7d7; 
            color: var(--danger);
        }
        
        /* ACTION BUTTONS */
        .action-btns {
            display: flex;
            gap: 8px;
        }
        
        .btn-small {
            padding: 8px 16px;
            font-size: 0.9rem;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        
        .btn-small:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        /* ALERTS */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success);
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger);
        }
        
        /* EMPTY STATE */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--secondary);
        }
        
        .empty-state i {
            font-size: 3rem;
            opacity: 0.3;
            margin-bottom: 15px;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .attendance-table {
                display: block;
                overflow-x: auto;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .date-filter-row {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .dashboard-stats {
                grid-template-columns: 1fr;
            }
            
            .action-btns {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .container {
                border-radius: 10px;
            }
            
            .header, .content {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 1.4rem;
            }
            
            .section-title {
                font-size: 1.2rem;
            }
            
            .stat-card {
                padding: 15px;
            }
            
            .stat-number {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <h1>
                <i class="fas fa-clipboard-check"></i> Attendance Management
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">Edit cadet attendance status - Present or Absent only</p>
        </div>
        
        <div class="content">
            <!-- ALERTS -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType == 'success' ? 'success' : 'error'; ?>">
                    <i class="fas <?php echo $messageType == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                    <div><?php echo $message; ?></div>
                </div>
            <?php endif; ?>
            
            <!-- DASHBOARD STATS -->
            <div class="dashboard-stats">
                <div class="stat-card total">
                    <div class="stat-icon" style="color: var(--primary);">
                        <i class="fas fa-list"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="stat-label">Total Records</div>
                </div>
                
                <div class="stat-card present">
                    <div class="stat-icon" style="color: var(--success);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['present'] ?? 0; ?></div>
                    <div class="stat-label">Present</div>
                </div>
                
                <div class="stat-card absent">
                    <div class="stat-icon" style="color: var(--danger);">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['absent'] ?? 0; ?></div>
                    <div class="stat-label">Absent</div>
                </div>
            </div>
            
            <!-- FILTER SECTION -->
            <div class="filter-section">
                <div class="section-title">
                    <i class="fas fa-filter"></i> Attendance Filters
                </div>
                
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label>Service</label>
                            <select name="service_type" class="filter-select">
                                <option value="">All Services</option>
                                <?php foreach ($serviceTypes as $service): ?>
                                    <option value="<?php echo htmlspecialchars($service['service_type']); ?>" 
                                        <?php echo $filter_service == $service['service_type'] ? 'selected' : ''; ?>>
                                        <?php echo $serviceLabels[$service['service_type']] ?? ucfirst($service['service_type']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Rank</label>
                            <select name="rank" class="filter-select">
                                <option value="">All Ranks</option>
                                <?php foreach ($rankLevels as $rank): ?>
                                    <option value="<?php echo htmlspecialchars($rank['rank_level']); ?>" 
                                        <?php echo $filter_rank == $rank['rank_level'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($rank['rank_level']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Training Type</label>
                            <select name="training" class="filter-select">
                                <option value="">All Training</option>
                                <?php foreach ($trainingTypes as $training): ?>
                                    <option value="<?php echo htmlspecialchars($training['training_type']); ?>" 
                                        <?php echo $filter_training == $training['training_type'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($training['training_type']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Attendance Status</label>
                            <select name="status" class="filter-select">
                                <option value="all">All Status</option>
                                <option value="present" <?php echo $filter_status == 'present' ? 'selected' : ''; ?>>Present</option>
                                <option value="absent" <?php echo $filter_status == 'absent' ? 'selected' : ''; ?>>Absent</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- DATE FILTER ROW -->
                    <div class="date-filter-row">
                        <div class="filter-group">
                            <label>Date Filter Type</label>
                            <select name="date_filter" id="dateFilterType" onchange="toggleDateFields()">
                                <option value="all" <?php echo $date_filter_type == 'all' ? 'selected' : ''; ?>>All Dates</option>
                                <option value="month" <?php echo $date_filter_type == 'month' ? 'selected' : ''; ?>>By Month</option>
                                <option value="specific" <?php echo $date_filter_type == 'specific' ? 'selected' : ''; ?>>Specific Date</option>
                            </select>
                        </div>
                        
                        <div class="filter-group" id="monthField" style="<?php echo $date_filter_type != 'month' ? 'display: none;' : ''; ?>">
                            <label>Select Month</label>
                            <input type="month" name="month" 
                                   value="<?php echo htmlspecialchars($filter_month); ?>"
                                   max="<?php echo date('Y-m'); ?>">
                        </div>
                        
                        <div class="filter-group" id="dateField" style="<?php echo $date_filter_type != 'specific' ? 'display: none;' : ''; ?>">
                            <label>Select Date</label>
                            <input type="date" name="date" 
                                   value="<?php echo htmlspecialchars($filter_date); ?>"
                                   max="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply All Filters
                        </button>
                        <a href="manage_attendance.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                           class="btn btn-export">
                            <i class="fas fa-file-export"></i> Export CSV
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- ATTENDANCE TABLE -->
            <div class="section-title">
                <i class="fas fa-list"></i> Attendance List
                <span style="background: var(--accent); color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.9rem;">
                    <?php echo count($attendance_records); ?> records
                </span>
            </div>
            
            <div class="table-container">
                <?php if (empty($attendance_records)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h3>No Records Found</h3>
                        <p>No attendance records found for the selected filters.</p>
                    </div>
                <?php else: ?>
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th>Cadet</th>
                                <th>Service</th>
                                <th>Rank</th>
                                <th>Training</th>
                                <th>Location</th>
                                <th>Session</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Recorded By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_records as $record): ?>
                            <tr>
                                <td>
                                    <div class="cadet-info">
                                        <div class="cadet-avatar">
                                            <?php echo substr($record['name'], 0, 1); ?>
                                        </div>
                                        <div>
                                            <div class="cadet-name"><?php echo htmlspecialchars($record['name']); ?></div>
                                            <div class="cadet-number"><?php echo $record['military_number']; ?></div>
                                        </div>
                                    </div>
                                </td>
                                
                                <td>
                                    <span class="service-badge badge
                                            echo $record['service_type'] == 'darat' ? 'fa-truck' : 
                                                 ($record['service_type'] == 'laut' ? 'fa-ship' : 'fa-fighter-jet'); 
                                        ?>"></i>
                                        <?php echo $serviceLabels[$record['service_type']] ?? ucfirst($record['service_type']); ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <span style="padding: 4px 10px; border-radius: 5px; background: #edf2f7; font-weight: 500;">
                                        <?php echo $record['rank_level']; ?>
                                    </span>
                                </td>
                                
                                <td><?php echo htmlspecialchars($record['training_type']); ?></td>
                                <td><?php echo htmlspecialchars($record['location']); ?></td>
                                
                                <td>
                                    <span style="font-weight: 500;">
                                        <?php echo $sessionLabels[$record['session_time']] ?? $record['session_time']; ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <span style="font-weight: 500;">
                                        <?php echo date('d/m/Y', strtotime($record['date'])); ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <span class="status-badge status-<?php echo $record['status']; ?>">
                                        <?php echo $statusLabels[$record['status']] ?? ucfirst($record['status']); ?>
                                    </span>
                                    <?php if ($record['recorded_at']): ?>
                                        <br>
                                        <small style="color: var(--secondary); font-size: 0.8rem;">
                                            <?php echo date('H:i', strtotime($record['recorded_at'])); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <?php if ($record['checked_by_name']): ?>
                                        <span style="font-size: 0.9rem; font-weight: 500; color: var(--primary);">
                                            <?php echo htmlspecialchars($record['checked_by_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="font-size: 0.9rem; color: var(--secondary);">
                                            <i class="fas fa-robot"></i> System
                                        </span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <div class="action-btns">
                                        <button class="btn-small btn-success" 
                                                onclick="updateAttendance(<?php echo $record['attendance_id']; ?>, 'present')">
                                            <i class="fas fa-check"></i> Present
                                        </button>
                                        <button class="btn-small btn-danger" 
                                                onclick="updateAttendance(<?php echo $record['attendance_id']; ?>, 'absent')">
                                            <i class="fas fa-times"></i> Absent
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Set filter date to today if empty
        document.addEventListener('DOMContentLoaded', function() {
            toggleDateFields(); // Initialize date fields
        });
        
        // Toggle date fields based on filter type
        function toggleDateFields() {
            const filterType = document.getElementById('dateFilterType').value;
            const monthField = document.getElementById('monthField');
            const dateField = document.getElementById('dateField');
            
            if (filterType === 'month') {
                monthField.style.display = 'flex';
                dateField.style.display = 'none';
            } else if (filterType === 'specific') {
                monthField.style.display = 'none';
                dateField.style.display = 'flex';
            } else {
                monthField.style.display = 'none';
                dateField.style.display = 'none';
            }
        }
        
        // Apply sort function
        function applySort(sortValue) {
            const url = new URL(window.location.href);
            url.searchParams.set('sort', sortValue);
            window.location.href = url.toString();
        }
        
        // Update attendance function (ADMIN EDIT STATUS)
        function updateAttendance(attendanceId, status) {
            const statusText = status === 'present' ? 'Present' : 'Absent';
            if (confirm('Change attendance status to "' + statusText + '"?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'attendance_id';
                idInput.value = attendanceId;
                
                const statusInput = document.createElement('input');
                statusInput.type = 'hidden';
                statusInput.name = 'status';
                statusInput.value = status;
                
                const submitInput = document.createElement('input');
                submitInput.type = 'hidden';
                submitInput.name = 'update_status';
                submitInput.value = '1';
                
                form.appendChild(idInput);
                form.appendChild(statusInput);
                form.appendChild(submitInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
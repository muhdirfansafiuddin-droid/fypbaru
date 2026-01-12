<?php
// admin/manage_excuses.php - MANAGE CADET EXCUSES
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('admin');
$auth = new Auth();
$user = $auth->getCurrentUser();
$db = new Database();

// Get filter parameters
$status_filter = $_GET['status'] ?? 'pending';
$service_filter = $_GET['service'] ?? 'all';
$date_filter = $_GET['date'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Handle excuse approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_excuse'])) {
        $attendance_id = intval($_POST['attendance_id']);
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        
        // Update excuse status to approved
        $updateQuery = "UPDATE attendance 
                       SET status = 'excused', 
                           verified_by = ?,
                           verified_at = NOW(),
                           checked_at = NOW(),
                           reason = CONCAT(reason, IFNULL(CONCAT(' [Admin notes: ', ?, ']'), ''))
                       WHERE attendance_id = ? 
                       AND is_excuse = 1";
        
        $stmt = $db->prepare($updateQuery);
        $stmt->bind_param("isi", $user['user_id'], $admin_notes, $attendance_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Excuse successfully approved!";
            
            // Log activity
            $logDesc = "Admin approved excuse for attendance ID: $attendance_id";
            $logSql = "INSERT INTO activity_logs (user_id, activity_type, description, related_id) 
                      VALUES (?, 'approve_excuse', ?, ?)";
            $logStmt = $db->prepare($logSql);
            $logStmt->bind_param("isi", $user['user_id'], $logDesc, $attendance_id);
            $logStmt->execute();
        } else {
            $_SESSION['error'] = "Failed to approve excuse! Error: " . $stmt->error;
        }
        
    } elseif (isset($_POST['reject_excuse'])) {
        $attendance_id = intval($_POST['attendance_id']);
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        
        // Update excuse status to rejected
        $updateQuery = "UPDATE attendance 
                       SET status = 'absent', 
                           verified_by = ?,
                           verified_at = NOW(),
                           checked_at = NOW(),
                           reason = CONCAT(reason, IFNULL(CONCAT(' [Rejected: ', ?, ']'), ''))
                       WHERE attendance_id = ? 
                       AND is_excuse = 1";
        
        $stmt = $db->prepare($updateQuery);
        $stmt->bind_param("isi", $user['user_id'], $admin_notes, $attendance_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Leave rejected!";
            
            // Log activity
            $logDesc = "Admin rejected excuse for attendance ID: $attendance_id";
            $logSql = "INSERT INTO activity_logs (user_id, activity_type, description, related_id) 
                      VALUES (?, 'reject_excuse', ?, ?)";
            $logStmt = $db->prepare($logSql);
            $logStmt->bind_param("isi", $user['user_id'], $logDesc, $attendance_id);
            $logStmt->execute();
        } else {
            $_SESSION['error'] = "Failed to reject excuse! Error: " . $stmt->error;
        }
    }
    
    header("Location: manage_excuses.php?" . http_build_query($_GET));
    exit();
}

// Build query for excuse applications
$query = "SELECT 
            a.attendance_id,
            a.date,
            a.status,
            a.reason,
            a.proof_file,
            a.recorded_at,
            a.verified_by,
            a.checked_at,
            a.verified_at,
            a.user_id as cadet_id,
            u.military_number,
            u.name as cadet_name,
            u.rank_level,
            u.service_type,
            u.email as cadet_email,
            rh.user_id as rankholder_id,
            rh.name as rankholder_name,
            admin.name as admin_name,
            ts.training_type,
            ts.location,
            ts.session_time,
            ts.training_date,
            CASE 
                WHEN a.verified_by IS NOT NULL AND a.status = 'excused' THEN 'approved'
                WHEN a.verified_by IS NOT NULL AND a.status = 'absent' THEN 'rejected'
                ELSE 'pending'
            END as excuse_status
        FROM attendance a
        INNER JOIN users u ON a.user_id = u.user_id
        LEFT JOIN users rh ON a.checked_by = rh.user_id
        LEFT JOIN users admin ON a.verified_by = admin.user_id
        LEFT JOIN training_sessions ts ON a.session_id = ts.session_id
        WHERE a.is_excuse = 1";
    
$params = [];
$types = "";

// Add status filter
if ($status_filter === 'pending') {
    $query .= " AND a.verified_by IS NULL";
} elseif ($status_filter === 'approved') {
    $query .= " AND a.verified_by IS NOT NULL AND a.status = 'excused'";
} elseif ($status_filter === 'rejected') {
    $query .= " AND a.verified_by IS NOT NULL AND a.status = 'absent'";
}

// Add service filter
if ($service_filter !== 'all') {
    $query .= " AND u.service_type = ?";
    $params[] = $service_filter;
    $types .= "s";
}

// Add date filter
if (!empty($date_filter) && $date_filter != 'all') {
    $query .= " AND DATE(a.date) = ?";
    $params[] = $date_filter;
    $types .= "s";
}

// Add search filter
if (!empty($search_query)) {
    $query .= " AND (u.name LIKE ? OR u.military_number LIKE ? OR rh.name LIKE ? OR u.rank_level LIKE ? OR u.email LIKE ?)";
    $searchTerm = "%$search_query%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "sssss";
}

$query .= " ORDER BY a.recorded_at DESC, a.date DESC";

$stmt = $db->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$excusesResult = $stmt->get_result();
$totalExcuses = $excusesResult->num_rows;

// Get excuse statistics
$statsQuery = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN verified_by IS NULL THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN verified_by IS NOT NULL AND status = 'excused' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN verified_by IS NOT NULL AND status = 'absent' THEN 1 ELSE 0 END) as rejected
            FROM attendance 
            WHERE is_excuse = 1";
    
$statsStmt = $db->prepare($statsQuery);
$statsStmt->execute();
$statsResult = $statsStmt->get_result();
$stats = $statsResult->fetch_assoc();

if (!$stats) {
    $stats = [
        'total' => 0,
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0
    ];
}

// Get service types for filter
$servicesQuery = "SELECT DISTINCT u.service_type 
                 FROM attendance a
                 JOIN users u ON a.user_id = u.user_id
                 WHERE a.is_excuse = 1
                 AND u.service_type IS NOT NULL
                 ORDER BY u.service_type";
$servicesStmt = $db->prepare($servicesQuery);
$servicesStmt->execute();
$servicesResult = $servicesStmt->get_result();

// Get dates for filter
$datesQuery = "SELECT DISTINCT DATE(date) as date FROM attendance 
              WHERE is_excuse = 1
              ORDER BY date DESC LIMIT 30";
$datesStmt = $db->prepare($datesQuery);
$datesStmt->execute();
$datesResult = $datesStmt->get_result();

// Helper functions
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
        case 'darat': return 'service-badge-army';
        case 'laut': return 'service-badge-navy';
        case 'udara': return 'service-badge-airforce';
        default: return 'service-badge-default';
    }
}

function getSessionTimeLabel($time) {
    $labels = [
        'pagi' => 'Morning',
        'tengah hari' => 'Midday',
        'petang' => 'Evening',
        'malam' => 'Night'
    ];
    return $labels[$time] ?? ucfirst($time);
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
    <title>Manage Cadet's Excuse - Admin CAAMS</title>
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
        .stat-card.pending { border-top-color: var(--warning); }
        .stat-card.approved { border-top-color: var(--success); }
        .stat-card.rejected { border-top-color: var(--danger); }
        
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
        
        .search-group {
            position: relative;
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
        }
        
        .search-input {
            padding-left: 45px;
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
        
        /* EXCUSES TABLE */
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .excuses-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .excuses-table th {
            background: var(--primary);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .excuses-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .excuses-table tr:hover {
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
        
        .status-pending { 
            background: rgba(237, 137, 54, 0.1); 
            color: var(--warning); 
            border: 1px solid rgba(237, 137, 54, 0.3);
        }
        
        .status-approved { 
            background: rgba(72, 187, 120, 0.1); 
            color: var(--success); 
            border: 1px solid rgba(72, 187, 120, 0.3);
        }
        
        .status-rejected { 
            background: rgba(245, 101, 101, 0.1); 
            color: var(--danger); 
            border: 1px solid rgba(245, 101, 101, 0.3);
        }
        
        /* ACTION BUTTONS */
        .action-btns {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
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
        
        .btn-view {
            background: var(--info);
            color: white;
        }
        
        .btn-approve {
            background: var(--success);
            color: white;
        }
        
        .btn-reject {
            background: var(--danger);
            color: white;
        }
        
        .btn-cadet {
            background: var(--accent);
            color: white;
        }
        
        /* REASON BOX */
        .reason-box {
            max-width: 250px;
            padding: 10px;
            background: #f7fafc;
            border-radius: 6px;
            font-size: 0.85rem;
            line-height: 1.4;
            max-height: 100px;
            overflow-y: auto;
            border-left: 3px solid var(--accent);
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
        
        /* MODAL */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s;
        }
        
        .modal {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.3s;
        }
        
        .modal-large {
            max-width: 700px;
        }
        
        .modal-header {
            padding: 20px;
            background: var(--primary);
            color: white;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 20px;
            background: #f7fafc;
            border-radius: 0 0 15px 15px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--secondary);
        }
        
        textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
            font-size: 0.9rem;
        }
        
        textarea:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        /* PROOF VIEWER */
        .proof-viewer {
            text-align: center;
            padding: 20px;
        }
        
        .proof-image {
            max-width: 100%;
            max-height: 400px;
            border-radius: 8px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        /* DETAILS BOX */
        .details-box {
            background: #f7fafc;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .details-title {
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 1rem;
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .detail-item {
            margin-bottom: 8px;
        }
        
        .detail-label {
            font-size: 0.85rem;
            color: var(--secondary);
            margin-bottom: 3px;
        }
        
        .detail-value {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.9rem;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .excuses-table {
                display: block;
                overflow-x: auto;
            }
            
            .filter-grid {
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
                align-items: flex-start;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
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
                <i class="fas fa-user-injured"></i> Manage Cadet's Excuse
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">Review and approve excuse applications from cadets</p>
        </div>
        
        <div class="content">
            <!-- ALERTS -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <!-- DASHBOARD STATS -->
            <div class="dashboard-stats">
                <div class="stat-card total">
                    <div class="stat-icon" style="color: var(--primary);">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="stat-label">Total Applications</div>
                </div>
                
                <div class="stat-card pending">
                    <div class="stat-icon" style="color: var(--warning);">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['pending'] ?? 0; ?></div>
                    <div class="stat-label">Pending Review</div>
                </div>
                
                <div class="stat-card approved">
                    <div class="stat-icon" style="color: var(--success);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['approved'] ?? 0; ?></div>
                    <div class="stat-label">Approved</div>
                </div>
                
                <div class="stat-card rejected">
                    <div class="stat-icon" style="color: var(--danger);">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['rejected'] ?? 0; ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
            </div>
            
            <!-- FILTER SECTION -->
            <div class="filter-section">
                <div class="section-title">
                    <i class="fas fa-filter"></i> Application Filters
                </div>
                
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label>Status</label>
                            <select name="status" class="filter-select">
                                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                                <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Service</label>
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
                            <label>Session Date</label>
                            <select name="date" class="filter-select">
                                <option value="all" <?php echo $date_filter == 'all' ? 'selected' : ''; ?>>All Dates</option>
                                <?php 
                                while($date = $datesResult->fetch_assoc()): 
                                    $dateStr = $date['date'];
                                    $displayDate = date('d/m/Y', strtotime($dateStr));
                                ?>
                                    <option value="<?php echo $dateStr; ?>" 
                                        <?php echo $date_filter == $dateStr ? 'selected' : ''; ?>>
                                        <?php echo $displayDate; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group search-group">
                            <label>Search</label>
                            <div style="position: relative;">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" 
                                       name="search" 
                                       class="search-input"
                                       placeholder="Name, military number, rank or email..."
                                       value="<?php echo htmlspecialchars($search_query); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="manage_excuses.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- EXCUSES TABLE -->
            <div class="section-title">
                <i class="fas fa-list"></i> Excuse Applications List
                <span style="background: var(--accent); color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.9rem;">
                    <?php echo $totalExcuses; ?> applications
                </span>
            </div>
            
            <div class="table-container">
                <?php if ($totalExcuses > 0): ?>
                <table class="excuses-table">
                    <thead>
                        <tr>
                            <th>Cadet (Military No.)</th>
                            <th>Service</th>
                            <th>Date/Time</th>
                            <th>Reason</th>
                            <th>Recorded By</th>
                            <th>Proof</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $excusesResult->data_seek(0);
                        while($excuse = $excusesResult->fetch_assoc()): 
                            $status = $excuse['excuse_status'];
                            $statusLabels = [
                                'pending' => 'Pending',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected'
                            ];
                        ?>
                        <tr>
                            <td>
                                <div class="cadet-info">
                                    <div class="cadet-avatar" style="background: <?php echo $status == 'pending' ? 'var(--warning)' : ($status == 'approved' ? 'var(--success)' : 'var(--danger)'); ?>;">
                                        <?php echo strtoupper(substr($excuse['cadet_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="cadet-name"><?php echo htmlspecialchars($excuse['cadet_name']); ?></div>
                                        <div class="cadet-number"><?php echo $excuse['military_number']; ?></div>
                                        <div style="font-size: 0.85rem; color: var(--secondary); margin-top: 2px;">
                                            <?php echo ucfirst($excuse['rank_level']); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            
                            <td>
                                <span class="service-badge badge-<?php echo $excuse['service_type']; ?>">
                                    <i class="fas <?php 
                                        echo $excuse['service_type'] == 'darat' ? 'fa-truck' : 
                                             ($excuse['service_type'] == 'laut' ? 'fa-ship' : 'fa-fighter-jet'); 
                                    ?>"></i>
                                    <?php echo getServiceLabel($excuse['service_type']); ?>
                                </span>
                            </td>
                            
                            <td>
                                <div>
                                    <strong><?php echo formatDate($excuse['date']); ?></strong>
                                    <?php if ($excuse['session_time']): ?>
                                    <div style="font-size: 0.85rem; color: var(--secondary); margin-top: 3px;">
                                        <?php echo getSessionTimeLabel($excuse['session_time']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($excuse['training_type']): ?>
                                    <div style="font-size: 0.8rem; color: var(--accent); margin-top: 2px;">
                                        <i class="fas fa-running"></i> <?php echo $excuse['training_type']; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <td style="max-width: 250px;">
                                <div class="reason-box">
                                    <?php echo htmlspecialchars(substr($excuse['reason'], 0, 150)); ?>
                                    <?php if (strlen($excuse['reason']) > 150): ?>...<?php endif; ?>
                                </div>
                            </td>
                            
                            <td>
                                <div>
                                    <span style="font-weight: 600; color: var(--primary);">
                                        <?php echo htmlspecialchars($excuse['rankholder_name'] ?? 'System'); ?>
                                    </span>
                                    <div style="font-size: 0.85rem; color: var(--secondary); margin-top: 3px;">
                                        <?php echo date('h:i A', strtotime($excuse['recorded_at'])); ?>
                                    </div>
                                    <?php if ($excuse['admin_name']): ?>
                                    <div style="font-size: 0.75rem; color: var(--success); margin-top: 3px;">
                                        <i class="fas fa-user-tie"></i> <?php echo $excuse['admin_name']; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <td>
                                <?php if ($excuse['proof_file']): ?>
                                    <button class="btn-small btn-view" onclick="viewProof('<?php echo htmlspecialchars($excuse['proof_file']); ?>')">
                                        <i class="fas fa-eye"></i> View Proof
                                    </button>
                                <?php else: ?>
                                    <span style="font-size: 0.8rem; color: var(--warning);">
                                        <i class="fas fa-times"></i> None
                                    </span>
                                <?php endif; ?>
                            </td>
                            
                            <td>
                                <span class="status-badge status-<?php echo $status; ?>">
                                    <?php echo $statusLabels[$status]; ?>
                                </span>
                                <?php if ($excuse['verified_at']): ?>
                                <div style="margin-top: 3px; font-size: 0.75rem; color: var(--secondary);">
                                    <?php echo date('d/m/Y h:i A', strtotime($excuse['verified_at'])); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            
                            <td>
                                <div class="action-btns">
                                    <button class="btn-small btn-cadet" 
                                            onclick="showCadetDetails(
                                                <?php echo $excuse['cadet_id']; ?>,
                                                '<?php echo htmlspecialchars(addslashes($excuse['cadet_name'])); ?>',
                                                '<?php echo htmlspecialchars(addslashes($excuse['military_number'])); ?>',
                                                '<?php echo htmlspecialchars(addslashes($excuse['rank_level'])); ?>',
                                                '<?php echo htmlspecialchars(addslashes($excuse['service_type'])); ?>',
                                                '<?php echo htmlspecialchars(addslashes($excuse['cadet_email'])); ?>'
                                            )">
                                        <i class="fas fa-user"></i> Cadet Details
                                    </button>
                                    
                                    <?php if ($status === 'pending'): ?>
                                        <button class="btn-small btn-approve" 
                                                onclick="approveExcuse(<?php echo $excuse['attendance_id']; ?>, '<?php echo htmlspecialchars(addslashes($excuse['cadet_name'])); ?>')">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button class="btn-small btn-reject" 
                                                onclick="rejectExcuse(<?php echo $excuse['attendance_id']; ?>, '<?php echo htmlspecialchars(addslashes($excuse['cadet_name'])); ?>')">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    <?php else: ?>
                                        <span style="font-size: 0.8rem; color: var(--secondary); padding: 6px 0; display: block;">
                                            <?php echo $status === 'approved' ? 'Already Approved' : 'Already Rejected'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-file-medical-alt"></i>
                    <h3>No Excuse Applications</h3>
                    <p>No excuse applications found with current filters.</p>
                    <?php if ($status_filter != 'all'): ?>
                    <p style="font-size: 0.9rem; color: var(--secondary); margin-top: 10px;">
                        Try changing filters or check "All Status".
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- APPROVE MODAL -->
    <div class="modal-overlay" id="approveModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> Approve Excuse Application</h3>
                <button class="modal-close" onclick="closeModal('approveModal')">&times;</button>
            </div>
            <form method="POST" id="approveForm">
                <div class="modal-body">
                    <div id="approveCadetName"></div>
                    
                    <div class="form-group">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="admin_notes" placeholder="Add notes if needed..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="attendance_id" id="approveAttendanceId">
                    <input type="hidden" name="approve_excuse" value="1">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('approveModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- REJECT MODAL -->
    <div class="modal-overlay" id="rejectModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> Reject Excuse Application</h3>
                <button class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
            </div>
            <form method="POST" id="rejectForm">
                <div class="modal-body">
                    <div id="rejectCadetName"></div>
                    
                    <div class="form-group">
                        <label class="form-label">Rejection Reason *</label>
                        <textarea name="admin_notes" placeholder="State rejection reason..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="attendance_id" id="rejectAttendanceId">
                    <input type="hidden" name="reject_excuse" value="1">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('rejectModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- PROOF VIEWER MODAL -->
    <div class="modal-overlay" id="proofModal">
        <div class="modal" style="max-width: 700px;">
            <div class="modal-header">
                <h3><i class="fas fa-file-image"></i> Excuse Proof</h3>
                <button class="modal-close" onclick="closeModal('proofModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="proof-viewer" id="proofContainer">
                    <!-- Proof will be loaded here -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- CADET DETAILS MODAL -->
    <div class="modal-overlay" id="cadetModal">
        <div class="modal modal-large">
            <div class="modal-header">
                <h3><i class="fas fa-user-circle"></i> Cadet Information</h3>
                <button class="modal-close" onclick="closeModal('cadetModal')">&times;</button>
            </div>
            <div class="modal-body" id="cadetDetails">
                <!-- Cadet information will be displayed here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('cadetModal')">Close</button>
            </div>
        </div>
    </div>
    
    <script>
        // Approve excuse function
        function approveExcuse(attendanceId, cadetName) {
            document.getElementById('approveCadetName').innerHTML = 
                `<div style="background: rgba(72, 187, 120, 0.1); padding: 15px; border-radius: 8px; border-left: 4px solid var(--success); margin-bottom: 15px;">
                    <strong>${cadetName}</strong><br>
                    <small style="color: var(--secondary);">Are you sure you want to approve this excuse application?</small>
                </div>`;
            document.getElementById('approveAttendanceId').value = attendanceId;
            document.getElementById('approveModal').style.display = 'flex';
        }
        
        // Reject excuse function
        function rejectExcuse(attendanceId, cadetName) {
            document.getElementById('rejectCadetName').innerHTML = 
                `<div style="background: rgba(245, 101, 101, 0.1); padding: 15px; border-radius: 8px; border-left: 4px solid var(--danger); margin-bottom: 15px;">
                    <strong>${cadetName}</strong><br>
                    <small style="color: var(--secondary);">Are you sure you want to reject this excuse application?</small>
                </div>`;
            document.getElementById('rejectAttendanceId').value = attendanceId;
            document.getElementById('rejectModal').style.display = 'flex';
        }
        
        // Show cadet details function
        function showCadetDetails(cadetId, cadetName, militaryNumber, rankLevel, serviceType, cadetEmail) {
            const cadetDetails = document.getElementById('cadetDetails');
            
            const serviceIcon = serviceType === 'darat' ? 'fa-truck' : 
                               serviceType === 'laut' ? 'fa-ship' : 'fa-fighter-jet';
            const serviceLabel = serviceType === 'darat' ? 'Army' : 
                                serviceType === 'laut' ? 'Navy' : 'Air Force';
            const serviceColor = serviceType === 'darat' ? 'var(--army-green)' : 
                                serviceType === 'laut' ? 'var(--navy)' : 'var(--airforce-blue)';
            
            // Display cadet information
            cadetDetails.innerHTML = `
                <div style="text-align: center; margin-bottom: 20px;">
                    <div class="cadet-avatar" style="width: 80px; height: 80px; font-size: 2rem; margin: 0 auto 15px auto; background: ${serviceColor};">
                        ${cadetName.charAt(0).toUpperCase()}
                    </div>
                    <h3>${cadetName}</h3>
                    <p style="color: var(--secondary);">${militaryNumber}</p>
                </div>
                
                <div class="details-box">
                    <div class="details-title">Personal Information</div>
                    
                    <div class="details-grid">
                        <div class="detail-item">
                            <div class="detail-label">Military Number</div>
                            <div class="detail-value">${militaryNumber}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">System ID</div>
                            <div class="detail-value">${cadetId}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Rank</div>
                            <div class="detail-value">${rankLevel.toUpperCase()}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Service</div>
                            <div class="detail-value">
                                <span class="service-badge badge-${serviceType}">
                                    <i class="fas ${serviceIcon}"></i>
                                    ${serviceLabel}
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Email</div>
                        <div class="detail-value">
                            <a href="mailto:${cadetEmail}" style="color: var(--accent); text-decoration: none;">
                                ${cadetEmail}
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="details-box">
                    <div class="details-title">Excuse Statistics</div>
                    <p style="color: var(--secondary); font-size: 0.9rem; margin-bottom: 10px;">
                        Overall excuse statistics for this cadet:
                    </p>
                    
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 120px; background: white; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0; text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: var(--warning);">0</div>
                            <div style="font-size: 0.75rem; color: var(--secondary);">Pending</div>
                        </div>
                        <div style="flex: 1; min-width: 120px; background: white; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0; text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: var(--success);">0</div>
                            <div style="font-size: 0.75rem; color: var(--secondary);">Approved</div>
                        </div>
                        <div style="flex: 1; min-width: 120px; background: white; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0; text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: var(--danger);">0</div>
                            <div style="font-size: 0.75rem; color: var(--secondary);">Rejected</div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
                        <div class="detail-label">Note:</div>
                        <div class="detail-value" style="font-size: 0.85rem;">
                            These statistics are based on excuse applications made by this cadet.
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('cadetModal').style.display = 'flex';
        }
        
        // View proof function
        function viewProof(filePath) {
            const container = document.getElementById('proofContainer');
            const extension = filePath.split('.').pop().toLowerCase();
            
            if (['jpg', 'jpeg', 'png', 'gif', 'bmp'].includes(extension)) {
                // Image file
                container.innerHTML = `
                    <img src="../${filePath}" class="proof-image" alt="Excuse Proof">
                    <div style="margin-top: 15px;">
                        <a href="../${filePath}" target="_blank" class="btn" style="background: var(--accent); color: white; text-decoration: none;">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>
                `;
            } else if (['pdf'].includes(extension)) {
                // PDF file
                container.innerHTML = `
                    <div style="padding: 40px 20px;">
                        <i class="fas fa-file-pdf" style="font-size: 4rem; color: var(--danger);"></i>
                        <h4 style="margin: 15px 0 10px 0;">PDF Document</h4>
                        <p>This file is in PDF format. Please download to view.</p>
                        <a href="../${filePath}" 
                           target="_blank" 
                           class="btn" 
                           style="background: var(--danger); color: white; margin-top: 15px; text-decoration: none;">
                            <i class="fas fa-download"></i> Download PDF
                        </a>
                    </div>
                `;
            } else {
                // Other file types
                container.innerHTML = `
                    <div style="padding: 40px 20px;">
                        <i class="fas fa-file" style="font-size: 4rem; color: var(--accent);"></i>
                        <h4 style="margin: 15px 0 10px 0;">File Attachment</h4>
                        <p>Cannot preview this file type. Please download to view.</p>
                        <a href="../${filePath}" 
                           target="_blank" 
                           class="btn" 
                           style="background: var(--accent); color: white; margin-top: 15px; text-decoration: none;">
                            <i class="fas fa-download"></i> Download File
                        </a>
                    </div>
                `;
            }
            
            document.getElementById('proofModal').style.display = 'flex';
        }
        
        // Close modal function
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Form validation for reject form
        document.getElementById('rejectForm').addEventListener('submit', function(e) {
            const textarea = this.querySelector('textarea[name="admin_notes"]');
            
            if (!textarea.value.trim()) {
                e.preventDefault();
                alert('Please fill in the rejection reason!');
                textarea.focus();
                return false;
            }
            
            return true;
        });
        
        // Auto-refresh page every 30 seconds for pending excuses
        setInterval(() => {
            const statusFilter = document.querySelector('select[name="status"]');
            if (statusFilter && statusFilter.value === 'pending') {
                location.reload();
            }
        }, 30000);
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.style.display = 'none';
            }
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape key closes modals
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal-overlay');
                modals.forEach(modal => {
                    if (modal.style.display === 'flex') {
                        modal.style.display = 'none';
                    }
                });
            }
        });
    </script>
</body>
</html>
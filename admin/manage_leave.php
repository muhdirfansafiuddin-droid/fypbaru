<?php
// admin/manage_leave.php
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

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$date_filter = $_GET['date'] ?? '';
$search_query = $_GET['search'] ?? '';

// Build WHERE clause for filters
$whereClauses = ["a.is_leave = 1"];
$params = [];
$types = "";

if ($status_filter != 'all') {
    if ($status_filter == 'pending') {
        $whereClauses[] = "a.checked_by IS NULL";
    } elseif ($status_filter == 'approved') {
        $whereClauses[] = "a.checked_by IS NOT NULL AND a.status = 'excused'";
    } elseif ($status_filter == 'rejected') {
        $whereClauses[] = "a.checked_by IS NOT NULL AND a.status = 'absent'";
    }
}

if ($type_filter != 'all') {
    $whereClauses[] = "a.leave_type = ?";
    $params[] = $type_filter;
    $types .= "s";
}

if (!empty($date_filter)) {
    $whereClauses[] = "a.date = ?";
    $params[] = $date_filter;
    $types .= "s";
}

if (!empty($search_query)) {
    $whereClauses[] = "(u.name LIKE ? OR u.military_number LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $types .= "ss";
}

$whereSQL = implode(' AND ', $whereClauses);

// Handle leave approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $attendance_id = $_POST['attendance_id'];
    $action = $_POST['action'];
    $admin_notes = $_POST['admin_notes'] ?? '';
    
    $new_status = ($action == 'approve') ? 'excused' : 'absent';
    $checked_by = $user['user_id'];
    $checked_at = date('Y-m-d H:i:s');
    
    $updateSql = "UPDATE attendance 
                 SET status = ?, 
                     checked_by = ?, 
                     checked_at = ?,
                     reason = CONCAT(IFNULL(reason, ''), ' | Admin: ', ?)
                 WHERE attendance_id = ? AND is_leave = 1";
    
    $stmt = $db->prepare($updateSql);
    $stmt->bind_param("sisss", $new_status, $checked_by, $checked_at, $admin_notes, $attendance_id);
    
    if ($stmt->execute()) {
        // Log activity
        $actionText = ($action == 'approve') ? 'approved' : 'rejected';
        $logDesc = "Leave $actionText for attendance ID: $attendance_id";
        $logSql = "INSERT INTO activity_logs (user_id, activity_type, description, related_id) 
                  VALUES (?, 'leave_$actionText', ?, ?)";
        $logStmt = $db->prepare($logSql);
        $logStmt->bind_param("isi", $user['user_id'], $logDesc, $attendance_id);
        $logStmt->execute();
        
        $message = "Leave application has been $actionText successfully!";
        $messageType = 'success';
        
        // Refresh page
        header("Location: manage_leave.php?" . http_build_query($_GET));
        exit();
    } else {
        $message = "Database error: " . $stmt->error;
        $messageType = 'error';
    }
}

// Get leave statistics
$statsSql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN checked_by IS NULL AND is_leave = 1 THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN checked_by IS NOT NULL AND status = 'excused' AND is_leave = 1 THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN checked_by IS NOT NULL AND status = 'absent' AND is_leave = 1 THEN 1 ELSE 0 END) as rejected
    FROM attendance 
    WHERE is_leave = 1";
    
$statsStmt = $db->prepare($statsSql);
$statsStmt->execute();
$statsResult = $statsStmt->get_result();
$stats = $statsResult->fetch_assoc();

// Get leave applications with pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$query = "SELECT 
    a.*,
    u.user_id, u.name as cadet_name, u.military_number, u.service_type, u.rank_level,
    ts.training_date, ts.location, ts.training_type, ts.session_time,
    admin.name as admin_name
    FROM attendance a
    JOIN users u ON a.user_id = u.user_id
    JOIN training_sessions ts ON a.session_id = ts.session_id
    LEFT JOIN users admin ON a.checked_by = admin.user_id
    WHERE $whereSQL
    ORDER BY a.date DESC, a.recorded_at DESC
    LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $db->prepare($query);
if (!empty($params)) {
    $bindParams = array_merge([$types], $params);
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}
$stmt->execute();
$leaves = $stmt->get_result();

// Get total count for pagination
$countSql = "SELECT COUNT(*) as total FROM attendance a
            JOIN users u ON a.user_id = u.user_id
            JOIN training_sessions ts ON a.session_id = ts.session_id
            WHERE $whereSQL";

$countStmt = $db->prepare($countSql);
if (!empty($params)) {
    $countParams = array_slice($params, 0, -2); // Remove limit and offset
    $countTypes = substr($types, 0, -2); // Remove 'ii'
    if (!empty($countParams)) {
        $countBindParams = array_merge([$countTypes], $countParams);
        call_user_func_array([$countStmt, 'bind_param'], $countBindParams);
    }
}
$countStmt->execute();
$totalResult = $countStmt->get_result();
$totalLeaves = $totalResult->fetch_assoc()['total'] ?? 0;
$totalPages = ceil($totalLeaves / $limit);

// Get leave type counts for stats
$typeStatsSql = "SELECT 
    COALESCE(leave_type, 'lain') as type,
    COUNT(*) as count
    FROM attendance 
    WHERE is_leave = 1
    GROUP BY leave_type";
$typeStatsResult = $db->query($typeStatsSql);
$typeStats = [];
$totalByType = 0;
while ($row = $typeStatsResult->fetch_assoc()) {
    $typeStats[$row['type']] = $row['count'];
    $totalByType += $row['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Urus Pelepasan - CAAMS</title>
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
        
        /* MESSAGE ALERT */
        .alert {
            padding: 15px 20px;
            margin: 20px 30px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.3s ease-out;
        }
        
        .alert.success {
            background: #d4edda;
            color: #155724;
            border-left: 5px solid var(--success);
        }
        
        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 5px solid var(--danger);
        }
        
        .alert.info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 5px solid var(--accent);
        }
        
        .alert.warning {
            background: #fff3cd;
            color: #856404;
            border-left: 5px solid var(--warning);
        }
        
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
        
        /* STATISTICS */
        .stats-section {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 1100px) {
            .stats-section {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 600px) {
            .stats-section {
                grid-template-columns: 1fr;
            }
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-top: 5px solid;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.total { border-top-color: var(--accent); }
        .stat-card.pending { border-top-color: var(--warning); }
        .stat-card.approved { border-top-color: var(--success); }
        .stat-card.rejected { border-top-color: var(--danger); }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .stat-icon {
            font-size: 2rem;
            opacity: 0.8;
        }
        
        .stat-card.total .stat-icon { color: var(--accent); }
        .stat-card.pending .stat-icon { color: var(--warning); }
        .stat-card.approved .stat-icon { color: var(--success); }
        .stat-card.rejected .stat-icon { color: var(--danger); }
        
        .stat-number {
            font-size: 2.8rem;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--secondary);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        /* TYPE DISTRIBUTION */
        .type-distribution {
            background: #f7fafc;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .type-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .type-item {
            background: white;
            padding: 15px;
            border-radius: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid var(--accent);
        }
        
        .type-name {
            font-weight: 500;
            color: var(--secondary);
        }
        
        .type-count {
            font-weight: bold;
            color: var(--primary);
            font-size: 1.2rem;
        }
        
        /* FILTER SECTION */
        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            margin-bottom: 0;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        input, select {
            width: 100%;
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
            color: #718096;
        }
        
        .search-input {
            padding-left: 45px;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
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
            background: var(--secondary);
            color: white;
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        
        /* LEAVE TABLE */
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .table-header {
            background: var(--primary);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-title {
            font-size: 1.2rem;
        }
        
        .export-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.3s;
        }
        
        .export-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .leaves-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .leaves-table th {
            background: #edf2f7;
            color: var(--secondary);
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .leaves-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .leaves-table tr:hover {
            background: #f7fafc;
        }
        
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
            font-size: 0.9rem;
        }
        
        .cadet-details {
            flex: 1;
        }
        
        .cadet-name {
            font-weight: 600;
            color: var(--primary);
        }
        
        .cadet-number {
            font-size: 0.85rem;
            color: #718096;
        }
        
        .cadet-rank {
            font-size: 0.8rem;
            background: #e2e8f0;
            padding: 2px 8px;
            border-radius: 10px;
            color: var(--secondary);
        }
        
        .session-info {
            font-weight: 500;
            color: var(--primary);
        }
        
        .session-details {
            font-size: 0.85rem;
            color: #718096;
        }
        
        .type-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
            text-transform: capitalize;
        }
        
        .type-sakit { background: #fed7d7; color: #c53030; }
        .type-cuti { background: #c6f6d5; color: #276749; }
        .type-kecemasan { background: #fed7d7; color: #c53030; }
        .type-lain { background: #e2e8f0; color: var(--secondary); }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-excused { background: #d4edda; color: #155724; }
        .status-absent { background: #f8d7da; color: #721c24; }
        
        .action-btns {
            display: flex;
            gap: 5px;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 0.85rem;
            border-radius: 5px;
        }
        
        /* PAGINATION */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .page-btn {
            padding: 8px 15px;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .page-btn:hover {
            border-color: var(--accent);
            background: #f7fafc;
        }
        
        .page-btn.active {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }
        
        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
        
        textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            resize: vertical;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .leaves-table {
                display: block;
                overflow-x: auto;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .action-btns {
                flex-direction: column;
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
                <i class="fas fa-file-medical"></i> Urus Pelepasan
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">Semak dan kelulusan permohonan pelepasan kadet dengan pengesahan bukti</p>
        </div>
        
        <!-- MESSAGE ALERT -->
        <?php if ($message): ?>
            <div class="alert <?php echo $messageType; ?>">
                <i class="fas <?php 
                    echo $messageType == 'success' ? 'fa-check-circle' : 
                         ($messageType == 'error' ? 'fa-exclamation-triangle' : 
                         ($messageType == 'warning' ? 'fa-exclamation-circle' : 'fa-info-circle')); 
                ?>"></i>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>
        
        <!-- MAIN CONTENT -->
        <div class="content">
            <!-- STATISTICS -->
            <div class="stats-section">
                <div class="stat-card total">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                            <div class="stat-label">Total Permohonan</div>
                        </div>
                        <i class="fas fa-file-alt stat-icon"></i>
                    </div>
                </div>
                
                <div class="stat-card pending">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['pending'] ?? 0; ?></div>
                            <div class="stat-label">Dalam Semakan</div>
                        </div>
                        <i class="fas fa-clock stat-icon"></i>
                    </div>
                </div>
                
                <div class="stat-card approved">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['approved'] ?? 0; ?></div>
                            <div class="stat-label">Diluluskan</div>
                        </div>
                        <i class="fas fa-check-circle stat-icon"></i>
                    </div>
                </div>
                
                <div class="stat-card rejected">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['rejected'] ?? 0; ?></div>
                            <div class="stat-label">Ditolak</div>
                        </div>
                        <i class="fas fa-times-circle stat-icon"></i>
                    </div>
                </div>
            </div>
            
            <!-- TYPE DISTRIBUTION -->
            <div class="type-distribution">
                <h3 class="section-title" style="font-size: 1.3rem; border-bottom: none; margin-bottom: 15px;">
                    <i class="fas fa-chart-pie"></i> Taburan Jenis Pelepasan
                </h3>
                
                <div class="type-list">
                    <?php foreach ($typeStats as $type => $count): 
                        $typeLabel = [
                            'sakit' => 'Sakit',
                            'cuti' => 'Cuti Rehat',
                            'kecemasan' => 'Kecemasan',
                            'lain' => 'Lain-lain'
                        ][$type] ?? ucfirst($type);
                        
                        $percentage = $totalByType > 0 ? round(($count / $totalByType) * 100, 1) : 0;
                    ?>
                        <div class="type-item">
                            <span class="type-name"><?php echo $typeLabel; ?></span>
                            <div style="text-align: right;">
                                <div class="type-count"><?php echo $count; ?></div>
                                <small style="color: #718096; font-size: 0.8rem;"><?php echo $percentage; ?>%</small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- FILTER SECTION -->
            <div class="filter-section">
                <form method="GET" action="" id="filterForm">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label>Status Pelepasan</label>
                            <select name="status" id="statusFilter">
                                <option value="all">Semua Status</option>
                                <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Dalam Semakan</option>
                                <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Diluluskan</option>
                                <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Ditolak</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Jenis Pelepasan</label>
                            <select name="type" id="typeFilter">
                                <option value="all">Semua Jenis</option>
                                <option value="sakit" <?php echo $type_filter == 'sakit' ? 'selected' : ''; ?>>Sakit</option>
                                <option value="cuti" <?php echo $type_filter == 'cuti' ? 'selected' : ''; ?>>Cuti Rehat</option>
                                <option value="kecemasan" <?php echo $type_filter == 'kecemasan' ? 'selected' : ''; ?>>Kecemasan</option>
                                <option value="lain" <?php echo $type_filter == 'lain' ? 'selected' : ''; ?>>Lain-lain</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Tarikh</label>
                            <input type="date" name="date" value="<?php echo $date_filter; ?>">
                        </div>
                        
                        <div class="filter-group search-group">
                            <label>Cari Kadet</label>
                            <div style="position: relative;">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" 
                                       name="search" 
                                       class="search-input"
                                       placeholder="Nama atau No Tentera..."
                                       value="<?php echo htmlspecialchars($search_query); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Gunakan Filter
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                            <i class="fas fa-redo"></i> Set Semula
                        </button>
                        <a href="manage_leave.php" class="btn" style="background: #e2e8f0; color: var(--secondary); text-decoration: none;">
                            <i class="fas fa-times"></i> Clear All
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- LEAVE APPLICATIONS TABLE -->
            <div class="table-container">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-list"></i> Senarai Permohonan Pelepasan
                    </h3>
                    <button class="export-btn" onclick="exportToCSV()">
                        <i class="fas fa-file-export"></i> Export CSV
                    </button>
                </div>
                
                <table class="leaves-table">
                    <thead>
                        <tr>
                            <th>Kadet</th>
                            <th>Sesi Latihan</th>
                            <th>Jenis</th>
                            <th>Alasan</th>
                            <th>Bukti</th>
                            <th>Status</th>
                            <th>Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($leaves->num_rows == 0): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fas fa-file-medical-alt"></i>
                                        <h3>Tiada Permohonan Pelepasan</h3>
                                        <p>Tidak ada permohonan pelepasan untuk ditunjukkan dengan filter semasa.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php while ($leave = $leaves->fetch_assoc()): 
                                $status = $leave['checked_by'] ? ($leave['status'] == 'excused' ? 'approved' : 'rejected') : 'pending';
                                $sessionLabels = [
                                    'pagi' => 'Pagi',
                                    'tengah hari' => 'Tengah Hari', 
                                    'petang' => 'Petang',
                                    'malam' => 'Malam'
                                ];
                            ?>
                            <tr>
                                <td>
                                    <div class="cadet-info">
                                        <div class="cadet-avatar">
                                            <?php echo substr($leave['cadet_name'], 0, 1); ?>
                                        </div>
                                        <div class="cadet-details">
                                            <div class="cadet-name"><?php echo htmlspecialchars($leave['cadet_name']); ?></div>
                                            <div class="cadet-number"><?php echo $leave['military_number']; ?></div>
                                            <div class="cadet-rank"><?php echo $leave['rank_level']; ?></div>
                                        </div>
                                    </div>
                                </td>
                                
                                <td>
                                    <div class="session-info">
                                        <?php echo htmlspecialchars($leave['training_type']); ?>
                                    </div>
                                    <div class="session-details">
                                        <?php echo date('d/m/Y', strtotime($leave['training_date'])); ?> 
                                        | <?php echo $sessionLabels[$leave['session_time']] ?? $leave['session_time']; ?>
                                        <br>
                                        <small><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($leave['location']); ?></small>
                                    </div>
                                </td>
                                
                                <td>
                                    <span class="type-badge type-<?php echo $leave['leave_type'] ?? 'lain'; ?>">
                                        <?php 
                                            $typeLabels = [
                                                'sakit' => 'Sakit',
                                                'cuti' => 'Cuti',
                                                'kecemasan' => 'Kecemasan',
                                                'lain' => 'Lain'
                                            ];
                                            echo $typeLabels[$leave['leave_type'] ?? 'lain'] ?? 'Lain';
                                        ?>
                                    </span>
                                </td>
                                
                                <td style="max-width: 200px;">
                                    <small><?php echo htmlspecialchars(substr($leave['reason'] ?? '', 0, 80)); ?>
                                    <?php if (strlen($leave['reason'] ?? '') > 80): ?>...<?php endif; ?></small>
                                    <?php if ($leave['checked_by'] && !empty($leave['reason'])): ?>
                                        <br>
                                        <small style="color: var(--accent);">
                                            <i class="fas fa-user-tie"></i> 
                                            <?php echo $leave['admin_name'] ?? 'Admin'; ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <?php if ($leave['proof_file']): ?>
                                        <button class="btn btn-small btn-primary" onclick="viewProof('<?php echo htmlspecialchars($leave['proof_file']); ?>')">
                                            <i class="fas fa-eye"></i> Lihat
                                        </button>
                                    <?php else: ?>
                                        <span class="status-badge status-pending" style="font-size: 0.75rem;">Tiada Bukti</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <span class="status-badge status-<?php echo $status; ?>">
                                        <?php 
                                            $statusLabels = [
                                                'pending' => 'Dalam Semakan',
                                                'approved' => 'Diluluskan',
                                                'rejected' => 'Ditolak'
                                            ];
                                            echo $statusLabels[$status] ?? ucfirst($status);
                                        ?>
                                    </span>
                                    <?php if ($leave['checked_at']): ?>
                                        <br>
                                        <small style="color: #718096; font-size: 0.8rem;">
                                            <?php echo date('d/m/Y H:i', strtotime($leave['checked_at'])); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <?php if ($status == 'pending'): ?>
                                        <div class="action-btns">
                                            <button class="btn btn-small btn-success" 
                                                    onclick="openApproveModal(<?php echo $leave['attendance_id']; ?>, '<?php echo htmlspecialchars($leave['cadet_name']); ?>')">
                                                <i class="fas fa-check"></i> Lulus
                                            </button>
                                            <button class="btn btn-small btn-danger" 
                                                    onclick="openRejectModal(<?php echo $leave['attendance_id']; ?>, '<?php echo htmlspecialchars($leave['cadet_name']); ?>')">
                                                <i class="fas fa-times"></i> Tolak
                                            </button>
                                            <button class="btn btn-small" 
                                                    style="background: #e2e8f0; color: var(--secondary);"
                                                    onclick="viewDetails(<?php echo $leave['attendance_id']; ?>)">
                                                <i class="fas fa-info-circle"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <button class="btn btn-small" 
                                                style="background: #e2e8f0; color: var(--secondary);"
                                                onclick="viewDetails(<?php echo $leave['attendance_id']; ?>)">
                                            <i class="fas fa-eye"></i> Lihat
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- PAGINATION -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <button class="page-btn" 
                        onclick="goToPage(<?php echo max(1, $page - 1); ?>)" 
                        <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                    <i class="fas fa-chevron-left"></i> Sebelum
                </button>
                
                <?php 
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                for ($i = $startPage; $i <= $endPage; $i++): 
                ?>
                    <button class="page-btn <?php echo $i == $page ? 'active' : ''; ?>" 
                            onclick="goToPage(<?php echo $i; ?>)">
                        <?php echo $i; ?>
                    </button>
                <?php endfor; ?>
                
                <button class="page-btn" 
                        onclick="goToPage(<?php echo min($totalPages, $page + 1); ?>)" 
                        <?php echo $page >= $totalPages ? 'disabled' : ''; ?>>
                    Seterusnya <i class="fas fa-chevron-right"></i>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- APPROVE MODAL -->
    <div class="modal-overlay" id="approveModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> Lulus Permohonan Pelepasan</h3>
                <button class="modal-close" onclick="closeModal('approveModal')">&times;</button>
            </div>
            <form method="POST" id="approveForm">
                <div class="modal-body">
                    <p id="approveCadetName"></p>
                    <div class="form-group">
                        <label>Catatan Pentadbiran (Pilihan)</label>
                        <textarea name="admin_notes" rows="4" placeholder="Tambah catatan untuk kadet (jika perlu)..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="attendance_id" id="approveAttendanceId">
                    <input type="hidden" name="action" value="approve">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('approveModal')">Batal</button>
                    <button type="submit" class="btn btn-success">Sahkan Kelulusan</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- REJECT MODAL -->
    <div class="modal-overlay" id="rejectModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> Tolak Permohonan Pelepasan</h3>
                <button class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
            </div>
            <form method="POST" id="rejectForm">
                <div class="modal-body">
                    <p id="rejectCadetName"></p>
                    <div class="form-group">
                        <label>Sebab Penolakan *</label>
                        <textarea name="admin_notes" rows="4" placeholder="Sila berikan sebab penolakan..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="attendance_id" id="rejectAttendanceId">
                    <input type="hidden" name="action" value="reject">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('rejectModal')">Batal</button>
                    <button type="submit" class="btn btn-danger">Sahkan Penolakan</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- PROOF VIEWER MODAL -->
    <div class="modal-overlay" id="proofModal">
        <div class="modal" style="max-width: 800px;">
            <div class="modal-header">
                <h3><i class="fas fa-image"></i> Bukti Pelepasan</h3>
                <button class="modal-close" onclick="closeModal('proofModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="proofContent" style="text-align: center;">
                    <img id="proofImage" src="" alt="Proof" style="max-width: 100%; max-height: 400px; border-radius: 8px;">
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Modal functions
        function openApproveModal(attendanceId, cadetName) {
            document.getElementById('approveCadetName').innerHTML = 
                `<strong>${cadetName}</strong><br><small>Adakah anda pasti mahu meluluskan permohonan pelepasan ini?</small>`;
            document.getElementById('approveAttendanceId').value = attendanceId;
            document.getElementById('approveModal').style.display = 'flex';
        }
        
        function openRejectModal(attendanceId, cadetName) {
            document.getElementById('rejectCadetName').innerHTML = 
                `<strong>${cadetName}</strong><br><small>Adakah anda pasti mahu menolak permohonan pelepasan ini?</small>`;
            document.getElementById('rejectAttendanceId').value = attendanceId;
            document.getElementById('rejectModal').style.display = 'flex';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function viewProof(filePath) {
            const proofContent = document.getElementById('proofContent');
            const proofImage = document.getElementById('proofImage');
            
            // Check file extension
            const extension = filePath.split('.').pop().toLowerCase();
            
            if (['jpg', 'jpeg', 'png', 'gif', 'bmp'].includes(extension)) {
                // Image file
                proofImage.src = '../uploads/' + filePath;
                proofImage.style.display = 'block';
                proofContent.innerHTML = '';
                proofContent.appendChild(proofImage);
            } else if (['pdf'].includes(extension)) {
                // PDF file - show link
                proofContent.innerHTML = `
                    <div style="padding: 40px 20px;">
                        <i class="fas fa-file-pdf" style="font-size: 4rem; color: #f56565;"></i>
                        <h4 style="margin: 15px 0 10px 0;">PDF Document</h4>
                        <p>This is a PDF file. Please download to view.</p>
                        <a href="../uploads/${filePath}" 
                           target="_blank" 
                           class="btn btn-danger" 
                           style="margin-top: 15px; text-decoration: none;">
                            <i class="fas fa-download"></i> Download PDF
                        </a>
                    </div>
                `;
            } else {
                // Other file types
                proofContent.innerHTML = `
                    <div style="padding: 40px 20px;">
                        <i class="fas fa-file" style="font-size: 4rem; color: var(--accent);"></i>
                        <h4 style="margin: 15px 0 10px 0;">File Attachment</h4>
                        <p>Cannot preview this file type. Please download to view.</p>
                        <a href="../uploads/${filePath}" 
                           target="_blank" 
                           class="btn btn-primary" 
                           style="margin-top: 15px; text-decoration: none;">
                            <i class="fas fa-download"></i> Download File
                        </a>
                    </div>
                `;
            }
            
            document.getElementById('proofModal').style.display = 'flex';
        }
        
        function viewDetails(attendanceId) {
            // In real implementation, you would redirect to details page or show more info
            window.location.href = `leave_details.php?id=${attendanceId}`;
        }
        
        // Filter functions
        function resetFilters() {
            document.getElementById('statusFilter').value = 'all';
            document.getElementById('typeFilter').value = 'all';
            document.getElementById('filterForm').querySelector('input[name="date"]').value = '';
            document.getElementById('filterForm').querySelector('input[name="search"]').value = '';
            document.getElementById('filterForm').submit();
        }
        
        // Pagination
        function goToPage(page) {
            const url = new URL(window.location.href);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }
        
        // Export to CSV
        function exportToCSV() {
            const table = document.querySelector('.leaves-table');
            const rows = table.querySelectorAll('tr');
            const csv = [];
            
            for (let i = 0; i < rows.length; i++) {
                const row = [], cols = rows[i].querySelectorAll('td, th');
                
                for (let j = 0; j < cols.length; j++) {
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/(\s\s+)/gm, ' ');
                    data = data.replace(/"/g, '""');
                    row.push('"' + data + '"');
                }
                
                csv.push(row.join(','));
            }
            
            const csvString = csv.join('\n');
            const filename = `leave_applications_${new Date().toISOString().split('T')[0]}.csv`;
            const link = document.createElement('a');
            link.style.display = 'none';
            link.setAttribute('target', '_blank');
            link.setAttribute('href', 'data:text/csv;charset=utf-8,' + encodeURIComponent(csvString));
            link.setAttribute('download', filename);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showToast('Report exported successfully!', 'success');
        }
        
        // Toast notification
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                background: ${type === 'success' ? '#48bb78' : 
                           type === 'error' ? '#f56565' : 
                           type === 'warning' ? '#ed8936' : '#4299e1'};
                color: white;
                border-radius: 8px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
                z-index: 1000;
                animation: slideInRight 0.3s ease-out;
            `;
            
            toast.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 
                              type === 'error' ? 'fa-exclamation-triangle' : 
                              type === 'warning' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
                ${message}
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOutRight 0.3s ease-out';
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 3000);
        }
        
        // Add CSS animations for toast
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.style.display = 'none';
            }
        }
        
        // Auto-refresh every 30 seconds for pending leaves
        setInterval(() => {
            if (document.getElementById('statusFilter').value === 'pending') {
                location.reload();
            }
        }, 30000);
    </script>
</body>
</html>
<?php
// rankholder/manage_leaves.php - FIXED VERSION
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
    
    // Check if user is logged in
    if (!$user || $user['role'] !== 'rankholder') {
        header("Location: ../index.php");
        exit();
    }
    
    $rankholder_id = $user['user_id'];
    $service_type = $user['service_type'] ?? null;
    
    // Get filter from URL
    $status_filter = $_GET['status'] ?? 'pending';
    $service_filter = $_GET['service'] ?? ($service_type ?? 'all');
    
    // Process leave submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['cadet_id']) && isset($_POST['leave_reason']) && isset($_FILES['leave_proof'])) {
            $cadet_id = intval($_POST['cadet_id']);
            $leave_reason = trim($_POST['leave_reason']);
            $leave_type = $_POST['leave_type'] ?? 'sakit';
            
            // Handle file upload
            $leave_proof = null;
            if (isset($_FILES['leave_proof']) && $_FILES['leave_proof']['error'] === 0) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf', 'image/gif'];
                $file_type = $_FILES['leave_proof']['type'];
                $file_size = $_FILES['leave_proof']['size'];
                
                if (in_array($file_type, $allowed_types) && $file_size <= 5 * 1024 * 1024) { // 5MB max
                    $upload_dir = '../uploads/leave_proofs/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    // Get cadet info for filename
                    $cadetQuery = "SELECT military_number FROM users WHERE user_id = ?";
                    $cadetStmt = $db->prepare($cadetQuery);
                    $cadetStmt->bind_param("i", $cadet_id);
                    $cadetStmt->execute();
                    $cadetResult = $cadetStmt->get_result();
                    $cadet = $cadetResult->fetch_assoc();
                    $military_number = $cadet['military_number'] ?? 'unknown';
                    
                    $file_ext = pathinfo($_FILES['leave_proof']['name'], PATHINFO_EXTENSION);
                    $file_name = 'leave_' . $military_number . '_' . time() . '.' . $file_ext;
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['leave_proof']['tmp_name'], $file_path)) {
                        $leave_proof = 'uploads/leave_proofs/' . $file_name;
                    }
                }
            }
            
            // Insert leave record - FIXED: Removed start_date and end_date
            $insertQuery = "INSERT INTO attendance (user_id, date, status, checked_by, recorded_at, reason, leave_proof, is_leave, session_id) 
                           VALUES (?, CURDATE(), 'excused', ?, CURRENT_TIMESTAMP, ?, ?, 1, NULL)";
            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->bind_param("iiss", $cadet_id, $rankholder_id, $leave_reason, $leave_proof);
            
            if ($insertStmt->execute()) {
                $_SESSION['success'] = "Pelepasan berjaya direkod!";
            } else {
                $_SESSION['error'] = "Gagal merekod pelepasan!";
            }
            
            header("Location: manage_leaves.php");
            exit();
        }
    }
    
    // Get cadets with existing leave records for today - FIXED: Removed start_date and end_date
    $query = "SELECT 
                u.user_id,
                u.military_number,
                u.name,
                u.rank_level,
                u.service_type,
                a.attendance_id,
                a.date,
                a.reason,
                a.leave_proof,
                a.recorded_at,
                a.verified_by,
                CASE 
                    WHEN a.verified_by IS NOT NULL THEN 'approved'
                    WHEN a.leave_proof IS NOT NULL THEN 'submitted'
                    ELSE 'pending'
                END as leave_status
            FROM users u
            LEFT JOIN attendance a ON u.user_id = a.user_id 
                AND DATE(a.date) = CURDATE() 
                AND a.is_leave = 1
            WHERE u.role = 'cadet'";
    
    $params = [];
    $types = "";
    
    // Add service filter
    if ($service_filter !== 'all') {
        $query .= " AND u.service_type = ?";
        $params[] = $service_filter;
        $types .= "s";
    }
    
    // Add status filter
    if ($status_filter === 'with_leave') {
        $query .= " AND a.is_leave = 1";
    } elseif ($status_filter === 'without_leave') {
        $query .= " AND (a.is_leave IS NULL OR a.is_leave = 0)";
    } elseif ($status_filter === 'pending') {
        $query .= " AND a.is_leave = 1 AND a.verified_by IS NULL";
    }
    
    $query .= " ORDER BY u.service_type, u.name";
    
    if (!empty($params)) {
        $stmt = $db->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $cadetsResult = $stmt->get_result();
    } else {
        $stmt = $db->prepare($query);
        $stmt->execute();
        $cadetsResult = $stmt->get_result();
    }
    
    // Get leave statistics
    $statsQuery = "SELECT 
                    COUNT(DISTINCT u.user_id) as total_cadets,
                    SUM(CASE WHEN a.is_leave = 1 THEN 1 ELSE 0 END) as total_leaves,
                    SUM(CASE WHEN a.is_leave = 1 AND a.verified_by IS NOT NULL THEN 1 ELSE 0 END) as approved_leaves,
                    SUM(CASE WHEN a.is_leave = 1 AND a.verified_by IS NULL THEN 1 ELSE 0 END) as pending_leaves
                FROM users u
                LEFT JOIN attendance a ON u.user_id = a.user_id 
                    AND DATE(a.date) = CURDATE()
                WHERE u.role = 'cadet'";
    
    if ($service_filter !== 'all') {
        $statsQuery .= " AND u.service_type = ?";
        $statsStmt = $db->prepare($statsQuery);
        $statsStmt->bind_param("s", $service_filter);
    } else {
        $statsStmt = $db->prepare($statsQuery);
    }
    
    $statsStmt->execute();
    $statsResult = $statsStmt->get_result();
    $stats = $statsResult->fetch_assoc();
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Get service type label
function getServiceLabel($type) {
    $labels = [
        'darat' => 'Darat',
        'laut' => 'Laut', 
        'udara' => 'Udara',
        'all' => 'Semua'
    ];
    return $labels[$type] ?? $type;
}

// Get service badge color
function getServiceBadge($type) {
    switch($type) {
        case 'darat': return 'service-badge-darat';
        case 'laut': return 'service-badge-laut';
        case 'udara': return 'service-badge-udara';
        default: return 'service-badge-default';
    }
}

// Get leave status badge
function getLeaveStatusBadge($status) {
    switch($status) {
        case 'approved': return '<span class="status-badge approved">Lulus</span>';
        case 'submitted': return '<span class="status-badge submitted">Dihantar</span>';
        case 'pending': return '<span class="status-badge pending">Menunggu</span>';
        default: return '<span class="status-badge none">Tiada</span>';
    }
}

// Get leave type label
function getLeaveTypeLabel($type) {
    $labels = [
        'sakit' => 'Sakit',
        'cuti' => 'Cuti',
        'urusan' => 'Urusan Peribadi',
        'lain' => 'Lain-lain'
    ];
    return $labels[$type] ?? ucfirst($type);
}

// Format date
function formatDate($dateString) {
    if (empty($dateString)) {
        return '';
    }
    
    try {
        $date = strtotime($dateString);
        if ($date === false) {
            return '';
        }
        return date('d/m/Y', $date);
    } catch (Exception $e) {
        return '';
    }
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pelepasan - CAAMS</title>
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
            padding-bottom: 60px; /* Space for bottom nav */
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
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
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
        
        /* FILTERS */
        .filters-section {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        
        @media (min-width: 480px) {
            .filters-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-label {
            margin-bottom: 6px;
            color: var(--secondary);
            font-weight: 500;
            font-size: 0.85rem;
        }
        
        .filter-select {
            padding: 10px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 0.9rem;
            width: 100%;
            background: white;
            cursor: pointer;
        }
        
        /* CADETS SECTION */
        .cadets-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
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
        
        .cadets-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .cadets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
        }
        
        @media (min-width: 480px) {
            .cadets-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }
        }
        
        .cadet-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            border: 2px solid var(--gray-200);
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            min-height: 130px;
        }
        
        .cadet-card:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .cadet-card.selected {
            border-color: var(--accent);
            background: linear-gradient(135deg, rgba(49, 130, 206, 0.05) 0%, rgba(49, 130, 206, 0.02) 100%);
        }
        
        .cadet-card.has-leave {
            border-color: var(--purple);
        }
        
        .cadet-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }
        
        .cadet-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .avatar-darat {
            background: linear-gradient(135deg, var(--darat) 0%, #2f855a 100%);
        }
        
        .avatar-laut {
            background: linear-gradient(135deg, var(--laut) 0%, #2c5282 100%);
        }
        
        .avatar-udara {
            background: linear-gradient(135deg, var(--udara) 0%, #805ad5 100%);
        }
        
        .cadet-info h4 {
            color: var(--primary);
            margin-bottom: 2px;
            font-size: 0.95rem;
            line-height: 1.3;
            word-break: break-word;
        }
        
        .cadet-info p {
            color: var(--gray-600);
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .cadet-details {
            margin-top: 8px;
        }
        
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
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-badge.approved {
            background: rgba(72, 187, 120, 0.1);
            color: var(--success);
        }
        
        .status-badge.submitted {
            background: rgba(159, 122, 234, 0.1);
            color: var(--purple);
        }
        
        .status-badge.pending {
            background: rgba(237, 137, 54, 0.1);
            color: var(--warning);
        }
        
        .status-badge.none {
            background: rgba(160, 174, 192, 0.1);
            color: var(--gray-500);
        }
        
        /* LEAVE FORM SECTION */
        .leave-form-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            margin-top: 20px;
            display: none;
        }
        
        .selected-cadet-info {
            background: linear-gradient(135deg, rgba(159, 122, 234, 0.05) 0%, rgba(159, 122, 234, 0.02) 100%);
            border-left: 4px solid var(--purple);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .cadet-info-title {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }
        
        /* LEAVE FORM - FIXED: Removed date range fields */
        .leave-form {
            margin-top: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.95rem;
        }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s;
            background: white;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: var(--purple);
            outline: none;
            box-shadow: 0 0 0 3px rgba(159, 122, 234, 0.1);
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
        }
        
        .input-help {
            display: block;
            margin-top: 5px;
            font-size: 0.8rem;
            color: var(--gray-600);
        }
        
        /* FILE UPLOAD */
        .file-upload {
            border: 2px dashed var(--gray-300);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            margin-bottom: 15px;
        }
        
        .file-upload:hover {
            border-color: var(--purple);
            background: rgba(159, 122, 234, 0.05);
        }
        
        .file-upload i {
            font-size: 2rem;
            color: var(--purple);
            margin-bottom: 10px;
        }
        
        .file-input {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            opacity: 0;
            cursor: pointer;
        }
        
        .file-preview {
            display: none;
            margin-top: 15px;
            padding: 15px;
            background: var(--gray-100);
            border-radius: 8px;
            border: 1px solid var(--gray-300);
        }
        
        .file-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .file-icon {
            width: 40px;
            height: 40px;
            background: var(--purple);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }
        
        .file-details {
            flex: 1;
        }
        
        .file-name {
            font-weight: 600;
            margin-bottom: 3px;
            color: var(--primary);
        }
        
        .file-size {
            font-size: 0.85rem;
            color: var(--gray-600);
        }
        
        .remove-file {
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            font-size: 1.2rem;
            padding: 5px;
        }
        
        /* SUBMIT BUTTON */
        .btn-submit {
            width: 100%;
            background: linear-gradient(135deg, var(--purple) 0%, #805ad5 100%);
            color: white;
            border: none;
            padding: 14px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 15px;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(159, 122, 234, 0.3);
        }
        
        /* ALERTS */
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease-out;
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
        
        @keyframes slideIn {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* NO DATA STATE */
        .no-data {
            text-align: center;
            padding: 30px 15px;
            color: var(--gray-500);
        }
        
        .no-data i {
            font-size: 2.5rem;
            margin-bottom: 10px;
            opacity: 0.3;
        }
        
        .no-data h3 {
            font-size: 1.2rem;
            margin-bottom: 8px;
            color: var(--gray-600);
        }
        
        .no-data p {
            font-size: 0.9rem;
            line-height: 1.4;
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
        
        /* LOADING SPINNER */
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .loading i {
            font-size: 2rem;
            color: var(--accent);
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* TOUCH FRIENDLY */
        @media (hover: none) {
            .cadet-card:hover,
            .btn-submit:hover {
                transform: none;
            }
            
            .cadet-card:active {
                transform: scale(0.98);
                transition: transform 0.1s;
            }
            
            .btn-submit:active {
                transform: scale(0.98);
                transition: transform 0.1s;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="main-header">
            <div class="header-title">
                <h1>
                    <i class="fas fa-file-medical"></i>
                    Pengurusan Pelepasan
                </h1>
            </div>
            
            <!-- STATS -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_cadets'] ?? 0; ?></div>
                    <div class="stat-label">JUMLAH</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_leaves'] ?? 0; ?></div>
                    <div class="stat-label">PELEPASAN</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['approved_leaves'] ?? 0; ?></div>
                    <div class="stat-label">LULUS</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['pending_leaves'] ?? 0; ?></div>
                    <div class="stat-label">MENUNGGU</div>
                </div>
            </div>
        </div>
        
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
        
        <!-- FILTERS -->
        <div class="filters-section">
            <div class="filters-grid">
                <div class="filter-group">
                    <label class="filter-label">Perkhidmatan</label>
                    <select class="filter-select" id="serviceFilter" onchange="filterService()">
                        <option value="all" <?php echo $service_filter === 'all' ? 'selected' : ''; ?>>Semua</option>
                        <option value="darat" <?php echo $service_filter === 'darat' ? 'selected' : ''; ?>>Darat</option>
                        <option value="laut" <?php echo $service_filter === 'laut' ? 'selected' : ''; ?>>Laut</option>
                        <option value="udara" <?php echo $service_filter === 'udara' ? 'selected' : ''; ?>>Udara</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select class="filter-select" id="statusFilter" onchange="filterStatus()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>Semua Kadet</option>
                        <option value="with_leave" <?php echo $status_filter === 'with_leave' ? 'selected' : ''; ?>>Ada Pelepasan</option>
                        <option value="without_leave" <?php echo $status_filter === 'without_leave' ? 'selected' : ''; ?>>Tiada Pelepasan</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Menunggu Kelulusan</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Tindakan</label>
                    <button class="filter-select" style="background: var(--purple); color: white; border: none; cursor: pointer;" 
                            onclick="clearSelection()">
                        <i class="fas fa-times"></i> Clear Pilihan
                    </button>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">&nbsp;</label>
                    <button class="filter-select" style="background: var(--accent); color: white; border: none; cursor: pointer;" 
                            onclick="location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
        
        <!-- CADETS LIST -->
        <div class="cadets-section">
            <div class="cadets-header">
                <h2 class="section-title" style="margin: 0; border: none; padding: 0;">
                    <i class="fas fa-users"></i> Senarai Kadet
                    <span style="font-size: 0.9rem; color: var(--gray-500); margin-left: 6px;">
                        (<?php echo $cadetsResult->num_rows; ?>)
                    </span>
                </h2>
            </div>
            
            <div style="margin-bottom: 15px; padding: 10px; background: var(--gray-100); border-radius: 6px; border-left: 3px solid var(--purple);">
                <p style="font-weight: 600; color: var(--primary); margin-bottom: 4px; font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i> Klik kadet untuk rekod pelepasan
                </p>
                <p style="color: var(--gray-600); font-size: 0.8rem;">
                    Pilih kadet yang memerlukan pelepasan
                </p>
            </div>
            
            <div class="cadets-grid">
                <?php if ($cadetsResult->num_rows > 0): 
                    while($cadet = $cadetsResult->fetch_assoc()): 
                        $avatarClass = 'avatar-' . ($cadet['service_type'] ?? 'default');
                        $hasLeave = !empty($cadet['attendance_id']);
                        $cardClass = $hasLeave ? 'has-leave' : '';
                ?>
                <div class="cadet-card <?php echo $cardClass; ?>" 
                     onclick="selectCadet(<?php echo $cadet['user_id']; ?>, '<?php echo htmlspecialchars($cadet['name']); ?>', '<?php echo $cadet['military_number']; ?>', this)">
                    
                    <div class="cadet-header">
                        <div class="cadet-avatar <?php echo $avatarClass; ?>">
                            <?php echo strtoupper(substr($cadet['name'] ?? '', 0, 1)); ?>
                        </div>
                        <div class="cadet-info">
                            <h4><?php echo htmlspecialchars($cadet['name'] ?? ''); ?></h4>
                            <p><?php echo $cadet['military_number'] ?? ''; ?></p>
                        </div>
                    </div>
                    
                    <div class="cadet-details">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                            <span class="service-badge <?php echo getServiceBadge($cadet['service_type'] ?? ''); ?>">
                                <?php echo substr(getServiceLabel($cadet['service_type'] ?? ''), 0, 1); ?>
                            </span>
                            <span style="font-size: 0.75rem; color: var(--gray-500);">
                                <?php echo ucfirst($cadet['rank_level'] ?? ''); ?>
                            </span>
                        </div>
                        
                        <div>
                            <?php echo getLeaveStatusBadge($cadet['leave_status'] ?? 'none'); ?>
                        </div>
                        
                        <?php if ($hasLeave && !empty($cadet['reason'])): ?>
                        <div style="margin-top: 8px; font-size: 0.8rem; color: var(--gray-600);">
                            <i class="fas fa-sticky-note"></i> 
                            <?php echo substr(htmlspecialchars($cadet['reason']), 0, 20); ?>
                            <?php if (strlen($cadet['reason']) > 20): ?>...<?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
                <?php else: ?>
                <div class="no-data" style="grid-column: 1 / -1;">
                    <i class="fas fa-users-slash"></i>
                    <h3>Tiada Kadet</h3>
                    <p>Tidak ada kadet ditemukan dengan filter semasa</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- LEAVE FORM (Hidden Initially) -->
        <div class="leave-form-section" id="leaveFormSection">
            <h2 class="section-title">
                <i class="fas fa-file-upload"></i> Rekod Pelepasan
            </h2>
            
            <!-- Selected Cadet Info -->
            <div class="selected-cadet-info" id="selectedCadetInfo">
                <!-- Will be populated by JavaScript -->
            </div>
            
            <!-- Leave Form - FIXED: Removed date range fields -->
            <form id="leaveForm" method="POST" action="" enctype="multipart/form-data" class="leave-form">
                <input type="hidden" name="cadet_id" id="cadetId">
                <input type="hidden" name="leave_type" id="leave_type" value="sakit">
                
                <!-- Reason -->
                <div class="form-group">
                    <label for="leave_reason" class="form-label">Alasan Pelepasan</label>
                    <textarea name="leave_reason" 
                              id="leave_reason" 
                              class="form-textarea" 
                              placeholder="Nyatakan alasan pelepasan dengan terperinci..."
                              required></textarea>
                    <small class="input-help">
                        <i class="fas fa-info-circle"></i> Sila berikan alasan yang jelas dan lengkap
                    </small>
                </div>
                
                <!-- Proof Upload -->
                <div class="form-group">
                    <label class="form-label">Bukti Pelepasan</label>
                    <div class="file-upload" onclick="document.getElementById('leave_proof').click()">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p style="margin-bottom: 5px; font-weight: 600;">Klik untuk upload file</p>
                        <p style="font-size: 0.9rem; color: var(--gray-600);">
                            Format: PNG, JPG, PDF (Maksimum: 5MB)
                        </p>
                        <input type="file" 
                               name="leave_proof" 
                               id="leave_proof" 
                               class="file-input"
                               accept=".png,.jpg,.jpeg,.pdf"
                               required>
                    </div>
                    <div class="file-preview" id="filePreview">
                        <!-- File preview will appear here -->
                    </div>
                </div>
                
                <!-- Submit Button -->
                <button type="submit" class="btn-submit">
                    <i class="fas fa-paper-plane"></i> Hantar Permohonan Pelepasan
                </button>
            </form>
        </div>
    </div>
    
    <!-- LOADING SPINNER -->
    <div class="loading" id="loading">
        <i class="fas fa-spinner"></i>
        <p>Memproses...</p>
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
        
        <a href="manage_leaves.php" class="mobile-nav-item active">
            <div class="mobile-nav-icon">
                <i class="fas fa-file-upload"></i>
            </div>
            <div class="mobile-nav-label">Pelepasan</div>
        </a>
    </nav>
    
    <script>
        // Global variables
        let selectedCadet = null;
        let selectedCard = null;
        
        // Filter functions
        function filterService() {
            const service = document.getElementById('serviceFilter').value;
            const status = document.getElementById('statusFilter').value;
            window.location.href = `manage_leaves.php?service=${service}&status=${status}`;
        }
        
        function filterStatus() {
            const service = document.getElementById('serviceFilter').value;
            const status = document.getElementById('statusFilter').value;
            window.location.href = `manage_leaves.php?service=${service}&status=${status}`;
        }
        
        // Select cadet for leave application
        function selectCadet(cadetId, cadetName, militaryNumber, cardElement) {
            // Deselect previous card
            if (selectedCard) {
                selectedCard.classList.remove('selected');
            }
            
            // Select new card
            selectedCadet = {
                id: cadetId,
                name: cadetName,
                militaryNumber: militaryNumber
            };
            selectedCard = cardElement;
            cardElement.classList.add('selected');
            
            // Update form
            document.getElementById('cadetId').value = cadetId;
            
            // Update selected cadet info
            const infoDiv = document.getElementById('selectedCadetInfo');
            infoDiv.innerHTML = `
                <div class="cadet-info-title">
                    <i class="fas fa-user"></i> Kadet Dipilih
                </div>
                <div style="color: var(--gray-600); font-size: 0.95rem;">
                    <p><strong>${cadetName}</strong></p>
                    <p><i class="fas fa-id-card"></i> ${militaryNumber}</p>
                    <p style="margin-top: 5px; font-size: 0.9rem;">
                        <i class="fas fa-info-circle"></i> Sila lengkapkan maklumat pelepasan di bawah
                    </p>
                </div>
            `;
            
            // Show leave form section
            document.getElementById('leaveFormSection').style.display = 'block';
            
            // Scroll to form
            setTimeout(() => {
                document.getElementById('leaveFormSection').scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'start' 
                });
            }, 300);
        }
        
        // Clear selection
        function clearSelection() {
            if (selectedCard) {
                selectedCard.classList.remove('selected');
            }
            selectedCadet = null;
            selectedCard = null;
            document.getElementById('leaveFormSection').style.display = 'none';
        }
        
        // File upload preview
        document.getElementById('leave_proof').addEventListener('change', function(e) {
            if (this.files.length > 0) {
                const file = this.files[0];
                const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
                
                // Check file type
                const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Jenis file tidak disokong. Sila upload PNG, JPG, atau PDF sahaja.');
                    this.value = '';
                    return;
                }
                
                // Check file size (5MB max)
                if (file.size > 5 * 1024 * 1024) {
                    alert('File terlalu besar. Maksimum saiz adalah 5MB.');
                    this.value = '';
                    return;
                }
                
                // Show preview
                const preview = document.getElementById('filePreview');
                preview.innerHTML = `
                    <div class="file-info">
                        <div class="file-icon">
                            <i class="fas fa-file"></i>
                        </div>
                        <div class="file-details">
                            <div class="file-name">${file.name}</div>
                            <div class="file-size">${fileSizeMB} MB</div>
                        </div>
                        <button type="button" class="remove-file" onclick="removeFile()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
                preview.style.display = 'block';
            }
        });
        
        // Remove file
        function removeFile() {
            document.getElementById('leave_proof').value = '';
            document.getElementById('filePreview').style.display = 'none';
            document.getElementById('filePreview').innerHTML = '';
        }
        
        // Form validation and submission
        document.getElementById('leaveForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (!selectedCadet) {
                alert('Sila pilih kadet terlebih dahulu');
                return false;
            }
            
            // Show loading
            const loading = document.getElementById('loading');
            loading.style.display = 'block';
            
            // Disable submit button
            const submitBtn = this.querySelector('.btn-submit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menghantar...';
            
            // Submit form
            this.submit();
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Touch events for mobile
            document.addEventListener('touchstart', function() {
                // Add touch feedback if needed
            });
            
            // Prevent zoom on double tap
            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(event) {
                const now = (new Date()).getTime();
                if (now - lastTouchEnd <= 300) {
                    event.preventDefault();
                }
                lastTouchEnd = now;
            }, false);
        });
        
        // Handle orientation change
        window.addEventListener('orientationchange', function() {
            setTimeout(() => {
                window.scrollTo(0, 0);
            }, 100);
        });
    </script>
</body>
</html>
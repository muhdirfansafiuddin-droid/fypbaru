<?php
// cadet/apply_excuse.php - MOBILE VIEW VERSION (ENGLISH)
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
    $user_name = $user['name'];
    $military_number = $user['military_number'];
    $today = date('Y-m-d');
    
    // Get upcoming training sessions (30 DAYS AHEAD)
    $sessionsQuery = "SELECT 
                        ts.session_id,
                        ts.training_type,
                        ts.location,
                        ts.training_date,
                        ts.session_time,
                        a.status,
                        a.is_excuse,
                        a.reason,
                        a.verified_at,
                        u.name as verified_by_name,
                        CASE 
                            WHEN ts.training_date > CURDATE() THEN 'upcoming'
                            WHEN ts.training_date = CURDATE() THEN 'today'
                            ELSE 'past'
                        END as date_status
                    FROM training_sessions ts
                    LEFT JOIN attendance a ON ts.session_id = a.session_id 
                        AND a.user_id = ?
                        AND DATE(a.date) = DATE(ts.training_date)
                    LEFT JOIN users u ON a.verified_by = u.user_id
                    WHERE ts.training_date > CURDATE() -- ONLY UPCOMING
                    AND ts.training_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) -- 30 DAYS
                    AND ts.is_active = 1
                    ORDER BY ts.training_date, ts.session_time";
    
    $sessionsStmt = $db->prepare($sessionsQuery);
    $sessionsStmt->bind_param("i", $cadet_id);
    $sessionsStmt->execute();
    $sessionsResult = $sessionsStmt->get_result();
    
    // Get existing excuses (pending and approved)
    $excusesQuery = "SELECT 
                        a.date,
                        a.reason,
                        a.status,
                        a.proof_file,
                        ts.training_type,
                        ts.location,
                        a.recorded_at,
                        a.verified_at,
                        u.name as verified_by_name,
                        ru.name as rankholder_name
                    FROM attendance a
                    JOIN training_sessions ts ON a.session_id = ts.session_id
                    LEFT JOIN users u ON a.verified_by = u.user_id
                    LEFT JOIN users ru ON a.checked_by = ru.user_id
                    WHERE a.user_id = ?
                    AND a.is_excuse = 1
                    ORDER BY a.date DESC
                    LIMIT 10";
    
    $excusesStmt = $db->prepare($excusesQuery);
    $excusesStmt->bind_param("i", $cadet_id);
    $excusesStmt->execute();
    $excusesResult = $excusesStmt->get_result();
    
    // Process excuse application
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_excuse'])) {
        $session_id = intval($_POST['session_id']);
        $reason = trim($_POST['reason']);
        $proof_file = null;
        
        // Validate
        if (empty($session_id)) {
            $_SESSION['error'] = "Please select a training session.";
        } elseif (empty($reason) || strlen($reason) < 10) {
            $_SESSION['error'] = "Please provide a complete reason (minimum 10 characters).";
        } else {
            // Check if session exists and is in future
            $checkSession = "SELECT training_date FROM training_sessions 
                           WHERE session_id = ? AND training_date > CURDATE()";
            $checkStmt = $db->prepare($checkSession);
            $checkStmt->bind_param("i", $session_id);
            $checkStmt->execute();
            $sessionCheck = $checkStmt->get_result();
            
            if ($sessionCheck->num_rows === 0) {
                $_SESSION['error'] = "Training session not found or has ended.";
            } else {
                $sessionData = $sessionCheck->fetch_assoc();
                $session_date = $sessionData['training_date'];
                
                // Check if already applied for this session
                $checkExisting = "SELECT attendance_id FROM attendance 
                                WHERE user_id = ? AND session_id = ? AND DATE(date) = DATE(?)";
                $existingStmt = $db->prepare($checkExisting);
                $existingStmt->bind_param("iis", $cadet_id, $session_id, $session_date);
                $existingStmt->execute();
                $existingResult = $existingStmt->get_result();
                
                if ($existingResult->num_rows > 0) {
                    $_SESSION['error'] = "You already have a record for this session.";
                } else {
                    // Handle file upload if any
                    if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = __DIR__ . '/../uploads/excuses/';
                        
                        // Create directory if not exists
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $file_name = time() . '_' . basename($_FILES['proof_file']['name']);
                        $file_path = $upload_dir . $file_name;
                        
                        // Check file size (5MB max)
                        if ($_FILES['proof_file']['size'] > 5 * 1024 * 1024) {
                            $_SESSION['error'] = "File too large! Maximum 5MB only.";
                            header("Location: apply_excuse.php");
                            exit();
                        }
                        
                        // Check file type
                        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
                        $file_type = mime_content_type($_FILES['proof_file']['tmp_name']);
                        
                        if (in_array($file_type, $allowed_types)) {
                            if (move_uploaded_file($_FILES['proof_file']['tmp_name'], $file_path)) {
                                $proof_file = 'uploads/excuses/' . $file_name;
                            } else {
                                $_SESSION['error'] = "Failed to upload file. Please try again.";
                                header("Location: apply_excuse.php");
                                exit();
                            }
                        } else {
                            $_SESSION['error'] = "Only image files (JPEG, PNG, GIF) and PDF are allowed.";
                            header("Location: apply_excuse.php");
                            exit();
                        }
                    }
                    
                    // Insert excuse application - without absent_type
                    $status = 'pending';
                    
                    $insertQuery = "INSERT INTO attendance 
                                    (user_id, session_id, date, status, reason, proof_file, is_excuse, recorded_at) 
                                   VALUES (?, ?, ?, ?, ?, ?, 1, NOW())";
                    $insertStmt = $db->prepare($insertQuery);
                    $insertStmt->bind_param("iissss", $cadet_id, $session_id, $session_date, $status, $reason, $proof_file);
                    
                    if ($insertStmt->execute()) {
                        $_SESSION['success'] = "Excuse application submitted successfully! Status: WAITING FOR ADMIN APPROVAL";
                        header("Location: apply_excuse.php");
                        exit();
                    } else {
                        // Debug error
                        error_log("MySQL Error: " . $insertStmt->error);
                        error_log("Query: " . $insertQuery);
                        
                        $_SESSION['error'] = "Failed to submit application: " . $insertStmt->error;
                    }
                }
            }
        }
    }
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Helper functions
function formatDate($dateString) {
    if (empty($dateString)) return '';
    try {
        $date = strtotime($dateString);
        return $date ? date('d/m/Y', $date) : '';
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

function getStatusBadge($status, $verified_by = null, $verified_at = null) {
    switch($status) {
        case 'approved':
            $badge = '<span class="status-badge approved">APPROVED</span>';
            if ($verified_by && $verified_at) {
                $badge .= '<br><small style="color: #718096; font-size: 0.8rem; margin-top: 3px; display: block;">' . 
                         '<i class="fas fa-check-circle"></i> Approved on ' . date('d/m/Y H:i', strtotime($verified_at)) . 
                         '</small>';
            }
            return $badge;
            
        case 'rejected':
            $badge = '<span class="status-badge rejected">REJECTED</span>';
            if ($verified_by && $verified_at) {
                $badge .= '<br><small style="color: #718096; font-size: 0.8rem; margin-top: 3px; display: block;">' . 
                         '<i class="fas fa-times-circle"></i> Rejected on ' . date('d/m/Y H:i', strtotime($verified_at)) . 
                         '</small>';
            }
            return $badge;
            
        case 'pending':
            return '<span class="status-badge pending">WAITING FOR APPROVAL</span>';
            
        default:
            return '<span class="status-badge unknown">' . strtoupper($status) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for Excuse - CAAMS</title>
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
            --cuti: #f6ad55;
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
        
        /* INFO BOX */
        .info-box {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .info-box i {
            font-size: 1.5rem;
        }
        
        .info-content h3 {
            font-size: 1rem;
            margin-bottom: 5px;
        }
        
        .info-content p {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        
        /* SESSIONS LIST */
        .sessions-list {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .sessions-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .sessions-header .section-title {
            color: var(--primary);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .date-filter {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .date-filter-btn {
            padding: 6px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 15px;
            background: white;
            color: var(--secondary);
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .date-filter-btn.active {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }
        
        .date-filter-btn:hover:not(.active) {
            background: #f7fafc;
        }
        
        .session-item {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid var(--accent);
            transition: all 0.3s ease;
        }
        
        .session-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .session-title {
            font-weight: 600;
            color: var(--primary);
            font-size: 1rem;
            flex: 1;
        }
        
        .session-date {
            background: rgba(49, 130, 206, 0.1);
            color: var(--accent);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
            margin-left: 10px;
        }
        
        .session-details {
            color: var(--gray);
            font-size: 0.85rem;
            margin-bottom: 10px;
        }
        
        .session-details p {
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .session-status {
            font-size: 0.8rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 12px;
            display: inline-block;
        }
        
        .status-available {
            background: rgba(72, 187, 120, 0.1);
            color: var(--success);
        }
        
        .status-pending {
            background: rgba(237, 137, 54, 0.1);
            color: var(--warning);
        }
        
        .status-approved {
            background: rgba(72, 187, 120, 0.1);
            color: var(--success);
        }
        
        .status-rejected {
            background: rgba(245, 101, 101, 0.1);
            color: var(--danger);
        }
        
        /* FORM SECTION */
        .form-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .section-title {
            color: var(--primary);
            font-size: 1.1rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--primary);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-select, .form-input, .form-textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }
        
        .form-select:focus, .form-input:focus, .form-textarea:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        /* FILE UPLOAD */
        .file-upload-container {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            border: 2px dashed #cbd5e0;
            transition: all 0.3s ease;
        }
        
        .file-upload-container:hover {
            border-color: var(--accent);
            background: rgba(49, 130, 206, 0.05);
        }
        
        .file-input {
            display: none;
        }
        
        .file-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            text-align: center;
        }
        
        .file-icon {
            font-size: 2rem;
            color: var(--accent);
        }
        
        .file-hint {
            color: var(--gray);
            font-size: 0.85rem;
            line-height: 1.4;
        }
        
        .file-preview {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            padding: 10px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        
        .file-preview i {
            color: var(--accent);
            font-size: 1.2rem;
        }
        
        .file-info {
            flex: 1;
        }
        
        .file-name {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--primary);
        }
        
        .file-size {
            font-size: 0.75rem;
            color: var(--gray);
        }
        
        .remove-file {
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            font-size: 1.2rem;
            padding: 5px;
        }
        
        /* FORM NOTE */
        .form-note {
            background: #fff8e1;
            border-left: 4px solid var(--warning);
            padding: 12px;
            border-radius: 6px;
            margin-top: 15px;
            font-size: 0.85rem;
            color: var(--secondary);
        }
        
        .form-note i {
            color: var(--warning);
            margin-right: 8px;
        }
        
        /* SUBMIT BUTTON */
        .btn-submit {
            background: linear-gradient(135deg, var(--success) 0%, #38a169 100%);
            color: white;
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(72, 187, 120, 0.3);
        }
        
        /* EXCUSE HISTORY */
        .history-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .excuse-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .excuse-item {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            border-left: 4px solid var(--excuse);
        }
        
        .excuse-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .excuse-title {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.95rem;
            flex: 1;
        }
        
        .excuse-date {
            color: var(--gray);
            font-size: 0.8rem;
            white-space: nowrap;
            margin-left: 10px;
        }
        
        .excuse-details {
            color: var(--gray);
            font-size: 0.85rem;
            margin-bottom: 10px;
        }
        
        .excuse-details p {
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .excuse-reason {
            background: white;
            border-radius: 6px;
            padding: 10px;
            margin-top: 8px;
            font-size: 0.85rem;
            border: 1px solid #e2e8f0;
        }
        
        .excuse-reason strong {
            color: var(--primary);
        }
        
        .status-badge {
            padding: 6px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            margin-top: 5px;
        }
        
        .status-badge.pending {
            background: rgba(237, 137, 54, 0.1);
            color: var(--warning);
            border: 1px solid rgba(237, 137, 54, 0.3);
        }
        
        .status-badge.approved {
            background: rgba(72, 187, 120, 0.1);
            color: var(--success);
            border: 1px solid rgba(72, 187, 120, 0.3);
        }
        
        .status-badge.rejected {
            background: rgba(245, 101, 101, 0.1);
            color: var(--danger);
            border: 1px solid rgba(245, 101, 101, 0.3);
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
        
        /* NO DATA */
        .no-data {
            text-align: center;
            padding: 30px;
            color: #718096;
        }
        
        .no-data i {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.3;
        }
        
        /* MOBILE NAVIGATION */
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
        
        /* ANIMATIONS */
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <h1>
                <i class="fas fa-file-medical"></i>
                Apply for Excuse
            </h1>
            <div class="user-info">
                <div class="user-details">
                    <div class="user-avatar">
                        <?php 
                            $initials = strtoupper(substr($user_name, 0, 1));
                            echo $initials;
                        ?>
                    </div>
                    <div class="user-text">
                        <h3><?php echo htmlspecialchars($user_name); ?></h3>
                        <p>Military No: <?php echo $military_number; ?></p>
                        <div class="user-badges">
                            <span class="service-badge"><?php echo ucfirst($user['service_type'] ?? 'Service'); ?></span>
                            <span class="rank-badge"><?php echo ucfirst($user['rank_level'] ?? 'Cadet'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- INFO BOX -->
        <div class="info-box fade-in">
            <i class="fas fa-info-circle"></i>
            <div class="info-content">
                <h3>Important Notice!</h3>
                <p>Excuse applications can only be made for UPCOMING activities (within 30 days). Admin will review and update your application status.</p>
            </div>
        </div>
        
        <!-- ALERTS -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success fade-in">
                <i class="fas fa-check-circle"></i> 
                <div><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error fade-in">
                <i class="fas fa-exclamation-circle"></i> 
                <div><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
            </div>
        <?php endif; ?>
        
        <!-- UPCOMING SESSIONS (30 DAYS) -->
        <div class="sessions-list fade-in">
            <div class="sessions-header">
                <h2 class="section-title">
                    <i class="fas fa-calendar-alt"></i>
                    Upcoming Sessions
                </h2>
                
                <div class="date-filter">
                    <button class="date-filter-btn active" onclick="filterSessions('all')">All</button>
                    <button class="date-filter-btn" onclick="filterSessions('week')">This Week</button>
                </div>
            </div>
            
            <?php if ($sessionsResult->num_rows > 0): ?>
            <div class="excuse-list" id="sessionsContainer">
                <?php while($session = $sessionsResult->fetch_assoc()): 
                    $session_date = $session['training_date'];
                    $has_record = !empty($session['status']);
                    $is_excuse = $session['is_excuse'] ?? false;
                    $date_status = $session['date_status'];
                    $verified_by = $session['verified_by_name'] ?? null;
                    $verified_at = $session['verified_at'] ?? null;
                ?>
                <div class="session-item" data-date="<?php echo $session_date; ?>">
                    <div class="session-header">
                        <div class="session-title"><?php echo htmlspecialchars($session['training_type']); ?></div>
                        <div class="session-date"><?php echo formatDate($session_date); ?></div>
                    </div>
                    <div class="session-details">
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($session['location']); ?></p>
                        <p><i class="far fa-clock"></i> <?php echo getSessionTimeLabel($session['session_time']); ?></p>
                        
                        <?php if ($has_record && !empty($session['reason'])): ?>
                            <p style="margin-top: 8px; color: var(--warning);">
                                <i class="fas fa-sticky-note"></i> 
                                <strong>Reason:</strong> <?php echo htmlspecialchars(substr($session['reason'], 0, 100)); ?><?php echo strlen($session['reason']) > 100 ? '...' : ''; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($has_record): ?>
                        <div class="session-status 
                            <?php 
                            if ($is_excuse && $session['status'] === 'pending') {
                                echo 'status-pending';
                            } elseif ($is_excuse && $session['status'] === 'approved') {
                                echo 'status-approved';
                            } elseif ($is_excuse && $session['status'] === 'rejected') {
                                echo 'status-rejected';
                            } elseif ($session['status'] === 'present') {
                                echo 'status-approved';
                            } elseif ($session['status'] === 'absent') {
                                echo 'status-rejected';
                            } else {
                                echo 'status-pending';
                            }
                            ?>">
                            <?php 
                            if ($is_excuse && $session['status'] === 'pending') {
                                echo 'Already Applied (Waiting)';
                            } elseif ($is_excuse && $session['status'] === 'approved') {
                                echo 'Excuse APPROVED';
                            } elseif ($is_excuse && $session['status'] === 'rejected') {
                                echo 'Excuse REJECTED';
                            } elseif ($session['status'] === 'present') {
                                echo 'Already Attended';
                            } elseif ($session['status'] === 'absent') {
                                echo 'Not Attended';
                            } else {
                                echo 'Not Recorded Yet';
                            }
                            ?>
                            
                            <?php if ($verified_at && ($session['status'] === 'approved' || $session['status'] === 'rejected')): ?>
                                <br><small style="color: #718096; font-size: 0.7rem; margin-top: 3px; display: block;">
                                    <i class="fas fa-user-check"></i> 
                                    <?php echo $session['status'] === 'approved' ? 'Approved' : 'Rejected'; ?> on 
                                    <?php echo date('d/m/Y H:i', strtotime($verified_at)); ?>
                                    <?php if ($verified_by): ?>
                                        by <?php echo htmlspecialchars($verified_by); ?>
                                    <?php endif; ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="session-status status-available">
                            Available for Excuse Application
                        </div>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-calendar-times"></i>
                <h3>No Upcoming Sessions</h3>
                <p>No training sessions scheduled for the next 30 days</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- EXCUSE APPLICATION FORM -->
        <div class="form-section fade-in">
            <h2 class="section-title">
                <i class="fas fa-file-import"></i>
                Application Form
            </h2>
            
            <form method="POST" action="" enctype="multipart/form-data" id="excuseForm">
                <input type="hidden" name="apply_excuse" value="1">
                
                <!-- Session Selection -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-dumbbell"></i> Select Training Session
                    </label>
                    <select name="session_id" class="form-select" required id="sessionSelect">
                        <option value="">-- Please select a training session --</option>
                        <?php 
                        $sessionsResult->data_seek(0);
                        while($session = $sessionsResult->fetch_assoc()):
                            // Only show sessions without existing records and upcoming
                            if (empty($session['status']) && $session['date_status'] !== 'past'): 
                        ?>
                        <option value="<?php echo $session['session_id']; ?>" data-date="<?php echo $session['training_date']; ?>">
                            <?php echo htmlspecialchars($session['training_type']); ?> - 
                            <?php echo formatDate($session['training_date']); ?> (<?php echo getSessionTimeLabel($session['session_time']); ?>)
                        </option>
                        <?php 
                            endif;
                        endwhile; 
                        ?>
                    </select>
                </div>
                
                <!-- Reason -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-comment-medical"></i> Excuse Reason
                    </label>
                    <textarea name="reason" class="form-textarea" 
                              placeholder="Please state your excuse reason in detail. Example: family event, important matters, health issues, etc."
                              required minlength="10"></textarea>
                    <small style="display: block; margin-top: 5px; color: var(--gray); font-size: 0.8rem;">
                        Minimum 10 characters
                    </small>
                </div>
                
                <!-- File Upload -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-file-upload"></i> Supporting Document (Optional)
                    </label>
                    <div class="file-upload-container">
                        <input type="file" name="proof_file" id="proofFile" class="file-input" 
                               accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx">
                        <label for="proofFile" class="file-label">
                            <div class="file-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <div class="file-hint">
                                Click to upload supporting document<br>
                                (Image, Document, PDF - Max: 5MB)
                            </div>
                        </label>
                    </div>
                    <div id="filePreview" class="file-preview" style="display: none;">
                        <i class="fas fa-file"></i>
                        <div class="file-info">
                            <div class="file-name" id="fileName"></div>
                            <div class="file-size" id="fileSize"></div>
                        </div>
                        <button type="button" class="remove-file" onclick="removeFile()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Note -->
                <div class="form-note">
                    <i class="fas fa-info-circle"></i>
                    <strong>Note:</strong> Applications will be processed by ADMIN. Please ensure the information provided is accurate. Excuse applications must be made at least 24 hours before the training session starts. Status will change to "APPROVED" or "REJECTED" after review by admin.
                </div>
                
                <!-- Submit Button -->
                <button type="submit" class="btn-submit">
                    <i class="fas fa-paper-plane"></i> Submit Application
                </button>
            </form>
        </div>
        
        <!-- EXCUSE HISTORY -->
        <div class="history-section fade-in">
            <h2 class="section-title">
                <i class="fas fa-history"></i>
                Application History
            </h2>
            
            <?php if ($excusesResult->num_rows > 0): ?>
            <div class="excuse-list">
                <?php while($excuse = $excusesResult->fetch_assoc()): ?>
                <div class="excuse-item">
                    <div class="excuse-header">
                        <div class="excuse-title">
                            <?php echo htmlspecialchars($excuse['training_type']); ?>
                        </div>
                        <div class="excuse-date">
                            <?php echo formatDate($excuse['date']); ?>
                        </div>
                    </div>
                    <div class="excuse-details">
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($excuse['location']); ?></p>
                        <p><i class="far fa-clock"></i> 
                            <?php echo date('d/m/Y H:i', strtotime($excuse['recorded_at'])); ?>
                        </p>
                    </div>
                    <div class="excuse-reason">
                        <strong>Reason:</strong> <?php echo htmlspecialchars($excuse['reason']); ?>
                    </div>
                    
                    <?php if ($excuse['proof_file']): ?>
                    <div style="margin-top: 8px;">
                        <a href="<?php echo htmlspecialchars($excuse['proof_file']); ?>" 
                           target="_blank" 
                           style="color: var(--accent); text-decoration: none; font-size: 0.85rem;">
                            <i class="fas fa-paperclip"></i> View Supporting Document
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 10px;">
                        <?php echo getStatusBadge(
                            $excuse['status'], 
                            $excuse['verified_by_name'],
                            $excuse['verified_at']
                        ); ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-file-slash"></i>
                <h3>No Excuse Records</h3>
                <p>You have not made any excuse applications</p>
            </div>
            <?php endif; ?>
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
        
        <a href="view_allowance.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-money-bill"></i>
            </div>
            <div class="mobile-nav-label">Allowance</div>
        </a>
        
        <a href="apply_excuse.php" class="mobile-nav-item active">
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
        // File upload preview
        const fileInput = document.getElementById('proofFile');
        const filePreview = document.getElementById('filePreview');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        
        fileInput.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const file = this.files[0];
                const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
                
                // Check file size (5MB max)
                if (file.size > 5 * 1024 * 1024) {
                    alert('File too large! Please select a file less than 5MB.');
                    this.value = '';
                    return;
                }
                
                // Show preview
                fileName.textContent = file.name;
                fileSize.textContent = fileSizeMB + ' MB';
                filePreview.style.display = 'flex';
            }
        });
        
        function removeFile() {
            fileInput.value = '';
            filePreview.style.display = 'none';
        }
        
        // Form validation
        document.getElementById('excuseForm').addEventListener('submit', function(e) {
            const sessionSelect = document.getElementById('sessionSelect');
            const reasonTextarea = document.querySelector('textarea[name="reason"]');
            
            if (!sessionSelect.value) {
                e.preventDefault();
                alert('Please select a training session.');
                sessionSelect.focus();
                return false;
            }
            
            if (!reasonTextarea.value.trim() || reasonTextarea.value.trim().length < 10) {
                e.preventDefault();
                alert('Please fill in the excuse reason completely (minimum 10 characters).');
                reasonTextarea.focus();
                return false;
            }
            
            // Show loading
            const submitBtn = this.querySelector('.btn-submit');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            
            // Re-enable button after 5 seconds if still on page
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }, 5000);
            
            return true;
        });
        
        // Date filtering for sessions
        function filterSessions(filter) {
            const dateFilterBtns = document.querySelectorAll('.date-filter-btn');
            dateFilterBtns.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            const sessionItems = document.querySelectorAll('.session-item');
            const today = new Date();
            
            sessionItems.forEach(item => {
                const dateStr = item.getAttribute('data-date');
                const sessionDate = new Date(dateStr);
                
                let show = true;
                
                if (filter === 'week') {
                    const nextWeek = new Date(today);
                    nextWeek.setDate(today.getDate() + 7);
                    show = sessionDate >= today && sessionDate <= nextWeek;
                }
                // 'all' shows everything
                
                item.style.display = show ? 'block' : 'none';
            });
        }
        
        // Auto-select session based on date
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-refresh page every 60 seconds to check for updates
            setInterval(() => {
                if (!document.hidden) {
                    window.location.reload();
                }
            }, 60000);
            
            // Add animation to elements
            const fadeElements = document.querySelectorAll('.fade-in');
            fadeElements.forEach((element, index) => {
                element.style.animationDelay = `${index * 0.1}s`;
            });
        });
        
        // Mobile nav interaction
        document.querySelectorAll('.mobile-nav-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.mobile-nav-item').forEach(nav => {
                    nav.classList.remove('active');
                });
                this.classList.add('active');
            });
        });
    </script>
</body>
</html>
<?php
// rankholder/take_attendance.php - MOBILE FRIENDLY VERSION
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
    $default_service_type = $user['service_type'] ?? null;
    
    // Get filter from URL or use default
    $service_filter = $_GET['service'] ?? $default_service_type ?? 'all';
    
    // Process attendance form (BULK)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['session_id']) && isset($_POST['status']) && isset($_POST['cadets'])) {
            $session_id = intval($_POST['session_id']);
            $status = $_POST['status'];
            $cadets = $_POST['cadets']; // Array of military numbers
            
            $successCount = 0;
            $errorCount = 0;
            
            foreach ($cadets as $military_number) {
                $military_number = trim($military_number);
                
                if (empty($military_number)) continue;
                
                // Find cadet
                $findCadet = "SELECT user_id FROM users WHERE military_number = ? AND role = 'cadet'";
                $stmt = $db->prepare($findCadet);
                $stmt->bind_param("s", $military_number);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $cadet = $result->fetch_assoc();
                    $cadet_id = $cadet['user_id'];
                    
                    // Check if attendance already exists
                    $checkQuery = "SELECT attendance_id FROM attendance WHERE user_id = ? AND session_id = ?";
                    $checkStmt = $db->prepare($checkQuery);
                    $checkStmt->bind_param("ii", $cadet_id, $session_id);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    
                    if ($checkResult->num_rows > 0) {
                        // Update existing
                        $updateQuery = "UPDATE attendance SET status = ?, checked_by = ?, date = CURDATE(), 
                                       recorded_at = CURRENT_TIMESTAMP
                                       WHERE user_id = ? AND session_id = ?";
                        $updateStmt = $db->prepare($updateQuery);
                        $updateStmt->bind_param("siii", $status, $rankholder_id, $cadet_id, $session_id);
                        
                        if ($updateStmt->execute()) {
                            $successCount++;
                        } else {
                            $errorCount++;
                        }
                    } else {
                        // Insert new
                        $insertQuery = "INSERT INTO attendance (user_id, session_id, date, status, checked_by, recorded_at) 
                                       VALUES (?, ?, CURDATE(), ?, ?, CURRENT_TIMESTAMP)";
                        $insertStmt = $db->prepare($insertQuery);
                        $insertStmt->bind_param("iisi", $cadet_id, $session_id, $status, $rankholder_id);
                        
                        if ($insertStmt->execute()) {
                            $successCount++;
                        } else {
                            $errorCount++;
                        }
                    }
                } else {
                    $errorCount++;
                }
            }
            
            if ($successCount > 0) {
                $_SESSION['success'] = "Berjaya merekod $successCount kadet!";
                if ($errorCount > 0) {
                    $_SESSION['error'] = "$errorCount kadet gagal direkod.";
                }
            } else {
                $_SESSION['error'] = "Gagal merekod kehadiran!";
            }
            
            header("Location: take_attendance.php?session_id=" . $session_id . "&service=" . $service_filter);
            exit();
        }
    }
    
    // Get session ID from URL if provided
    $selected_session_id = $_GET['session_id'] ?? null;
    
    // Get ALL sessions for today (no service filter)
    $sessionsQuery = "SELECT ts.session_id, ts.training_type, ts.location, ts.session_time, ts.training_date,
                     ts.notes, ts.max_attendance,
                     COUNT(DISTINCT a.user_id) as attendance_count
                     FROM training_sessions ts
                     LEFT JOIN attendance a ON ts.session_id = a.session_id
                     WHERE DATE(ts.training_date) = CURDATE() 
                     AND ts.is_active = 1
                     GROUP BY ts.session_id 
                     ORDER BY ts.session_time";
    
    $sessionsStmt = $db->prepare($sessionsQuery);
    $sessionsStmt->execute();
    $sessionsResult = $sessionsStmt->get_result();
    $totalSessions = $sessionsResult->num_rows;
    
    // Get total cadets for ALL services
    $totalCadetsQuery = "SELECT COUNT(*) as total FROM users WHERE role = 'cadet'";
    $totalCadetsStmt = $db->prepare($totalCadetsQuery);
    $totalCadetsStmt->execute();
    $totalCadetsResult = $totalCadetsStmt->get_result();
    $totalCadets = $totalCadetsResult->fetch_assoc()['total'] ?? 0;
    
    // Get cadets based on filter
    if ($selected_session_id) {
        if ($service_filter === 'all') {
            $cadetsQuery = "SELECT 
                                u.user_id, 
                                u.military_number, 
                                u.name, 
                                u.rank_level,
                                u.service_type,
                                a.status as today_status,
                                a.recorded_at
                            FROM users u
                            LEFT JOIN attendance a ON u.user_id = a.user_id 
                                AND a.session_id = ?
                            WHERE u.role = 'cadet'
                            ORDER BY u.service_type, u.rank_level, u.name";
            
            $cadetsStmt = $db->prepare($cadetsQuery);
            $cadetsStmt->bind_param("i", $selected_session_id);
        } else {
            $cadetsQuery = "SELECT 
                                u.user_id, 
                                u.military_number, 
                                u.name, 
                                u.rank_level,
                                u.service_type,
                                a.status as today_status,
                                a.recorded_at
                            FROM users u
                            LEFT JOIN attendance a ON u.user_id = a.user_id 
                                AND a.session_id = ?
                            WHERE u.role = 'cadet'
                            AND u.service_type = ?
                            ORDER BY u.rank_level, u.name";
            
            $cadetsStmt = $db->prepare($cadetsQuery);
            $cadetsStmt->bind_param("is", $selected_session_id, $service_filter);
        }
        
        $cadetsStmt->execute();
        $cadetsResult = $cadetsStmt->get_result();
    }
    
    // Get today's attendance summary
    $summaryQuery = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                    SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused
                FROM attendance a
                WHERE a.checked_by = ?
                AND DATE(a.date) = CURDATE()";
    
    $summaryStmt = $db->prepare($summaryQuery);
    $summaryStmt->bind_param("i", $rankholder_id);
    $summaryStmt->execute();
    $summaryResult = $summaryStmt->get_result();
    $summary = $summaryResult->fetch_assoc();
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Session time labels
$sessionTimeLabels = [
    'pagi' => 'Pagi',
    'tengah hari' => 'Tengah Hari',
    'petang' => 'Petang',
    'malam' => 'Malam'
];

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

// Get status badge
function getStatusBadge($status) {
    if (empty($status)) {
        return '<span class="status-badge unknown">Belum</span>';
    }
    
    switch($status) {
        case 'present': return '<span class="status-badge present">Hadir</span>';
        case 'absent': return '<span class="status-badge absent">Absen</span>';
        case 'late': return '<span class="status-badge late">Lewat</span>';
        case 'excused': return '<span class="status-badge excused">Lepas</span>';
        default: return '<span class="status-badge unknown">Belum</span>';
    }
}

// Get formatted date
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

// Get formatted time from session_time
function getSessionTimeLabel($time) {
    global $sessionTimeLabels;
    
    if (empty($time)) {
        return '';
    }
    
    return $sessionTimeLabels[$time] ?? ucfirst($time ?: '');
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekod Kehadiran - CAAMS</title>
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
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.15);
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        
        .back-btn:hover {
            background: rgba(255, 255, 255, 0.25);
        }
        
        /* STATS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
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
        
        /* SERVICE FILTER */
        .service-filter {
            display: flex;
            gap: 8px;
            margin-bottom: 15px;
            flex-wrap: wrap;
            overflow-x: auto;
            padding-bottom: 5px;
            -webkit-overflow-scrolling: touch;
        }
        
        .service-btn {
            padding: 8px 15px;
            border: 2px solid var(--gray-300);
            border-radius: 20px;
            background: white;
            color: var(--gray-700);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
            white-space: nowrap;
        }
        
        .service-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .service-btn.active {
            border-color: var(--accent);
            background: rgba(49, 130, 206, 0.1);
            color: var(--accent);
        }
        
        .service-btn.darat.active {
            border-color: var(--darat);
            background: rgba(56, 161, 105, 0.1);
            color: var(--darat);
        }
        
        .service-btn.laut.active {
            border-color: var(--laut);
            background: rgba(49, 130, 206, 0.1);
            color: var(--laut);
        }
        
        .service-btn.udara.active {
            border-color: var(--udara);
            background: rgba(159, 122, 234, 0.1);
            color: var(--udara);
        }
        
        /* SESSION CARDS */
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
        
        .sessions-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-top: 10px;
        }
        
        @media (min-width: 768px) {
            .sessions-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        .session-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            border: 2px solid var(--gray-200);
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        
        .session-card:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .session-card.active {
            border-color: var(--accent);
            background: linear-gradient(135deg, rgba(49, 130, 206, 0.05) 0%, rgba(49, 130, 206, 0.02) 100%);
        }
        
        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        
        .session-title {
            font-weight: 600;
            color: var(--primary);
            font-size: 1rem;
            flex: 1;
        }
        
        .session-time {
            background: rgba(49, 130, 206, 0.1);
            color: var(--accent);
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .session-details {
            color: var(--gray-600);
            margin-bottom: 12px;
            font-size: 0.9rem;
        }
        
        .session-details p {
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .session-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            color: var(--gray-500);
            margin-top: 8px;
        }
        
        .attendance-count {
            font-weight: 600;
            color: var(--primary);
        }
        
        .progress-bar {
            height: 6px;
            background: var(--gray-200);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 8px;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--accent);
            border-radius: 3px;
            transition: width 0.5s ease;
        }
        
        /* ATTENDANCE RECORDING SECTION */
        .attendance-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            margin-top: 20px;
        }
        
        .selected-session-info {
            background: linear-gradient(135deg, rgba(49, 130, 206, 0.05) 0%, rgba(49, 130, 206, 0.02) 100%);
            border-left: 4px solid var(--accent);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .session-info-title {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }
        
        .session-info-details {
            color: var(--gray-600);
            font-size: 0.9rem;
        }
        
        /* ATTENDANCE FORM */
        .attendance-form {
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
        
        .form-input {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s;
            background: white;
        }
        
        .form-input:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        .input-help {
            display: block;
            margin-top: 5px;
            font-size: 0.8rem;
            color: var(--gray-600);
        }
        
        /* STATUS BUTTONS */
        .status-buttons {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 10px;
        }
        
        .status-btn {
            padding: 14px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            background: white;
            color: var(--gray-700);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }
        
        .status-btn:hover {
            transform: translateY(-2px);
        }
        
        .status-btn.active {
            border-color: var(--accent);
            background: rgba(49, 130, 206, 0.1);
        }
        
        .status-btn.present { color: var(--success); }
        .status-btn.absent { color: var(--danger); }
        .status-btn.late { color: var(--warning); }
        .status-btn.excused { color: var(--purple); }
        
        /* SUBMIT BUTTON */
        .btn-submit {
            width: 100%;
            background: linear-gradient(135deg, var(--success) 0%, #38a169 100%);
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
            box-shadow: 0 4px 12px rgba(72, 187, 120, 0.3);
        }
        
        /* CADETS LIST SECTION */
        .cadets-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            margin-top: 20px;
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
            min-height: 120px;
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
        
        .cadet-checkbox {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--accent);
        }
        
        .cadet-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
            padding-right: 25px;
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
        
        .cadet-status {
            margin-top: 5px;
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
        
        .status-badge.present {
            background: rgba(72, 187, 120, 0.1);
            color: var(--success);
        }
        
        .status-badge.absent {
            background: rgba(245, 101, 101, 0.1);
            color: var(--danger);
        }
        
        .status-badge.late {
            background: rgba(237, 137, 54, 0.1);
            color: var(--warning);
        }
        
        .status-badge.excused {
            background: rgba(159, 122, 234, 0.1);
            color: var(--purple);
        }
        
        .status-badge.unknown {
            background: rgba(160, 174, 192, 0.1);
            color: var(--gray-500);
        }
        
        .select-all-btn {
            background: var(--gray-200);
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            color: var(--gray-700);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .select-all-btn:hover {
            background: var(--gray-300);
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
        
        /* UTILITIES */
        .text-center { text-align: center; }
        .mb-1 { margin-bottom: 10px; }
        .mt-1 { margin-top: 10px; }
        .d-flex { display: flex; }
        .align-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .gap-1 { gap: 10px; }
        
        /* TOUCH FRIENDLY */
        @media (hover: none) {
            .session-card:hover,
            .cadet-card:hover,
            .status-btn:hover,
            .btn-submit:hover {
                transform: none;
            }
            
            .session-card:active,
            .cadet-card:active {
                transform: scale(0.98);
                transition: transform 0.1s;
            }
            
            .status-btn:active,
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
                    <i class="fas fa-clipboard-check"></i>
                    Rekod Kehadiran
                </h1>
    
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
        
        <!-- SERVICE FILTER -->
        <div class="service-filter">
            <button class="service-btn <?php echo $service_filter === 'all' ? 'active' : ''; ?>" 
                    onclick="filterService('all')">
                <i class="fas fa-users"></i> Semua
            </button>
            <button class="service-btn darat <?php echo $service_filter === 'darat' ? 'active' : ''; ?>" 
                    onclick="filterService('darat')">
                <i class="fas fa-mountain"></i> Darat
            </button>
            <button class="service-btn laut <?php echo $service_filter === 'laut' ? 'active' : ''; ?>" 
                    onclick="filterService('laut')">
                <i class="fas fa-ship"></i> Laut
            </button>
            <button class="service-btn udara <?php echo $service_filter === 'udara' ? 'active' : ''; ?>" 
                    onclick="filterService('udara')">
                <i class="fas fa-plane"></i> Udara
            </button>
        </div>
        
        <!-- SESSIONS LIST -->
        <div class="attendance-section">
            <h2 class="section-title">
                <i class="fas fa-calendar-day"></i> Sesi Hari Ini
            </h2>
            
            <div class="sessions-grid">
                <?php 
                $sessionsResult->data_seek(0);
                if ($totalSessions > 0): 
                    while($session = $sessionsResult->fetch_assoc()): 
                        $isSelected = ($selected_session_id == $session['session_id']);
                        $progress = $totalCadets > 0 ? 
                            round(($session['attendance_count'] / $totalCadets) * 100) : 0;
                ?>
                <div class="session-card <?php echo $isSelected ? 'active' : ''; ?>" 
                     onclick="selectSession(<?php echo $session['session_id']; ?>)">
                    <div class="session-header">
                        <div class="session-title"><?php echo htmlspecialchars($session['training_type'] ?? ''); ?></div>
                        <div class="session-time"><?php echo getSessionTimeLabel($session['session_time'] ?? ''); ?></div>
                    </div>
                    <div class="session-details">
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($session['location'] ?? ''); ?></p>
                        <p><i class="far fa-calendar"></i> <?php echo formatDate($session['training_date'] ?? ''); ?></p>
                    </div>
                    <div class="session-stats">
                        <span><i class="fas fa-users"></i> <span class="attendance-count"><?php echo $session['attendance_count']; ?></span>/<?php echo $totalCadets; ?></span>
                        <span><?php echo $progress; ?>%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min($progress, 100); ?>%"></div>
                    </div>
                </div>
                <?php endwhile; ?>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-calendar-times"></i>
                    <h3>Tiada Sesi</h3>
                    <p>Tidak ada sesi latihan dijadualkan untuk hari ini</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- ATTENDANCE RECORDING -->
        <?php if ($selected_session_id): 
            // Get selected session details
            $sessionsResult->data_seek(0);
            $selectedSession = null;
            while($session = $sessionsResult->fetch_assoc()) {
                if ($session['session_id'] == $selected_session_id) {
                    $selectedSession = $session;
                    break;
                }
            }
            
            if ($selectedSession):
        ?>
        <div class="attendance-section">
            <h2 class="section-title">
                <i class="fas fa-user-check"></i> Rekod Kehadiran
            </h2>
            
            <!-- Selected Session Info -->
            <div class="selected-session-info">
                <div class="session-info-title">
                    <i class="fas fa-calendar-check"></i> Sesi Dipilih
                </div>
                <div class="session-info-details">
                    <p><strong><?php echo htmlspecialchars($selectedSession['training_type'] ?? ''); ?></strong></p>
                    <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($selectedSession['location'] ?? ''); ?></p>
                    <p><i class="far fa-clock"></i> <?php echo getSessionTimeLabel($selectedSession['session_time'] ?? ''); ?></p>
                </div>
            </div>
            
            <!-- Attendance Form -->
            <form id="attendanceForm" method="POST" action="" class="attendance-form">
                <input type="hidden" name="session_id" value="<?php echo $selected_session_id; ?>">
                
                <!-- Manual Input -->
                <div class="form-group">
                    <label for="manual_input" class="form-label">Input Nombor Tentera</label>
                    <textarea 
                           id="manual_input"
                           class="form-input" 
                           placeholder="Masukkan nombor tentera (pisahkan dengan koma atau baris baru)"
                           rows="3"
                           oninput="processManualInput(this.value)"
                           style="resize: vertical; min-height: 80px;"></textarea>
                    <small class="input-help">
                        <i class="fas fa-info-circle"></i> Contoh: NV8709405, CD003, AB1234
                    </small>
                </div>
                
                <!-- Status Selection -->
                <div class="form-group">
                    <label class="form-label">Status Kehadiran</label>
                    <div class="status-buttons">
                        <button type="button" class="status-btn present active" data-status="present">
                            <i class="fas fa-check-circle"></i> Hadir
                        </button>
                        <button type="button" class="status-btn absent" data-status="absent">
                            <i class="fas fa-times-circle"></i> Absen
                        </button>
                        <button type="button" class="status-btn late" data-status="late">
                            <i class="fas fa-clock"></i> Lewat
                        </button>
                        <button type="button" class="status-btn excused" data-status="excused">
                            <i class="fas fa-file-medical"></i> Lepas
                        </button>
                    </div>
                    <input type="hidden" name="status" id="status" value="present">
                </div>
                
                <!-- Selected Cadets List (Hidden) -->
                <div id="selectedCadetsContainer" style="display: none;">
                    <!-- Will be populated by JavaScript -->
                </div>
                
                <button type="submit" class="btn-submit" id="submitBtn">
                    <i class="fas fa-save"></i> Simpan (<span id="selectedCount">0</span>)
                </button>
            </form>
        </div>
        
        <!-- CADETS LIST -->
        <div class="cadets-section">
            <div class="cadets-header">
                <h2 class="section-title" style="margin: 0; border: none; padding: 0;">
                    <i class="fas fa-users"></i> <?php echo getServiceLabel($service_filter); ?>
                    <span style="font-size: 0.9rem; color: var(--gray-500); margin-left: 6px;">
                        (<?php echo $cadetsResult->num_rows; ?>)
                    </span>
                </h2>
                <button type="button" class="select-all-btn" onclick="selectAllCadets()">
                    <i class="fas fa-check-double"></i> Pilih Semua
                </button>
            </div>
            
            <div style="margin-bottom: 15px; padding: 10px; background: var(--gray-100); border-radius: 6px; border-left: 3px solid var(--accent);">
                <p style="font-weight: 600; color: var(--primary); margin-bottom: 4px; font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i> Pilih kadet untuk rekod kehadiran
                </p>
                <p style="color: var(--gray-600); font-size: 0.8rem;">
                    Klik kadet atau checkbox untuk pilih
                </p>
            </div>
            
            <div class="cadets-grid">
                <?php if ($cadetsResult->num_rows > 0): 
                    while($cadet = $cadetsResult->fetch_assoc()): 
                        $avatarClass = 'avatar-' . ($cadet['service_type'] ?? 'default');
                ?>
                <div class="cadet-card" onclick="toggleCadetSelection('<?php echo $cadet['military_number']; ?>', this)">
                    <input type="checkbox" 
                           class="cadet-checkbox" 
                           id="cadet_<?php echo $cadet['user_id']; ?>"
                           value="<?php echo $cadet['military_number']; ?>"
                           onclick="event.stopPropagation(); toggleCadetSelection('<?php echo $cadet['military_number']; ?>', this.closest('.cadet-card'))">
                    
                    <div class="cadet-header">
                        <div class="cadet-avatar <?php echo $avatarClass; ?>">
                            <?php echo strtoupper(substr($cadet['name'] ?? '', 0, 1)); ?>
                        </div>
                        <div class="cadet-info">
                            <h4><?php echo htmlspecialchars($cadet['name'] ?? ''); ?></h4>
                            <p><?php echo $cadet['military_number'] ?? ''; ?></p>
                        </div>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 5px;">
                        <div>
                            <span class="service-badge <?php echo getServiceBadge($cadet['service_type'] ?? ''); ?>">
                                <?php echo substr(getServiceLabel($cadet['service_type'] ?? ''), 0, 1); ?>
                            </span>
                            <span style="font-size: 0.75rem; color: var(--gray-500);">
                                <?php echo ucfirst($cadet['rank_level'] ?? ''); ?>
                            </span>
                        </div>
                        <div class="cadet-status">
                            <?php echo getStatusBadge($cadet['today_status'] ?? ''); ?>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
                <?php else: ?>
                <div class="no-data" style="grid-column: 1 / -1;">
                    <i class="fas fa-users-slash"></i>
                    <h3>Tiada Kadet</h3>
                    <p>Tidak ada kadet dalam kategori <?php echo getServiceLabel($service_filter); ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--gray-200);">
                <p style="text-align: center; color: var(--gray-600); font-size: 0.8rem;">
                    <i class="fas fa-mouse-pointer"></i> Pilih kadet, pilih status, kemudian simpan
                </p>
            </div>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="attendance-section">
            <div class="no-data">
                <i class="fas fa-mouse-pointer"></i>
                <h3>Pilih Sesi Latihan</h3>
                <p>Klik sesi latihan di atas untuk mula merekod kehadiran</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- LOADING SPINNER -->
    <div class="loading" id="loading">
        <i class="fas fa-spinner"></i>
        <p>Menyimpan...</p>
    </div>
    
    <!-- MOBILE NAVIGATION -->
    <nav class="mobile-nav">
        <a href="dashboard.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-home"></i>
            </div>
            <div class="mobile-nav-label">Dashboard</div>
        </a>
        
        <a href="take_attendance.php" class="mobile-nav-item active">
            <div class="mobile-nav-icon">
                <i class="fas fa-qrcode"></i>
            </div>
            <div class="mobile-nav-label">Rekod</div>
        </a>
        
        <a href="view_attendance.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-list"></i>
            </div>
            <div class="mobile-nav-label">Lihat</div>
        </a>
        
        <a href="manage_leaves.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-file-upload"></i>
            </div>
            <div class="mobile-nav-label">Pelepasan</div>
        </a>
    </nav>
    
    <script>
        // Global variables
        let selectedCadets = new Set();
        
        // Filter service
        function filterService(service) {
            const sessionId = <?php echo $selected_session_id ? "'$selected_session_id'" : 'null'; ?>;
            if (sessionId) {
                window.location.href = `take_attendance.php?session_id=${sessionId}&service=${service}`;
            } else {
                window.location.href = `take_attendance.php?service=${service}`;
            }
        }
        
        // Select session
        function selectSession(sessionId) {
            window.location.href = `take_attendance.php?session_id=${sessionId}&service=<?php echo $service_filter; ?>`;
        }
        
        // Status buttons functionality
        const statusButtons = document.querySelectorAll('.status-btn');
        const statusInput = document.getElementById('status');
        
        statusButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                // Remove active class from all buttons
                statusButtons.forEach(b => b.classList.remove('active'));
                // Add active class to clicked button
                this.classList.add('active');
                // Update hidden input
                statusInput.value = this.dataset.status;
            });
        });
        
        // Toggle cadet selection
        function toggleCadetSelection(militaryNumber, cardElement) {
            const checkbox = cardElement.querySelector('.cadet-checkbox');
            
            if (selectedCadets.has(militaryNumber)) {
                // Deselect
                selectedCadets.delete(militaryNumber);
                cardElement.classList.remove('selected');
                if (checkbox) checkbox.checked = false;
            } else {
                // Select
                selectedCadets.add(militaryNumber);
                cardElement.classList.add('selected');
                if (checkbox) checkbox.checked = true;
            }
            
            updateSelectedCount();
            updateFormCadets();
        }
        
        // Select all cadets
        function selectAllCadets() {
            const checkboxes = document.querySelectorAll('.cadet-checkbox');
            const cards = document.querySelectorAll('.cadet-card');
            
            const allSelected = selectedCadets.size === checkboxes.length;
            
            if (allSelected) {
                // Deselect all
                selectedCadets.clear();
                cards.forEach(card => card.classList.remove('selected'));
                checkboxes.forEach(cb => cb.checked = false);
            } else {
                // Select all
                checkboxes.forEach(cb => {
                    selectedCadets.add(cb.value);
                });
                cards.forEach(card => card.classList.add('selected'));
                checkboxes.forEach(cb => cb.checked = true);
            }
            
            updateSelectedCount();
            updateFormCadets();
        }
        
        // Update selected count display
        function updateSelectedCount() {
            const countElement = document.getElementById('selectedCount');
            if (countElement) {
                countElement.textContent = selectedCadets.size;
            }
            
            // Update submit button text
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                if (selectedCadets.size > 0) {
                    submitBtn.innerHTML = `<i class="fas fa-save"></i> Simpan (${selectedCadets.size})`;
                } else {
                    submitBtn.innerHTML = `<i class="fas fa-save"></i> Simpan`;
                }
            }
        }
        
        // Update form with selected cadets
        function updateFormCadets() {
            const container = document.getElementById('selectedCadetsContainer');
            if (!container) return;
            
            // Clear existing
            container.innerHTML = '';
            
            // Add hidden inputs for each selected cadet
            selectedCadets.forEach(militaryNumber => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'cadets[]';
                input.value = militaryNumber;
                container.appendChild(input);
            });
            
            // Show container if there are selected cadets
            container.style.display = selectedCadets.size > 0 ? 'block' : 'none';
        }
        
        // Process manual input
        function processManualInput(inputText) {
            if (!inputText.trim()) return;
            
            // Split by commas, new lines, or spaces
            const numbers = inputText.split(/[,;\n\r\s]+/).map(num => num.trim()).filter(num => num.length > 0);
            
            // Clear current selection
            selectedCadets.clear();
            document.querySelectorAll('.cadet-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.querySelectorAll('.cadet-checkbox').forEach(cb => {
                cb.checked = false;
            });
            
            // Add each number to selection
            numbers.forEach(num => {
                selectedCadets.add(num);
                
                // Find and check corresponding checkbox
                const checkbox = document.querySelector(`.cadet-checkbox[value="${num}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                    checkbox.closest('.cadet-card').classList.add('selected');
                }
            });
            
            updateSelectedCount();
            updateFormCadets();
        }
        
        // Form validation and submission
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('attendanceForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    if (selectedCadets.size === 0) {
                        alert('Sila pilih sekurang-kurangnya seorang kadet');
                        return false;
                    }
                    
                    // Show loading
                    const loading = document.getElementById('loading');
                    loading.style.display = 'block';
                    
                    // Disable submit button
                    const submitBtn = document.getElementById('submitBtn');
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
                    
                    // Submit form
                    this.submit();
                });
            }
            
            // Initialize selected count
            updateSelectedCount();
            updateFormCadets();
            
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
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+Enter or Cmd+Enter to submit
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                const submitBtn = document.getElementById('submitBtn');
                if (submitBtn && !submitBtn.disabled) {
                    submitBtn.click();
                }
            }
            
            // Escape to clear selection
            if (e.key === 'Escape') {
                selectedCadets.clear();
                document.querySelectorAll('.cadet-card').forEach(card => {
                    card.classList.remove('selected');
                });
                document.querySelectorAll('.cadet-checkbox').forEach(cb => {
                    cb.checked = false;
                });
                updateSelectedCount();
                updateFormCadets();
            }
        });
        
        // Auto-scroll to form when session is selected
        <?php if ($selected_session_id): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Scroll to attendance form section
            const attendanceSection = document.querySelector('.attendance-section');
            if (attendanceSection) {
                setTimeout(() => {
                    attendanceSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 300);
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
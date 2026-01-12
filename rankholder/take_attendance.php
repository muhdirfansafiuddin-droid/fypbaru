<?php
// rankholder/take_attendance.php - FIXED VERSION (SHOWS UPCOMING SESSIONS)
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
    
    if (!$user || $user['role'] !== 'rankholder') {
        header("Location: ../index.php");
        exit();
    }
    
    $rankholder_id = $user['user_id'];
    $default_service_type = $user['service_type'] ?? null;
    
    // Get filter from URL or use default
    $service_filter = $_GET['service'] ?? $default_service_type ?? 'all';
    $rank_filter = $_GET['rank'] ?? 'all';
    $date_filter = $_GET['date'] ?? 'all';  // 'today', 'tomorrow', 'all' - DEFAULT: all
    
    // Process attendance form (BULK)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['session_id']) && isset($_POST['attendance_data'])) {
            $session_id = intval($_POST['session_id']);
            $attendance_data = $_POST['attendance_data']; // Array with user_id => status
            
            $successCount = 0;
            $errorCount = 0;
            
            foreach ($attendance_data as $cadet_id => $data) {
                $cadet_id = intval($cadet_id);
                $status = $data['status']; // 'present' or 'absent'
                $absent_type = isset($data['absent_type']) ? $data['absent_type'] : null; // 'cuti' or 'excuse'
                $reason = isset($data['reason']) ? trim($data['reason']) : null;
                
                // Check if attendance already exists
                $checkQuery = "SELECT attendance_id FROM attendance 
                               WHERE user_id = ? AND session_id = ? AND DATE(date) = CURDATE()";
                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->bind_param("ii", $cadet_id, $session_id);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                
                if ($checkResult->num_rows > 0) {
                    // Update existing
                    $updateQuery = "UPDATE attendance SET 
                                    status = ?, 
                                    absent_type = ?,
                                    reason = ?,
                                    checked_by = ?, 
                                    recorded_at = CURRENT_TIMESTAMP
                                   WHERE user_id = ? AND session_id = ? AND DATE(date) = CURDATE()";
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bind_param("sssiii", $status, $absent_type, $reason, $rankholder_id, $cadet_id, $session_id);
                    
                    if ($updateStmt->execute()) {
                        $successCount++;
                    } else {
                        $errorCount++;
                    }
                } else {
                    // Insert new
                    $insertQuery = "INSERT INTO attendance 
                                    (user_id, session_id, date, status, absent_type, reason, checked_by, recorded_at) 
                                   VALUES (?, ?, CURDATE(), ?, ?, ?, ?, CURRENT_TIMESTAMP)";
                    $insertStmt = $db->prepare($insertQuery);
                    $insertStmt->bind_param("iissssi", $cadet_id, $session_id, $status, $absent_type, $reason, $rankholder_id);
                    
                    if ($insertStmt->execute()) {
                        $successCount++;
                    } else {
                        $errorCount++;
                    }
                }
            }
            
            if ($successCount > 0) {
                $_SESSION['success'] = "Successfully recorded $successCount cadets!";
                if ($errorCount > 0) {
                    $_SESSION['error'] = "$errorCount cadets failed to record.";
                }
            } else {
                $_SESSION['error'] = "Failed to record attendance!";
            }
            
            header("Location: take_attendance.php?session_id=" . $session_id . 
                   "&service=" . $service_filter . "&rank=" . $rank_filter . "&date=" . $date_filter);
            exit();
        }
    }
    
    // Get session ID from URL if provided
    $selected_session_id = $_GET['session_id'] ?? null;
    
    // Get sessions based on date filter - FIXED: SHOW UPCOMING SESSIONS
    $where_date = "DATE(ts.training_date) >= CURDATE()"; // DEFAULT: all upcoming
    
    if ($date_filter === 'today') {
        $where_date = "DATE(ts.training_date) = CURDATE()";
    } elseif ($date_filter === 'tomorrow') {
        $where_date = "DATE(ts.training_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
    }
    
    $sessionsQuery = "SELECT 
                        ts.session_id, 
                        ts.training_type, 
                        ts.location, 
                        ts.session_time, 
                        ts.training_date,
                        ts.notes,
                        ts.max_attendance,
                        COUNT(DISTINCT a.user_id) as attendance_count
                     FROM training_sessions ts
                     LEFT JOIN attendance a ON ts.session_id = a.session_id 
                          AND DATE(a.date) = CURDATE()
                     WHERE $where_date 
                     AND ts.is_active = 1
                     GROUP BY ts.session_id 
                     ORDER BY ts.training_date ASC, 
                     CASE ts.session_time 
                         WHEN 'pagi' THEN 1
                         WHEN 'tengah hari' THEN 2
                         WHEN 'petang' THEN 3
                         WHEN 'malam' THEN 4
                         ELSE 5
                     END";
    
    $sessionsStmt = $db->prepare($sessionsQuery);
    $sessionsStmt->execute();
    $sessionsResult = $sessionsStmt->get_result();
    $totalSessions = $sessionsResult->num_rows;
    
    // Get total cadets for filter
    if ($service_filter === 'all') {
        $totalCadetsQuery = "SELECT COUNT(*) as total FROM users WHERE role = 'cadet'";
        $totalCadetsStmt = $db->prepare($totalCadetsQuery);
        $totalCadetsStmt->execute();
    } else {
        $totalCadetsQuery = "SELECT COUNT(*) as total FROM users 
                            WHERE role = 'cadet' AND service_type = ?";
        $totalCadetsStmt = $db->prepare($totalCadetsQuery);
        $totalCadetsStmt->bind_param("s", $service_filter);
        $totalCadetsStmt->execute();
    }
    $totalCadetsResult = $totalCadetsStmt->get_result();
    $totalCadets = $totalCadetsResult->fetch_assoc()['total'] ?? 0;
    
    // Get cadets based on filter
    if ($selected_session_id) {
        // Get cadets for selected session with attendance data
        $cadetsQuery = "SELECT 
                            u.user_id, 
                            u.military_number, 
                            u.name, 
                            u.rank_level,
                            u.service_type,
                            a.status as today_status,
                            a.absent_type,
                            a.reason,
                            a.recorded_at
                        FROM users u
                        LEFT JOIN attendance a ON u.user_id = a.user_id 
                            AND a.session_id = ?
                            AND DATE(a.date) = CURDATE()
                        WHERE u.role = 'cadet'";
        
        $params = [$selected_session_id];
        $types = "i";
        
        if ($service_filter !== 'all') {
            $cadetsQuery .= " AND u.service_type = ?";
            $params[] = $service_filter;
            $types .= "s";
        }
        
        if ($rank_filter !== 'all') {
            $cadetsQuery .= " AND u.rank_level = ?";
            $params[] = $rank_filter;
            $types .= "s";
        }
        
        $cadetsQuery .= " ORDER BY u.service_type, u.rank_level, u.name";
        
        $cadetsStmt = $db->prepare($cadetsQuery);
        if (count($params) > 1) {
            $cadetsStmt->bind_param($types, ...$params);
        } else {
            $cadetsStmt->bind_param($types, $selected_session_id);
        }
        $cadetsStmt->execute();
        $cadetsResult = $cadetsStmt->get_result();
    }
    
    // Get today's attendance summary
    $summaryQuery = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent
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

// Helper functions
$sessionTimeLabels = [
    'pagi' => 'Morning (06:00 - 10:00)',
    'tengah hari' => 'Afternoon (10:00 - 14:00)',
    'petang' => 'Evening (14:00 - 18:00)',
    'malam' => 'Night (18:00 - 22:00)'
];

function getServiceLabel($type) {
    $labels = [
        'darat' => 'Army',
        'laut' => 'Navy', 
        'udara' => 'Air Force',
        'all' => 'All'
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

function getStatusBadge($status, $absent_type = null, $reason = null) {
    if (empty($status)) {
        return '<span class="status-badge unknown">Not Yet</span>';
    }
    
    switch($status) {
        case 'present': 
            return '<span class="status-badge present">Present</span>';
        case 'absent': 
            if ($absent_type === 'cuti') {
                return '<span class="status-badge sick">S (Sick)</span>' . 
                       ($reason ? '<br><small class="reason-text">' . htmlspecialchars($reason) . '</small>' : '');
            } elseif ($absent_type === 'excuse') {
                return '<span class="status-badge excuse">Excused</span>' . 
                       ($reason ? '<br><small class="reason-text">' . htmlspecialchars($reason) . '</small>' : '');
            } else {
                return '<span class="status-badge absent">Absent</span>';
            }
        default: 
            return '<span class="status-badge unknown">Not Yet</span>';
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
        if (!$date) return '';
        
        $today = strtotime('today');
        $tomorrow = strtotime('tomorrow');
        
        if ($date == $today) {
            return 'Today';
        } elseif ($date == $tomorrow) {
            return 'Tomorrow';
        } else {
            // Format: Mon, 15 Jan 2024
            return date('D, d M Y', $date);
        }
    } catch (Exception $e) {
        return '';
    }
}

function isUpcoming($dateString) {
    if (empty($dateString)) return false;
    try {
        $date = strtotime($dateString);
        $today = strtotime('today');
        return $date > $today;
    } catch (Exception $e) {
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Attendance - CAAMS</title>
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
            --sick: #f6ad55;
            --excuse: #68d391;
            --army: #38a169;
            --navy: #3182ce;
            --airforce: #9f7aea;
            --upcoming: #9f7aea;
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
            grid-template-columns: repeat(2, 1fr);
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
        
        /* FILTER SECTION */
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        
        @media (min-width: 480px) {
            .filter-grid {
                grid-template-columns: repeat(3, 1fr);
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
        
        /* SESSIONS GRID */
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
        
        /* UPCOMING BADGE */
        .upcoming-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: linear-gradient(135deg, var(--upcoming) 0%, #805ad5 100%);
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
            padding-right: 80px;
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
        
        /* BULK ACTION BAR */
        .bulk-action-bar {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .selected-count {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.9rem;
        }
        
        .bulk-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .bulk-btn {
            padding: 8px 12px;
            border: 2px solid var(--gray-300);
            border-radius: 6px;
            background: white;
            color: var(--gray-700);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .bulk-btn.present { 
            color: var(--success);
            border-color: var(--success);
        }
        
        .bulk-btn.absent { 
            color: var(--danger);
            border-color: var(--danger);
        }
        
        .bulk-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        /* CADETS SECTION */
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
        
        .cadet-item {
            background: white;
            border-radius: 8px;
            padding: 15px;
            border: 2px solid var(--gray-200);
            margin-bottom: 10px;
            transition: all 0.2s;
            position: relative;
        }
        
        .cadet-item:hover {
            border-color: var(--accent);
        }
        
        .cadet-item.selected {
            border-color: var(--accent);
            background: linear-gradient(135deg, rgba(49, 130, 206, 0.05) 0%, rgba(49, 130, 206, 0.02) 100%);
        }
        
        /* CHECKBOX */
        .cadet-checkbox {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--accent);
        }
        
        .cadet-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
            padding-right: 30px;
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
        
        .avatar-army {
            background: linear-gradient(135deg, var(--army) 0%, #2f855a 100%);
        }
        
        .avatar-navy {
            background: linear-gradient(135deg, var(--navy) 0%, #2c5282 100%);
        }
        
        .avatar-airforce {
            background: linear-gradient(135deg, var(--airforce) 0%, #805ad5 100%);
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
        
        .service-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-right: 6px;
        }
        
        .service-badge-army {
            background: rgba(56, 161, 105, 0.1);
            color: var(--army);
        }
        
        .service-badge-navy {
            background: rgba(49, 130, 206, 0.1);
            color: var(--navy);
        }
        
        .service-badge-airforce {
            background: rgba(159, 122, 234, 0.1);
            color: var(--airforce);
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-right: 5px;
        }
        
        .status-badge.present {
            background: rgba(72, 187, 120, 0.1);
            color: var(--success);
        }
        
        .status-badge.absent {
            background: rgba(245, 101, 101, 0.1);
            color: var(--danger);
        }
        
        .status-badge.sick {
            background: rgba(246, 173, 85, 0.1);
            color: var(--sick);
        }
        
        .status-badge.excuse {
            background: rgba(104, 211, 145, 0.1);
            color: var(--excuse);
        }
        
        .status-badge.unknown {
            background: rgba(160, 174, 192, 0.1);
            color: var(--gray-500);
        }
        
        /* STATUS OPTIONS */
        .status-options {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        
        .status-option {
            padding: 8px 12px;
            border: 2px solid var(--gray-300);
            border-radius: 6px;
            background: white;
            color: var(--gray-700);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .status-option.active {
            border-color: var(--accent);
            background: rgba(49, 130, 206, 0.1);
        }
        
        .status-option.present { 
            color: var(--success); 
        }
        
        .status-option.absent { 
            color: var(--danger); 
        }
        
        .status-option.sick { 
            color: var(--sick); 
        }
        
        .status-option.excuse { 
            color: var(--excuse); 
        }
        
        /* SUB OPTIONS (for absent types) */
        .sub-options {
            display: none;
            margin-top: 8px;
            padding-left: 15px;
            border-left: 2px solid var(--gray-300);
        }
        
        .sub-options.show {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* REASON INPUT */
        .reason-input {
            margin-top: 10px;
            display: none;
        }
        
        .reason-input.show {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        .reason-input textarea {
            width: 100%;
            padding: 8px;
            border: 2px solid var(--gray-300);
            border-radius: 6px;
            font-size: 0.85rem;
            resize: vertical;
            min-height: 60px;
        }
        
        /* ATTENDANCE FORM */
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
        
        .attendance-form {
            margin-top: 15px;
        }
        
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
        
        .reason-text {
            color: var(--gray-600);
            font-size: 0.8rem;
            margin-top: 3px;
            display: block;
            font-style: italic;
        }
        
        /* SELECT ALL */
        .select-all-btn {
            background: var(--gray-200);
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            color: var(--gray-700);
            font-weight: 600;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        /* NO DATA STYLE */
        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-500);
        }
        
        .no-data i {
            font-size: 3rem;
            opacity: 0.3;
            margin-bottom: 15px;
        }
        
        .no-data h3 {
            margin: 10px 0;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="main-header">
            <div class="header-title">
                <h1><i class="fas fa-clipboard-check"></i> Record Attendance</h1>
                <p style="opacity: 0.9; font-size: 0.9rem;">Select status for each cadet</p>
            </div>
            
            <!-- STATS -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $summary['present'] ?? 0; ?></div>
                    <div class="stat-label">Present</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $summary['absent'] ?? 0; ?></div>
                    <div class="stat-label">Absent</div>
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
        <div class="filter-section">
            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label">Service Type</label>
                    <select class="filter-select" onchange="filterService(this.value)">
                        <option value="all" <?php echo $service_filter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="darat" <?php echo $service_filter === 'darat' ? 'selected' : ''; ?>>Army</option>
                        <option value="laut" <?php echo $service_filter === 'laut' ? 'selected' : ''; ?>>Navy</option>
                        <option value="udara" <?php echo $service_filter === 'udara' ? 'selected' : ''; ?>>Air Force</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Rank Level</label>
                    <select class="filter-select" onchange="filterRank(this.value)">
                        <option value="all" <?php echo $rank_filter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="Junior" <?php echo $rank_filter === 'Junior'? 'selected' : ''; ?>>Junior</option>
                        <option value="Intermediate" <?php echo $rank_filter === 'Intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                        <option value="Senior" <?php echo $rank_filter === 'Senior' ? 'selected' : ''; ?>>Senior</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Session Date</label>
                    <select class="filter-select" onchange="filterDate(this.value)">
                        <option value="all" <?php echo $date_filter === 'all' ? 'selected' : ''; ?>>All Upcoming</option>
                        <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="tomorrow" <?php echo $date_filter === 'tomorrow' ? 'selected' : ''; ?>>Tomorrow</option>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- SESSIONS LIST -->
        <div class="attendance-section">
            <h2 class="section-title" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-calendar-day"></i> 
                <?php 
                if ($date_filter === 'today') {
                    echo 'Today\'s Sessions';
                } elseif ($date_filter === 'tomorrow') {
                    echo 'Tomorrow\'s Sessions';
                } else {
                    echo 'All Upcoming Sessions';
                }
                ?>
                <span style="font-size: 0.9rem; color: var(--gray-500); font-weight: normal; margin-left: auto;">
                    <?php echo $totalSessions; ?> sessions
                </span>
            </h2>
            
            <div class="sessions-grid">
                <?php if ($totalSessions > 0): 
                    $sessionsResult->data_seek(0);
                    while($session = $sessionsResult->fetch_assoc()): 
                        $isSelected = ($selected_session_id == $session['session_id']);
                        $progress = $totalCadets > 0 ? 
                            round(($session['attendance_count'] / $totalCadets) * 100) : 0;
                        $isUpcomingSession = isUpcoming($session['training_date']);
                ?>
                <div class="session-card <?php echo $isSelected ? 'active' : ''; ?>" 
                     onclick="selectSession(<?php echo $session['session_id']; ?>)">
                    <?php if ($isUpcomingSession): ?>
                    <div class="upcoming-badge">
                        <i class="fas fa-clock"></i> Upcoming
                    </div>
                    <?php endif; ?>
                    
                    <div class="session-header">
                        <div class="session-title"><?php echo htmlspecialchars($session['training_type'] ?? ''); ?></div>
                        <div class="session-time"><?php echo getSessionTimeLabel($session['session_time'] ?? ''); ?></div>
                    </div>
                    <div class="session-details">
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($session['location'] ?? ''); ?></p>
                        <p><i class="far fa-calendar"></i> <?php echo formatDate($session['training_date'] ?? ''); ?></p>
                        <?php if (!empty($session['notes'])): ?>
                        <p><i class="fas fa-sticky-note"></i> <?php echo htmlspecialchars(substr($session['notes'], 0, 50)); ?>...</p>
                        <?php endif; ?>
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
                    <h3>No Sessions</h3>
                    <p>No training sessions <?php 
                        if ($date_filter === 'today') {
                            echo 'today';
                        } elseif ($date_filter === 'tomorrow') {
                            echo 'tomorrow';
                        } else {
                            echo 'upcoming';
                        }
                    ?></p>
                    <p style="margin-top: 10px; font-size: 0.9rem; color: var(--gray-400);">
                        <i class="fas fa-info-circle"></i> Admin needs to create sessions first
                    </p>
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
                $selectedSessionUpcoming = isUpcoming($selectedSession['training_date']);
        ?>
        <div class="attendance-section">
            <h2 class="section-title" style="margin-bottom: 15px;"><i class="fas fa-user-check"></i> Record Attendance</h2>
            
            <!-- Selected Session Info -->
            <div class="selected-session-info">
                <div class="session-info-title" style="font-weight: 600; color: var(--primary); margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-calendar-check"></i> Selected Session
                    </div>
                    <?php if ($selectedSessionUpcoming): ?>
                    <span style="background: linear-gradient(135deg, var(--upcoming) 0%, #805ad5 100%); color: white; padding: 3px 10px; border-radius: 12px; font-size: 0.8rem; font-weight: 600;">
                        <i class="fas fa-clock"></i> Upcoming
                    </span>
                    <?php endif; ?>
                </div>
                <div class="session-info-details" style="color: var(--gray-600); font-size: 0.95rem;">
                    <p style="font-weight: 600; color: var(--primary); margin-bottom: 8px; font-size: 1rem;">
                        <?php echo htmlspecialchars($selectedSession['training_type'] ?? ''); ?>
                    </p>
                    <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($selectedSession['location'] ?? ''); ?></p>
                    <p><i class="far fa-clock"></i> <?php echo getSessionTimeLabel($selectedSession['session_time'] ?? ''); ?></p>
                    <p><i class="far fa-calendar"></i> <?php echo formatDate($selectedSession['training_date'] ?? ''); ?></p>
                    <?php if (!empty($selectedSession['notes'])): ?>
                    <p style="margin-top: 8px; padding: 8px; background: rgba(49, 130, 206, 0.05); border-radius: 6px;">
                        <i class="fas fa-sticky-note"></i> <strong>Notes:</strong> <?php echo htmlspecialchars($selectedSession['notes']); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Bulk Action Bar -->
            <div class="bulk-action-bar" id="bulkActionBar" style="display: none;">
                <div class="selected-count">
                    <span id="selectedCount">0</span> cadets selected
                </div>
                <div class="bulk-actions">
                    <button type="button" class="bulk-btn present" onclick="bulkSetPresent()">
                        <i class="fas fa-check"></i> Mark as Present
                    </button>
                    <button type="button" class="bulk-btn absent" onclick="bulkSetAbsent()">
                        <i class="fas fa-times"></i> Mark as Absent
                    </button>
                </div>
            </div>
            
            <!-- Attendance Form -->
            <form id="attendanceForm" method="POST" action="" class="attendance-form">
                <input type="hidden" name="session_id" value="<?php echo $selected_session_id; ?>">
                
                <!-- Cadets List -->
                <div class="cadets-section">
                    <div class="cadets-header">
                        <h2 class="section-title" style="margin: 0; border: none; padding: 0;">
                            <i class="fas fa-users"></i> <?php echo getServiceLabel($service_filter); ?>
                            <span style="font-size: 0.9rem; color: var(--gray-500); margin-left: 6px;">
                                (<?php echo $cadetsResult->num_rows; ?> cadets)
                            </span>
                        </h2>
                        <div class="bulk-actions">
                            <button type="button" class="select-all-btn" onclick="selectAllCadets()">
                                <i class="fas fa-check-double"></i> Select All
                            </button>
                            <button type="button" class="bulk-btn present" onclick="setAllPresent()" style="padding: 8px 12px;">
                                <i class="fas fa-check"></i> All Present
                            </button>
                        </div>
                    </div>
                    
                    <?php if ($cadetsResult->num_rows > 0): 
                        $cadetsResult->data_seek(0);
                        while($cadet = $cadetsResult->fetch_assoc()): 
                            $avatarClass = 'avatar-' . ($cadet['service_type'] ?? 'default');
                            $currentStatus = $cadet['today_status'] ?? 'present'; // DEFAULT: present
                            $currentAbsentType = $cadet['absent_type'] ?? '';
                            $currentReason = $cadet['reason'] ?? '';
                    ?>
                    <div class="cadet-item" data-cadet-id="<?php echo $cadet['user_id']; ?>" id="cadet_<?php echo $cadet['user_id']; ?>">
                        <input type="checkbox" 
                               class="cadet-checkbox" 
                               id="checkbox_<?php echo $cadet['user_id']; ?>"
                               onclick="toggleCadetSelection(<?php echo $cadet['user_id']; ?>)">
                        
                        <div class="cadet-header">
                            <div class="cadet-avatar <?php echo $avatarClass; ?>">
                                <?php echo strtoupper(substr($cadet['name'] ?? '', 0, 1)); ?>
                            </div>
                            <div class="cadet-info">
                                <h4><?php echo htmlspecialchars($cadet['name'] ?? ''); ?></h4>
                                <p><?php echo $cadet['military_number'] ?? ''; ?></p>
                                <div style="margin-top: 3px;">
                                    <span class="service-badge <?php echo getServiceBadge($cadet['service_type'] ?? ''); ?>">
                                        <?php echo substr(getServiceLabel($cadet['service_type'] ?? ''), 0, 1); ?>
                                    </span>
                                    <span style="font-size: 0.75rem; color: var(--gray-500);">
                                        <?php echo ucfirst($cadet['rank_level'] ?? ''); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Current Status -->
                        <div class="current-status" style="margin-bottom: 10px;">
                            <small style="color: var(--gray-500);">Current status:</small>
                            <div style="display: inline-block; margin-left: 5px;">
                                <?php echo getStatusBadge($currentStatus, $currentAbsentType, $currentReason); ?>
                            </div>
                        </div>
                        
                        <!-- Status Options -->
                        <div class="status-options">
                            <button type="button" class="status-option present <?php echo $currentStatus === 'present' ? 'active' : ''; ?>" 
                                    onclick="setPresent(<?php echo $cadet['user_id']; ?>)" 
                                    data-cadet="<?php echo $cadet['user_id']; ?>">
                                <i class="fas fa-check"></i> Present
                            </button>
                            <button type="button" class="status-option absent <?php echo $currentStatus === 'absent' && empty($currentAbsentType) ? 'active' : ''; ?>" 
                                    onclick="setAbsent(<?php echo $cadet['user_id']; ?>)" 
                                    data-cadet="<?php echo $cadet['user_id']; ?>">
                                <i class="fas fa-times"></i> Absent
                            </button>
                        </div>
                        
                        <!-- Sub Options for Absent Type -->
                        <div class="sub-options" id="sub_options_<?php echo $cadet['user_id']; ?>">
                            <div style="margin-top: 8px; margin-bottom: 5px;">
                                <small style="color: var(--gray-500);">Absence type:</small>
                            </div>
                            <div style="display: flex; gap: 8px;">
                                <button type="button" class="status-option sick <?php echo $currentStatus === 'absent' && $currentAbsentType === 'cuti' ? 'active' : ''; ?>" 
                                        onclick="setAbsentType(<?php echo $cadet['user_id']; ?>, 'cuti')" 
                                        data-cadet="<?php echo $cadet['user_id']; ?>">
                                    <i class="fas fa-hospital"></i> S (Sick)
                                </button>
                                <button type="button" class="status-option excuse <?php echo $currentStatus === 'absent' && $currentAbsentType === 'excuse' ? 'active' : ''; ?>" 
                                        onclick="setAbsentType(<?php echo $cadet['user_id']; ?>, 'excuse')" 
                                        data-cadet="<?php echo $cadet['user_id']; ?>">
                                    <i class="fas fa-file-signature"></i> Excused
                                </button>
                            </div>
                        </div>
                        
                        <!-- Reason Input (for sick/excuse) -->
                        <div class="reason-input" id="reason_<?php echo $cadet['user_id']; ?>">
                            <textarea placeholder="Reason (e.g.: fever, family matters, etc.)" 
                                      oninput="setReason(<?php echo $cadet['user_id']; ?>, this.value)"
                                      id="reason_text_<?php echo $cadet['user_id']; ?>"><?php echo $currentReason; ?></textarea>
                        </div>
                        
                        <!-- Hidden inputs -->
                        <input type="hidden" name="attendance_data[<?php echo $cadet['user_id']; ?>][status]" 
                               value="<?php echo $currentStatus; ?>" 
                               id="status_<?php echo $cadet['user_id']; ?>">
                        <input type="hidden" name="attendance_data[<?php echo $cadet['user_id']; ?>][absent_type]" 
                               value="<?php echo $currentAbsentType; ?>" 
                               id="absent_type_<?php echo $cadet['user_id']; ?>">
                        <input type="hidden" name="attendance_data[<?php echo $cadet['user_id']; ?>][reason]" 
                               value="<?php echo htmlspecialchars($currentReason); ?>" 
                               id="reason_hidden_<?php echo $cadet['user_id']; ?>">
                    </div>
                    <?php endwhile; ?>
                    <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-users-slash"></i>
                        <h3>No Cadets</h3>
                        <p>No cadets in the <?php echo getServiceLabel($service_filter); ?> category</p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <button type="submit" class="btn-submit" id="submitBtn">
                    <i class="fas fa-save"></i> Save All Attendance
                </button>
            </form>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="attendance-section">
            <div class="no-data">
                <i class="fas fa-mouse-pointer"></i>
                <h3>Select Training Session</h3>
                <p>Click on a training session above to start recording attendance</p>
                <p style="margin-top: 10px; font-size: 0.9rem; color: var(--gray-400);">
                    <i class="fas fa-info-circle"></i> Upcoming sessions created by admin will appear here
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- MOBILE NAV -->
    <nav class="mobile-nav">
        <a href="dashboard.php" class="mobile-nav-item">
            <div style="font-size: 1.2rem; margin-bottom: 3px;"><i class="fas fa-home"></i></div>
            <div style="font-size: 0.7rem; opacity: 0.9;">Dashboard</div>
        </a>
        
        <a href="take_attendance.php" class="mobile-nav-item active">
            <div style="font-size: 1.2rem; margin-bottom: 3px;"><i class="fas fa-clipboard-check"></i></div>
            <div style="font-size: 0.7rem; opacity: 0.9;">Record</div>
        </a>
        
        <a href="view_attendance.php" class="mobile-nav-item">
            <div style="font-size: 1.2rem; margin-bottom: 3px;"><i class="fas fa-list"></i></div>
            <div style="font-size: 0.7rem; opacity: 0.9;">View</div>
        </a>
    </nav>
    
    <script>
        // Track selected cadets
        let selectedCadets = new Set();
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            <?php 
            // Initialize UI based on current data
            if (isset($cadetsResult) && $cadetsResult->num_rows > 0) {
                $cadetsResult->data_seek(0);
                while($cadet = $cadetsResult->fetch_assoc()): 
                    $currentStatus = $cadet['today_status'] ?? 'present'; // DEFAULT: present
                    $currentAbsentType = $cadet['absent_type'] ?? '';
            ?>
            // Set initial UI state for each cadet
            const cadetId<?php echo $cadet['user_id']; ?> = <?php echo $cadet['user_id']; ?>;
            const status<?php echo $cadet['user_id']; ?> = '<?php echo $currentStatus; ?>';
            const absentType<?php echo $cadet['user_id']; ?> = '<?php echo $currentAbsentType; ?>';
            
            if (status<?php echo $cadet['user_id']; ?> === 'absent') {
                // Show sub options for absent cadets
                document.getElementById('sub_options_<?php echo $cadet['user_id']; ?>').classList.add('show');
                
                if (absentType<?php echo $cadet['user_id']; ?> === 'cuti' || absentType<?php echo $cadet['user_id']; ?> === 'excuse') {
                    // Show reason input for sick/excuse
                    document.getElementById('reason_<?php echo $cadet['user_id']; ?>').classList.add('show');
                }
            }
            <?php endwhile; } ?>
        });
        
        // Filter functions
        function filterService(service) {
            const sessionId = <?php echo $selected_session_id ? "'$selected_session_id'" : 'null'; ?>;
            const rank = '<?php echo $rank_filter; ?>';
            const date = '<?php echo $date_filter; ?>';
            
            if (sessionId) {
                window.location.href = `take_attendance.php?session_id=${sessionId}&service=${service}&rank=${rank}&date=${date}`;
            } else {
                window.location.href = `take_attendance.php?service=${service}&rank=${rank}&date=${date}`;
            }
        }
        
        function filterRank(rank) {
            const sessionId = <?php echo $selected_session_id ? "'$selected_session_id'" : 'null'; ?>;
            const service = '<?php echo $service_filter; ?>';
            const date = '<?php echo $date_filter; ?>';
            
            if (sessionId) {
                window.location.href = `take_attendance.php?session_id=${sessionId}&service=${service}&rank=${rank}&date=${date}`;
            } else {
                window.location.href = `take_attendance.php?service=${service}&rank=${rank}&date=${date}`;
            }
        }
        
        function filterDate(dateFilter) {
            const sessionId = <?php echo $selected_session_id ? "'$selected_session_id'" : 'null'; ?>;
            const service = '<?php echo $service_filter; ?>';
            const rank = '<?php echo $rank_filter; ?>';
            
            let url = `take_attendance.php?service=${service}&rank=${rank}&date=${dateFilter}`;
            if (sessionId) {
                url += `&session_id=${sessionId}`;
            }
            
            window.location.href = url;
        }
        
        function selectSession(sessionId) {
            window.location.href = `take_attendance.php?session_id=${sessionId}&service=<?php echo $service_filter; ?>&rank=<?php echo $rank_filter; ?>&date=<?php echo $date_filter; ?>`;
        }
        
        // Toggle cadet selection
        function toggleCadetSelection(cadetId) {
            const checkbox = document.getElementById('checkbox_' + cadetId);
            const cadetElement = document.getElementById('cadet_' + cadetId);
            
            if (checkbox.checked) {
                selectedCadets.add(cadetId);
                cadetElement.classList.add('selected');
            } else {
                selectedCadets.delete(cadetId);
                cadetElement.classList.remove('selected');
            }
            
            updateBulkActionBar();
        }
        
        // Select all cadets
        function selectAllCadets() {
            const checkboxes = document.querySelectorAll('.cadet-checkbox');
            const cadetItems = document.querySelectorAll('.cadet-item');
            
            // Check if all are already selected
            const allSelected = selectedCadets.size === checkboxes.length;
            
            if (allSelected) {
                // Deselect all
                selectedCadets.clear();
                checkboxes.forEach(cb => cb.checked = false);
                cadetItems.forEach(item => item.classList.remove('selected'));
            } else {
                // Select all
                checkboxes.forEach(cb => {
                    const cadetId = parseInt(cb.id.replace('checkbox_', ''));
                    selectedCadets.add(cadetId);
                    cb.checked = true;
                });
                cadetItems.forEach(item => item.classList.add('selected'));
            }
            
            updateBulkActionBar();
        }
        
        // Update bulk action bar
        function updateBulkActionBar() {
            const bulkActionBar = document.getElementById('bulkActionBar');
            const selectedCount = document.getElementById('selectedCount');
            
            selectedCount.textContent = selectedCadets.size;
            
            if (selectedCadets.size > 0) {
                bulkActionBar.style.display = 'flex';
            } else {
                bulkActionBar.style.display = 'none';
            }
        }
        
        // Set all cadets as present
        function setAllPresent() {
            const checkboxes = document.querySelectorAll('.cadet-checkbox');
            checkboxes.forEach(cb => {
                const cadetId = parseInt(cb.id.replace('checkbox_', ''));
                setPresent(cadetId);
            });
            
            // Show success message
            showTempMessage('All cadets marked as Present', 'success');
        }
        
        // Bulk set selected cadets as present
        function bulkSetPresent() {
            if (selectedCadets.size === 0) return;
            
            selectedCadets.forEach(cadetId => {
                setPresent(cadetId);
            });
            
            showTempMessage(`${selectedCadets.size} cadets marked as Present`, 'success');
        }
        
        // Bulk set selected cadets as absent
        function bulkSetAbsent() {
            if (selectedCadets.size === 0) return;
            
            selectedCadets.forEach(cadetId => {
                setAbsent(cadetId);
            });
            
            showTempMessage(`${selectedCadets.size} cadets marked as Absent`, 'success');
        }
        
        // Set cadet as present
        function setPresent(cadetId) {
            const statusInput = document.getElementById('status_' + cadetId);
            const absentTypeInput = document.getElementById('absent_type_' + cadetId);
            const reasonInput = document.getElementById('reason_' + cadetId);
            const reasonText = document.getElementById('reason_text_' + cadetId);
            const reasonHiddenInput = document.getElementById('reason_hidden_' + cadetId);
            const subOptions = document.getElementById('sub_options_' + cadetId);
            
            // Update hidden inputs
            statusInput.value = 'present';
            absentTypeInput.value = '';
            reasonHiddenInput.value = '';
            
            // Update buttons
            const buttons = document.querySelectorAll(`[data-cadet="${cadetId}"]`);
            buttons.forEach(btn => btn.classList.remove('active'));
            
            // Activate present button
            const presentBtn = document.querySelector(`[data-cadet="${cadetId}"].present`);
            if (presentBtn) {
                presentBtn.classList.add('active');
            }
            
            // Hide sub options and reason input
            subOptions.classList.remove('show');
            reasonInput.classList.remove('show');
            reasonText.value = '';
        }
        
        // Set cadet as absent
        function setAbsent(cadetId) {
            const statusInput = document.getElementById('status_' + cadetId);
            const absentTypeInput = document.getElementById('absent_type_' + cadetId);
            const reasonInput = document.getElementById('reason_' + cadetId);
            const reasonText = document.getElementById('reason_text_' + cadetId);
            const reasonHiddenInput = document.getElementById('reason_hidden_' + cadetId);
            const subOptions = document.getElementById('sub_options_' + cadetId);
            
            // Update hidden inputs
            statusInput.value = 'absent';
            absentTypeInput.value = '';
            reasonHiddenInput.value = '';
            
            // Update buttons
            const buttons = document.querySelectorAll(`[data-cadet="${cadetId}"]`);
            buttons.forEach(btn => btn.classList.remove('active'));
            
            // Activate absent button
            const absentBtn = document.querySelector(`[data-cadet="${cadetId}"].absent`);
            if (absentBtn) {
                absentBtn.classList.add('active');
            }
            
            // Show sub options, hide reason input
            subOptions.classList.add('show');
            reasonInput.classList.remove('show');
            reasonText.value = '';
            
            // Deactivate sick/excuse buttons if active
            const sickBtn = document.querySelector(`[data-cadet="${cadetId}"].sick`);
            const excuseBtn = document.querySelector(`[data-cadet="${cadetId}"].excuse`);
            if (sickBtn) sickBtn.classList.remove('active');
            if (excuseBtn) excuseBtn.classList.remove('active');
        }
        
        // Set absent type (sick or excuse)
        function setAbsentType(cadetId, absentType) {
            const absentTypeInput = document.getElementById('absent_type_' + cadetId);
            const reasonInput = document.getElementById('reason_' + cadetId);
            const reasonText = document.getElementById('reason_text_' + cadetId);
            
            // Update hidden input
            absentTypeInput.value = absentType;
            
            // Update buttons
            const sickBtn = document.querySelector(`[data-cadet="${cadetId}"].sick`);
            const excuseBtn = document.querySelector(`[data-cadet="${cadetId}"].excuse`);
            
            if (absentType === 'cuti') {
                if (sickBtn) sickBtn.classList.add('active');
                if (excuseBtn) excuseBtn.classList.remove('active');
            } else if (absentType === 'excuse') {
                if (sickBtn) sickBtn.classList.remove('active');
                if (excuseBtn) excuseBtn.classList.add('active');
            }
            
            // Show reason input
            reasonInput.classList.add('show');
            reasonText.focus();
        }
        
        // Set reason for absence
        function setReason(cadetId, reason) {
            const reasonHiddenInput = document.getElementById('reason_hidden_' + cadetId);
            reasonHiddenInput.value = reason;
        }
        
        // Show temporary message
        function showTempMessage(message, type) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type === 'success' ? 'success' : 'error'}`;
            alertDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
            
            const container = document.querySelector('.container');
            const firstElement = container.children[2]; // After header and filters
            
            container.insertBefore(alertDiv, firstElement);
            
            // Remove after 3 seconds
            setTimeout(() => {
                alertDiv.remove();
            }, 3000);
        }
        
        // Auto-save feature warning
        let hasChanges = false;
        
        // Monitor form changes
        const form = document.getElementById('attendanceForm');
        if (form) {
            // Add change listeners to all interactive elements
            const interactiveElements = form.querySelectorAll('button, textarea, .cadet-checkbox');
            interactiveElements.forEach(element => {
                element.addEventListener('click', function() {
                    hasChanges = true;
                });
                element.addEventListener('input', function() {
                    hasChanges = true;
                });
            });
            
            form.addEventListener('submit', function(e) {
                hasChanges = false;
                
                // Show loading state
                const submitBtn = document.getElementById('submitBtn');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                
                return true;
            });
        }
        
        // Warn before leaving page with unsaved changes
        window.addEventListener('beforeunload', function(e) {
            if (hasChanges) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave this page?';
            }
        });
    </script>
</body>
</html>
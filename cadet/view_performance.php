<?php
// cadet/view_performance.php - VIEW PERFORMANCE FOR CADET WITH GPA SYSTEM
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
    $service_type = $user['service_type'] ?? null;
    $rank_level = $user['rank_level'] ?? null;
    $today = date('Y-m-d');
    $current_month = date('Y-m');
    $current_year = date('Y');
    $user_name = $user['name'] ?? 'Cadet';
    $military_number = $user['military_number'] ?? 'N/A';
    
    // Get selected month from URL or default to current month
    $selected_month = $_GET['month'] ?? $current_month;
    list($year, $month) = explode('-', $selected_month);
    
    // Grading System based on provided image
    $grading_system = [
        'A' => ['min' => 85, 'max' => 100, 'nilai_mata' => 4.00, 'tahap' => 'Excellent', 'color' => '#10b981'],
        'A-' => ['min' => 80, 'max' => 84, 'nilai_mata' => 3.67, 'tahap' => 'Excellent', 'color' => '#34d399'],
        'B+' => ['min' => 75, 'max' => 79, 'nilai_mata' => 3.33, 'tahap' => 'Very Good', 'color' => '#60a5fa'],
        'B' => ['min' => 70, 'max' => 74, 'nilai_mata' => 3.00, 'tahap' => 'Good', 'color' => '#3b82f6'],
        'B-' => ['min' => 65, 'max' => 69, 'nilai_mata' => 2.67, 'tahap' => 'Good', 'color' => '#6366f1'],
        'C+' => ['min' => 60, 'max' => 64, 'nilai_mata' => 2.33, 'tahap' => 'Satisfactory', 'color' => '#f59e0b'],
        'C' => ['min' => 55, 'max' => 59, 'nilai_mata' => 2.00, 'tahap' => 'Satisfactory', 'color' => '#fbbf24'],
        'C-' => ['min' => 50, 'max' => 54, 'nilai_mata' => 1.67, 'tahap' => 'Pass', 'color' => '#f97316'],
        'D+' => ['min' => 45, 'max' => 49, 'nilai_mata' => 1.33, 'tahap' => 'Conditional Pass', 'color' => '#ef4444'],
        'D' => ['min' => 40, 'max' => 44, 'nilai_mata' => 1.00, 'tahap' => 'Conditional Pass', 'color' => '#e53e3e'],
        'F' => ['min' => 0, 'max' => 39, 'nilai_mata' => 0.00, 'tahap' => 'Fail', 'color' => '#c53030']
    ];
    
    // 1. GET ATTENDANCE DATA FOR SELECTED MONTH
    $attendanceSql = "SELECT 
                        a.date,
                        a.status,
                        ts.training_type,
                        ts.location,
                        ts.session_time,
                        a.recorded_at
                    FROM attendance a
                    JOIN training_sessions ts ON a.session_id = ts.session_id
                    WHERE a.user_id = ?
                    AND DATE_FORMAT(a.date, '%Y-%m') = ?
                    ORDER BY a.date ASC";
    
    $attendanceStmt = $db->prepare($attendanceSql);
    $attendanceStmt->bind_param("is", $cadet_id, $selected_month);
    $attendanceStmt->execute();
    $attendanceResult = $attendanceStmt->get_result();
    
    // 2. GET MONTHLY PERFORMANCE SUMMARY
    $performanceSql = "SELECT 
                        COUNT(DISTINCT a.date) as total_sessions,
                        SUM(CASE WHEN a.status IN ('present', 'excused') THEN 1 ELSE 0 END) as attended_sessions,
                        AVG(CASE 
                            WHEN a.status = 'present' THEN 100 
                            WHEN a.status = 'excused' THEN 50 
                            ELSE 0 
                        END) as attendance_score
                    FROM attendance a
                    WHERE a.user_id = ?
                    AND DATE_FORMAT(a.date, '%Y-%m') = ?";
    
    $performanceStmt = $db->prepare($performanceSql);
    $performanceStmt->bind_param("is", $cadet_id, $selected_month);
    $performanceStmt->execute();
    $performanceData = $performanceStmt->get_result()->fetch_assoc();
    
    // Calculate attendance percentage
    $total_sessions = $performanceData['total_sessions'] ?? 0;
    $attended_sessions = $performanceData['attended_sessions'] ?? 0;
    $attendance_percentage = $total_sessions > 0 ? round(($attended_sessions / $total_sessions) * 100, 1) : 0;
    $attendance_score = $performanceData['attendance_score'] ?? 0;
    
    // 3. GET OVERALL PERFORMANCE SCORES FROM USER TABLE
    $scoresSql = "SELECT 
                    attendance_score,
                    discipline_score,
                    skill_score,
                    performance_grade,
                    performance_level,
                    performance_comments,
                    last_performance_update
                FROM users 
                WHERE user_id = ?";
    
    $scoresStmt = $db->prepare($scoresSql);
    $scoresStmt->bind_param("i", $cadet_id);
    $scoresStmt->execute();
    $scoresData = $scoresStmt->get_result()->fetch_assoc();
    
    // Calculate total performance score
    $attendance_score_db = floatval($scoresData['attendance_score'] ?? 0);
    $discipline_score = floatval($scoresData['discipline_score'] ?? 0);
    $skill_score = floatval($scoresData['skill_score'] ?? 0);
    
    $score_count = 0;
    $total_score = 0;
    
    if ($attendance_score_db > 0) {
        $total_score += $attendance_score_db;
        $score_count++;
    }
    
    if ($discipline_score > 0) {
        $total_score += $discipline_score;
        $score_count++;
    }
    
    if ($skill_score > 0) {
        $total_score += $skill_score;
        $score_count++;
    }
    
    $average_score = $score_count > 0 ? round($total_score / $score_count, 2) : 0;
    
    // Determine grade based on average score
    $performance_grade = 'F';
    $nilai_mata = 0.00;
    $tahap_prestasi = 'Fail';
    $grade_color = '#c53030';
    
    foreach ($grading_system as $grade => $criteria) {
        if ($average_score >= $criteria['min'] && $average_score <= $criteria['max']) {
            $performance_grade = $grade;
            $nilai_mata = $criteria['nilai_mata'];
            $tahap_prestasi = $criteria['tahap'];
            $grade_color = $criteria['color'];
            break;
        }
    }
    
    // 4. GET PERFORMANCE HISTORY (Last 6 months)
    $historySql = "SELECT 
                    DATE_FORMAT(a.date, '%Y-%m') as month_year,
                    COUNT(DISTINCT a.date) as total_sessions,
                    SUM(CASE WHEN a.status IN ('present', 'excused') THEN 1 ELSE 0 END) as attended_sessions
                FROM attendance a
                WHERE a.user_id = ?
                AND a.date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(a.date, '%Y-%m')
                ORDER BY month_year DESC";
    
    $historyStmt = $db->prepare($historySql);
    $historyStmt->bind_param("i", $cadet_id);
    $historyStmt->execute();
    $historyResult = $historyStmt->get_result();
    
    $performance_history = [];
    while ($row = $historyResult->fetch_assoc()) {
        $attendance_rate = $row['total_sessions'] > 0 
            ? round(($row['attended_sessions'] / $row['total_sessions']) * 100, 1) 
            : 0;
        
        // Estimate score based on attendance (for historical months)
        $estimated_score = min(100, $attendance_rate * 1.2); // Scale attendance to score
        
        // Determine grade for historical data
        $historical_grade = 'F';
        foreach ($grading_system as $grade => $criteria) {
            if ($estimated_score >= $criteria['min'] && $estimated_score <= $criteria['max']) {
                $historical_grade = $grade;
                break;
            }
        }
        
        $performance_history[] = [
            'month' => $row['month_year'],
            'attendance_rate' => $attendance_rate,
            'estimated_score' => round($estimated_score, 1),
            'grade' => $historical_grade
        ];
    }
    
    // 5. GET ALL MONTHS WITH ATTENDANCE
    $monthsSql = "SELECT DISTINCT DATE_FORMAT(date, '%Y-%m') as month_year 
                 FROM attendance 
                 WHERE user_id = ?
                 ORDER BY month_year DESC";
    
    $monthsStmt = $db->prepare($monthsSql);
    $monthsStmt->bind_param("i", $cadet_id);
    $monthsStmt->execute();
    $monthsResult = $monthsStmt->get_result();
    $available_months = [];
    while ($row = $monthsResult->fetch_assoc()) {
        $available_months[] = $row['month_year'];
    }
    
    // 6. GET TRAINING TYPE BREAKDOWN
    $trainingSql = "SELECT 
                    ts.training_type,
                    COUNT(*) as count,
                    SUM(CASE WHEN a.status IN ('present', 'excused') THEN 1 ELSE 0 END) as attended
                FROM attendance a
                JOIN training_sessions ts ON a.session_id = ts.session_id
                WHERE a.user_id = ?
                AND DATE_FORMAT(a.date, '%Y-%m') = ?
                GROUP BY ts.training_type";
    
    $trainingStmt = $db->prepare($trainingSql);
    $trainingStmt->bind_param("is", $cadet_id, $selected_month);
    $trainingStmt->execute();
    $trainingResult = $trainingStmt->get_result();
    
    $training_breakdown = [];
    while ($row = $trainingResult->fetch_assoc()) {
        $attendance_rate = $row['count'] > 0 
            ? round(($row['attended'] / $row['count']) * 100, 1) 
            : 0;
        
        $training_breakdown[] = [
            'type' => $row['training_type'],
            'total' => $row['count'],
            'attended' => $row['attended'],
            'rate' => $attendance_rate
        ];
    }
    
    // 7. CADET INFO
    $cadetInfo = [
        'name' => $user['name'],
        'military_number' => $user['military_number'],
        'service_type' => $user['service_type'],
        'rank_level' => $user['rank_level'],
        'join_date' => $user['join_date'],
        'email' => $user['email'],
        'phone' => $user['phone']
    ];
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Helper functions
function getServiceLabel($type) {
    $labels = [
        'darat' => 'Army',
        'laut' => 'Navy', 
        'udara' => 'Air Force'
    ];
    return $labels[$type] ?? $type;
}

function getRankLabel($rank) {
    $labels = [
        'junior' => 'Junior',
        'intermediate' => 'Intermediate',
        'senior' => 'Senior'
    ];
    return $labels[$rank] ?? $rank;
}

function formatDate($dateString) {
    if (empty($dateString) || $dateString == '0000-00-00') return '';
    try {
        $date = strtotime($dateString);
        return $date ? date('d/m/Y', $date) : '';
    } catch (Exception $e) {
        return '';
    }
}

function getMonthName($monthYear) {
    if (empty($monthYear)) return '';
    return date('F Y', strtotime($monthYear . '-01'));
}

function getGradeColor($grade) {
    global $grading_system;
    return $grading_system[$grade]['color'] ?? '#718096';
}

function getAttendanceBadge($rate) {
    if ($rate >= 90) {
        return '<span class="attendance-badge excellent"><i class="fas fa-star"></i> Excellent</span>';
    } elseif ($rate >= 80) {
        return '<span class="attendance-badge good"><i class="fas fa-thumbs-up"></i> Good</span>';
    } elseif ($rate >= 70) {
        return '<span class="attendance-badge average"><i class="fas fa-check"></i> Satisfactory</span>';
    } elseif ($rate >= 40) {
        return '<span class="attendance-badge poor"><i class="fas fa-exclamation-triangle"></i> Needs Improvement</span>';
    } else {
        return '<span class="attendance-badge failed"><i class="fas fa-times-circle"></i> Failed</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Performance - CAAMS</title>
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
            --money: #38a169;
            --info: #4299e1;
            
            /* Grade Colors */
            --grade-a: #10b981;
            --grade-a-: #34d399;
            --grade-b+: #60a5fa;
            --grade-b: #3b82f6;
            --grade-b-: #6366f1;
            --grade-c+: #f59e0b;
            --grade-c: #fbbf24;
            --grade-c-: #f97316;
            --grade-d+: #ef4444;
            --grade-d: #e53e3e;
            --grade-f: #c53030;
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
            background: linear-gradient(135deg, var(--grade-b) 0%, var(--grade-b+) 100%);
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
        
        /* MONTH FILTER */
        .month-filter {
            background: white;
            border-radius: 10px;
            padding: 10px 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .filter-label {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .month-select {
            padding: 8px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            color: var(--secondary);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            min-width: 180px;
        }
        
        .month-select:focus {
            border-color: var(--accent);
            outline: none;
        }
        
        /* GPA SUMMARY */
        .gpa-summary {
            background: linear-gradient(135deg, var(--primary) 0%, #2c5282 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .gpa-summary::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        
        .gpa-label {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .gpa-value {
            font-size: 3rem;
            font-weight: 800;
            margin: 10px 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
            color: white;
        }
        
        .gpa-grade {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 10px;
        }
        
        .gpa-details {
            margin-top: 15px;
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        /* STATS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
            text-align: center;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        
        .stat-icon {
            font-size: 1.5rem;
            margin-bottom: 10px;
            opacity: 0.8;
        }
        
        .stat-icon.attendance { color: var(--info); }
        .stat-icon.score { color: var(--grade-a); }
        .stat-icon.discipline { color: var(--grade-b); }
        .stat-icon.skill { color: var(--grade-c); }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 5px 0;
            line-height: 1;
        }
        
        .attendance .stat-number { color: var(--info); }
        .score .stat-number { color: var(--grade-a); }
        .discipline .stat-number { color: var(--grade-b); }
        .skill .stat-number { color: var(--grade-c); }
        
        .stat-label {
            color: var(--secondary);
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        /* ATTENDANCE BADGES */
        .attendance-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .attendance-badge.excellent {
            background: rgba(72, 187, 120, 0.2);
            color: #155724;
            border: 1px solid rgba(72, 187, 120, 0.3);
        }
        
        .attendance-badge.good {
            background: rgba(66, 153, 225, 0.2);
            color: #0c5460;
            border: 1px solid rgba(66, 153, 225, 0.3);
        }
        
        .attendance-badge.average {
            background: rgba(255, 193, 7, 0.2);
            color: #856404;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }
        
        .attendance-badge.poor {
            background: rgba(253, 126, 20, 0.2);
            color: #8d2d00;
            border: 1px solid rgba(253, 126, 20, 0.3);
        }
        
        .attendance-badge.failed {
            background: rgba(220, 53, 69, 0.2);
            color: #721c24;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        
        /* GRADE BADGE */
        .grade-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 1.2rem;
            font-weight: 800;
            color: white;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        /* PERFORMANCE DETAILS */
        .performance-details {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .section-title {
            color: var(--primary);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .detail-item:last-child {
            border-bottom: none;
            font-weight: 600;
            color: var(--primary);
            font-size: 1.1rem;
            padding-top: 15px;
        }
        
        .detail-label {
            color: var(--gray);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .detail-value {
            font-weight: 600;
            color: var(--secondary);
            text-align: right;
        }
        
        .detail-value.score {
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        /* PROGRESS BAR */
        .progress-container {
            margin: 15px 0;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .progress-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .progress-percent {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--accent);
        }
        
        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--accent);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        /* PERFORMANCE HISTORY */
        .performance-history {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .history-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        
        .history-card {
            background: #f7fafc;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .history-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .history-month {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 5px;
        }
        
        .history-grade {
            font-size: 1.5rem;
            font-weight: 800;
            margin: 5px 0;
            padding: 5px;
            border-radius: 5px;
            color: white;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
        }
        
        .history-score {
            font-size: 0.85rem;
            color: var(--secondary);
            font-weight: 600;
        }
        
        /* GRADING SYSTEM */
        .grading-system {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .grading-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 0.85rem;
        }
        
        .grading-table th {
            background: #edf2f7;
            color: var(--primary);
            padding: 10px;
            text-align: center;
            font-weight: 600;
        }
        
        .grading-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e2e8f0;
            text-align: center;
        }
        
        .grading-table tr:hover {
            background: #f7fafc;
        }
        
        /* TRAINING BREAKDOWN */
        .training-breakdown {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .breakdown-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 15px;
        }
        
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background: #f7fafc;
            border-radius: 8px;
            border-left: 4px solid var(--accent);
        }
        
        .breakdown-info h4 {
            color: var(--primary);
            margin-bottom: 2px;
            font-size: 0.95rem;
        }
        
        .breakdown-info p {
            color: var(--gray);
            font-size: 0.8rem;
        }
        
        /* NO DATA */
        .no-data {
            text-align: center;
            padding: 30px;
            color: var(--gray);
        }
        
        .no-data i {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.3;
        }
        
        /* COMMENT BOX */
        .comment-box {
            background: #f0f9ff;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid var(--info);
        }
        
        .comment-header {
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .comment-content {
            color: var(--secondary);
            line-height: 1.6;
            font-size: 0.9rem;
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
        
        /* GRADE COLORS FOR CSS */
        .grade-a { background: var(--grade-a); }
        .grade-a- { background: var(--grade-a-); }
        .grade-b+ { background: var(--grade-b+); }
        .grade-b { background: var(--grade-b); }
        .grade-b- { background: var(--grade-b-); }
        .grade-c+ { background: var(--grade-c+); }
        .grade-c { background: var(--grade-c); }
        .grade-c- { background: var(--grade-c-); }
        .grade-d+ { background: var(--grade-d+); }
        .grade-d { background: var(--grade-d); }
        .grade-f { background: var(--grade-f); }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <h1>
                <i class="fas fa-chart-line"></i>
                My Performance
            </h1>
            <div class="user-info">
                <div class="user-details">
                    <div class="user-avatar">
                        <?php 
                            $initials = strtoupper(substr($cadetInfo['name'], 0, 1));
                            echo $initials;
                        ?>
                    </div>
                    <div class="user-text">
                        <h3><?php echo htmlspecialchars($cadetInfo['name']); ?></h3>
                        <p>Military No: <?php echo $cadetInfo['military_number']; ?></p>
                        <div class="user-badges">
                            <span class="service-badge"><?php echo getServiceLabel($cadetInfo['service_type']); ?></span>
                            <span class="rank-badge"><?php echo getRankLabel($cadetInfo['rank_level']); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- MONTH FILTER -->
        <div class="month-filter">
            <div class="filter-label">
                <i class="fas fa-calendar-alt"></i>
                Select Month
            </div>
            <select class="month-select" onchange="window.location.href='?month='+this.value">
                <?php foreach ($available_months as $month_opt): ?>
                    <option value="<?php echo $month_opt; ?>" <?php echo $selected_month == $month_opt ? 'selected' : ''; ?>>
                        <?php echo getMonthName($month_opt); ?>
                    </option>
                <?php endforeach; ?>
                <?php if (!in_array($selected_month, $available_months) && $selected_month != $current_month): ?>
                    <option value="<?php echo $selected_month; ?>" selected>
                        <?php echo getMonthName($selected_month); ?>
                    </option>
                <?php endif; ?>
                <?php if (empty($available_months)): ?>
                    <option value="<?php echo $selected_month; ?>" selected>
                        <?php echo getMonthName($selected_month); ?>
                    </option>
                <?php endif; ?>
            </select>
        </div>
        
        <!-- GPA SUMMARY -->
        <div class="gpa-summary">
            <div class="gpa-label">
                <i class="fas fa-graduation-cap"></i>
                GRADE POINT AVERAGE (GPA)
            </div>
            
            <div class="gpa-value"><?php echo number_format($nilai_mata, 2); ?></div>
            
            <div class="grade-badge" style="background: <?php echo $grade_color; ?>;">
                <?php echo $performance_grade; ?>
            </div>
            
            <div class="gpa-details">
                <?php echo $tahap_prestasi; ?>
                <br>
                <small style="opacity: 0.8; font-size: 0.85rem;">
                    Average Score: <?php echo number_format($average_score, 2); ?>%
                </small>
            </div>
        </div>
        
        <!-- STATS GRID -->
        <div class="stats-grid">
            <div class="stat-card attendance">
                <div class="stat-icon attendance">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-number">
                    <?php echo $attendance_percentage; ?>%
                </div>
                <div class="stat-label">Attendance</div>
                <div style="margin-top: 8px;">
                    <?php echo getAttendanceBadge($attendance_percentage); ?>
                </div>
            </div>
            
            <div class="stat-card score">
                <div class="stat-icon score">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-number">
                    <?php echo number_format($average_score, 1); ?>%
                </div>
                <div class="stat-label">Average Score</div>
                <small style="color: var(--gray); font-size: 0.75rem;">
                    (<?php echo $attended_sessions; ?>/<?php echo $total_sessions; ?> sessions)
                </small>
            </div>
            
            <div class="stat-card discipline">
                <div class="stat-icon discipline">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="stat-number">
                    <?php echo number_format($discipline_score, 1); ?>%
                </div>
                <div class="stat-label">Discipline</div>
                <div style="margin-top: 5px;">
                    <?php 
                    $discipline_badge = '';
                    if ($discipline_score >= 85) {
                        $discipline_badge = '<span style="color: var(--success); font-size: 0.8rem;"><i class="fas fa-shield-alt"></i> Excellent</span>';
                    } elseif ($discipline_score >= 70) {
                        $discipline_badge = '<span style="color: var(--info); font-size: 0.8rem;"><i class="fas fa-shield-alt"></i> Good</span>';
                    } elseif ($discipline_score >= 50) {
                        $discipline_badge = '<span style="color: var(--warning); font-size: 0.8rem;"><i class="fas fa-shield-alt"></i> Satisfactory</span>';
                    } else {
                        $discipline_badge = '<span style="color: var(--danger); font-size: 0.8rem;"><i class="fas fa-shield-alt"></i> Needs Improvement</span>';
                    }
                    echo $discipline_badge;
                    ?>
                </div>
            </div>
            
            <div class="stat-card skill">
                <div class="stat-icon skill">
                    <i class="fas fa-tools"></i>
                </div>
                <div class="stat-number">
                    <?php echo number_format($skill_score, 1); ?>%
                </div>
                <div class="stat-label">Skills</div>
                <div style="margin-top: 5px;">
                    <?php 
                    $skill_badge = '';
                    if ($skill_score >= 85) {
                        $skill_badge = '<span style="color: var(--success); font-size: 0.8rem;"><i class="fas fa-star"></i> Expert</span>';
                    } elseif ($skill_score >= 70) {
                        $skill_badge = '<span style="color: var(--info); font-size: 0.8rem;"><i class="fas fa-star-half-alt"></i> Proficient</span>';
                    } elseif ($skill_score >= 50) {
                        $skill_badge = '<span style="color: var(--warning); font-size: 0.8rem;"><i class="fas fa-star"></i> Intermediate</span>';
                    } else {
                        $skill_badge = '<span style="color: var(--danger); font-size: 0.8rem;"><i class="fas fa-star"></i> Basic</span>';
                    }
                    echo $skill_badge;
                    ?>
                </div>
            </div>
        </div>
        
        <!-- PERFORMANCE DETAILS -->
        <div class="performance-details">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-info-circle"></i>
                    Monthly Performance Details
                </h3>
                <span style="font-size: 0.8rem; color: var(--gray);">
                    <?php echo getMonthName($selected_month); ?>
                </span>
            </div>
            
            <div class="details-grid">
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-calendar-alt"></i> Total Training Sessions
                    </span>
                    <span class="detail-value">
                        <?php echo $total_sessions; ?> sessions
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-calendar-check"></i> Attendance
                    </span>
                    <span class="detail-value">
                        <?php echo $attended_sessions; ?> sessions (<?php echo $attendance_percentage; ?>%)
                    </span>
                </div>
                
                <div class="progress-container">
                    <div class="progress-header">
                        <div class="progress-title">Attendance Performance</div>
                        <div class="progress-percent"><?php echo $attendance_percentage; ?>%</div>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min($attendance_percentage, 100); ?>%"></div>
                    </div>
                    <small style="color: var(--gray); font-size: 0.75rem;">
                        Target: 80% | <?php echo $attended_sessions; ?>/<?php echo $total_sessions; ?> sessions
                    </small>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-user-shield"></i> Discipline Score
                    </span>
                    <span class="detail-value score" style="color: <?php echo getGradeColor($performance_grade); ?>;">
                        <?php echo number_format($discipline_score, 1); ?>%
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-tools"></i> Skills Score
                    </span>
                    <span class="detail-value score" style="color: <?php echo getGradeColor($performance_grade); ?>;">
                        <?php echo number_format($skill_score, 1); ?>%
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-calculator"></i> Average Score
                    </span>
                    <span class="detail-value score" style="color: <?php echo getGradeColor($performance_grade); ?>; font-size: 1.2rem;">
                        <?php echo number_format($average_score, 1); ?>%
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-star"></i> Performance Grade
                    </span>
                    <span class="detail-value">
                        <span class="grade-badge" style="background: <?php echo $grade_color; ?>; padding: 5px 15px; font-size: 1rem;">
                            <?php echo $performance_grade; ?>
                        </span>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-graduation-cap"></i> Grade Point (GPA)
                    </span>
                    <span class="detail-value score" style="color: <?php echo getGradeColor($performance_grade); ?>; font-size: 1.2rem;">
                        <?php echo number_format($nilai_mata, 2); ?>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-medal"></i> Performance Level
                    </span>
                    <span class="detail-value">
                        <strong><?php echo $tahap_prestasi; ?></strong>
                    </span>
                </div>
                
                <?php if ($scoresData['last_performance_update'] && $scoresData['last_performance_update'] != '0000-00-00'): ?>
                <div class="detail-item" style="font-size: 0.85rem; color: var(--gray); padding-top: 10px;">
                    <span class="detail-label">
                        <i class="fas fa-history"></i> Last Updated
                    </span>
                    <span class="detail-value">
                        <?php echo formatDate($scoresData['last_performance_update']); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- TRAINING BREAKDOWN -->
        <?php if (!empty($training_breakdown)): ?>
        <div class="training-breakdown">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-running"></i>
                    Training Breakdown
                </h3>
                <span style="font-size: 0.8rem; color: var(--gray);">
                    <?php echo count($training_breakdown); ?> training types
                </span>
            </div>
            
            <div class="breakdown-list">
                <?php foreach ($training_breakdown as $training): ?>
                <div class="breakdown-item">
                    <div class="breakdown-info">
                        <h4><?php echo htmlspecialchars($training['type']); ?></h4>
                        <p>
                            <?php echo $training['attended']; ?>/<?php echo $training['total']; ?> sessions attended
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-weight: 700; font-size: 1.1rem; color: var(--primary);">
                            <?php echo $training['rate']; ?>%
                        </div>
                        <small style="color: var(--gray); font-size: 0.75rem;">
                            Attendance Rate
                        </small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- PERFORMANCE HISTORY -->
        <?php if (!empty($performance_history)): ?>
        <div class="performance-history">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-history"></i>
                    Performance History (Last 6 Months)
                </h3>
            </div>
            
            <div class="history-grid">
                <?php foreach ($performance_history as $history): 
                    $history_grade = $history['grade'];
                    $history_grade_class = str_replace(['+', '-'], ['plus', '-'], strtolower($history_grade));
                ?>
                <div class="history-card">
                    <div class="history-month">
                        <?php echo date('M Y', strtotime($history['month'] . '-01')); ?>
                    </div>
                    <div class="history-grade grade-<?php echo $history_grade_class; ?>">
                        <?php echo $history_grade; ?>
                    </div>
                    <div class="history-score">
                        <?php echo $history['estimated_score']; ?>%
                    </div>
                    <small style="color: var(--gray); font-size: 0.75rem;">
                        <?php echo $history['attendance_rate']; ?>% attendance
                    </small>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- GRADING SYSTEM -->
        <div class="grading-system">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-list-alt"></i>
                    Grading & Assessment System
                </h3>
            </div>
            
            <table class="grading-table">
                <thead>
                    <tr>
                        <th>Score (%)</th>
                        <th>Grade</th>
                        <th>Grade Point (GPA)</th>
                        <th>Performance Level</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grading_system as $grade => $criteria): ?>
                    <tr>
                        <td><?php echo $criteria['min']; ?> - <?php echo $criteria['max']; ?></td>
                        <td style="color: <?php echo $criteria['color']; ?>; font-weight: 700;">
                            <?php echo $grade; ?>
                        </td>
                        <td><strong><?php echo $criteria['nilai_mata']; ?></strong></td>
                        <td><?php echo $criteria['tahap']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div style="margin-top: 15px; padding: 10px; background: #f0f9ff; border-radius: 5px; font-size: 0.85rem; color: var(--primary);">
                <i class="fas fa-info-circle"></i> 
                <strong>Note:</strong> This assessment system is based on official CADET grading system. Grade points are used for GPA calculation.
            </div>
        </div>
        
        <!-- COMMENTS & RECOMMENDATIONS -->
        <?php if (!empty($scoresData['performance_comments'])): ?>
        <div class="comment-box">
            <div class="comment-header">
                <i class="fas fa-comment-alt"></i>
                Admin Comments & Recommendations
            </div>
            <div class="comment-content">
                <?php echo nl2br(htmlspecialchars($scoresData['performance_comments'])); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- IMPORTANT NOTES -->
        <div style="background: #f0f9ff; border-radius: 10px; padding: 15px; margin-bottom: 15px; border-left: 4px solid var(--accent);">
            <h4 style="color: var(--primary); margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-info-circle"></i> Important Information
            </h4>
            <ul style="color: var(--secondary); font-size: 0.85rem; line-height: 1.6;">
                <li><strong>GPA System:</strong> Grade points (4.00 scale) based on average performance score</li>
                <li><strong>Grade Calculation:</strong> Determined according to established score ranges</li>
                <li><strong>Attendance:</strong> Directly contributes to overall performance score</li>
                <li><strong>Updates:</strong> Performance is updated periodically by administrators</li>
                <li><strong>Objective:</strong> Minimum performance target is grade C (Pass)</li>
            </ul>
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
        
        <a href="apply_excuse.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-file-medical"></i>
            </div>
            <div class="mobile-nav-label">Excuse</div>
        </a>
        
        <a href="view_performance.php" class="mobile-nav-item active">
            <div class="mobile-nav-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="mobile-nav-label">Performance</div>
        </a>
    </nav>
    
    <script>
        // Add animation to stats cards on load
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            // Animate history cards
            const historyCards = document.querySelectorAll('.history-card');
            historyCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 50 + 500);
            });
            
            // Mobile nav will now properly navigate when clicked
            // The navigation links have proper href attributes
            
            // Auto-refresh page every 2 minutes for real-time updates
            setInterval(() => {
                if (!document.hidden) {
                    console.log('Auto-refresh for performance updates...');
                    window.location.reload();
                }
            }, 120000); // 2 minutes
            
            // Animate progress bars
            const progressFills = document.querySelectorAll('.progress-fill');
            progressFills.forEach((fill, index) => {
                setTimeout(() => {
                    const width = fill.style.width;
                    fill.style.width = '0%';
                    setTimeout(() => {
                        fill.style.width = width;
                    }, 300);
                }, index * 200 + 300);
            });
        });
        
        // Set initial opacity for animation
        document.querySelectorAll('.stat-card, .history-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        });
        
        // Manual refresh button (optional)
        function manualRefresh() {
            if (confirm('Refresh performance data now?')) {
                window.location.reload();
            }
        }
    </script>
</body>
</html>
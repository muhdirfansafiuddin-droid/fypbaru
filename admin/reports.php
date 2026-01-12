<?php
// admin/reports.php - REAL-TIME PERFORMANCE REPORTS
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('admin');
$user = (new Auth())->getCurrentUser();
$db = new Database();

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Default filter values
$month_filter = $_GET['month'] ?? date('Y-m');
$rank_filter = $_GET['rank'] ?? 'all';
$service_filter = $_GET['service'] ?? 'all';
$grade_filter = $_GET['grade'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$training_filter = $_GET['training'] ?? 'all';

// Extract year and month from filter
list($year, $month) = explode('-', $month_filter);

// Sistem Gred 
$grading_system = [
    'A' => ['min' => 85, 'max' => 100, 'nilai_mata' => 4.00, 'tahap' => 'Cemerlang'],
    'A-' => ['min' => 80, 'max' => 84, 'nilai_mata' => 3.67, 'tahap' => 'Cemerlang'],
    'B+' => ['min' => 75, 'max' => 79, 'nilai_mata' => 3.33, 'tahap' => 'Sangat Baik'],
    'B' => ['min' => 70, 'max' => 74, 'nilai_mata' => 3.00, 'tahap' => 'Baik'],
    'B-' => ['min' => 65, 'max' => 69, 'nilai_mata' => 2.67, 'tahap' => 'Baik'],
    'C+' => ['min' => 60, 'max' => 64, 'nilai_mata' => 2.33, 'tahap' => 'Memuaskan'],
    'C' => ['min' => 55, 'max' => 59, 'nilai_mata' => 2.00, 'tahap' => 'Memuaskan'],
    'C-' => ['min' => 50, 'max' => 54, 'nilai_mata' => 1.67, 'tahap' => 'Lulus'],
    'D+' => ['min' => 45, 'max' => 49, 'nilai_mata' => 1.33, 'tahap' => 'Lulus Bersyarat'],
    'D' => ['min' => 40, 'max' => 44, 'nilai_mata' => 1.00, 'tahap' => 'Lulus Bersyarat'],
    'F' => ['min' => 0, 'max' => 39, 'nilai_mata' => 0.00, 'tahap' => 'Gagal']
];

// REAL-TIME DATABASE QUERIES

// 1. Get overall cadet statistics
$overallStatsQuery = "SELECT 
    COUNT(*) as total_cadets,
    SUM(CASE WHEN service_type = 'darat' THEN 1 ELSE 0 END) as army_count,
    SUM(CASE WHEN service_type = 'laut' THEN 1 ELSE 0 END) as navy_count,
    SUM(CASE WHEN service_type = 'udara' THEN 1 ELSE 0 END) as airforce_count,
    SUM(CASE WHEN rank_level = 'junior' THEN 1 ELSE 0 END) as junior_count,
    SUM(CASE WHEN rank_level = 'intermediate' THEN 1 ELSE 0 END) as intermediate_count,
    SUM(CASE WHEN rank_level = 'senior' THEN 1 ELSE 0 END) as senior_count
FROM users 
WHERE role = 'cadet'";

$overallStatsStmt = $db->prepare($overallStatsQuery);
$overallStatsStmt->execute();
$overallStats = $overallStatsStmt->get_result()->fetch_assoc();

// 2. Get attendance statistics for selected month
$attendanceStatsQuery = "SELECT 
    COUNT(DISTINCT a.user_id) as cadets_with_attendance,
    COUNT(*) as total_records,
    SUM(CASE WHEN a.status IN ('present', 'excused') THEN 1 ELSE 0 END) as present_count,
    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
    SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) as excused_count
FROM attendance a
JOIN users u ON a.user_id = u.user_id
WHERE YEAR(a.date) = ? AND MONTH(a.date) = ?
AND u.role = 'cadet'";

$attendanceStmt = $db->prepare($attendanceStatsQuery);
$attendanceStmt->bind_param("ii", $year, $month);
$attendanceStmt->execute();
$attendanceStats = $attendanceStmt->get_result()->fetch_assoc();

// Calculate attendance percentage
$attendanceRate = 0;
if ($attendanceStats && $attendanceStats['total_records'] > 0) {
    $attendanceRate = ($attendanceStats['present_count'] / $attendanceStats['total_records']) * 100;
}

// 3. Get performance grade distribution (GPA system)
$performanceQuery = "SELECT 
    performance_grade,
    COUNT(*) as grade_count
FROM users 
WHERE role = 'cadet' 
AND performance_grade IS NOT NULL
GROUP BY performance_grade
ORDER BY FIELD(performance_grade, 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'F')";

$performanceStmt = $db->prepare($performanceQuery);
$performanceStmt->execute();
$performanceResult = $performanceStmt->get_result();

// Initialize performance stats based on grading system
$performanceStats = [];
foreach ($grading_system as $grade => $data) {
    $performanceStats[$grade] = 0;
}

$totalWithGrades = 0;
while ($row = $performanceResult->fetch_assoc()) {
    if (isset($performanceStats[$row['performance_grade']])) {
        $performanceStats[$row['performance_grade']] = $row['grade_count'];
        $totalWithGrades += $row['grade_count'];
    }
}

// 4. Get training sessions for selected month
$sessionsQuery = "SELECT 
    COUNT(*) as total_sessions,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_sessions,
    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_sessions
FROM training_sessions 
WHERE YEAR(training_date) = ? AND MONTH(training_date) = ?";

$sessionsStmt = $db->prepare($sessionsQuery);
$sessionsStmt->bind_param("ii", $year, $month);
$sessionsStmt->execute();
$sessionsStats = $sessionsStmt->get_result()->fetch_assoc();

// 5. Get cadets with performance data (FILTERED)
$whereClause = "WHERE u.role = 'cadet'";
$params = [];
$types = "";

if ($rank_filter != 'all') {
    $whereClause .= " AND u.rank_level = ?";
    $params[] = $rank_filter;
    $types .= "s";
}

if ($service_filter != 'all') {
    $whereClause .= " AND u.service_type = ?";
    $params[] = $service_filter;
    $types .= "s";
}

// Add search filter
if (!empty($search_query)) {
    $search_term = "%{$search_query}%";
    $whereClause .= " AND (u.name LIKE ? OR u.military_number LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ss";
}

// Calculate GPA for each cadet based on performance score
$cadetsQuery = "SELECT u.*, 
    (SELECT COUNT(*) FROM attendance a WHERE a.user_id = u.user_id AND MONTH(a.date) = ? AND YEAR(a.date) = ?) as total_sessions,
    (SELECT COUNT(*) FROM attendance a WHERE a.user_id = u.user_id AND MONTH(a.date) = ? AND YEAR(a.date) = ? AND a.status IN ('present', 'excused')) as attended_sessions,
    (SELECT ROUND(AVG(CASE WHEN a.status IN ('present', 'excused') THEN 1 ELSE 0 END) * 100, 2) 
     FROM attendance a 
     WHERE a.user_id = u.user_id AND MONTH(a.date) = ? AND YEAR(a.date) = ?) as attendance_percentage
    FROM users u 
    {$whereClause}
    ORDER BY u.name";

$cadetsStmt = $db->prepare($cadetsQuery);

// Bind parameters
if ($types) {
    // Create array of references
    $bindParams = array_merge([$types . 'ssssss'], $params, [$month, $year, $month, $year, $month, $year]);
    
    // Convert all to references
    $refs = [];
    foreach ($bindParams as $key => $value) {
        $refs[$key] = &$bindParams[$key];
    }
    
    call_user_func_array([$cadetsStmt, 'bind_param'], $refs);
} else {
    $cadetsStmt->bind_param("ssssss", $month, $year, $month, $year, $month, $year);
}

$cadetsStmt->execute();
$cadets = $cadetsStmt->get_result();

// Calculate filtered statistics and process each cadet's performance
$filteredCadets = 0;
$filteredAttendance = 0;
$failedCadets = 0; // Cadets with attendance below 40%
$cadetsData = [];

while ($cadet = $cadets->fetch_assoc()) {
    $filteredCadets++;
    
    // Calculate attendance rate
    $attendanceRateIndividual = $cadet['attendance_percentage'] ?? 0;
    $filteredAttendance += $attendanceRateIndividual;
    
    // Calculate total performance score (0-100)
    $total_score = 0;
    $score_count = 0;
    
    if (!empty($cadet['attendance_score']) && $cadet['attendance_score'] > 0) {
        $total_score += $cadet['attendance_score'];
        $score_count++;
    }
    
    if (!empty($cadet['discipline_score']) && $cadet['discipline_score'] > 0) {
        $total_score += $cadet['discipline_score'];
        $score_count++;
    }
    
    if (!empty($cadet['skill_score']) && $cadet['skill_score'] > 0) {
        $total_score += $cadet['skill_score'];
        $score_count++;
    }
    
    // Calculate average score
    $average_score = $score_count > 0 ? ($total_score / $score_count) : 0;
    
    // Determine grade based on average score
    $performance_grade = 'F';
    $nilai_mata = 0.00;
    $tahap_prestasi = 'Gagal';
    
    foreach ($grading_system as $grade => $criteria) {
        if ($average_score >= $criteria['min'] && $average_score <= $criteria['max']) {
            $performance_grade = $grade;
            $nilai_mata = $criteria['nilai_mata'];
            $tahap_prestasi = $criteria['tahap'];
            break;
        }
    }
    
    // Apply grade filter if set
    if ($grade_filter != 'all' && $grade_filter != $performance_grade) {
        continue;
    }
    
    // Count failed cadets (below 40%)
    if ($attendanceRateIndividual < 40) {
        $failedCadets++;
    }
    
    // Store cadet data with calculated performance
    $cadetsData[] = [
        'data' => $cadet,
        'attendance_rate' => $attendanceRateIndividual,
        'average_score' => $average_score,
        'performance_grade' => $performance_grade,
        'nilai_mata' => $nilai_mata,
        'tahap_prestasi' => $tahap_prestasi,
        'gpa' => $nilai_mata
    ];
}

$avgAttendanceFiltered = $filteredCadets > 0 ? $filteredAttendance / $filteredCadets : 0;

// 6. Get training types for filter dropdown
$trainingTypesQuery = "SELECT DISTINCT training_type FROM training_sessions ORDER BY training_type";
$trainingTypesStmt = $db->prepare($trainingTypesQuery);
$trainingTypesStmt->execute();
$trainingTypes = $trainingTypesStmt->get_result();

// 7. Get detailed training sessions (FILTERED by training type)
$trainingWhereClause = "WHERE MONTH(ts.training_date) = ? AND YEAR(ts.training_date) = ?";
$trainingParams = [];
$trainingTypesStr = "";

if ($training_filter != 'all') {
    $trainingWhereClause .= " AND ts.training_type = ?";
    $trainingParams[] = $training_filter;
    $trainingTypesStr .= "s";
}

$detailedSessionsQuery = "SELECT ts.*, u.name as creator_name,
    (SELECT COUNT(*) FROM attendance a WHERE a.session_id = ts.session_id) as attendance_count,
    (SELECT COUNT(*) FROM attendance a WHERE a.session_id = ts.session_id AND a.status = 'present') as present_count
    FROM training_sessions ts
    JOIN users u ON ts.created_by = u.user_id
    {$trainingWhereClause}
    ORDER BY ts.training_date DESC";

$detailedSessionsStmt = $db->prepare($detailedSessionsQuery);

// Bind parameters for training sessions query
if ($trainingTypesStr) {
    $detailedSessionsStmt->bind_param("ii" . $trainingTypesStr, $month, $year, $training_filter);
} else {
    $detailedSessionsStmt->bind_param("ii", $month, $year);
}

$detailedSessionsStmt->execute();
$trainingSessions = $detailedSessionsStmt->get_result();

// Calculate attendance percentages for training sessions
$trainingSessionsData = [];
while ($session = $trainingSessions->fetch_assoc()) {
    $attendance_count = $session['attendance_count'] ?? 0;
    $present_count = $session['present_count'] ?? 0;
    $attendance_percentage = $attendance_count > 0 ? round(($present_count / $attendance_count) * 100, 1) : 0;
    
    $trainingSessionsData[] = [
        'data' => $session,
        'attendance_percentage' => $attendance_percentage
    ];
}

// 8. Calculate overall GPA statistics
$gpaStats = [
    'total_gpa' => 0,
    'highest_gpa' => 0,
    'lowest_gpa' => 4.00,
    'excellent_count' => 0, // A & A-
    'good_count' => 0, // B+, B, B-
    'average_count' => 0, // C+, C, C-
    'poor_count' => 0 // D+, D, F
];

foreach ($cadetsData as $cadet) {
    $gpa = $cadet['gpa'];
    $gpaStats['total_gpa'] += $gpa;
    
    if ($gpa > $gpaStats['highest_gpa']) {
        $gpaStats['highest_gpa'] = $gpa;
    }
    
    if ($gpa < $gpaStats['lowest_gpa']) {
        $gpaStats['lowest_gpa'] = $gpa;
    }
    
    // Count by grade category
    $grade = $cadet['performance_grade'];
    if (in_array($grade, ['A', 'A-'])) {
        $gpaStats['excellent_count']++;
    } elseif (in_array($grade, ['B+', 'B', 'B-'])) {
        $gpaStats['good_count']++;
    } elseif (in_array($grade, ['C+', 'C', 'C-'])) {
        $gpaStats['average_count']++;
    } else {
        $gpaStats['poor_count']++;
    }
}

$gpaStats['average_gpa'] = count($cadetsData) > 0 ? $gpaStats['total_gpa'] / count($cadetsData) : 0;

// 9. Handle cadet performance update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_performance'])) {
    $cadet_id = intval($_POST['cadet_id']);
    $performance_grade = $_POST['performance_grade'];
    $attendance_score = floatval($_POST['attendance_score']);
    $discipline_score = floatval($_POST['discipline_score']);
    $skill_score = floatval($_POST['skill_score']);
    $admin_comments = $_POST['admin_comments'] ?? ''; // Get admin comments
    
    // Debug: Lihat nilai parameter
    error_log("DEBUG - Parameters:");
    error_log("Cadet ID: $cadet_id");
    error_log("Grade: $performance_grade");
    error_log("Attendance: $attendance_score");
    error_log("Discipline: $discipline_score");
    error_log("Skill: $skill_score");
    error_log("Comments: " . substr($admin_comments, 0, 50) . "...");
    
    // PERBAIKI: Gunakan query yang lebih simple dulu
    $updateQuery = "UPDATE users SET 
        performance_grade = ?,
        attendance_score = ?,
        discipline_score = ?,
        skill_score = ?,
        performance_comments = ?
        WHERE user_id = ? AND role = 'cadet'";
    
    $updateStmt = $db->prepare($updateQuery);
    
    if ($updateStmt === false) {
        $_SESSION['error'] = "Failed to prepare statement: " . $db->error;
        header("Location: reports.php?" . http_build_query($_GET));
        exit();
    }
    
    // PERBAIKI: Hanya 6 parameter untuk 6 ?
    $result = $updateStmt->bind_param("sdddss", 
        $performance_grade, 
        $attendance_score, 
        $discipline_score, 
        $skill_score,
        $admin_comments,
        $cadet_id
    );
    
    if ($result === false) {
        $_SESSION['error'] = "Failed to bind parameters: " . $updateStmt->error;
        header("Location: reports.php?" . http_build_query($_GET));
        exit();
    }
    
    if ($updateStmt->execute()) {
        $_SESSION['success'] = "Performance updated successfully!";
        
        // Log activity
        $logQuery = "INSERT INTO activity_logs (user_id, activity_type, description, related_id) 
                    VALUES (?, 'update_performance', 'Admin updated performance for cadet ID: $cadet_id', ?)";
        $logStmt = $db->prepare($logQuery);
        $logStmt->bind_param("ii", $user['user_id'], $cadet_id);
        $logStmt->execute();
        
        // Redirect to avoid form resubmission
        header("Location: reports.php?" . http_build_query($_GET));
        exit();
    } else {
        $_SESSION['error'] = "Failed to update performance! Error: " . $updateStmt->error;
    }
}

// 10. Handle generate PDF report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_pdf_report'])) {
    $cadet_id = intval($_POST['cadet_id']);
    $report_month = $_POST['report_month'];
    $report_type = $_POST['report_type'];
    
    // Get cadet data for PDF
    $cadetQuery = "SELECT * FROM users WHERE user_id = ?";
    $cadetStmt = $db->prepare($cadetQuery);
    $cadetStmt->bind_param("i", $cadet_id);
    $cadetStmt->execute();
    $cadetData = $cadetStmt->get_result()->fetch_assoc();
    
    // Check if DomPDF is available
    $dompdfPath = __DIR__ . '/../vendor/autoload.php';
    $useDompdf = file_exists($dompdfPath);
    
    // Generate PDF content
    $pdf_content = generatePDFReport($cadetData, $report_month, $report_type, $grading_system);
    
    if ($useDompdf) {
        // Use DomPDF to convert HTML to PDF
        require_once $dompdfPath;
        
        try {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->loadHtml($pdf_content);
            $dompdf->render();
            
            // Output the generated PDF
            $filename = "cadet_performance_" . $cadetData['military_number'] . "_" . date('Y-m-d') . ".pdf";
            $dompdf->stream($filename, [
                'Attachment' => true
            ]);
            exit();
        } catch (Exception $e) {
            // Fallback to HTML download if DomPDF fails
            header('Content-Type: text/html');
            header('Content-Disposition: attachment; filename="cadet_performance_' . $cadetData['military_number'] . '_' . date('Y-m-d') . '.html"');
            echo $pdf_content;
            exit();
        }
    } else {
        // Download as HTML (fallback)
        header('Content-Type: text/html');
        header('Content-Disposition: attachment; filename="cadet_performance_' . $cadetData['military_number'] . '_' . date('Y-m-d') . '.html"');
        echo $pdf_content;
        exit();
    }
}

// Function to generate PDF report (simplified version)
function generatePDFReport($cadet, $month, $type, $grading_system) {
    $cadet_name = $cadet['name'] ?? 'Unknown';
    $military_no = $cadet['military_number'] ?? 'N/A';
    $service_type = $cadet['service_type'] ?? 'Unknown';
    $rank_level = $cadet['rank_level'] ?? 'Unknown';
    $admin_comments = $cadet['performance_comments'] ?? '';
    
    // Calculate average score
    $attendance_score = $cadet['attendance_score'] ?? 0;
    $discipline_score = $cadet['discipline_score'] ?? 0;
    $skill_score = $cadet['skill_score'] ?? 0;
    
    $total_score = 0;
    $score_count = 0;
    
    if ($attendance_score > 0) {
        $total_score += $attendance_score;
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
    
    $average_score = $score_count > 0 ? ($total_score / $score_count) : 0;
    
    // Determine grade based on database grade or calculated grade
    $performance_grade_db = $cadet['performance_grade'] ?? '';
    $performance_grade = $performance_grade_db;
    $nilai_mata = 0.00;
    $tahap_prestasi = 'Gagal';
    
    // If no grade in database, calculate it
    if (empty($performance_grade_db)) {
        foreach ($grading_system as $grade => $criteria) {
            if ($average_score >= $criteria['min'] && $average_score <= $criteria['max']) {
                $performance_grade = $grade;
                $nilai_mata = $criteria['nilai_mata'];
                $tahap_prestasi = $criteria['tahap'];
                break;
            }
        }
    } else {
        // Use grade from database
        foreach ($grading_system as $grade => $criteria) {
            if ($performance_grade_db == $grade) {
                $nilai_mata = $criteria['nilai_mata'];
                $tahap_prestasi = $criteria['tahap'];
                break;
            }
        }
    }
    
    // Simple HTML PDF content
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <title>Performance Report - ' . $cadet_name . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
            .info-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .info-table td { padding: 8px; border: 1px solid #ddd; }
            .performance-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
            .performance-table th, .performance-table td { padding: 10px; border: 1px solid #ddd; text-align: center; }
            .grading-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
            .grading-table th, .grading-table td { padding: 8px; border: 1px solid #ddd; text-align: center; }
            .footer { margin-top: 50px; text-align: right; font-size: 12px; color: #666; }
            .grade-highlight { font-size: 24px; font-weight: bold; color: #1a365d; text-align: center; padding: 20px; background: #f7fafc; border-radius: 10px; margin: 20px 0; }
            .comments-section { margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #1a365d; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>CADET PERFORMANCE REPORT</h1>
            <h2>' . htmlspecialchars($cadet_name) . '</h2>
            <h3>' . date('F Y', strtotime($month . '-01')) . '</h3>
        </div>
        
        <table class="info-table">
            <tr>
                <td><strong>Cadet Name:</strong></td>
                <td>' . htmlspecialchars($cadet_name) . '</td>
                <td><strong>Military Number:</strong></td>
                <td>' . htmlspecialchars($military_no) . '</td>
            </tr>
            <tr>
                <td><strong>Service Type:</strong></td>
                <td>' . strtoupper($service_type) . '</td>
                <td><strong>Rank Level:</strong></td>
                <td>' . ucfirst($rank_level) . '</td>
            </tr>
            <tr>
                <td><strong>Report Month:</strong></td>
                <td>' . date('F Y', strtotime($month . '-01')) . '</td>
                <td><strong>Report Type:</strong></td>
                <td>' . ucfirst($type) . ' Report</td>
            </tr>
        </table>
        
        <div class="grade-highlight">
            FINAL GRADE: ' . $performance_grade . ' | GPA: ' . number_format($nilai_mata, 2) . '
        </div>
        
        <h3>Performance Summary</h3>
        <table class="performance-table">
            <thead>
                <tr style="background: #1a365d; color: white;">
                    <th>Attendance Score</th>
                    <th>Discipline Score</th>
                    <th>Skill Score</th>
                    <th>Average Score</th>
                    <th>Final Grade</th>
                    <th>Grade Point (GPA)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>' . number_format($attendance_score, 2) . '%</td>
                    <td>' . number_format($discipline_score, 2) . '%</td>
                    <td>' . number_format($skill_score, 2) . '%</td>
                    <td><strong>' . number_format($average_score, 2) . '%</strong></td>
                    <td><strong style="font-size: 18px;">' . $performance_grade . '</strong></td>
                    <td><strong>' . number_format($nilai_mata, 2) . '</strong></td>
                </tr>
            </tbody>
        </table>';
    
    // Add admin comments section if available
    if (!empty($admin_comments)) {
        $html .= '
        <div class="comments-section">
            <h3 style="color: #1a365d; margin-bottom: 15px;">Admin Comments & Recommendations</h3>
            <div style="white-space: pre-wrap; line-height: 1.6; padding: 10px; background: white; border-radius: 5px;">
                ' . htmlspecialchars($admin_comments) . '
            </div>
        </div>';
    }
    
    $html .= '
        <h3>Grading System Reference</h3>
        <table class="grading-table">
            <thead>
                <tr style="background: #2d3748; color: white;">
                    <th>Score (%)</th>
                    <th>Grade</th>
                    <th>Grade Point (GPA)</th>
                    <th>Performance Level</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($grading_system as $grade => $criteria) {
        $html .= '<tr>
                    <td>' . $criteria['min'] . ' - ' . $criteria['max'] . '</td>
                    <td><strong>' . $grade . '</strong></td>
                    <td>' . $criteria['nilai_mata'] . '</td>
                    <td>' . $criteria['tahap'] . '</td>
                </tr>';
    }
    
    $html .= '</tbody>
        </table>
        
        <div class="footer">
            <p>Generated on: ' . date('d/m/Y H:i:s') . '</p>
            <p>CAAMS - Cadet Attendance & Allowance Management System</p>
            <p>This is an official performance report document</p>
        </div>
    </body>
    </html>';
    
    return $html;
}

// Check if DomPDF is available
$dompdfPath = __DIR__ . '/../vendor/autoload.php';
$dompdf_available = file_exists($dompdfPath);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Reports - CAAMS</title>
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
            --army-green: #276749;
            --navy-blue: #2c5282;
            --airforce-blue: #2b6cb0;
            --gpa-excellent: #10b981;
            --gpa-good: #3b82f6;
            --gpa-average: #f59e0b;
            --gpa-poor: #ef4444;
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
        
        /* STATS CARDS */
        .stats-section {
            padding: 30px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
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
            transition: transform 0.3s;
            border-left: 5px solid var(--accent);
            text-align: center;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.total { border-left-color: var(--primary); }
        .stat-card.attendance { border-left-color: var(--info); }
        .stat-card.performance { border-left-color: var(--success); }
        .stat-card.failed { border-left-color: var(--danger); }
        .stat-card.army { border-left-color: var(--army-green); }
        .stat-card.navy { border-left-color: var(--navy-blue); }
        .stat-card.airforce { border-left-color: var(--airforce-blue); }
        .stat-card.gpa { border-left-color: var(--gpa-excellent); }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            opacity: 0.8;
        }
        
        .stat-value {
            font-size: 2.2rem;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #718096;
            font-size: 0.9rem;
        }
        
        /* GRADING SYSTEM TABLE */
        .grading-section {
            padding: 30px;
            background: #f7fafc;
            border-radius: 15px;
            margin: 0 30px 30px 30px;
        }
        
        .grading-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .grading-table th {
            background: var(--primary);
            color: white;
            padding: 15px;
            text-align: center;
            font-weight: 600;
        }
        
        .grading-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            text-align: center;
        }
        
        .grading-table tr:hover {
            background: #f7fafc;
        }
        
        .grade-header {
            font-weight: bold;
            color: var(--primary);
        }
        
        /* PERFORMANCE CHART */
        .chart-section {
            padding: 30px;
            background: #f7fafc;
            border-radius: 15px;
            margin: 0 30px 30px 30px;
        }
        
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .chart-bars {
            display: flex;
            align-items: flex-end;
            height: 200px;
            gap: 10px;
            padding: 20px 0;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .chart-bar {
            flex: 1;
            background: var(--accent);
            border-radius: 8px 8px 0 0;
            position: relative;
            transition: all 0.3s;
            min-height: 10px;
        }
        
        .chart-bar:hover {
            opacity: 0.9;
            transform: scale(1.05);
        }
        
        .chart-bar-label {
            position: absolute;
            bottom: -30px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 0.85rem;
            color: var(--secondary);
        }
        
        .chart-bar-value {
            position: absolute;
            top: -25px;
            left: 0;
            right: 0;
            text-align: center;
            font-weight: bold;
            color: var(--primary);
        }
        
        .grade-color-a { background: #10b981; }
        .grade-color-a- { background: #34d399; }
        .grade-color-b+ { background: #60a5fa; }
        .grade-color-b { background: #3b82f6; }
        .grade-color-b- { background: #6366f1; }
        .grade-color-c+ { background: #f59e0b; }
        .grade-color-c { background: #fbbf24; }
        .grade-color-c- { background: #f97316; }
        .grade-color-d+ { background: #ef4444; }
        .grade-color-d { background: #e53e3e; }
        .grade-color-f { background: #c53030; }
        
        /* REPORTS TABLES */
        .reports-section {
            padding: 0 30px 30px 30px;
        }
        
        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-top: 20px;
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
        
        .reports-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .reports-table th {
            background: #edf2f7;
            color: var(--secondary);
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .reports-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .reports-table tr:hover {
            background: #f7fafc;
        }
        
        .attendance-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            min-width: 70px;
            text-align: center;
        }
        
        .attendance-excellent { background: #d4edda; color: #155724; }
        .attendance-good { background: #d1ecf1; color: #0c5460; }
        .attendance-average { background: #fff3cd; color: #856404; }
        .attendance-poor { background: #f8d7da; color: #721c24; }
        .attendance-failed { background: #721c24; color: white; }
        
        .grade-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: bold;
            min-width: 40px;
            text-align: center;
        }
        
        .grade-a { background: #10b981; color: white; }
        .grade-a- { background: #34d399; color: white; }
        .grade-b+ { background: #60a5fa; color: white; }
        .grade-b { background: #3b82f6; color: white; }
        .grade-b- { background: #6366f1; color: white; }
        .grade-c+ { background: #f59e0b; color: white; }
        .grade-c { background: #fbbf24; color: white; }
        .grade-c- { background: #f97316; color: white; }
        .grade-d+ { background: #ef4444; color: white; }
        .grade-d { background: #e53e3e; color: white; }
        .grade-f { background: #c53030; color: white; }
        
        /* SERVICE BADGES */
        .service-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .badge-army { background: #c6f6d5; color: var(--army-green); }
        .badge-navy { background: #bee3f8; color: var(--navy-blue); }
        .badge-airforce { background: #e9d8fd; color: var(--airforce-blue); }
        
        /* GPA BADGES */
        .gpa-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 0.85rem;
            font-weight: bold;
            min-width: 50px;
            text-align: center;
        }
        
        .gpa-excellent { background: #d4edda; color: #155724; }
        .gpa-good { background: #d1ecf1; color: #0c5460; }
        .gpa-average { background: #fff3cd; color: #856404; }
        .gpa-poor { background: #f8d7da; color: #721c24; }
        
        /* UPDATE PERFORMANCE MODAL */
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
        }
        
        .modal {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
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
        
        .form-group-modal {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--secondary);
        }
        
        /* ACTION BUTTONS */
        .action-btns {
            display: flex;
            gap: 5px;
        }
        
        .btn-small {
            padding: 6px 12px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-edit {
            background: var(--warning);
            color: white;
        }
        
        .btn-view {
            background: var(--info);
            color: white;
        }
        
        .btn-report {
            background: var(--gpa-excellent);
            color: white;
        }
        
        .btn-comments {
            background: var(--accent);
            color: white;
        }
        
        /* FILTER SECTION */
        .filter-section {
            background: #f7fafc;
            padding: 20px 30px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
            font-size: 0.9rem;
        }
        
        input, select, button, textarea {
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            min-width: 150px;
        }
        
        textarea {
            width: 100%;
            resize: vertical;
            min-height: 80px;
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        .search-input {
            flex: 1;
            min-width: 250px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: 42px;
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
            background: #e2e8f0;
            color: var(--secondary);
        }
        
        .btn-secondary:hover {
            background: #cbd5e0;
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
            .reports-table {
                display: block;
                overflow-x: auto;
            }
            
            .filter-form {
                flex-direction: column;
            }
            
            input, select, button {
                width: 100%;
                min-width: auto;
            }
            
            .action-btns {
                flex-direction: column;
            }
        }
        
        /* ATTENDANCE PROGRESS BAR */
        .attendance-progress {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 150px;
        }
        
        .progress-bar {
            flex: 1;
            background: #e2e8f0;
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .progress-excellent { background: #10b981; }
        .progress-good { background: #3b82f6; }
        .progress-average { background: #f59e0b; }
        .progress-poor { background: #f97316; }
        .progress-failed { background: #ef4444; }
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
                <i class="fas fa-chart-bar"></i> Performance Reports
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">Real-time cadet performance reports with comprehensive filtering</p>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div style="background: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin-top: 10px;">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px; margin-top: 10px;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!$dompdf_available): ?>
                <div style="background: #fff3cd; color: #856404; padding: 10px; border-radius: 5px; margin-top: 10px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-info-circle"></i>
                    <span>For PDF export, install DomPDF: <code>composer require dompdf/dompdf</code></span>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- REAL-TIME STATS SECTION -->
        <div class="stats-section">
            <div class="stat-card total">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $overallStats['total_cadets'] ?? 0; ?></div>
                <div class="stat-label">Total Cadets</div>
            </div>
            
            <div class="stat-card attendance">
                <div class="stat-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-value"><?php echo round($attendanceRate, 1); ?>%</div>
                <div class="stat-label">Overall Attendance</div>
            </div>
            
            <div class="stat-card performance">
                <div class="stat-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="stat-value"><?php echo number_format($gpaStats['average_gpa'], 2); ?></div>
                <div class="stat-label">Average GPA</div>
            </div>
            
            <div class="stat-card failed">
                <div class="stat-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-value"><?php echo $failedCadets; ?></div>
                <div class="stat-label">Failed (<40%)</div>
            </div>
        </div>
        
        <!-- SERVICE BREAKDOWN -->
        <div class="stats-section">
            <div class="stat-card army">
                <div class="stat-icon">
                    <i class="fas fa-truck"></i>
                </div>
                <div class="stat-value"><?php echo $overallStats['army_count'] ?? 0; ?></div>
                <div class="stat-label">Army Cadets</div>
            </div>
            
            <div class="stat-card navy">
                <div class="stat-icon">
                    <i class="fas fa-ship"></i>
                </div>
                <div class="stat-value"><?php echo $overallStats['navy_count'] ?? 0; ?></div>
                <div class="stat-label">Navy Cadets</div>
            </div>
            
            <div class="stat-card airforce">
                <div class="stat-icon">
                    <i class="fas fa-fighter-jet"></i>
                </div>
                <div class="stat-value"><?php echo $overallStats['airforce_count'] ?? 0; ?></div>
                <div class="stat-label">Air Force Cadets</div>
            </div>
            
            <div class="stat-card gpa">
                <div class="stat-icon">
                    <i class="fas fa-star"></i>
                </div>
                <div class="stat-value"><?php echo $gpaStats['excellent_count']; ?></div>
                <div class="stat-label">Excellent (A/A-)</div>
            </div>
        </div>
        
        
        <!-- PERFORMANCE CHART -->
        <div class="chart-section">
            <h2 style="color: var(--primary); margin-bottom: 20px;">
                <i class="fas fa-chart-pie"></i> Performance Grade Distribution
            </h2>
            
            <div class="chart-container">
                <div class="chart-bars">
                    <?php 
                    $maxValue = max($performanceStats);
                    foreach ($performanceStats as $grade => $count): 
                        if ($maxValue > 0) {
                            $height = ($count / $maxValue) * 150;
                        } else {
                            $height = 10;
                        }
                        $gradeClass = str_replace(['+', '-'], ['plus', '-'], strtolower($grade));
                    ?>
                        <div class="chart-bar grade-color-<?php echo $gradeClass; ?>" 
                             style="height: <?php echo $height; ?>px;"
                             title="<?php echo $grade; ?>: <?php echo $count; ?> cadets">
                            <div class="chart-bar-value"><?php echo $count; ?></div>
                            <div class="chart-bar-label"><?php echo $grade; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <!-- GRADING SYSTEM TABLE -->
        <div class="grading-section">
            <h2 style="color: var(--primary); margin-bottom: 20px;">
                <i class="fas fa-list-alt"></i> Grading System (GPA)
            </h2>
            
            <table class="grading-table">
                <thead>
                    <tr>
                        <th>Marks (%)</th>
                        <th>Grade</th>
                        <th>Grade Point (GPA)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grading_system as $grade => $data): ?>
                    <tr>
                        <td><?php echo $data['min']; ?> - <?php echo $data['max']; ?></td>
                        <td class="grade-header"><?php echo $grade; ?></td>
                        <td><strong><?php echo $data['nilai_mata']; ?></strong></td>
                       
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- CADET PERFORMANCE REPORTS -->
        <div class="reports-section">
            <div class="table-container">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-user-graduate"></i> Cadet Performance Report
                        <span style="font-size: 0.9rem; color: rgba(255,255,255,0.8); margin-left: 10px;">
                            (<?php echo count($cadetsData); ?> cadets found)
                        </span>
                    </h3>
                    <button class="export-btn" onclick="exportTableToCSV('cadet-report')">
                        <i class="fas fa-file-export"></i> Export CSV
                    </button>
                </div>
                
                <!-- CADET FILTER SECTION -->
                <div class="filter-section">
                    <form method="GET" action="" class="filter-form">
                        <input type="hidden" name="month" value="<?php echo $month_filter; ?>">
                        <input type="hidden" name="training" value="<?php echo $training_filter; ?>">
                        
                        <div class="form-group">
                            <label for="rank">Rank Level</label>
                            <select id="rank" name="rank">
                                <option value="all">All Ranks</option>
                                <option value="junior" <?php echo $rank_filter == 'junior' ? 'selected' : ''; ?>>Junior</option>
                                <option value="intermediate" <?php echo $rank_filter == 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                                <option value="senior" <?php echo $rank_filter == 'senior' ? 'selected' : ''; ?>>Senior</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="service">Service Type</label>
                            <select id="service" name="service">
                                <option value="all">All Services</option>
                                <option value="darat" <?php echo $service_filter == 'darat' ? 'selected' : ''; ?>>Army</option>
                                <option value="laut" <?php echo $service_filter == 'laut' ? 'selected' : ''; ?>>Navy</option>
                                <option value="udara" <?php echo $service_filter == 'udara' ? 'selected' : ''; ?>>Air Force</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="grade">Grade</label>
                            <select id="grade" name="grade">
                                <option value="all">All Grades</option>
                                <?php foreach ($grading_system as $grade => $data): ?>
                                    <option value="<?php echo $grade; ?>" <?php echo $grade_filter == $grade ? 'selected' : ''; ?>>
                                        <?php echo $grade; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group" style="flex: 2;">
                            <label for="search">Search (Name or Military No)</label>
                            <input type="text" 
                                   id="search" 
                                   name="search" 
                                   class="search-input"
                                   value="<?php echo htmlspecialchars($search_query); ?>"
                                   placeholder="Enter name or military number...">
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            <a href="reports.php?month=<?php echo $month_filter; ?>&training=<?php echo $training_filter; ?>" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
                
                <table class="reports-table" id="cadet-report">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Military No</th>
                            <th>Name</th>
                            <th>Service</th>
                            <th>Rank</th>
                            <th>Attendance</th>
                            <th>Avg Score</th>
                            <th>Grade</th>
                            <th>GPA</th>
                            <th>Comments</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 1;
                        foreach ($cadetsData as $cadetInfo): 
                            $cadet = $cadetInfo['data'];
                            $attendanceRate = $cadetInfo['attendance_rate'];
                            $average_score = $cadetInfo['average_score'];
                            $performance_grade = $cadetInfo['performance_grade'];
                            $nilai_mata = $cadetInfo['nilai_mata'];
                            $admin_comments = $cadet['performance_comments'] ?? '';
                            
                            // Determine attendance class
                            $attendanceClass = '';
                            $progressClass = '';
                            if ($attendanceRate >= 90) {
                                $attendanceClass = 'attendance-excellent';
                                $progressClass = 'progress-excellent';
                            } elseif ($attendanceRate >= 80) {
                                $attendanceClass = 'attendance-good';
                                $progressClass = 'progress-good';
                            } elseif ($attendanceRate >= 70) {
                                $attendanceClass = 'attendance-average';
                                $progressClass = 'progress-average';
                            } elseif ($attendanceRate >= 40) {
                                $attendanceClass = 'attendance-poor';
                                $progressClass = 'progress-poor';
                            } else {
                                $attendanceClass = 'attendance-failed';
                                $progressClass = 'progress-failed';
                            }
                            
                            // Determine GPA class
                            $gpaClass = '';
                            if ($nilai_mata >= 3.67) $gpaClass = 'gpa-excellent';
                            elseif ($nilai_mata >= 3.00) $gpaClass = 'gpa-good';
                            elseif ($nilai_mata >= 2.00) $gpaClass = 'gpa-average';
                            else $gpaClass = 'gpa-poor';
                            
                            // Service badge class
                            $serviceClass = '';
                            if ($cadet['service_type'] == 'darat') $serviceClass = 'badge-army';
                            elseif ($cadet['service_type'] == 'laut') $serviceClass = 'badge-navy';
                            elseif ($cadet['service_type'] == 'udara') $serviceClass = 'badge-airforce';
                            
                            // Grade badge class
                            $gradeClass = str_replace(['+', '-'], ['plus', '-'], strtolower($performance_grade));
                        ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td><strong><?php echo htmlspecialchars($cadet['military_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($cadet['name']); ?></td>
                                <td>
                                    <span class="service-badge <?php echo $serviceClass; ?>">
                                        <?php 
                                        $serviceText = '';
                                        switch($cadet['service_type']) {
                                            case 'darat': $serviceText = 'Army'; break;
                                            case 'laut': $serviceText = 'Navy'; break;
                                            case 'udara': $serviceText = 'Air Force'; break;
                                            default: $serviceText = ucfirst($cadet['service_type']);
                                        }
                                        echo $serviceText;
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-weight: 600; color: var(--primary);">
                                        <?php echo ucfirst($cadet['rank_level']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="attendance-progress">
                                        <div class="progress-bar">
                                            <div class="progress-fill <?php echo $progressClass; ?>" style="width: <?php echo min($attendanceRate, 100); ?>%"></div>
                                        </div>
                                        <span style="font-weight: 600; min-width: 40px; text-align: right;">
                                            <?php echo round($attendanceRate, 1); ?>%
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo round($average_score, 2); ?></strong>
                                </td>
                                <td>
                                    <span class="grade-badge grade-<?php echo $gradeClass; ?>">
                                        <?php echo $performance_grade; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="gpa-badge <?php echo $gpaClass; ?>">
                                        <?php echo number_format($nilai_mata, 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($admin_comments)): ?>
                                        <div style="position: relative;">
                                            <button class="btn-small btn-comments" onclick="showComments('<?php echo htmlspecialchars(addslashes($cadet['name'])); ?>', '<?php echo htmlspecialchars(addslashes($admin_comments)); ?>')">
                                                <i class="fas fa-comment"></i> View
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <small style="color: #718096;">No comments</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <button class="btn-small btn-edit" onclick="openPerformanceModal(
                                            <?php echo $cadet['user_id']; ?>,
                                            '<?php echo htmlspecialchars(addslashes($cadet['name'])); ?>',
                                            '<?php echo htmlspecialchars(addslashes($cadet['performance_grade'] ?? '')); ?>',
                                            <?php echo $cadet['attendance_score'] ?? 0; ?>,
                                            <?php echo $cadet['discipline_score'] ?? 0; ?>,
                                            <?php echo $cadet['skill_score'] ?? 0; ?>,
                                            '<?php echo htmlspecialchars(addslashes($admin_comments)); ?>'
                                        )">
                                            <i class="fas fa-edit"></i> Update
                                        </button>
                                        <button class="btn-small btn-report" onclick="generatePDFReport(
                                            <?php echo $cadet['user_id']; ?>,
                                            '<?php echo htmlspecialchars(addslashes($cadet['name'])); ?>',
                                            '<?php echo $month_filter; ?>'
                                        )">
                                            <i class="fas fa-file-pdf"></i> PDF Report
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($cadetsData)): ?>
                            <tr>
                                <td colspan="11" class="empty-state">
                                    <i class="fas fa-user-slash"></i>
                                    <p>No cadet data found with the current filter</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        
        
        <!-- TRAINING SESSIONS REPORT -->
        <div class="reports-section">
            <div class="table-container">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-calendar-alt"></i> Training Sessions Report - <?php echo date('F Y', strtotime($month_filter)); ?>
                    </h3>
                    <button class="export-btn" onclick="exportTableToCSV('session-report')">
                        <i class="fas fa-file-export"></i> Export CSV
                    </button>
                </div>
              
                <!-- TRAINING FILTER SECTION -->
                <div class="filter-section">
                    <form method="GET" action="" class="filter-form">
                        <input type="hidden" name="rank" value="<?php echo $rank_filter; ?>">
                        <input type="hidden" name="service" value="<?php echo $service_filter; ?>">
                        <input type="hidden" name="grade" value="<?php echo $grade_filter; ?>">
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
                        
                        <div class="form-group">
                            <label for="month">Month</label>
                            <input type="month" 
                                   id="month" 
                                   name="month" 
                                   value="<?php echo $month_filter; ?>"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="training">Training Type</label>
                            <select id="training" name="training">
                                <option value="all">All Training Types</option>
                                <?php 
                                $trainingTypes->data_seek(0); // Reset pointer
                                while($trainingType = $trainingTypes->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($trainingType['training_type']); ?>" 
                                        <?php echo $training_filter == $trainingType['training_type'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($trainingType['training_type']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            <a href="reports.php?rank=<?php echo $rank_filter; ?>&service=<?php echo $service_filter; ?>&grade=<?php echo $grade_filter; ?>&search=<?php echo urlencode($search_query); ?>" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
                
                <table class="reports-table" id="session-report">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Training Type</th>
                            <th>Location</th>
                            <th>Session Time</th>
                            <th>Created By</th>
                            <th>Attendance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sessionCounter = 1;
                        foreach ($trainingSessionsData as $sessionInfo): 
                            $session = $sessionInfo['data'];
                            $attendancePercent = $sessionInfo['attendance_percentage'];
                        ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($session['training_date'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($session['training_type']); ?></strong></td>
                                <td><?php echo htmlspecialchars($session['location']); ?></td>
                                <td>
                                    <?php 
                                    $sessionTime = '';
                                    switch($session['session_time']) {
                                        case 'pagi': $sessionTime = 'Morning'; break;
                                        case 'tengah hari': $sessionTime = 'Midday'; break;
                                        case 'petang': $sessionTime = 'Evening'; break;
                                        case 'malam': $sessionTime = 'Night'; break;
                                        default: $sessionTime = ucfirst($session['session_time']);
                                    }
                                    echo $sessionTime;
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($session['creator_name']); ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="flex: 1; background: #e2e8f0; height: 8px; border-radius: 4px;">
                                            <div style="width: <?php echo min($attendancePercent, 100); ?>%; 
                                                       background: var(--accent); 
                                                       height: 100%; 
                                                       border-radius: 4px;"></div>
                                        </div>
                                        <span style="font-weight: 600;"><?php echo $attendancePercent; ?>%</span>
                                    </div>
                                    <small style="color: #718096; font-size: 0.8rem;">
                                        <?php echo $session['present_count']; ?>/<?php echo $session['attendance_count']; ?> present
                                    </small>
                                </td>
                                <td>
                                    <?php if ($session['is_active'] == 1): ?>
                                        <span class="attendance-badge attendance-excellent">ACTIVE</span>
                                    <?php else: ?>
                                        <span class="attendance-badge attendance-poor">INACTIVE</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php 
                            $sessionCounter++;
                        endforeach; 
                        ?>
                        
                        <?php if ($sessionCounter == 1): ?>
                            <tr>
                                <td colspan="7" class="empty-state">
                                    <i class="fas fa-calendar-times"></i>
                                    <p>No training sessions for this month</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- UPDATE PERFORMANCE MODAL -->
    <div class="modal-overlay" id="performanceModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Update Performance</h3>
                <button class="modal-close" onclick="closeModal('performanceModal')">&times;</button>
            </div>
            <form method="POST" id="updatePerformanceForm">
                <div class="modal-body">
                    <div id="cadetInfo" style="margin-bottom: 20px; padding: 15px; background: #f7fafc; border-radius: 8px;">
                        <!-- Cadet info will be inserted here -->
                    </div>
                    
                    <div class="form-group-modal">
                        <label class="form-label">Performance Grade *</label>
                        <select name="performance_grade" class="filter-select" required>
                            <option value="">Select Grade</option>
                            <?php foreach ($grading_system as $grade => $data): ?>
                                <option value="<?php echo $grade; ?>">
                                    <?php echo $grade; ?> (<?php echo $data['min']; ?>-<?php echo $data['max']; ?>%) - <?php echo $data['tahap']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group-modal">
                        <label class="form-label">Attendance Score (0-100)</label>
                        <input type="number" name="attendance_score" min="0" max="100" step="0.01" class="filter-input" required>
                    </div>
                    
                    <div class="form-group-modal">
                        <label class="form-label">Discipline Score (0-100)</label>
                        <input type="number" name="discipline_score" min="0" max="100" step="0.01" class="filter-input" required>
                    </div>
                    
                    <div class="form-group-modal">
                        <label class="form-label">Skill Score (0-100)</label>
                        <input type="number" name="skill_score" min="0" max="100" step="0.01" class="filter-input" required>
                    </div>
                    
                    <div class="form-group-modal">
                        <label class="form-label">Admin Comments / Notes</label>
                        <textarea name="admin_comments" rows="3" class="filter-input" placeholder="Enter comments, recommendations, or feedback for the cadet..."></textarea>
                        <small style="color: #718096;">These comments will be included in the PDF report</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="cadet_id" id="cadetId">
                    <input type="hidden" name="update_performance" value="1">
                    <button type="button" class="btn" onclick="closeModal('performanceModal')" style="background: #e2e8f0; color: var(--secondary);">Cancel</button>
                    <button type="submit" class="btn btn-success">Update Performance</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- GENERATE PDF REPORT MODAL -->
    <div class="modal-overlay" id="pdfReportModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-file-pdf"></i> Generate PDF Performance Report</h3>
                <button class="modal-close" onclick="closeModal('pdfReportModal')">&times;</button>
            </div>
            <form method="POST" id="generatePDFReportForm" target="_blank">
                <div class="modal-body">
                    <div id="cadetPDFInfo" style="margin-bottom: 20px; padding: 15px; background: #f7fafc; border-radius: 8px;">
                        <!-- Cadet info for PDF report will be inserted here -->
                    </div>
                    
                    <div class="form-group-modal">
                        <label class="form-label">Select Month</label>
                        <input type="month" name="report_month" value="<?php echo $month_filter; ?>" class="filter-input" required>
                    </div>
                    
                    <div class="form-group-modal">
                        <label class="form-label">Report Type</label>
                        <select name="report_type" class="filter-select" required>
                            <option value="monthly">Monthly Performance Report</option>
                            <option value="summary">Performance Summary</option>
                            <option value="detailed">Detailed Report</option>
                        </select>
                    </div>
                    
                    <div class="form-group-modal">
                        <label class="form-label">Include Details</label>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="include_attendance" checked> Attendance Details
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="include_scores" checked> Performance Scores
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="include_gpa" checked> GPA Calculation
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="include_comments" checked> Admin Comments
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="cadet_id" id="pdfCadetId">
                    <input type="hidden" name="generate_pdf_report" value="1">
                    <button type="button" class="btn" onclick="closeModal('pdfReportModal')" style="background: #e2e8f0; color: var(--secondary);">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-download"></i> Generate & Download <?php echo $dompdf_available ? 'PDF' : 'HTML'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- VIEW COMMENTS MODAL -->
    <div class="modal-overlay" id="commentsModal">
        <div class="modal" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fas fa-comments"></i> Admin Comments</h3>
                <button class="modal-close" onclick="closeModal('commentsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="commentsCadetName" style="margin-bottom: 15px; padding: 10px; background: #f7fafc; border-radius: 8px;">
                    <!-- Cadet name will be inserted here -->
                </div>
                <div id="commentsContent" style="padding: 15px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; min-height: 150px; max-height: 300px; overflow-y: auto;">
                    <!-- Comments will be displayed here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('commentsModal')" style="background: #e2e8f0; color: var(--secondary);">Close</button>
            </div>
        </div>
    </div>
    
    <script>
        // Export table to CSV
        function exportTableToCSV(tableId) {
            const table = document.getElementById(tableId);
            const rows = table.querySelectorAll('tr');
            const csv = [];
            
            for (let i = 0; i < rows.length; i++) {
                const row = [], cols = rows[i].querySelectorAll('td, th');
                
                for (let j = 0; j < cols.length; j++) {
                    // Clean data: remove HTML tags and extra spaces
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/(\s\s+)/gm, ' ');
                    data = data.replace(/"/g, '""'); // Escape double quotes
                    row.push('"' + data + '"');
                }
                
                csv.push(row.join(','));
            }
            
            // Download CSV file
            const csvString = csv.join('\n');
            const filename = tableId + '_<?php echo date("Y-m-d"); ?>.csv';
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
        
        // Show toast notification
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                background: ${type === 'success' ? '#48bb78' : '#f56565'};
                color: white;
                border-radius: 8px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
                z-index: 1000;
                animation: slideInRight 0.3s ease-out;
            `;
            
            toast.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i>
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
        
        // Open performance update modal
        function openPerformanceModal(cadetId, cadetName, currentGrade, attendanceScore, disciplineScore, skillScore, adminComments) {
            const modal = document.getElementById('performanceModal');
            const cadetInfo = document.getElementById('cadetInfo');
            const form = document.getElementById('updatePerformanceForm');
            
            // Set cadet info
            cadetInfo.innerHTML = `
                <strong>${cadetName}</strong><br>
                <small style="color: #718096;">Update performance scores and grade</small>
            `;
            
            // Set cadet ID
            document.getElementById('cadetId').value = cadetId;
            
            // Set current values in form
            if (currentGrade) {
                form.querySelector('select[name="performance_grade"]').value = currentGrade;
            }
            
            form.querySelector('input[name="attendance_score"]').value = attendanceScore || 0;
            form.querySelector('input[name="discipline_score"]').value = disciplineScore || 0;
            form.querySelector('input[name="skill_score"]').value = skillScore || 0;
            
            // Set admin comments
            if (adminComments) {
                form.querySelector('textarea[name="admin_comments"]').value = adminComments;
            }
            
            modal.style.display = 'flex';
        }
        
        // Open generate PDF report modal
        function generatePDFReport(cadetId, cadetName, currentMonth) {
            const modal = document.getElementById('pdfReportModal');
            const cadetInfo = document.getElementById('cadetPDFInfo');
            const form = document.getElementById('generatePDFReportForm');
            
            // Set cadet info
            cadetInfo.innerHTML = `
                <strong>${cadetName}</strong><br>
                <small style="color: #718096;">Generate performance report for this cadet</small>
            `;
            
            // Set cadet ID and current month
            document.getElementById('pdfCadetId').value = cadetId;
            form.querySelector('input[name="report_month"]').value = currentMonth;
            
            modal.style.display = 'flex';
        }
        
        // Show admin comments
        function showComments(cadetName, comments) {
            const modal = document.getElementById('commentsModal');
            const cadetNameElement = document.getElementById('commentsCadetName');
            const content = document.getElementById('commentsContent');
            
            // Set cadet name
            cadetNameElement.innerHTML = `
                <strong><i class="fas fa-user"></i> ${cadetName}</strong><br>
                <small style="color: #718096;">Admin Comments</small>
            `;
            
            // Format comments (preserve line breaks)
            const formattedComments = comments.replace(/\n/g, '<br>');
            
            // Set comments content
            content.innerHTML = `
                <div style="white-space: pre-wrap; font-size: 14px; line-height: 1.6;">
                    ${formattedComments}
                </div>
                <div style="margin-top: 15px; font-size: 12px; color: #718096;">
                    <i class="fas fa-user-shield"></i> Comments by Administrator
                </div>
            `;
            
            modal.style.display = 'flex';
        }
        
        // Close modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
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
            @keyframes slideUp {
                from { transform: translateY(30px); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);
        
        // Auto-refresh page every 60 seconds for real-time updates
        setInterval(() => {
            console.log('Refreshing reports...');
            // Only refresh if user is not interacting with the page
            if (!document.hasFocus()) {
                location.reload();
            }
        }, 60000);
        
        // Chart bar hover effects
        document.addEventListener('DOMContentLoaded', function() {
            const chartBars = document.querySelectorAll('.chart-bar');
            chartBars.forEach(bar => {
                bar.addEventListener('mouseenter', function() {
                    this.style.opacity = '0.9';
                    this.style.transform = 'scale(1.05)';
                });
                
                bar.addEventListener('mouseleave', function() {
                    this.style.opacity = '1';
                    this.style.transform = 'scale(1)';
                });
            });
        });
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.style.display = 'none';
            }
        }
        
        // Handle form submission for performance update
        document.getElementById('updatePerformanceForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Validate scores are between 0-100
            const attendanceScore = parseFloat(this.querySelector('input[name="attendance_score"]').value);
            const disciplineScore = parseFloat(this.querySelector('input[name="discipline_score"]').value);
            const skillScore = parseFloat(this.querySelector('input[name="skill_score"]').value);
            
            if (attendanceScore < 0 || attendanceScore > 100 ||
                disciplineScore < 0 || disciplineScore > 100 ||
                skillScore < 0 || skillScore > 100) {
                alert('All scores must be between 0 and 100!');
                return false;
            }
            
            // Submit the form
            this.submit();
        });
    </script>
</body>
</html>
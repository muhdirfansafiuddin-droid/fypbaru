<?php
// admin/reports.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('admin');
$user = (new Auth())->getCurrentUser();
$db = new Database();

// Default filter values
$month_filter = $_GET['month'] ?? date('Y-m');
$rank_filter = $_GET['rank'] ?? 'all';
$service_filter = $_GET['service'] ?? 'all';

// Get all cadets with performance data
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

$sql = "SELECT u.*, 
        (SELECT COUNT(*) FROM attendance a WHERE a.user_id = u.user_id AND MONTH(a.date) = MONTH(?) AND YEAR(a.date) = YEAR(?)) as total_sessions,
        (SELECT COUNT(*) FROM attendance a WHERE a.user_id = u.user_id AND MONTH(a.date) = MONTH(?) AND YEAR(a.date) = YEAR(?) AND a.status IN ('present', 'excused')) as attended_sessions
        FROM users u 
        {$whereClause}
        ORDER BY u.name";

$stmt = $db->prepare($sql);

// Bind parameters
if ($types) {
    $bindParams = array_merge([$types . 'ssss'], $params, [$month_filter, $month_filter, $month_filter, $month_filter]);
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
} else {
    $stmt->bind_param("ssss", $month_filter, $month_filter, $month_filter, $month_filter);
}

$stmt->execute();
$cadets = $stmt->get_result();

// Calculate statistics
$totalCadets = 0;
$totalAttendance = 0;
$avgAttendance = 0;
$performanceStats = [
    'A+' => 0, 'A' => 0, 'B+' => 0, 'B' => 0, 'B-' => 0,
    'C+' => 0, 'C' => 0, 'C-' => 0, 'D' => 0, 'E' => 0
];

while ($cadet = $cadets->fetch_assoc()) {
    $totalCadets++;
    
    if ($cadet['total_sessions'] > 0) {
        $attendanceRate = ($cadet['attended_sessions'] / $cadet['total_sessions']) * 100;
        $totalAttendance += $attendanceRate;
    }
    
    if ($cadet['performance_grade']) {
        $performanceStats[$cadet['performance_grade']]++;
    }
}

if ($totalCadets > 0) {
    $avgAttendance = $totalAttendance / $totalCadets;
}


// Get recent training sessions
$sessionsSql = "SELECT ts.*, u.name as creator_name,
                (SELECT COUNT(*) FROM attendance a WHERE a.session_id = ts.session_id) as attendance_count,
                (SELECT COUNT(*) FROM attendance a WHERE a.session_id = ts.session_id AND a.status = 'present') as present_count
                FROM training_sessions ts
                JOIN users u ON ts.created_by = u.user_id
                WHERE MONTH(ts.training_date) = MONTH(?) AND YEAR(ts.training_date) = YEAR(?)
                ORDER BY ts.training_date DESC";
$sessionsStmt = $db->prepare($sessionsSql);
$sessionsStmt->bind_param("ss", $month_filter, $month_filter);
$sessionsStmt->execute();
$trainingSessions = $sessionsStmt->get_result();
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
        
        /* FILTER SECTION */
        .filter-section {
            background: #f7fafc;
            padding: 20px 30px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(3, 1fr) auto;
            gap: 15px;
            align-items: end;
        }
        
        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: 1fr;
            }
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        input, select, button {
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
        
        .btn {
            padding: 12px 25px;
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
        
        .btn-success {
            background: var(--success);
            color: white;
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
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.success {
            border-left-color: var(--success);
        }
        
        .stat-card.warning {
            border-left-color: var(--warning);
        }
        
        .stat-card.danger {
            border-left-color: var(--danger);
        }
        
        .stat-card.info {
            border-left-color: var(--info);
        }
        
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
            transition: height 0.3s;
            min-height: 10px;
        }
        
        .chart-bar:hover {
            opacity: 0.9;
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
        
        .grade-color-aplus { background: #10b981; }
        .grade-color-a { background: #34d399; }
        .grade-color-bplus { background: #60a5fa; }
        .grade-color-b { background: #3b82f6; }
        .grade-color-bminus { background: #6366f1; }
        .grade-color-cplus { background: #f59e0b; }
        .grade-color-c { background: #fbbf24; }
        .grade-color-cminus { background: #f97316; }
        .grade-color-d { background: #ef4444; }
        .grade-color-e { background: #dc2626; }
        
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
        
        .grade-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: bold;
            min-width: 40px;
            text-align: center;
        }
        
        .grade-aplus { background: #10b981; color: white; }
        .grade-a { background: #34d399; color: white; }
        .grade-bplus { background: #60a5fa; color: white; }
        .grade-b { background: #3b82f6; color: white; }
        .grade-bminus { background: #6366f1; color: white; }
        .grade-cplus { background: #f59e0b; color: white; }
        .grade-c { background: #fbbf24; color: white; }
        .grade-cminus { background: #f97316; color: white; }
        .grade-d { background: #ef4444; color: white; }
        .grade-e { background: #dc2626; color: white; }
        
        /* RANKHOLDER CARDS */
        .rankholders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .rankholder-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-top: 5px solid var(--accent);
        }
        
        .rankholder-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .rankholder-avatar {
            width: 60px;
            height: 60px;
            background: var(--accent);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: bold;
        }
        
        .rankholder-info {
            flex: 1;
        }
        
        .rankholder-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 15px;
        }
        
        .stat-item {
            text-align: center;
            padding: 10px;
            background: #f7fafc;
            border-radius: 8px;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
        }
        
        .stat-text {
            font-size: 0.85rem;
            color: #718096;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .reports-table {
                display: block;
                overflow-x: auto;
            }
            
            .rankholders-grid {
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
                <i class="fas fa-chart-bar"></i> Performance Reports
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">Laporan prestasi kadet berdasarkan kehadiran, penglibatan, dan performance grade</p>
        </div>
        
        <!-- FILTER SECTION -->
        <div class="filter-section">
            <form method="GET" action="" class="filter-form">
                <div class="form-group">
                    <label for="month">Bulan & Tahun</label>
                    <input type="month" 
                           id="month" 
                           name="month" 
                           value="<?php echo $month_filter; ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="rank">Pangkat</label>
                    <select id="rank" name="rank">
                        <option value="all">Semua Pangkat</option>
                        <option value="junior" <?php echo $rank_filter == 'junior' ? 'selected' : ''; ?>>Junior</option>
                        <option value="intermediate" <?php echo $rank_filter == 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                        <option value="senior" <?php echo $rank_filter == 'senior' ? 'selected' : ''; ?>>Senior</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="service">Service Type</label>
                    <select id="service" name="service">
                        <option value="all">Semua Service</option>
                        <option value="darat" <?php echo $service_filter == 'darat' ? 'selected' : ''; ?>>Darat</option>
                        <option value="laut" <?php echo $service_filter == 'laut' ? 'selected' : ''; ?>>Laut</option>
                        <option value="udara" <?php echo $service_filter == 'udara' ? 'selected' : ''; ?>>Udara</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter Reports
                    </button>
                </div>
            </form>
        </div>
        
        <!-- STATS SECTION -->
        <div class="stats-section">
            <div class="stat-card success">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $totalCadets; ?></div>
                <div class="stat-label">Total Kadet</div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-value"><?php echo round($avgAttendance, 1); ?>%</div>
                <div class="stat-label">Average Attendance</div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-value">
                    <?php 
                        $excellent = $performanceStats['A+'] + $performanceStats['A'];
                        echo $excellent;
                    ?>
                </div>
                <div class="stat-label">Excellent Performance (A/A+)</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-dumbbell"></i>
                </div>
                <div class="stat-value">
                    <?php 
                        $totalSessions = 0;
                        while ($session = $trainingSessions->fetch_assoc()) {
                            $totalSessions++;
                        }
                        $trainingSessions->data_seek(0); // Reset pointer
                        echo $totalSessions;
                    ?>
                </div>
                <div class="stat-label">Training Sessions</div>
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
                    ?>
                        <div class="chart-bar grade-color-<?php echo strtolower($grade); ?>" 
                             style="height: <?php echo $height; ?>px;"
                             title="<?php echo $grade; ?>: <?php echo $count; ?> kadet">
                            <div class="chart-bar-value"><?php echo $count; ?></div>
                            <div class="chart-bar-label"><?php echo $grade; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- CADET PERFORMANCE REPORTS -->
        <div class="reports-section">
            <div class="table-container">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-user-graduate"></i> Cadet Performance Report
                    </h3>
                    <button class="export-btn" onclick="exportTableToCSV('cadet-report')">
                        <i class="fas fa-file-export"></i> Export CSV
                    </button>
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
                            <th>Performance Grade</th>
                            <th>Attendance Score</th>
                            <th>Discipline Score</th>
                            <th>Skill Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 1;
                        $cadets->data_seek(0); // Reset pointer
                        while ($cadet = $cadets->fetch_assoc()): 
                            $attendanceRate = ($cadet['total_sessions'] > 0) 
                                ? round(($cadet['attended_sessions'] / $cadet['total_sessions']) * 100, 1) 
                                : 0;
                            
                            $attendanceClass = '';
                            if ($attendanceRate >= 90) $attendanceClass = 'attendance-excellent';
                            elseif ($attendanceRate >= 80) $attendanceClass = 'attendance-good';
                            elseif ($attendanceRate >= 70) $attendanceClass = 'attendance-average';
                            else $attendanceClass = 'attendance-poor';
                        ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td><strong><?php echo htmlspecialchars($cadet['military_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($cadet['name']); ?></td>
                                <td><?php echo $cadet['service_type']; ?></td>
                                <td><?php echo $cadet['rank_level']; ?></td>
                                <td>
                                    <span class="attendance-badge <?php echo $attendanceClass; ?>">
                                        <?php echo $attendanceRate; ?>%
                                    </span>
                                    <br>
                                    <small style="color: #718096; font-size: 0.8rem;">
                                        <?php echo $cadet['attended_sessions']; ?>/<?php echo $cadet['total_sessions']; ?> sesi
                                    </small>
                                </td>
                                <td>
                                    <?php if ($cadet['performance_grade']): ?>
                                        <span class="grade-badge grade-<?php echo strtolower($cadet['performance_grade']); ?>">
                                            <?php echo $cadet['performance_grade']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #718096;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $cadet['attendance_score'] ?? '0.00'; ?>
                                </td>
                                <td>
                                    <?php echo $cadet['discipline_score'] ?? '0.00'; ?>
                                </td>
                                <td>
                                    <?php echo $cadet['skill_score'] ?? '0.00'; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        
                        <?php if ($counter == 1): ?>
                            <tr>
                                <td colspan="10" style="text-align: center; padding: 40px; color: var(--secondary);">
                                    <i class="fas fa-user-slash" style="font-size: 2rem; opacity: 0.3; margin-bottom: 10px;"></i>
                                    <p>Tiada data kadet ditemui dengan filter ini</p>
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
                
                <table class="reports-table" id="session-report">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Training Type</th>
                            <th>Location</th>
                            <th>Session Time</th>
                            <th>Created By</th>
                            <th>Attendance</th>
                            <th>Rate</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sessionCounter = 1;
                        while ($session = $trainingSessions->fetch_assoc()): 
                            $attendancePercent = ($session['attendance_count'] > 0 && $session['max_attendance'] > 0)
                                ? round(($session['attendance_count'] / $session['max_attendance']) * 100, 1)
                                : 0;
                        ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($session['training_date'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($session['training_type']); ?></strong></td>
                                <td><?php echo htmlspecialchars($session['location']); ?></td>
                                <td><?php echo $session['session_time']; ?></td>
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
                                        <?php echo $session['present_count']; ?>/<?php echo $session['attendance_count']; ?> hadir
                                    </small>
                                </td>
                                <td>
                                    RM <?php echo number_format($session['training_rate'] ?? 0, 2); ?>
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
                        endwhile; 
                        ?>
                        
                        <?php if ($sessionCounter == 1): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px; color: var(--secondary);">
                                    <i class="fas fa-calendar-times" style="font-size: 2rem; opacity: 0.3; margin-bottom: 10px;"></i>
                                    <p>Tiada sesi latihan untuk bulan ini</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
        
        // Update chart bars on hover
        document.addEventListener('DOMContentLoaded', function() {
            const chartBars = document.querySelectorAll('.chart-bar');
            chartBars.forEach(bar => {
                bar.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.05)';
                });
                
                bar.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });
        });
    </script>
</body>
</html>
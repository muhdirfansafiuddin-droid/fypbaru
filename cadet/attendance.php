<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is cadet
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'cadet') {
    header("Location: ../login.php");
    exit();
}

// Get cadet info
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$cadet = $stmt->fetch();

// Get attendance records
$attendance_stmt = $pdo->prepare("
    SELECT a.*, ts.location, ts.training_type, ts.session_time,
           u.name as verified_by_name
    FROM attendance a
    LEFT JOIN training_sessions ts ON a.session_id = ts.session_id
    LEFT JOIN users u ON a.verified_by = u.user_id
    WHERE a.user_id = ?
    ORDER BY a.date DESC
    LIMIT 50
");
$attendance_stmt->execute([$user_id]);
$attendance_records = $attendance_stmt->fetchAll();

// Calculate attendance summary
$summary_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
        SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused
    FROM attendance 
    WHERE user_id = ?
");
$summary_stmt->execute([$user_id]);
$summary = $summary_stmt->fetch();
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance - Cadet</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Reuse dashboard styles and add specific styles for attendance */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar (same as dashboard) */
        .sidebar {
            width: 250px;
            background: linear-gradient(180deg, #1a237e 0%, #283593 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s;
            z-index: 1000;
        }
        
        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header h2 {
            font-size: 1.2rem;
            margin-bottom: 5px;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            padding: 15px;
            background: rgba(255,255,255,0.1);
            margin: 15px;
            border-radius: 10px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: #1a237e;
            font-size: 1.5rem;
        }
        
        .user-info h4 {
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        .nav-menu {
            list-style: none;
            padding: 0 15px;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .logout-btn {
            margin: 20px 15px;
            display: block;
            width: calc(100% - 30px);
            padding: 12px;
            background: rgba(255,255,255,0.1);
            border: none;
            border-radius: 8px;
            color: white;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 20px;
            transition: margin-left 0.3s;
        }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #1a237e;
            cursor: pointer;
        }
        
        .page-title h1 {
            color: #1a237e;
            font-size: 1.5rem;
        }
        
        /* Attendance Specific Styles */
        .attendance-header {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            border-left: 5px solid #1a237e;
        }
        
        .stat-box.present { border-left-color: #4caf50; }
        .stat-box.absent { border-left-color: #f44336; }
        .stat-box.late { border-left-color: #ff9800; }
        .stat-box.excused { border-left-color: #2196f3; }
        
        .stat-number {
            font-size: 2.2rem;
            font-weight: bold;
            color: #1a237e;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #666;
        }
        
        .stat-percentage {
            font-size: 1rem;
            font-weight: 500;
            margin-top: 8px;
        }
        
        .present .stat-number { color: #4caf50; }
        .absent .stat-number { color: #f44336; }
        .late .stat-number { color: #ff9800; }
        .excused .stat-number { color: #2196f3; }
        
        /* Filters */
        .filters {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-group label {
            font-weight: 500;
            color: #555;
        }
        
        .filter-select {
            padding: 8px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            color: #333;
            font-size: 0.9rem;
        }
        
        .btn-filter {
            padding: 8px 20px;
            background: #1a237e;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .btn-filter:hover {
            background: #283593;
        }
        
        /* Attendance Table */
        .attendance-table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        .attendance-table thead {
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            color: white;
        }
        
        .attendance-table th {
            padding: 15px;
            text-align: left;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .attendance-table tbody tr {
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }
        
        .attendance-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .attendance-table td {
            padding: 15px;
            font-size: 0.9rem;
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-present { background: #e8f5e9; color: #388e3c; }
        .status-absent { background: #ffebee; color: #c62828; }
        .status-late { background: #fff3e0; color: #f57c00; }
        .status-excused { background: #e3f2fd; color: #1976d2; }
        
        .proof-link {
            color: #1a237e;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .proof-link:hover {
            text-decoration: underline;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #888;
        }
        
        .no-data i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #ccc;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 20px;
            background: #f8f9fa;
        }
        
        .page-btn {
            padding: 8px 15px;
            border: 1px solid #e0e0e0;
            background: white;
            color: #333;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .page-btn:hover {
            background: #1a237e;
            color: white;
            border-color: #1a237e;
        }
        
        .page-btn.active {
            background: #1a237e;
            color: white;
            border-color: #1a237e;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .mobile-menu-btn {
                display: block;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .stat-box {
                padding: 15px;
            }
            
            .stat-number {
                font-size: 1.8rem;
            }
        }
        
        @media (min-width: 769px) and (max-width: 1024px) {
            .sidebar {
                width: 220px;
            }
            
            .main-content {
                margin-left: 220px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-shield-alt"></i> CAAMS</h2>
                <p>Cadet Panel</p>
            </div>
            
            <div class="user-profile">
                <div class="user-avatar">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($cadet['name']); ?></h4>
                    <p><?php echo htmlspecialchars($cadet['military_number']); ?></p>
                    <p><i class="fas fa-star"></i> <?php echo htmlspecialchars($cadet['rank_level'] ?? 'Junior'); ?></p>
                </div>
            </div>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="attendance.php" class="nav-link active">
                        <i class="fas fa-calendar-check"></i> Attendance
                    </a>
                </li>
                <li class="nav-item">
                    <a href="performance.php" class="nav-link">
                        <i class="fas fa-chart-line"></i> Performance
                    </a>
                </li>
                <li class="nav-item">
                    <a href="allowance.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i> Allowance
                    </a>
                </li>
                <li class="nav-item">
                    <a href="leave_status.php" class="nav-link">
                        <i class="fas fa-clipboard-list"></i> Leave Status
                    </a>
                </li>
                <li class="nav-item">
                    <a href="profile.php" class="nav-link">
                        <i class="fas fa-user-cog"></i> Profile
                    </a>
                </li>
            </ul>
            
            <a href="../logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Log Keluar
            </a>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="top-bar">
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1><i class="fas fa-calendar-check"></i> Attendance Records</h1>
                    <p>View and track your attendance history</p>
                </div>
                <div class="date-time">
                    <p><?php echo date('d/m/Y'); ?></p>
                </div>
            </div>
            
            <!-- Attendance Summary -->
            <div class="attendance-header">
                <h2 style="color: #1a237e; margin-bottom: 20px; font-size: 1.3rem;">
                    <i class="fas fa-chart-pie"></i> Attendance Summary
                </h2>
                
                <div class="stats-grid">
                    <div class="stat-box present">
                        <div class="stat-number"><?php echo $summary['present'] ?? '0'; ?></div>
                        <div class="stat-label">Present</div>
                        <?php if ($summary['total'] > 0): ?>
                        <div class="stat-percentage">
                            <?php echo round(($summary['present'] / $summary['total']) * 100, 1); ?>%
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="stat-box absent">
                        <div class="stat-number"><?php echo $summary['absent'] ?? '0'; ?></div>
                        <div class="stat-label">Absent</div>
                        <?php if ($summary['total'] > 0): ?>
                        <div class="stat-percentage">
                            <?php echo round(($summary['absent'] / $summary['total']) * 100, 1); ?>%
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="stat-box late">
                        <div class="stat-number"><?php echo $summary['late'] ?? '0'; ?></div>
                        <div class="stat-label">Late</div>
                        <?php if ($summary['total'] > 0): ?>
                        <div class="stat-percentage">
                            <?php echo round(($summary['late'] / $summary['total']) * 100, 1); ?>%
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="stat-box excused">
                        <div class="stat-number"><?php echo $summary['excused'] ?? '0'; ?></div>
                        <div class="stat-label">Excused</div>
                        <?php if ($summary['total'] > 0): ?>
                        <div class="stat-percentage">
                            <?php echo round(($summary['excused'] / $summary['total']) * 100, 1); ?>%
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 15px; border-radius: 10px; margin-top: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong>Total Sessions:</strong> <?php echo $summary['total'] ?? '0'; ?>
                        </div>
                        <div>
                            <strong>Attendance Rate:</strong> 
                            <span style="color: #4caf50; font-weight: bold;">
                                <?php 
                                if ($summary['total'] > 0) {
                                    $rate = (($summary['present'] + $summary['excused']) / $summary['total']) * 100;
                                    echo round($rate, 1) . '%';
                                } else {
                                    echo '0%';
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters" style="margin-bottom: 20px; background: white; padding: 20px; border-radius: 10px;">
                <div class="filter-group">
                    <label for="monthFilter"><i class="fas fa-calendar"></i> Month:</label>
                    <select id="monthFilter" class="filter-select">
                        <option value="">All Months</option>
                        <option value="01">January</option>
                        <option value="02">February</option>
                        <option value="03">March</option>
                        <option value="04">April</option>
                        <option value="05">May</option>
                        <option value="06">June</option>
                        <option value="07">July</option>
                        <option value="08">August</option>
                        <option value="09">September</option>
                        <option value="10">October</option>
                        <option value="11">November</option>
                        <option value="12">December</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="statusFilter"><i class="fas fa-filter"></i> Status:</label>
                    <select id="statusFilter" class="filter-select">
                        <option value="">All Status</option>
                        <option value="present">Present</option>
                        <option value="absent">Absent</option>
                        <option value="late">Late</option>
                        <option value="excused">Excused</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="trainingFilter"><i class="fas fa-dumbbell"></i> Training Type:</label>
                    <select id="trainingFilter" class="filter-select">
                        <option value="">All Types</option>
                        <option value="latihan_tempatan">Latihan Tempatan</option>
                        <option value="latihan_berterusan">Latihan Berterusan</option>
                        <option value="latihan_kem">Latihan Kem</option>
                    </select>
                </div>
                
                <button class="btn-filter" id="applyFilter">
                    <i class="fas fa-search"></i> Apply Filters
                </button>
            </div>
            
            <!-- Attendance Table -->
            <div class="attendance-table-container">
                <div class="table-responsive">
                    <?php if (!empty($attendance_records)): ?>
                        <table class="attendance-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Training Type</th>
                                    <th>Location</th>
                                    <th>Time</th>
                                    <th>Status</th>
                                    <th>Verified By</th>
                                    <th>Proof/Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance_records as $record): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo date('d/m/Y', strtotime($record['date'])); ?></strong><br>
                                        <small><?php echo date('l', strtotime($record['date'])); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($record['training_type'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($record['location'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        $time_mapping = [
                                            'pagi' => 'Morning (8AM-12PM)',
                                            'tengah hari' => 'Noon (1PM-5PM)',
                                            'petang' => 'Evening (6PM-9PM)',
                                            'malam' => 'Night (10PM-12AM)'
                                        ];
                                        echo htmlspecialchars($time_mapping[$record['session_time']] ?? $record['session_time']);
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $status_class = 'status-' . $record['status'];
                                        $status_text = ucfirst($record['status']);
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($record['verified_by_name']): ?>
                                            <span style="color: #4caf50;">
                                                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($record['verified_by_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #ff9800;">
                                                <i class="fas fa-clock"></i> Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($record['proof_file']): ?>
                                            <a href="../uploads/<?php echo htmlspecialchars($record['proof_file']); ?>" 
                                               target="_blank" class="proof-link">
                                                <i class="fas fa-file-alt"></i> View Proof
                                            </a>
                                        <?php elseif ($record['reason']): ?>
                                            <span title="<?php echo htmlspecialchars($record['reason']); ?>">
                                                <i class="fas fa-comment"></i> <?php echo substr($record['reason'], 0, 30); ?>...
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #888;">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-calendar-times"></i>
                            <h3>No Attendance Records Found</h3>
                            <p>Your attendance records will appear here once they are recorded.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Pagination -->
            <div class="pagination">
                <button class="page-btn"><i class="fas fa-chevron-left"></i> Previous</button>
                <button class="page-btn active">1</button>
                <button class="page-btn">2</button>
                <button class="page-btn">3</button>
                <span style="padding: 8px 15px;">...</span>
                <button class="page-btn">10</button>
                <button class="page-btn">Next <i class="fas fa-chevron-right"></i></button>
            </div>
            
            <!-- Export Options -->
            <div style="background: white; padding: 20px; border-radius: 10px; text-align: center;">
                <h3 style="color: #1a237e; margin-bottom: 15px; font-size: 1.1rem;">
                    <i class="fas fa-download"></i> Export Attendance Data
                </h3>
                <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                    <button class="btn-filter" style="background: #4caf50;">
                        <i class="fas fa-file-pdf"></i> Export as PDF
                    </button>
                    <button class="btn-filter" style="background: #2196f3;">
                        <i class="fas fa-file-excel"></i> Export as Excel
                    </button>
                    <button class="btn-filter" style="background: #ff9800;">
                        <i class="fas fa-print"></i> Print Records
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });
        
        // Filter functionality
        document.getElementById('applyFilter').addEventListener('click', function() {
            const month = document.getElementById('monthFilter').value;
            const status = document.getElementById('statusFilter').value;
            const training = document.getElementById('trainingFilter').value;
            
            // Show loading
            alert('Applying filters... (This would filter the table in a real implementation)');
            
            // In real implementation, this would reload the page with filters or make AJAX call
            // window.location.href = `attendance.php?month=${month}&status=${status}&training=${training}`;
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileBtn = document.getElementById('mobileMenuBtn');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !mobileBtn.contains(event.target) &&
                sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
            }
        });
        
        // Highlight current month in filter
        const currentMonth = ('0' + (new Date().getMonth() + 1)).slice(-2);
        document.getElementById('monthFilter').value = currentMonth;
    </script>
</body>
</html>
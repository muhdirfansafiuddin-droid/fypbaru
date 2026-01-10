<?php
// rankholder/view_attendance.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start(); // TAMBAHKAN SESSION START

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
    
    $service_type = $user['service_type'] ?? null;
    $rankholder_id = $user['user_id'];
    
    // Get filter parameters
    $date = $_GET['date'] ?? date('Y-m-d');
    $status = $_GET['status'] ?? 'all';
    $search = $_GET['search'] ?? '';
    
    // Build query for attendance records
    $query = "SELECT 
                a.attendance_id,
                a.date,
                a.status,
                a.recorded_at,
                u.user_id,
                u.military_number,
                u.name as cadet_name,
                u.rank_level,
                ts.training_type,
                ts.location,
                ts.session_time
            FROM attendance a
            JOIN users u ON a.user_id = u.user_id
            JOIN training_sessions ts ON a.session_id = ts.session_id
            WHERE a.checked_by = ?
            AND u.service_type = ?";
    
    $params = [$rankholder_id, $service_type];
    $types = "is";
    
    // Add date filter
    if (!empty($date)) {
        $query .= " AND DATE(a.date) = ?";
        $params[] = $date;
        $types .= "s";
    }
    
    // Add status filter
    if ($status !== 'all') {
        $query .= " AND a.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    // Add search filter
    if (!empty($search)) {
        $query .= " AND (u.military_number LIKE ? OR u.name LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "ss";
    }
    
    $query .= " ORDER BY a.date DESC, u.name LIMIT 100";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $attendanceResult = $stmt->get_result();
    
    // Get attendance summary
    $summaryQuery = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                    SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused
                FROM attendance a
                JOIN users u ON a.user_id = u.user_id
                WHERE a.checked_by = ?
                AND u.service_type = ?
                AND DATE(a.date) = ?";
    
    $summaryStmt = $db->prepare($summaryQuery);
    $summaryStmt->bind_param("iss", $rankholder_id, $service_type, $date);
    $summaryStmt->execute();
    $summaryResult = $summaryStmt->get_result();
    $summary = $summaryResult->fetch_assoc();
    
    // Get dates for filter
    $datesQuery = "SELECT DISTINCT DATE(date) as date FROM attendance 
                  WHERE checked_by = ? 
                  ORDER BY date DESC LIMIT 30";
    $datesStmt = $db->prepare($datesQuery);
    $datesStmt->bind_param("i", $rankholder_id);
    $datesStmt->execute();
    $datesResult = $datesStmt->get_result();
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lihat Kehadiran - CAAMS</title>
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
        }
        
        .header h1 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .back-btn {
            background: rgba(255,255,255,0.1);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }
        
        /* FILTER SECTION */
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        
        @media (min-width: 768px) {
            .filter-form {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-label {
            margin-bottom: 5px;
            color: var(--secondary);
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .filter-select, .filter-input {
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.95rem;
            width: 100%;
        }
        
        .filter-btn {
            background: var(--accent);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .filter-btn:hover {
            background: #2c5282;
        }
        
        /* SUMMARY CARDS */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }
        
        @media (min-width: 480px) {
            .summary-grid {
                grid-template-columns: repeat(5, 1fr);
            }
        }
        
        .summary-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .summary-card.total { border-top: 4px solid var(--accent); }
        .summary-card.present { border-top: 4px solid var(--success); }
        .summary-card.absent { border-top: 4px solid var(--danger); }
        .summary-card.late { border-top: 4px solid var(--warning); }
        .summary-card.excused { border-top: 4px solid var(--purple); }
        
        .summary-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 5px 0;
        }
        
        .summary-label {
            color: var(--secondary);
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        /* ATTENDANCE TABLE */
        .attendance-table {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }
        
        th {
            background: var(--light);
            color: var(--primary);
            font-weight: 600;
            text-align: left;
            padding: 12px 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        
        tr:hover {
            background: var(--light);
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-present { background: rgba(72, 187, 120, 0.1); color: var(--success); }
        .badge-absent { background: rgba(245, 101, 101, 0.1); color: var(--danger); }
        .badge-late { background: rgba(237, 137, 54, 0.1); color: var(--warning); }
        .badge-excused { background: rgba(159, 122, 234, 0.1); color: var(--purple); }
        
        .cadet-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .cadet-avatar {
            width: 35px;
            height: 35px;
            background: var(--accent);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .cadet-details h4 {
            color: var(--primary);
            margin-bottom: 2px;
            font-size: 0.95rem;
        }
        
        .cadet-details p {
            color: #718096;
            font-size: 0.85rem;
        }
        
        /* NO DATA */
        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: #718096;
        }
        
        .no-data i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.3;
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
        
        /* UTILITIES */
        .text-center { text-align: center; }
        .mb-2 { margin-bottom: 15px; }
        .mt-2 { margin-top: 15px; }
        
        /* LOADING */
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
        
        /* NEW: Table styling without actions column */
        table th:nth-child(4),
        table td:nth-child(4) {
            width: 120px;
        }
        
        @media (max-width: 768px) {
            table th:nth-child(3),
            table td:nth-child(3) {
                display: none;
            }
            
            .mobile-nav-item {
                padding: 6px;
            }
            
            .mobile-nav-icon {
                font-size: 1rem;
            }
            
            .mobile-nav-label {
                font-size: 0.6rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <h1>
                <i class="fas fa-clipboard-list"></i>
                Lihat Kehadiran
            </h1>
            
        </div>
        
        <!-- FILTER SECTION -->
        <div class="filter-section">
    <form method="GET" action="" class="filter-form">
        <div class="filter-group">
            <label class="filter-label">Tarikh</label>
            <input type="date" 
                   name="date" 
                   class="filter-input" 
                   value="<?php echo $date; ?>"
                   max="<?php echo date('Y-m-d'); ?>"
                   onchange="this.form.submit()">
        </div>
        
        <div class="filter-group">
            <label class="filter-label">Status</label>
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>Semua Status</option>
                <option value="present" <?php echo $status == 'present' ? 'selected' : ''; ?>>Hadir</option>
                <option value="absent" <?php echo $status == 'absent' ? 'selected' : ''; ?>>Tidak Hadir</option>
                <option value="late" <?php echo $status == 'late' ? 'selected' : ''; ?>>Lewat</option>
                <option value="excused" <?php echo $status == 'excused' ? 'selected' : ''; ?>>Pelepasan</option>
            </select>
        </div>
                
                <div class="filter-group">
                    <label class="filter-label">Cari Kadet</label>
                    <input type="text" 
                           name="search" 
                           class="filter-input" 
                           placeholder="Nama atau No. Tentera"
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">&nbsp;</label>
                    <button type="submit" class="filter-btn">
                        <i class="fas fa-search"></i> Cari
                    </button>
                </div>
            </form>
        </div>
        
        <!-- SUMMARY -->
        <div class="summary-grid">
            <div class="summary-card total">
                <div class="summary-number"><?php echo $summary['total'] ?? 0; ?></div>
                <div class="summary-label">JUMLAH</div>
            </div>
            <div class="summary-card present">
                <div class="summary-number"><?php echo $summary['present'] ?? 0; ?></div>
                <div class="summary-label">HADIR</div>
            </div>
            <div class="summary-card absent">
                <div class="summary-number"><?php echo $summary['absent'] ?? 0; ?></div>
                <div class="summary-label">TIDAK HADIR</div>
            </div>
            <div class="summary-card late">
                <div class="summary-number"><?php echo $summary['late'] ?? 0; ?></div>
                <div class="summary-label">LEWAT</div>
            </div>
            <div class="summary-card excused">
                <div class="summary-number"><?php echo $summary['excused'] ?? 0; ?></div>
                <div class="summary-label">BERCUTI</div>
            </div>
        </div>
        
        <!-- ATTENDANCE TABLE -->
        <div class="attendance-table">
            <div class="loading" id="loading">
                <i class="fas fa-spinner"></i>
                <p>Memuatkan data...</p>
            </div>
            
            <div class="table-responsive">
                <?php if ($attendanceResult->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>KADET</th>
                            <th>SESI LATIHAN</th>
                            <th>TARIKH/MASA</th>
                            <th>STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $attendanceResult->fetch_assoc()): 
                            $statusClass = 'badge-' . $row['status'];
                        ?>
                        <tr>
                            <td>
                                <div class="cadet-info">
                                    <div class="cadet-avatar">
                                        <?php echo strtoupper(substr($row['cadet_name'], 0, 1)); ?>
                                    </div>
                                    <div class="cadet-details">
                                        <h4><?php echo htmlspecialchars($row['cadet_name']); ?></h4>
                                        <p><?php echo $row['military_number']; ?></p>
                                        <small style="color: #718096;">
                                            <?php echo ucfirst($row['rank_level']); ?>
                                        </small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($row['training_type']); ?></strong>
                                    <div style="font-size: 0.85rem; color: #718096;">
                                        <?php echo htmlspecialchars($row['location']); ?>
                                        <br>
                                        <?php echo ucfirst($row['session_time']); ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <strong><?php echo date('d/m/Y', strtotime($row['date'])); ?></strong>
                                    <div style="font-size: 0.85rem; color: #718096;">
                                        <?php echo date('h:i A', strtotime($row['recorded_at'])); ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php echo strtoupper($row['status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>Tiada Rekod Kehadiran</h3>
                    <p>Tiada rekod kehadiran ditemukan untuk tarikh dan filter yang dipilih.</p>
                    <a href="take_attendance.php" class="back-btn" style="margin-top: 15px; display: inline-block;">
                        <i class="fas fa-plus-circle"></i> Ambil Kehadiran
                    </a>
                </div>
                <?php endif; ?>
            </div>
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
        
        <a href="take_attendance.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-qrcode"></i>
            </div>
            <div class="mobile-nav-label">Ambil</div>
        </a>
        
        <a href="view_attendance.php" class="mobile-nav-item active">
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
        // Show/hide loading
        function showLoading() {
            document.getElementById('loading').style.display = 'block';
        }
        
        function hideLoading() {
            document.getElementById('loading').style.display = 'none';
        }
        
        // Form submission loading
        document.querySelector('.filter-form').addEventListener('submit', function() {
            showLoading();
        });
        
        // Mobile touch events
        document.addEventListener('touchstart', function() {
            // Add touch feedback
        });
        
        // Export to PDF/Excel (optional)
        function exportData(format) {
            const params = new URLSearchParams(window.location.search);
            params.set('export', format);
            
            if (format === 'pdf') {
                window.open('export_attendance.php?' + params.toString(), '_blank');
            } else if (format === 'excel') {
                window.open('export_attendance.php?format=excel&' + params.toString(), '_blank');
            }
        }
        
        // Auto-refresh every 30 seconds (optional)
        setInterval(() => {
            location.reload();
        }, 30000);
        
        // Handle orientation change
        window.addEventListener('orientationchange', function() {
            setTimeout(() => {
                window.scrollTo(0, 0);
            }, 100);
        });
        
        // Add active class to current page in mobile nav
        document.addEventListener('DOMContentLoaded', function() {
            // This page is already highlighted with "active" class in HTML
            // You can add more logic here if needed
            
            // Add ripple effect to mobile nav items
            const navItems = document.querySelectorAll('.mobile-nav-item');
            navItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    // Remove active class from all items
                    navItems.forEach(navItem => {
                        navItem.classList.remove('active');
                    });
                    // Add active class to clicked item
                    this.classList.add('active');
                });
            });
        });
    </script>
</body>
</html>
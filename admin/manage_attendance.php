<?php
// admin/manage_attendance.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('admin');
$user = (new Auth())->getCurrentUser();
$db = new Database();

// Initialize variables
$filter_service = isset($_GET['service_type']) ? $_GET['service_type'] : '';
$filter_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$message = '';
$messageType = '';

// Handle attendance status update
if (isset($_POST['update_status'])) {
    $attendance_id = $_POST['attendance_id'];
    $status = $_POST['status'];
    $reason = $_POST['reason'] ?? '';
    
    // Update attendance status in database
    $stmt = $db->prepare("UPDATE attendance SET status = ?, reason = ?, checked_by = ?, checked_at = NOW() WHERE attendance_id = ?");
    $stmt->bind_param("ssii", $status, $reason, $user['user_id'], $attendance_id);
    
    if ($stmt->execute()) {
        // Log activity
        $logDesc = "Updated attendance #$attendance_id to: $status";
        $logStmt = $db->prepare("INSERT INTO activity_logs (user_id, activity_type, description, related_id) VALUES (?, 'attendance_updated', ?, ?)");
        $logStmt->bind_param("isi", $user['user_id'], $logDesc, $attendance_id);
        $logStmt->execute();
        
        $message = 'Status kehadiran berjaya dikemaskini!';
        $messageType = 'success';
    } else {
        $message = 'Database error: ' . $stmt->error;
        $messageType = 'error';
    }
}

// Handle bulk verify
if (isset($_POST['bulk_verify'])) {
    if (!empty($_POST['attendance_ids'])) {
        $ids = implode(',', array_map('intval', $_POST['attendance_ids']));
        $stmt = $db->query("UPDATE attendance SET status = 'present', checked_by = {$user['user_id']}, checked_at = NOW() WHERE attendance_id IN ($ids)");
        
        if ($stmt) {
            // Log activity
            $logDesc = "Bulk verified " . count($_POST['attendance_ids']) . " records";
            $logStmt = $db->prepare("INSERT INTO activity_logs (user_id, activity_type, description) VALUES (?, 'attendance_updated', ?)");
            $logStmt->bind_param("is", $user['user_id'], $logDesc);
            $logStmt->execute();
            
            $message = count($_POST['attendance_ids']) . ' rekod berjaya disahkan!';
            $messageType = 'success';
        }
    }
}

// Handle export to CSV
if (isset($_POST['export_csv'])) {
    $start_date = $_POST['export_start_date'];
    $end_date = $_POST['export_end_date'];
    
    // Fetch data for export
    $sql = "SELECT 
                a.attendance_id,
                u.military_number,
                u.name,
                u.service_type,
                ts.training_type,
                ts.location,
                ts.session_time,
                a.date,
                a.status,
                a.reason,
                DATE_FORMAT(a.recorded_at, '%Y-%m-%d %H:%i:%s') as recorded_at,
                u2.name as verified_by,
                DATE_FORMAT(a.checked_at, '%Y-%m-%d %H:%i:%s') as checked_at
            FROM attendance a
            JOIN users u ON a.user_id = u.user_id
            JOIN training_sessions ts ON a.session_id = ts.session_id
            LEFT JOIN users u2 ON a.checked_by = u2.user_id
            WHERE a.date BETWEEN ? AND ?
            ORDER BY a.date DESC, u.name";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $exportData = $result->fetch_all(MYSQLI_ASSOC);
    
    // Generate CSV file
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan_kehadiran_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM
    
    // Header row
    fputcsv($output, ['ID', 'No. Tentera', 'Nama', 'Perkhidmatan', 'Jenis Latihan', 'Lokasi', 'Sesi', 'Tarikh', 'Status', 'Sebab', 'Direkod Pada', 'Disahkan Oleh', 'Disahkan Pada']);
    
    // Data rows
    foreach ($exportData as $row) {
        fputcsv($output, [
            $row['attendance_id'],
            $row['military_number'],
            $row['name'],
            $row['service_type'],
            $row['training_type'],
            $row['location'],
            $row['session_time'],
            $row['date'],
            $row['status'],
            $row['reason'],
            $row['recorded_at'],
            $row['verified_by'],
            $row['checked_at']
        ]);
    }
    
    fclose($output);
    exit();
}

// Fetch attendance records with filters
$sql = "SELECT 
            a.*, 
            u.name, 
            u.military_number, 
            u.service_type,
            ts.training_type,
            ts.location,
            ts.session_time,
            u2.name as verified_by_name
        FROM attendance a 
        JOIN users u ON a.user_id = u.user_id 
        JOIN training_sessions ts ON a.session_id = ts.session_id
        LEFT JOIN users u2 ON a.checked_by = u2.user_id
        WHERE a.date = ?";
    
$params = [$filter_date];
$types = "s";

if (!empty($filter_service)) {
    $sql .= " AND u.service_type = ?";
    $params[] = $filter_service;
    $types .= "s";
}

if (!empty($filter_status)) {
    $sql .= " AND a.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

$sql .= " ORDER BY a.date DESC, u.name";

$stmt = $db->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$attendance_records = $result->fetch_all(MYSQLI_ASSOC);

// Get statistics
$statsSql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused
            FROM attendance a 
            JOIN users u ON a.user_id = u.user_id
            WHERE a.date = ?";
            
if (!empty($filter_service)) {
    $statsSql .= " AND u.service_type = ?";
}

$statsStmt = $db->prepare($statsSql);
if (!empty($filter_service)) {
    $statsStmt->bind_param("ss", $filter_date, $filter_service);
} else {
    $statsStmt->bind_param("s", $filter_date);
}
$statsStmt->execute();
$statsResult = $statsStmt->get_result();
$stats = $statsResult->fetch_assoc();

// Get service types for filter
$serviceStmt = $db->query("SELECT DISTINCT service_type FROM users WHERE service_type IS NOT NULL ORDER BY service_type");
$serviceTypes = $serviceStmt->fetch_all(MYSQLI_ASSOC);

// Status labels
$statusLabels = [
    'present' => 'Hadir',
    'absent' => 'Tidak Hadir',
    'late' => 'Lewat',
    'excused' => 'Dimaafkan'
];

// Service labels
$serviceLabels = [
    'darat' => 'Darat',
    'laut' => 'Laut',
    'udara' => 'Udara'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Urus Kehadiran - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --success: #48bb78;
            --warning: #ed8936;
            --danger: #f56565;
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
        
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* MAIN CONTENT */
        .content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            padding: 30px;
        }
        
        @media (max-width: 1100px) {
            .content {
                grid-template-columns: 1fr;
            }
        }
        
        /* SECTION STYLES */
        .section {
            padding: 25px;
            background: #f7fafc;
            border-radius: 15px;
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
        
        /* FILTER SECTION */
        .filter-group {
            margin-bottom: 20px;
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
        
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        /* BUTTONS */
        .btn {
            padding: 15px 30px;
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
            flex: 1;
        }
        
        .btn-secondary {
            background: var(--secondary);
            color: white;
            flex: 1;
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        /* STATISTICS CARDS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            transition: transform 0.3s;
            border-left: 4px solid;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-total { border-left-color: #6c757d; }
        .stat-present { border-left-color: var(--success); }
        .stat-absent { border-left-color: var(--danger); }
        .stat-late { border-left-color: var(--warning); }
        .stat-excused { border-left-color: var(--accent); }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 10px 0;
        }
        
        .stat-label {
            color: #718096;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* TABLE */
        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-top: 20px;
        }
        
        .table-header {
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: var(--primary);
        }
        
        th {
            padding: 15px;
            text-align: left;
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #e9ecef;
        }
        
        tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* STATUS BADGES */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-present { background: #d4edda; color: #155724; }
        .status-absent { background: #f8d7da; color: #721c24; }
        .status-late { background: #fff3cd; color: #856404; }
        .status-excused { background: #d1ecf1; color: #0c5460; }
        
        /* SERVICE BADGES */
        .service-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .service-darat { background: #e9ecef; color: #495057; }
        .service-laut { background: #cff4fc; color: #055160; }
        .service-udara { background: #fff3cd; color: #664d03; }
        
        /* CHECKBOX */
        .checkbox-cell {
            text-align: center;
        }
        
        /* ACTION BUTTONS */
        .action-btns {
            display: flex;
            gap: 5px;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 0.85rem;
        }
        
        /* MODAL */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            animation: modalSlide 0.3s ease;
        }
        
        @keyframes modalSlide {
            from { transform: translateY(-30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-header {
            background: var(--primary);
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }
        
        .modal-body {
            padding: 20px;
            max-height: 60vh;
            overflow-y: auto;
        }
        
        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        /* EMPTY STATE */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--secondary);
        }
        
        .empty-state i {
            font-size: 3rem;
            opacity: 0.3;
            margin-bottom: 15px;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            table {
                min-width: 800px;
            }
            
            .btn-group {
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
                <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
            </a>
            <h1>
                <i class="fas fa-clipboard-check"></i> Urus Kehadiran
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">Kelola dan sahkan rekod kehadiran kadet</p>
        </div>
        
        <!-- INFORMATION ALERT -->
        <div class="alert info">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Cara penggunaan:</strong> 
                1. Tapis rekod mengikut tarikh/perkhidmatan → 
                2. Semak status kehadiran → 
                3. Edit jika perlu → 
                4. Eksport laporan
            </div>
        </div>
        
        <!-- MESSAGE ALERT -->
        <?php if ($message): ?>
            <div class="alert <?php echo $messageType; ?>">
                <i class="fas <?php 
                    echo $messageType == 'success' ? 'fa-check-circle' : 
                         ($messageType == 'info' ? 'fa-info-circle' : 'fa-exclamation-triangle'); 
                ?>"></i>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>
        
        <!-- MAIN CONTENT -->
        <div class="content">
            <!-- LEFT: FILTERS -->
            <div class="section">
                <h2 class="section-title">
                    <i class="fas fa-filter"></i> Tapisan Rekod
                </h2>
                
                <form method="GET" action="" id="filterForm">
                    <div class="filter-group">
                        <label for="date">Tarikh</label>
                        <input type="date" 
                               id="date" 
                               name="date" 
                               value="<?php echo htmlspecialchars($filter_date); ?>"
                               max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="service_type">Perkhidmatan</label>
                        <select id="service_type" name="service_type">
                            <option value="">Semua Perkhidmatan</option>
                            <?php foreach ($serviceTypes as $service): ?>
                                <option value="<?php echo htmlspecialchars($service['service_type']); ?>" 
                                    <?php echo $filter_service == $service['service_type'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($serviceLabels[$service['service_type']] ?? ucfirst($service['service_type'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="status">Status Kehadiran</label>
                        <select id="status" name="status">
                            <option value="">Semua Status</option>
                            <option value="present" <?php echo $filter_status == 'present' ? 'selected' : ''; ?>>Hadir</option>
                            <option value="absent" <?php echo $filter_status == 'absent' ? 'selected' : ''; ?>>Tidak Hadir</option>
                            <option value="late" <?php echo $filter_status == 'late' ? 'selected' : ''; ?>>Lewat</option>
                            <option value="excused" <?php echo $filter_status == 'excused' ? 'selected' : ''; ?>>Dimaafkan</option>
                        </select>
                    </div>
                    
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Tapis
                        </button>
                        <a href="manage_attendance.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- RIGHT: STATISTICS -->
            <div class="section">
                <h2 class="section-title">
                    <i class="fas fa-chart-bar"></i> Statistik
                </h2>
                
                <div class="stats-grid">
                    <div class="stat-card stat-total">
                        <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                        <div class="stat-label">JUMLAH REKOD</div>
                        <small><?php echo date('d/m/Y', strtotime($filter_date)); ?></small>
                    </div>
                    
                    <div class="stat-card stat-present">
                        <div class="stat-value"><?php echo $stats['present'] ?? 0; ?></div>
                        <div class="stat-label">HADIR</div>
                        <small>
                            <?php echo $stats['total'] > 0 ? round(($stats['present']/$stats['total'])*100, 1) : 0; ?>%
                        </small>
                    </div>
                    
                    <div class="stat-card stat-absent">
                        <div class="stat-value"><?php echo $stats['absent'] ?? 0; ?></div>
                        <div class="stat-label">TIDAK HADIR</div>
                        <small>
                            <?php echo $stats['total'] > 0 ? round(($stats['absent']/$stats['total'])*100, 1) : 0; ?>%
                        </small>
                    </div>
                    
                    <div class="stat-card stat-late">
                        <div class="stat-value"><?php echo $stats['late'] ?? 0; ?></div>
                        <div class="stat-label">LEWAT</div>
                        <small>
                            <?php echo $stats['total'] > 0 ? round(($stats['late']/$stats['total'])*100, 1) : 0; ?>%
                        </small>
                    </div>
                    
                    <div class="stat-card stat-excused">
                        <div class="stat-value"><?php echo $stats['excused'] ?? 0; ?></div>
                        <div class="stat-label">DIMAAPKAN</div>
                        <small>
                            <?php echo $stats['total'] > 0 ? round(($stats['excused']/$stats['total'])*100, 1) : 0; ?>%
                        </small>
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <button class="btn btn-success" onclick="openExportModal()">
                        <i class="fas fa-file-export"></i> Eksport Laporan
                    </button>
                </div>
            </div>
        </div>
        
        <!-- TABLE SECTION -->
        <div class="content" style="grid-template-columns: 1fr;">
            <div class="section">
                <div class="table-header">
                    <h2 class="section-title" style="margin-bottom: 0;">
                        <i class="fas fa-list"></i> Senarai Kehadiran
                    </h2>
                    
                    <?php if (!empty($attendance_records)): ?>
                    <form method="POST" onsubmit="return confirm('Sahkan semua rekod yang dipilih?')" style="margin: 0;">
                        <input type="hidden" name="bulk_verify" value="1">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check-double"></i> Sahkan Dipilih
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($attendance_records)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h3>Tiada Rekod Ditemui</h3>
                        <p>Tiada rekod kehadiran untuk tapisan yang dipilih.</p>
                        <p style="color: #718096; font-size: 0.9rem;">
                            Cuba pilih tarikh lain atau reset tapisan.
                        </p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th width="50" class="checkbox-cell">
                                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                    </th>
                                    <th>#</th>
                                    <th>No. Tentera</th>
                                    <th>Nama</th>
                                    <th>Perkhidmatan</th>
                                    <th>Latihan</th>
                                    <th>Lokasi</th>
                                    <th>Sesi</th>
                                    <th>Tarikh</th>
                                    <th>Status</th>
                                    <th>Bukti</th>
                                    <th>Disahkan Oleh</th>
                                    <th>Tindakan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance_records as $index => $record): ?>
                                <tr>
                                    <td class="checkbox-cell">
                                        <input type="checkbox" name="attendance_ids[]" class="record-checkbox" value="<?php echo $record['attendance_id']; ?>">
                                    </td>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><strong><?php echo htmlspecialchars($record['military_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($record['name']); ?></td>
                                    <td>
                                        <?php 
                                        $service_class = 'service-' . $record['service_type'];
                                        ?>
                                        <span class="service-badge <?php echo $service_class; ?>">
                                            <?php echo $serviceLabels[$record['service_type']] ?? ucfirst($record['service_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($record['training_type']); ?></td>
                                    <td><?php echo htmlspecialchars($record['location']); ?></td>
                                    <td><?php echo ucfirst($record['session_time']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($record['date'])); ?></td>
                                    <td>
                                        <?php
                                        $status_class = 'status-' . $record['status'];
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $statusLabels[$record['status']] ?? ucfirst($record['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($record['proof_file']): ?>
                                            <button class="btn btn-small" style="background: var(--accent); color: white;" onclick="viewProof('<?php echo htmlspecialchars($record['proof_file']); ?>')">
                                                <i class="fas fa-eye"></i> Lihat
                                            </button>
                                        <?php else: ?>
                                            <span style="color: #718096;">Tiada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($record['verified_by_name']): ?>
                                            <span style="color: var(--success);">
                                                <i class="fas fa-user-check"></i>
                                                <?php echo htmlspecialchars($record['verified_by_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--warning);">
                                                <i class="fas fa-clock"></i> Belum disahkan
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-btns">
                                            <button type="button" class="btn btn-small" style="background: var(--warning); color: white;" 
                                                    data-bs-toggle="modal" data-bs-target="#editModal<?php echo $record['attendance_id']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-small" style="background: var(--accent); color: white;" 
                                                    onclick="quickVerify(<?php echo $record['attendance_id']; ?>)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- EXPORT MODAL -->
    <div id="exportModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-file-export"></i> Eksport Laporan</h3>
                <button class="modal-close" onclick="closeExportModal()">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <p>Pilih julat tarikh untuk eksport laporan CSV:</p>
                    <div style="margin-bottom: 20px;">
                        <label for="export_start_date" style="display: block; margin-bottom: 8px; font-weight: 600;">Tarikh Mula</label>
                        <input type="date" id="export_start_date" name="export_start_date" required style="width: 100%;">
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label for="export_end_date" style="display: block; margin-bottom: 8px; font-weight: 600;">Tarikh Tamat</label>
                        <input type="date" id="export_end_date" name="export_end_date" required style="width: 100%;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeExportModal()">
                        <i class="fas fa-times"></i> Batal
                    </button>
                    <button type="submit" name="export_csv" class="btn btn-success">
                        <i class="fas fa-download"></i> Eksport CSV
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- EDIT MODALS -->
    <?php foreach ($attendance_records as $record): ?>
    <div id="editModal<?php echo $record['attendance_id']; ?>" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Status Kehadiran</h3>
                <button class="modal-close" onclick="closeModal('editModal<?php echo $record['attendance_id']; ?>')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="attendance_id" value="<?php echo $record['attendance_id']; ?>">
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600;">Kadet</label>
                        <input type="text" value="<?php echo htmlspecialchars($record['name'] . ' (' . $record['military_number'] . ')'); ?>" readonly style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600;">Butiran Latihan</label>
                        <input type="text" value="<?php echo htmlspecialchars($record['training_type'] . ' di ' . $record['location'] . ' (Sesi: ' . $record['session_time'] . ')'); ?>" readonly style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label for="status<?php echo $record['attendance_id']; ?>" style="display: block; margin-bottom: 8px; font-weight: 600;">Status Kehadiran</label>
                        <select id="status<?php echo $record['attendance_id']; ?>" name="status" required style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
                            <option value="present" <?php echo $record['status'] == 'present' ? 'selected' : ''; ?>>Hadir</option>
                            <option value="absent" <?php echo $record['status'] == 'absent' ? 'selected' : ''; ?>>Tidak Hadir</option>
                            <option value="late" <?php echo $record['status'] == 'late' ? 'selected' : ''; ?>>Lewat</option>
                            <option value="excused" <?php echo $record['status'] == 'excused' ? 'selected' : ''; ?>>Dimaafkan</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="reason<?php echo $record['attendance_id']; ?>" style="display: block; margin-bottom: 8px; font-weight: 600;">Sebab (jika perlu)</label>
                        <textarea id="reason<?php echo $record['attendance_id']; ?>" name="reason" rows="3" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;"><?php echo htmlspecialchars($record['reason'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editModal<?php echo $record['attendance_id']; ?>')">
                        <i class="fas fa-times"></i> Batal
                    </button>
                    <button type="submit" name="update_status" class="btn btn-primary">
                        <i class="fas fa-save"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    
    <script>
        // Set default dates for export modal
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const firstDay = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0];
            
            document.getElementById('export_start_date').value = firstDay;
            document.getElementById('export_end_date').value = today;
            
            // Set filter date to today if empty
            const filterDate = document.getElementById('date');
            if (!filterDate.value) {
                filterDate.value = today;
            }
        });
        
        // Modal functions
        function openExportModal() {
            document.getElementById('exportModal').style.display = 'flex';
        }
        
        function closeExportModal() {
            document.getElementById('exportModal').style.display = 'none';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Select All checkbox functionality
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.record-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }
        
        // Update select all when individual checkboxes change
        document.querySelectorAll('.record-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                if (!this.checked) {
                    document.getElementById('selectAll').checked = false;
                } else {
                    const allCheckboxes = document.querySelectorAll('.record-checkbox');
                    const allChecked = Array.from(allCheckboxes).every(cb => cb.checked);
                    document.getElementById('selectAll').checked = allChecked;
                }
            });
        });
        
        // Quick verify function
        function quickVerify(attendanceId) {
            if (confirm('Tukar status ke "Hadir"?')) {
                // Create a hidden form
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'attendance_id';
                idInput.value = attendanceId;
                
                const statusInput = document.createElement('input');
                statusInput.type = 'hidden';
                statusInput.name = 'status';
                statusInput.value = 'present';
                
                const submitInput = document.createElement('input');
                submitInput.type = 'hidden';
                submitInput.name = 'update_status';
                submitInput.value = '1';
                
                form.appendChild(idInput);
                form.appendChild(statusInput);
                form.appendChild(submitInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // View proof function
        function viewProof(filename) {
            alert('Fail bukti: ' + filename + '\n\nFungsi paparan fail akan dilaksanakan kemudian.');
        }
        
        // Toast notification
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
    </script>
</body>
</html>
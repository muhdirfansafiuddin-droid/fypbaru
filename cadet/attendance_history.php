<?php
// cadet/attendance_history.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('cadet');
$user = (new Auth())->getCurrentUser();
$db = new Database();

// Get attendance history with filters
$filterMonth = $_GET['month'] ?? date('Y-m');
$filterStatus = $_GET['status'] ?? 'all';

$sql = "SELECT a.*, ts.training_type, ts.location, ts.session_time,
        DATE_FORMAT(a.date, '%d/%m/%Y') as formatted_date,
        CASE 
            WHEN a.status = 'present' THEN 'Hadir'
            WHEN a.status = 'excused' THEN 'Pelepasan'
            WHEN a.status = 'absent' THEN 'Tidak Hadir'
            WHEN a.status = 'late' THEN 'Lewat'
            ELSE a.status
        END as status_malay,
        u.name as checked_by_name
        FROM attendance a
        JOIN training_sessions ts ON a.session_id = ts.session_id
        LEFT JOIN users u ON a.checked_by = u.user_id
        WHERE a.user_id = ?";
        
$params = [$user['user_id']];
$types = "i";

// Apply filters
if ($filterMonth !== 'all') {
    $sql .= " AND DATE_FORMAT(a.date, '%Y-%m') = ?";
    $params[] = $filterMonth;
    $types .= "s";
}

if ($filterStatus !== 'all') {
    $sql .= " AND a.status = ?";
    $params[] = $filterStatus;
    $types .= "s";
}

$sql .= " ORDER BY a.date DESC, ts.session_time";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$attendanceHistory = $stmt->get_result();

// Get months for filter dropdown
$monthsSql = "SELECT DISTINCT DATE_FORMAT(date, '%Y-%m') as month,
             DATE_FORMAT(date, '%M %Y') as month_name
             FROM attendance 
             WHERE user_id = ?
             ORDER BY month DESC";
$monthsStmt = $db->prepare($monthsSql);
$monthsStmt->bind_param("i", $user['user_id']);
$monthsStmt->execute();
$months = $monthsStmt->get_result();

// Get statistics
$statsSql = "SELECT 
    COUNT(CASE WHEN status = 'present' THEN 1 END) as present_count,
    COUNT(CASE WHEN status = 'excused' THEN 1 END) as excused_count,
    COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count,
    COUNT(*) as total_count
    FROM attendance 
    WHERE user_id = ?";
$statsStmt = $db->prepare($statsSql);
$statsStmt->bind_param("i", $user['user_id']);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

// Session time labels
$sessionTimeLabels = [
    'pagi' => 'Pagi',
    'tengah hari' => 'Tengah Hari', 
    'petang' => 'Petang',
    'malam' => 'Malam'
];

// Status colors
$statusColors = [
    'present' => ['#d4edda', '#155724', '✅'],
    'excused' => ['#fff3cd', '#856404', '📄'],
    'absent' => ['#f8d7da', '#721c24', '❌'],
    'late' => ['#d1ecf1', '#0c5460', '⏰']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sejarah Kehadiran - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* SAME CSS AS DASHBOARD */
        .history-container {
            padding: 16px;
        }
        
        .filters {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .filter-select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
        }
        
        .filter-btn {
            grid-column: 1 / -1;
            background: var(--accent);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 20px;
        }
        
        .stat-badge {
            background: white;
            border-radius: 8px;
            padding: 10px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .stat-count {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 3px;
        }
        
        .stat-present .stat-count { color: var(--success); }
        .stat-excused .stat-count { color: var(--warning); }
        .stat-absent .stat-count { color: var(--danger); }
        .stat-total .stat-count { color: var(--primary); }
        
        .stat-label {
            font-size: 0.7rem;
            color: #718096;
        }
        
        .history-list {
            margin-top: 20px;
        }
        
        .history-item {
            background: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-left: 4px solid;
        }
        
        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .history-date {
            font-weight: 700;
            color: var(--primary);
            font-size: 1.1rem;
        }
        
        .history-status {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .history-details {
            font-size: 0.9rem;
            color: #718096;
        }
        
        .history-details div {
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .empty-history {
            text-align: center;
            padding: 40px 20px;
            color: #a0aec0;
        }
        
        .empty-history i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .export-btn {
            background: var(--success);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 15px;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="mobile-container">
        <!-- HEADER -->
        <header class="mobile-header">
            <button class="back-btn" onclick="goBack()">
                <i class="fas fa-arrow-left"></i>
            </button>
            <div class="header-content">
                <h1 class="user-name">Sejarah Kehadiran</h1>
                <p style="opacity: 0.9; font-size: 0.9rem;">
                    <i class="fas fa-history"></i> Rekod Kehadiran Terperinci
                </p>
            </div>
        </header>
        
        <div class="history-container">
            <!-- FILTERS -->
            <div class="filters">
                <form method="GET" action="">
                    <div class="filter-row">
                        <select name="month" class="filter-select">
                            <option value="all">Semua Bulan</option>
                            <?php while($month = $months->fetch_assoc()): ?>
                                <option value="<?php echo $month['month']; ?>" 
                                    <?php echo $filterMonth == $month['month'] ? 'selected' : ''; ?>>
                                    <?php echo $month['month_name']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        
                        <select name="status" class="filter-select">
                            <option value="all">Semua Status</option>
                            <option value="present" <?php echo $filterStatus == 'present' ? 'selected' : ''; ?>>Hadir</option>
                            <option value="excused" <?php echo $filterStatus == 'excused' ? 'selected' : ''; ?>>Pelepasan</option>
                            <option value="absent" <?php echo $filterStatus == 'absent' ? 'selected' : ''; ?>>Tidak Hadir</option>
                            <option value="late" <?php echo $filterStatus == 'late' ? 'selected' : ''; ?>>Lewat</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="filter-btn">
                        <i class="fas fa-filter"></i> Tapis
                    </button>
                </form>
            </div>
            
            <!-- STATISTICS -->
            <div class="stats-grid">
                <div class="stat-badge stat-total">
                    <div class="stat-count"><?php echo $stats['total_count'] ?? 0; ?></div>
                    <div class="stat-label">Jumlah</div>
                </div>
                
                <div class="stat-badge stat-present">
                    <div class="stat-count"><?php echo $stats['present_count'] ?? 0; ?></div>
                    <div class="stat-label">Hadir</div>
                </div>
                
                <div class="stat-badge stat-excused">
                    <div class="stat-count"><?php echo $stats['excused_count'] ?? 0; ?></div>
                    <div class="stat-label">Pelepasan</div>
                </div>
                
                <div class="stat-badge stat-absent">
                    <div class="stat-count"><?php echo $stats['absent_count'] ?? 0; ?></div>
                    <div class="stat-label">Tidak Hadir</div>
                </div>
            </div>
            
            <!-- HISTORY LIST -->
            <div class="history-list">
                <?php if ($attendanceHistory->num_rows > 0): ?>
                    <?php while($record = $attendanceHistory->fetch_assoc()): 
                        $status = $record['status'];
                        $color = $statusColors[$status] ?? ['#e2e8f0', '#718096', '❓'];
                    ?>
                        <div class="history-item" style="border-left-color: <?php echo $color[1]; ?>;">
                            <div class="history-header">
                                <div class="history-date">
                                    <?php echo $record['formatted_date']; ?>
                                </div>
                                <div class="history-status" style="background: <?php echo $color[0]; ?>; color: <?php echo $color[1]; ?>;">
                                    <?php echo $color[2]; ?> <?php echo $record['status_malay']; ?>
                                </div>
                            </div>
                            
                            <div class="history-details">
                                <div>
                                    <i class="fas fa-dumbbell"></i>
                                    <strong><?php echo htmlspecialchars($record['training_type']); ?></strong>
                                </div>
                                
                                <div>
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($record['location']); ?>
                                    • <?php echo $sessionTimeLabels[$record['session_time']] ?? $record['session_time']; ?>
                                </div>
                                
                                <?php if ($status === 'excused' && !empty($record['checked_by_name'])): ?>
                                <div>
                                    <i class="fas fa-user-check"></i>
                                    Dilulus oleh: <?php echo htmlspecialchars($record['checked_by_name']); ?>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($record['reason'])): ?>
                                <div>
                                    <i class="fas fa-comment"></i>
                                    Sebab: <?php echo htmlspecialchars(substr($record['reason'], 0, 50)); ?>
                                    <?php if (strlen($record['reason']) > 50) echo '...'; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div>
                                    <i class="fas fa-clock"></i>
                                    Direkod: <?php echo date('H:i', strtotime($record['recorded_at'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-history">
                        <i class="fas fa-calendar-times"></i>
                        <p>Tiada rekod kehadiran</p>
                        <small>Kehadiran akan muncul selepas direkod oleh rankholder</small>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- EXPORT BUTTON -->
            <?php if ($attendanceHistory->num_rows > 0): ?>
            <button class="export-btn" onclick="exportHistory()">
                <i class="fas fa-download"></i> Export Rekod
            </button>
            <?php endif; ?>
        </div>
        
        <!-- FOOTER NAV -->
        <nav class="mobile-footer">
            <a href="dashboard.php" class="nav-item">
                <div class="nav-icon">
                    <i class="fas fa-home"></i>
                </div>
                <div class="nav-label">Utama</div>
            </a>
            
            <a href="performance.php" class="nav-item">
                <div class="nav-icon">
                    <i class="fas fa-chart-bar"></i>
                </div>
                <div class="nav-label">Prestasi</div>
            </a>
            
            <a href="attendance_history.php" class="nav-item active">
                <div class="nav-icon">
                    <i class="fas fa-history"></i>
                </div>
                <div class="nav-label">Kehadiran</div>
            </a>
            
            <a href="allowance.php" class="nav-item">
                <div class="nav-icon">
                    <i class="fas fa-money-bill"></i>
                </div>
                <div class="nav-label">Elaun</div>
            </a>
            
            <a href="profile.php" class="nav-item">
                <div class="nav-icon">
                    <i class="fas fa-user"></i>
                </div>
                <div class="nav-label">Profil</div>
            </a>
        </nav>
    </div>
    
    <script>
        function goBack() {
            if (document.referrer) {
                window.history.back();
            } else {
                window.location.href = 'dashboard.php';
            }
        }
        
        function exportHistory() {
            const month = document.querySelector('select[name="month"]').value;
            const status = document.querySelector('select[name="status"]').value;
            
            // Simple CSV export
            let csv = 'Tarikh,Jenis Latihan,Tempat,Sesi,Status,Nota\n';
            
            document.querySelectorAll('.history-item').forEach(item => {
                const date = item.querySelector('.history-date').textContent.trim();
                const details = item.querySelectorAll('.history-details div');
                const training = details[0]?.querySelector('strong')?.textContent || '';
                const location = details[1]?.textContent.split('•')[0]?.replace('📍', '').trim() || '';
                const session = details[1]?.textContent.split('•')[1]?.trim() || '';
                const status = item.querySelector('.history-status').textContent.replace(/[✅📄❌⏰❓]/g, '').trim();
                const reason = details.find(d => d.textContent.includes('Sebab:'))?.textContent.replace('Sebab:', '').trim() || '';
                
                csv += `"${date}","${training}","${location}","${session}","${status}","${reason}"\n`;
            });
            
            // Create download link
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `kehadiran_${month}_${status}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            showToast('Fail CSV berjaya dimuat turun', 'success');
        }
        
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                left: 16px;
                right: 16px;
                padding: 15px;
                background: ${type === 'success' ? '#48bb78' : '#f56565'};
                color: white;
                border-radius: 10px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
                z-index: 1000;
                text-align: center;
                animation: slideDown 0.3s ease-out;
            `;
            
            toast.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i>
                ${message}
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideUp 0.3s ease-out';
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 3000);
        }
    </script>
</body>
</html>
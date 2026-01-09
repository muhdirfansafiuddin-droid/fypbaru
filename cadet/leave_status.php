<?php
// cadet/leave_status.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('cadet');
$user = (new Auth())->getCurrentUser();
$db = new Database();

// Get leave requests
$sql = "SELECT a.*, ts.training_type, ts.location, ts.training_date, ts.session_time,
        DATE_FORMAT(a.date, '%d/%m/%Y') as formatted_date,
        u.name as checked_by_name,
        DATE_FORMAT(a.checked_at, '%d/%m/%Y %H:%i') as checked_at_formatted,
        CASE 
            WHEN a.checked_by IS NULL THEN 'Menunggu'
            WHEN a.status = 'excused' THEN 'Diluluskan'
            ELSE 'Ditolak'
        END as status_malay
        FROM attendance a
        JOIN training_sessions ts ON a.session_id = ts.session_id
        LEFT JOIN users u ON a.checked_by = u.user_id
        WHERE a.user_id = ?
        AND a.status = 'excused'
        ORDER BY a.date DESC, a.checked_by IS NULL DESC";

$stmt = $db->prepare($sql);
$stmt->bind_param("i", $user['user_id']);
$stmt->execute();
$leaveRequests = $stmt->get_result();

// Get statistics
$statsSql = "SELECT 
    COUNT(CASE WHEN checked_by IS NULL THEN 1 END) as pending_count,
    COUNT(CASE WHEN checked_by IS NOT NULL AND status = 'excused' THEN 1 END) as approved_count,
    COUNT(CASE WHEN checked_by IS NOT NULL AND status != 'excused' THEN 1 END) as rejected_count,
    COUNT(*) as total_count
    FROM attendance 
    WHERE user_id = ? AND status = 'excused'";

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Status Pelepasan - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* SAME CSS AS DASHBOARD */
        .leave-container {
            padding: 16px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .stat-icon {
            font-size: 1.5rem;
            margin-bottom: 8px;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 5px 0;
        }
        
        .stat-pending .stat-icon { color: var(--warning); }
        .stat-approved .stat-icon { color: var(--success); }
        .stat-rejected .stat-icon { color: var(--danger); }
        
        .stat-label {
            font-size: 0.8rem;
            color: #718096;
        }
        
        .leave-list {
            margin-top: 20px;
        }
        
        .leave-item {
            background: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 15px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-left: 4px solid;
        }
        
        .leave-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .leave-date {
            font-weight: 700;
            color: var(--primary);
            font-size: 1.1rem;
        }
        
        .leave-status {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-approved {
            background: #d4edda;
            color: #155724;
        }
        
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }
        
        .leave-details {
            font-size: 0.9rem;
            color: #718096;
        }
        
        .leave-details div {
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .reason-box {
            background: #f7fafc;
            padding: 12px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 0.9rem;
        }
        
        .proof-link {
            display: inline-block;
            margin-top: 8px;
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #a0aec0;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .info-note {
            background: #e8f4fe;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 0.9rem;
            color: var(--primary);
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
                <h1 class="user-name">Status Pelepasan</h1>
                <p style="opacity: 0.9; font-size: 0.9rem;">
                    <i class="fas fa-file-medical"></i> Permohonan & Kelulusan Pelepasan
                </p>
            </div>
        </header>
        
        <div class="leave-container">
            <!-- STATS -->
            <div class="stats-grid">
                <div class="stat-card stat-pending">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['pending_count'] ?? 0; ?></div>
                    <div class="stat-label">Menunggu</div>
                </div>
                
                <div class="stat-card stat-approved">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['approved_count'] ?? 0; ?></div>
                    <div class="stat-label">Diluluskan</div>
                </div>
                
                <div class="stat-card stat-rejected">
                    <div class="stat-icon">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['rejected_count'] ?? 0; ?></div>
                    <div class="stat-label">Ditolak</div>
                </div>
            </div>
            
            <!-- LEAVE REQUESTS -->
            <div class="leave-list">
                <?php if ($leaveRequests->num_rows > 0): ?>
                    <?php while($leave = $leaveRequests->fetch_assoc()): 
                        $isPending = $leave['checked_by'] === null;
                        $isApproved = !$isPending && $leave['status'] === 'excused';
                        $statusClass = $isPending ? 'status-pending' : 
                                      ($isApproved ? 'status-approved' : 'status-rejected');
                    ?>
                        <div class="leave-item" style="border-left-color: <?php 
                            echo $isPending ? '#ffc107' : 
                                 ($isApproved ? '#28a745' : '#dc3545'); 
                        ?>;">
                            <div class="leave-header">
                                <div class="leave-date"><?php echo $leave['formatted_date']; ?></div>
                                <div class="leave-status <?php echo $statusClass; ?>">
                                    <?php echo $leave['status_malay']; ?>
                                </div>
                            </div>
                            
                            <div class="leave-details">
                                <div>
                                    <i class="fas fa-dumbbell"></i>
                                    <strong><?php echo htmlspecialchars($leave['training_type']); ?></strong>
                                </div>
                                
                                <div>
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($leave['location']); ?>
                                    • <?php echo $sessionTimeLabels[$leave['session_time']] ?? $leave['session_time']; ?>
                                </div>
                                
                                <?php if (!empty($leave['reason'])): ?>
                                <div class="reason-box">
                                    <strong><i class="fas fa-comment"></i> Sebab:</strong>
                                    <p style="margin-top: 5px;"><?php echo htmlspecialchars($leave['reason']); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($leave['proof_file'])): ?>
                                <div>
                                    <i class="fas fa-paperclip"></i>
                                    <a href="<?php echo htmlspecialchars($leave['proof_file']); ?>" 
                                       target="_blank" 
                                       class="proof-link">
                                        <i class="fas fa-download"></i> Muat Turun Bukti
                                    </a>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!$isPending): ?>
                                <div>
                                    <i class="fas fa-user-check"></i>
                                    <?php echo $isApproved ? 'Dilulus' : 'Ditolak'; ?> oleh: 
                                    <strong><?php echo htmlspecialchars($leave['checked_by_name']); ?></strong>
                                </div>
                                
                                <div>
                                    <i class="fas fa-calendar-check"></i>
                                    Masa: <?php echo $leave['checked_at_formatted']; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-file-medical"></i>
                        <p>Tiada permohonan pelepasan</p>
                        <small>Semua permohonan pelepasan akan muncul di sini</small>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- INFO NOTE -->
            <div class="info-note">
                <h4 style="margin-bottom: 10px; color: var(--primary);">
                    <i class="fas fa-info-circle"></i> Maklumat Penting
                </h4>
                <p><strong>Perhatian Kadet:</strong></p>
                <ul style="padding-left: 20px; margin: 10px 0;">
                    <li>Anda <strong>TIDAK BOLEH</strong> membuat permohonan pelepasan sendiri</li>
                    <li>Rankholder akan memohon pelepasan untuk anda</li>
                    <li>Status kelulusan akan dikemas kini di sini</li>
                    <li>Pastikan rankholder mempunyai bukti yang mencukupi</li>
                </ul>
                <p style="margin-top: 10px; font-style: italic; color: #718096;">
                    Sila hubungi rankholder anda untuk sebarang pertanyaan mengenai pelepasan.
                </p>
            </div>
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
            
            <a href="attendance_history.php" class="nav-item">
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
        
        // Auto-refresh every 30 seconds for pending leaves
        setInterval(() => {
            const pendingItems = document.querySelectorAll('.status-pending');
            if (pendingItems.length > 0) {
                // Check if any pending leaves need updating
                window.location.reload();
            }
        }, 30000);
    </script>
</body>
</html>
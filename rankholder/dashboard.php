<?php
// rankholder/dashboard.php - MOBILE FRIENDLY DASHBOARD
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('rankholder');
$user = (new Auth())->getCurrentUser();
$db = new Database();

// Get rankholder's service type
$serviceType = $user['service_type'] ?? 'darat';

// Get active activities for rankholder's service type
$sql = "SELECT ts.*, u.name as creator_name,
        (SELECT COUNT(*) FROM attendance a WHERE a.session_id = ts.session_id) as attendance_count
        FROM training_sessions ts
        JOIN users u ON ts.created_by = u.user_id
        WHERE ts.is_active = 1 
        AND ts.expires_at > NOW()
        AND (ts.training_date >= CURDATE() OR ts.training_date = CURDATE())
        ORDER BY ts.training_date ASC, ts.session_time
        LIMIT 5";

$stmt = $db->prepare($sql);
$stmt->execute();
$activeActivities = $stmt->get_result();

// Get cadets under this rankholder (same service type)
$cadetsSql = "SELECT user_id, name, military_number, rank_level,
             (SELECT COUNT(*) FROM attendance a WHERE a.user_id = u.user_id AND MONTH(a.date) = MONTH(CURDATE())) as monthly_attendance
             FROM users u
             WHERE role = 'cadet' 
             AND service_type = ?
             ORDER BY rank_level, name";

$cadetsStmt = $db->prepare($cadetsSql);
$cadetsStmt->bind_param("s", $serviceType);
$cadetsStmt->execute();
$cadets = $cadetsStmt->get_result();

// Get today's attendance stats
$todayStatsSql = "SELECT 
                  COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_today,
                  COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent_today,
                  COUNT(CASE WHEN a.status = 'excused' THEN 1 END) as excused_today
                  FROM attendance a
                  JOIN users u ON a.user_id = u.user_id
                  WHERE u.service_type = ? 
                  AND DATE(a.date) = CURDATE()";

$statsStmt = $db->prepare($todayStatsSql);
$statsStmt->bind_param("s", $serviceType);
$statsStmt->execute();
$todayStats = $statsStmt->get_result()->fetch_assoc();

// Session time labels
$sessionTimeLabels = [
    'pagi' => 'Pagi',
    'tengah hari' => 'Tengah Hari', 
    'petang' => 'Petang',
    'malam' => 'Malam'
];

// FIXED: Helper functions to prevent deprecated warnings
function safeHtmlSpecialChars($string) {
    return $string !== null ? htmlspecialchars($string, ENT_QUOTES, 'UTF-8') : '';
}

function safeUcfirst($string) {
    return $string !== null ? ucfirst($string) : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Rankholder Dashboard - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --success: #48bb78;
            --warning: #ed8936;
            --danger: #f56565;
            --darat: #48bb78;
            --laut: #4299e1;
            --udara: #9f7aea;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        body {
            background: #f0f2f5;
            color: #2d3748;
            line-height: 1.6;
            -webkit-tap-highlight-color: transparent;
        }
        
        /* MOBILE CONTAINER */
        .mobile-container {
            max-width: 100%;
            min-height: 100vh;
        }
        
        /* HEADER */
        .mobile-header {
            background: linear-gradient(135deg, var(--primary) 0%, #2c5282 100%);
            color: white;
            padding: 20px 16px;
            position: relative;
            overflow: hidden;
        }
        
        .header-content {
            position: relative;
            z-index: 2;
        }
        
        .greeting {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        
        .user-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .service-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .service-darat { background: rgba(72, 187, 120, 0.2); color: #48bb78; }
        .service-laut { background: rgba(66, 153, 225, 0.2); color: #4299e1; }
        .service-udara { background: rgba(159, 122, 234, 0.2); color: #9f7aea; }
        
        /* LOGOUT BUTTON */
        .logout-btn {
            position: absolute;
            top: 20px;
            right: 16px;
            background: rgba(255,255,255,0.15);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
            cursor: pointer;
            z-index: 3;
        }
        
        /* STATS CARDS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            padding: 16px;
            margin-top: -30px;
            position: relative;
            z-index: 2;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transition: transform 0.2s;
        }
        
        .stat-card:active {
            transform: scale(0.98);
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
        
        .stat-present .stat-icon { color: var(--success); }
        .stat-absent .stat-icon { color: var(--danger); }
        .stat-excused .stat-icon { color: var(--warning); }
        
        .stat-label {
            font-size: 0.8rem;
            color: #718096;
        }
        
        /* QUICK ACTIONS */
        .quick-actions {
            padding: 16px;
        }
        
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .action-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        .action-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        
        .action-card:active {
            transform: scale(0.98);
            border-color: var(--accent);
        }
        
        .action-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--accent);
        }
        
        .action-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--primary);
        }
        
        .action-desc {
            font-size: 0.85rem;
            color: #718096;
        }
        
        /* ACTIVE ACTIVITIES */
        .activities-section {
            padding: 16px;
        }
        
        .activities-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .activity-item {
            background: white;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-left: 4px solid var(--accent);
        }
        
        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .activity-title {
            font-weight: 600;
            color: var(--primary);
            font-size: 1rem;
        }
        
        .attendance-count {
            background: var(--light);
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            color: var(--accent);
            font-weight: 600;
        }
        
        .activity-details {
            font-size: 0.9rem;
            color: #718096;
        }
        
        .activity-details div {
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .go-btn {
            display: block;
            width: 100%;
            margin-top: 12px;
            padding: 10px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
        }
        
        /* CADETS LIST */
        .cadets-section {
            padding: 16px;
        }
        
        .cadets-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
        }
        
        .cadet-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .cadet-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--light);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            color: var(--accent);
            font-weight: 600;
            font-size: 1.2rem;
        }
        
        .cadet-name {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--primary);
        }
        
        .cadet-number {
            font-size: 0.8rem;
            color: #718096;
            margin-bottom: 8px;
        }
        
        .cadet-stats {
            font-size: 0.8rem;
            color: var(--success);
            font-weight: 600;
        }
        
        /* FOOTER NAV */
        .mobile-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            padding: 10px 16px;
            display: flex;
            justify-content: space-around;
            border-top: 1px solid #e2e8f0;
            z-index: 100;
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #718096;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .nav-item.active {
            color: var(--accent);
            background: rgba(49, 130, 206, 0.1);
        }
        
        .nav-icon {
            font-size: 1.2rem;
            margin-bottom: 4px;
        }
        
        .nav-label {
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        /* LOADING */
        .loading {
            display: flex;
            justify-content: center;
            padding: 40px;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #e2e8f0;
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* RESPONSIVE */
        @media (min-width: 768px) {
            .mobile-container {
                max-width: 500px;
                margin: 0 auto;
                box-shadow: 0 0 30px rgba(0,0,0,0.1);
            }
            
            .mobile-footer {
                max-width: 500px;
                left: 50%;
                transform: translateX(-50%);
            }
        }
        
        @media (max-width: 380px) {
            .action-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- MOBILE CONTAINER -->
    <div class="mobile-container">
        <!-- HEADER -->
        <header class="mobile-header">
            <button class="logout-btn" onclick="logout()">
                <i class="fas fa-sign-out-alt"></i>
            </button>
            
            <div class="header-content">
                <div class="greeting">
                    <i class="fas fa-sun"></i> Selamat 
                    <?php
                    $hour = date('H');
                    if ($hour < 12) echo 'Pagi';
                    elseif ($hour < 15) echo 'Tengah Hari';
                    elseif ($hour < 19) echo 'Petang';
                    else echo 'Malam';
                    ?>
                </div>
                <h1 class="user-name"><?php echo safeHtmlSpecialChars($user['name'] ?? ''); ?></h1>
                <div class="user-info">
                    <span><i class="fas fa-id-card"></i> <?php echo safeHtmlSpecialChars($user['military_number'] ?? ''); ?></span>
                    <span class="service-badge service-<?php echo safeHtmlSpecialChars($serviceType); ?>">
                        <i class="fas fa-<?php 
                            echo $serviceType == 'darat' ? 'mountain' : 
                                 ($serviceType == 'laut' ? 'anchor' : 'plane'); 
                        ?>"></i>
                        <?php echo strtoupper(safeHtmlSpecialChars($serviceType)); ?>
                    </span>
                    <span><i class="fas fa-user-shield"></i> Rankholder</span>
                </div>
            </div>
        </header>
        
        <!-- TODAY'S STATS -->
        <div class="stats-grid">
            <div class="stat-card stat-present">
                <div class="stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-number"><?php echo $todayStats['present_today'] ?? 0; ?></div>
                <div class="stat-label">Hadir Hari Ini</div>
            </div>
            
            <div class="stat-card stat-absent">
                <div class="stat-icon">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="stat-number"><?php echo $todayStats['absent_today'] ?? 0; ?></div>
                <div class="stat-label">Tidak Hadir</div>
            </div>
            
            <div class="stat-card stat-excused">
                <div class="stat-icon">
                    <i class="fas fa-file-medical"></i>
                </div>
                <div class="stat-number"><?php echo $todayStats['excused_today'] ?? 0; ?></div>
                <div class="stat-label">Pelepasan</div>
            </div>
        </div>
        
        <!-- QUICK ACTIONS -->
        <div class="quick-actions">
            <h2 class="section-title">
                <i class="fas fa-bolt"></i> Tindakan Pantas
            </h2>
            
            <div class="action-grid">
                <a href="take_attendance.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="action-title">Ambil Kehadiran</div>
                    <div class="action-desc">Rekod kehadiran kadet</div>
                </a>
                
                <a href="view_attendance.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-list-check"></i>
                    </div>
                    <div class="action-title">Lihat Kehadiran</div>
                    <div class="action-desc">Semak rekod kehadiran</div>
                </a>
                
                <a href="cadet_leave.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-file-medical"></i>
                    </div>
                    <div class="action-title">Pelepasan</div>
                    <div class="action-desc">Urus pelepasan kadet</div>
                </a>
                
                <a href="reports.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="action-title">Laporan</div>
                    <div class="action-desc">Lihat statistik</div>
                </a>
            </div>
        </div>
        
        <!-- ACTIVE ACTIVITIES -->
        <div class="activities-section">
            <h2 class="section-title">
                <i class="fas fa-calendar-alt"></i> Aktiviti Aktif
            </h2>
            
            <div class="activities-list">
                <?php if ($activeActivities->num_rows > 0): ?>
                    <?php while($activity = $activeActivities->fetch_assoc()): 
                        $attendanceUrl = "take_attendance.php?activity=" . $activity['session_id'];
                    ?>
                        <div class="activity-item">
                            <div class="activity-header">
                                <div class="activity-title"><?php echo safeHtmlSpecialChars($activity['training_type']); ?></div>
                                <span class="attendance-count">
                                    <i class="fas fa-users"></i> <?php echo $activity['attendance_count']; ?>
                                </span>
                            </div>
                            
                            <div class="activity-details">
                                <div>
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo safeHtmlSpecialChars($activity['location'] ?? ''); ?>
                                </div>
                                <div>
                                    <i class="fas fa-calendar"></i>
                                    <?php echo date('d/m/Y', strtotime($activity['training_date'])); ?>
                                    • <?php echo $sessionTimeLabels[$activity['session_time']] ?? $activity['session_time']; ?>
                                </div>
                                <div>
                                    <i class="fas fa-user-tie"></i>
                                    Dicipta oleh: <?php echo safeHtmlSpecialChars($activity['creator_name'] ?? ''); ?>
                                </div>
                            </div>
                            
                            <a href="<?php echo $attendanceUrl; ?>" class="go-btn">
                                <i class="fas fa-arrow-right"></i> Ambil Kehadiran
                            </a>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 30px; color: #a0aec0;">
                        <i class="fas fa-calendar-times" style="font-size: 2rem; margin-bottom: 10px;"></i>
                        <p>Tiada aktiviti aktif</p>
                        <small>Sila hubungi admin untuk aktiviti baru</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- CADETS UNDER SUPERVISION -->
        <div class="cadets-section">
            <h2 class="section-title">
                <i class="fas fa-users"></i> Kadet Bawah Pengawasan
                <span style="font-size: 0.9rem; color: #718096; margin-left: auto;">
                    <?php echo $cadets->num_rows; ?> kadet
                </span>
            </h2>
            
            <div class="cadets-list">
                <?php if ($cadets->num_rows > 0): ?>
                    <?php while($cadet = $cadets->fetch_assoc()): ?>
                        <div class="cadet-card">
                            <div class="cadet-avatar">
                                <?php echo strtoupper(substr($cadet['name'] ?? '', 0, 1)); ?>
                            </div>
                            <div class="cadet-name"><?php echo safeHtmlSpecialChars($cadet['name'] ?? ''); ?></div>
                            <div class="cadet-number"><?php echo safeHtmlSpecialChars($cadet['military_number'] ?? ''); ?></div>
                            <div class="cadet-stats">
                                <i class="fas fa-calendar-check"></i> <?php echo $cadet['monthly_attendance'] ?? 0; ?> hari
                            </div>
                            <div style="margin-top: 8px;">
                                <span style="font-size: 0.75rem; padding: 2px 8px; background: #e2e8f0; border-radius: 10px;">
                                    <?php echo safeUcfirst($cadet['rank_level'] ?? ''); ?>
                                </span>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 30px; color: #a0aec0;">
                        <i class="fas fa-users-slash" style="font-size: 2rem; margin-bottom: 10px;"></i>
                        <p>Tiada kadet di bawah pengawasan</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- FOOTER NAV -->
        <nav class="mobile-footer">
            <a href="dashboard.php" class="nav-item active">
                <div class="nav-icon">
                    <i class="fas fa-home"></i>
                </div>
                <div class="nav-label">Utama</div>
            </a>
            
            <a href="take_attendance.php" class="nav-item">
                <div class="nav-icon">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div class="nav-label">Kehadiran</div>
            </a>
            
            <a href="cadet_leave.php" class="nav-item">
                <div class="nav-icon">
                    <i class="fas fa-file-medical"></i>
                </div>
                <div class="nav-label">Pelepasan</div>
            </a>
            
            <a href="reports.php" class="nav-item">
                <div class="nav-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="nav-label">Laporan</div>
            </a>
            
            <a href="profile.php" class="nav-item">
                <div class="nav-icon">
                    <i class="fas fa-user"></i>
                </div>
                <div class="nav-label">Profil</div>
            </a>
        </nav>
    </div>
    
    <!-- JAVASCRIPT -->
    <script>
        // Logout function
        function logout() {
            if (confirm('Logout dari sistem?')) {
                window.location.href = '../auth/logout.php';
            }
        }
        
        // Refresh dashboard every 60 seconds
        setInterval(() => {
            // Only refresh if user is active
            if (!document.hidden) {
                window.location.reload();
            }
        }, 60000);
        
        // Pull-to-refresh for dashboard
        let startY = 0;
        
        document.addEventListener('touchstart', function(e) {
            startY = e.touches[0].pageY;
        });
        
        document.addEventListener('touchmove', function(e) {
            const touchY = e.touches[0].pageY;
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            
            // If at top and pulling down hard
            if (scrollTop === 0 && touchY > startY + 100) {
                e.preventDefault();
                showRefreshAnimation();
            }
        });
        
        function showRefreshAnimation() {
            const refreshDiv = document.createElement('div');
            refreshDiv.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                background: var(--accent);
                color: white;
                padding: 15px;
                text-align: center;
                z-index: 1000;
                animation: slideDown 0.3s ease-out;
            `;
            refreshDiv.innerHTML = `
                <i class="fas fa-redo fa-spin"></i> Menyegarkan...
            `;
            document.body.appendChild(refreshDiv);
            
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }
        
        // Handle offline/online
        window.addEventListener('online', () => {
            showToast('Kembali online', 'success');
        });
        
        window.addEventListener('offline', () => {
            showToast('Anda offline', 'warning');
        });
        
        // Toast notification
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
        
        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideDown {
                from { transform: translateY(-100%); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
            @keyframes slideUp {
                from { transform: translateY(0); opacity: 1; }
                to { transform: translateY(-100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
        
        // Vibration on tap (if supported)
        document.addEventListener('DOMContentLoaded', () => {
            const cards = document.querySelectorAll('.action-card, .stat-card');
            cards.forEach(card => {
                card.addEventListener('touchstart', () => {
                    if ('vibrate' in navigator) {
                        navigator.vibrate(10);
                    }
                });
            });
            
            // Keep screen awake
            if ('wakeLock' in navigator) {
                navigator.wakeLock.request('screen');
            }
        });
    </script>
</body>
</html>
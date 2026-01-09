<?php
// cadet/profile.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('cadet');
$user = (new Auth())->getCurrentUser();
$db = new Database();

// Get additional stats
$statsSql = "SELECT 
    COUNT(CASE WHEN status = 'present' THEN 1 END) as total_present,
    COUNT(*) as total_sessions,
    MIN(date) as join_date
    FROM attendance 
    WHERE user_id = ?";

$statsStmt = $db->prepare($statsSql);
$statsStmt->bind_param("i", $user['user_id']);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$totalPresent = $stats['total_present'] ?? 0;
$totalSessions = $stats['total_sessions'] ?? 0;
$joinDate = $stats['join_date'] ?? $user['created_at'];
$attendanceRate = $totalSessions > 0 ? round(($totalPresent / $totalSessions) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Profil Kadet - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* SAME CSS AS DASHBOARD - COPY FROM ABOVE */
        /* Add profile specific styles */
        .profile-container {
            padding: 16px;
        }
        
        .profile-header {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: white;
            font-size: 2.5rem;
            font-weight: 700;
        }
        
        .profile-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin: 20px 0;
        }
        
        .profile-stat {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .profile-info {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #718096;
        }
        
        .info-value {
            font-weight: 600;
            color: var(--primary);
        }
        
        .service-icon {
            font-size: 1.2rem;
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div class="mobile-container">
        <!-- HEADER (SAME AS DASHBOARD) -->
        <header class="mobile-header">
            <button class="back-btn" onclick="goBack()">
                <i class="fas fa-arrow-left"></i>
            </button>
            <div class="header-content">
                <h1 class="user-name">Profil Saya</h1>
            </div>
        </header>
        
        <div class="profile-container">
            <!-- AVATAR & NAME -->
            <div class="profile-header">
                <div class="avatar-large">
                    <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                </div>
                <h2 style="margin-bottom: 5px; color: var(--primary);"><?php echo htmlspecialchars($user['name']); ?></h2>
                <p style="color: #718096; margin-bottom: 20px;">
                    <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($user['military_number']); ?>
                </p>
            </div>
            
            <!-- STATS -->
            <div class="profile-stats">
                <div class="profile-stat">
                    <div style="font-size: 1.8rem; font-weight: 700; color: var(--success);">
                        <?php echo $attendanceRate; ?>%
                    </div>
                    <div style="font-size: 0.85rem; color: #718096;">Rate Kehadiran</div>
                </div>
                
                <div class="profile-stat">
                    <div style="font-size: 1.8rem; font-weight: 700; color: var(--accent);">
                        <?php echo $totalSessions; ?>
                    </div>
                    <div style="font-size: 0.85rem; color: #718096;">Sesi Hadir</div>
                </div>
            </div>
            
            <!-- PERSONAL INFO -->
            <div class="profile-info">
                <h3 style="margin-bottom: 15px; color: var(--primary);">
                    <i class="fas fa-info-circle"></i> Maklumat Peribadi
                </h3>
                
                <div class="info-item">
                    <span class="info-label">Nama Penuh</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['name']); ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Nombor Tentera</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['military_number']); ?></span>
                </div>
                
                <?php if (!empty($user['email'])): ?>
                <div class="info-item">
                    <span class="info-label">Email</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['email']); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="info-item">
                    <span class="info-label">Jenis Servis</span>
                    <span class="info-value">
                        <i class="fas fa-<?php 
                            echo $user['service_type'] == 'darat' ? 'mountain' : 
                                 ($user['service_type'] == 'laut' ? 'anchor' : 'plane'); 
                        ?> service-icon"></i>
                        <?php echo strtoupper(htmlspecialchars($user['service_type'])); ?>
                    </span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Peringkat</span>
                    <span class="info-value">
                        <?php echo ucfirst($user['rank_level']); ?> Cadet
                    </span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Tarikh Daftar</span>
                    <span class="info-value">
                        <?php echo date('d/m/Y', strtotime($joinDate)); ?>
                    </span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Tempoh Perkhidmatan</span>
                    <span class="info-value">
                        <?php
                        $join = new DateTime($joinDate);
                        $now = new DateTime();
                        $interval = $join->diff($now);
                        echo $interval->format('%m bulan, %d hari');
                        ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- FOOTER NAV (SAME AS DASHBOARD) -->
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
            
            <a href="profile.php" class="nav-item active">
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
        
        // SAME JS FUNCTIONS AS DASHBOARD
        document.addEventListener('DOMContentLoaded', function() {
            if ('vibrate' in navigator) {
                document.querySelector('.back-btn').addEventListener('touchstart', () => {
                    navigator.vibrate(10);
                });
            }
        });
    </script>
</body>
</html>
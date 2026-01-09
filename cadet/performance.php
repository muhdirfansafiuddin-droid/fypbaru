<?php
// cadet/performance.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('cadet');
$user = (new Auth())->getCurrentUser();
$db = new Database();

// 1. Get monthly performance for last 6 months
$monthlySql = "SELECT 
    DATE_FORMAT(date, '%Y-%m') as month,
    COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
    COUNT(CASE WHEN status = 'excused' THEN 1 END) as excused,
    COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent,
    COUNT(*) as total,
    ROUND((COUNT(CASE WHEN status = 'present' THEN 1 END) / COUNT(*)) * 100, 1) as rate
    FROM attendance 
    WHERE user_id = ?
    GROUP BY DATE_FORMAT(date, '%Y-%m')
    ORDER BY month DESC LIMIT 6";

$monthlyStmt = $db->prepare($monthlySql);
$monthlyStmt->bind_param("i", $user['user_id']);
$monthlyStmt->execute();
$monthlyData = $monthlyStmt->get_result();

// 2. Get performance by training type
$typeSql = "SELECT 
    ts.training_type,
    COUNT(CASE WHEN a.status = 'present' THEN 1 END) as attended,
    COUNT(*) as total,
    ROUND((COUNT(CASE WHEN a.status = 'present' THEN 1 END) / COUNT(*)) * 100, 1) as rate
    FROM attendance a
    JOIN training_sessions ts ON a.session_id = ts.session_id
    WHERE a.user_id = ?
    GROUP BY ts.training_type
    ORDER BY rate DESC";

$typeStmt = $db->prepare($typeSql);
$typeStmt->bind_param("i", $user['user_id']);
$typeStmt->execute();
$typeData = $typeStmt->get_result();

// 3. Calculate current grade
function calculateGrade($attendanceRate) {
    if ($attendanceRate >= 90) return ['A+', 'Cemerlang Tertinggi', 4.00, '#155724', '#d4edda'];
    if ($attendanceRate >= 80) return ['A', 'Cemerlang', 4.00, '#0f5132', '#d1e7dd'];
    if ($attendanceRate >= 75) return ['A-', 'Kepujian Tinggi', 3.67, '#0c4128', '#badbcc'];
    if ($attendanceRate >= 70) return ['B+', 'Kepujian', 3.33, '#38761d', '#d9ead3'];
    if ($attendanceRate >= 65) return ['B', 'Kepujian', 3.00, '#274e13', '#cfe2b5'];
    if ($attendanceRate >= 60) return ['B-', 'Lulus Baik', 2.67, '#1c3b0a', '#b6d7a8'];
    if ($attendanceRate >= 55) return ['C+', 'Lulus', 2.33, '#783f04', '#fce5cd'];
    if ($attendanceRate >= 50) return ['C', 'Lulus Minimum', 2.00, '#674ea7', '#d9d2e9'];
    if ($attendanceRate >= 45) return ['C-', 'Lulus Bersyarat', 1.67, '#351c75', '#c9c2e6'];
    if ($attendanceRate >= 40) return ['D+', 'Lulus Lemah', 1.33, '#741b47', '#f4cccc'];
    if ($attendanceRate >= 35) return ['D', 'Lulus Lemah', 1.00, '#5b0f00', '#ea9999'];
    return ['E/F', 'Gagal', 0.00, '#660000', '#f8d7da'];
}

// Get overall attendance rate
$overallSql = "SELECT 
    COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
    COUNT(*) as total
    FROM attendance 
    WHERE user_id = ?";
$overallStmt = $db->prepare($overallSql);
$overallStmt->bind_param("i", $user['user_id']);
$overallStmt->execute();
$overall = $overallStmt->get_result()->fetch_assoc();

$overallRate = $overall['total'] > 0 ? round(($overall['present'] / $overall['total']) * 100) : 0;
$gradeInfo = calculateGrade($overallRate);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Prestasi Kadet - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* SAME CSS AS DASHBOARD */
        /* Add performance specific styles */
        .performance-container {
            padding: 16px;
        }
        
        .current-grade {
            background: <?php echo $gradeInfo[4]; ?>;
            color: <?php echo $gradeInfo[3]; ?>;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 20px;
            border: 2px solid <?php echo $gradeInfo[3]; ?>;
        }
        
        .grade-big {
            font-size: 3.5rem;
            font-weight: 900;
            margin-bottom: 5px;
        }
        
        .progress-bar {
            height: 20px;
            background: #e2e8f0;
            border-radius: 10px;
            margin: 15px 0;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: <?php echo $gradeInfo[3]; ?>;
            border-radius: 10px;
            width: <?php echo $overallRate; ?>%;
            transition: width 0.5s ease;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            color: #718096;
            margin-bottom: 5px;
        }
        
        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--primary);
        }
        
        .month-item {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
            padding: 10px;
            background: #f7fafc;
            border-radius: 8px;
        }
        
        .month-label {
            min-width: 80px;
            font-weight: 600;
            color: var(--primary);
        }
        
        .month-bar {
            flex: 1;
            height: 20px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            margin: 0 10px;
        }
        
        .month-fill {
            height: 100%;
            background: var(--success);
            border-radius: 10px;
        }
        
        .month-rate {
            min-width: 50px;
            text-align: right;
            font-weight: 600;
            color: var(--primary);
        }
        
        .type-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .type-item:last-child {
            border-bottom: none;
        }
        
        .type-name {
            font-weight: 600;
            color: var(--primary);
        }
        
        .type-stats {
            text-align: right;
        }
        
        .type-attendance {
            font-weight: 700;
            color: var(--success);
        }
        
        .type-count {
            font-size: 0.85rem;
            color: #718096;
        }
        
        .improvement-tips {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            border-left: 4px solid #ffc107;
        }
        
        .improvement-tips h4 {
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
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
                <h1 class="user-name">Prestasi Saya</h1>
                <p style="opacity: 0.9; font-size: 0.9rem;">
                    <i class="fas fa-chart-line"></i> Laporan Prestasi Terperinci
                </p>
            </div>
        </header>
        
        <div class="performance-container">
            <!-- CURRENT GRADE -->
            <div class="current-grade">
                <div class="grade-big"><?php echo $gradeInfo[0]; ?></div>
                <div style="font-size: 1.2rem; font-weight: 600; margin-bottom: 5px;">
                    <?php echo $gradeInfo[1]; ?>
                </div>
                <div style="font-size: 1rem; margin-bottom: 10px;">
                    Grade Point: <strong><?php echo $gradeInfo[2]; ?></strong>
                </div>
                
                <div class="progress-label">
                    <span>Kehadiran Keseluruhan</span>
                    <span><?php echo $overallRate; ?>%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
                
                <div style="font-size: 0.9rem; margin-top: 10px;">
                    <i class="fas fa-calendar-check"></i> 
                    <?php echo $overall['present'] ?? 0; ?> dari <?php echo $overall['total'] ?? 0; ?> sesi
                </div>
            </div>
            
            <!-- MONTHLY TREND -->
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fas fa-chart-line"></i> Trend Bulanan
                </h3>
                
                <?php if ($monthlyData->num_rows > 0): ?>
                    <?php while($month = $monthlyData->fetch_assoc()): 
                        $monthName = date('M Y', strtotime($month['month'] . '-01'));
                        $rate = $month['rate'];
                    ?>
                        <div class="month-item">
                            <div class="month-label"><?php echo $monthName; ?></div>
                            <div class="month-bar">
                                <div class="month-fill" style="width: <?php echo $rate; ?>%"></div>
                            </div>
                            <div class="month-rate"><?php echo $rate; ?>%</div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 30px; color: #a0aec0;">
                        <i class="fas fa-chart-line"></i>
                        <p>Tiada data prestasi</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- BY TRAINING TYPE -->
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fas fa-list-check"></i> Mengikut Jenis Latihan
                </h3>
                
                <?php if ($typeData->num_rows > 0): ?>
                    <?php while($type = $typeData->fetch_assoc()): ?>
                        <div class="type-item">
                            <div class="type-name"><?php echo htmlspecialchars($type['training_type']); ?></div>
                            <div class="type-stats">
                                <div class="type-attendance"><?php echo $type['rate']; ?>%</div>
                                <div class="type-count"><?php echo $type['attended']; ?>/<?php echo $type['total']; ?> sesi</div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 20px; color: #a0aec0;">
                        Tiada data mengikut jenis latihan
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- IMPROVEMENT TIPS -->
            <?php if ($overallRate < 80): ?>
            <div class="improvement-tips">
                <h4>
                    <i class="fas fa-lightbulb"></i> Cadangan Penambahbaikan
                </h4>
                <ul style="padding-left: 20px; margin: 0;">
                    <?php if ($overallRate < 60): ?>
                        <li>Hadir semua sesi latihan wajib</li>
                        <li>Beritahu rankholder awal jika tidak dapat hadir</li>
                        <li>Sediakan dokumentasi pelepasan yang lengkap</li>
                    <?php endif; ?>
                    <?php if ($overallRate >= 60 && $overallRate < 80): ?>
                        <li>Tingkatkan kehadiran ke 80% untuk gred A</li>
                        <li>Pastikan tiada kehadiran lewat</li>
                        <li>Libatkan diri aktif dalam semua latihan</li>
                    <?php endif; ?>
                </ul>
            </div>
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
            
            <a href="performance.php" class="nav-item active">
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
        
        // Animate progress bars
        document.addEventListener('DOMContentLoaded', function() {
            const progressBars = document.querySelectorAll('.progress-fill, .month-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        });
    </script>
</body>
</html>
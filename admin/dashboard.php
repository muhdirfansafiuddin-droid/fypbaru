<?php
// admin/dashboard.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// UPDATE PATH INI:
require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('admin');
$auth = new Auth();
$user = $auth->getCurrentUser();
$db = new Database();

if (isset($_GET['page'])) {
    $page = $_GET['page'];
    
    switch($page) {
        case 'generate_qr':
            require 'generate_qr.php';
            exit();
        case 'list_cadets':
            require 'list_cadets.php';
            exit(); }
        }

// Helper function to format time
function timeAgo($timestamp) {
    if (empty($timestamp)) return "No data";
    
    $time = strtotime($timestamp);
    $timeDiff = time() - $time;
    
    if ($timeDiff < 60) {
        return "Baru sahaja";
    } elseif ($timeDiff < 3600) {
        $mins = floor($timeDiff / 60);
        return "$mins minit" . ($mins > 1 ? "" : "") . " lepas";
    } elseif ($timeDiff < 86400) {
        $hours = floor($timeDiff / 3600);
        return "$hours jam" . ($hours > 1 ? "" : "") . " lepas";
    } elseif ($timeDiff < 604800) {
        $days = floor($timeDiff / 86400);
        return "$days hari" . ($days > 1 ? "" : "") . " lepas";
    } else {
        return date('d M Y, h:i A', $time);
    }
}

// Fetch statistics
$stats = [];

// 1. Total cadets
$sql1 = "SELECT COUNT(*) as total FROM users WHERE role = 'cadet'";
$stmt1 = $db->prepare($sql1);
$stmt1->execute();
$result1 = $stmt1->get_result();
$stats['cadets'] = $result1->fetch_assoc()['total'] ?? 0;

// 2. Total training sessions this month
$sql2 = "SELECT COUNT(*) as total FROM training_sessions 
        WHERE MONTH(training_date) = MONTH(CURRENT_DATE()) 
        AND YEAR(training_date) = YEAR(CURRENT_DATE())";
$stmt2 = $db->prepare($sql2);
$stmt2->execute();
$result2 = $stmt2->get_result();
$stats['sessions'] = $result2->fetch_assoc()['total'] ?? 0;

// 3. Pending leave requests
$sql3 = "SELECT COUNT(*) as total FROM attendance 
        WHERE status = 'excused' AND checked_by IS NULL";
$stmt3 = $db->prepare($sql3);
$stmt3->execute();
$result3 = $stmt3->get_result();
$stats['pending_leaves'] = $result3->fetch_assoc()['total'] ?? 0;

// 4. Average attendance rate
$sql4 = "SELECT AVG(attendance_rate) as avg FROM allowance_calculations 
        WHERE month_year = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')";
$stmt4 = $db->prepare($sql4);
$stmt4->execute();
$result4 = $stmt4->get_result();
$stats['avg_attendance'] = $result4->fetch_assoc()['avg'] ?? 0;

// 5. Total attendance today
$sql5 = "SELECT COUNT(*) as total FROM attendance 
        WHERE DATE(date) = CURDATE() AND status = 'present'";
$stmt5 = $db->prepare($sql5);
$stmt5->execute();
$result5 = $stmt5->get_result();
$stats['attendance_today'] = $result5->fetch_assoc()['total'] ?? 0;

// Fetch latest activities
// 1. Latest registered cadets
$sql6 = "SELECT name, created_at FROM users 
        WHERE role = 'cadet' 
        ORDER BY created_at DESC LIMIT 1";
$stmt6 = $db->prepare($sql6);
$stmt6->execute();
$latestCadet = $stmt6->get_result()->fetch_assoc();

// 2. Latest training sessions
$sql7 = "SELECT location, training_type, created_at 
        FROM training_sessions 
        ORDER BY created_at DESC LIMIT 1";
$stmt7 = $db->prepare($sql7);
$stmt7->execute();
$latestSession = $stmt7->get_result()->fetch_assoc();

// 3. Latest attendance updates
$sql8 = "SELECT a.recorded_at, u.name, ts.training_type, ts.location
        FROM attendance a
        JOIN users u ON a.user_id = u.user_id
        JOIN training_sessions ts ON a.session_id = ts.session_id
        WHERE a.checked_by IS NOT NULL
        ORDER BY a.recorded_at DESC LIMIT 1";
$stmt8 = $db->prepare($sql8);
$stmt8->execute();
$latestAttendance = $stmt8->get_result()->fetch_assoc();

// 4. Latest leave approvals
$sql9 = "SELECT a.reason, a.checked_at, u.name, a.status
        FROM attendance a
        JOIN users u ON a.user_id = u.user_id
        WHERE a.status = 'excused' AND a.checked_by IS NOT NULL
        ORDER BY a.checked_at DESC LIMIT 1";
$stmt9 = $db->prepare($sql9);
$stmt9->execute();
$latestLeave = $stmt9->get_result()->fetch_assoc();

// 5. Latest allowance calculations
$sql10 = "SELECT ac.calculated_at, u.name, ac.total_amount 
         FROM allowance_calculations ac
         JOIN users u ON ac.user_id = u.user_id
         ORDER BY ac.calculated_at DESC LIMIT 1";
$stmt10 = $db->prepare($sql10);
$stmt10->execute();
$latestAllowance = $stmt10->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - CAAMS</title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        /* HEADER */
        .dashboard-header {
            background: var(--primary);
            color: white;
            padding: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .dashboard-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }
        
        .system-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
            letter-spacing: 1px;
        }
        
        .system-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-bottom: 20px;
        }
        
        .user-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255,255,255,0.1);
            padding: 15px 20px;
            border-radius: 10px;
            backdrop-filter: blur(10px);
        }
        
        .user-details h3 {
            margin-bottom: 5px;
            font-size: 1.3rem;
        }
        
        .user-details p {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .logout-btn {
            background: var(--accent);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .logout-btn:hover {
            background: #2c5282;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        /* STATISTICS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            padding: 0 30px;
            margin-top: -25px;
            position: relative;
            z-index: 1;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px 20px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            border-top: 5px solid;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.cadets { border-color: var(--accent); }
        .stat-card.sessions { border-color: var(--warning); }
        .stat-card.pending { border-color: var(--danger); }
        .stat-card.attendance { border-color: var(--success); }
        .stat-card.rate { border-color: var(--purple); }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        
        .stat-card.cadets .stat-icon { color: var(--accent); }
        .stat-card.sessions .stat-icon { color: var(--warning); }
        .stat-card.pending .stat-icon { color: var(--danger); }
        .stat-card.attendance .stat-icon { color: var(--success); }
        .stat-card.rate .stat-icon { color: var(--purple); }
        
        .stat-number {
            font-size: 2.8rem;
            font-weight: 700;
            color: var(--primary);
            margin: 10px 0;
        }
        
        .stat-label {
            color: var(--secondary);
            font-size: 0.95rem;
            font-weight: 600;
        }
        
        /* MAIN CONTENT */
        .dashboard-main {
            padding: 60px 30px 40px;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 40px;
        }
        
        .section-title {
            color: var(--primary);
            font-size: 1.8rem;
            margin-bottom: 25px;
            padding-bottom: 10px;
            border-bottom: 3px solid var(--accent);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: var(--accent);
        }
        
        /* FUNCTIONS GRID */
        .functions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .function-card {
            background: var(--light);
            border-radius: 15px;
            padding: 25px;
            transition: all 0.3s;
            border: 2px solid transparent;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .function-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .card-icon {
            font-size: 2.5rem;
            color: var(--accent);
            margin-bottom: 15px;
        }
        
        .card-title {
            font-size: 1.3rem;
            color: var(--primary);
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .card-desc {
            color: var(--secondary);
            line-height: 1.5;
            font-size: 0.95rem;
        }
        
        /* ACTIVITY FEED */
        .activity-feed {
            background: var(--light);
            border-radius: 15px;
            padding: 25px;
            height: fit-content;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 20px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        
        .activity-content h4 {
            color: var(--primary);
            margin-bottom: 5px;
            font-size: 1rem;
        }
        
        .activity-time {
            color: #718096;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .activity-time i {
            font-size: 0.8rem;
        }
        
        /* FOOTER */
        .dashboard-footer {
            background: var(--secondary);
            color: white;
            padding: 25px 30px;
            text-align: center;
            border-top: 5px solid var(--accent);
        }
        
        .footer-text {
            opacity: 0.9;
            line-height: 1.6;
        }
        
        /* RESPONSIVE */
        @media (max-width: 1100px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 992px) {
            .dashboard-main {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .functions-grid {
                grid-template-columns: 1fr;
            }
            
            .user-info {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .system-title {
                font-size: 2rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- HEADER -->
        <header class="dashboard-header">
            <h1 class="system-title">CAAMS</h1>
            <p class="system-subtitle">Centralized Attendance & Allowance Management System</p>
            
            <div class="user-info">
                <div class="user-details">
                    <h3>Welcome, <?php echo htmlspecialchars($user['name']); ?></h3>
                    <p>Admin ID: <?php echo htmlspecialchars($user['military_number']); ?> | Role: Administrator</p>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </header>
        
        <!-- STATISTICS -->
        <div class="stats-grid">
            <div class="stat-card cadets">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number"><?php echo $stats['cadets']; ?></div>
                <div class="stat-label">Total Kadet</div>
            </div>
            
            <div class="stat-card sessions">
                <div class="stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-number"><?php echo $stats['sessions']; ?></div>
                <div class="stat-label">Sesi Bulan Ini</div>
            </div>
            
            <div class="stat-card pending">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-number"><?php echo $stats['pending_leaves']; ?></div>
                <div class="stat-label">Pelepasan Tunggu</div>
            </div>
            
            <div class="stat-card attendance">
                <div class="stat-icon">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div class="stat-number"><?php echo $stats['attendance_today']; ?></div>
                <div class="stat-label">Kehadiran Hari Ini</div>
            </div>
            
            <div class="stat-card rate">
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['avg_attendance'], 1); ?>%</div>
                <div class="stat-label">Purata Kehadiran</div>
            </div>
        </div>
        
        <!-- MAIN CONTENT -->
        <main class="dashboard-main">
            <!-- LEFT COLUMN: Functions -->
            <div class="functions-section">
                <h2 class="section-title">
                    <i class="fas fa-sliders-h"></i> Dashboard
                </h2>
                
                <div class="functions-grid">
                    <a href="register_user.php" class="function-card">
                        <div class="card-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h3 class="card-title">Urus Pengguna</h3>
                        <p class="card-desc">Register new users, update user information, and manage user roles and permissions.</p>
                    </a>
                    
                  <a href="jana_aktiviti.php" class="function-card">
                    <div class="card-icon">
                 <i class="fas fa-qrcode"></i>
                     </div>
                 <h3 class="card-title">Jana Aktiviti</h3>
                 <p class="card-desc">Generate dynamic QR codes for training sessions.</p>
                    </a>
                    
                    <a href="manage_attendance.php" class="function-card">
                        <div class="card-icon">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <h3 class="card-title">Urus Kehadiran</h3>
                        <p class="card-desc">View, verify, and manage cadet attendance records from all training sessions.</p>
                    </a>
                    
                    <a href="manage_leave.php" class="function-card">
                        <div class="card-icon">
                            <i class="fas fa-file-medical"></i>
                        </div>
                        <h3 class="card-title">Urus Pelepasan</h3>
                        <p class="card-desc">Review and approve/reject cadet leave requests with proof documentation.</p>
                    </a>
                    
                    <a href="manage_allowance.php" class="function-card">
                        <div class="card-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <h3 class="card-title">Urus Elaun</h3>
                        <p class="card-desc">Calculate and manage cadet allowances based on attendance and performance.</p>
                    </a>
                    
                    <a href="reports.php" class="function-card">
                        <div class="card-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h3 class="card-title">Laporan Akhir</h3>
                        <p class="card-desc">Generate comprehensive reports and export data for analysis and record-keeping.</p>
                    </a>

                    <a href="list_cadets.php" class="function-card">
    <div class="card-icon">
        <i class="fas fa-users"></i>
    </div>
    <h3 class="card-title">Senarai Kadet</h3>
    <p class="card-desc">View and filter cadets by service type and rank level with statistics.</p>
</a>
                </div>
            </div>
            
            <!-- RIGHT COLUMN: Activity Feed -->
            <div class="activity-section">
                <h2 class="section-title">
                    <i class="fas fa-history"></i> Aktiviti Terkini
                </h2>
                
                <div class="activity-feed">
                    <!-- Activity 1: Latest cadet registered -->
                    <div class="activity-item">
                        <div class="activity-icon" style="background: #4299e1;">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="activity-content">
                            <h4>
                                <?php if ($latestCadet): ?>
                                    Kadet <strong><?php echo htmlspecialchars($latestCadet['name']); ?></strong> didaftarkan
                                <?php else: ?>
                                    Tiada kadet didaftarkan
                                <?php endif; ?>
                            </h4>
                            <p class="activity-time">
                                <i class="far fa-clock"></i>
                                <?php echo $latestCadet ? timeAgo($latestCadet['created_at']) : 'No data'; ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Activity 2: Latest training session created -->
                    <div class="activity-item">
                        <div class="activity-icon" style="background: #ed8936;">
                            <i class="fas fa-qrcode"></i>
                        </div>
                        <div class="activity-content">
                            <h4>
                                <?php if ($latestSession): ?>
                                    Kod QR untuk <strong><?php echo htmlspecialchars($latestSession['training_type']); ?></strong>
                                    <?php if ($latestSession['location']): ?>
                                        di <strong><?php echo htmlspecialchars($latestSession['location']); ?></strong>
                                    <?php endif; ?>
                                    dijana
                                <?php else: ?>
                                    Tiada sesi latihan dijana
                                <?php endif; ?>
                            </h4>
                            <p class="activity-time">
                                <i class="far fa-clock"></i>
                                <?php echo $latestSession ? timeAgo($latestSession['created_at']) : 'No data'; ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Activity 3: Latest attendance update -->
                    <div class="activity-item">
                        <div class="activity-icon" style="background: #48bb78;">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <div class="activity-content">
                            <h4>
                                <?php if ($latestAttendance): ?>
                                    Kehadiran <strong><?php echo htmlspecialchars($latestAttendance['name']); ?></strong> 
                                    <?php if ($latestAttendance['training_type']): ?>
                                        untuk <strong><?php echo htmlspecialchars($latestAttendance['training_type']); ?></strong>
                                    <?php endif; ?>
                                    dikemas kini
                                <?php else: ?>
                                    Tiada kehadiran dikemas kini
                                <?php endif; ?>
                            </h4>
                            <p class="activity-time">
                                <i class="far fa-clock"></i>
                                <?php echo $latestAttendance ? timeAgo($latestAttendance['recorded_at']) : 'No data'; ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Activity 4: Latest leave approval -->
                    <div class="activity-item">
                        <div class="activity-icon" style="background: #9f7aea;">
                            <i class="fas fa-file-medical"></i>
                        </div>
                        <div class="activity-content">
                            <h4>
                                <?php if ($latestLeave): ?>
                                    Pelepasan <strong><?php echo htmlspecialchars($latestLeave['name']); ?></strong> diluluskan
                                    <?php if ($latestLeave['reason']): ?>
                                        <br><small><?php echo htmlspecialchars(substr($latestLeave['reason'], 0, 50)); ?><?php echo strlen($latestLeave['reason']) > 50 ? '...' : ''; ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Tiada pelepasan diluluskan
                                <?php endif; ?>
                            </h4>
                            <p class="activity-time">
                                <i class="far fa-clock"></i>
                                <?php echo $latestLeave ? timeAgo($latestLeave['checked_at']) : 'No data'; ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Activity 5: Latest allowance calculation -->
                    <div class="activity-item">
                        <div class="activity-icon" style="background: #f56565;">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="activity-content">
                            <h4>
                                <?php if ($latestAllowance): ?>
                                    Elaun <strong><?php echo htmlspecialchars($latestAllowance['name']); ?></strong> dikira: 
                                    <strong>RM <?php echo number_format($latestAllowance['total_amount'], 2); ?></strong>
                                <?php else: ?>
                                    Tiada elaun dikira
                                <?php endif; ?>
                            </h4>
                            <p class="activity-time">
                                <i class="far fa-clock"></i>
                                <?php echo $latestAllowance ? timeAgo($latestAllowance['calculated_at']) : 'No data'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        
        <!-- FOOTER -->
        <footer class="dashboard-footer">
            <p class="footer-text">
                CAAMS Dashboard Admin<br>
                Markas PALAPES, Universiti Pertahanan Nasional Malaysia<br>
                &copy; 2026 Centralized Attendance & Allowance Management System
            </p>
        </footer>
    </div>
    
    <script>
        // Auto-refresh activity feed every 60 seconds
        setTimeout(() => {
            location.reload();
        }, 60000);
        
        // Card hover effects
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.function-card');
            
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
            
            // Update current time
            function updateTime() {
                const now = new Date();
                const options = { 
                    weekday: 'long', 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: true
                };
                // You can display this somewhere if needed
                console.log('Last updated: ' + now.toLocaleDateString('en-MY', options));
            }
            
            updateTime();
        });
    </script>
</body>
</html>
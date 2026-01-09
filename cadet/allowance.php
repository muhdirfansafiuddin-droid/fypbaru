<?php
// cadet/allowance.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('cadet');
$user = (new Auth())->getCurrentUser();
$db = new Database();

// Get allowance history
$sql = "SELECT ac.*, 
        (SELECT name FROM users WHERE user_id = ac.calculated_by) as calculated_by_name
        FROM allowance_calculations ac
        WHERE ac.user_id = ?
        ORDER BY ac.month_year DESC";

$stmt = $db->prepare($sql);
$stmt->bind_param("i", $user['user_id']);
$stmt->execute();
$allowanceHistory = $stmt->get_result();

// Get total allowance received
$totalSql = "SELECT 
    COUNT(*) as months_count,
    SUM(total_amount) as total_received,
    AVG(attendance_rate) as avg_rate,
    AVG(performance_bonus) as avg_bonus
    FROM allowance_calculations 
    WHERE user_id = ?";

$totalStmt = $db->prepare($totalSql);
$totalStmt->bind_param("i", $user['user_id']);
$totalStmt->execute();
$totals = $totalStmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Elaun Kadet - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* SAME CSS AS DASHBOARD */
        .allowance-container {
            padding: 16px;
        }
        
        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-top: 4px solid var(--warning);
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 15px;
        }
        
        .summary-item {
            text-align: center;
            padding: 15px;
            background: #f7fafc;
            border-radius: 8px;
        }
        
        .summary-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .summary-label {
            font-size: 0.85rem;
            color: #718096;
        }
        
        .allowance-list {
            margin-top: 20px;
        }
        
        .allowance-item {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-left: 4px solid var(--success);
        }
        
        .allowance-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .allowance-month {
            font-weight: 700;
            color: var(--primary);
            font-size: 1.1rem;
        }
        
        .allowance-amount {
            font-size: 1.5rem;
            font-weight: 900;
            color: var(--success);
        }
        
        .breakdown {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
        }
        
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        
        .breakdown-item:last-child {
            border-bottom: none;
        }
        
        .breakdown-total {
            font-weight: 700;
            color: var(--primary);
            border-top: 2px solid #e2e8f0;
            padding-top: 10px;
            margin-top: 5px;
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
        
        .formula-note {
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
                <h1 class="user-name">Elaun Saya</h1>
                <p style="opacity: 0.9; font-size: 0.9rem;">
                    <i class="fas fa-money-bill-wave"></i> Rekod Elaun & Pembayaran
                </p>
            </div>
        </header>
        
        <div class="allowance-container">
            <!-- SUMMARY -->
            <div class="summary-card">
                <h3 style="margin-bottom: 15px; color: var(--primary);">
                    <i class="fas fa-chart-pie"></i> Ringkasan Elaun
                </h3>
                
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="summary-value">
                            RM<?php echo number_format($totals['total_received'] ?? 0, 2); ?>
                        </div>
                        <div class="summary-label">Jumlah Diterima</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value">
                            <?php echo $totals['months_count'] ?? 0; ?>
                        </div>
                        <div class="summary-label">Bulan</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value">
                            <?php echo number_format($totals['avg_rate'] ?? 0, 1); ?>%
                        </div>
                        <div class="summary-label">Purata Kehadiran</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value">
                            RM<?php echo number_format($totals['avg_bonus'] ?? 0, 2); ?>
                        </div>
                        <div class="summary-label">Purata Bonus</div>
                    </div>
                </div>
            </div>
            
            <!-- ALLOWANCE HISTORY -->
            <div class="allowance-list">
                <h3 style="margin-bottom: 15px; color: var(--primary);">
                    <i class="fas fa-history"></i> Sejarah Elaun
                </h3>
                
                <?php if ($allowanceHistory->num_rows > 0): ?>
                    <?php while($allowance = $allowanceHistory->fetch_assoc()): 
                        $monthName = date('F Y', strtotime($allowance['month_year'] . '-01'));
                    ?>
                        <div class="allowance-item">
                            <div class="allowance-header">
                                <div class="allowance-month"><?php echo $monthName; ?></div>
                                <div class="allowance-amount">
                                    RM<?php echo number_format($allowance['total_amount'], 2); ?>
                                </div>
                            </div>
                            
                            <div class="breakdown">
                                <div class="breakdown-item">
                                    <span>Asas (RM<?php echo number_format($allowance['base_amount'], 2); ?>)</span>
                                    <span>RM<?php echo number_format($allowance['base_amount'], 2); ?></span>
                                </div>
                                
                                <div class="breakdown-item">
                                    <span>Kehadiran (<?php echo number_format($allowance['attendance_rate'], 1); ?>%)</span>
                                    <span>RM<?php echo number_format($allowance['calculated_amount'], 2); ?></span>
                                </div>
                                
                                <?php if ($allowance['performance_bonus'] > 0): ?>
                                <div class="breakdown-item">
                                    <span>Bonus Prestasi</span>
                                    <span>+ RM<?php echo number_format($allowance['performance_bonus'], 2); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="breakdown-item breakdown-total">
                                    <span>JUMLAH</span>
                                    <span>RM<?php echo number_format($allowance['total_amount'], 2); ?></span>
                                </div>
                            </div>
                            
                            <div style="margin-top: 10px; font-size: 0.85rem; color: #718096; display: flex; justify-content: space-between;">
                                <span>
                                    <i class="fas fa-calculator"></i> 
                                    Dikira oleh: <?php echo htmlspecialchars($allowance['calculated_by_name']); ?>
                                </span>
                                <span>
                                    <i class="fas fa-calendar"></i> 
                                    <?php echo date('d/m/Y', strtotime($allowance['calculated_at'])); ?>
                                </span>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-money-bill-wave"></i>
                        <p>Tiada rekod elaun</p>
                        <small>Elaun akan dikira pada akhir setiap bulan</small>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- FORMULA NOTE -->
            <div class="formula-note">
                <h4 style="margin-bottom: 10px; color: var(--primary);">
                    <i class="fas fa-calculator"></i> Formula Pengiraan Elaun
                </h4>
                <p><strong>Asas:</strong> RM100.00 setiap bulan</p>
                <p><strong>Prorata:</strong> Asas × Rate Kehadiran (%)</p>
                <p><strong>Bonus Prestasi:</strong> Ditambah berdasarkan gred prestasi</p>
                <p style="margin-top: 10px; font-style: italic; color: #718096;">
                    Contoh: 85% kehadiran + Bonus RM15.00 = RM100 × 85% + RM15 = RM115.00
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
            
            <a href="allowance.php" class="nav-item active">
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
        
        // Format currency on load
        document.addEventListener('DOMContentLoaded', function() {
            const amounts = document.querySelectorAll('.allowance-amount');
            amounts.forEach(amount => {
                const text = amount.textContent;
                // Already formatted by PHP
            });
        });
    </script>
</body>
</html>
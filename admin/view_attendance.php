<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

$cadet_id = $_GET['cadet_id'] ?? 0;

// Fetch cadet info
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'cadet'");
$stmt->execute([$cadet_id]);
$cadet = $stmt->fetch();

if (!$cadet) {
    header('Location: list_cadets.php?error=cadet_not_found');
    exit();
}

// Fetch attendance records
$attendance_stmt = $pdo->prepare("
    SELECT a.*, ts.training_date, ts.training_type, ts.location, ts.session_time
    FROM attendance a
    JOIN training_sessions ts ON a.session_id = ts.session_id
    WHERE a.user_id = ?
    ORDER BY ts.training_date DESC
");
$attendance_stmt->execute([$cadet_id]);
$attendance_records = $attendance_stmt->fetchAll();

// Calculate statistics
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
        SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused
    FROM attendance 
    WHERE user_id = ?
");
$stats_stmt->execute([$cadet_id]);
$stats = $stats_stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekod Kehadiran - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --success: #48bb78;
            --danger: #f56565;
            --warning: #ed8936;
            --info: #4299e1;
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
            max-width: 1200px;
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
        
        /* CONTENT */
        .content {
            padding: 30px;
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
        
        /* INFO CARD */
        .info-card {
            background: #f7fafc;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 4px solid var(--accent);
        }
        
        /* STATS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-box {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-top: 4px solid var(--accent);
        }
        
        .stat-box.total { border-top-color: var(--primary); }
        .stat-box.present { border-top-color: var(--success); }
        .stat-box.absent { border-top-color: var(--danger); }
        .stat-box.late { border-top-color: var(--warning); }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .stat-label {
            color: var(--secondary);
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
        }
        
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .attendance-table th {
            background: var(--primary);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .attendance-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .attendance-table tr:hover {
            background: #f7fafc;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-present { background: #d4edda; color: #155724; }
        .status-absent { background: #f8d7da; color: #721c24; }
        .status-late { background: #fff3cd; color: #856404; }
        .status-excused { background: #d1ecf1; color: #0c5460; }
        
        /* BUTTON */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        
        .btn-secondary {
            background: var(--secondary);
            color: white;
        }
        
        .btn-secondary:hover {
            background: #1a202c;
        }
        
        /* EMPTY STATE */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--secondary);
        }
        
        .empty-state i {
            font-size: 3rem;
            opacity: 0.3;
            margin-bottom: 15px;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .attendance-table {
                display: block;
                overflow-x: auto;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <a href="list_cadets.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Kembali ke Senarai
            </a>
            <h1>
                <i class="fas fa-calendar-check"></i> Rekod Kehadiran
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">Lihat rekod kehadiran kadet</p>
        </div>
        
        <div class="content">
            <!-- INFO CARD -->
            <div class="info-card">
                <h4><i class="fas fa-user"></i> Maklumat Kadet</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 10px;">
                    <div>
                        <strong>Nama:</strong> <?php echo htmlspecialchars($cadet['name']); ?>
                    </div>
                    <div>
                        <strong>No. Tentera:</strong> <?php echo $cadet['military_number']; ?>
                    </div>
                    <div>
                        <strong>Perkhidmatan:</strong> <?php echo strtoupper($cadet['service_type']); ?>
                    </div>
                    <div>
                        <strong>Pangkat:</strong> <?php echo ucfirst($cadet['rank_level']); ?>
                    </div>
                </div>
            </div>
            
            <!-- STATISTICS -->
            <div class="section-title">
                <i class="fas fa-chart-pie"></i> Statistik Kehadiran
            </div>
            
            <div class="stats-grid">
                <div class="stat-box total">
                    <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="stat-label">Total</div>
                    <i class="fas fa-calendar-alt" style="font-size: 1.5rem; color: var(--primary); margin-top: 10px;"></i>
                </div>
                
                <div class="stat-box present">
                    <div class="stat-number" style="color: var(--success);"><?php echo $stats['present'] ?? 0; ?></div>
                    <div class="stat-label">Hadir</div>
                    <i class="fas fa-check-circle" style="font-size: 1.5rem; color: var(--success); margin-top: 10px;"></i>
                </div>
                
                <div class="stat-box absent">
                    <div class="stat-number" style="color: var(--danger);"><?php echo $stats['absent'] ?? 0; ?></div>
                    <div class="stat-label">Tidak Hadir</div>
                    <i class="fas fa-times-circle" style="font-size: 1.5rem; color: var(--danger); margin-top: 10px;"></i>
                </div>
                
                <div class="stat-box late">
                    <div class="stat-number" style="color: var(--warning);"><?php echo $stats['late'] ?? 0; ?></div>
                    <div class="stat-label">Lewat</div>
                    <i class="fas fa-clock" style="font-size: 1.5rem; color: var(--warning); margin-top: 10px;"></i>
                </div>
            </div>
            
            <!-- ATTENDANCE TABLE -->
            <div class="section-title">
                <i class="fas fa-history"></i> Rekod Kehadiran
            </div>
            
            <div class="table-container">
                <?php if (empty($attendance_records)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>Tiada Rekod Kehadiran</h3>
                        <p>Kadet ini belum mempunyai rekod kehadiran.</p>
                    </div>
                <?php else: ?>
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th>Tarikh</th>
                                <th>Aktiviti</th>
                                <th>Lokasi</th>
                                <th>Sesi</th>
                                <th>Status</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_records as $record): 
                                $status_labels = [
                                    'present' => 'Hadir',
                                    'absent' => 'Tidak Hadir', 
                                    'late' => 'Lewat',
                                    'excused' => 'Bermasalah'
                                ];
                            ?>
                            <tr>
                                <td>
                                    <?php echo date('d/m/Y', strtotime($record['training_date'])); ?><br>
                                    <small><?php echo date('l', strtotime($record['training_date'])); ?></small>
                                </td>
                                <td><?php echo $record['training_type']; ?></td>
                                <td><?php echo $record['location']; ?></td>
                                <td>
                                    <?php 
                                    $session_labels = [
                                        'pagi' => 'Pagi',
                                        'tengah hari' => 'Tengah Hari',
                                        'petang' => 'Petang',
                                        'malam' => 'Malam'
                                    ];
                                    echo $session_labels[$record['session_time']] ?? $record['session_time'];
                                    ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $record['status']; ?>">
                                        <?php echo $status_labels[$record['status']] ?? $record['status']; ?>
                                    </span>
                                </td>
                                <td><?php echo $record['reason'] ? htmlspecialchars($record['reason']) : '-'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <!-- BACK BUTTON -->
            <div style="text-align: center; margin-top: 30px;">
                <a href="list_cadets.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Kembali ke Senarai Kadet
                </a>
            </div>
        </div>
    </div>
</body>
</html>
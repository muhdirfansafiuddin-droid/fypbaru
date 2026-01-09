<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

// Handle search filter
$search = $_GET['search'] ?? '';
$service_type = $_GET['service_type'] ?? 'all';
$rank_level = $_GET['rank_level'] ?? 'all';

// Build query with filters
$query = "SELECT * FROM users WHERE role = 'cadet'";
$params = [];

if (!empty($search)) {
    $query .= " AND (name LIKE ? OR military_number LIKE ? OR email LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
}

if ($service_type != 'all') {
    $query .= " AND service_type = ?";
    $params[] = $service_type;
}

if ($rank_level != 'all') {
    $query .= " AND rank_level = ?";
    $params[] = $rank_level;
}

$query .= " ORDER BY name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$cadets = $stmt->fetchAll();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN service_type = 'darat' THEN 1 ELSE 0 END) as darat,
        SUM(CASE WHEN service_type = 'laut' THEN 1 ELSE 0 END) as laut,
        SUM(CASE WHEN service_type = 'udara' THEN 1 ELSE 0 END) as udara,
        SUM(CASE WHEN rank_level = 'junior' THEN 1 ELSE 0 END) as junior,
        SUM(CASE WHEN rank_level = 'intermediate' THEN 1 ELSE 0 END) as intermediate,
        SUM(CASE WHEN rank_level = 'senior' THEN 1 ELSE 0 END) as senior
    FROM users 
    WHERE role = 'cadet'
";
$stats_stmt = $pdo->query($stats_query);
$stats = $stats_stmt->fetch();

// Get performance data for each cadet
$performance_data = [];
if (!empty($cadets)) {
    $cadet_ids = array_column($cadets, 'user_id');
    $placeholders = implode(',', array_fill(0, count($cadet_ids), '?'));
    
    $performance_query = "
        SELECT 
            user_id,
            COUNT(*) as total_attendance,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days
        FROM attendance 
        WHERE user_id IN ($placeholders)
        GROUP BY user_id
    ";
    
    $perf_stmt = $pdo->prepare($performance_query);
    $perf_stmt->execute($cadet_ids);
    $perf_results = $perf_stmt->fetchAll();
    
    foreach ($perf_results as $perf) {
        $performance_data[$perf['user_id']] = $perf;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Senarai Kadet - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --success: #48bb78;
            --warning: #ed8936;
            --danger: #f56565;
            --info: #4299e1;
            --navy: #2c5282;
            --army-green: #276749;
            --airforce-blue: #2b6cb0;
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
        
        /* MAIN CONTENT */
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
        
        /* DASHBOARD STATS */
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            text-align: center;
            border-top: 5px solid var(--accent);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.total { border-top-color: var(--primary); }
        .stat-card.darat { border-top-color: var(--army-green); }
        .stat-card.laut { border-top-color: var(--navy); }
        .stat-card.udara { border-top-color: var(--airforce-blue); }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        
        .stat-number {
            font-size: 2.2rem;
            font-weight: bold;
            margin: 10px 0;
            color: var(--primary);
        }
        
        .stat-label {
            color: var(--secondary);
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* FILTER SECTION */
        .filter-section {
            background: #f7fafc;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        input, select {
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
        
        .filter-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
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
        }
        
        .btn-primary:hover {
            background: #2c5282;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        
        /* CADETS TABLE */
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .cadets-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .cadets-table th {
            background: var(--primary);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .cadets-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .cadets-table tr:hover {
            background: #f7fafc;
        }
        
        .service-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-darat { background: #c6f6d5; color: var(--army-green); }
        .badge-laut { background: #bee3f8; color: var(--navy); }
        .badge-udara { background: #e9d8fd; color: var(--airforce-blue); }
        
        .rank-badge {
            padding: 4px 10px;
            border-radius: 5px;
            font-size: 0.85rem;
            background: #edf2f7;
            color: var(--secondary);
        }
        
        .performance-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .performance-bar {
            flex: 1;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .performance-fill {
            height: 100%;
            border-radius: 4px;
        }
        
        .performance-high { background: var(--success); }
        .performance-medium { background: var(--warning); }
        .performance-low { background: var(--danger); }
        
        .action-btns {
            display: flex;
            gap: 8px;
        }
        
        .btn-small {
            padding: 8px 16px;
            font-size: 0.9rem;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        
        .btn-small:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .btn-view {
            background: var(--info);
            color: white;
        }
        
        .btn-edit {
            background: var(--success);
            color: white;
        }
        
        .btn-attendance {
            background: var(--warning);
            color: white;
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
            .cadets-table {
                display: block;
                overflow-x: auto;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .dashboard-stats {
                grid-template-columns: 1fr;
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
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <h1>
                <i class="fas fa-users"></i> Senarai Kadet
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">Lihat dan urus semua kadet dalam sistem</p>
        </div>
        
        <div class="content">
            <!-- DASHBOARD STATS -->
            <div class="dashboard-stats">
                <div class="stat-card total">
                    <div class="stat-icon" style="color: var(--primary);">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="stat-label">Jumlah Kadet</div>
                </div>
                
                <div class="stat-card darat">
                    <div class="stat-icon" style="color: var(--army-green);">
                        <i class="fas fa-truck"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['darat'] ?? 0; ?></div>
                    <div class="stat-label">Angkatan Darat</div>
                </div>
                
                <div class="stat-card laut">
                    <div class="stat-icon" style="color: var(--navy);">
                        <i class="fas fa-ship"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['laut'] ?? 0; ?></div>
                    <div class="stat-label">Angkatan Laut</div>
                </div>
                
                <div class="stat-card udara">
                    <div class="stat-icon" style="color: var(--airforce-blue);">
                        <i class="fas fa-fighter-jet"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['udara'] ?? 0; ?></div>
                    <div class="stat-label">Angkatan Udara</div>
                </div>
            </div>
            
            <!-- FILTER SECTION -->
            <div class="filter-section">
                <div class="section-title">
                    <i class="fas fa-filter"></i> Tapisan Kadet
                </div>
                
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label>Cari Kadet</label>
                            <input type="text" name="search" placeholder="Nama, No. Tentera atau Email..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label>Jenis Perkhidmatan</label>
                            <select name="service_type">
                                <option value="all" <?php echo $service_type == 'all' ? 'selected' : ''; ?>>Semua Perkhidmatan</option>
                                <option value="darat" <?php echo $service_type == 'darat' ? 'selected' : ''; ?>>Angkatan Darat</option>
                                <option value="laut" <?php echo $service_type == 'laut' ? 'selected' : ''; ?>>Angkatan Laut</option>
                                <option value="udara" <?php echo $service_type == 'udara' ? 'selected' : ''; ?>>Angkatan Udara</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Tahap Pangkat</label>
                            <select name="rank_level">
                                <option value="all" <?php echo $rank_level == 'all' ? 'selected' : ''; ?>>Semua Tahap</option>
                                <option value="junior" <?php echo $rank_level == 'junior' ? 'selected' : ''; ?>>Junior</option>
                                <option value="intermediate" <?php echo $rank_level == 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                                <option value="senior" <?php echo $rank_level == 'senior' ? 'selected' : ''; ?>>Senior</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Cari Kadet
                        </button>
                        <a href="list_cadets.php" class="btn btn-warning">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- CADETS TABLE -->
            <div class="section-title">
                <i class="fas fa-list"></i> Senarai Kadet 
                <span style="background: var(--accent); color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.9rem;">
                    <?php echo count($cadets); ?> ditemui
                </span>
            </div>
            
            <div class="table-container">
                <?php if (empty($cadets)): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-slash"></i>
                        <h3>Tiada Kadet Ditemui</h3>
                        <p>Tidak ada kadet yang sepadan dengan kriteria carian anda.</p>
                    </div>
                <?php else: ?>
                    <table class="cadets-table">
                        <thead>
                            <tr>
                                <th>No. Tentera</th>
                                <th>Nama Kadet</th>
                                <th>Perkhidmatan</th>
                                <th>Pangkat</th>
                                <th>Email</th>
                                <th>Performance</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cadets as $cadet): 
                                $performance_score = $cadet['performance_score'] ?? 0;
                                $performance_class = $performance_score >= 7 ? 'performance-high' : 
                                                   ($performance_score >= 5 ? 'performance-medium' : 'performance-low');
                                
                                $perf_data = $performance_data[$cadet['user_id']] ?? null;
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($cadet['military_number']); ?></strong>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($cadet['name']); ?></strong><br>
                                    <small class="text-muted">ID: <?php echo $cadet['user_id']; ?></small>
                                </td>
                                <td>
                                    <span class="service-badge badge-<?php echo $cadet['service_type']; ?>">
                                        <i class="fas <?php 
                                            echo $cadet['service_type'] == 'darat' ? 'fa-truck' : 
                                                 ($cadet['service_type'] == 'laut' ? 'fa-ship' : 'fa-fighter-jet'); 
                                        ?>"></i>
                                        <?php echo strtoupper($cadet['service_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="rank-badge">
                                        <i class="fas fa-star"></i>
                                        <?php echo ucfirst($cadet['rank_level']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($cadet['email'])): ?>
                                        <a href="mailto:<?php echo $cadet['email']; ?>" style="color: var(--accent);">
                                            <?php echo $cadet['email']; ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">Tiada email</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="performance-indicator">
                                        <span style="font-weight: bold; width: 40px;">
                                            <?php echo number_format($performance_score, 1); ?>/10
                                        </span>
                                        <div class="performance-bar">
                                            <div class="performance-fill <?php echo $performance_class; ?>" 
                                                 style="width: <?php echo min($performance_score * 10, 100); ?>%"></div>
                                        </div>
                                    </div>
                                    <?php if ($perf_data && $perf_data['total_attendance'] > 0): ?>
                                        <small class="text-muted">
                                            <?php echo $perf_data['present_days']; ?>/<?php echo $perf_data['total_attendance']; ?> kehadiran
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
    <div class="action-btns">
        <button class="btn-small btn-view" onclick="viewCadet(<?php echo $cadet['user_id']; ?>)">
            <i class="fas fa-eye"></i> Lihat
        </button>
        <button class="btn-small btn-edit" onclick="window.location.href='edit_cadet.php?id=<?php echo $cadet['user_id']; ?>'">
            <i class="fas fa-edit"></i> Edit
        </button>
        <button class="btn-small btn-attendance" onclick="window.location.href='view_attendance.php?cadet_id=<?php echo $cadet['user_id']; ?>'">
            <i class="fas fa-calendar-check"></i> Kehadiran
        </button>
    </div>
</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- DETAILS MODAL -->
    <div id="detailsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 10px; width: 90%; max-width: 500px; padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="color: var(--primary);"><i class="fas fa-user"></i> Maklumat Kadet</h3>
                <button onclick="closeModal('detailsModal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <div id="cadetDetails"></div>
            <div style="text-align: right; margin-top: 20px;">
                <button onclick="closeModal('detailsModal')" class="btn-small" style="background: var(--secondary); color: white;">
                    <i class="fas fa-times"></i> Tutup
                </button>
            </div>
        </div>
    </div>
    
 <script>
    // View Cadet Details - FIXED
    function viewCadet(cadetId) {
        fetch(`get_cadet_details.php?id=${cadetId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const cadet = data.cadet;
                    const modal = document.getElementById('detailsModal');
                    const detailsDiv = document.getElementById('cadetDetails');
                    
                    const detailsHTML = `
                        <div style="display: grid; gap: 10px;">
                            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                                <span><strong>ID Kadet:</strong></span>
                                <span>#${cadet.user_id}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                                <span><strong>Nama Penuh:</strong></span>
                                <span>${cadet.name}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                                <span><strong>No. Tentera:</strong></span>
                                <span>${cadet.military_number}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                                <span><strong>Email:</strong></span>
                                <span>${cadet.email || 'Tiada'}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                                <span><strong>Jenis Perkhidmatan:</strong></span>
                                <span style="padding: 4px 10px; border-radius: 20px; background: #e2e8f0;">
                                    ${cadet.service_type ? cadet.service_type.toUpperCase() : 'N/A'}
                                </span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                                <span><strong>Tahap Pangkat:</strong></span>
                                <span style="padding: 4px 10px; border-radius: 5px; background: #edf2f7;">
                                    ${cadet.rank_level ? cadet.rank_level.toUpperCase() : 'N/A'}
                                </span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                                <span><strong>Skor Performance:</strong></span>
                                <span style="font-weight: bold; color: ${cadet.performance_score >= 7 ? '#48bb78' : 
                                                                  cadet.performance_score >= 5 ? '#ed8936' : 
                                                                  '#f56565'}">
                                    ${cadet.performance_score || 0}/10
                                </span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                                <span><strong>Kadar Elaun:</strong></span>
                                <span>RM ${(cadet.allowance_rate || 0).toFixed(2)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 8px 0;">
                                <span><strong>Daftar Pada:</strong></span>
                                <span>${cadet.created_at ? new Date(cadet.created_at).toLocaleDateString('ms-MY') : 'N/A'}</span>
                            </div>
                        </div>
                    `;
                    
                    detailsDiv.innerHTML = detailsHTML;
                    modal.style.display = 'flex';
                } else {
                    alert('Error: ' + (data.message || 'Failed to load cadet details'));
                }
            })
            .catch(error => {
                console.error('Fetch Error:', error);
                alert('Error loading cadet details. Please try again.');
            });
    }
    
    // Edit Cadet - FIXED (direct redirect)
    function editCadet(cadetId) {
        window.location.href = `edit_cadet.php?id=${cadetId}`;
    }
    
    // View Attendance - FIXED (direct redirect)
    function viewAttendance(cadetId) {
        window.location.href = `view_attendance.php?cadet_id=${cadetId}`;
    }
    
    // Close modal
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('detailsModal');
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    }
</script>
        
</body>
</html>
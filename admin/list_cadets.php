<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

// Handle search filter for cadets
$search_cadet = $_GET['search_cadet'] ?? '';
$service_type = $_GET['service_type'] ?? 'all';
$rank_level = $_GET['rank_level'] ?? 'all';

// Handle search filter for rankholders
$search_rankholder = $_GET['search_rankholder'] ?? '';
$rankholder_service = $_GET['rankholder_service'] ?? 'all';

// Build query for cadets with filters
$query_cadet = "SELECT * FROM users WHERE role = 'cadet'";
$params_cadet = [];

if (!empty($search_cadet)) {
    $query_cadet .= " AND (name LIKE ? OR military_number LIKE ? OR email LIKE ?)";
    $search_term = "%$search_cadet%";
    $params_cadet = array_merge($params_cadet, [$search_term, $search_term, $search_term]);
}

if ($service_type != 'all') {
    $query_cadet .= " AND service_type = ?";
    $params_cadet[] = $service_type;
}

if ($rank_level != 'all') {
    $query_cadet .= " AND rank_level = ?";
    $params_cadet[] = $rank_level;
}

$query_cadet .= " ORDER BY name ASC";

$stmt_cadet = $pdo->prepare($query_cadet);
$stmt_cadet->execute($params_cadet);
$cadets = $stmt_cadet->fetchAll();

// Build query for rankholders with filters
$query_rankholder = "SELECT * FROM users WHERE role = 'rankholder'";
$params_rankholder = [];

if (!empty($search_rankholder)) {
    $query_rankholder .= " AND (name LIKE ? OR military_number LIKE ? OR email LIKE ?)";
    $search_term_r = "%$search_rankholder%";
    $params_rankholder = array_merge($params_rankholder, [$search_term_r, $search_term_r, $search_term_r]);
}

if ($rankholder_service != 'all') {
    $query_rankholder .= " AND service_type = ?";
    $params_rankholder[] = $rankholder_service;
}

$query_rankholder .= " ORDER BY name ASC";

$stmt_rankholder = $pdo->prepare($query_rankholder);
$stmt_rankholder->execute($params_rankholder);
$rankholders = $stmt_rankholder->fetchAll();

// Get statistics for cadets
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

// Get statistics for rankholders
$stats_rankholder_query = "
    SELECT 
        COUNT(*) as total_rh,
        SUM(CASE WHEN service_type = 'darat' THEN 1 ELSE 0 END) as darat_rh,
        SUM(CASE WHEN service_type = 'laut' THEN 1 ELSE 0 END) as laut_rh,
        SUM(CASE WHEN service_type = 'udara' THEN 1 ELSE 0 END) as udara_rh
    FROM users 
    WHERE role = 'rankholder'
";
$stats_rankholder_stmt = $pdo->query($stats_rankholder_query);
$stats_rankholder = $stats_rankholder_stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Senarai Kadet & Rankholder - CAAMS</title>
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
            --rankholder-color: #805ad5;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        
        body {
            background: #82CAFF;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1600px;
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
        
        .section-title.rankholder {
            border-bottom-color: var(--rankholder-color);
        }
        
        /* DASHBOARD STATS */
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            text-align: center;
            border-top: 4px solid var(--accent);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
        }
        
        .stat-card.total { border-top-color: var(--primary); }
        .stat-card.rankholder { border-top-color: var(--rankholder-color); }
        .stat-card.darat { border-top-color: var(--army-green); }
        .stat-card.laut { border-top-color: var(--navy); }
        .stat-card.udara { border-top-color: var(--airforce-blue); }
        
        .stat-icon {
            font-size: 2.2rem;
            margin-bottom: 12px;
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            margin: 8px 0;
            color: var(--primary);
        }
        
        .stat-label {
            color: var(--secondary);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        /* TABS */
        .tabs {
            display: flex;
            background: #f7fafc;
            border-radius: 10px;
            margin-bottom: 30px;
            overflow: hidden;
        }
        
        .tab-btn {
            flex: 1;
            padding: 15px;
            text-align: center;
            background: none;
            border: none;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            color: var(--secondary);
        }
        
        .tab-btn:hover {
            background: #e2e8f0;
        }
        
        .tab-btn.active {
            background: var(--accent);
            color: white;
        }
        
        .tab-btn.rankholder.active {
            background: var(--rankholder-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
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
        
        .btn-purple {
            background: var(--rankholder-color);
            color: white;
        }
        
        .btn-purple:hover {
            background: #6b46c1;
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
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        /* TABLES */
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .users-table th {
            background: var(--primary);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .users-table.rankholder th {
            background: var(--rankholder-color);
        }
        
        .users-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .users-table tr:hover {
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
        .badge-rankholder { background: #e9d8fd; color: var(--rankholder-color); }
        
        .rank-badge {
            padding: 4px 10px;
            border-radius: 5px;
            font-size: 0.85rem;
            background: #edf2f7;
            color: var(--secondary);
        }
        
        .action-btns {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
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
        
        .btn-delete {
            background: var(--danger);
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
        
        /* MODAL */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
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
        
        .modal-body {
            padding: 25px;
        }
        
        .close-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        /* FORM STYLES */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .form-control:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
        }
        
        /* NOTIFICATION */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            z-index: 10000;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            animation: slideIn 0.3s ease-out;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .notification.success {
            background: var(--success);
        }
        
        .notification.error {
            background: var(--danger);
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
        
        /* RESPONSIVE */
        @media (max-width: 1200px) {
            .dashboard-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .users-table {
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
            
            .tabs {
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
                <i class="fas fa-users"></i> Manage Users (Cadets & Rankholders)
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">View and manage all users in the system</p>
        </div>
        
        <div class="content">
            <!-- DASHBOARD STATS -->
            <div class="dashboard-stats">
                <!-- TOTAL CADETS -->
                <div class="stat-card total">
                    <div class="stat-icon" style="color: var(--primary);">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="stat-label">Total Cadets</div>
                </div>
                
                <!-- TOTAL RANKHOLDERS -->
                <div class="stat-card rankholder">
                    <div class="stat-icon" style="color: var(--rankholder-color);">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats_rankholder['total_rh'] ?? 0; ?></div>
                    <div class="stat-label">Total Rankholders</div>
                </div>
                
                <!-- CADETS - ARMY (DARAT) -->
                <div class="stat-card darat">
                    <div class="stat-icon" style="color: var(--army-green);">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-number">
                        <?php echo $stats['darat'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Cadets - Army</div>
                </div>
                
                <!-- CADETS - NAVY (LAUT) -->
                <div class="stat-card laut">
                    <div class="stat-icon" style="color: var(--navy);">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-number">
                        <?php echo $stats['laut'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Cadets - Navy</div>
                </div>
                
                <!-- CADETS - AIR FORCE (UDARA) -->
                <div class="stat-card udara">
                    <div class="stat-icon" style="color: var(--airforce-blue);">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-number">
                        <?php echo $stats['udara'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Cadets - Air Force</div>
                </div>
                
                <!-- RANKHOLDERS - ARMY (DARAT) -->
                <div class="stat-card" style="border-top-color: var(--army-green);">
                    <div class="stat-icon" style="color: var(--army-green);">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="stat-number">
                        <?php echo $stats_rankholder['darat_rh'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Rankholders - Army</div>
                </div>
                
                <!-- RANKHOLDERS - NAVY (LAUT) -->
                <div class="stat-card" style="border-top-color: var(--navy);">
                    <div class="stat-icon" style="color: var(--navy);">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="stat-number">
                        <?php echo $stats_rankholder['laut_rh'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Rankholders - Navy</div>
                </div>
                
                <!-- RANKHOLDERS - AIR FORCE (UDARA) -->
                <div class="stat-card" style="border-top-color: var(--airforce-blue);">
                    <div class="stat-icon" style="color: var(--airforce-blue);">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="stat-number">
                        <?php echo $stats_rankholder['udara_rh'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Rankholders - Air Force</div>
                </div>
            </div>
            
            <!-- TABS -->
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('cadet')">
                    <i class="fas fa-user-graduate"></i> Cadets
                </button>
                <button class="tab-btn" onclick="switchTab('rankholder')">
                    <i class="fas fa-star"></i> Rankholders
                </button>
            </div>
            
            <!-- CADET TAB -->
            <div id="cadet-tab" class="tab-content active">
                <!-- FILTER SECTION FOR CADETS -->
                <div class="filter-section">
                    <div class="section-title">
                        <i class="fas fa-filter"></i> Filter Cadets
                    </div>
                    
                    <form method="GET" action="">
                        <input type="hidden" name="search_rankholder" value="<?php echo htmlspecialchars($search_rankholder); ?>">
                        <input type="hidden" name="rankholder_service" value="<?php echo htmlspecialchars($rankholder_service); ?>">
                        
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label>Search Cadets</label>
                                <input type="text" name="search_cadet" placeholder="Name, Military No. or Email..." 
                                       value="<?php echo htmlspecialchars($search_cadet); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label>Service Type</label>
                                <select name="service_type">
                                    <option value="all" <?php echo $service_type == 'all' ? 'selected' : ''; ?>>All Services</option>
                                    <option value="darat" <?php echo $service_type == 'darat' ? 'selected' : ''; ?>>Army</option>
                                    <option value="laut" <?php echo $service_type == 'laut' ? 'selected' : ''; ?>>Navy</option>
                                    <option value="udara" <?php echo $service_type == 'udara' ? 'selected' : ''; ?>>Air Force</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label>Rank Level</label>
                                <select name="rank_level">
                                    <option value="all" <?php echo $rank_level == 'all' ? 'selected' : ''; ?>>All Levels</option>
                                    <option value="junior" <?php echo $rank_level == 'junior' ? 'selected' : ''; ?>>Junior</option>
                                    <option value="intermediate" <?php echo $rank_level == 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                                    <option value="senior" <?php echo $rank_level == 'senior' ? 'selected' : ''; ?>>Senior</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Find Cadets
                            </button>
                            <a href="list_cadets.php" class="btn btn-warning">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- CADETS TABLE -->
                <div class="section-title">
                    <i class="fas fa-user-graduate"></i> List Cadets
                    <span style="background: var(--accent); color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.9rem;">
                        <?php echo count($cadets); ?> found
                    </span>
                </div>
                
                <div class="table-container">
                    <?php if (empty($cadets)): ?>
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <h3>No Cadets Found</h3>
                            <p>No cadets match your search criteria.</p>
                        </div>
                    <?php else: ?>
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th>Military Number</th>
                                    <th>Cadet Name</th>
                                    <th>Service Type</th>
                                    <th>Rank Level</th>
                                    <th>Email</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cadets as $cadet): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($cadet['military_number']); ?></strong>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($cadet['name']); ?></strong><br>
                                        <small class="text-muted">ID: <?php echo $cadet['user_id']; ?></small>
                                    </td>
                                    <td>
                                        <?php if ($cadet['service_type']): ?>
                                            <span class="service-badge badge-<?php echo $cadet['service_type']; ?>">
                                                <i class="fas <?php 
                                                    echo $cadet['service_type'] == 'darat' ? 'fa-truck' : 
                                                         ($cadet['service_type'] == 'laut' ? 'fa-ship' : 'fa-fighter-jet'); 
                                                ?>"></i>
                                                <?php echo strtoupper($cadet['service_type']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($cadet['rank_level']): ?>
                                            <span class="rank-badge">
                                                <i class="fas fa-star"></i>
                                                <?php echo ucfirst($cadet['rank_level']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($cadet['email'])): ?>
                                            <a href="mailto:<?php echo $cadet['email']; ?>" style="color: var(--accent);">
                                                <?php echo $cadet['email']; ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">No email</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-btns">
                                            <button class="btn-small btn-view" onclick="viewUser(<?php echo $cadet['user_id']; ?>, 'cadet')">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <button class="btn-small btn-edit" onclick="editUser(<?php echo $cadet['user_id']; ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn-small btn-delete" onclick="confirmDelete(<?php echo $cadet['user_id']; ?>, '<?php echo htmlspecialchars($cadet['name']); ?>')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                            <button class="btn-small btn-attendance" onclick="window.location.href='view_attendance.php?cadet_id=<?php echo $cadet['user_id']; ?>'">
                                                <i class="fas fa-calendar-check"></i> Attendance
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
            
            <!-- RANKHOLDER TAB -->
            <div id="rankholder-tab" class="tab-content">
                <!-- FILTER SECTION FOR RANKHOLDERS -->
                <div class="filter-section">
                    <div class="section-title">
                        <i class="fas fa-filter"></i> Filter Rankholders
                    </div>
                    
                    <form method="GET" action="">
                        <input type="hidden" name="search_cadet" value="<?php echo htmlspecialchars($search_cadet); ?>">
                        <input type="hidden" name="service_type" value="<?php echo htmlspecialchars($service_type); ?>">
                        <input type="hidden" name="rank_level" value="<?php echo htmlspecialchars($rank_level); ?>">
                        
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label>Search Rankholders</label>
                                <input type="text" name="search_rankholder" placeholder="Name, Military No. or Email..." 
                                       value="<?php echo htmlspecialchars($search_rankholder); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label>Service Type</label>
                                <select name="rankholder_service">
                                    <option value="all" <?php echo $rankholder_service == 'all' ? 'selected' : ''; ?>>All Services</option>
                                    <option value="darat" <?php echo $rankholder_service == 'darat' ? 'selected' : ''; ?>>Army</option>
                                    <option value="laut" <?php echo $rankholder_service == 'laut' ? 'selected' : ''; ?>>Navy</option>
                                    <option value="udara" <?php echo $rankholder_service == 'udara' ? 'selected' : ''; ?>>Air Force</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-purple">
                                <i class="fas fa-search"></i> Find Rankholders
                            </button>
                            <a href="list_cadets.php" class="btn btn-warning">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- RANKHOLDERS TABLE -->
                <div class="section-title rankholder">
                    <i class="fas fa-star"></i> List Rankholders
                    <span style="background: var(--rankholder-color); color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.9rem;">
                        <?php echo count($rankholders); ?> found
                    </span>
                </div>
                
                <div class="table-container">
                    <?php if (empty($rankholders)): ?>
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <h3>No Rankholders Found</h3>
                            <p>No rankholders match your search criteria.</p>
                        </div>
                    <?php else: ?>
                        <table class="users-table rankholder">
                            <thead>
                                <tr>
                                    <th>Military Number</th>
                                    <th>Name</th>
                                    <th>Service Type</th>
                                    <th>Rank Level</th>
                                    <th>Email</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rankholders as $rankholder): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($rankholder['military_number']); ?></strong>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($rankholder['name']); ?></strong><br>
                                        <small class="text-muted">ID: <?php echo $rankholder['user_id']; ?></small>
                                    </td>
                                    <td>
                                        <?php if ($rankholder['service_type']): ?>
                                            <span class="service-badge badge-<?php echo $rankholder['service_type']; ?>">
                                                <i class="fas <?php 
                                                    echo $rankholder['service_type'] == 'darat' ? 'fa-truck' : 
                                                         ($rankholder['service_type'] == 'laut' ? 'fa-ship' : 'fa-fighter-jet'); 
                                                ?>"></i>
                                                <?php echo strtoupper($rankholder['service_type']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($rankholder['rank_level']): ?>
                                            <span class="rank-badge">
                                                <i class="fas fa-star"></i>
                                                <?php echo ucfirst($rankholder['rank_level']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($rankholder['email'])): ?>
                                            <a href="mailto:<?php echo $rankholder['email']; ?>" style="color: var(--rankholder-color);">
                                                <?php echo $rankholder['email']; ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">No email</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-btns">
                                            <button class="btn-small btn-view" onclick="viewUser(<?php echo $rankholder['user_id']; ?>, 'rankholder')">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <button class="btn-small btn-edit" onclick="editUser(<?php echo $rankholder['user_id']; ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn-small btn-delete" onclick="confirmDelete(<?php echo $rankholder['user_id']; ?>, '<?php echo htmlspecialchars($rankholder['name']); ?>')">
                                                <i class="fas fa-trash"></i> Delete
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
    </div>
    
    <!-- VIEW USER MODAL -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="margin: 0; color: white;"><i class="fas fa-user"></i> User Details</h3>
                <button class="close-btn" onclick="closeModal('viewModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="userDetails"></div>
                <div class="form-actions">
                    <button onclick="closeModal('viewModal')" class="btn" style="background: var(--secondary); color: white;">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- EDIT USER MODAL -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="margin: 0; color: white;"><i class="fas fa-edit"></i> Edit User</h3>
                <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editForm" method="POST">
                    <input type="hidden" id="edit_user_id" name="user_id">
                    <input type="hidden" id="edit_user_role" name="role">
                    
                    <div class="form-group">
                        <label for="edit_name">Full Name *</label>
                        <input type="text" id="edit_name" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_military_number">Military Number *</label>
                        <input type="text" id="edit_military_number" name="military_number" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_email">Email</label>
                        <input type="email" id="edit_email" name="email" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_phone">Phone</label>
                        <input type="text" id="edit_phone" name="phone" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_service_type">Service Type</label>
                        <select id="edit_service_type" name="service_type" class="form-control">
                            <option value="">Select Service</option>
                            <option value="darat">Army</option>
                            <option value="laut">Navy</option>
                            <option value="udara">Air Force</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_rank_level">Rank Level</label>
                        <select id="edit_rank_level" name="rank_level" class="form-control">
                            <option value="">Select Rank</option>
                            <option value="junior">Junior</option>
                            <option value="intermediate">Intermediate</option>
                            <option value="senior">Senior</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_join_date">Join Date</label>
                        <input type="date" id="edit_join_date" name="join_date" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_date_of_birth">Date of Birth</label>
                        <input type="date" id="edit_date_of_birth" name="date_of_birth" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_address">Address</label>
                        <textarea id="edit_address" name="address" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" onclick="closeModal('editModal')" class="btn" style="background: var(--secondary); color: white;">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- CONFIRM DELETE MODAL -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="margin: 0; color: white;"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h3>
                <button class="close-btn" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p id="deleteMessage" style="font-size: 1.1rem; margin-bottom: 20px;"></p>
                <p style="color: var(--danger); font-weight: 600; margin-bottom: 25px;">
                    <i class="fas fa-exclamation-circle"></i> Warning: This action cannot be undone!
                </p>
                <div class="form-actions">
                    <button onclick="closeModal('deleteModal')" class="btn" style="background: var(--secondary); color: white;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button onclick="deleteUser()" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Yes, Delete User
                    </button>
                </div>
            </div>
        </div>
    </div>
    
<script>
    // Tab switching function
    function switchTab(tab) {
        // Hide all tabs
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.remove('active');
        });
        
        // Remove active class from all tab buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        // Show selected tab
        document.getElementById(tab + '-tab').classList.add('active');
        
        // Activate selected tab button
        document.querySelectorAll('.tab-btn').forEach(btn => {
            if (btn.textContent.includes(tab === 'cadet' ? 'Cadets' : 'Rankholders')) {
                btn.classList.add('active');
            }
        });
    }
    
    // View User Details
    function viewUser(userId, role) {
        fetch(`get_user_details.php?id=${userId}&role=${role}`)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const user = data.user;
                    const modal = document.getElementById('viewModal');
                    const detailsDiv = document.getElementById('userDetails');
                    
                    const userType = user.role === 'cadet' ? 'Cadet' : 
                                    user.role === 'rankholder' ? 'Rankholder' : 
                                    user.role === 'admin' ? 'Admin' : 'User';
                    
                    const detailsHTML = `
                        <div style="display: grid; gap: 12px;">
                            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee;">
                                <span><strong>User Type:</strong></span>
                                <span style="padding: 4px 12px; border-radius: 20px; background: ${user.role === 'cadet' ? '#bee3f8' : 
                                                                                         user.role === 'rankholder' ? '#e9d8fd' : 
                                                                                         '#c6f6d5'}; 
                                              color: ${user.role === 'cadet' ? '#2c5282' : 
                                                     user.role === 'rankholder' ? '#805ad5' : 
                                                     '#276749'};">
                                    <i class="fas ${user.role === 'cadet' ? 'fa-user-graduate' : 
                                                   user.role === 'rankholder' ? 'fa-star' : 
                                                   'fa-user-cog'}"></i>
                                    ${userType}
                                </span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee;">
                                <span><strong>User ID:</strong></span>
                                <span>#${user.user_id}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee;">
                                <span><strong>Full Name:</strong></span>
                                <span>${user.name}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee;">
                                <span><strong>Military No.:</strong></span>
                                <span>${user.military_number}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee;">
                                <span><strong>Email:</strong></span>
                                <span>${user.email || '<span class="text-muted">No email</span>'}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee;">
                                <span><strong>Phone:</strong></span>
                                <span>${user.phone || '<span class="text-muted">No phone</span>'}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee;">
                                <span><strong>Service:</strong></span>
                                <span>
                                    ${user.service_type ? 
                                        `<span style="padding: 4px 10px; border-radius: 20px; background: ${user.service_type === 'darat' ? '#c6f6d5' : 
                                                                                         user.service_type === 'laut' ? '#bee3f8' : 
                                                                                         '#e9d8fd'}; 
                                                  color: ${user.service_type === 'darat' ? '#276749' : 
                                                         user.service_type === 'laut' ? '#2c5282' : 
                                                         '#2b6cb0'};">
                                            <i class="fas ${user.service_type === 'darat' ? 'fa-truck' : 
                                                           user.service_type === 'laut' ? 'fa-ship' : 
                                                           'fa-fighter-jet'}"></i>
                                            ${user.service_type.toUpperCase()}
                                        </span>` : 
                                        '<span class="text-muted">Not set</span>'
                                    }
                                </span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee;">
                                <span><strong>Rank Level:</strong></span>
                                <span>
                                    ${user.rank_level ? 
                                        `<span style="padding: 4px 10px; border-radius: 5px; background: #edf2f7; color: var(--secondary);">
                                            <i class="fas fa-star"></i>
                                            ${user.rank_level.toUpperCase()}
                                        </span>` : 
                                        '<span class="text-muted">Not set</span>'
                                    }
                                </span>
                            </div>
                            ${user.date_of_birth ? `
                                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee;">
                                    <span><strong>Date of Birth:</strong></span>
                                    <span>${new Date(user.date_of_birth).toLocaleDateString('ms-MY')}</span>
                                </div>
                            ` : ''}
                            ${user.join_date ? `
                                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee;">
                                    <span><strong>Join Date:</strong></span>
                                    <span>${new Date(user.join_date).toLocaleDateString('ms-MY')}</span>
                                </div>
                            ` : ''}
                            ${user.address ? `
                                <div style="padding: 10px 0; border-bottom: 1px solid #eee;">
                                    <div style="margin-bottom: 5px;"><strong>Address:</strong></div>
                                    <div>${user.address.replace(/\n/g, '<br>')}</div>
                                </div>
                            ` : ''}
                            <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                                <span><strong>Registered On:</strong></span>
                                <span>${new Date(user.created_at).toLocaleDateString('ms-MY', { 
                                    weekday: 'long', 
                                    year: 'numeric', 
                                    month: 'long', 
                                    day: 'numeric' 
                                })}</span>
                            </div>
                        </div>
                    `;
                    
                    detailsDiv.innerHTML = detailsHTML;
                    modal.style.display = 'flex';
                } else {
                    showNotification('Error: ' + (data.message || 'Failed to load user details'), 'error');
                }
            })
            .catch(error => {
                console.error('Fetch Error:', error);
                showNotification('Error loading user details. Please try again.', 'error');
            });
    }
    
    // Edit User
    function editUser(userId) {
        fetch(`get_user_details.php?id=${userId}`)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const user = data.user;
                    
                    // Fill form with user data
                    document.getElementById('edit_user_id').value = user.user_id;
                    document.getElementById('edit_user_role').value = user.role;
                    document.getElementById('edit_name').value = user.name;
                    document.getElementById('edit_military_number').value = user.military_number;
                    document.getElementById('edit_email').value = user.email || '';
                    document.getElementById('edit_phone').value = user.phone || '';
                    document.getElementById('edit_service_type').value = user.service_type || '';
                    document.getElementById('edit_rank_level').value = user.rank_level || '';
                    
                    // Format dates for input fields
                    if (user.join_date) {
                        const joinDate = new Date(user.join_date);
                        document.getElementById('edit_join_date').value = joinDate.toISOString().split('T')[0];
                    } else {
                        document.getElementById('edit_join_date').value = '';
                    }
                    
                    if (user.date_of_birth) {
                        const dob = new Date(user.date_of_birth);
                        document.getElementById('edit_date_of_birth').value = dob.toISOString().split('T')[0];
                    } else {
                        document.getElementById('edit_date_of_birth').value = '';
                    }
                    
                    document.getElementById('edit_address').value = user.address || '';
                    
                    // Show modal
                    openModal('editModal');
                } else {
                    showNotification('Error: ' + (data.message || 'Failed to load user data'), 'error');
                }
            })
            .catch(error => {
                console.error('Fetch Error:', error);
                showNotification('Error loading user data. Please try again.', 'error');
            });
    }
    
    // Confirm Delete
    let userToDelete = null;
    let userNameToDelete = '';
    
    function confirmDelete(userId, userName) {
        userToDelete = userId;
        userNameToDelete = userName;
        
        document.getElementById('deleteMessage').innerHTML = 
            `Are you sure you want to delete <strong>${userName}</strong>?`;
        
        openModal('deleteModal');
    }
    
    // Show notification
    function showNotification(message, type = 'success') {
        // Remove existing notifications
        document.querySelectorAll('.notification').forEach(notification => {
            notification.remove();
        });
        
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.innerHTML = `
            <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
            ${message}
        `;
        
        document.body.appendChild(notification);
        
        // Remove notification after 3 seconds
        setTimeout(() => {
            notification.style.animation = 'slideOut 0.3s ease-out';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 300);
        }, 3000);
    }
    
    // Delete User with AJAX
    function deleteUser() {
        if (!userToDelete) return;
        
        const deleteBtn = document.querySelector('#deleteModal .btn-danger');
        const originalText = deleteBtn.innerHTML;
        deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        deleteBtn.disabled = true;
        
        fetch('delete_user.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `id=${userToDelete}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('User deleted successfully!', 'success');
                closeModal('deleteModal');
                
                // Refresh page to update list
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showNotification('Error: ' + data.message, 'error');
                
                // Reset button
                deleteBtn.innerHTML = originalText;
                deleteBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Fetch Error:', error);
            showNotification('Error deleting user. Please try again.', 'error');
            
            // Reset button
            deleteBtn.innerHTML = originalText;
            deleteBtn.disabled = false;
        })
        .finally(() => {
            userToDelete = null;
            userNameToDelete = '';
        });
    }
    
    // Edit User Form Submission with AJAX
    document.getElementById('editForm').addEventListener('submit', function(e) {
        e.preventDefault(); // Prevent default form submission
        
        const form = this;
        const formData = new FormData(form);
        
        // Show loading
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        submitBtn.disabled = true;
        
        fetch('update_user.php', {
            method: 'POST',
            body: new URLSearchParams(formData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('User updated successfully!', 'success');
                closeModal('editModal');
                
                // Refresh page to show updated data
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showNotification('Error: ' + data.message, 'error');
                
                // Reset button
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Error updating user. Please try again.', 'error');
            
            // Reset button
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });
    
    // View Attendance - direct redirect
    function viewAttendance(cadetId) {
        window.location.href = `view_attendance.php?cadet_id=${cadetId}`;
    }
    
    // Modal functions
    function openModal(modalId) {
        document.getElementById(modalId).style.display = 'flex';
    }
    
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    }
    
    // Prevent form submission with Enter key in edit form
    document.getElementById('editForm').addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
        }
    });
</script>
        
</body>
</html>
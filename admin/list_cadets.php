<?php
// admin/list_cadets.php
require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/controllers/UserController.php';

RBAC::checkPermission('admin');
$user = (new Auth())->getCurrentUser();
$userController = new UserController();

// Get filter parameters
$service = $_GET['service'] ?? null;
$rank = $_GET['rank'] ?? null;

// Get cadets based on filters
$cadets = $userController->getCadetsByServiceRank($service, $rank);

// Statistics
$totalCadets = $cadets->num_rows;
$cadets->data_seek(0); // Reset pointer
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>List Cadets - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --success: #48bb78;
            --warning: #ed8936;
            --danger: #f56565;
        }
        
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .header { background: var(--primary); color: white; padding: 25px 30px; }
        .back-btn { color: white; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 20px; }
        .content { padding: 30px; }
        
        /* FILTERS */
        .filters {
            background: #f7fafc;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .filter-group { flex: 1; min-width: 200px; }
        .filter-group label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--secondary); }
        .filter-group select { width: 100%; padding: 10px; border: 2px solid #e2e8f0; border-radius: 5px; }
        .btn-filter { background: var(--accent); color: white; border: none; padding: 10px 25px; border-radius: 5px; cursor: pointer; }
        
        /* STATS */
        .stats { display: flex; gap: 15px; margin-bottom: 30px; }
        .stat-card { flex: 1; background: white; padding: 20px; border-radius: 10px; text-align: center; border-top: 5px solid var(--accent); box-shadow: 0 3px 10px rgba(0,0,0,0.1); }
        .stat-number { font-size: 2.5rem; font-weight: 700; color: var(--primary); }
        .stat-label { color: var(--secondary); font-size: 0.9rem; }
        
        /* TABLE */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { background: var(--primary); color: white; padding: 15px; text-align: left; }
        td { padding: 15px; border-bottom: 1px solid #e2e8f0; }
        tr:hover { background: #f7fafc; }
        
        .badge { padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .badge-darat { background: #fef3c7; color: #92400e; }
        .badge-laut { background: #dbeafe; color: #1e40af; }
        .badge-udara { background: #e0e7ff; color: #3730a3; }
        
        .badge-junior { background: #f0f9ff; color: #0369a1; }
        .badge-intermediate { background: #f0fdf4; color: #166534; }
        .badge-senior { background: #fef2f2; color: #991b1b; }
        
        .actions { display: flex; gap: 10px; }
        .btn-action { padding: 5px 10px; border-radius: 5px; text-decoration: none; font-size: 0.9rem; }
        .btn-view { background: var(--accent); color: white; }
        .btn-edit { background: var(--warning); color: white; }
        .btn-delete { background: var(--danger); color: white; }
        
        @media (max-width: 768px) {
            .filters { flex-direction: column; }
            .stats { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <h1><i class="fas fa-users"></i> List Cadets</h1>
            <p>Filter and view cadets by service type and rank level</p>
        </div>
        
        <div class="content">
            <!-- FILTERS -->
            <form method="GET" class="filters">
                <div class="filter-group">
                    <label>Service Type</label>
                    <select name="service">
                        <option value="">All Services</option>
                        <option value="darat" <?php echo $service == 'darat' ? 'selected' : ''; ?>>Darat</option>
                        <option value="laut" <?php echo $service == 'laut' ? 'selected' : ''; ?>>Laut</option>
                        <option value="udara" <?php echo $service == 'udara' ? 'selected' : ''; ?>>Udara</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Rank Level</label>
                    <select name="rank">
                        <option value="">All Ranks</option>
                        <option value="junior" <?php echo $rank == 'junior' ? 'selected' : ''; ?>>Junior</option>
                        <option value="intermediate" <?php echo $rank == 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                        <option value="senior" <?php echo $rank == 'senior' ? 'selected' : ''; ?>>Senior</option>
                    </select>
                </div>
                
                <button type="submit" class="btn-filter">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                
                <a href="list_cadets.php" class="btn-filter" style="background: var(--secondary);">
                    <i class="fas fa-redo"></i> Clear
                </a>
            </form>
            
            <!-- STATISTICS -->
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $totalCadets; ?></div>
                    <div class="stat-label">Total Cadets</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">
                        <?php 
                        $daratCount = 0;
                        $cadets->data_seek(0);
                        while($row = $cadets->fetch_assoc()) {
                            if($row['service_type'] == 'darat') $daratCount++;
                        }
                        echo $daratCount;
                        $cadets->data_seek(0);
                        ?>
                    </div>
                    <div class="stat-label">Darat Service</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">
                        <?php 
                        $seniorCount = 0;
                        while($row = $cadets->fetch_assoc()) {
                            if($row['rank_level'] == 'senior') $seniorCount++;
                        }
                        echo $seniorCount;
                        $cadets->data_seek(0);
                        ?>
                    </div>
                    <div class="stat-label">Senior Rank</div>
                </div>
            </div>
            
            <!-- TABLE -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Military No.</th>
                            <th>Name</th>
                            <th>Service Type</th>
                            <th>Rank Level</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($totalCadets > 0): ?>
                            <?php $counter = 1; ?>
                            <?php while($cadet = $cadets->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($cadet['military_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($cadet['name']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $cadet['service_type']; ?>">
                                            <?php echo ucfirst($cadet['service_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $cadet['rank_level']; ?>">
                                            <?php echo ucfirst($cadet['rank_level']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($cadet['created_at'])); ?></td>
                                    <td class="actions">
                                        <a href="view_cadet.php?id=<?php echo $cadet['user_id']; ?>" class="btn-action btn-view">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="register_user.php?edit=<?php echo $cadet['user_id']; ?>" class="btn-action btn-edit">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-users-slash" style="font-size: 3rem; color: #cbd5e0; margin-bottom: 15px; display: block;"></i>
                                    <h3 style="color: var(--secondary);">No cadets found</h3>
                                    <p style="color: #718096;">Try adjusting your filters or register new cadets.</p>
                                    <a href="register_user.php" style="display: inline-block; margin-top: 15px; padding: 10px 20px; background: var(--accent); color: white; text-decoration: none; border-radius: 5px;">
                                        <i class="fas fa-user-plus"></i> Register New Cadet
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- EXPORT OPTIONS -->
            <div style="margin-top: 30px; padding: 20px; background: #f7fafc; border-radius: 10px; text-align: center;">
                <h3 style="color: var(--primary); margin-bottom: 15px;">Export Data</h3>
                <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                    <button style="padding: 10px 20px; background: var(--success); color: white; border: none; border-radius: 5px; cursor: pointer;">
                        <i class="fas fa-file-excel"></i> Export to Excel
                    </button>
                    <button style="padding: 10px 20px; background: var(--danger); color: white; border: none; border-radius: 5px; cursor: pointer;">
                        <i class="fas fa-file-pdf"></i> Export to PDF
                    </button>
                    <button style="padding: 10px 20px; background: var(--secondary); color: white; border: none; border-radius: 5px; cursor: pointer;">
                        <i class="fas fa-print"></i> Print List
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

$cadet_id = $_GET['id'] ?? 0;

// Fetch cadet data
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'cadet'");
$stmt->execute([$cadet_id]);
$cadet = $stmt->fetch();

if (!$cadet) {
    header('Location: list_cadets.php?error=cadet_not_found');
    exit();
}

// Handle update
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $service_type = $_POST['service_type'] ?? '';
    $rank_level = $_POST['rank_level'] ?? '';
    $allowance_rate = $_POST['allowance_rate'] ?? 100.00;
    $performance_score = $_POST['performance_score'] ?? 0;
    
    $update_stmt = $pdo->prepare("
        UPDATE users 
        SET name = ?, email = ?, service_type = ?, rank_level = ?, 
            allowance_rate = ?, performance_score = ?
        WHERE user_id = ?
    ");
    
    if ($update_stmt->execute([$name, $email, $service_type, $rank_level, $allowance_rate, $performance_score, $cadet_id])) {
        $message = 'success:Kadet berjaya dikemaskini!';
        header('Location: list_cadets.php?message=updated');
        exit();
    } else {
        $message = 'error:Gagal mengemaskini kadet';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Kadet - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --success: #48bb78;
            --danger: #f56565;
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
            max-width: 800px;
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
        
        /* MESSAGE */
        .alert {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .alert.success {
            background: #d4edda;
            color: #155724;
            border-left: 5px solid var(--success);
        }
        
        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 5px solid var(--danger);
        }
        
        /* FORM */
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        input, select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
        }
        
        input:focus, select:focus {
            border-color: var(--accent);
            outline: none;
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
            gap: 10px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: var(--accent);
            color: white;
        }
        
        .btn-primary:hover {
            background: #2c5282;
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: var(--secondary);
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }
        
        /* INFO CARD */
        .info-card {
            background: #f7fafc;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid var(--accent);
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
                <i class="fas fa-edit"></i> Edit Kadet
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">Kemaskini maklumat kadet</p>
        </div>
        
        <div class="content">
            <!-- MESSAGE -->
            <?php if ($message): 
                list($type, $text) = explode(':', $message, 2);
            ?>
                <div class="alert <?php echo $type; ?>">
                    <i class="fas <?php echo $type == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                    <div><?php echo $text; ?></div>
                </div>
            <?php endif; ?>
            
            <!-- INFO CARD -->
            <div class="info-card">
                <h4><i class="fas fa-info-circle"></i> Maklumat Kadet</h4>
                <p><strong>ID Kadet:</strong> #<?php echo $cadet['user_id']; ?></p>
                <p><strong>No. Tentera:</strong> <?php echo $cadet['military_number']; ?></p>
                <p><strong>Daftar Pada:</strong> <?php echo date('d/m/Y', strtotime($cadet['created_at'])); ?></p>
            </div>
            
            <!-- EDIT FORM -->
            <div class="section-title">
                <i class="fas fa-user-edit"></i> Kemaskini Maklumat
            </div>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label>Nama Penuh *</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($cadet['name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($cadet['email'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label>Jenis Perkhidmatan *</label>
                    <select name="service_type" required>
                        <option value="">Pilih Perkhidmatan</option>
                        <option value="darat" <?php echo $cadet['service_type'] == 'darat' ? 'selected' : ''; ?>>Angkatan Darat</option>
                        <option value="laut" <?php echo $cadet['service_type'] == 'laut' ? 'selected' : ''; ?>>Angkatan Laut</option>
                        <option value="udara" <?php echo $cadet['service_type'] == 'udara' ? 'selected' : ''; ?>>Angkatan Udara</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Tahap Pangkat *</label>
                    <select name="rank_level" required>
                        <option value="">Pilih Tahap</option>
                        <option value="junior" <?php echo $cadet['rank_level'] == 'junior' ? 'selected' : ''; ?>>Junior</option>
                        <option value="intermediate" <?php echo $cadet['rank_level'] == 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                        <option value="senior" <?php echo $cadet['rank_level'] == 'senior' ? 'selected' : ''; ?>>Senior</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Kadar Elaun (RM)</label>
                    <input type="number" name="allowance_rate" step="0.01" min="0" value="<?php echo $cadet['allowance_rate'] ?? 100.00; ?>">
                </div>
                
                <div class="form-group">
                    <label>Skor Performance (0-10)</label>
                    <input type="number" name="performance_score" step="0.1" min="0" max="10" value="<?php echo $cadet['performance_score'] ?? 0; ?>">
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Simpan Perubahan
                    </button>
                    <a href="list_cadets.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Auto-focus first input
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('input[name="name"]').focus();
        });
    </script>
</body>
</html>
<?php
// admin/manage_allowance.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('admin');
$user = (new Auth())->getCurrentUser();
$db = new Database();

$message = '';
$messageType = '';
$calculatedResults = null;
$allowanceHistory = [];
$cadets = [];

// Get all cadets
$sql = "SELECT user_id, military_number, name, rank_level, service_type FROM users WHERE role = 'cadet' ORDER BY name";
$result = $db->query($sql);
while ($row = $result->fetch_assoc()) {
    $cadets[] = $row;
}

// Get allowance history
$historySql = "SELECT ac.*, u.name as cadet_name, u.military_number 
              FROM allowance_calculations ac 
              JOIN users u ON ac.user_id = u.user_id 
              ORDER BY ac.calculated_at DESC 
              LIMIT 10";
$historyResult = $db->query($historySql);
while ($row = $historyResult->fetch_assoc()) {
    $allowanceHistory[] = $row;
}

// Handle rate update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_rate'])) {
    $allowance_rate_junior = floatval($_POST['allowance_rate_junior']);
    $allowance_rate_intermediate = floatval($_POST['allowance_rate_intermediate']);
    $allowance_rate_senior = floatval($_POST['allowance_rate_senior']);
    $training_rate_latihan_tempatan = floatval($_POST['training_rate_latihan_tempatan']);
    $training_rate_latihan_berterusan = floatval($_POST['training_rate_latihan_berterusan']);
    $training_rate_latihan_kem = floatval($_POST['training_rate_latihan_kem']);
    
    // Update rates in users table (admin row)
    $updateSql = "UPDATE users SET 
                  allowance_rate_junior = ?, 
                  allowance_rate_intermediate = ?, 
                  allowance_rate_senior = ?, 
                  training_rate_latihan_tempatan = ?, 
                  training_rate_latihan_berterusan = ?, 
                  training_rate_latihan_kem = ? 
                  WHERE user_id = ?";
    $stmt = $db->prepare($updateSql);
    $stmt->bind_param("dddddii", 
        $allowance_rate_junior,
        $allowance_rate_intermediate,
        $allowance_rate_senior,
        $training_rate_latihan_tempatan,
        $training_rate_latihan_berterusan,
        $training_rate_latihan_kem,
        $user['user_id']
    );
    
    if ($stmt->execute()) {
        // Log activity
        $logDesc = "Updated allowance rates: Junior(RM{$allowance_rate_junior}), Intermediate(RM{$allowance_rate_intermediate}), Senior(RM{$allowance_rate_senior})";
        $logSql = "INSERT INTO activity_logs (user_id, activity_type, description) VALUES (?, 'allowance_rate_updated', ?)";
        $logStmt = $db->prepare($logSql);
        $logStmt->bind_param("is", $user['user_id'], $logDesc);
        $logStmt->execute();
        
        $message = 'Kadar allowance berjaya dikemaskini!';
        $messageType = 'success';
    } else {
        $message = 'Database error: ' . $stmt->error;
        $messageType = 'error';
    }
}

// Handle allowance calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate_allowance'])) {
    $month_year = $_POST['month_year'];
    $cadet_id = $_POST['cadet_id'];
    
    // Validate inputs
    if (empty($month_year) || empty($cadet_id)) {
        $message = 'Sila pilih bulan dan kadet';
        $messageType = 'error';
    } else {
        // Get cadet details
        $cadetSql = "SELECT * FROM users WHERE user_id = ?";
        $cadetStmt = $db->prepare($cadetSql);
        $cadetStmt->bind_param("i", $cadet_id);
        $cadetStmt->execute();
        $cadetResult = $cadetStmt->get_result();
        $cadet = $cadetResult->fetch_assoc();
        
        if (!$cadet) {
            $message = 'Kadet tidak ditemui';
            $messageType = 'error';
        } else {
            // Calculate attendance rate for the month
            $month = date('m', strtotime($month_year));
            $year = date('Y', strtotime($month_year));
            
            // Get training sessions for the month
            $sessionSql = "SELECT session_id, training_type, training_rate FROM training_sessions 
                          WHERE MONTH(training_date) = ? AND YEAR(training_date) = ?";
            $sessionStmt = $db->prepare($sessionSql);
            $sessionStmt->bind_param("ii", $month, $year);
            $sessionStmt->execute();
            $sessions = $sessionStmt->get_result();
            
            $totalSessions = 0;
            $attendedSessions = 0;
            $trainingTypeRates = [];
            
            while ($session = $sessions->fetch_assoc()) {
                $totalSessions++;
                
                // Check attendance for this session
                $attendanceSql = "SELECT * FROM attendance 
                                 WHERE user_id = ? AND session_id = ? AND status IN ('present', 'excused')";
                $attendanceStmt = $db->prepare($attendanceSql);
                $attendanceStmt->bind_param("ii", $cadet_id, $session['session_id']);
                $attendanceStmt->execute();
                $attendanceResult = $attendanceStmt->get_result();
                
                if ($attendanceResult->num_rows > 0) {
                    $attendedSessions++;
                    
                    // Store training type rate for calculation
                    $trainingType = $session['training_type'];
                    $trainingRate = $session['training_rate'];
                    
                    if (!isset($trainingTypeRates[$trainingType])) {
                        $trainingTypeRates[$trainingType] = $trainingRate;
                    }
                }
            }
            
            // Calculate attendance rate
            $attendance_rate = ($totalSessions > 0) ? ($attendedSessions / $totalSessions) * 100 : 0;
            
            // Get allowance rate based on rank
            $rankRate = 0;
            switch ($cadet['rank_level']) {
                case 'junior':
                    $rankRate = $cadet['allowance_rate_junior'];
                    break;
                case 'intermediate':
                    $rankRate = $cadet['allowance_rate_intermediate'];
                    break;
                case 'senior':
                    $rankRate = $cadet['allowance_rate_senior'];
                    break;
            }
            
            // Get training type rate (average)
            $trainingTypeRate = 0;
            if (!empty($trainingTypeRates)) {
                $trainingTypeRate = array_sum($trainingTypeRates) / count($trainingTypeRates);
            }
            
            // Base amount (from user table)
            $baseAmount = $cadet['allowance_rate_' . $cadet['rank_level']] ?? 100.00;
            
            // Calculate allowance
            $calculatedAmount = $baseAmount * ($attendance_rate / 100);
            
            // Add training type bonus
            $calculatedAmount += ($calculatedAmount * $trainingTypeRate / 100);
            
            // Performance bonus based on grade
            $performanceBonus = 0;
            if ($cadet['performance_grade']) {
                $gradeBonus = [
                    'A+' => 30,
                    'A' => 25,
                    'B+' => 20,
                    'B' => 15,
                    'B-' => 10,
                    'C+' => 5,
                    'C' => 0,
                    'C-' => -5,
                    'D' => -10,
                    'E' => -15
                ];
                
                $bonusPercentage = $gradeBonus[$cadet['performance_grade']] ?? 0;
                $performanceBonus = ($calculatedAmount * $bonusPercentage) / 100;
            }
            
            // Total amount
            $totalAmount = $calculatedAmount + $performanceBonus;
            
            // Store calculation results
            $calculatedResults = [
                'cadet' => $cadet,
                'month_year' => $month_year,
                'attendance_rate' => round($attendance_rate, 2),
                'total_sessions' => $totalSessions,
                'attended_sessions' => $attendedSessions,
                'rank_rate' => $rankRate,
                'training_type_rate' => $trainingTypeRate,
                'base_amount' => $baseAmount,
                'calculated_amount' => round($calculatedAmount, 2),
                'performance_bonus' => round($performanceBonus, 2),
                'total_amount' => round($totalAmount, 2),
                'training_types' => $trainingTypeRates
            ];
        }
    }
}

// Handle save calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_calculation'])) {
    $user_id = $_POST['user_id'];
    $month_year = $_POST['month_year'];
    $attendance_rate = floatval($_POST['attendance_rate']);
    $training_type_rate = floatval($_POST['training_type_rate']);
    $rank_rate = floatval($_POST['rank_rate']);
    $base_amount = floatval($_POST['base_amount']);
    $calculated_amount = floatval($_POST['calculated_amount']);
    $performance_bonus = floatval($_POST['performance_bonus']);
    $total_amount = floatval($_POST['total_amount']);
    
    // Check if already exists
    $checkSql = "SELECT * FROM allowance_calculations WHERE user_id = ? AND month_year = ?";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->bind_param("is", $user_id, $month_year);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows > 0) {
        // Update existing
        $saveSql = "UPDATE allowance_calculations SET 
                   attendance_rate = ?, 
                   training_type_rate = ?, 
                   rank_rate = ?, 
                   base_amount = ?, 
                   calculated_amount = ?, 
                   performance_bonus = ?, 
                   total_amount = ?, 
                   calculated_by = ?, 
                   calculated_at = NOW() 
                   WHERE user_id = ? AND month_year = ?";
    } else {
        // Insert new
        $saveSql = "INSERT INTO allowance_calculations 
                   (user_id, month_year, attendance_rate, training_type_rate, rank_rate, 
                    base_amount, calculated_amount, performance_bonus, total_amount, calculated_by) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    }
    
    $saveStmt = $db->prepare($saveSql);
    if (strpos($saveSql, 'UPDATE') !== false) {
        $saveStmt->bind_param("dddddddiis", 
            $attendance_rate, $training_type_rate, $rank_rate, $base_amount,
            $calculated_amount, $performance_bonus, $total_amount, 
            $user['user_id'], $user_id, $month_year
        );
    } else {
        $saveStmt->bind_param("isdddddddi", 
            $user_id, $month_year, $attendance_rate, $training_type_rate, $rank_rate,
            $base_amount, $calculated_amount, $performance_bonus, $total_amount, $user['user_id']
        );
    }
    
    if ($saveStmt->execute()) {
        // Log activity
        $cadetName = $_POST['cadet_name'] ?? '';
        $logDesc = "Calculated allowance for {$cadetName} - {$month_year}: RM{$total_amount}";
        $logSql = "INSERT INTO activity_logs (user_id, activity_type, description, related_id) 
                  VALUES (?, 'allowance_calculated', ?, ?)";
        $logStmt = $db->prepare($logSql);
        $logStmt->bind_param("isi", $user['user_id'], $logDesc, $saveStmt->insert_id);
        $logStmt->execute();
        
        $message = 'Pengiraan allowance berjaya disimpan!';
        $messageType = 'success';
        
        // Refresh history
        header("Location: manage_allowance.php?success=1");
        exit();
    } else {
        $message = 'Database error: ' . $saveStmt->error;
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Allowance - CAAMS</title>
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
        
        /* MESSAGE ALERT */
        .alert {
            padding: 15px 20px;
            margin: 20px 30px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.3s ease-out;
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
        
        .alert.info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 5px solid var(--accent);
        }
        
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* MAIN CONTENT */
        .content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            padding: 30px;
        }
        
        @media (max-width: 1100px) {
            .content {
                grid-template-columns: 1fr;
            }
        }
        
        /* FORM SECTIONS */
        .form-section, .preview-section {
            padding: 25px;
            background: #f7fafc;
            border-radius: 15px;
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
        
        /* FORM STYLES */
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        /* BUTTONS */
        .btn {
            padding: 15px 30px;
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
            width: 100%;
            margin-top: 10px;
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
        
        /* RATE INPUTS */
        .rate-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .rate-input {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
        }
        
        .rate-input label {
            font-size: 0.9rem;
            color: #718096;
        }
        
        .rate-input input {
            border: none;
            padding: 5px 0;
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
        }
        
        /* CALCULATION RESULT */
        .calculation-result {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-top: 20px;
        }
        
        .result-header {
            background: var(--primary);
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .result-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px dashed #e2e8f0;
        }
        
        .result-item.total {
            border-top: 3px solid var(--accent);
            border-bottom: none;
            padding-top: 15px;
            margin-top: 15px;
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--primary);
        }
        
        .result-value {
            font-weight: 600;
            color: var(--primary);
        }
        
        .result-value.amount {
            font-size: 1.3rem;
            color: var(--success);
        }
        
        /* HISTORY TABLE */
        .history-section {
            grid-column: 1 / -1;
            padding: 30px;
            background: white;
            border-radius: 15px;
            margin-top: 20px;
        }
        
        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .history-table th {
            background: var(--primary);
            color: white;
            padding: 12px;
            text-align: left;
        }
        
        .history-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .amount-cell {
            font-weight: bold;
            color: var(--success);
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .rate-inputs {
                grid-template-columns: 1fr;
            }
            
            .history-table {
                display: block;
                overflow-x: auto;
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
                <i class="fas fa-calculator"></i> Manage Allowance
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">Formula & calculation untuk allowance kadet (rate berbeza mengikut jenis latihan & pangkat)</p>
        </div>
        
        <!-- INFORMATION ALERT -->
        <div class="alert info">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Formula Allowance:</strong> 
                (Base Rate × Attendance Rate) + Training Type Bonus + Performance Bonus = Total Allowance
            </div>
        </div>
        
        <!-- MESSAGE ALERT -->
        <?php if ($message): ?>
            <div class="alert <?php echo $messageType; ?>">
                <i class="fas <?php 
                    echo $messageType == 'success' ? 'fa-check-circle' : 
                         ($messageType == 'info' ? 'fa-info-circle' : 'fa-exclamation-triangle'); 
                ?>"></i>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>
        
        <!-- MAIN CONTENT -->
        <div class="content">
            <!-- LEFT: SET RATES -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="fas fa-cog"></i> Set Allowance Rates
                </h2>
                
                <form method="POST" action="">
                    <input type="hidden" name="update_rate" value="1">
                    
                    <h3 style="margin: 20px 0 15px 0; color: var(--secondary);">
                        <i class="fas fa-user-graduate"></i> Allowance Rate Mengikut Pangkat
                    </h3>
                    
                    <div class="rate-inputs">
                        <div class="rate-input">
                            <label>Junior</label>
                            <input type="number" 
                                   name="allowance_rate_junior" 
                                   value="50.00" 
                                   step="0.01" 
                                   min="0" 
                                   required>
                            <span style="color: #718096; font-size: 0.9rem;">RM/day</span>
                        </div>
                        
                        <div class="rate-input">
                            <label>Intermediate</label>
                            <input type="number" 
                                   name="allowance_rate_intermediate" 
                                   value="70.00" 
                                   step="0.01" 
                                   min="0" 
                                   required>
                            <span style="color: #718096; font-size: 0.9rem;">RM/day</span>
                        </div>
                        
                        <div class="rate-input">
                            <label>Senior</label>
                            <input type="number" 
                                   name="allowance_rate_senior" 
                                   value="100.00" 
                                   step="0.01" 
                                   min="0" 
                                   required>
                            <span style="color: #718096; font-size: 0.9rem;">RM/day</span>
                        </div>
                    </div>
                    
                    <h3 style="margin: 25px 0 15px 0; color: var(--secondary);">
                        <i class="fas fa-dumbbell"></i> Training Type Bonus Rate
                    </h3>
                    
                    <div class="rate-inputs">
                        <div class="rate-input">
                            <label>Latihan Tempatan</label>
                            <input type="number" 
                                   name="training_rate_latihan_tempatan" 
                                   value="10.00" 
                                   step="0.01" 
                                   min="0" 
                                   required>
                            <span style="color: #718096; font-size: 0.9rem;">% bonus</span>
                        </div>
                        
                        <div class="rate-input">
                            <label>Latihan Berterusan</label>
                            <input type="number" 
                                   name="training_rate_latihan_berterusan" 
                                   value="15.00" 
                                   step="0.01" 
                                   min="0" 
                                   required>
                            <span style="color: #718096; font-size: 0.9rem;">% bonus</span>
                        </div>
                        
                        <div class="rate-input">
                            <label>Latihan Kem</label>
                            <input type="number" 
                                   name="training_rate_latihan_kem" 
                                   value="20.00" 
                                   step="0.01" 
                                   min="0" 
                                   required>
                            <span style="color: #718096; font-size: 0.9rem;">% bonus</span>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Rate Changes
                    </button>
                </form>
            </div>
            
            <!-- RIGHT: CALCULATE ALLOWANCE -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="fas fa-calculator"></i> Calculate Allowance
                </h2>
                
                <form method="POST" action="" id="calculateForm">
                    <input type="hidden" name="calculate_allowance" value="1">
                    
                    <div class="form-group">
                        <label for="month_year">Bulan & Tahun</label>
                        <input type="month" 
                               id="month_year" 
                               name="month_year" 
                               value="<?php echo date('Y-m'); ?>"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="cadet_id">Pilih Kadet</label>
                        <select id="cadet_id" name="cadet_id" required>
                            <option value="">Pilih Kadet</option>
                            <?php foreach ($cadets as $cadet): ?>
                                <option value="<?php echo $cadet['user_id']; ?>">
                                    <?php echo htmlspecialchars($cadet['military_number'] . ' - ' . $cadet['name'] . ' (' . $cadet['rank_level'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-calculator"></i> Calculate Allowance
                    </button>
                </form>
                
                <!-- CALCULATION RESULT -->
                <?php if ($calculatedResults): ?>
                <div class="calculation-result">
                    <div class="result-header">
                        <h3 style="margin: 0;">
                            <i class="fas fa-file-invoice-dollar"></i> 
                            Allowance Calculation Result
                        </h3>
                    </div>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="save_calculation" value="1">
                        <input type="hidden" name="user_id" value="<?php echo $calculatedResults['cadet']['user_id']; ?>">
                        <input type="hidden" name="cadet_name" value="<?php echo htmlspecialchars($calculatedResults['cadet']['name']); ?>">
                        <input type="hidden" name="month_year" value="<?php echo $calculatedResults['month_year']; ?>">
                        <input type="hidden" name="attendance_rate" value="<?php echo $calculatedResults['attendance_rate']; ?>">
                        <input type="hidden" name="training_type_rate" value="<?php echo $calculatedResults['training_type_rate']; ?>">
                        <input type="hidden" name="rank_rate" value="<?php echo $calculatedResults['rank_rate']; ?>">
                        <input type="hidden" name="base_amount" value="<?php echo $calculatedResults['base_amount']; ?>">
                        <input type="hidden" name="calculated_amount" value="<?php echo $calculatedResults['calculated_amount']; ?>">
                        <input type="hidden" name="performance_bonus" value="<?php echo $calculatedResults['performance_bonus']; ?>">
                        <input type="hidden" name="total_amount" value="<?php echo $calculatedResults['total_amount']; ?>">
                        
                        <div class="result-item">
                            <span>Kadet:</span>
                            <span class="result-value"><?php echo htmlspecialchars($calculatedResults['cadet']['name']); ?></span>
                        </div>
                        
                        <div class="result-item">
                            <span>Bulan:</span>
                            <span class="result-value"><?php echo date('F Y', strtotime($calculatedResults['month_year'])); ?></span>
                        </div>
                        
                        <div class="result-item">
                            <span>Pangkat:</span>
                            <span class="result-value"><?php echo $calculatedResults['cadet']['rank_level']; ?></span>
                        </div>
                        
                        <div class="result-item">
                            <span>Kehadiran:</span>
                            <span class="result-value">
                                <?php echo $calculatedResults['attended_sessions']; ?> / <?php echo $calculatedResults['total_sessions']; ?> sesi
                                (<?php echo $calculatedResults['attendance_rate']; ?>%)
                            </span>
                        </div>
                        
                        <div class="result-item">
                            <span>Base Rate (Pangkat):</span>
                            <span class="result-value">RM <?php echo number_format($calculatedResults['rank_rate'], 2); ?></span>
                        </div>
                        
                        <div class="result-item">
                            <span>Training Type Bonus:</span>
                            <span class="result-value"><?php echo $calculatedResults['training_type_rate']; ?>%</span>
                        </div>
                        
                        <?php if (!empty($calculatedResults['training_types'])): ?>
                        <div class="result-item">
                            <span>Jenis Latihan:</span>
                            <span class="result-value">
                                <?php foreach ($calculatedResults['training_types'] as $type => $rate): ?>
                                    <span style="display: inline-block; background: #e2e8f0; padding: 2px 8px; border-radius: 4px; margin: 2px;">
                                        <?php echo $type; ?> (<?php echo $rate; ?>%)
                                    </span>
                                <?php endforeach; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="result-item">
                            <span>Base Amount:</span>
                            <span class="result-value">RM <?php echo number_format($calculatedResults['calculated_amount'], 2); ?></span>
                        </div>
                        
                        <?php if ($calculatedResults['performance_bonus'] != 0): ?>
                        <div class="result-item">
                            <span>Performance Bonus/Potongan:</span>
                            <span class="result-value" style="color: <?php echo $calculatedResults['performance_bonus'] > 0 ? 'var(--success)' : 'var(--danger)'; ?>">
                                <?php echo $calculatedResults['performance_bonus'] > 0 ? '+' : ''; ?>
                                RM <?php echo number_format($calculatedResults['performance_bonus'], 2); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="result-item total">
                            <span>TOTAL ALLOWANCE:</span>
                            <span class="result-value amount">RM <?php echo number_format($calculatedResults['total_amount'], 2); ?></span>
                        </div>
                        
                        <button type="submit" class="btn btn-success" style="width: 100%; margin-top: 20px;">
                            <i class="fas fa-save"></i> Save Calculation
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- ALLOWANCE HISTORY -->
        <div class="history-section">
            <h2 class="section-title">
                <i class="fas fa-history"></i> Allowance History
            </h2>
            
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Cadet</th>
                        <th>Period</th>
                        <th>Attendance Rate</th>
                        <th>Base Amount</th>
                        <th>Performance Bonus</th>
                        <th>Total Amount</th>
                        <th>Calculated By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($allowanceHistory)): ?>
                        <?php foreach ($allowanceHistory as $history): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($history['calculated_at'])); ?></td>
                                <td><?php echo htmlspecialchars($history['cadet_name']); ?></td>
                                <td><?php echo date('F Y', strtotime($history['month_year'] . '-01')); ?></td>
                                <td><?php echo $history['attendance_rate']; ?>%</td>
                                <td class="amount-cell">RM <?php echo number_format($history['calculated_amount'], 2); ?></td>
                                <td class="amount-cell" style="color: <?php echo $history['performance_bonus'] > 0 ? 'var(--success)' : 'var(--danger)'; ?>">
                                    <?php echo $history['performance_bonus'] > 0 ? '+' : ''; ?>
                                    RM <?php echo number_format($history['performance_bonus'], 2); ?>
                                </td>
                                <td class="amount-cell">RM <?php echo number_format($history['total_amount'], 2); ?></td>
                                <td>Admin</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 30px; color: var(--secondary);">
                                <i class="fas fa-file-invoice-dollar" style="font-size: 2rem; opacity: 0.3; margin-bottom: 10px;"></i>
                                <p>Belum ada pengiraan allowance</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
        // Show toast notification
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                background: ${type === 'success' ? '#48bb78' : '#f56565'};
                color: white;
                border-radius: 8px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
                z-index: 1000;
                animation: slideInRight 0.3s ease-out;
            `;
            
            toast.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i>
                ${message}
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOutRight 0.3s ease-out';
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 3000);
        }
        
        // Add CSS animations for toast
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
        
        // Auto focus on calculation form if result exists
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($calculatedResults): ?>
                document.getElementById('calculateForm').scrollIntoView({ behavior: 'smooth' });
            <?php endif; ?>
        });
    </script>
</body>
</html> 
    </php>
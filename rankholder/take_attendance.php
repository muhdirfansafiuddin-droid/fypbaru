<?php
// rankholder/take_attendance.php - MOBILE FRIENDLY
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('rankholder');
$user = (new Auth())->getCurrentUser();
$db = new Database();

$message = '';
$messageType = '';
$selectedActivity = null;
$attendanceHistory = [];

// Get activity ID from URL
$activityId = $_GET['activity'] ?? 0;

if ($activityId) {
    // Get activity details
    $sql = "SELECT ts.*, u.name as creator_name,
            (SELECT COUNT(*) FROM attendance a WHERE a.session_id = ts.session_id) as attendance_count
            FROM training_sessions ts
            JOIN users u ON ts.created_by = u.user_id
            WHERE ts.session_id = ? AND ts.is_active = 1 AND ts.expires_at > NOW()";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $activityId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $selectedActivity = $result->fetch_assoc();
        
        // Get recent attendance for this activity
        $historySql = "SELECT a.*, u.name, u.military_number 
                      FROM attendance a
                      JOIN users u ON a.user_id = u.user_id
                      WHERE a.session_id = ?
                      ORDER BY a.recorded_at DESC
                      LIMIT 10";
        $historyStmt = $db->prepare($historySql);
        $historyStmt->bind_param("i", $activityId);
        $historyStmt->execute();
        $attendanceHistory = $historyStmt->get_result();
    }
}

// Handle attendance submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $militaryNumber = trim($_POST['military_number'] ?? '');
    $attendanceId = (int)($_POST['activity_id'] ?? 0);
    
    if (empty($militaryNumber)) {
        $message = "Sila masukkan nombor tentera";
        $messageType = "error";
    } elseif (!$attendanceId) {
        $message = "Aktiviti tidak sah";
        $messageType = "error";
    } else {
        // 1. Check if cadet exists
        $cadetSql = "SELECT user_id, name FROM users 
                    WHERE military_number = ? AND role = 'cadet'";
        $cadetStmt = $db->prepare($cadetSql);
        $cadetStmt->bind_param("s", $militaryNumber);
        $cadetStmt->execute();
        $cadetResult = $cadetStmt->get_result();
        
        if ($cadetResult->num_rows === 0) {
            $message = "Nombor tentera tidak dijumpai dalam sistem";
            $messageType = "error";
        } else {
            $cadet = $cadetResult->fetch_assoc();
            $cadetId = $cadet['user_id'];
            $cadetName = $cadet['name'];
            
            // 2. Check if already attended
            $checkSql = "SELECT * FROM attendance 
                        WHERE user_id = ? AND session_id = ?";
            $checkStmt = $db->prepare($checkSql);
            $checkStmt->bind_param("ii", $cadetId, $attendanceId);
            $checkStmt->execute();
            
            if ($checkStmt->get_result()->num_rows > 0) {
                $message = "Kadet ini sudah mendaftar kehadiran";
                $messageType = "warning";
            } else {
                // 3. Insert attendance record
                $insertSql = "INSERT INTO attendance 
                             (user_id, session_id, date, status, recorded_at) 
                             VALUES (?, ?, CURDATE(), 'present', NOW())";
                $insertStmt = $db->prepare($insertSql);
                $insertStmt->bind_param("ii", $cadetId, $attendanceId);
                
                if ($insertStmt->execute()) {
                    $message = "✅ Kehadiran berjaya direkod!<br><strong>{$cadetName}</strong>";
                    $messageType = "success";
                    
                    // Log activity
                    $logSql = "INSERT INTO activity_logs (user_id, activity_type, description, related_id) 
                              VALUES (?, 'attendance_taken', ?, ?)";
                    $logStmt = $db->prepare($logSql);
                    $desc = "Recorded attendance for {$cadetName} in activity #{$attendanceId}";
                    $logStmt->bind_param("isi", $user['user_id'], $desc, $attendanceId);
                    $logStmt->execute();
                    
                    // Refresh attendance history
                    $historyStmt = $db->prepare($historySql);
                    $historyStmt->bind_param("i", $attendanceId);
                    $historyStmt->execute();
                    $attendanceHistory = $historyStmt->get_result();
                    
                    // Auto-clear form after 3 seconds
                    header("refresh:3;url=take_attendance.php?activity=" . $activityId);
                } else {
                    $message = "Ralat sistem. Sila cuba lagi.";
                    $messageType = "error";
                }
            }
        }
    }
}

// Session time labels
$sessionTimeLabels = [
    'pagi' => 'Pagi (06:00 - 10:00)',
    'tengah hari' => 'Tengah Hari (10:00 - 14:00)',
    'petang' => 'Petang (14:00 - 18:00)',
    'malam' => 'Malam (18:00 - 22:00)'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Ambil Kehadiran - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --success: #48bb78;
            --warning: #ed8936;
            --danger: #f56565;
            --light: #f7fafc;
            --dark: #2d3748;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }
        
        body {
            background: #f0f2f5;
            color: var(--dark);
            line-height: 1.6;
            -webkit-tap-highlight-color: transparent;
        }
        
        /* MOBILE CONTAINER */
        .mobile-container {
            max-width: 100%;
            min-height: 100vh;
            background: white;
            position: relative;
        }
        
        /* HEADER - MOBILE STICKY */
        .mobile-header {
            background: var(--primary);
            color: white;
            padding: 20px 16px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .back-btn {
            color: white;
            background: rgba(255,255,255,0.15);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            cursor: pointer;
            flex-shrink: 0;
        }
        
        .header-text h1 {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .header-text p {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        
        /* USER INFO */
        .user-info {
            background: rgba(255,255,255,0.1);
            padding: 10px 12px;
            border-radius: 8px;
            margin-top: 12px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .user-info i {
            font-size: 1rem;
        }
        
        /* MAIN CONTENT - MOBILE SCROLL */
        .mobile-content {
            padding: 0;
        }
        
        /* ACTIVITY CARD */
        .activity-card {
            margin: 16px;
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-top: 4px solid var(--accent);
        }
        
        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .activity-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .activity-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            background: #d4edda;
            color: #155724;
        }
        
        .activity-details {
            display: grid;
            gap: 10px;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .detail-item:last-child {
            border-bottom: none;
        }
        
        .detail-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: var(--light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            flex-shrink: 0;
        }
        
        .detail-text {
            flex: 1;
        }
        
        .detail-label {
            font-size: 0.85rem;
            color: #718096;
            margin-bottom: 2px;
        }
        
        .detail-value {
            font-weight: 600;
            color: var(--dark);
        }
        
        /* ATTENDANCE FORM - MOBILE OPTIMIZED */
        .attendance-form {
            margin: 16px;
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .form-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .input-with-icon {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--accent);
            font-size: 1.1rem;
        }
        
        .mobile-input {
            width: 100%;
            padding: 16px 16px 16px 50px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            background: var(--light);
            transition: all 0.3s;
            -webkit-appearance: none;
        }
        
        .mobile-input:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        .mobile-btn {
            width: 100%;
            padding: 18px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: var(--accent);
            color: white;
            margin-top: 10px;
        }
        
        .mobile-btn:active {
            transform: scale(0.98);
            background: #2c5282;
        }
        
        .mobile-btn:disabled {
            background: #a0aec0;
            cursor: not-allowed;
        }
        
        /* MESSAGE TOAST - MOBILE */
        .message-toast {
            margin: 16px;
            padding: 16px;
            border-radius: 10px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideDown 0.3s ease-out;
        }
        
        .message-toast.success {
            background: #d4edda;
            border-left: 4px solid var(--success);
            color: #155724;
        }
        
        .message-toast.error {
            background: #f8d7da;
            border-left: 4px solid var(--danger);
            color: #721c24;
        }
        
        .message-toast.warning {
            background: #fff3cd;
            border-left: 4px solid var(--warning);
            color: #856404;
        }
        
        .message-icon {
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        
        .message-content {
            flex: 1;
        }
        
        @keyframes slideDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* ATTENDANCE HISTORY - MOBILE */
        .history-section {
            margin: 16px;
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .refresh-btn {
            background: var(--light);
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            cursor: pointer;
        }
        
        .history-list {
            max-height: 300px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .history-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .history-item:last-child {
            border-bottom: none;
        }
        
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            font-weight: 600;
            flex-shrink: 0;
        }
        
        .history-info {
            flex: 1;
        }
        
        .cadet-name {
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .cadet-number {
            font-size: 0.85rem;
            color: #718096;
        }
        
        .history-time {
            font-size: 0.8rem;
            color: #a0aec0;
            text-align: right;
        }
        
        /* FOOTER */
        .mobile-footer {
            margin-top: 20px;
            padding: 16px;
            text-align: center;
            color: #718096;
            font-size: 0.85rem;
            border-top: 1px solid #e2e8f0;
            background: var(--light);
        }
        
        /* LOADING SPINNER */
        .spinner {
            width: 24px;
            height: 24px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* RESPONSIVE MEDIA QUERIES */
        @media (min-width: 768px) {
            .mobile-container {
                max-width: 500px;
                margin: 0 auto;
                box-shadow: 0 0 30px rgba(0,0,0,0.1);
                min-height: 100vh;
            }
            
            .mobile-header {
                border-radius: 12px 12px 0 0;
            }
        }
        
        /* DARK MODE SUPPORT */
        @media (prefers-color-scheme: dark) {
            body {
                background: #1a202c;
            }
            
            .mobile-container {
                background: #2d3748;
                color: #e2e8f0;
            }
            
            .activity-card, .attendance-form, .history-section {
                background: #4a5568;
                color: #e2e8f0;
            }
            
            .mobile-input {
                background: #4a5568;
                border-color: #718096;
                color: #e2e8f0;
            }
            
            .detail-item {
                border-color: #718096;
            }
            
            .history-item {
                border-color: #718096;
            }
        }
        
        /* TOUCH FRIENDLY */
        @media (hover: none) and (pointer: coarse) {
            .mobile-btn {
                min-height: 56px;
            }
            
            .mobile-input {
                min-height: 56px;
            }
            
            .back-btn, .refresh-btn {
                min-width: 44px;
                min-height: 44px;
            }
        }
    </style>
</head>
<body>
    <!-- MOBILE CONTAINER -->
    <div class="mobile-container">
        <!-- STICKY HEADER -->
        <header class="mobile-header">
            <div class="header-content">
                <button class="back-btn" onclick="goBack()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="header-text">
                    <h1>Ambil Kehadiran</h1>
                    <p>Markas PALAPES - CAAMS</p>
                </div>
            </div>
            
            <div class="user-info">
                <i class="fas fa-user-shield"></i>
                <span><?php echo htmlspecialchars($user['name']); ?> • <?php echo htmlspecialchars($user['military_number']); ?></span>
            </div>
        </header>
        
        <!-- MAIN CONTENT -->
        <main class="mobile-content">
            <?php if ($selectedActivity): ?>
                <!-- ACTIVITY CARD -->
                <div class="activity-card">
                    <div class="activity-header">
                        <h2 class="activity-title"><?php echo htmlspecialchars($selectedActivity['training_type']); ?></h2>
                        <span class="activity-badge">AKTIF</span>
                    </div>
                    
                    <div class="activity-details">
                        <div class="detail-item">
                            <div class="detail-icon">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <div class="detail-text">
                                <div class="detail-label">Tempat</div>
                                <div class="detail-value"><?php echo htmlspecialchars($selectedActivity['location']); ?></div>
                            </div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-icon">
                                <i class="fas fa-calendar"></i>
                            </div>
                            <div class="detail-text">
                                <div class="detail-label">Tarikh</div>
                                <div class="detail-value"><?php echo date('d/m/Y', strtotime($selectedActivity['training_date'])); ?></div>
                            </div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="detail-text">
                                <div class="detail-label">Sesi</div>
                                <div class="detail-value"><?php echo $sessionTimeLabels[$selectedActivity['session_time']] ?? $selectedActivity['session_time']; ?></div>
                            </div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="detail-text">
                                <div class="detail-label">Kehadiran</div>
                                <div class="detail-value"><?php echo $selectedActivity['attendance_count']; ?> kadet</div>
                            </div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-icon">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div class="detail-text">
                                <div class="detail-label">Dicipta oleh</div>
                                <div class="detail-value"><?php echo htmlspecialchars($selectedActivity['creator_name']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- MESSAGE TOAST -->
                <?php if ($message): ?>
                    <div class="message-toast <?php echo $messageType; ?>">
                        <div class="message-icon">
                            <i class="fas <?php 
                                echo $messageType == 'success' ? 'fa-check-circle' : 
                                     ($messageType == 'warning' ? 'fa-exclamation-triangle' : 'fa-times-circle'); 
                            ?>"></i>
                        </div>
                        <div class="message-content">
                            <?php echo $message; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- ATTENDANCE FORM -->
                <div class="attendance-form">
                    <h3 class="form-title">
                        <i class="fas fa-clipboard-check"></i> Isi Kehadiran
                    </h3>
                    
                    <form method="POST" action="" id="attendanceForm">
                        <input type="hidden" name="activity_id" value="<?php echo $activityId; ?>">
                        
                        <div class="form-group">
                            <div class="input-with-icon">
                                <div class="input-icon">
                                    <i class="fas fa-id-card"></i>
                                </div>
                                <input type="text" 
                                       name="military_number" 
                                       class="mobile-input" 
                                       placeholder="Nombor Tentera"
                                       required
                                       autofocus
                                       pattern="[A-Z0-9]+"
                                       title="Masukkan nombor tentera (contoh: NV8709403)"
                                       <?php if ($messageType === 'success') echo 'disabled'; ?>>
                            </div>
                            <small style="display: block; margin-top: 8px; color: #718096; font-size: 0.85rem;">
                                <i class="fas fa-info-circle"></i> Contoh: NV8709403, CD001, dll.
                            </small>
                        </div>
                        
                        <button type="submit" 
                                name="submit_attendance" 
                                class="mobile-btn"
                                <?php if ($messageType === 'success') echo 'disabled'; ?>>
                            <?php if ($messageType === 'success'): ?>
                                <i class="fas fa-check-circle"></i> Sudah Didaftar
                            <?php else: ?>
                                <i class="fas fa-check-circle"></i> Sahkan Kehadiran
                            <?php endif; ?>
                        </button>
                    </form>
                </div>
                
                <!-- ATTENDANCE HISTORY -->
                <div class="history-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="fas fa-history"></i> Sejarah Kehadiran
                        </h3>
                        <button class="refresh-btn" onclick="refreshPage()">
                            <i class="fas fa-redo"></i>
                        </button>
                    </div>
                    
                    <div class="history-list" id="historyList">
                        <?php if ($attendanceHistory->num_rows > 0): ?>
                            <?php while($record = $attendanceHistory->fetch_assoc()): ?>
                                <div class="history-item">
                                    <div class="avatar">
                                        <?php echo strtoupper(substr($record['name'], 0, 1)); ?>
                                    </div>
                                    <div class="history-info">
                                        <div class="cadet-name"><?php echo htmlspecialchars($record['name']); ?></div>
                                        <div class="cadet-number"><?php echo htmlspecialchars($record['military_number']); ?></div>
                                    </div>
                                    <div class="history-time">
                                        <?php 
                                        $time = strtotime($record['recorded_at']);
                                        echo date('H:i', $time);
                                        ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 30px; color: #a0aec0;">
                                <i class="fas fa-users-slash" style="font-size: 2rem; margin-bottom: 10px;"></i>
                                <p>Belum ada kehadiran direkod</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- NO ACTIVITY FOUND -->
                <div style="padding: 60px 20px; text-align: center;">
                    <i class="fas fa-calendar-times" style="font-size: 3rem; color: var(--danger); margin-bottom: 20px;"></i>
                    <h2 style="margin-bottom: 10px; color: var(--danger);">Aktiviti Tidak Dijumpai</h2>
                    <p style="color: #718096; margin-bottom: 20px;">
                        Aktiviti tidak wujud, sudah tamat tempoh, atau tidak aktif.
                    </p>
                    <button class="mobile-btn" onclick="goBack()" style="background: var(--secondary);">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </button>
                </div>
            <?php endif; ?>
        </main>
        
        <!-- FOOTER -->
        <footer class="mobile-footer">
            <p><i class="fas fa-mobile-alt"></i> CAAMS Mobile • <?php echo date('Y'); ?></p>
            <p style="margin-top: 5px; font-size: 0.8rem;">
                Gunakan di lapangan untuk ambil kehadiran kadet
            </p>
        </footer>
    </div>
    
    <!-- MOBILE OPTIMIZED JAVASCRIPT -->
    <script>
        // Go back function
        function goBack() {
            if (document.referrer) {
                window.history.back();
            } else {
                window.location.href = 'dashboard.php';
            }
        }
        
        // Refresh page
        function refreshPage() {
            window.location.reload();
        }
        
        // Auto-focus on input
        document.addEventListener('DOMContentLoaded', function() {
            const inputField = document.querySelector('input[name="military_number"]');
            if (inputField && !inputField.disabled) {
                inputField.focus();
                
                // Show keyboard on mobile
                inputField.addEventListener('touchstart', function() {
                    this.focus();
                });
            }
            
            // Clear form after successful submission
            <?php if ($messageType === 'success'): ?>
                setTimeout(() => {
                    const form = document.getElementById('attendanceForm');
                    if (form) {
                        form.reset();
                        const submitBtn = form.querySelector('button[type="submit"]');
                        const inputField = form.querySelector('input[name="military_number"]');
                        
                        if (submitBtn && inputField) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Sahkan Kehadiran';
                            inputField.disabled = false;
                            inputField.focus();
                        }
                    }
                }, 3000);
            <?php endif; ?>
            
            // Prevent form resubmission
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
            
            // Auto-scroll to form on mobile
            if (window.innerWidth < 768) {
                setTimeout(() => {
                    const formElement = document.querySelector('.attendance-form');
                    if (formElement) {
                        formElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, 500);
            }
            
            // Handle form submission
            const form = document.getElementById('attendanceForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const inputField = this.querySelector('input[name="military_number"]');
                    
                    // Show loading
                    if (submitBtn && inputField.value.trim()) {
                        submitBtn.innerHTML = '<div class="spinner"></div> Memproses...';
                        submitBtn.disabled = true;
                        inputField.disabled = true;
                    }
                });
            }
            
            // Auto-uppercase military number
            const militaryInput = document.querySelector('input[name="military_number"]');
            if (militaryInput) {
                militaryInput.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            }
            
            // Pull-to-refresh for history
            let touchStartY = 0;
            const historyList = document.getElementById('historyList');
            
            if (historyList && window.innerWidth < 768) {
                historyList.addEventListener('touchstart', function(e) {
                    touchStartY = e.touches[0].clientY;
                });
                
                historyList.addEventListener('touchmove', function(e) {
                    const touchY = e.touches[0].clientY;
                    const scrollTop = this.scrollTop;
                    
                    // If at top and pulling down
                    if (scrollTop === 0 && touchY > touchStartY + 50) {
                        refreshPage();
                    }
                });
            }
            
            // Vibration on successful submission (if supported)
            <?php if ($messageType === 'success'): ?>
                if ('vibrate' in navigator) {
                    navigator.vibrate(200);
                }
            <?php endif; ?>
            
            // Keep screen awake during attendance
            if ('wakeLock' in navigator) {
                let wakeLock = null;
                
                const requestWakeLock = async () => {
                    try {
                        wakeLock = await navigator.wakeLock.request('screen');
                    } catch (err) {
                        console.log('Wake Lock not supported');
                    }
                };
                
                requestWakeLock();
                
                // Re-request when page becomes visible again
                document.addEventListener('visibilitychange', async () => {
                    if (wakeLock !== null && document.visibilityState === 'visible') {
                        await requestWakeLock();
                    }
                });
            }
        });
        
        // Handle offline/online status
        window.addEventListener('online', function() {
            showToast('Anda kembali online', 'success');
        });
        
        window.addEventListener('offline', function() {
            showToast('Anda offline. Kehadiran akan disimpan apabila online.', 'warning');
        });
        
        // Toast notification
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 80px;
                left: 16px;
                right: 16px;
                padding: 15px;
                background: ${type === 'success' ? '#48bb78' : '#f56565'};
                color: white;
                border-radius: 10px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
                z-index: 1000;
                text-align: center;
                animation: slideDown 0.3s ease-out;
            `;
            
            toast.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i>
                ${message}
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideUp 0.3s ease-out';
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 3000);
        }
        
        // Add CSS animation for slideUp
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideUp {
                from { transform: translateY(0); opacity: 1; }
                to { transform: translateY(-20px); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
        
        // Handle back button on Android
        document.addEventListener('backbutton', function(e) {
            e.preventDefault();
            goBack();
        }, false);
    </script>
</body>
</html>
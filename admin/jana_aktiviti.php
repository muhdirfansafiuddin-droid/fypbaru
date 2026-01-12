<?php
// admin/create_activity.php - FIXED VERSION (NO QR)
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
$createdActivity = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_activity'])) {
    $formData = [
        'location' => $_POST['location'] ?? '',
        'training_date' => $_POST['training_date'] ?? '',
        'training_type' => $_POST['training_type'] ?? '',
        'session_time' => $_POST['session_time'] ?? '',
        'notes' => $_POST['notes'] ?? '',
        'max_attendance' => (int)($_POST['max_attendance'] ?? 100)
    ];
    
    // Validate required fields
    if (empty($formData['training_date']) || empty($formData['training_type']) || empty($formData['session_time'])) {
        $message = 'Date, training type, and session time are required';
        $messageType = 'error';
    } else {
        // Insert into training_sessions (WITHOUT QR_TOKEN)
        $sql = "INSERT INTO training_sessions 
                (location, training_date, training_type, session_time, max_attendance, notes, created_by, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $db->prepare($sql);
        $createdBy = $user['user_id'];
        $isActive = 1;
        
        $stmt->bind_param(
            "ssssissi",
            $formData['location'],
            $formData['training_date'],
            $formData['training_type'],
            $formData['session_time'],
            $formData['max_attendance'],
            $formData['notes'],
            $createdBy,
            $isActive
        );
        
        if ($stmt->execute()) {
            $sessionId = $stmt->insert_id;
            
            // Create activity URL for rankholder (WITHOUT TOKEN)
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
            $attendanceUrl = $baseUrl . "/rankholder/take_attendance.php?session=" . $sessionId;
            
            // Log activity
            $activityDesc = "Created activity: {$formData['training_type']} at {$formData['location']}";
            $logSql = "INSERT INTO activity_logs (user_id, activity_type, description, related_id) 
                      VALUES (?, 'session_created', ?, ?)";
            $logStmt = $db->prepare($logSql);
            $logStmt->bind_param("isi", $user['user_id'], $activityDesc, $sessionId);
            $logStmt->execute();
            
            $message = 'Activity successfully created!';
            $messageType = 'success';
            
            $createdActivity = [
                'session_id' => $sessionId,
                'training_type' => $formData['training_type'],
                'location' => $formData['location'],
                'training_date' => $formData['training_date'],
                'session_time' => $formData['session_time'],
                'attendance_url' => $attendanceUrl,
                'max_attendance' => $formData['max_attendance'],
                'notes' => $formData['notes'],
                'created_at' => date('Y-m-d H:i:s')
            ];
        } else {
            $message = 'Database error: ' . $stmt->error;
            $messageType = 'error';
        }
    }
}

// Get recent activities
$sql = "SELECT ts.*, u.name as creator_name,
        (SELECT COUNT(*) FROM attendance a WHERE a.session_id = ts.session_id) as attendance_count
        FROM training_sessions ts
        JOIN users u ON ts.created_by = u.user_id
        ORDER BY ts.created_at DESC
        LIMIT 10";
$stmt = $db->prepare($sql);
$stmt->execute();
$recentActivities = $stmt->get_result();

// Training type options
$trainingTypes = [
    'Latihan Tempatan',
    'Latihan Berterusan', 
    'Latihan Kem Tahunan',
    'Istiadat Pentauliahan',
    'Perbarisan Hari Kemerdekaan',
    'Baris Rabu',
    'FETIK'
];

// Service types
$serviceTypes = [
    'darat' => 'Army',
    'laut' => 'Navy',
    'udara' => 'Air Force'
];

// Session time labels
$sessionTimeLabels = [
    'pagi' => 'Morning (06:00 - 10:00)',
    'tengah hari' => 'Midday (10:00 - 14:00)',
    'petang' => 'Evening (14:00 - 18:00)',
    'malam' => 'Night (18:00 - 22:00)'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Activity - CAAMS</title>
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
            background: #82CAFF;
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
        
        /* FORM */
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
        
        /* ACTIVITY PREVIEW */
        .activity-preview {
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .activity-info {
            margin-top: 20px;
            padding: 20px;
            background: #e8f4fe;
            border-radius: 8px;
            text-align: left;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #cbd5e0;
        }
        
        .info-item:last-child {
            margin-bottom: 0;
            border-bottom: none;
        }
        
        /* LINK BOX */
        .link-box {
            background: #e6fffa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            word-break: break-all;
        }
        
        .link-box a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }
        
        .link-box a:hover {
            text-decoration: underline;
        }
        
        .copy-btn {
            background: var(--secondary);
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.8rem;
            margin-left: 10px;
        }
        
        /* ACTIVITIES LIST */
        .activities-section {
            grid-column: 1 / -1;
            padding: 30px;
            background: white;
            border-radius: 15px;
            margin-top: 20px;
        }
        
        .activities-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .activities-table th {
            background: var(--primary);
            color: white;
            padding: 12px;
            text-align: left;
        }
        
        .activities-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-expired { background: #f8d7da; color: #721c24; }
        
        .action-btns {
            display: flex;
            gap: 5px;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 0.85rem;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .activities-table {
                display: block;
                overflow-x: auto;
            }
            
            .action-btns {
                flex-direction: column;
            }
        }
        
        /* FORM GRID */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
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
                <i class="fas fa-calendar-plus"></i> Create Activity
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">Create training activities for cadets</p>
        </div>
        
        <!-- INFORMATION ALERT -->
        <div class="alert info">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>How to use:</strong> 
                1. Fill activity information → 
                2. Activity will be available for rankholder to take attendance
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
            <!-- LEFT: FORM -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="fas fa-plus-circle"></i> Create New Activity
                </h2>
                
                <form method="POST" action="" id="activityForm">
                    <input type="hidden" name="create_activity" value="1">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="training_type">Training Type *</label>
                            <select id="training_type" name="training_type" required>
                                <option value="">Select Training Type</option>
                                <?php foreach ($trainingTypes as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>">
                                        <?php echo htmlspecialchars($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="session_time">Training Session *</label>
                            <select id="session_time" name="session_time" required>
                                <option value="">Select Session</option>
                                <option value="pagi">Morning (06:00 - 10:00)</option>
                                <option value="tengah hari">Midday (10:00 - 14:00)</option>
                                <option value="petang">Evening (14:00 - 18:00)</option>
                                <option value="malam">Night (18:00 - 22:00)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="location">Training Location *</label>
                        <input type="text" 
                               id="location" 
                               name="location" 
                               placeholder="Example: Parade Square, Main Hall, Class 1, etc"
                               required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="training_date">Training Date *</label>
                            <input type="date" 
                                   id="training_date" 
                                   name="training_date" 
                                   value="<?php echo date('Y-m-d'); ?>"
                                   required
                                   min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="max_attendance">Attendance Limit (Optional)</label>
                            <input type="number" 
                                   id="max_attendance" 
                                   name="max_attendance" 
                                   placeholder="Example: 50 (leave empty for no limit)"
                                   min="1" 
                                   max="500">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Additional Notes</label>
                        <textarea id="notes" 
                                  name="notes" 
                                  rows="3" 
                                  placeholder="Special instructions, required equipment, uniform, etc..."></textarea>
                        <small style="color: #718096; font-size: 0.85rem; display: block; margin-top: 5px;">
                            <i class="fas fa-info-circle"></i> These notes will be displayed to rankholder
                        </small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-calendar-plus"></i> Create Activity
                    </button>
                </form>
            </div>
            
            <!-- RIGHT: ACTIVITY PREVIEW -->
            <div class="preview-section">
                <h2 class="section-title">
                    <i class="fas fa-eye"></i> Activity Preview
                </h2>
                
                <div class="activity-preview">
                    <?php if ($createdActivity): ?>
                        <!-- SUCCESS MESSAGE -->
                        <div style="text-align: center; margin-bottom: 20px;">
                            <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--success); margin-bottom: 10px;"></i>
                            <h3 style="color: var(--success);">Activity Successfully Created!</h3>
                            <p>Rankholder can access through:</p>
                        </div>
                        
                        <!-- ACTIVITY INFORMATION -->
                        <div class="activity-info">
                            <div class="info-item">
                                <span>Activity ID:</span>
                                <strong>#<?php echo htmlspecialchars($createdActivity['session_id']); ?></strong>
                            </div>
                            <div class="info-item">
                                <span>Training Type:</span>
                                <strong><?php echo htmlspecialchars($createdActivity['training_type']); ?></strong>
                            </div>
                            <div class="info-item">
                                <span>Location:</span>
                                <strong><?php echo htmlspecialchars($createdActivity['location']); ?></strong>
                            </div>
                            <div class="info-item">
                                <span>Date:</span>
                                <strong><?php echo date('d/m/Y', strtotime($createdActivity['training_date'])); ?></strong>
                            </div>
                            <div class="info-item">
                                <span>Session:</span>
                                <strong><?php echo $sessionTimeLabels[$createdActivity['session_time']] ?? $createdActivity['session_time']; ?></strong>
                            </div>
                            <div class="info-item">
                                <span>Attendance Limit:</span>
                                <strong><?php echo $createdActivity['max_attendance'] ?: 'No Limit'; ?></strong>
                            </div>
                            <?php if ($createdActivity['notes']): ?>
                            <div class="info-item">
                                <span>Notes:</span>
                                <strong><?php echo htmlspecialchars($createdActivity['notes']); ?></strong>
                            </div>
                            <?php endif; ?>
                            <div class="info-item">
                                <span>Created at:</span>
                                <strong><?php echo date('d/m/Y H:i:s', strtotime($createdActivity['created_at'])); ?></strong>
                            </div>
                        </div>
                        
                        <!-- DIRECT ACCESS -->
                        <div class="link-box">
                            <strong><i class="fas fa-link"></i> Direct Access:</strong>
                            <div style="margin-top: 8px;">
                                <a href="<?php echo htmlspecialchars($createdActivity['attendance_url']); ?>" 
                                   target="_blank" 
                                   id="attendanceLink">
                                    <?php echo htmlspecialchars($createdActivity['attendance_url']); ?>
                                </a>
                                <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($createdActivity['attendance_url']); ?>')">
                                    <i class="fas fa-copy"></i> Copy
                                </button>
                            </div>
                            <small style="display: block; margin-top: 8px; color: #718096;">
                                <i class="fas fa-share-alt"></i> Rankholder can directly access this link
                            </small>
                        </div>
                        
                        <!-- ACTION BUTTONS -->
                        <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
                            <button onclick="copyToClipboard('<?php echo htmlspecialchars($createdActivity['attendance_url']); ?>')" 
                                    class="btn" style="background: var(--accent); color: white;">
                                <i class="fas fa-copy"></i> Copy Link
                            </button>
                            <button onclick="window.open('<?php echo htmlspecialchars($createdActivity['attendance_url']); ?>', '_blank')" 
                                    class="btn" style="background: var(--success); color: white;">
                                <i class="fas fa-external-link-alt"></i> Open
                            </button>
                            <button onclick="window.location.href='dashboard.php'" 
                                    class="btn" style="background: var(--secondary); color: white;">
                                <i class="fas fa-home"></i> Dashboard
                            </button>
                        </div>
                    <?php else: ?>
                        <div style="padding: 40px 20px; text-align: center; color: var(--secondary);">
                            <i class="fas fa-calendar-alt" style="font-size: 3rem; opacity: 0.3; margin-bottom: 15px;"></i>
                            <h3 style="margin-bottom: 10px;">No Activity Created</h3>
                            <p>Fill the form on the left to create a new activity</p>
                            <small style="display: block; margin-top: 15px; color: #a0aec0;">
                                <i class="fas fa-info-circle"></i> Activity will be available for rankholder to take attendance
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- RECENT ACTIVITIES -->
        <div class="activities-section">
            <h2 class="section-title">
                <i class="fas fa-history"></i> Recent Activities
            </h2>
            
            <table class="activities-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Training Type</th>
                        <th>Location</th>
                        <th>Date</th>
                        <th>Session</th>
                        <th>Attendance</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($recentActivities && $recentActivities->num_rows > 0): ?>
                        <?php while($activity = $recentActivities->fetch_assoc()): 
                            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
                            $attendanceUrl = $baseUrl . "/rankholder/take_attendance.php?session=" . $activity['session_id'];
                            $isActive = $activity['is_active'] == 1;
                        ?>
                            <tr>
                                <td>#<?php echo $activity['session_id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($activity['training_type']); ?></strong></td>
                                <td><?php echo htmlspecialchars($activity['location']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($activity['training_date'])); ?></td>
                                <td><?php echo $sessionTimeLabels[$activity['session_time']] ?? $activity['session_time']; ?></td>
                                <td>
                                    <strong><?php echo $activity['attendance_count']; ?></strong> cadets
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $isActive ? 'status-active' : 'status-expired'; ?>">
                                        <?php echo $isActive ? 'ACTIVE' : 'ENDED'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <button onclick="copyToClipboard('<?php echo $attendanceUrl; ?>')" 
                                                class="btn btn-small" style="background: var(--accent); color: white;">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                        <button onclick="window.open('<?php echo $attendanceUrl; ?>', '_blank')" 
                                                class="btn btn-small" style="background: var(--success); color: white;">
                                            <i class="fas fa-external-link-alt"></i>
                                        </button>
                                        <button onclick="viewActivity(<?php echo $activity['session_id']; ?>)" 
                                                class="btn btn-small" style="background: var(--secondary); color: white;">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 30px; color: var(--secondary);">
                                <i class="fas fa-calendar-times" style="font-size: 2rem; opacity: 0.3; margin-bottom: 10px;"></i>
                                <p>No activities created yet</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
        // Copy to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                showToast('Link copied!', 'success');
            }).catch(err => {
                // Fallback method
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                showToast('Link copied!', 'success');
            });
        }
        
        // View activity details
        function viewActivity(activityId) {
            window.location.href = `view_activity.php?id=${activityId}`;
        }
        
        // Toast notification
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
        
        // Auto-select today's date
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('training_date').valueAsDate = new Date();
        });
        
        // Form validation
        document.getElementById('activityForm').addEventListener('submit', function(e) {
            const trainingType = document.getElementById('training_type').value;
            const location = document.getElementById('location').value;
            const trainingDate = document.getElementById('training_date').value;
            const sessionTime = document.getElementById('session_time').value;
            
            if (!trainingType) {
                e.preventDefault();
                showToast('Please select training type', 'error');
                return false;
            }
            
            if (!location.trim()) {
                e.preventDefault();
                showToast('Please enter training location', 'error');
                return false;
            }
            
            if (!trainingDate) {
                e.preventDefault();
                showToast('Please select training date', 'error');
                return false;
            }
            
            if (!sessionTime) {
                e.preventDefault();
                showToast('Please select training session', 'error');
                return false;
            }
            
            // Show loading
            const submitBtn = this.querySelector('.btn-primary');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
            submitBtn.disabled = true;
            
            return true;
        });
    </script>
</body>
</html>
<?php
// admin/manage_allowance.php - ALLOWANCE MANAGEMENT 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('admin');
$auth = new Auth();
$user = $auth->getCurrentUser();
$db = new Database();

$message = '';
$messageType = '';

// Handle export CSV
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="allowance_export_' . date('Y-m-d_H-i-s') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV header
    fputcsv($output, [
        'ID', 'Military Number', 'Name', 'Service', 'Rank', 
        'Month', 'Training Days', 'Allowance Tempatan', 'Allowance Berterusan',
        'Allowance Kem', 'Allowance Pentauliahan', 'Allowance Bounty', 
        'Allowance Pakaian', 'Total Training', 'Total Additional', 'Total Amount',
        'Payment Status', 'Payment Date', 'Calculated By', 'Calculated Date'
    ]);
    
    // Build export query
    $exportSql = "SELECT 
                    ac.*,
                    u.military_number,
                    u.name,
                    u.service_type,
                    u.rank_level,
                    admin.name as admin_name
                FROM allowance_calculations ac
                JOIN users u ON ac.user_id = u.user_id
                LEFT JOIN users admin ON ac.calculated_by = admin.user_id
                WHERE u.role = 'cadet'";
    
    $exportParams = [];
    $exportTypes = "";
    
    // Apply filters for export
    if (!empty($_GET['service_type'])) {
        $exportSql .= " AND u.service_type = ?";
        $exportParams[] = $_GET['service_type'];
        $exportTypes .= "s";
    }
    
    if (!empty($_GET['rank'])) {
        $exportSql .= " AND u.rank_level = ?";
        $exportParams[] = $_GET['rank'];
        $exportTypes .= "s";
    }
    
    if (!empty($_GET['month'])) {
        $exportSql .= " AND ac.month_year = ?";
        $exportParams[] = $_GET['month'];
        $exportTypes .= "s";
    }
    
    if (isset($_GET['payment_status']) && $_GET['payment_status'] !== 'all') {
        $exportSql .= " AND ac.is_paid = ?";
        $exportParams[] = ($_GET['payment_status'] == 'paid') ? 1 : 0;
        $exportTypes .= "i";
    }
    
    if (!empty($_GET['search'])) {
        $exportSql .= " AND (u.name LIKE ? OR u.military_number LIKE ?)";
        $searchTerm = "%{$_GET['search']}%";
        $exportParams[] = $searchTerm;
        $exportParams[] = $searchTerm;
        $exportTypes .= "ss";
    }
    
    $exportSql .= " ORDER BY u.name ASC";
    
    $exportStmt = $db->prepare($exportSql);
    if ($exportParams) {
        $exportStmt->bind_param($exportTypes, ...$exportParams);
    }
    $exportStmt->execute();
    $exportResult = $exportStmt->get_result();
    
    while ($row = $exportResult->fetch_assoc()) {
        fputcsv($output, [
            $row['calc_id'],
            $row['military_number'],
            $row['name'],
            ucfirst($row['service_type']),
            ucfirst($row['rank_level']),
            date('F Y', strtotime($row['month_year'] . '-01')),
            $row['training_days'],
            number_format($row['allowance_tempatan'], 2),
            number_format($row['allowance_berterusan'], 2),
            number_format($row['allowance_kem'], 2),
            number_format($row['allowance_pentauliahan'], 2),
            number_format($row['allowance_bounty'], 2),
            number_format($row['allowance_pakaian'], 2),
            number_format($row['total_training'], 2),
            number_format($row['total_additional'], 2),
            number_format($row['total_amount'], 2),
            $row['is_paid'] ? 'Paid' : 'Unpaid',
            $row['payment_date'] ? date('d/m/Y', strtotime($row['payment_date'])) : '',
            $row['admin_name'] ?? 'System',
            date('d/m/Y H:i', strtotime($row['calculated_at']))
        ]);
    }
    
    fclose($output);
    exit();
}

// Handle export PDF using dompdf - INTEGRATED
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    // Include dompdf library
    require_once __DIR__ . '/../vendor/dompdf/dompdf/autoload.inc.php';
    
    // Create new PDF document
    $dompdf = new Dompdf\Dompdf();
    $dompdf->setPaper('A4', 'landscape');
    
    // Build query for PDF data
    $pdfSql = "SELECT 
                    ac.*,
                    u.military_number,
                    u.name,
                    u.service_type,
                    u.rank_level,
                    admin.name as admin_name
                FROM allowance_calculations ac
                JOIN users u ON ac.user_id = u.user_id
                LEFT JOIN users admin ON ac.calculated_by = admin.user_id
                WHERE u.role = 'cadet'";
    
    $pdfParams = [];
    $pdfTypes = "";
    
    // Apply filters for PDF
    if (!empty($_GET['service_type'])) {
        $pdfSql .= " AND u.service_type = ?";
        $pdfParams[] = $_GET['service_type'];
        $pdfTypes .= "s";
    }
    
    if (!empty($_GET['rank'])) {
        $pdfSql .= " AND u.rank_level = ?";
        $pdfParams[] = $_GET['rank'];
        $pdfTypes .= "s";
    }
    
    if (!empty($_GET['month'])) {
        $pdfSql .= " AND ac.month_year = ?";
        $pdfParams[] = $_GET['month'];
        $pdfTypes .= "s";
    }
    
    if (isset($_GET['payment_status']) && $_GET['payment_status'] !== 'all') {
        $pdfSql .= " AND ac.is_paid = ?";
        $pdfParams[] = ($_GET['payment_status'] == 'paid') ? 1 : 0;
        $pdfTypes .= "i";
    }
    
    if (!empty($_GET['search'])) {
        $pdfSql .= " AND (u.name LIKE ? OR u.military_number LIKE ?)";
        $searchTerm = "%{$_GET['search']}%";
        $pdfParams[] = $searchTerm;
        $pdfParams[] = $searchTerm;
        $pdfTypes .= "ss";
    }
    
    $pdfSql .= " ORDER BY u.name ASC";
    
    $pdfStmt = $db->prepare($pdfSql);
    if ($pdfParams) {
        $pdfStmt->bind_param($pdfTypes, ...$pdfParams);
    }
    $pdfStmt->execute();
    $pdfResult = $pdfStmt->get_result();
    
    // Get statistics for PDF
    $statsSql = "SELECT 
                    COUNT(*) as total_records,
                    SUM(ac.total_amount) as total_amount,
                    SUM(CASE WHEN ac.is_paid = 1 THEN ac.total_amount ELSE 0 END) as paid_amount,
                    SUM(CASE WHEN ac.is_paid = 0 THEN ac.total_amount ELSE 0 END) as unpaid_amount
                FROM allowance_calculations ac
                JOIN users u ON ac.user_id = u.user_id
                WHERE u.role = 'cadet'";
    
    $statsParams = [];
    $statsTypes = "";
    
    if (!empty($_GET['service_type'])) {
        $statsSql .= " AND u.service_type = ?";
        $statsParams[] = $_GET['service_type'];
        $statsTypes .= "s";
    }
    
    if (!empty($_GET['rank'])) {
        $statsSql .= " AND u.rank_level = ?";
        $statsParams[] = $_GET['rank'];
        $statsTypes .= "s";
    }
    
    if (!empty($_GET['month'])) {
        $statsSql .= " AND ac.month_year = ?";
        $statsParams[] = $_GET['month'];
        $statsTypes .= "s";
    }
    
    $statsStmt = $db->prepare($statsSql);
    if ($statsParams) {
        $statsStmt->bind_param($statsTypes, ...$statsParams);
    }
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();
    
    // Generate HTML for PDF
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Allowance Report - ' . date('d/m/Y H:i') . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 3px solid #1a365d; padding-bottom: 15px; }
            .header h1 { color: #1a365d; margin: 0; }
            .header p { color: #666; margin: 5px 0; }
            .stats { margin: 20px 0; padding: 15px; background: #f7fafc; border-radius: 8px; }
            .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
            .stat-box { text-align: center; padding: 10px; }
            .stat-value { font-size: 24px; font-weight: bold; color: #1a365d; }
            .stat-label { font-size: 12px; color: #666; text-transform: uppercase; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            th { background-color: #1a365d; color: white; padding: 10px; text-align: left; font-size: 12px; }
            td { padding: 8px; border-bottom: 1px solid #ddd; font-size: 11px; }
            tr:nth-child(even) { background-color: #f9f9f9; }
            .paid { color: #38a169; font-weight: bold; }
            .unpaid { color: #e53e3e; font-weight: bold; }
            .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; text-align: center; color: #666; font-size: 11px; }
            .badge { padding: 3px 8px; border-radius: 10px; font-size: 10px; font-weight: bold; }
            .badge-army { background: #c6f6d5; color: #276749; }
            .badge-navy { background: #bee3f8; color: #2c5282; }
            .badge-airforce { background: #e9d8fd; color: #553c9a; }
            .badge-junior { background: #fed7d7; color: #c53030; }
            .badge-intermediate { background: #fed7d7; color: #c53030; }
            .badge-senior { background: #fed7d7; color: #c53030; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>CAAMS - Allowance Report</h1>
            <p>Generated on ' . date('d F Y, h:i A') . '</p>';
            
    if (!empty($_GET['month'])) {
        $html .= '<p>Month: ' . date('F Y', strtotime($_GET['month'] . '-01')) . '</p>';
    }
    if (!empty($_GET['service_type'])) {
        $html .= '<p>Service: ' . ucfirst($_GET['service_type']) . '</p>';
    }
    if (!empty($_GET['rank'])) {
        $html .= '<p>Rank: ' . ucfirst($_GET['rank']) . '</p>';
    }
    if (isset($_GET['payment_status']) && $_GET['payment_status'] !== 'all') {
        $html .= '<p>Status: ' . ucfirst($_GET['payment_status']) . '</p>';
    }
    
    $html .= '</div>
        
        <div class="stats">
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-value">' . number_format($stats['total_records'] ?? 0) . '</div>
                    <div class="stat-label">Total Records</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value">RM ' . number_format($stats['total_amount'] ?? 0, 2) . '</div>
                    <div class="stat-label">Total Amount</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value">RM ' . number_format($stats['paid_amount'] ?? 0, 2) . '</div>
                    <div class="stat-label">Paid Amount</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value">RM ' . number_format($stats['unpaid_amount'] ?? 0, 2) . '</div>
                    <div class="stat-label">Unpaid Amount</div>
                </div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Military No</th>
                    <th>Name</th>
                    <th>Service</th>
                    <th>Rank</th>
                    <th>Month</th>
                    <th>Training Days</th>
                    <th>Allowance Tempatan</th>
                    <th>Allowance Berterusan</th>
                    <th>Allowance Kem</th>
                    <th>Allowance Pentauliahan</th>
                    <th>Allowance Bounty</th>
                    <th>Allowance Pakaian</th>
                    <th>Total Amount</th>
                    <th>Status</th>
                    <th>Calculated By</th>
                </tr>
            </thead>
            <tbody>';
    
    $counter = 1;
    $totalAmount = 0;
    while ($row = $pdfResult->fetch_assoc()) {
        $serviceClass = 'badge-' . $row['service_type'];
        $rankClass = 'badge-' . $row['rank_level'];
        
        $html .= '<tr>
                    <td>' . $counter . '</td>
                    <td>' . htmlspecialchars($row['military_number']) . '</td>
                    <td>' . htmlspecialchars($row['name']) . '</td>
                    <td><span class="badge ' . $serviceClass . '">' . ucfirst($row['service_type']) . '</span></td>
                    <td><span class="badge ' . $rankClass . '">' . ucfirst($row['rank_level']) . '</span></td>
                    <td>' . date('F Y', strtotime($row['month_year'] . '-01')) . '</td>
                    <td>' . $row['training_days'] . '</td>
                    <td>RM ' . number_format($row['allowance_tempatan'], 2) . '</td>
                    <td>RM ' . number_format($row['allowance_berterusan'], 2) . '</td>
                    <td>RM ' . number_format($row['allowance_kem'], 2) . '</td>
                    <td>RM ' . number_format($row['allowance_pentauliahan'], 2) . '</td>
                    <td>RM ' . number_format($row['allowance_bounty'], 2) . '</td>
                    <td>RM ' . number_format($row['allowance_pakaian'], 2) . '</td>
                    <td><strong>RM ' . number_format($row['total_amount'], 2) . '</strong></td>
                    <td class="' . ($row['is_paid'] ? 'paid' : 'unpaid') . '">' . 
                    ($row['is_paid'] ? 'PAID' : 'UNPAID') . '</td>
                    <td>' . htmlspecialchars($row['admin_name'] ?? 'System') . '</td>
                </tr>';
        $counter++;
        $totalAmount += $row['total_amount'];
    }
    
    $html .= '</tbody>
        </table>
        
        <div class="footer">
            <p>Total Records: ' . ($counter - 1) . ' | Total Amount: RM ' . number_format($totalAmount, 2) . '</p>
            <p>Report generated by CAAMS &copy; ' . date('Y') . '</p>
        </div>
    </body>
    </html>';
    
    // Load HTML content
    $dompdf->loadHtml($html);
    
    // Render PDF
    $dompdf->render();
    
    // Output PDF
    $dompdf->stream('allowance_report_' . date('Y-m-d_H-i-s') . '.pdf', [
        'Attachment' => true
    ]);
    
    exit();
}

// Define default allowance rates
$defaultRates = [
    'tempatan_per_hour' => 8.00,
    'tempatan_hours_per_day' => 12,
    'junior_per_day' => 53.83,
    'intermediate_per_day' => 58.00,
    'senior_per_day' => 62.17,
    'pentauliahan_senior' => 870.83,
    'pentauliahan_days' => 14,
    'pakaian_senior' => 1500.00,
    'bounty_all' => 520.00
];

// Check if session already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load saved rates from session or use default
if (!isset($_SESSION['allowance_rates'])) {
    $_SESSION['allowance_rates'] = $defaultRates;
}
$allowanceRates = $_SESSION['allowance_rates'];

// Handle allowance rate updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_rates'])) {
    // Update rates from form
    $allowanceRates['tempatan_per_hour'] = floatval($_POST['tempatan_per_hour']);
    $allowanceRates['tempatan_hours_per_day'] = intval($_POST['tempatan_hours_per_day']);
    $allowanceRates['junior_per_day'] = floatval($_POST['junior_per_day']);
    $allowanceRates['intermediate_per_day'] = floatval($_POST['intermediate_per_day']);
    $allowanceRates['senior_per_day'] = floatval($_POST['senior_per_day']);
    $allowanceRates['pentauliahan_senior'] = floatval($_POST['pentauliahan_senior']);
    $allowanceRates['pentauliahan_days'] = intval($_POST['pentauliahan_days']);
    $allowanceRates['pakaian_senior'] = floatval($_POST['pakaian_senior']);
    $allowanceRates['bounty_all'] = floatval($_POST['bounty_all']);
    
    // Save to session
    $_SESSION['allowance_rates'] = $allowanceRates;
    
    $message = 'Allowance rates successfully updated!';
    $messageType = 'success';
    
    $logQuery = "INSERT INTO activity_logs (user_id, activity_type, description) 
                VALUES (?, 'allowance_rate_updated', 'Admin updated all allowance rates')";
    $logStmt = $db->prepare($logQuery);
    $logStmt->bind_param("i", $user['user_id']);
    $logStmt->execute();
}

// Handle payment status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    $calc_id = intval($_POST['calc_id']);
    $payment_status = $_POST['payment_status'];
    $payment_date = !empty($_POST['payment_date']) ? $_POST['payment_date'] : null;
    
    $updateSql = "UPDATE allowance_calculations SET 
                    is_paid = ?, 
                    payment_date = ?
                  WHERE calc_id = ?";
    
    $updateStmt = $db->prepare($updateSql);
    $paid_status = ($payment_status == 'paid') ? 1 : 0;
    
    if ($payment_date) {
        $updateStmt->bind_param("isi", $paid_status, $payment_date, $calc_id);
    } else {
        $updateStmt->bind_param("isi", $paid_status, $payment_date, $calc_id);
    }
    
    if ($updateStmt->execute()) {
        $message = 'Payment status successfully updated!';
        $messageType = 'success';
    } else {
        $message = 'Database error: ' . $updateStmt->error;
        $messageType = 'error';
    }
}

// Handle recalculate for specific cadet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalculate'])) {
    $user_id = intval($_POST['user_id']);
    $month_year = $_POST['month_year'];
    
    // Calculate allowance for specific cadet
    calculateCadetAllowance($user_id, $month_year, $db, $user['user_id'], $allowanceRates);
    $message = 'Allowance recalculated for selected cadet!';
    $messageType = 'success';
}

// Handle bulk calculate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_calculate'])) {
    $month_year = $_POST['month_year'];
    $service_type = $_POST['service_type'] ?? '';
    $rank_level = $_POST['rank_level'] ?? '';
    
    // Get all cadets based on filters
    $cadetSql = "SELECT user_id FROM users WHERE role = 'cadet'";
    $params = [];
    $types = "";
    
    if (!empty($service_type)) {
        $cadetSql .= " AND service_type = ?";
        $params[] = $service_type;
        $types .= "s";
    }
    
    if (!empty($rank_level)) {
        $cadetSql .= " AND rank_level = ?";
        $params[] = $rank_level;
        $types .= "s";
    }
    
    $cadetStmt = $db->prepare($cadetSql);
    if ($params) {
        $cadetStmt->bind_param($types, ...$params);
    }
    $cadetStmt->execute();
    $cadetResult = $cadetStmt->get_result();
    
    $count = 0;
    while ($cadet = $cadetResult->fetch_assoc()) {
        calculateCadetAllowance($cadet['user_id'], $month_year, $db, $user['user_id'], $allowanceRates);
        $count++;
    }
    
    $message = "Allowance calculated for {$count} cadets!";
    $messageType = 'success';
}

// Function to calculate allowance for a cadet - FIXED VERSION
function calculateCadetAllowance($user_id, $month_year, $db, $admin_id, $rates) {
    // Get cadet details
    $cadetSql = "SELECT * FROM users WHERE user_id = ?";
    $cadetStmt = $db->prepare($cadetSql);
    $cadetStmt->bind_param("i", $user_id);
    $cadetStmt->execute();
    $cadet = $cadetStmt->get_result()->fetch_assoc();
    
    if (!$cadet) return false;
    
    // Get attendance for the month - FIXED QUERY
    $attendanceSql = "SELECT 
                        a.*,
                        ts.training_type,
                        ts.training_category,
                        ts.session_time,
                        ts.training_date
                    FROM attendance a
                    JOIN training_sessions ts ON a.session_id = ts.session_id
                    WHERE a.user_id = ?
                    AND DATE_FORMAT(a.date, '%Y-%m') = ?
                    AND a.status IN ('present', 'excused')";
    
    $attendanceStmt = $db->prepare($attendanceSql);
    $attendanceStmt->bind_param("is", $user_id, $month_year);
    $attendanceStmt->execute();
    $attendance = $attendanceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Initialize totals
    $allowance_tempatan = 0;
    $allowance_berterusan = 0;
    $allowance_kem = 0;
    $allowance_pentauliahan = 0;
    $allowance_bounty = 0;
    $allowance_pakaian = 0;
    $training_days = count($attendance);
    
    // Calculate based on training types - FIXED LOGIC
    $tempatan_days = 0;
    $berterusan_days = 0;
    $kem_days = 0;
    $pentauliahan_days = 0;
    
    foreach ($attendance as $record) {
        $training_type = strtolower($record['training_type'] ?? '');
        
        // Check if this is latihan tempatan
        if (strpos($training_type, 'tempatan') !== false) {
            $tempatan_days++;
        }
        // Check if latihan berterusan
        elseif (strpos($training_type, 'berterusan') !== false) {
            $berterusan_days++;
        }
        // Check if latihan kem tahunan
        elseif (strpos($training_type, 'kem') !== false || 
                strpos($training_type, 'tahunan') !== false) {
            $kem_days++;
        }
        // Check if latihan pentauliahan
        elseif (strpos($training_type, 'pentauliahan') !== false) {
            $pentauliahan_days++;
        }
    }
    
    // Calculate allowances based on days
    if ($tempatan_days > 0) {
        $allowance_tempatan = $rates['tempatan_per_hour'] * $rates['tempatan_hours_per_day'] * $tempatan_days;
    }
    
    if ($berterusan_days > 0) {
        $rate_key = $cadet['rank_level'] . '_per_day';
        $allowance_berterusan = $rates[$rate_key] * $berterusan_days;
    }
    
    if ($kem_days > 0) {
        $rate_key = $cadet['rank_level'] . '_per_day';
        $allowance_kem = $rates[$rate_key] * $kem_days;
    }
    
    // Pentauliahan is only for senior cadets and is a fixed amount
    if ($pentauliahan_days > 0 && $cadet['rank_level'] == 'senior') {
        $allowance_pentauliahan = $rates['pentauliahan_senior'];
    }
    
    // Add bounty for all (once per year)
    $current_year = date('Y');
    $bountyCheck = $db->prepare("SELECT COUNT(*) as count FROM allowance_calculations 
                                WHERE user_id = ? AND YEAR(calculated_at) = ? 
                                AND allowance_bounty > 0");
    $bountyCheck->bind_param("ii", $user_id, $current_year);
    $bountyCheck->execute();
    $bountyResult = $bountyCheck->get_result()->fetch_assoc();
    
    if ($bountyResult['count'] == 0) {
        $allowance_bounty = $rates['bounty_all'];
    }
    
    // Add pakaian allowance for senior (once per year)
    if ($cadet['rank_level'] == 'senior') {
        $pakaianCheck = $db->prepare("SELECT COUNT(*) as count FROM allowance_calculations 
                                     WHERE user_id = ? AND YEAR(calculated_at) = ? 
                                     AND allowance_pakaian > 0");
        $pakaianCheck->bind_param("ii", $user_id, $current_year);
        $pakaianCheck->execute();
        $pakaianResult = $pakaianCheck->get_result()->fetch_assoc();
        
        if ($pakaianResult['count'] == 0) {
            $allowance_pakaian = $rates['pakaian_senior'];
        }
    }
    
    // Calculate totals
    $total_training = $allowance_tempatan + $allowance_berterusan + $allowance_kem + $allowance_pentauliahan;
    $total_additional = $allowance_bounty + $allowance_pakaian;
    $total_amount = $total_training + $total_additional;
    
    // Check if record exists
    $checkSql = "SELECT calc_id FROM allowance_calculations 
                 WHERE user_id = ? AND month_year = ?";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->bind_param("is", $user_id, $month_year);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    
    if ($existing) {
        // Update existing record
        $updateSql = "UPDATE allowance_calculations SET
                        attendance_rate = ?,
                        training_days = ?,
                        allowance_rate_junior = ?,
                        allowance_rate_intermediate = ?,
                        allowance_rate_senior = ?,
                        allowance_tempatan = ?,
                        allowance_berterusan = ?,
                        allowance_kem = ?,
                        allowance_pentauliahan = ?,
                        allowance_bounty = ?,
                        allowance_pakaian = ?,
                        total_training = ?,
                        total_additional = ?,
                        total_amount = ?,
                        calculated_by = ?,
                        calculated_at = CURRENT_TIMESTAMP
                      WHERE calc_id = ?";
        
        $updateStmt = $db->prepare($updateSql);
        
        $junior_rate = $rates['junior_per_day'];
        $intermediate_rate = $rates['intermediate_per_day'];
        $senior_rate = $rates['senior_per_day'];
        
        $updateStmt->bind_param(
            "ddddddddddddddii",
            $training_days, $training_days,
            $junior_rate, $intermediate_rate, $senior_rate,
            $allowance_tempatan, $allowance_berterusan, $allowance_kem, $allowance_pentauliahan,
            $allowance_bounty, $allowance_pakaian,
            $total_training, $total_additional, $total_amount,
            $admin_id, $existing['calc_id']
        );
        
        $updateStmt->execute();
    } else {
        // Insert new record
        $insertSql = "INSERT INTO allowance_calculations (
                        user_id, month_year, attendance_rate, training_days,
                        allowance_rate_junior, allowance_rate_intermediate, allowance_rate_senior,
                        allowance_tempatan, allowance_berterusan, allowance_kem, allowance_pentauliahan,
                        allowance_bounty, allowance_pakaian,
                        total_training, total_additional, total_amount,
                        calculated_by
                      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insertStmt = $db->prepare($insertSql);
        
        $junior_rate = $rates['junior_per_day'];
        $intermediate_rate = $rates['intermediate_per_day'];
        $senior_rate = $rates['senior_per_day'];
        
        $insertStmt->bind_param(
            "isdiddddddddddddi",
            $user_id, $month_year,
            $training_days, $training_days,
            $junior_rate, $intermediate_rate, $senior_rate,
            $allowance_tempatan, $allowance_berterusan, $allowance_kem, $allowance_pentauliahan,
            $allowance_bounty, $allowance_pakaian,
            $total_training, $total_additional, $total_amount,
            $admin_id
        );
        
        $insertStmt->execute();
    }
    
    return true;
}

// Function to get allowance details
function getAllowanceDetails($calc_id, $db) {
    $sql = "SELECT 
                ac.*,
                u.military_number,
                u.name,
                u.service_type,
                u.rank_level,
                u.email,
                u.phone,
                admin.name as admin_name
            FROM allowance_calculations ac
            JOIN users u ON ac.user_id = u.user_id
            LEFT JOIN users admin ON ac.calculated_by = admin.user_id
            WHERE ac.calc_id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $calc_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Get filter parameters
$filter_service = $_GET['service_type'] ?? '';
$filter_rank = $_GET['rank'] ?? '';
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_payment = $_GET['payment_status'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'name_asc';

// Get allowance calculations with filters
$allowanceSql = "SELECT 
                    ac.*,
                    u.user_id,
                    u.military_number,
                    u.name,
                    u.service_type,
                    u.rank_level,
                    admin.name as admin_name
                FROM allowance_calculations ac
                JOIN users u ON ac.user_id = u.user_id
                LEFT JOIN users admin ON ac.calculated_by = admin.user_id
                WHERE u.role = 'cadet'";
                
$params = [];
$types = "";

if (!empty($filter_service)) {
    $allowanceSql .= " AND u.service_type = ?";
    $params[] = $filter_service;
    $types .= "s";
}

if (!empty($filter_rank)) {
    $allowanceSql .= " AND u.rank_level = ?";
    $params[] = $filter_rank;
    $types .= "s";
}

if (!empty($filter_month)) {
    $allowanceSql .= " AND ac.month_year = ?";
    $params[] = $filter_month;
    $types .= "s";
}

if ($filter_payment !== 'all') {
    $allowanceSql .= " AND ac.is_paid = ?";
    $params[] = ($filter_payment == 'paid') ? 1 : 0;
    $types .= "i";
}

if (!empty($search)) {
    $allowanceSql .= " AND (u.name LIKE ? OR u.military_number LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "ss";
}

// Add sorting
switch ($sort_by) {
    case 'amount_desc':
        $allowanceSql .= " ORDER BY ac.total_amount DESC";
        break;
    case 'amount_asc':
        $allowanceSql .= " ORDER BY ac.total_amount ASC";
        break;
    case 'service_asc':
        $allowanceSql .= " ORDER BY u.service_type ASC, u.name ASC";
        break;
    case 'service_desc':
        $allowanceSql .= " ORDER BY u.service_type DESC, u.name ASC";
        break;
    case 'rank_asc':
        $allowanceSql .= " ORDER BY u.rank_level ASC, u.name ASC";
        break;
    case 'rank_desc':
        $allowanceSql .= " ORDER BY u.rank_level DESC, u.name ASC";
        break;
    case 'date_desc':
        $allowanceSql .= " ORDER BY ac.calculated_at DESC";
        break;
    case 'date_asc':
        $allowanceSql .= " ORDER BY ac.calculated_at ASC";
        break;
    case 'name_asc':
    default:
        $allowanceSql .= " ORDER BY u.name ASC";
        break;
}

$allowanceStmt = $db->prepare($allowanceSql);
if ($params) {
    $allowanceStmt->bind_param($types, ...$params);
}
$allowanceStmt->execute();
$allowanceResult = $allowanceStmt->get_result();
$allowanceRecords = $allowanceResult->fetch_all(MYSQLI_ASSOC);

// Get statistics
$statsSql = "SELECT 
                COUNT(*) as total_records,
                SUM(ac.total_amount) as total_amount,
                SUM(CASE WHEN ac.is_paid = 1 THEN ac.total_amount ELSE 0 END) as paid_amount,
                SUM(CASE WHEN ac.is_paid = 0 THEN ac.total_amount ELSE 0 END) as unpaid_amount
            FROM allowance_calculations ac
            JOIN users u ON ac.user_id = u.user_id
            WHERE u.role = 'cadet'";
            
$statsParams = [];
$statsTypes = "";

if (!empty($filter_service)) {
    $statsSql .= " AND u.service_type = ?";
    $statsParams[] = $filter_service;
    $statsTypes .= "s";
}

if (!empty($filter_rank)) {
    $statsSql .= " AND u.rank_level = ?";
    $statsParams[] = $filter_rank;
    $statsTypes .= "s";
}

if (!empty($filter_month)) {
    $statsSql .= " AND ac.month_year = ?";
    $statsParams[] = $filter_month;
    $statsTypes .= "s";
}

if ($filter_payment !== 'all') {
    $statsSql .= " AND ac.is_paid = ?";
    $statsParams[] = ($filter_payment == 'paid') ? 1 : 0;
    $statsTypes .= "i";
}

$statsStmt = $db->prepare($statsSql);
if ($statsParams) {
    $statsStmt->bind_param($statsTypes, ...$statsParams);
}
$statsStmt->execute();
$statsResult = $statsStmt->get_result();
$stats = $statsResult->fetch_assoc();

// Get distinct service types
$serviceStmt = $db->query("SELECT DISTINCT service_type FROM users WHERE service_type IS NOT NULL ORDER BY service_type");
$serviceTypes = $serviceStmt->fetch_all(MYSQLI_ASSOC);

// Get distinct rank levels
$rankStmt = $db->query("SELECT DISTINCT rank_level FROM users WHERE rank_level IS NOT NULL ORDER BY rank_level");
$rankLevels = $rankStmt->fetch_all(MYSQLI_ASSOC);

$serviceLabels = [
    'darat' => '<span class="service-option service-army"><i class="fas fa-truck"></i> Army</span>',
    'laut' => '<span class="service-option service-navy"><i class="fas fa-ship"></i> Navy</span>',
    'udara' => '<span class="service-option service-airforce"><i class="fas fa-fighter-jet"></i> Air Force</span>'
];

$rankLabels = [
    'junior' => '<span class="badge badge-junior">Junior</span>',
    'intermediate' => '<span class="badge badge-intermediate">Intermediate</span>',
    'senior' => '<span class="badge badge-senior">Senior</span>'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Allowance Management - CAAMS</title>
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
            --export: #805ad5;
            --pdf: #e53e3e;
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
        
        .header h1 {
            font-size: 1.8rem;
            margin-bottom: 5px;
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
        .stat-card.paid { border-top-color: var(--success); }
        .stat-card.unpaid { border-top-color: var(--danger); }
        
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
        
        /* Custom styled options for service filter */
        .service-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 0;
        }
        
        .service-army {
            color: var(--army-green);
        }
        
        .service-navy {
            color: var(--navy);
        }
        
        .service-airforce {
            color: var(--airforce-blue);
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
        
        .btn-secondary {
            background: #edf2f7;
            color: var(--secondary);
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .btn-export {
            background: var(--export);
            color: white;
        }
        
        .btn-export:hover {
            background: #6b46c1;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .btn-pdf {
            background: var(--pdf);
            color: white;
        }
        
        .btn-pdf:hover {
            background: #c53030;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        /* DATE FILTER ROW */
        .date-filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }
        
        /* ATTENDANCE TABLE */
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .allowance-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .allowance-table th {
            background: var(--primary);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .allowance-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .allowance-table tr:hover {
            background: #f7fafc;
        }
        
        /* CADET INFO */
        .cadet-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .cadet-avatar {
            width: 40px;
            height: 40px;
            background: var(--accent);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
        }
        
        .cadet-name {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 2px;
        }
        
        .cadet-number {
            font-size: 0.9rem;
            color: var(--secondary);
        }
        
        /* SERVICE BADGES */
        .service-badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-army { 
            background: #c6f6d5; 
            color: var(--army-green);
        }
        
        .badge-navy { 
            background: #bee3f8; 
            color: var(--navy);
        }
        
        .badge-airforce { 
            background: #e9d8fd; 
            color: var(--airforce-blue);
        }
        
        /* STATUS BADGES */
        .status-badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-paid { 
            background: #c6f6d5; 
            color: var(--success);
        }
        
        .status-unpaid { 
            background: #fed7d7; 
            color: var(--danger);
        }
        
        /* ALLOWANCE AMOUNT */
        .allowance-amount {
            font-weight: bold;
            font-size: 1.1rem;
            color: var(--primary);
        }
        
        /* ACTION BUTTONS */
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
        
        /* ALERTS */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success);
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger);
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
        
        /* CONFIGURE RATES BUTTON */
        .configure-rates-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 100;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 15px 25px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
        }
        
        .configure-rates-btn:hover {
            background: var(--accent);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
        }
        
        /* MODALS */
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
            padding: 30px;
            border-radius: 15px;
            max-width: 800px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 1.5rem;
            color: var(--primary);
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--secondary);
        }
        
        /* DETAILS MODAL */
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .details-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid var(--accent);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .details-title {
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .details-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .details-label {
            color: var(--secondary);
            font-weight: 500;
        }
        
        .details-value {
            font-weight: 600;
            color: var(--primary);
        }
        
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding: 8px;
            background: #f7fafc;
            border-radius: 6px;
        }
        
        .breakdown-label {
            color: var(--secondary);
        }
        
        .breakdown-value {
            font-weight: 600;
            color: var(--primary);
        }
        
        .total-breakdown {
            background: var(--primary);
            color: white;
            padding: 12px;
            border-radius: 6px;
            margin-top: 10px;
            font-weight: bold;
        }
        
        /* RATE CARDS IN MODAL */
        .rate-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .rate-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid var(--accent);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .rate-title {
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .rate-group {
            display: flex;
            flex-direction: column;
            margin-bottom: 15px;
        }
        
        .rate-group label {
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        .rate-input {
            width: 100%;
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-size: 16px;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .allowance-table {
                display: block;
                overflow-x: auto;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .date-filter-row {
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
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .rate-cards, .details-grid {
                grid-template-columns: 1fr;
            }
            
            .configure-rates-btn {
                bottom: 20px;
                right: 20px;
                padding: 12px 20px;
                font-size: 14px;
            }
        }
        
        @media (max-width: 480px) {
            .container {
                border-radius: 10px;
            }
            
            .header, .content {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 1.4rem;
            }
            
            .section-title {
                font-size: 1.2rem;
            }
            
            .stat-card {
                padding: 15px;
            }
            
            .stat-number {
                font-size: 1.8rem;
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
                <i class="fas fa-money-bill-wave"></i> Allowance Management
            </h1>
            <p style="opacity: 0.8; margin-top: 5px;">Manage and calculate cadet allowances based on actual attendance</p>
        </div>
        
        <div class="content">
            <!-- ALERTS -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType == 'success' ? 'success' : 'error'; ?>">
                    <i class="fas <?php echo $messageType == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                    <div><?php echo $message; ?></div>
                </div>
            <?php endif; ?>
            
            <!-- DASHBOARD STATS -->
            <div class="dashboard-stats">
                <div class="stat-card total">
                    <div class="stat-icon" style="color: var(--primary);">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['total_records'] ?? 0; ?></div>
                    <div class="stat-label">Cadet Records</div>
                    <div class="stat-number" style="font-size: 1.5rem; color: var(--accent);">
                        RM <?php echo number_format($stats['total_amount'] ?? 0, 2); ?>
                    </div>
                </div>
                
                <div class="stat-card paid">
                    <div class="stat-icon" style="color: var(--success);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-number">RM <?php echo number_format($stats['paid_amount'] ?? 0, 2); ?></div>
                    <div class="stat-label">Total Paid</div>
                </div>
                
                <div class="stat-card unpaid">
                    <div class="stat-icon" style="color: var(--danger);">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-number">RM <?php echo number_format($stats['unpaid_amount'] ?? 0, 2); ?></div>
                    <div class="stat-label">Pending Payment</div>
                </div>
            </div>
            
            <!-- FILTER SECTION -->
            <div class="filter-section">
                <div class="section-title">
                    <i class="fas fa-filter"></i> Allowance Filters
                </div>
                
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label>Service</label>
                            <select name="service_type" class="filter-select">
                                <option value="">All Services</option>
                                <?php foreach ($serviceTypes as $service): ?>
                                    <option value="<?php echo htmlspecialchars($service['service_type']); ?>" 
                                        <?php echo $filter_service == $service['service_type'] ? 'selected' : ''; ?>>
                                        <?php echo $serviceLabels[$service['service_type']] ?? ucfirst($service['service_type']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Rank</label>
                            <select name="rank" class="filter-select">
                                <option value="">All Ranks</option>
                                <?php foreach ($rankLevels as $rank): ?>
                                    <option value="<?php echo htmlspecialchars($rank['rank_level']); ?>" 
                                        <?php echo $filter_rank == $rank['rank_level'] ? 'selected' : ''; ?>>
                                        <?php echo $rankLabels[$rank['rank_level']] ?? ucfirst($rank['rank_level']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Month</label>
                            <input type="month" name="month" 
                                   value="<?php echo htmlspecialchars($filter_month); ?>"
                                   max="<?php echo date('Y-m'); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label>Payment Status</label>
                            <select name="payment_status" class="filter-select">
                                <option value="all">All Status</option>
                                <option value="paid" <?php echo $filter_payment == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="unpaid" <?php echo $filter_payment == 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Search</label>
                            <input type="text" name="search" placeholder="Name or Military Number" 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply All Filters
                        </button>
                        <a href="manage_allowance.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                        <button type="button" class="btn btn-success" onclick="openCalculateModal()">
                            <i class="fas fa-calculator"></i> Calculate
                        </button>
                        <a href="?<?php 
                            $exportParams = $_GET;
                            $exportParams['export'] = 'csv';
                            echo http_build_query($exportParams); 
                        ?>" class="btn btn-export">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </a>
                        
                    </div>
                </form>
            </div>
            
            <!-- ALLOWANCE TABLE -->
            <div class="section-title">
                <i class="fas fa-list"></i> Allowance List
                <span style="background: var(--accent); color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.9rem;">
                    <?php echo count($allowanceRecords); ?> records
                </span>
            </div>
            
            <div class="table-container">
                <?php if (empty($allowanceRecords)): ?>
                    <div class="empty-state">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3>No Allowance Records Found</h3>
                        <p>No allowance records found for the selected filters.</p>
                        <button class="btn btn-primary" onclick="openCalculateModal()" style="margin-top: 20px;">
                            <i class="fas fa-calculator"></i> Calculate Allowance Now
                        </button>
                    </div>
                <?php else: ?>
                    <table class="allowance-table">
                        <thead>
                            <tr>
                                <th>Cadet</th>
                                <th>Service</th>
                                <th>Rank</th>
                                <th>Month</th>
                                <th>Training Days</th>
                                <th>Total Amount</th>
                                <th>Payment Status</th>
                                <th>Calculated By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allowanceRecords as $record): ?>
                            <tr>
                                <td>
                                    <div class="cadet-info">
                                        <div class="cadet-avatar">
                                            <?php echo substr($record['name'], 0, 1); ?>
                                        </div>
                                        <div>
                                            <div class="cadet-name"><?php echo htmlspecialchars($record['name']); ?></div>
                                            <div class="cadet-number"><?php echo $record['military_number']; ?></div>
                                        </div>
                                    </div>
                                </td>
                                
                                <td>
                                    <span class="service-badge badge-<?php echo $record['service_type']; ?>">
                                        <i class="fas 
                                            <?php echo $record['service_type'] == 'darat' ? 'fa-truck' : 
                                                   ($record['service_type'] == 'laut' ? 'fa-ship' : 'fa-fighter-jet'); ?>">
                                        </i>
                                        <?php echo ucfirst($record['service_type']); ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <span class="badge badge-<?php echo $record['rank_level']; ?>">
                                        <?php echo ucfirst($record['rank_level']); ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <span style="font-weight: 500;">
                                        <?php echo date('F Y', strtotime($record['month_year'] . '-01')); ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <span style="font-weight: 500; font-size: 1.1rem;">
                                        <?php echo $record['training_days']; ?> days
                                    </span>
                                </td>
                                
                                <td>
                                    <span class="allowance-amount">
                                        RM <?php echo number_format($record['total_amount'], 2); ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <span class="status-badge status-<?php echo $record['is_paid'] ? 'paid' : 'unpaid'; ?>">
                                        <?php echo $record['is_paid'] ? 'Paid' : 'Unpaid'; ?>
                                        <?php if ($record['payment_date']): ?>
                                            <br>
                                            <small style="font-size: 0.8rem;">
                                                <?php echo date('d/m/Y', strtotime($record['payment_date'])); ?>
                                            </small>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <?php if ($record['admin_name']): ?>
                                        <span style="font-size: 0.9rem; color: var(--secondary);">
                                            <?php echo htmlspecialchars($record['admin_name']); ?><br>
                                            <small><?php echo date('d/m/Y', strtotime($record['calculated_at'])); ?></small>
                                        </span>
                                    <?php else: ?>
                                        <span style="font-size: 0.9rem; color: var(--secondary);">
                                            <i class="fas fa-robot"></i> System
                                        </span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <div class="action-btns">
                                        <?php if (!$record['is_paid']): ?>
                                            <button class="btn-small btn-success" 
                                                    onclick="updatePayment(<?php echo $record['calc_id']; ?>)">
                                                <i class="fas fa-check"></i> Paid
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button class="btn-small btn-primary" 
                                                onclick="recalculateAllowance(<?php echo $record['user_id']; ?>, '<?php echo $record['month_year']; ?>')">
                                            <i class="fas fa-redo"></i> Recalculate
                                        </button>
                                        
                                        <button class="btn-small btn-warning" 
                                                onclick="viewAllowanceDetails(<?php echo $record['calc_id']; ?>)">
                                            <i class="fas fa-eye"></i> View
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

    <!-- CONFIGURE RATES BUTTON (FLOATING) -->
    <button class="configure-rates-btn" onclick="openRateModal()">
        <i class="fas fa-cog"></i> Configure Rates
    </button>

    <!-- RATE CONFIGURATION MODAL -->
    <div id="rateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-cog"></i> Allowance Rate Configuration
                </h3>
                <button class="close-modal" onclick="closeRateModal()">&times;</button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="update_rates" value="1">
                
                <div class="rate-cards">
                    <!-- Latihan Tempatan/Baris -->
                    <div class="rate-card">
                        <div class="rate-title">
                            <i class="fas fa-map-marker-alt"></i> Latihan Tempatan/Baris
                        </div>
                        <div class="rate-group">
                            <label>Rate per hour (RM)</label>
                            <input type="number" step="0.01" name="tempatan_per_hour" 
                                   class="rate-input" value="<?php echo $allowanceRates['tempatan_per_hour']; ?>" required>
                        </div>
                        <div class="rate-group">
                            <label>Hours per day</label>
                            <input type="number" step="1" name="tempatan_hours_per_day" 
                                   class="rate-input" value="<?php echo $allowanceRates['tempatan_hours_per_day']; ?>" required>
                        </div>
                        <small style="color: var(--secondary); font-size: 0.9rem;">
                            Note: 1 day = <?php echo $allowanceRates['tempatan_hours_per_day']; ?> hours (RM<?php echo number_format($allowanceRates['tempatan_per_hour'] * $allowanceRates['tempatan_hours_per_day'], 2); ?> per day)
                        </small>
                    </div>
                    
                    <!-- Latihan Berterusan -->
                    <div class="rate-card">
                        <div class="rate-title">
                            <i class="fas fa-running"></i> Latihan Berterusan
                        </div>
                        <div class="rate-group">
                            <label>Junior per day (RM)</label>
                            <input type="number" step="0.01" name="junior_per_day" 
                                   class="rate-input" value="<?php echo $allowanceRates['junior_per_day']; ?>" required>
                        </div>
                        <div class="rate-group">
                            <label>Intermediate per day (RM)</label>
                            <input type="number" step="0.01" name="intermediate_per_day" 
                                   class="rate-input" value="<?php echo $allowanceRates['intermediate_per_day']; ?>" required>
                        </div>
                        <div class="rate-group">
                            <label>Senior per day (RM)</label>
                            <input type="number" step="0.01" name="senior_per_day" 
                                   class="rate-input" value="<?php echo $allowanceRates['senior_per_day']; ?>" required>
                        </div>
                    </div>
                    
                    <!-- Latihan Kem Tahunan -->
                    <div class="rate-card">
                        <div class="rate-title">
                            <i class="fas fa-campground"></i> Latihan Kem Tahunan
                        </div>
                        <div class="rate-group">
                            <label>Junior per day (RM)</label>
                            <input type="number" step="0.01" value="<?php echo $allowanceRates['junior_per_day']; ?>" 
                                   class="rate-input" readonly style="background: #f7fafc;">
                        </div>
                        <div class="rate-group">
                            <label>Intermediate per day (RM)</label>
                            <input type="number" step="0.01" value="<?php echo $allowanceRates['intermediate_per_day']; ?>" 
                                   class="rate-input" readonly style="background: #f7fafc;">
                        </div>
                        <div class="rate-group">
                            <label>Senior per day (RM)</label>
                            <input type="number" step="0.01" value="<?php echo $allowanceRates['senior_per_day']; ?>" 
                                   class="rate-input" readonly style="background: #f7fafc;">
                        </div>
                        <small style="color: var(--secondary); font-size: 0.9rem;">
                            Same rates as Latihan Berterusan
                        </small>
                    </div>
                    
                    <!-- Latihan Pentauliahan -->
                    <div class="rate-card">
                        <div class="rate-title">
                            <i class="fas fa-graduation-cap"></i> Latihan Pentauliahan
                        </div>
                        <div class="rate-group">
                            <label>Senior rate (RM)</label>
                            <input type="number" step="0.01" name="pentauliahan_senior" 
                                   class="rate-input" value="<?php echo $allowanceRates['pentauliahan_senior']; ?>" required>
                        </div>
                        <div class="rate-group">
                            <label>Duration (days)</label>
                            <input type="number" step="1" name="pentauliahan_days" 
                                   class="rate-input" value="<?php echo $allowanceRates['pentauliahan_days']; ?>" required>
                        </div>
                        <small style="color: var(--secondary); font-size: 0.9rem;">
                            For senior cadets only (14 days) = RM<?php echo number_format($allowanceRates['pentauliahan_senior'] / $allowanceRates['pentauliahan_days'], 2); ?> per day
                        </small>
                    </div>
                    
                    <!-- Elaun Tambahan -->
                    <div class="rate-card">
                        <div class="rate-title">
                            <i class="fas fa-gift"></i> Additional Allowances
                        </div>
                        <div class="rate-group">
                            <label>Pakaian (Senior only) (RM)</label>
                            <input type="number" step="0.01" name="pakaian_senior" 
                                   class="rate-input" value="<?php echo $allowanceRates['pakaian_senior']; ?>" required>
                        </div>
                        <div class="rate-group">
                            <label>Bounty (All ranks) (RM)</label>
                            <input type="number" step="0.01" name="bounty_all" 
                                   class="rate-input" value="<?php echo $allowanceRates['bounty_all']; ?>" required>
                        </div>
                        <small style="color: var(--secondary); font-size: 0.9rem;">
                            Given once per year
                        </small>
                    </div>
                </div>
                
                <div style="margin-top: 30px; display: flex; gap: 15px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeRateModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Save All Rates
                    </button>
                    <button type="button" class="btn btn-warning" onclick="resetRates()">
                        <i class="fas fa-undo"></i> Reset Defaults
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- CALCULATE ALLOWANCE MODAL -->
    <div id="calculateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-calculator"></i> Calculate Allowance
                </h3>
                <button class="close-modal" onclick="closeCalculateModal()">&times;</button>
            </div>
            
            <form method="POST" action="" id="calculateForm">
                <div class="filter-group" style="margin-bottom: 20px;">
                    <label>Month to Calculate</label>
                    <input type="month" name="month_year" id="calcMonth" 
                           value="<?php echo date('Y-m'); ?>"
                           max="<?php echo date('Y-m'); ?>" required>
                </div>
                
                <div class="filter-group" style="margin-bottom: 20px;">
                    <label>Calculation Type</label>
                    <select name="calc_type" id="calcType" onchange="toggleCalcType()">
                        <option value="bulk">Bulk Calculate (All Cadets)</option>
                        <option value="specific">Specific Cadet</option>
                    </select>
                </div>
                
                <div id="specificCadetFields" style="display: none;">
                    <div class="filter-group" style="margin-bottom: 20px;">
                        <label>Select Cadet</label>
                        <select name="user_id" id="cadetSelect">
                            <option value="">Select Cadet</option>
                            <?php 
                            $cadetsStmt = $db->query("SELECT user_id, military_number, name, rank_level FROM users WHERE role = 'cadet' ORDER BY name");
                            $cadets = $cadetsStmt->fetch_all(MYSQLI_ASSOC);
                            foreach ($cadets as $cadet): ?>
                                <option value="<?php echo $cadet['user_id']; ?>">
                                    <?php echo htmlspecialchars($cadet['name'] . ' (' . $cadet['military_number'] . ') - ' . ucfirst($cadet['rank_level'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div id="bulkFilters">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label>Service Type (Optional)</label>
                            <select name="service_type" id="calcService">
                                <option value="">All Services</option>
                                <?php foreach ($serviceTypes as $service): ?>
                                    <option value="<?php echo htmlspecialchars($service['service_type']); ?>">
                                        <?php echo ucfirst($service['service_type']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Rank Level (Optional)</label>
                            <select name="rank_level" id="calcRank">
                                <option value="">All Ranks</option>
                                <?php foreach ($rankLevels as $rank): ?>
                                    <option value="<?php echo htmlspecialchars($rank['rank_level']); ?>">
                                        <?php echo ucfirst($rank['rank_level']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 30px; display: flex; gap: 15px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeCalculateModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success" name="bulk_calculate" id="calcButton">
                        <i class="fas fa-calculator"></i> Calculate Allowance
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- UPDATE PAYMENT MODAL -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-money-check-alt"></i> Update Payment Status
                </h3>
                <button class="close-modal" onclick="closePaymentModal()">&times;</button>
            </div>
            
            <form method="POST" action="" id="paymentForm">
                <input type="hidden" name="calc_id" id="paymentCalcId">
                <input type="hidden" name="update_payment" value="1">
                
                <div class="filter-group" style="margin-bottom: 20px;">
                    <label>Payment Status</label>
                    <select name="payment_status" id="paymentStatus" onchange="togglePaymentDate()">
                        <option value="unpaid">Unpaid</option>
                        <option value="paid">Paid</option>
                    </select>
                </div>
                
                <div class="filter-group" id="paymentDateField" style="display: none;">
                    <label>Payment Date</label>
                    <input type="date" name="payment_date" id="paymentDate" 
                           value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div style="margin-top: 30px; display: flex; gap: 15px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closePaymentModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- ALLOWANCE DETAILS MODAL -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-file-invoice-dollar"></i> Allowance Details
                </h3>
                <button class="close-modal" onclick="closeDetailsModal()">&times;</button>
            </div>
            
            <div id="detailsContent">
                <!-- Details will be loaded here via AJAX -->
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: var(--accent);"></i>
                    <p style="margin-top: 20px; color: var(--secondary);">Loading details...</p>
                </div>
            </div>
            
            <div style="margin-top: 30px; display: flex; gap: 15px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closeDetailsModal()">
                    <i class="fas fa-times"></i> Close
                </button>
                <button type="button" class="btn btn-primary" onclick="printAllowanceDetails()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>

    <script>
        // Rate Modal
        function openRateModal() {
            document.getElementById('rateModal').style.display = 'flex';
        }
        
        function closeRateModal() {
            document.getElementById('rateModal').style.display = 'none';
        }
        
        // Calculate Modal
        function openCalculateModal() {
            document.getElementById('calculateModal').style.display = 'flex';
        }
        
        function closeCalculateModal() {
            document.getElementById('calculateModal').style.display = 'none';
        }
        
        function toggleCalcType() {
            const calcType = document.getElementById('calcType').value;
            const specificFields = document.getElementById('specificCadetFields');
            const bulkFilters = document.getElementById('bulkFilters');
            const calcButton = document.getElementById('calcButton');
            
            if (calcType === 'specific') {
                specificFields.style.display = 'block';
                bulkFilters.style.display = 'none';
                calcButton.name = 'recalculate';
                calcButton.innerHTML = '<i class="fas fa-calculator"></i> Calculate for Selected Cadet';
            } else {
                specificFields.style.display = 'none';
                bulkFilters.style.display = 'block';
                calcButton.name = 'bulk_calculate';
                calcButton.innerHTML = '<i class="fas fa-calculator"></i> Calculate Allowance';
            }
        }
        
        // Payment Modal
        function updatePayment(calcId) {
            document.getElementById('paymentCalcId').value = calcId;
            document.getElementById('paymentModal').style.display = 'flex';
        }
        
        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
        }
        
        function togglePaymentDate() {
            const paymentStatus = document.getElementById('paymentStatus').value;
            const paymentDateField = document.getElementById('paymentDateField');
            
            if (paymentStatus === 'paid') {
                paymentDateField.style.display = 'block';
            } else {
                paymentDateField.style.display = 'none';
            }
        }
        
        // Details Modal
        function viewAllowanceDetails(calcId) {
            document.getElementById('detailsModal').style.display = 'flex';
            
            // Load details via AJAX
            fetch(`get_allowance_details.php?calc_id=${calcId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('detailsContent').innerHTML = data;
                })
                .catch(error => {
                    document.getElementById('detailsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px;">
                            <i class="fas fa-exclamation-triangle fa-2x" style="color: var(--danger);"></i>
                            <p style="margin-top: 20px; color: var(--danger);">Error loading details. Please try again.</p>
                        </div>
                    `;
                });
        }
        
        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }
        
        function printAllowanceDetails() {
            const detailsContent = document.getElementById('detailsContent').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Allowance Details</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        .print-header { text-align: center; margin-bottom: 30px; }
                        .print-header h1 { color: #1a365d; }
                        .print-section { margin-bottom: 20px; }
                        .print-item { display: flex; justify-content: space-between; margin-bottom: 10px; }
                        .print-label { font-weight: bold; color: #2d3748; }
                        .print-value { color: #1a365d; }
                        .print-breakdown { margin-top: 20px; }
                        .breakdown-item { display: flex; justify-content: space-between; padding: 8px; background: #f7fafc; margin-bottom: 5px; }
                        .total-breakdown { background: #1a365d; color: white; padding: 12px; font-weight: bold; margin-top: 20px; }
                        @media print {
                            button { display: none; }
                        }
                    </style>
                </head>
                <body>
                    ${detailsContent}
                    <script>
                        window.onload = function() { window.print(); }
                    <\/script>
                </body>
                </html>
            `);
            printWindow.document.close();
        }
        
        // Other functions
        function recalculateAllowance(userId, monthYear) {
            if (confirm('Recalculate allowance for this cadet?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'user_id';
                userIdInput.value = userId;
                
                const monthInput = document.createElement('input');
                monthInput.type = 'hidden';
                monthInput.name = 'month_year';
                monthInput.value = monthYear;
                
                const recalcInput = document.createElement('input');
                recalcInput.type = 'hidden';
                recalcInput.name = 'recalculate';
                recalcInput.value = '1';
                
                form.appendChild(userIdInput);
                form.appendChild(monthInput);
                form.appendChild(recalcInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function resetRates() {
            if (confirm('Reset all rates to default values?')) {
                // Default rates based on your requirement
                document.querySelector('input[name="tempatan_per_hour"]').value = 8.00;
                document.querySelector('input[name="tempatan_hours_per_day"]').value = 12;
                document.querySelector('input[name="junior_per_day"]').value = 53.83;
                document.querySelector('input[name="intermediate_per_day"]').value = 58.00;
                document.querySelector('input[name="senior_per_day"]').value = 62.17;
                document.querySelector('input[name="pentauliahan_senior"]').value = 870.83;
                document.querySelector('input[name="pentauliahan_days"]').value = 14;
                document.querySelector('input[name="pakaian_senior"]').value = 1500.00;
                document.querySelector('input[name="bounty_all"]').value = 520.00;
            }
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // Auto-close modals on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.style.display = 'none';
                });
            }
        });
    </script>
</body>
</html>
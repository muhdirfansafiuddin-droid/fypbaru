<?php
// admin/manage_allowance.php - SIMPLIFIED VERSION WITH BULK CALCULATION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

RBAC::checkPermission('admin');
$user = (new Auth())->getCurrentUser();
$db = new Database();

// ================= DEFAULT RATES =================
$defaultRates = [
    'local_per_day' => 96.00,
    'continuous_junior' => 53.83,
    'continuous_intermediate' => 58.00,
    'continuous_senior' => 62.17,
    'camp_junior' => 53.83,
    'camp_intermediate' => 58.00,
    'camp_senior' => 62.17,
    'accreditation_per_day' => 62.20,
    'clothing_senior' => 125.00,
    'bounty_all' => 43.33,
];

// Load rates from database - KEEP ORIGINAL MALAY COLUMN NAMES
$ratesSql = "SELECT 
    allowance_rate_junior,
    allowance_rate_intermediate,
    allowance_rate_senior,
    training_rate_latihan_tempatan,
    training_rate_latihan_berterusan,
    training_rate_latihan_kem
    FROM users WHERE role = 'admin' LIMIT 1";
$ratesResult = $db->query($ratesSql);

if ($ratesResult && $ratesResult->num_rows > 0) {
    $dbRates = $ratesResult->fetch_assoc();
    
    $rates = [
        'local_per_day' => ($dbRates['training_rate_latihan_tempatan'] ?? 8.00) * 12,
        'continuous_junior' => $dbRates['allowance_rate_junior'] ?? 53.83,
        'continuous_intermediate' => $dbRates['allowance_rate_intermediate'] ?? 58.00,
        'continuous_senior' => $dbRates['allowance_rate_senior'] ?? 62.17,
        'camp_junior' => $dbRates['allowance_rate_junior'] ?? 53.83,
        'camp_intermediate' => $dbRates['allowance_rate_intermediate'] ?? 58.00,
        'camp_senior' => $dbRates['allowance_rate_senior'] ?? 62.17,
        'accreditation_per_day' => 62.20,
        'clothing_senior' => 125.00,
        'bounty_all' => 43.33,
    ];
} else {
    $rates = $defaultRates;
}

$_SESSION['allowance_rates'] = $rates;

// ================= HANDLE FORM SUBMISSIONS =================
$message = '';
$messageType = '';

// Determine active section
if (isset($_GET['section'])) {
    $active_section = $_GET['section'];
} elseif (isset($_POST['update_rates'])) {
    $active_section = 'rates';
} elseif (isset($_POST['calculate_allowance']) && isset($_POST['cadet_id']) && $_POST['cadet_id'] > 0) {
    $active_section = 'result';
} elseif (isset($_POST['calculate_allowance'])) {
    $active_section = 'calculate';
} elseif (isset($_POST['save_calculation'])) {
    $active_section = 'result';
} elseif (isset($_POST['update_attendance_payment'])) {
    $active_section = 'payments';
} else {
    $active_section = 'rates';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. UPDATE RATES
    if (isset($_POST['update_rates'])) {
        $local_per_day = floatval($_POST['rate_local_per_day']);
        $local_per_hour = $local_per_day / 12;
        
        $accreditation_total = floatval($_POST['rate_accreditation_total']);
        $accreditation_per_day = $accreditation_total / 14;
        
        $clothing_senior = floatval($_POST['rate_clothing_senior']) * 12; // Convert monthly to yearly
        $bounty_all = floatval($_POST['rate_bounty_all']) * 12; // Convert monthly to yearly
        
        // Update rates in database - KEEP ORIGINAL MALAY COLUMN NAMES
        $updateSql = "UPDATE users SET 
            allowance_rate_junior = ?,
            allowance_rate_intermediate = ?,
            allowance_rate_senior = ?,
            training_rate_latihan_tempatan = ?,
            training_rate_latihan_berterusan = ?,
            training_rate_latihan_kem = ?
            WHERE role = 'admin'";
        
        $stmt = $db->prepare($updateSql);
        $continuous_rate = ($_POST['rate_continuous_junior'] + $_POST['rate_continuous_intermediate'] + $_POST['rate_continuous_senior']) / 3;
        $camp_rate = ($_POST['rate_camp_junior'] + $_POST['rate_camp_intermediate'] + $_POST['rate_camp_senior']) / 3;
        
        $stmt->bind_param("dddiii",
            $_POST['rate_continuous_junior'],
            $_POST['rate_continuous_intermediate'],
            $_POST['rate_continuous_senior'],
            $local_per_hour,
            $continuous_rate,
            $camp_rate
        );
        
        if ($stmt->execute()) {
            // Update session rates
            $rates = [
                'local_per_day' => $local_per_day,
                'continuous_junior' => floatval($_POST['rate_continuous_junior']),
                'continuous_intermediate' => floatval($_POST['rate_continuous_intermediate']),
                'continuous_senior' => floatval($_POST['rate_continuous_senior']),
                'camp_junior' => floatval($_POST['rate_camp_junior']),
                'camp_intermediate' => floatval($_POST['rate_camp_intermediate']),
                'camp_senior' => floatval($_POST['rate_camp_senior']),
                'accreditation_per_day' => $accreditation_per_day,
                'clothing_senior' => floatval($_POST['rate_clothing_senior']),
                'bounty_all' => floatval($_POST['rate_bounty_all']),
            ];
            
            $_SESSION['allowance_rates'] = $rates;
            
            // Log activity
            $logSql = "INSERT INTO activity_logs (user_id, activity_type, description) VALUES (?, 'allowance_rate_updated', ?)";
            $logStmt = $db->prepare($logSql);
            $logDesc = "Updated allowance rates";
            $logStmt->bind_param("is", $user['user_id'], $logDesc);
            $logStmt->execute();
            
            $message = '✅ Allowance rates updated successfully!';
            $messageType = 'success';
        } else {
            $message = '❌ Error: ' . $stmt->error;
            $messageType = 'error';
        }
    }
    
    // 2. CALCULATE ALLOWANCE (SINGLE OR BULK)
    elseif (isset($_POST['calculate_allowance'])) {
        $month_year = $_POST['month_year'];
        $calculation_type = $_POST['calculation_type'] ?? 'single';
        $service_filter = $_POST['service_filter'] ?? 'all';
        $rank_filter = $_POST['rank_filter'] ?? 'all';
        
        if ($calculation_type == 'single') {
            $cadet_id = $_POST['cadet_id'] ?? 0;
            
            if ($cadet_id > 0) {
                // Single cadet calculation
                $sql = "SELECT * FROM users WHERE user_id = ? AND role = 'cadet'";
                $stmt = $db->prepare($sql);
                $stmt->bind_param("i", $cadet_id);
                $stmt->execute();
                $cadet = $stmt->get_result()->fetch_assoc();
                
                if ($cadet) {
                    $calculation = calculateCadetAllowance($cadet, $month_year, $rates);
                    $_SESSION['single_result'] = $calculation;
                    $_SESSION['calc_month'] = $month_year;
                    $active_section = 'result';
                    $message = '✅ Successfully calculated allowance for ' . htmlspecialchars($cadet['name']);
                    $messageType = 'success';
                }
            }
        } else {
            // Bulk calculation
            $sql = "SELECT * FROM users WHERE role = 'cadet'";
            $params = [];
            $types = '';
            
            if ($service_filter != 'all') {
                $sql .= " AND service_type = ?";
                $params[] = $service_filter;
                $types .= 's';
            }
            
            if ($rank_filter != 'all') {
                $sql .= " AND rank_level = ?";
                $params[] = $rank_filter;
                $types .= 's';
            }
            
            $sql .= " ORDER BY service_type, rank_level, name";
            $stmt = $db->prepare($sql);
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $cadetsResult = $stmt->get_result();
            
            $bulkResults = [];
            $total_cadets = 0;
            
            while ($cadet = $cadetsResult->fetch_assoc()) {
                $calculation = calculateCadetAllowance($cadet, $month_year, $rates);
                $bulkResults[] = $calculation;
                $total_cadets++;
            }
            
            $_SESSION['bulk_results'] = $bulkResults;
            $_SESSION['calc_month'] = $month_year;
            $_SESSION['filter_service'] = $service_filter;
            $_SESSION['filter_rank'] = $rank_filter;
            
            $message = '✅ Successfully calculated allowance for ' . $total_cadets . ' cadets';
            $messageType = 'success';
            $active_section = 'bulk_result';
        }
    }
    
    // 3. SAVE CALCULATION TO DATABASE
    elseif (isset($_POST['save_calculation'])) {
        $user_id = intval($_POST['user_id']);
        $month_year = $_POST['month_year'];
        $attendance_days = intval($_POST['attendance_days']);
        $total_sessions = intval($_POST['total_sessions']);
        $attendance_rate = floatval($_POST['attendance_rate']);
        $total_training = floatval($_POST['total_training']);
        $total_additional = floatval($_POST['total_additional']);
        $total_amount = floatval($_POST['total_amount']);
        
        $is_paid = isset($_POST['is_paid']) ? 1 : 0;
        $payment_date = $is_paid ? date('Y-m-d') : NULL;
        
        // Check if record exists
        $checkSql = "SELECT calc_id FROM allowance_calculations WHERE user_id = ? AND month_year = ?";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->bind_param("is", $user_id, $month_year);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            // Update existing
            $row = $checkResult->fetch_assoc();
            $calc_id = $row['calc_id'];
            
            $updateSql = "UPDATE allowance_calculations SET 
                attendance_rate = ?,
                base_amount = ?,
                calculated_amount = ?,
                total_amount = ?,
                is_paid = ?,
                payment_date = ?,
                calculated_by = ?,
                updated_at = NOW()
                WHERE calc_id = ?";
            
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->bind_param("ddddissi",
                $attendance_rate,
                $total_training,
                $total_additional,
                $total_amount,
                $is_paid,
                $payment_date,
                $user['user_id'],
                $calc_id
            );
            
            if ($updateStmt->execute()) {
                $message = '✅ Allowance record updated successfully!';
                $messageType = 'success';
                
                // Update session data
                if (isset($_SESSION['single_result'])) {
                    $_SESSION['single_result']['calc_id'] = $calc_id;
                    $_SESSION['single_result']['is_paid'] = $is_paid;
                    $_SESSION['single_result']['payment_date'] = $payment_date;
                }
            } else {
                $message = '❌ Error: ' . $updateStmt->error;
                $messageType = 'error';
            }
        } else {
            // Insert new
            $insertSql = "INSERT INTO allowance_calculations 
                (user_id, month_year, attendance_rate, base_amount, calculated_amount, 
                 total_amount, calculated_by, is_paid, payment_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $insertStmt = $db->prepare($insertSql);
            $insertStmt->bind_param("isdidddss",
                $user_id,
                $month_year,
                $attendance_rate,
                $total_training,
                $total_additional,
                $total_amount,
                $user['user_id'],
                $is_paid,
                $payment_date
            );
            
            if ($insertStmt->execute()) {
                $calc_id = $insertStmt->insert_id;
                $message = '✅ Allowance record saved successfully!';
                $messageType = 'success';
                
                // Update session data
                if (isset($_SESSION['single_result'])) {
                    $_SESSION['single_result']['calc_id'] = $calc_id;
                    $_SESSION['single_result']['is_paid'] = $is_paid;
                    $_SESSION['single_result']['payment_date'] = $payment_date;
                }
                
                // Log activity
                $logSql = "INSERT INTO activity_logs (user_id, activity_type, description, related_id) 
                          VALUES (?, 'allowance_calculated', ?, ?)";
                $logStmt = $db->prepare($logSql);
                $logDesc = "Calculated allowance for user #$user_id - month: $month_year";
                $logStmt->bind_param("isi", $user['user_id'], $logDesc, $calc_id);
                $logStmt->execute();
            } else {
                $message = '❌ Error: ' . $insertStmt->error;
                $messageType = 'error';
            }
        }
        $active_section = 'result';
    }
    
    // 4. UPDATE ATTENDANCE PAYMENT STATUS
    elseif (isset($_POST['update_attendance_payment'])) {
        $attendance_id = intval($_POST['attendance_id']);
        $payment_status = $_POST['payment_status'];
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_notes = $_POST['payment_notes'] ?? '';
        
        $updateSql = "UPDATE attendance SET 
            payment_status = ?,
            payment_date = ?,
            payment_notes = ?
            WHERE attendance_id = ?";
        
        $stmt = $db->prepare($updateSql);
        $stmt->bind_param("sssi", $payment_status, $payment_date, $payment_notes, $attendance_id);
        
        if ($stmt->execute()) {
            $message = '✅ Payment status updated successfully!';
            $messageType = 'success';
        } else {
            $message = '❌ Error: ' . $stmt->error;
            $messageType = 'error';
        }
        $active_section = 'payments';
    }
    
    // 5. EXPORT BULK RESULTS
    elseif (isset($_POST['export_bulk'])) {
        if (isset($_SESSION['bulk_results']) && !empty($_SESSION['bulk_results'])) {
            exportBulkResultsToCSV($_SESSION['bulk_results'], $db);
            exit;
        } else {
            $message = '❌ No data to export';
            $messageType = 'error';
        }
    }
}

// ================= EXPORT FUNCTION =================
function exportBulkResultsToCSV($results, $db) {
    if (empty($results)) return;
    
    $filename = 'allowance_bulk_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    
    fputcsv($output, [
        'No', 'Cadet Name', 'Military Number', 'Service', 'Rank',
        'Attendance Days', 'Total Sessions', 'Attendance %',
        'Local Training (RM)', 'Continuous Training (RM)', 'Camp Training (RM)', 'Accreditation Training (RM)',
        'Bounty (RM)', 'Clothing Allowance (RM)',
        'Training Total (RM)', 'Additional Total (RM)', 'GRAND TOTAL (RM)'
    ]);
    
    $counter = 1;
    $total_training = 0;
    $total_additional = 0;
    $total_all = 0;
    
    foreach ($results as $result) {
        $training_local = 0;
        $training_continuous = 0;
        $training_camp = 0;
        $training_accreditation = 0;
        
        foreach ($result['training_breakdown'] as $item) {
            if ($item['type'] == 'Local Training/Wednesday Parade') $training_local = $item['amount'];
            elseif ($item['type'] == 'Continuous Training') $training_continuous = $item['amount'];
            elseif ($item['type'] == 'Annual Camp Training') $training_camp = $item['amount'];
            elseif ($item['type'] == 'Accreditation Training') $training_accreditation = $item['amount'];
        }
        
        $bounty_amount = 0;
        $clothing_amount = 0;
        
        foreach ($result['additional_breakdown'] as $item) {
            if (strpos($item['type'], 'Bounty') !== false) $bounty_amount = $item['amount'];
            elseif (strpos($item['type'], 'Clothing') !== false) $clothing_amount = $item['amount'];
        }
        
        fputcsv($output, [
            $counter,
            $result['cadet']['name'],
            $result['cadet']['military_number'],
            strtoupper($result['cadet']['service_type']),
            ucfirst($result['cadet']['rank_level']),
            $result['attendance_days'],
            $result['total_sessions'],
            $result['attendance_rate'],
            number_format($training_local, 2),
            number_format($training_continuous, 2),
            number_format($training_camp, 2),
            number_format($training_accreditation, 2),
            number_format($bounty_amount, 2),
            number_format($clothing_amount, 2),
            number_format($result['training_allowance'], 2),
            number_format($result['additional_allowance'], 2),
            number_format($result['total_amount'], 2)
        ]);
        
        $total_training += $result['training_allowance'];
        $total_additional += $result['additional_allowance'];
        $total_all += $result['total_amount'];
        $counter++;
    }
    
    fputcsv($output, ['']);
    fputcsv($output, ['TOTAL', '', '', '', '', '', '', '', '', '', '', '', '', '', 
        number_format($total_training, 2), 
        number_format($total_additional, 2), 
        number_format($total_all, 2)]);
    
    fclose($output);
    exit;
}

// ================= HELPER FUNCTIONS =================
function getCadetAttendance($db, $user_id, $month_year) {
    $month = date('m', strtotime($month_year));
    $year = date('Y', strtotime($month_year));
    
    $sql = "SELECT DISTINCT DATE(a.date) as attendance_date, 
            ts.training_type, ts.session_id, a.status,
            a.payment_status, a.payment_date, a.attendance_id
            FROM attendance a 
            JOIN training_sessions ts ON a.session_id = ts.session_id 
            WHERE a.user_id = ? 
            AND MONTH(a.date) = ? 
            AND YEAR(a.date) = ? 
            AND a.status = 'present'
            ORDER BY a.date";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iii", $user_id, $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $attendance = [];
    while ($row = $result->fetch_assoc()) {
        $attendance[] = $row;
    }
    
    return $attendance;
}

function calculateCadetAllowance($cadet, $month_year, $rates) {
    global $db;
    
    $attendance = getCadetAttendance($db, $cadet['user_id'], $month_year);
    $total_days = count($attendance);
    
    $month = date('m', strtotime($month_year));
    $year = date('Y', strtotime($month_year));
    
    // Count total sessions for the month
    $sessions_sql = "SELECT COUNT(DISTINCT training_date) as total_sessions 
                    FROM training_sessions 
                    WHERE MONTH(training_date) = ? AND YEAR(training_date) = ?";
    $sessions_stmt = $db->prepare($sessions_sql);
    $sessions_stmt->bind_param("ii", $month, $year);
    $sessions_stmt->execute();
    $sessions_result = $sessions_stmt->get_result();
    $total_sessions = $sessions_result->fetch_assoc()['total_sessions'] ?? 0;
    
    $attendance_rate = ($total_sessions > 0) ? ($total_days / $total_sessions) * 100 : 0;
    
    // Categorize attendance by training type
    $training_days = [
        'local' => 0,
        'continuous' => 0,
        'camp' => 0,
        'accreditation' => 0
    ];
    
    $attendance_details = [
        'local' => [],
        'continuous' => [],
        'camp' => [],
        'accreditation' => []
    ];
    
    foreach ($attendance as $record) {
        $training_type = strtolower($record['training_type']);
        $date = date('d/m/Y', strtotime($record['attendance_date']));
        
        if (strpos($training_type, 'tempatan') !== false || strpos($training_type, 'baris') !== false || strpos($training_type, 'local') !== false) {
            $training_days['local']++;
            $attendance_details['local'][] = [
                'date' => $date,
                'session_name' => $record['training_type'],
                'type' => 'Local Training/Wednesday Parade',
                'payment_status' => $record['payment_status'] ?? 'pending',
                'attendance_id' => $record['attendance_id']
            ];
        } 
        elseif (strpos($training_type, 'kem') !== false || strpos($training_type, 'camp') !== false) {
            $training_days['camp']++;
            $attendance_details['camp'][] = [
                'date' => $date,
                'session_name' => $record['training_type'],
                'type' => 'Annual Camp Training',
                'payment_status' => $record['payment_status'] ?? 'pending',
                'attendance_id' => $record['attendance_id']
            ];
        }
        elseif (strpos($training_type, 'pentauliahan') !== false || strpos($training_type, 'accreditation') !== false) {
            $training_days['accreditation']++;
            $attendance_details['accreditation'][] = [
                'date' => $date,
                'session_name' => $record['training_type'],
                'type' => 'Accreditation Training',
                'payment_status' => $record['payment_status'] ?? 'pending',
                'attendance_id' => $record['attendance_id']
            ];
        }
        else {
            $training_days['continuous']++;
            $attendance_details['continuous'][] = [
                'date' => $date,
                'session_name' => $record['training_type'],
                'type' => 'Continuous Training',
                'payment_status' => $record['payment_status'] ?? 'pending',
                'attendance_id' => $record['attendance_id']
            ];
        }
    }
    
    $rank = $cadet['rank_level'];
    $training_allowance = 0;
    $allowance_breakdown = [];
    
    // 1. Local Training/Wednesday Parade
    if ($training_days['local'] > 0) {
        $local_amount = $training_days['local'] * $rates['local_per_day'];
        $training_allowance += $local_amount;
        $allowance_breakdown[] = [
            'type' => 'Local Training/Wednesday Parade',
            'days' => $training_days['local'],
            'rate' => $rates['local_per_day'],
            'amount' => $local_amount
        ];
    }
    
    // 2. Continuous Training
    if ($training_days['continuous'] > 0) {
        $rate_key = 'continuous_' . $rank;
        $daily_rate = $rates[$rate_key] ?? 0;
        $continuous_amount = $training_days['continuous'] * $daily_rate;
        $training_allowance += $continuous_amount;
        $allowance_breakdown[] = [
            'type' => 'Continuous Training',
            'days' => $training_days['continuous'],
            'rate' => $daily_rate,
            'amount' => $continuous_amount
        ];
    }
    
    // 3. Annual Camp Training
    if ($training_days['camp'] > 0) {
        $rate_key = 'camp_' . $rank;
        $daily_rate = $rates[$rate_key] ?? 0;
        $camp_amount = $training_days['camp'] * $daily_rate;
        $training_allowance += $camp_amount;
        $allowance_breakdown[] = [
            'type' => 'Annual Camp Training',
            'days' => $training_days['camp'],
            'rate' => $daily_rate,
            'amount' => $camp_amount
        ];
    }
    
    // 4. Accreditation Training (Senior only)
    if ($training_days['accreditation'] > 0 && $rank == 'senior') {
        $accreditation_amount = $training_days['accreditation'] * $rates['accreditation_per_day'];
        $training_allowance += $accreditation_amount;
        $allowance_breakdown[] = [
            'type' => 'Accreditation Training',
            'days' => $training_days['accreditation'],
            'rate' => $rates['accreditation_per_day'],
            'amount' => $accreditation_amount
        ];
    }
    
    // Calculate additional allowances
    $additional_allowance = 0;
    $additional_breakdown = [];
    
    // Bounty for all
    $additional_allowance += $rates['bounty_all'];
    $additional_breakdown[] = [
        'type' => 'Bounty (monthly)',
        'amount' => $rates['bounty_all']
    ];
    
    // Clothing for senior only
    if ($rank == 'senior') {
        $additional_allowance += $rates['clothing_senior'];
        $additional_breakdown[] = [
            'type' => 'Clothing Allowance (monthly)',
            'amount' => $rates['clothing_senior']
        ];
    }
    
    $total_amount = $training_allowance + $additional_allowance;
    
    // Check payment status from database
    $payment_sql = "SELECT calc_id, is_paid, payment_date FROM allowance_calculations 
                   WHERE user_id = ? AND month_year = ?";
    $payment_stmt = $db->prepare($payment_sql);
    $payment_stmt->bind_param("is", $cadet['user_id'], $month_year);
    $payment_stmt->execute();
    $payment_result = $payment_stmt->get_result();
    $payment_data = $payment_result->fetch_assoc();
    
    return [
        'cadet' => $cadet,
        'month_year' => $month_year,
        'attendance_days' => $total_days,
        'total_sessions' => $total_sessions,
        'attendance_rate' => round($attendance_rate, 2),
        'training_days' => $training_days,
        'attendance_details' => $attendance_details,
        'training_allowance' => round($training_allowance, 2),
        'training_breakdown' => $allowance_breakdown,
        'additional_allowance' => round($additional_allowance, 2),
        'additional_breakdown' => $additional_breakdown,
        'total_amount' => round($total_amount, 2),
        'calc_id' => $payment_data['calc_id'] ?? 0,
        'is_paid' => $payment_data['is_paid'] ?? 0,
        'payment_date' => $payment_data['payment_date'] ?? NULL
    ];
}

// ================= GET DATA FOR DISPLAY =================
// Get all cadets
$cadetsSql = "SELECT user_id, military_number, name, service_type, rank_level 
             FROM users WHERE role = 'cadet' ORDER BY service_type, rank_level, name";
$cadetsResult = $db->query($cadetsSql);
$allCadets = [];
$totalCadets = 0;
while ($row = $cadetsResult->fetch_assoc()) {
    $allCadets[] = $row;
    $totalCadets++;
}

// Get distinct months from training sessions
$monthsSql = "SELECT DISTINCT DATE_FORMAT(training_date, '%Y-%m') as month_year 
             FROM training_sessions 
             ORDER BY month_year DESC";
$monthsResult = $db->query($monthsSql);
$months = [];
while ($row = $monthsResult->fetch_assoc()) {
    $months[] = $row['month_year'];
}

// Get attendance data for payment status page
$payment_month = $_GET['payment_month'] ?? date('Y-m');
$payment_cadet = $_GET['cadet'] ?? 'all';
$payment_type = $_GET['payment_type'] ?? 'all';
$payment_status_filter = $_GET['payment_status'] ?? 'all';

// Query for attendance with payment status
$attendancePaymentSql = "SELECT 
    a.attendance_id,
    a.user_id,
    a.date,
    a.status,
    a.payment_status,
    a.payment_date,
    a.payment_notes,
    ts.training_type,
    ts.location,
    ts.session_time,
    u.name,
    u.military_number,
    u.service_type,
    u.rank_level
FROM attendance a
JOIN training_sessions ts ON a.session_id = ts.session_id
JOIN users u ON a.user_id = u.user_id
WHERE a.status = 'present'
AND DATE_FORMAT(a.date, '%Y-%m') = ?";

$params = [$payment_month];
$types = "s";

if ($payment_cadet != 'all') {
    $attendancePaymentSql .= " AND a.user_id = ?";
    $params[] = $payment_cadet;
    $types .= "i";
}

if ($payment_type != 'all') {
    $attendancePaymentSql .= " AND ts.training_type LIKE ?";
    $params[] = '%' . $payment_type . '%';
    $types .= "s";
}

if ($payment_status_filter != 'all') {
    $attendancePaymentSql .= " AND a.payment_status = ?";
    $params[] = $payment_status_filter;
    $types .= "s";
}

$attendancePaymentSql .= " ORDER BY a.date DESC, u.name";

$attendanceStmt = $db->prepare($attendancePaymentSql);
if (!empty($params)) {
    $attendanceStmt->bind_param($types, ...$params);
}
$attendanceStmt->execute();
$attendanceResult = $attendanceStmt->get_result();

$attendanceRecords = [];
$total_paid_attendance = 0;
$total_pending_attendance = 0;
$total_processing_attendance = 0;

while ($row = $attendanceResult->fetch_assoc()) {
    $attendanceRecords[] = $row;
    
    if ($row['payment_status'] == 'paid') {
        $total_paid_attendance++;
    } elseif ($row['payment_status'] == 'pending') {
        $total_pending_attendance++;
    } else {
        $total_processing_attendance++;
    }
}

// Get unique training types for filter
$trainingTypesSql = "SELECT DISTINCT training_type FROM training_sessions ORDER BY training_type";
$trainingTypesResult = $db->query($trainingTypesSql);
$trainingTypes = [];
while ($row = $trainingTypesResult->fetch_assoc()) {
    $trainingTypes[] = $row['training_type'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Allowance Management System - CAAMS</title>
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
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, #2d3748 100%);
            color: white;
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .header-left h1 {
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.8rem;
        }
        
        .back-btn {
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            transition: all 0.3s;
            margin-bottom: 10px;
        }
        
        .back-btn:hover {
            background: rgba(255,255,255,0.2);
            transform: translateX(-5px);
        }
        
        /* NAVIGATION */
        .nav-container {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .nav-tabs {
            display: flex;
            list-style: none;
            padding: 0;
            margin: 0;
            overflow-x: auto;
            background: white;
        }
        
        .nav-tab {
            padding: 18px 30px;
            cursor: pointer;
            font-weight: 600;
            color: var(--secondary);
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.95rem;
        }
        
        .nav-tab:hover {
            background: #f7fafc;
            color: var(--primary);
        }
        
        .nav-tab.active {
            color: var(--primary);
            border-bottom: 3px solid var(--accent);
            background: #f0f9ff;
        }
        
        .section-content {
            display: none;
            padding: 30px;
            animation: fadeIn 0.3s ease-out;
        }
        
        .section-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* ALERT */
        .alert {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.3s ease-out;
        }
        
        .alert.success { background: #d4edda; color: #155724; border-left: 5px solid var(--success); }
        .alert.error { background: #f8d7da; color: #721c24; border-left: 5px solid var(--danger); }
        .alert.info { background: #d1ecf1; color: #0c5460; border-left: 5px solid var(--info); }
        
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* FORM STYLES */
        .form-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }
        
        .section-title {
            color: var(--primary);
            font-size: 1.5rem;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f4f8;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
            font-size: 0.95rem;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
            background: white;
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--accent) 0%, #2c5282 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(49, 130, 206, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #38a169 100%);
            color: white;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, var(--warning) 0%, #dd6b20 100%);
            color: white;
        }
        
        .btn-info {
            background: linear-gradient(135deg, var(--info) 0%, #2c5282 100%);
            color: white;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 25px;
            flex-wrap: wrap;
        }
        
        /* RATE CARDS */
        .rate-card {
            background: #f8fafc;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .rate-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .rate-card h3 {
            color: var(--primary);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .rate-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding: 8px 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        
        .rate-row:last-child {
            border-bottom: none;
        }
        
        .rate-label {
            color: var(--secondary);
        }
        
        .rate-value {
            font-weight: 600;
            color: var(--primary);
        }
        
        /* CALCULATION TYPE TOGGLE */
        .calc-type-toggle {
            display: flex;
            background: #f8fafc;
            border-radius: 8px;
            padding: 5px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }
        
        .calc-type-btn {
            flex: 1;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .calc-type-btn.active {
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            color: var(--primary);
        }
        
        /* TABLE STYLES */
        .table-container {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            margin-top: 20px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        
        .data-table th {
            background: #f8fafc;
            color: var(--primary);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.9rem;
            white-space: nowrap;
        }
        
        .data-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        
        .data-table tr:hover {
            background: #f7fafc;
        }
        
        .amount {
            font-weight: bold;
            color: var(--success);
        }
        
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-paid {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-processing {
            background: #cce5ff;
            color: #004085;
        }
        
        .service-badge {
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .service-darat { background: #fed7d7; color: #c53030; }
        .service-laut { background: #bee3f8; color: #2c5282; }
        .service-udara { background: #c6f6d5; color: #276749; }
        
        /* FILTER BAR */
        .filter-bar {
            background: #f8fafc;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-group label {
            margin-bottom: 0;
            white-space: nowrap;
        }
        
        /* PAYMENT STATUS */
        .payment-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .status-dot.paid { background: var(--success); }
        .status-dot.pending { background: var(--warning); }
        .status-dot.processing { background: var(--info); }
        
        /* PAYMENT STATUS BADGE IN TABLE */
        .payment-status-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .payment-status-paid {
            background: #d4edda;
            color: #155724;
        }
        
        .payment-status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .payment-status-processing {
            background: #cce5ff;
            color: #004085;
        }
        
        /* ATTENDANCE PAYMENT MODAL */
        .payment-modal {
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
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            background: var(--primary);
            color: white;
            padding: 20px;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 15px 20px;
            background: #f8fafc;
            border-radius: 0 0 10px 10px;
            text-align: right;
        }
        
        /* CALCULATION DETAILS */
        .calculation-details {
            background: #f8fafc;
            border-radius: 10px;
            padding: 25px;
            margin: 25px 0;
        }
        
        .detail-section {
            margin-bottom: 20px;
        }
        
        .detail-section h4 {
            color: var(--primary);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        
        /* QUICK ACTION BUTTONS */
        .quick-action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .quick-btn {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .quick-btn.paid {
            background: #d4edda;
            color: #155724;
        }
        
        .quick-btn.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .quick-btn.processing {
            background: #cce5ff;
            color: #004085;
        }
        
        .quick-btn:hover {
            opacity: 0.8;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .nav-tabs {
                flex-wrap: wrap;
            }
            
            .nav-tab {
                flex: 1;
                text-align: center;
                justify-content: center;
                padding: 15px;
                font-size: 0.85rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                font-size: 0.9rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .quick-action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <div class="header-left">
                <a href="dashboard.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h1><i class="fas fa-calculator"></i> Cadet Allowance Management System</h1>
                <p style="opacity: 0.9;">Manage Allowances & Payment Status</p>
            </div>
            <div style="text-align: right;">
                <small style="opacity: 0.7;">Total Cadets: <?php echo $totalCadets; ?></small><br>
                <small style="opacity: 0.7;"><?php echo date('F Y'); ?></small>
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
        
        <!-- NAVIGATION TABS - WITH BULK RESULT SECTION -->
        <div class="nav-container">
            <ul class="nav-tabs">
                <li class="nav-tab <?php echo $active_section == 'rates' ? 'active' : ''; ?>" onclick="showSection('rates')">
                    <i class="fas fa-cog"></i> Set Rates
                </li>
                <li class="nav-tab <?php echo $active_section == 'calculate' ? 'active' : ''; ?>" onclick="showSection('calculate')">
                    <i class="fas fa-calculator"></i> Calculate Allowance
                </li>
                <li class="nav-tab <?php echo $active_section == 'result' ? 'active' : ''; ?>" onclick="showSection('result')">
                    <i class="fas fa-chart-bar"></i> Calculation Result
                </li>
                <li class="nav-tab <?php echo $active_section == 'bulk_result' ? 'active' : ''; ?>" onclick="showSection('bulk_result')">
                    <i class="fas fa-users"></i> Bulk Results
                </li>
                <li class="nav-tab <?php echo $active_section == 'payments' ? 'active' : ''; ?>" onclick="showSection('payments')">
                    <i class="fas fa-credit-card"></i> Payment Status
                </li>
            </ul>
        </div>
        
        <!-- CONTENT AREA -->
        <div class="content-area">
            
            <!-- SECTION 1: SET RATES -->
            <div id="section-rates" class="section-content <?php echo $active_section == 'rates' ? 'active' : ''; ?>">
                <div class="form-section">
                    <h2 class="section-title"><i class="fas fa-cog"></i> Set Allowance Rates</h2>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="update_rates" value="1">
                        
                        <div class="form-grid">
                            <!-- LOCAL TRAINING/WEDNESDAY PARADE -->
                            <div class="rate-card">
                                <h3><i class="fas fa-running"></i> Local Training/Wednesday Parade</h3>
                                <div class="rate-row">
                                    <span class="rate-label">Rate per Day (RM)</span>
                                    <input type="number" name="rate_local_per_day" 
                                           value="<?php echo $rates['local_per_day']; ?>" 
                                           step="0.01" min="0" style="width: 120px; text-align: right;">
                                </div>
                            </div>
                            
                            <!-- CONTINUOUS TRAINING -->
                            <div class="rate-card">
                                <h3><i class="fas fa-calendar-alt"></i> Continuous Training (RM/day)</h3>
                                <div class="rate-row">
                                    <span class="rate-label">Junior</span>
                                    <input type="number" name="rate_continuous_junior" 
                                           value="<?php echo $rates['continuous_junior']; ?>" 
                                           step="0.01" min="0" style="width: 120px; text-align: right;">
                                </div>
                                <div class="rate-row">
                                    <span class="rate-label">Intermediate</span>
                                    <input type="number" name="rate_continuous_intermediate" 
                                           value="<?php echo $rates['continuous_intermediate']; ?>" 
                                           step="0.01" min="0" style="width: 120px; text-align: right;">
                                </div>
                                <div class="rate-row">
                                    <span class="rate-label">Senior</span>
                                    <input type="number" name="rate_continuous_senior" 
                                           value="<?php echo $rates['continuous_senior']; ?>" 
                                           step="0.01" min="0" style="width: 120px; text-align: right;">
                                </div>
                            </div>
                            
                            <!-- ANNUAL CAMP TRAINING -->
                            <div class="rate-card">
                                <h3><i class="fas fa-campground"></i> Annual Camp Training (RM/day)</h3>
                                <div class="rate-row">
                                    <span class="rate-label">Junior</span>
                                    <input type="number" name="rate_camp_junior" 
                                           value="<?php echo $rates['camp_junior']; ?>" 
                                           step="0.01" min="0" style="width: 120px; text-align: right;">
                                </div>
                                <div class="rate-row">
                                    <span class="rate-label">Intermediate</span>
                                    <input type="number" name="rate_camp_intermediate" 
                                           value="<?php echo $rates['camp_intermediate']; ?>" 
                                           step="0.01" min="0" style="width: 120px; text-align: right;">
                                </div>
                                <div class="rate-row">
                                    <span class="rate-label">Senior</span>
                                    <input type="number" name="rate_camp_senior" 
                                           value="<?php echo $rates['camp_senior']; ?>" 
                                           step="0.01" min="0" style="width: 120px; text-align: right;">
                                </div>
                            </div>
                            
                            <!-- ACCREDITATION TRAINING -->
                            <div class="rate-card">
                                <h3><i class="fas fa-user-graduate"></i> Accreditation Training</h3>
                                <div class="rate-row">
                                    <span class="rate-label">Total 14 days (RM)</span>
                                    <input type="number" name="rate_accreditation_total" 
                                           value="<?php echo ($rates['accreditation_per_day'] * 14); ?>" 
                                           step="0.01" min="0" style="width: 120px; text-align: right;">
                                </div>
                            </div>
                            
                            <!-- ADDITIONAL ALLOWANCES -->
                            <div class="rate-card">
                                <h3><i class="fas fa-gift"></i> Additional Allowances (Monthly)</h3>
                                <div class="rate-row">
                                    <span class="rate-label">Clothing (Senior) (RM)</span>
                                    <input type="number" name="rate_clothing_senior" 
                                           value="<?php echo $rates['clothing_senior']; ?>" 
                                           step="0.01" min="0" style="width: 120px; text-align: right;">
                                </div>
                                <div class="rate-row">
                                    <span class="rate-label">Bounty (All) (RM)</span>
                                    <input type="number" name="rate_bounty_all" 
                                           value="<?php echo $rates['bounty_all']; ?>" 
                                           step="0.01" min="0" style="width: 120px; text-align: right;">
                                </div>
                            </div>
                        </div>
                        
                        <div class="action-buttons">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save"></i> Save Rates
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- SECTION 2: CALCULATE ALLOWANCE -->
            <div id="section-calculate" class="section-content <?php echo $active_section == 'calculate' ? 'active' : ''; ?>">
                <div class="form-section">
                    <h2 class="section-title"><i class="fas fa-calculator"></i> Calculate Cadet Allowance</h2>
                    
                    <!-- CALCULATION TYPE TOGGLE -->
                    <div class="calc-type-toggle">
                        <div class="calc-type-btn active" onclick="showSingleForm()">
                            <i class="fas fa-user"></i> Single Calculation
                        </div>
                        <div class="calc-type-btn" onclick="showBulkForm()">
                            <i class="fas fa-users"></i> Bulk Calculation
                        </div>
                    </div>
                    
                    <!-- SINGLE CALCULATION FORM -->
                    <form id="singleForm" method="POST" action="">
                        <input type="hidden" name="calculate_allowance" value="1">
                        <input type="hidden" name="calculation_type" value="single">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="month_year"><i class="fas fa-calendar"></i> Select Month & Year</label>
                                <select id="month_year" name="month_year" required>
                                    <option value="">-- Select Month --</option>
                                    <?php foreach ($months as $month): ?>
                                        <option value="<?php echo $month; ?>" 
                                            <?php echo (isset($_POST['month_year']) && $_POST['month_year'] == $month) ? 'selected' : ''; ?>>
                                            <?php echo date('F Y', strtotime($month . '-01')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if (empty($months)): ?>
                                        <option value="<?php echo date('Y-m'); ?>">
                                            <?php echo date('F Y'); ?> (Current)
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="cadet_id"><i class="fas fa-user"></i> Select Cadet</label>
                                <select id="cadet_id" name="cadet_id" required>
                                    <option value="">-- Select Cadet --</option>
                                    <?php foreach ($allCadets as $cadet): ?>
                                        <option value="<?php echo $cadet['user_id']; ?>" 
                                            <?php echo (isset($_POST['cadet_id']) && $_POST['cadet_id'] == $cadet['user_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cadet['name']); ?> 
                                            (<?php echo strtoupper($cadet['service_type']); ?> - <?php echo ucfirst($cadet['rank_level']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="action-buttons">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-calculator"></i> Calculate Allowance
                            </button>
                        </div>
                    </form>
                    
                    <!-- BULK CALCULATION FORM -->
                    <form id="bulkForm" method="POST" action="" style="display: none;">
                        <input type="hidden" name="calculate_allowance" value="1">
                        <input type="hidden" name="calculation_type" value="bulk">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="bulk_month_year"><i class="fas fa-calendar"></i> Select Month & Year</label>
                                <select id="bulk_month_year" name="month_year" required>
                                    <option value="">-- Select Month --</option>
                                    <?php foreach ($months as $month): ?>
                                        <option value="<?php echo $month; ?>" 
                                            <?php echo (isset($_POST['month_year']) && $_POST['month_year'] == $month) ? 'selected' : ''; ?>>
                                            <?php echo date('F Y', strtotime($month . '-01')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="service_filter"><i class="fas fa-shield-alt"></i> Filter Service</label>
                                <select id="service_filter" name="service_filter">
                                    <option value="all">All Services</option>
                                    <option value="darat">Army</option>
                                    <option value="laut">Navy</option>
                                    <option value="udara">Air Force</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="rank_filter"><i class="fas fa-star"></i> Filter Rank</label>
                                <select id="rank_filter" name="rank_filter">
                                    <option value="all">All Ranks</option>
                                    <option value="junior">Junior</option>
                                    <option value="intermediate">Intermediate</option>
                                    <option value="senior">Senior</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="action-buttons">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-users"></i> Calculate Bulk Allowance
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- SECTION 3: SINGLE RESULT -->
            <div id="section-result" class="section-content <?php echo $active_section == 'result' ? 'active' : ''; ?>">
                <?php if (isset($_SESSION['single_result'])): 
                    $result = $_SESSION['single_result']; 
                ?>
                <div class="form-section">
                    <h2 class="section-title"><i class="fas fa-chart-bar"></i> Calculation Result - <?php echo htmlspecialchars($result['cadet']['name']); ?></h2>
                    
                    <div class="calculation-details">
                        <!-- CADET INFO -->
                        <div class="detail-section">
                            <h4><i class="fas fa-user"></i> Cadet Information</h4>
                            <div class="detail-row">
                                <span>Name:</span>
                                <span><strong><?php echo htmlspecialchars($result['cadet']['name']); ?></strong></span>
                            </div>
                            <div class="detail-row">
                                <span>Military Number:</span>
                                <span><?php echo $result['cadet']['military_number']; ?></span>
                            </div>
                            <div class="detail-row">
                                <span>Service:</span>
                                <span><span class="service-badge service-<?php echo $result['cadet']['service_type']; ?>">
                                    <?php echo strtoupper($result['cadet']['service_type']); ?>
                                </span></span>
                            </div>
                            <div class="detail-row">
                                <span>Rank:</span>
                                <span><strong><?php echo ucfirst($result['cadet']['rank_level']); ?></strong></span>
                            </div>
                            <div class="detail-row">
                                <span>Month:</span>
                                <span><strong><?php echo date('F Y', strtotime($result['month_year'] . '-01')); ?></strong></span>
                            </div>
                        </div>
                        
                        <!-- ATTENDANCE SUMMARY -->
                        <div class="detail-section">
                            <h4><i class="fas fa-calendar-check"></i> Attendance Summary</h4>
                            <div class="detail-row">
                                <span>Attendance Days:</span>
                                <span><strong><?php echo $result['attendance_days']; ?> days</strong></span>
                            </div>
                            <div class="detail-row">
                                <span>Total Sessions This Month:</span>
                                <span><?php echo $result['total_sessions']; ?> sessions</span>
                            </div>
                            <div class="detail-row">
                                <span>Attendance Rate:</span>
                                <span><strong style="color: <?php echo $result['attendance_rate'] >= 80 ? 'var(--success)' : 'var(--warning)'; ?>">
                                    <?php echo $result['attendance_rate']; ?>%
                                </strong></span>
                            </div>
                        </div>
                        
                        <!-- ATTENDANCE DETAIL BY TYPE -->
                        <div class="detail-section">
                            <h4><i class="fas fa-calendar-alt"></i> Attendance Details</h4>
                            
                            <?php 
                            $type_names = [
                                'local' => 'Local Training/Wednesday Parade',
                                'continuous' => 'Continuous Training',
                                'camp' => 'Annual Camp Training',
                                'accreditation' => 'Accreditation Training'
                            ];
                            
                            foreach ($result['attendance_details'] as $type => $sessions): 
                                if (!empty($sessions)): 
                            ?>
                                <div style="background: white; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 10px; overflow: hidden;">
                                    <div style="background: #f8fafc; padding: 12px 15px; border-bottom: 1px solid #e2e8f0;">
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <span style="font-weight: 600; color: var(--primary);"><?php echo $type_names[$type] ?? ucfirst($type); ?></span>
                                            <span class="badge" style="background: var(--info);">
                                                <?php echo count($sessions); ?> days
                                            </span>
                                        </div>
                                    </div>
                                    <div style="padding: 10px 15px;">
                                        <?php foreach ($sessions as $session): ?>
                                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px dashed #f0f4f8;">
                                                <span style="color: var(--secondary);"><?php echo $session['date']; ?></span>
                                                <span style="font-weight: 500;"><?php echo $session['session_name']; ?></span>
                                                <span class="payment-status-badge payment-status-<?php echo $session['payment_status']; ?>">
                                                    <?php echo ucfirst($session['payment_status']); ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </div>
                        
                        <!-- TRAINING ALLOWANCE BREAKDOWN -->
                        <div class="detail-section">
                            <h4><i class="fas fa-money-bill-wave"></i> Training Allowance Breakdown</h4>
                            
                            <?php foreach ($result['training_breakdown'] as $item): ?>
                                <div style="background: white; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 10px; padding: 15px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                        <span style="font-weight: 600; color: var(--primary);"><?php echo $item['type']; ?></span>
                                        <span class="badge" style="background: var(--success);">
                                            <?php echo $item['days']; ?> days
                                        </span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; color: var(--secondary);">
                                        <span><?php echo $item['days']; ?> days × RM <?php echo number_format($item['rate'], 2); ?></span>
                                        <span style="font-weight: bold; color: var(--success);">
                                            RM <?php echo number_format($item['amount'], 2); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($result['training_breakdown'])): ?>
                                <div style="text-align: center; padding: 20px; color: var(--secondary);">
                                    <i class="fas fa-calendar-times" style="font-size: 2rem; opacity: 0.3;"></i>
                                    <p>No attendance for this month</p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="detail-row" style="border-top: 2px solid var(--accent); font-weight: bold; margin-top: 15px;">
                                <span>Total Training Allowance:</span>
                                <span class="amount">RM <?php echo number_format($result['training_allowance'], 2); ?></span>
                            </div>
                        </div>
                        
                        <!-- ADDITIONAL ALLOWANCE -->
                        <div class="detail-section">
                            <h4><i class="fas fa-gift"></i> Additional Allowance</h4>
                            
                            <?php foreach ($result['additional_breakdown'] as $item): ?>
                                <div style="background: white; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 10px; padding: 15px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                        <span style="font-weight: 600; color: var(--primary);"><?php echo $item['type']; ?></span>
                                        <span class="badge" style="background: var(--warning);">
                                            Monthly
                                        </span>
                                    </div>
                                    <div style="text-align: right;">
                                        <span style="font-weight: bold; color: var(--success);">
                                            RM <?php echo number_format($item['amount'], 2); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <div class="detail-row" style="border-top: 2px solid var(--accent); font-weight: bold; margin-top: 15px;">
                                <span>Total Additional Allowance:</span>
                                <span class="amount">RM <?php echo number_format($result['additional_allowance'], 2); ?></span>
                            </div>
                        </div>
                        
                        <!-- GRAND TOTAL -->
                        <div class="detail-section" style="background: linear-gradient(135deg, #f0f9ff 0%, #e6fffa 100%); padding: 20px; border-radius: 10px;">
                            <h4><i class="fas fa-file-invoice-dollar"></i> GRAND TOTAL</h4>
                            <div class="detail-row" style="font-size: 1.3rem; font-weight: bold;">
                                <span>Total Allowance This Month:</span>
                                <span style="color: var(--success); font-size: 1.8rem;">
                                    RM <?php echo number_format($result['total_amount'], 2); ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- SAVE TO DATABASE FORM -->
                        <div class="detail-section" style="border: 2px solid var(--info); border-radius: 10px; padding: 20px;">
                            <h4><i class="fas fa-save"></i> Save to Database</h4>
                            <form method="POST" action="">
                                <input type="hidden" name="save_calculation" value="1">
                                <input type="hidden" name="user_id" value="<?php echo $result['cadet']['user_id']; ?>">
                                <input type="hidden" name="month_year" value="<?php echo $result['month_year']; ?>">
                                <input type="hidden" name="attendance_days" value="<?php echo $result['attendance_days']; ?>">
                                <input type="hidden" name="total_sessions" value="<?php echo $result['total_sessions']; ?>">
                                <input type="hidden" name="attendance_rate" value="<?php echo $result['attendance_rate']; ?>">
                                <input type="hidden" name="total_training" value="<?php echo $result['training_allowance']; ?>">
                                <input type="hidden" name="total_additional" value="<?php echo $result['additional_allowance']; ?>">
                                <input type="hidden" name="total_amount" value="<?php echo $result['total_amount']; ?>">
                                
                                <div style="display: flex; align-items: center; gap: 15px; margin: 15px 0;">
                                    <div style="flex: 1;">
                                        <label style="display: flex; align-items: center; gap: 10px;">
                                            <input type="checkbox" name="is_paid" value="1" <?php echo $result['is_paid'] ? 'checked' : ''; ?> 
                                                   style="width: auto;">
                                            <span>Mark as paid</span>
                                        </label>
                                        <?php if ($result['calc_id'] > 0): ?>
                                            <small style="color: var(--secondary); display: block; margin-top: 5px;">
                                                <i class="fas fa-info-circle"></i> Record ID: <?php echo $result['calc_id']; ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Save Record
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="?section=calculate" class="btn btn-primary">
                            <i class="fas fa-redo"></i> Calculate Another Allowance
                        </a>
                        <button type="button" class="btn btn-warning" onclick="window.print()">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <a href="?section=payments" class="btn btn-info">
                            <i class="fas fa-credit-card"></i> View Payment Status
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <div class="form-section">
                    <div style="text-align: center; padding: 50px 20px; color: var(--secondary);">
                        <i class="fas fa-chart-bar" style="font-size: 4rem; opacity: 0.3; margin-bottom: 20px;"></i>
                        <h3 style="margin-bottom: 10px;">No Calculation Results</h3>
                        <p>No allowance calculation to display.</p>
                        <a href="?section=calculate" class="btn btn-primary" style="margin-top: 20px;">
                            <i class="fas fa-calculator"></i> Go to Calculation
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- SECTION 4: BULK RESULT -->
            <div id="section-bulk_result" class="section-content <?php echo $active_section == 'bulk_result' ? 'active' : ''; ?>">
                <?php 
                if (isset($_SESSION['bulk_results']) && !empty($_SESSION['bulk_results'])) {
                    $results = $_SESSION['bulk_results'];
                    $has_results = true;
                } else {
                    $has_results = false;
                }
                ?>
                
                <?php if ($has_results): ?>
                <div class="form-section">
                    <h2 class="section-title"><i class="fas fa-users"></i> Bulk Allowance Calculation Results</h2>
                    
                    <!-- SUMMARY INFO -->
                    <div style="background: linear-gradient(135deg, #f0f9ff 0%, #e6fffa 100%); padding: 15px; border-radius: 10px; margin: 20px 0;">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <h4><i class="fas fa-info-circle"></i> Calculation Summary</h4>
                                <p style="margin: 5px 0;">
                                    Month: <strong><?php echo date('F Y', strtotime($_SESSION['calc_month'] . '-01')); ?></strong> | 
                                    Cadets: <strong><?php echo count($results); ?> persons</strong>
                                    <?php if ($_SESSION['filter_service'] != 'all'): ?>
                                        | Service: <strong><?php echo strtoupper($_SESSION['filter_service']); ?></strong>
                                    <?php endif; ?>
                                    <?php if ($_SESSION['filter_rank'] != 'all'): ?>
                                        | Rank: <strong><?php echo ucfirst($_SESSION['filter_rank']); ?></strong>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="export_bulk" value="1">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-file-excel"></i> Export CSV
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- RESULTS TABLE -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Cadet</th>
                                    <th>Military Number</th>
                                    <th>Service</th>
                                    <th>Rank</th>
                                    <th>Attendance Days</th>
                                    <th>Attendance %</th>
                                    <th>Training (RM)</th>
                                    <th>Additional (RM)</th>
                                    <th>Total (RM)</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $bulk_total_training = 0;
                                $bulk_total_additional = 0;
                                $bulk_total_all = 0;
                                ?>
                                <?php foreach ($results as $index => $result): 
                                    $bulk_total_training += $result['training_allowance'];
                                    $bulk_total_additional += $result['additional_allowance'];
                                    $bulk_total_all += $result['total_amount'];
                                ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($result['cadet']['name']); ?></strong>
                                        </td>
                                        <td><?php echo $result['cadet']['military_number']; ?></td>
                                        <td>
                                            <span class="service-badge service-<?php echo $result['cadet']['service_type']; ?>">
                                                <?php echo strtoupper($result['cadet']['service_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo ucfirst($result['cadet']['rank_level']); ?></td>
                                        <td><?php echo $result['attendance_days']; ?> days</td>
                                        <td><?php echo $result['attendance_rate']; ?>%</td>
                                        <td class="amount"><?php echo number_format($result['training_allowance'], 2); ?></td>
                                        <td class="amount"><?php echo number_format($result['additional_allowance'], 2); ?></td>
                                        <td class="amount"><strong><?php echo number_format($result['total_amount'], 2); ?></strong></td>
                                        <td>
                                            <!-- Button to view individual cadet details -->
                                            <a href="?section=result&cadet_id=<?php echo $result['cadet']['user_id']; ?>&month=<?php echo $result['month_year']; ?>" 
                                               class="btn" style="padding: 5px 10px; font-size: 0.8rem; background: var(--info); color: white;">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <!-- TOTALS ROW -->
                                <?php if (count($results) > 0): ?>
                                <tr style="background: #f0f9ff; font-weight: bold;">
                                    <td colspan="7" style="text-align: right; padding-right: 20px;">TOTAL:</td>
                                    <td class="amount">RM <?php echo number_format($bulk_total_training, 2); ?></td>
                                    <td class="amount">RM <?php echo number_format($bulk_total_additional, 2); ?></td>
                                    <td class="amount" style="color: var(--success); font-size: 1.1rem;">
                                        RM <?php echo number_format($bulk_total_all, 2); ?>
                                    </td>
                                    <td></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- EXPLANATION -->
                    <div style="margin-top: 30px; padding: 15px; background: #f8fafc; border-radius: 8px; border-left: 4px solid var(--info);">
                        <h4><i class="fas fa-info-circle"></i> Instructions:</h4>
                        <ul style="margin-left: 20px; color: var(--secondary);">
                            <li><strong>"View" button</strong> will show detailed calculation for individual cadet</li>
                            <li>In individual view, you can <strong>save to database</strong> and <strong>mark payment status</strong></li>
                            <li>Export CSV to get all data in spreadsheet format</li>
                            <li>Bulk data is for preview only, individual records need to be saved separately</li>
                        </ul>
                    </div>
                    
                    <div class="action-buttons">
                        <form method="POST" action="" style="display: inline;">
                            <input type="hidden" name="export_bulk" value="1">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Export to CSV
                            </button>
                        </form>
                        <a href="?section=calculate" class="btn btn-primary">
                            <i class="fas fa-redo"></i> Recalculate
                        </a>
                        <a href="?section=payments" class="btn btn-info">
                            <i class="fas fa-money-check-alt"></i> Go to Payment Status
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <div class="form-section">
                    <div style="text-align: center; padding: 50px 20px; color: var(--secondary);">
                        <i class="fas fa-users" style="font-size: 4rem; opacity: 0.3; margin-bottom: 20px;"></i>
                        <h3 style="margin-bottom: 10px;">No Bulk Calculation Results</h3>
                        <p>No bulk allowance calculation to display.</p>
                        <a href="?section=calculate" class="btn btn-primary">
                            <i class="fas fa-calculator"></i> Go to Calculation
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- SECTION 5: PAYMENT STATUS -->
            <div id="section-payments" class="section-content <?php echo $active_section == 'payments' ? 'active' : ''; ?>">
                <div class="form-section">
                    <h2 class="section-title"><i class="fas fa-credit-card"></i> Payment Status Based on Attendance</h2>
                    <p style="color: var(--secondary); margin-bottom: 20px;">
                        <i class="fas fa-calendar-check"></i> Manage payment status for each cadet's attendance session.
                        <strong>This status will be displayed in the cadet's mobile view.</strong>
                    </p>
                    
                    <!-- FILTER BAR -->
                    <div class="filter-bar">
                        <form method="GET" action="" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: center; width: 100%;">
                            <input type="hidden" name="section" value="payments">
                            
                            <div class="filter-group">
                                <label>Month:</label>
                                <select name="payment_month" onchange="this.form.submit()">
                                    <option value="">-- Select Month --</option>
                                    <?php foreach ($months as $month): ?>
                                        <option value="<?php echo $month; ?>" 
                                            <?php echo $payment_month == $month ? 'selected' : ''; ?>>
                                            <?php echo date('F Y', strtotime($month . '-01')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label>Cadet:</label>
                                <select name="cadet" onchange="this.form.submit()">
                                    <option value="all">All Cadets</option>
                                    <?php foreach ($allCadets as $cadet): ?>
                                        <option value="<?php echo $cadet['user_id']; ?>" 
                                            <?php echo $payment_cadet == $cadet['user_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cadet['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label>Training Type:</label>
                                <select name="payment_type" onchange="this.form.submit()">
                                    <option value="all">All Types</option>
                                    <?php foreach ($trainingTypes as $type): ?>
                                        <option value="<?php echo $type; ?>" 
                                            <?php echo $payment_type == $type ? 'selected' : ''; ?>>
                                            <?php echo $type; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label>Payment Status:</label>
                                <select name="payment_status" onchange="this.form.submit()">
                                    <option value="all">All Status</option>
                                    <option value="paid" <?php echo $payment_status_filter == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                    <option value="pending" <?php echo $payment_status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="processing" <?php echo $payment_status_filter == 'processing' ? 'selected' : ''; ?>>Processing</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <?php if (count($attendanceRecords) > 0): ?>
                        <!-- PAYMENT STATISTICS -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0;">
                            <div style="background: #d4edda; padding: 15px; border-radius: 8px; text-align: center;">
                                <div style="font-size: 2rem; color: #155724; font-weight: bold;"><?php echo $total_paid_attendance; ?></div>
                                <div style="color: #155724;">Paid</div>
                            </div>
                            <div style="background: #fff3cd; padding: 15px; border-radius: 8px; text-align: center;">
                                <div style="font-size: 2rem; color: #856404; font-weight: bold;"><?php echo $total_pending_attendance; ?></div>
                                <div style="color: #856404;">Pending</div>
                            </div>
                            <div style="background: #cce5ff; padding: 15px; border-radius: 8px; text-align: center;">
                                <div style="font-size: 2rem; color: #004085; font-weight: bold;"><?php echo $total_processing_attendance; ?></div>
                                <div style="color: #004085;">Processing</div>
                            </div>
                            <div style="background: #d1ecf1; padding: 15px; border-radius: 8px; text-align: center;">
                                <div style="font-size: 2rem; color: #0c5460; font-weight: bold;"><?php echo count($attendanceRecords); ?></div>
                                <div style="color: #0c5460;">Total Attendance</div>
                            </div>
                        </div>
                        
                        <!-- TABLE -->
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Cadet</th>
                                        <th>Date</th>
                                        <th>Training</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                        <th>Payment Status</th>
                                        <th>Payment Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attendanceRecords as $index => $attendance): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($attendance['name']); ?></strong><br>
                                                <small style="color: var(--secondary);">
                                                    <?php echo $attendance['military_number']; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php echo date('d/m/Y', strtotime($attendance['date'])); ?><br>
                                                <small style="color: var(--secondary);">
                                                    <?php echo ucfirst($attendance['session_time']); ?>
                                                </small>
                                            </td>
                                            <td><?php echo $attendance['training_type']; ?></td>
                                            <td><?php echo $attendance['location']; ?></td>
                                            <td>
                                                <span class="badge <?php echo $attendance['status'] == 'present' ? 'badge-paid' : 'badge-pending'; ?>">
                                                    <?php echo ucfirst($attendance['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="payment-status-badge payment-status-<?php echo $attendance['payment_status'] ?? 'pending'; ?>">
                                                    <?php echo ucfirst($attendance['payment_status'] ?? 'pending'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($attendance['payment_date'])): ?>
                                                    <?php echo date('d/m/Y', strtotime($attendance['payment_date'])); ?>
                                                <?php else: ?>
                                                    <span style="color: var(--secondary); font-style: italic;">Not paid</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="quick-action-buttons">
                                                    <button type="button" class="quick-btn paid" 
                                                            onclick="quickUpdatePayment(<?php echo $attendance['attendance_id']; ?>, 'paid')"
                                                            title="Mark as Paid">
                                                        <i class="fas fa-check"></i> Paid
                                                    </button>
                                                    <button type="button" class="quick-btn pending" 
                                                            onclick="quickUpdatePayment(<?php echo $attendance['attendance_id']; ?>, 'pending')"
                                                            title="Mark as Pending">
                                                        <i class="fas fa-clock"></i> Pending
                                                    </button>
                                                    <button type="button" class="quick-btn processing" 
                                                            onclick="quickUpdatePayment(<?php echo $attendance['attendance_id']; ?>, 'processing')"
                                                            title="Mark as Processing">
                                                        <i class="fas fa-sync"></i> Processing
                                                    </button>
                                                    <button type="button" class="btn" 
                                                            style="padding: 5px 10px; font-size: 0.8rem; background: var(--info); color: white;"
                                                            onclick="openAttendancePaymentModal(<?php echo $attendance['attendance_id']; ?>)">
                                                        <i class="fas fa-edit"></i> Details
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- IMPORTANT NOTES -->
                        <div style="margin-top: 30px; padding: 15px; background: #f0f9ff; border-radius: 8px; border-left: 4px solid var(--info);">
                            <h4><i class="fas fa-info-circle"></i> Important Notes:</h4>
                            <ul style="margin-left: 20px; color: var(--secondary);">
                                <li><strong>Payment status here will be displayed in the cadet's mobile view</strong></li>
                                <li>Cadets can view payment status for each of their attendance sessions</li>
                                <li>Use "Quick Update" to update status quickly</li>
                                <li>Use "Details" to add notes or specific payment dates</li>
                            </ul>
                        </div>
                        
                        <div class="action-buttons">
                            <button type="button" class="btn btn-success" onclick="exportAttendanceToExcel()">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                            <button type="button" class="btn btn-warning" onclick="window.print()">
                                <i class="fas fa-print"></i> Print Report
                            </button>
                            <a href="?section=calculate" class="btn btn-primary">
                                <i class="fas fa-calculator"></i> Go to Allowance Calculation
                            </a>
                        </div>
                        
                    <?php else: ?>
                        <div style="text-align: center; padding: 50px 20px; color: var(--secondary);">
                            <i class="fas fa-calendar-times" style="font-size: 4rem; opacity: 0.3; margin-bottom: 20px;"></i>
                            <h3 style="margin-bottom: 10px;">No Attendance Records</h3>
                            <p>No attendance records for the selected month.</p>
                            <div style="margin-top: 20px;">
                                <a href="?section=payments&payment_month=<?php echo date('Y-m'); ?>" class="btn btn-primary">
                                    <i class="fas fa-calendar"></i> View Current Month
                                </a>
                                <a href="dashboard.php?page=attendance" class="btn" style="background: #e2e8f0; color: var(--secondary);">
                                    <i class="fas fa-clipboard-check"></i> Go to Attendance Page
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
        </div>
    </div>
    
    <!-- ATTENDANCE PAYMENT MODAL -->
    <div id="attendancePaymentModal" class="payment-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="margin: 0;"><i class="fas fa-credit-card"></i> Update Payment Status</h3>
                <button type="button" onclick="closeAttendancePaymentModal()" style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="attendancePaymentForm" method="POST" action="">
                    <input type="hidden" name="update_attendance_payment" value="1">
                    <input type="hidden" name="attendance_id" id="modal_attendance_id">
                    
                    <div id="attendanceInfo" style="margin-bottom: 20px; padding: 15px; background: #f8fafc; border-radius: 8px;">
                        <!-- Attendance info will be loaded here -->
                    </div>
                    
                    <div class="form-group">
                        <label for="attendance_payment_status"><i class="fas fa-credit-card"></i> Payment Status</label>
                        <select id="attendance_payment_status" name="payment_status" required>
                            <option value="paid">Paid</option>
                            <option value="pending">Pending</option>
                            <option value="processing">Processing</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="attendance_payment_date"><i class="fas fa-calendar"></i> Payment Date</label>
                        <input type="date" id="attendance_payment_date" name="payment_date" 
                               value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="attendance_payment_notes"><i class="fas fa-sticky-note"></i> Notes</label>
                        <textarea id="attendance_payment_notes" name="payment_notes" rows="3" 
                                  placeholder="Example: Transaction No., Bank Name, etc."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeAttendancePaymentModal()" style="background: #e2e8f0; color: var(--secondary);">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="document.getElementById('attendancePaymentForm').submit()">
                    <i class="fas fa-check"></i> Save
                </button>
            </div>
        </div>
    </div>
    
    <script>
        // ================= SECTION SWITCHING =================
        function showSection(sectionName) {
            document.querySelectorAll('.section-content').forEach(section => {
                section.classList.remove('active');
            });
            document.querySelectorAll('.nav-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById('section-' + sectionName).classList.add('active');
            history.pushState(null, null, '?section=' + sectionName);
        }
        
        // ================= CALCULATION TYPE TOGGLE =================
        function showSingleForm() {
            document.getElementById('singleForm').style.display = 'block';
            document.getElementById('bulkForm').style.display = 'none';
            document.querySelectorAll('.calc-type-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelectorAll('.calc-type-btn')[0].classList.add('active');
        }
        
        function showBulkForm() {
            document.getElementById('singleForm').style.display = 'none';
            document.getElementById('bulkForm').style.display = 'block';
            document.querySelectorAll('.calc-type-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelectorAll('.calc-type-btn')[1].classList.add('active');
        }
        
        // ================= QUICK UPDATE PAYMENT =================
        function quickUpdatePayment(attendanceId, status) {
            if (confirm(`Mark payment status as "${status}"?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const input1 = document.createElement('input');
                input1.type = 'hidden';
                input1.name = 'update_attendance_payment';
                input1.value = '1';
                
                const input2 = document.createElement('input');
                input2.type = 'hidden';
                input2.name = 'attendance_id';
                input2.value = attendanceId;
                
                const input3 = document.createElement('input');
                input3.type = 'hidden';
                input3.name = 'payment_status';
                input3.value = status;
                
                form.appendChild(input1);
                form.appendChild(input2);
                form.appendChild(input3);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // ================= ATTENDANCE PAYMENT MODAL =================
        function openAttendancePaymentModal(attendanceId) {
            // Set attendance ID
            document.getElementById('modal_attendance_id').value = attendanceId;
            
            // Find row to get data from table
            const row = document.querySelector(`tr button[onclick*="${attendanceId}"]`).closest('tr');
            if (row) {
                const cells = row.querySelectorAll('td');
                const name = cells[1].querySelector('strong').textContent;
                const date = cells[2].firstChild.textContent.trim();
                const training = cells[3].textContent;
                const location = cells[4].textContent;
                const paymentStatusBadge = cells[6].querySelector('span');
                const paymentStatus = paymentStatusBadge ? paymentStatusBadge.textContent.toLowerCase() : 'pending';
                const paymentDate = cells[7].textContent.trim();
                
                const infoDiv = document.getElementById('attendanceInfo');
                infoDiv.innerHTML = `
                    <h4>Attendance Information</h4>
                    <p><strong>Name:</strong> ${name}</p>
                    <p><strong>Date:</strong> ${date}</p>
                    <p><strong>Training:</strong> ${training}</p>
                    <p><strong>Location:</strong> ${location}</p>
                    <p><strong>Current Payment Status:</strong> <span class="payment-status-badge payment-status-${paymentStatus}">${paymentStatus}</span></p>
                    ${paymentDate && paymentDate !== 'Not paid' ? `<p><strong>Last Payment Date:</strong> ${paymentDate}</p>` : ''}
                `;
                
                document.getElementById('attendance_payment_status').value = paymentStatus;
                
                // If there's a payment date in the table, use that
                let defaultDate = '<?php echo date("Y-m-d"); ?>';
                if (paymentDate && paymentDate !== 'Not paid') {
                    // Convert date from dd/mm/yyyy to yyyy-mm-dd
                    const parts = paymentDate.split('/');
                    if (parts.length === 3) {
                        defaultDate = `${parts[2]}-${parts[1]}-${parts[0]}`;
                    }
                }
                document.getElementById('attendance_payment_date').value = defaultDate;
                
                document.getElementById('attendancePaymentModal').style.display = 'flex';
            } else {
                alert('Cannot find attendance information');
            }
        }
        
        function closeAttendancePaymentModal() {
            document.getElementById('attendancePaymentModal').style.display = 'none';
        }
        
        // ================= EXPORT TO EXCEL =================
        function exportAttendanceToExcel() {
            const month = '<?php echo $payment_month; ?>';
            window.location.href = 'export_attendance_payment.php?month=' + month;
        }
        
        // ================= CHECK URL ON LOAD =================
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const section = urlParams.get('section');
            if (section) {
                showSection(section);
            }
            
            // Close modal on outside click
            window.onclick = function(event) {
                const attendanceModal = document.getElementById('attendancePaymentModal');
                if (event.target == attendanceModal) {
                    closeAttendancePaymentModal();
                }
            }
        });
    </script>
</body>
</html>
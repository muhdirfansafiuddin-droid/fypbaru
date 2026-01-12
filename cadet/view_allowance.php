<?php
// cadet/view_allowance.php - VIEW ALLOWANCE FOR CADET WITH REAL-TIME CALCULATION (ENGLISH)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../app/core/RBAC.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Database.php';

// Check permission - MUST BE cadet
RBAC::checkPermission('cadet');

try {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    $db = new Database();
    
    // Check if user is logged in
    if (!$user || $user['role'] !== 'cadet') {
        header("Location: ../index.php");
        exit();
    }
    
    $cadet_id = $user['user_id'];
    $service_type = $user['service_type'] ?? null;
    $rank_level = $user['rank_level'] ?? null;
    $today = date('Y-m-d');
    $current_month = date('Y-m');
    $current_year = date('Y');
    $user_name = $user['name'] ?? 'Cadet';
    $military_number = $user['military_number'] ?? 'N/A';
    
    // Get selected month from URL or default to current month
    $selected_month = $_GET['month'] ?? $current_month;
    
    // 1. GET ALLOWANCE DATA FROM DATABASE (if exists)
    $allowanceQuery = "SELECT 
                        ac.calc_id,
                        ac.month_year,
                        ac.attendance_rate,
                        ac.training_days,
                        ac.allowance_tempatan,
                        ac.allowance_berterusan,
                        ac.allowance_kem,
                        ac.allowance_pentauliahan,
                        ac.allowance_bounty,
                        ac.allowance_pakaian,
                        ac.total_training,
                        ac.total_additional,
                        ac.total_amount,
                        ac.is_paid,
                        ac.payment_date,
                        ac.calculated_by,
                        ac.calculated_at,
                        u.name as calculated_by_name
                    FROM allowance_calculations ac
                    LEFT JOIN users u ON ac.calculated_by = u.user_id
                    WHERE ac.user_id = ? 
                    AND ac.month_year = ?
                    ORDER BY ac.calculated_at DESC
                    LIMIT 1";
    
    $allowanceStmt = $db->prepare($allowanceQuery);
    $allowanceStmt->bind_param("is", $cadet_id, $selected_month);
    $allowanceStmt->execute();
    $allowanceResult = $allowanceStmt->get_result();
    $allowanceData = $allowanceResult->fetch_assoc();
    
    // 2. GET LATEST RATES FROM ADMIN - REAL-TIME
    $rateSql = "SELECT 
                allowance_rate_junior,
                allowance_rate_intermediate,
                allowance_rate_senior,
                training_rate_latihan_tempatan,
                training_rate_latihan_berterusan,
                training_rate_latihan_kem
            FROM users 
            WHERE role = 'admin' 
            LIMIT 1";
    
    $rateStmt = $db->prepare($rateSql);
    $rateStmt->execute();
    $rateResult = $rateStmt->get_result();
    $rates = $rateResult->fetch_assoc();
    
    // Calculate local training rate (convert hourly to daily)
    $local_rate_per_day = ($rates['training_rate_latihan_tempatan'] ?? 8.00) * 12;
    
    // Training rates based on rank
    $trainingRates = [
        'junior' => [
            'continuous' => $rates['allowance_rate_junior'] ?? 53.83,
            'camp' => $rates['allowance_rate_junior'] ?? 53.83,
            'local' => $local_rate_per_day
        ],
        'intermediate' => [
            'continuous' => $rates['allowance_rate_intermediate'] ?? 58.00,
            'camp' => $rates['allowance_rate_intermediate'] ?? 58.00,
            'local' => $local_rate_per_day
        ],
        'senior' => [
            'continuous' => $rates['allowance_rate_senior'] ?? 62.17,
            'camp' => $rates['allowance_rate_senior'] ?? 62.17,
            'local' => $local_rate_per_day
        ]
    ];
    
    // Fixed rates (from admin page)
    $fixedRates = [
        'accreditation_per_day' => 62.20,
        'bounty_all' => 43.33,
        'clothing_senior' => 125.00
    ];
    
    // Get cadet's rank rate
    $cadetRate = $trainingRates[$rank_level] ?? $trainingRates['junior'];
    
    // 3. GET ATTENDANCE RECORDS FOR SELECTED MONTH
    $attendanceSql = "SELECT 
                        a.attendance_id,
                        a.date,
                        a.status,
                        a.payment_status,
                        a.payment_date,
                        ts.training_type,
                        ts.training_category,
                        ts.location,
                        ts.session_time,
                        a.recorded_at
                    FROM attendance a
                    JOIN training_sessions ts ON a.session_id = ts.session_id
                    WHERE a.user_id = ?
                    AND DATE_FORMAT(a.date, '%Y-%m') = ?
                    ORDER BY a.date DESC";
    
    $attendanceStmt = $db->prepare($attendanceSql);
    $attendanceStmt->bind_param("is", $cadet_id, $selected_month);
    $attendanceStmt->execute();
    $attendanceResult = $attendanceStmt->get_result();
    
    // 4. CALCULATE REAL-TIME ALLOWANCE
    $attendanceRecords = [];
    $attendanceDays = 0;
    $trainingBreakdown = [
        'local' => 0,
        'continuous' => 0,
        'camp' => 0,
        'accreditation' => 0
    ];
    
    // Get total sessions for the month
    $monthStart = date('Y-m-01', strtotime($selected_month . '-01'));
    $monthEnd = date('Y-m-t', strtotime($selected_month . '-01'));
    
    $totalSessionsSql = "SELECT COUNT(DISTINCT training_date) as total_sessions 
                        FROM training_sessions 
                        WHERE training_date BETWEEN ? AND ?";
    $sessionsStmt = $db->prepare($totalSessionsSql);
    $sessionsStmt->bind_param("ss", $monthStart, $monthEnd);
    $sessionsStmt->execute();
    $sessionsResult = $sessionsStmt->get_result();
    $totalSessions = $sessionsResult->fetch_assoc()['total_sessions'] ?? 0;
    
    // Process attendance records
    while ($attendance = $attendanceResult->fetch_assoc()) {
        $attendanceRecords[] = $attendance;
        
        if ($attendance['status'] == 'present') {
            $attendanceDays++;
            
            // Categorize by training type
            $training_type = strtolower($attendance['training_type']);
            $training_category = strtolower($attendance['training_category'] ?? '');
            
            if (strpos($training_type, 'tempatan') !== false || strpos($training_type, 'baris') !== false || strpos($training_type, 'local') !== false) {
                $trainingBreakdown['local']++;
            } 
            elseif (strpos($training_type, 'kem') !== false || strpos($training_type, 'camp') !== false) {
                $trainingBreakdown['camp']++;
            }
            elseif (strpos($training_type, 'pentauliahan') !== false || strpos($training_type, 'accreditation') !== false) {
                $trainingBreakdown['accreditation']++;
            }
            else {
                $trainingBreakdown['continuous']++;
            }
        }
    }
    
    // Calculate attendance rate
    $attendanceRate = $totalSessions > 0 ? ($attendanceDays / $totalSessions) * 100 : 0;
    
    // Calculate training allowance REAL-TIME
    $trainingAllowance = 0;
    $trainingDetails = [];
    
    // Local Training
    if ($trainingBreakdown['local'] > 0) {
        $amount = $trainingBreakdown['local'] * $cadetRate['local'];
        $trainingAllowance += $amount;
        $trainingDetails[] = [
            'type' => 'Local Training',
            'days' => $trainingBreakdown['local'],
            'rate' => $cadetRate['local'],
            'amount' => $amount
        ];
    }
    
    // Continuous Training
    if ($trainingBreakdown['continuous'] > 0) {
        $amount = $trainingBreakdown['continuous'] * $cadetRate['continuous'];
        $trainingAllowance += $amount;
        $trainingDetails[] = [
            'type' => 'Continuous Training',
            'days' => $trainingBreakdown['continuous'],
            'rate' => $cadetRate['continuous'],
            'amount' => $amount
        ];
    }
    
    // Camp Training
    if ($trainingBreakdown['camp'] > 0) {
        $amount = $trainingBreakdown['camp'] * $cadetRate['camp'];
        $trainingAllowance += $amount;
        $trainingDetails[] = [
            'type' => 'Camp Training',
            'days' => $trainingBreakdown['camp'],
            'rate' => $cadetRate['camp'],
            'amount' => $amount
        ];
    }
    
    // Accreditation Training (Senior only)
    if ($trainingBreakdown['accreditation'] > 0 && $rank_level == 'senior') {
        $amount = $trainingBreakdown['accreditation'] * $fixedRates['accreditation_per_day'];
        $trainingAllowance += $amount;
        $trainingDetails[] = [
            'type' => 'Accreditation Training',
            'days' => $trainingBreakdown['accreditation'],
            'rate' => $fixedRates['accreditation_per_day'],
            'amount' => $amount
        ];
    }
    
    // Calculate additional allowances
    $additionalAllowance = 0;
    $additionalDetails = [];
    
    // Bounty for all
    $additionalAllowance += $fixedRates['bounty_all'];
    $additionalDetails[] = [
        'type' => 'Bounty',
        'amount' => $fixedRates['bounty_all']
    ];
    
    // Clothing for senior only
    if ($rank_level == 'senior') {
        $additionalAllowance += $fixedRates['clothing_senior'];
        $additionalDetails[] = [
            'type' => 'Clothing Allowance',
            'amount' => $fixedRates['clothing_senior']
        ];
    }
    
    // Calculate total
    $totalAmount = $trainingAllowance + $additionalAllowance;
    
    // 5. GET ALL MONTHS WITH ALLOWANCE CALCULATIONS
    $monthsSql = "SELECT DISTINCT month_year 
                 FROM allowance_calculations 
                 WHERE user_id = ?
                 ORDER BY month_year DESC";
    
    $monthsStmt = $db->prepare($monthsSql);
    $monthsStmt->bind_param("i", $cadet_id);
    $monthsStmt->execute();
    $monthsResult = $monthsStmt->get_result();
    $available_months = [];
    while ($row = $monthsResult->fetch_assoc()) {
        $available_months[] = $row['month_year'];
    }
    
    // 6. GET TOTAL SUMMARY
    $summarySql = "SELECT 
                    COUNT(DISTINCT month_year) as total_months,
                    SUM(total_amount) as total_earned,
                    SUM(is_paid) as total_paid_months,
                    SUM(CASE WHEN is_paid = 1 THEN total_amount ELSE 0 END) as total_paid_amount,
                    AVG(attendance_rate) as avg_attendance_rate
                FROM allowance_calculations 
                WHERE user_id = ?";
    
    $summaryStmt = $db->prepare($summarySql);
    $summaryStmt->bind_param("i", $cadet_id);
    $summaryStmt->execute();
    $summaryResult = $summaryStmt->get_result();
    $summaryData = $summaryResult->fetch_assoc();
    
    // 7. GET LATEST ALLOWANCE
    $latestAllowanceSql = "SELECT 
                            month_year,
                            total_amount,
                            is_paid,
                            payment_date
                        FROM allowance_calculations 
                        WHERE user_id = ?
                        ORDER BY month_year DESC
                        LIMIT 1";
    
    $latestStmt = $db->prepare($latestAllowanceSql);
    $latestStmt->bind_param("i", $cadet_id);
    $latestStmt->execute();
    $latestResult = $latestStmt->get_result();
    $latestAllowance = $latestResult->fetch_assoc();
    
    // 8. CADET INFO
    $cadetInfo = [
        'name' => $user['name'],
        'military_number' => $user['military_number'],
        'service_type' => $user['service_type'],
        'rank_level' => $user['rank_level'],
        'join_date' => $user['join_date'],
        'email' => $user['email'],
        'phone' => $user['phone']
    ];
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Helper functions
function getServiceLabel($type) {
    $labels = [
        'darat' => 'Army',
        'laut' => 'Navy', 
        'udara' => 'Air Force'
    ];
    return $labels[$type] ?? $type;
}

function getRankLabel($rank) {
    $labels = [
        'junior' => 'Junior',
        'intermediate' => 'Intermediate',
        'senior' => 'Senior'
    ];
    return $labels[$rank] ?? $rank;
}

function formatCurrency($amount) {
    if (empty($amount) || !is_numeric($amount)) $amount = 0;
    return 'RM ' . number_format($amount, 2);
}

function formatDate($dateString) {
    if (empty($dateString) || $dateString == '0000-00-00') return '';
    try {
        $date = strtotime($dateString);
        return $date ? date('d/m/Y', $date) : '';
    } catch (Exception $e) {
        return '';
    }
}

function formatTime($timeString) {
    if (empty($timeString)) return '';
    try {
        $time = strtotime($timeString);
        return $time ? date('h:i A', $time) : '';
    } catch (Exception $e) {
        return '';
    }
}

function getMonthName($monthYear) {
    if (empty($monthYear)) return '';
    return date('F Y', strtotime($monthYear . '-01'));
}

function getPaymentBadge($status) {
    if ($status == 1 || strtolower($status) == 'paid') {
        return '<span class="payment-badge paid"><i class="fas fa-check-circle"></i> Already Paid</span>';
    } else {
        return '<span class="payment-badge pending"><i class="fas fa-clock"></i> Waiting for Payment</span>';
    }
}

function getAttendancePaymentBadge($status) {
    switch(strtolower($status)) {
        case 'paid':
            return '<span class="payment-badge paid"><i class="fas fa-check-circle"></i> Already Paid</span>';
        case 'pending':
            return '<span class="payment-badge pending"><i class="fas fa-clock"></i> Waiting for Payment</span>';
        case 'processing':
            return '<span class="payment-badge processing"><i class="fas fa-sync-alt"></i> In Process</span>';
        default:
            return '<span class="payment-badge">' . ucfirst($status) . '</span>';
    }
}

function getSessionTimeLabel($time) {
    $labels = [
        'pagi' => 'Morning',
        'tengah hari' => 'Afternoon',
        'petang' => 'Evening',
        'malam' => 'Night'
    ];
    return $labels[$time] ?? ucfirst($time);
}

function getTrainingCategoryLabel($category) {
    $labels = [
        'tempatan' => 'Local Training',
        'berterusan' => 'Continuous Training',
        'kem_tahunan' => 'Annual Camp',
        'pentauliahan' => 'Accreditation'
    ];
    return $labels[$category] ?? $category;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Allowance - CAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #3182ce;
            --light: #f7fafc;
            --success: #48bb78;
            --warning: #ed8936;
            --danger: #f56565;
            --purple: #9f7aea;
            --blue: #4299e1;
            --gray: #718096;
            --teal: #38b2ac;
            --pink: #ed64a6;
            --cuti: #f6ad55;
            --excuse: #68d391;
            --money: #38a169;
            --info: #4299e1;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        
        body {
            background: #f0f2f5;
            min-height: 100vh;
            padding-bottom: 60px;
        }
        
        .container {
            max-width: 100%;
            padding: 15px;
        }
        
        /* HEADER */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, #2c5282 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        
        .header h1 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
            position: relative;
            z-index: 1;
        }
        
        .user-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            position: relative;
            z-index: 1;
        }
        
        .user-details {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(to bottom right, #3182ce, #2c5282);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            border: 3px solid rgba(255, 255, 255, 0.3);
        }
        
        .user-text h3 {
            font-size: 1.1rem;
            margin-bottom: 2px;
        }
        
        .user-text p {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        
        .user-badges {
            display: flex;
            gap: 5px;
            margin-top: 5px;
        }
        
        .service-badge {
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.2);
        }
        
        .rank-badge {
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.2);
        }
        
        /* MONTH FILTER */
        .month-filter {
            background: white;
            border-radius: 10px;
            padding: 10px 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .filter-label {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .month-select {
            padding: 8px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            color: var(--secondary);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            min-width: 180px;
        }
        
        .month-select:focus {
            border-color: var(--accent);
            outline: none;
        }
        
        /* ALLOWANCE SUMMARY */
        .allowance-summary {
            background: linear-gradient(135deg, var(--money) 0%, #37a16cff 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .allowance-summary::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        
        .summary-label {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .summary-amount {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 10px 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .summary-month {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-top: 5px;
        }
        
        .payment-status {
            margin-top: 15px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            background: rgba(255, 255, 255, 0.2);
        }
        
        .payment-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .payment-badge.paid {
            background: rgba(72, 187, 120, 0.2);
            color: white;
            border: 1px solid rgba(72, 187, 120, 0.3);
        }
        
        .payment-badge.pending {
            background: rgba(237, 137, 54, 0.2);
            color: white;
            border: 1px solid rgba(237, 137, 54, 0.3);
        }
        
        .payment-badge.processing {
            background: rgba(66, 153, 225, 0.2);
            color: white;
            border: 1px solid rgba(66, 153, 225, 0.3);
        }
        
        /* STATS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        
        .stat-icon {
            font-size: 1.5rem;
            margin-bottom: 10px;
            opacity: 0.8;
        }
        
        .stat-icon.months { color: var(--accent); }
        .stat-icon.earned { color: var(--money); }
        .stat-icon.paid { color: var(--success); }
        .stat-icon.attendance { color: var(--purple); }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 5px 0;
            line-height: 1;
        }
        
        .months .stat-number { color: var(--accent); }
        .earned .stat-number { color: var(--money); }
        .paid .stat-number { color: var(--success); }
        .attendance .stat-number { color: var(--purple); }
        
        .stat-label {
            color: var(--secondary);
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        /* REAL-TIME INFO BADGE */
        .realtime-badge {
            background: linear-gradient(135deg, var(--info) 0%, #2b6cb0 100%);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 15px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(66, 153, 225, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(66, 153, 225, 0); }
            100% { box-shadow: 0 0 0 0 rgba(66, 153, 225, 0); }
        }
        
        /* ALLOWANCE DETAILS */
        .allowance-details {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .section-title {
            color: var(--primary);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .detail-item:last-child {
            border-bottom: none;
            font-weight: 600;
            color: var(--primary);
            font-size: 1.1rem;
            padding-top: 15px;
        }
        
        .detail-label {
            color: var(--gray);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .detail-value {
            font-weight: 600;
            color: var(--secondary);
            text-align: right;
        }
        
        .detail-value.amount {
            color: var(--money);
            font-weight: 700;
        }
        
        /* ATTENDANCE PAYMENT LIST */
        .attendance-payment {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .attendance-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .attendance-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .attendance-item:last-child {
            border-bottom: none;
        }
        
        .attendance-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .attendance-icon {
            width: 40px;
            height: 40px;
            background: #f8fafc;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            font-size: 1rem;
        }
        
        .attendance-details h4 {
            color: var(--primary);
            margin-bottom: 2px;
            font-size: 0.95rem;
        }
        
        .attendance-details p {
            color: #718096;
            font-size: 0.8rem;
        }
        
        .attendance-time {
            color: var(--gray);
            font-size: 0.75rem;
            text-align: right;
        }
        
        /* NO DATA */
        .no-data {
            text-align: center;
            padding: 30px;
            color: #718096;
        }
        
        .no-data i {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.3;
        }
        
        /* PROGRESS BAR */
        .progress-container {
            margin: 15px 0;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .progress-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .progress-percent {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--accent);
        }
        
        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--accent);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        /* CALCULATION INFO */
        .calculation-info {
            background: #f8fafc;
            border-radius: 8px;
            padding: 12px;
            margin-top: 15px;
            font-size: 0.8rem;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* RATE CARD */
        .rate-card-small {
            background: #f0f9ff;
            border: 1px solid #bee3f8;
            border-radius: 8px;
            padding: 10px;
            margin: 10px 0;
        }
        
        .rate-card-small h5 {
            color: var(--info);
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        /* MOBILE NAV */
        .mobile-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--primary);
            display: flex;
            justify-content: space-around;
            padding: 10px;
            z-index: 1000;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .mobile-nav-item {
            color: white;
            text-decoration: none;
            text-align: center;
            flex: 1;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .mobile-nav-item.active {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }
        
        .mobile-nav-item.active .mobile-nav-icon {
            color: #fff;
        }
        
        .mobile-nav-item.active .mobile-nav-label {
            color: #fff;
            font-weight: 600;
        }
        
        .mobile-nav-icon {
            font-size: 1.2rem;
            margin-bottom: 3px;
            color: rgba(255, 255, 255, 0.8);
            transition: color 0.3s ease;
        }
        
        .mobile-nav-label {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.8);
            transition: color 0.3s ease;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <h1>
                <i class="fas fa-money-bill-wave"></i>
                My Allowance
            </h1>
            <div class="user-info">
                <div class="user-details">
                    <div class="user-avatar">
                        <?php 
                            $initials = strtoupper(substr($cadetInfo['name'], 0, 1));
                            echo $initials;
                        ?>
                    </div>
                    <div class="user-text">
                        <h3><?php echo htmlspecialchars($cadetInfo['name']); ?></h3>
                        <p>Military No: <?php echo $cadetInfo['military_number']; ?></p>
                        <div class="user-badges">
                            <span class="service-badge"><?php echo getServiceLabel($cadetInfo['service_type']); ?></span>
                            <span class="rank-badge"><?php echo getRankLabel($cadetInfo['rank_level']); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- MONTH FILTER -->
        <div class="month-filter">
            <div class="filter-label">
                <i class="fas fa-calendar-alt"></i>
                Select Month
            </div>
            <select class="month-select" onchange="window.location.href='?month='+this.value">
                <?php foreach ($available_months as $month): ?>
                    <option value="<?php echo $month; ?>" <?php echo $selected_month == $month ? 'selected' : ''; ?>>
                        <?php echo getMonthName($month); ?>
                    </option>
                <?php endforeach; ?>
                <?php if (!in_array($selected_month, $available_months) && $selected_month != $current_month): ?>
                    <option value="<?php echo $selected_month; ?>" selected>
                        <?php echo getMonthName($selected_month); ?>
                    </option>
                <?php endif; ?>
                <?php if (empty($available_months)): ?>
                    <option value="<?php echo $selected_month; ?>" selected>
                        <?php echo getMonthName($selected_month); ?>
                    </option>
                <?php endif; ?>
            </select>
        </div>
        
        <!-- REAL-TIME INFO BADGE -->
        <div class="realtime-badge">
            <i class="fas fa-sync-alt"></i>
            <span>Real-Time Calculation | Updated: <?php echo date('d/m/Y H:i:s'); ?></span>
        </div>
        
        <!-- ALLOWANCE SUMMARY -->
        <div class="allowance-summary">
            <div class="summary-label">
                <i class="fas fa-money-check-alt"></i>
                TOTAL ALLOWANCE (REAL-TIME)
            </div>
            
            <?php if ($allowanceData && $allowanceData['total_amount'] > 0): ?>
                <div class="summary-amount">
                    <?php echo formatCurrency($allowanceData['total_amount']); ?>
                </div>
                <div class="summary-month">
                    <?php echo getMonthName($allowanceData['month_year']); ?>
                    <small style="display: block; margin-top: 5px; opacity: 0.8;">
                        <i class="fas fa-database"></i> From saved records
                    </small>
                </div>
                <div class="payment-status">
                    <?php echo getPaymentBadge($allowanceData['is_paid']); ?>
                    <?php if ($allowanceData['payment_date'] && $allowanceData['payment_date'] != '0000-00-00'): ?>
                        <br><small style="font-size: 0.75rem; opacity: 0.9;">
                            Payment Date: <?php echo formatDate($allowanceData['payment_date']); ?>
                        </small>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="summary-amount">
                    <?php echo formatCurrency($totalAmount); ?>
                </div>
                <div class="summary-month">
                    <?php echo getMonthName($selected_month); ?>
                    <small style="display: block; margin-top: 5px; opacity: 0.8;">
                        <i class="fas fa-sync-alt"></i> Real-time calculation
                    </small>
                </div>
                <div class="payment-status">
                    <?php if ($attendanceDays > 0): ?>
                        <i class="fas fa-calculator"></i> Not Saved to Database
                    <?php else: ?>
                        <i class="fas fa-clock"></i> No Attendance
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- STATS GRID -->
        <div class="stats-grid">
            <div class="stat-card months">
                <div class="stat-icon months">
                    <i class="fas fa-calendar"></i>
                </div>
                <div class="stat-number">
                    <?php echo $summaryData['total_months'] ?? 0; ?>
                </div>
                <div class="stat-label">Months Calculated</div>
            </div>
            
            <div class="stat-card earned">
                <div class="stat-icon earned">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-number">
                    <?php echo $summaryData['total_earned'] ? formatCurrency($summaryData['total_earned']) : 'RM 0.00'; ?>
                </div>
                <div class="stat-label">Total Accumulated</div>
            </div>
            
            <div class="stat-card paid">
                <div class="stat-icon paid">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-number">
                    <?php echo $summaryData['total_paid_months'] ?? 0; ?>
                </div>
                <div class="stat-label">Months Paid</div>
            </div>
            
            <div class="stat-card attendance">
                <div class="stat-icon attendance">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-number">
                    <?php echo round($attendanceRate, 1); ?>%
                </div>
                <div class="stat-label">Attendance Rate</div>
            </div>
        </div>
        
        <!-- CURRENT RATES INFO -->
        <div style="background: #f0f9ff; border-radius: 10px; padding: 15px; margin-bottom: 15px; border-left: 4px solid var(--info);">
            <h4 style="color: var(--primary); margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-chart-line"></i> Current Allowance Rates (Real-Time)
            </h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
                <div class="rate-card-small">
                    <h5>Junior</h5>
                    <div style="font-size: 1.1rem; font-weight: bold; color: var(--money);">
                        RM <?php echo number_format($rates['allowance_rate_junior'] ?? 0, 2); ?>
                    </div>
                    <small style="color: var(--gray);">per day</small>
                </div>
                <div class="rate-card-small">
                    <h5>Intermediate</h5>
                    <div style="font-size: 1.1rem; font-weight: bold; color: var(--money);">
                        RM <?php echo number_format($rates['allowance_rate_intermediate'] ?? 0, 2); ?>
                    </div>
                    <small style="color: var(--gray);">per day</small>
                </div>
                <div class="rate-card-small">
                    <h5>Senior</h5>
                    <div style="font-size: 1.1rem; font-weight: bold; color: var(--money);">
                        RM <?php echo number_format($rates['allowance_rate_senior'] ?? 0, 2); ?>
                    </div>
                    <small style="color: var(--gray);">per day</small>
                </div>
                <div class="rate-card-small">
                    <h5>Local Training</h5>
                    <div style="font-size: 1.1rem; font-weight: bold; color: var(--money);">
                        RM <?php echo number_format($local_rate_per_day, 2); ?>
                    </div>
                    <small style="color: var(--gray);">per day (<?php echo number_format($rates['training_rate_latihan_tempatan'] ?? 0, 2); ?>/hour)</small>
                </div>
            </div>
            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #bee3f8; color: var(--gray); font-size: 0.85rem;">
                <i class="fas fa-info-circle"></i> These rates may change based on updates from the administrator.
            </div>
        </div>
        
        <!-- ALLOWANCE DETAILS -->
        <div class="allowance-details">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-file-invoice-dollar"></i>
                    Allowance Details (Real-Time)
                </h3>
                <?php if ($allowanceData && $allowanceData['calculated_at']): ?>
                    <span style="font-size: 0.8rem; color: var(--gray);">
                        Last saved: <?php echo formatDate($allowanceData['calculated_at']); ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <div class="details-grid">
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-calendar-check"></i> Attendance Days
                    </span>
                    <span class="detail-value">
                        <?php echo $attendanceDays; ?> days
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-calendar-alt"></i> Total Sessions
                    </span>
                    <span class="detail-value">
                        <?php echo $totalSessions; ?> sessions
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-chart-line"></i> Attendance Rate
                    </span>
                    <span class="detail-value">
                        <?php echo number_format($attendanceRate, 1); ?>%
                    </span>
                </div>
                
                <!-- Progress Bar -->
                <div class="progress-container">
                    <div class="progress-header">
                        <div class="progress-title">Attendance Performance</div>
                        <div class="progress-percent"><?php echo number_format($attendanceRate, 1); ?>%</div>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min($attendanceRate, 100); ?>%"></div>
                    </div>
                    <small style="color: var(--gray); font-size: 0.75rem;">
                        <?php echo $attendanceDays; ?>/<?php echo $totalSessions; ?> sessions
                    </small>
                </div>
                
                <!-- Training Allowance Breakdown -->
                <?php if (!empty($trainingDetails)): ?>
                    <?php foreach ($trainingDetails as $detail): ?>
                    <div class="detail-item">
                        <span class="detail-label">
                            <i class="fas fa-running"></i> <?php echo $detail['type']; ?>
                        </span>
                        <span class="detail-value amount">
                            RM <?php echo number_format($detail['amount'], 2); ?>
                            <small style="display: block; font-weight: normal; color: var(--gray);">
                                <?php echo $detail['days']; ?> days × RM <?php echo number_format($detail['rate'], 2); ?>
                            </small>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Additional Allowance -->
                <?php if (!empty($additionalDetails)): ?>
                    <?php foreach ($additionalDetails as $detail): ?>
                    <div class="detail-item">
                        <span class="detail-label">
                            <i class="fas fa-gift"></i> <?php echo $detail['type']; ?>
                        </span>
                        <span class="detail-value amount">
                            RM <?php echo number_format($detail['amount'], 2); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Totals -->
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-calculator"></i> Total Training Allowance
                    </span>
                    <span class="detail-value amount">
                        <?php echo formatCurrency($trainingAllowance); ?>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-gift"></i> Total Additional Allowance
                    </span>
                    <span class="detail-value amount">
                        <?php echo formatCurrency($additionalAllowance); ?>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-calculator"></i> TOTAL AMOUNT
                    </span>
                    <span class="detail-value amount" style="font-size: 1.2rem;">
                        <?php echo formatCurrency($totalAmount); ?>
                    </span>
                </div>
            </div>
            
            <?php if ($allowanceData && $allowanceData['calculated_by_name']): ?>
            <div class="calculation-info">
                <i class="fas fa-database"></i>
                <div>
                    Official record saved by: <strong><?php echo htmlspecialchars($allowanceData['calculated_by_name']); ?></strong><br>
                    Calculation date: <?php echo formatDate($allowanceData['calculated_at']); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!$allowanceData && $attendanceDays > 0): ?>
            <div class="calculation-info" style="background: #fff3cd; border: 1px solid #ffd93d;">
                <i class="fas fa-exclamation-triangle" style="color: #856404;"></i>
                <div>
                    <strong>Note:</strong> This is only a real-time calculation. Official records will be saved by the administrator.
                    The actual amount may vary depending on administrator verification.
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- ATTENDANCE PAYMENT LIST -->
        <div class="attendance-payment">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-calendar-check"></i>
                    Attendance & Payment Status
                </h3>
                <span style="font-size: 0.8rem; color: var(--gray);">
                    Month of <?php echo getMonthName($selected_month); ?>
                </span>
            </div>
            
            <?php if (!empty($attendanceRecords)): ?>
                <div class="attendance-list">
                    <?php foreach ($attendanceRecords as $attendance): ?>
                    <div class="attendance-item">
                        <div class="attendance-info">
                            <div class="attendance-icon" style="background: <?php echo $attendance['status'] == 'present' ? '#d4edda' : '#f8d7da'; ?>; color: <?php echo $attendance['status'] == 'present' ? '#155724' : '#721c24'; ?>;">
                                <?php 
                                $icon = 'fa-calendar-check';
                                $training_type = strtolower($attendance['training_type'] ?? '');
                                if (strpos($training_type, 'tempatan') !== false || strpos($training_type, 'baris') !== false) {
                                    $icon = 'fa-running';
                                } elseif (strpos($training_type, 'kem') !== false) {
                                    $icon = 'fa-campground';
                                } elseif (strpos($training_type, 'pentauliahan') !== false) {
                                    $icon = 'fa-graduation-cap';
                                }
                                ?>
                                <i class="fas <?php echo $icon; ?>"></i>
                            </div>
                            <div class="attendance-details">
                                <h4><?php echo htmlspecialchars($attendance['training_type']); ?></h4>
                                <p>
                                    <?php echo formatDate($attendance['date']); ?> • 
                                    <?php echo htmlspecialchars($attendance['location']); ?> • 
                                    <?php echo getSessionTimeLabel($attendance['session_time']); ?>
                                    <?php if ($attendance['training_category']): ?>
                                        • <?php echo getTrainingCategoryLabel($attendance['training_category']); ?>
                                    <?php endif; ?>
                                </p>
                                <small style="color: <?php echo $attendance['status'] == 'present' ? 'var(--success)' : 'var(--danger)'; ?>;">
                                    <i class="fas fa-user"></i> Status: <?php echo $attendance['status'] == 'present' ? 'Present' : 'Absent'; ?>
                                </small>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <?php if ($attendance['status'] == 'present'): ?>
                                <div>
                                    <?php echo getAttendancePaymentBadge($attendance['payment_status']); ?>
                                </div>
                                <?php if ($attendance['payment_date'] && $attendance['payment_date'] != '0000-00-00'): ?>
                                    <div class="attendance-time">
                                        <i class="fas fa-calendar"></i> 
                                        <?php echo formatDate($attendance['payment_date']); ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: var(--danger); font-size: 0.8rem;">
                                    <i class="fas fa-times-circle"></i> Not present
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-calendar-times"></i>
                    <p>No attendance recorded for month <?php echo getMonthName($selected_month); ?></p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- IMPORTANT NOTES -->
        <div style="background: #f0f9ff; border-radius: 10px; padding: 15px; margin-bottom: 15px; border-left: 4px solid var(--accent);">
            <h4 style="color: var(--primary); margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-info-circle"></i> Important Information
            </h4>
            <ul style="color: var(--secondary); font-size: 0.85rem; line-height: 1.6;">
                <li><strong>Real-Time Calculation:</strong> Allowance amounts are calculated based on current rates from administrator</li>
                <li><strong>Official Records:</strong> Only calculations saved by administrator will be considered official</li>
                <li><strong>Differences:</strong> There may be differences between real-time calculations and official records</li>
                <li><strong>Updates:</strong> Allowance rates will be updated automatically when administrator changes rates</li>
                <li><strong>Payment:</strong> Payment status is based on official records in the system</li>
            </ul>
        </div>
    </div>
    
    <!-- MOBILE NAVIGATION -->
    <nav class="mobile-nav">
        <a href="dashboard.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-home"></i>
            </div>
            <div class="mobile-nav-label">Dashboard</div>
        </a>
        
        <a href="view_allowance.php" class="mobile-nav-item active">
            <div class="mobile-nav-icon">
                <i class="fas fa-money-bill"></i>
            </div>
            <div class="mobile-nav-label">Allowance</div>
        </a>
        
        <a href="apply_excuse.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-file-medical"></i>
            </div>
            <div class="mobile-nav-label">Excuse</div>
        </a>
        
        <a href="view_performance.php" class="mobile-nav-item">
            <div class="mobile-nav-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="mobile-nav-label">Performance</div>
        </a>
    </nav>
    
    <script>
        // Add animation to stats cards on load
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            // Mobile nav interaction
            const navItems = document.querySelectorAll('.mobile-nav-item');
            navItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    navItems.forEach(navItem => {
                        navItem.classList.remove('active');
                    });
                    this.classList.add('active');
                });
            });
            
            // Auto-refresh page every 2 minutes for real-time updates
            setInterval(() => {
                if (!document.hidden) {
                    console.log('Auto-refresh for real-time updates...');
                    window.location.reload();
                }
            }, 120000); // 2 minutes
            
            // Show refresh notification
            const refreshTime = new Date();
            refreshTime.setMinutes(refreshTime.getMinutes() + 2);
            console.log('Page will be updated at: ' + refreshTime.toLocaleTimeString());
        });
        
        // Set initial opacity for animation
        document.querySelectorAll('.stat-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        });
        
        // Animate progress bar on load
        window.addEventListener('load', function() {
            const progressFill = document.querySelector('.progress-fill');
            if (progressFill) {
                const width = progressFill.style.width;
                progressFill.style.width = '0%';
                
                setTimeout(() => {
                    progressFill.style.width = width;
                }, 300);
            }
        });
        
        // Manual refresh button (optional)
        function manualRefresh() {
            if (confirm('Refresh real-time data now?')) {
                window.location.reload();
            }
        }
    </script>
</body>
</html>
<?php
// get_allowance_details.php
require_once __DIR__ . '/../app/core/Database.php';

$db = new Database();

if (isset($_GET['calc_id'])) {
    $calc_id = intval($_GET['calc_id']);
    
    // Get allowance details
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
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        ?>
        <div class="details-grid">
            <!-- Cadet Information -->
            <div class="details-card">
                <div class="details-title">
                    <i class="fas fa-user"></i> Cadet Information
                </div>
                <div class="details-item">
                    <span class="details-label">Name:</span>
                    <span class="details-value"><?php echo htmlspecialchars($row['name']); ?></span>
                </div>
                <div class="details-item">
                    <span class="details-label">Military Number:</span>
                    <span class="details-value"><?php echo $row['military_number']; ?></span>
                </div>
                <div class="details-item">
                    <span class="details-label">Service:</span>
                    <span class="details-value"><?php echo ucfirst($row['service_type']); ?></span>
                </div>
                <div class="details-item">
                    <span class="details-label">Rank:</span>
                    <span class="details-value"><?php echo ucfirst($row['rank_level']); ?></span>
                </div>
                <div class="details-item">
                    <span class="details-label">Month:</span>
                    <span class="details-value"><?php echo date('F Y', strtotime($row['month_year'] . '-01')); ?></span>
                </div>
            </div>
            
            <!-- Allowance Breakdown -->
            <div class="details-card">
                <div class="details-title">
                    <i class="fas fa-chart-pie"></i> Allowance Breakdown
                </div>
                <?php if ($row['allowance_tempatan'] > 0): ?>
                    <div class="breakdown-item">
                        <span class="breakdown-label">Latihan Tempatan/Baris:</span>
                        <span class="breakdown-value">RM <?php echo number_format($row['allowance_tempatan'], 2); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ($row['allowance_berterusan'] > 0): ?>
                    <div class="breakdown-item">
                        <span class="breakdown-label">Latihan Berterusan:</span>
                        <span class="breakdown-value">RM <?php echo number_format($row['allowance_berterusan'], 2); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ($row['allowance_kem'] > 0): ?>
                    <div class="breakdown-item">
                        <span class="breakdown-label">Latihan Kem Tahunan:</span>
                        <span class="breakdown-value">RM <?php echo number_format($row['allowance_kem'], 2); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ($row['allowance_pentauliahan'] > 0): ?>
                    <div class="breakdown-item">
                        <span class="breakdown-label">Latihan Pentauliahan:</span>
                        <span class="breakdown-value">RM <?php echo number_format($row['allowance_pentauliahan'], 2); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ($row['allowance_bounty'] > 0): ?>
                    <div class="breakdown-item">
                        <span class="breakdown-label">Bounty:</span>
                        <span class="breakdown-value">RM <?php echo number_format($row['allowance_bounty'], 2); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ($row['allowance_pakaian'] > 0): ?>
                    <div class="breakdown-item">
                        <span class="breakdown-label">Pakaian:</span>
                        <span class="breakdown-value">RM <?php echo number_format($row['allowance_pakaian'], 2); ?></span>
                    </div>
                <?php endif; ?>
                
                <div class="total-breakdown">
                    <div style="display: flex; justify-content: space-between;">
                        <span>Total Training:</span>
                        <span>RM <?php echo number_format($row['total_training'], 2); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                        <span>Total Additional:</span>
                        <span>RM <?php echo number_format($row['total_additional'], 2); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-top: 10px; font-size: 1.2rem;">
                        <span><strong>TOTAL AMOUNT:</strong></span>
                        <span><strong>RM <?php echo number_format($row['total_amount'], 2); ?></strong></span>
                    </div>
                </div>
            </div>
            
            <!-- Payment Information -->
            <div class="details-card">
                <div class="details-title">
                    <i class="fas fa-money-check-alt"></i> Payment Information
                </div>
                <div class="details-item">
                    <span class="details-label">Payment Status:</span>
                    <span class="details-value" style="color: <?php echo $row['is_paid'] ? 'var(--success)' : 'var(--danger)'; ?>;">
                        <?php echo $row['is_paid'] ? 'PAID' : 'UNPAID'; ?>
                    </span>
                </div>
                <?php if ($row['payment_date']): ?>
                    <div class="details-item">
                        <span class="details-label">Payment Date:</span>
                        <span class="details-value"><?php echo date('d/m/Y', strtotime($row['payment_date'])); ?></span>
                    </div>
                <?php endif; ?>
                <div class="details-item">
                    <span class="details-label">Training Days:</span>
                    <span class="details-value"><?php echo $row['training_days']; ?> days</span>
                </div>
                <div class="details-item">
                    <span class="details-label">Calculated By:</span>
                    <span class="details-value"><?php echo htmlspecialchars($row['admin_name'] ?? 'System'); ?></span>
                </div>
                <div class="details-item">
                    <span class="details-label">Calculated On:</span>
                    <span class="details-value"><?php echo date('d/m/Y H:i', strtotime($row['calculated_at'])); ?></span>
                </div>
            </div>
            
            <!-- Rate Information -->
            <div class="details-card">
                <div class="details-title">
                    <i class="fas fa-calculator"></i> Rate Information
                </div>
                <div class="details-item">
                    <span class="details-label">Junior Rate:</span>
                    <span class="details-value">RM <?php echo number_format($row['allowance_rate_junior'], 2); ?>/day</span>
                </div>
                <div class="details-item">
                    <span class="details-label">Intermediate Rate:</span>
                    <span class="details-value">RM <?php echo number_format($row['allowance_rate_intermediate'], 2); ?>/day</span>
                </div>
                <div class="details-item">
                    <span class="details-label">Senior Rate:</span>
                    <span class="details-value">RM <?php echo number_format($row['allowance_rate_senior'], 2); ?>/day</span>
                </div>
                <div class="details-item">
                    <span class="details-label">Attendance Rate:</span>
                    <span class="details-value"><?php echo number_format($row['attendance_rate'], 2); ?>%</span>
                </div>
            </div>
        </div>
        <?php
    } else {
        echo '<p style="text-align: center; color: var(--danger);">Allowance details not found.</p>';
    }
} else {
    echo '<p style="text-align: center; color: var(--danger);">Invalid request.</p>';
}
?>
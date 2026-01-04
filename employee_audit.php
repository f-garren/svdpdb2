<?php
require_once 'config.php';
require_once 'auth.php';

requireAdmin();

$employee_id = intval($_GET['employee_id'] ?? 0);
$db = getDB();

if ($employee_id <= 0) {
    header('Location: employees.php');
    exit;
}

// Get employee info
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch();

if (!$employee) {
    header('Location: employees.php');
    exit;
}

// Get audit trail
$stmt = $db->prepare("SELECT ea.*, 
    c.name as customer_name,
    c.id as customer_id,
    v.visit_type,
    v.visit_date,
    v.customer_id as visit_customer_id,
    vc.name as visit_customer_name,
    vch.voucher_code,
    vch.amount as voucher_amount,
    vch.customer_id as voucher_customer_id,
    vchc.name as voucher_customer_name
    FROM employee_audit ea
    LEFT JOIN customers c ON ea.target_type = 'customer' AND ea.target_id = c.id
    LEFT JOIN visits v ON ea.target_type = 'visit' AND ea.target_id = v.id
    LEFT JOIN customers vc ON v.customer_id = vc.id
    LEFT JOIN vouchers vch ON ea.target_type = 'voucher' AND ea.target_id = vch.id
    LEFT JOIN customers vchc ON vch.customer_id = vchc.id
    WHERE ea.employee_id = ?
    ORDER BY ea.created_at DESC
    LIMIT 500");
$stmt->execute([$employee_id]);
$audit_logs = $stmt->fetchAll();

$page_title = "Employee Audit Trail";
include 'header.php';
?>

<div class="container">
    <div class="page-header">
        <div class="header-actions">
            <a href="employees.php" class="btn btn-secondary">← Back to Employees</a>
        </div>
        <h1>Audit Trail: <?php echo htmlspecialchars($employee['full_name']); ?></h1>
        <p class="lead">Username: <?php echo htmlspecialchars($employee['username']); ?></p>
    </div>

    <div class="report-section">
        <h2>Action History</h2>
        <?php if (count($audit_logs) > 0): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Action Type</th>
                        <th>Target</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audit_logs as $log): ?>
                        <tr>
                            <td><?php echo date('M d, Y \a\t g:i A', strtotime($log['created_at'])); ?></td>
                            <td>
                                <?php 
                                $action_labels = [
                                    'customer_edit' => 'Customer Edit',
                                    'customer_create' => 'Customer Created',
                                    'visit_create' => 'Visit Created',
                                    'visit_invalidate' => 'Visit Invalidated',
                                    'voucher_create' => 'Voucher Created',
                                    'voucher_redeem' => 'Voucher Redeemed',
                                    'voucher_revoke' => 'Voucher Revoked',
                                    'settings_change' => 'Settings Changed',
                                    'employee_create' => 'Employee Created',
                                    'employee_deactivate' => 'Employee Deactivated',
                                    'employee_reactivate' => 'Employee Reactivated',
                                    'employee_password_reset' => 'Password Reset'
                                ];
                                echo htmlspecialchars($action_labels[$log['action_type']] ?? ucfirst(str_replace('_', ' ', $log['action_type'])));
                                ?>
                            </td>
                            <td>
                                <?php if ($log['target_type'] === 'customer' && $log['customer_name']): ?>
                                    <a href="customer_view.php?id=<?php echo $log['target_id']; ?>"><?php echo htmlspecialchars($log['customer_name']); ?></a>
                                <?php elseif ($log['target_type'] === 'visit' && $log['visit_type']): ?>
                                    <?php 
                                    $visit_customer_name = $log['visit_customer_name'] ?? null;
                                    $visit_customer_id = $log['visit_customer_id'] ?? null;
                                    if ($visit_customer_name && $visit_customer_id): ?>
                                        <a href="customer_view.php?id=<?php echo $visit_customer_id; ?>"><?php echo htmlspecialchars($visit_customer_name); ?></a> - 
                                    <?php endif; ?>
                                    <?php echo ucfirst($log['visit_type']); ?> Visit (<?php echo date('M d, Y', strtotime($log['visit_date'])); ?>)
                                <?php elseif ($log['target_type'] === 'voucher' && $log['voucher_code']): ?>
                                    <?php 
                                    $voucher_customer_name = $log['voucher_customer_name'] ?? null;
                                    $voucher_customer_id = $log['voucher_customer_id'] ?? null;
                                    if ($voucher_customer_name && $voucher_customer_id): ?>
                                        <a href="customer_view.php?id=<?php echo $voucher_customer_id; ?>"><?php echo htmlspecialchars($voucher_customer_name); ?></a> - 
                                    <?php endif; ?>
                                    Voucher <?php echo htmlspecialchars($log['voucher_code']); ?> ($<?php echo number_format($log['voucher_amount'], 2); ?>)
                                <?php elseif ($log['target_type']): ?>
                                    <?php echo ucfirst($log['target_type']); ?> #<?php echo $log['target_id']; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $details = $log['details'] ?? '';
                                // For visit_create actions, enhance details with customer name if available
                                if ($log['action_type'] === 'visit_create' && $log['visit_customer_name'] && $log['visit_customer_id']) {
                                    // Replace "customer ID X" with customer name link in details
                                    $details = preg_replace(
                                        '/customer ID \d+/i',
                                        '<a href="customer_view.php?id=' . $log['visit_customer_id'] . '">' . htmlspecialchars($log['visit_customer_name']) . '</a>',
                                        $details
                                    );
                                    echo $details;
                                } elseif ($log['action_type'] === 'voucher_create' && $log['voucher_customer_name'] && $log['voucher_customer_id']) {
                                    // Replace "customer ID X" with customer name link in details
                                    $details = preg_replace(
                                        '/customer ID \d+/i',
                                        '<a href="customer_view.php?id=' . $log['voucher_customer_id'] . '">' . htmlspecialchars($log['voucher_customer_name']) . '</a>',
                                        $details
                                    );
                                    echo $details;
                                } else {
                                    echo nl2br(htmlspecialchars($details));
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="no-data">No audit logs found for this employee.</p>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>


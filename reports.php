<?php
require_once 'config.php';
require_once 'auth.php';

requirePermission('report_access');

$db = getDB();
$page_title = "Reports";
$error = '';
$success = '';

// Handle voucher revocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_voucher'])) {
    $voucher_code = trim($_POST['voucher_code'] ?? '');
    
    if (!empty($voucher_code)) {
        try {
            // Check if voucher exists and is active
            $stmt = $db->prepare("SELECT status FROM vouchers WHERE voucher_code = ?");
            $stmt->execute([$voucher_code]);
            $voucher = $stmt->fetch();
            
            if (!$voucher) {
                $error = "Voucher not found.";
            } elseif ($voucher['status'] !== 'active') {
                $error = "Only active vouchers can be revoked.";
            } else {
                // Revoke voucher by setting status to expired
                $stmt = $db->prepare("UPDATE vouchers SET status = 'expired' WHERE voucher_code = ?");
                $stmt->execute([$voucher_code]);
                $success = "Voucher {$voucher_code} has been revoked successfully.";
            }
        } catch (Exception $e) {
            $error = "Error revoking voucher: " . $e->getMessage();
        }
    } else {
        $error = "Invalid voucher code.";
    }
}

include 'header.php';

// Get statistics
$stmt = $db->query("SELECT COUNT(*) as total FROM customers");
$total_customers = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM visits WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND (is_invalid = 0 OR is_invalid IS NULL)");
$visits_30_days = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM visits WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND (is_invalid = 0 OR is_invalid IS NULL)");
$visits_7_days = $stmt->fetch()['total'];

// Top customers by visits (excluding invalid)
$stmt = $db->query("SELECT c.name, c.phone, COUNT(v.id) as visit_count 
                   FROM customers c 
                   LEFT JOIN visits v ON c.id = v.customer_id AND (v.is_invalid = 0 OR v.is_invalid IS NULL)
                   GROUP BY c.id 
                   ORDER BY visit_count DESC 
                   LIMIT 10");
$top_customers = $stmt->fetchAll();

// Monthly visit trends (excluding invalid)
$stmt = $db->query("SELECT DATE_FORMAT(visit_date, '%Y-%m') as month, COUNT(*) as count 
                   FROM visits 
                   WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND (is_invalid = 0 OR is_invalid IS NULL)
                   GROUP BY DATE_FORMAT(visit_date, '%Y-%m')
                   ORDER BY month");
$monthly_trends = $stmt->fetchAll();

// Voucher statistics
$stmt = $db->query("SELECT COUNT(*) as total FROM vouchers");
$total_vouchers = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM vouchers WHERE status = 'active'");
$active_vouchers = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM vouchers WHERE status = 'redeemed'");
$redeemed_vouchers = $stmt->fetch()['total'];

$stmt = $db->query("SELECT SUM(amount) as total FROM vouchers WHERE status = 'redeemed'");
$total_redeemed_amount = $stmt->fetch()['total'] ?? 0;

// Get voucher filter parameters
$filter_search = $_GET['voucher_search'] ?? '';
$filter_issued_date = $_GET['voucher_issued_date'] ?? '';
$filter_redeemed_date = $_GET['voucher_redeemed_date'] ?? '';
$filter_status = $_GET['voucher_status'] ?? '';

// Build voucher query with filters
$where_conditions = [];
$params = [];

if (!empty($filter_search)) {
    $stmt = $db->prepare("SELECT DISTINCT customer_id FROM household_members WHERE name LIKE ?");
    $stmt->execute(["%$filter_search%"]);
    $household_customer_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($household_customer_ids)) {
        $household_placeholders = str_repeat('?,', count($household_customer_ids) - 1) . '?';
        $where_conditions[] = "(v.voucher_code LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR v.customer_id IN ($household_placeholders))";
        $search_term = "%$filter_search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params = array_merge($params, $household_customer_ids);
    } else {
        $where_conditions[] = "(v.voucher_code LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)";
        $search_term = "%$filter_search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
}

if (!empty($filter_issued_date)) {
    $where_conditions[] = "DATE(v.issued_date) = ?";
    $params[] = $filter_issued_date;
}

if (!empty($filter_redeemed_date)) {
    $where_conditions[] = "DATE(v.redeemed_date) = ?";
    $params[] = $filter_redeemed_date;
}

if (!empty($filter_status)) {
    $where_conditions[] = "v.status = ?";
    $params[] = $filter_status;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get vouchers with filters
$query = "SELECT v.*, c.name as customer_name, c.phone 
         FROM vouchers v 
         INNER JOIN customers c ON v.customer_id = c.id 
         $where_clause
         ORDER BY v.issued_date DESC 
         LIMIT 200";

if (!empty($params)) {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $recent_vouchers = $stmt->fetchAll();
} else {
    $stmt = $db->query($query);
    $recent_vouchers = $stmt->fetchAll();
}
?>

<div class="container">
    <div class="page-header">
        <h1>Reports & Statistics</h1>
        <p class="lead">View insights and analytics</p>
        <div style="margin-top: 1rem;">
            <a href="money_export.php" class="btn btn-primary">Export Money Visits to CSV</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

        <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><ion-icon name="people"></ion-icon></div>
            <div class="stat-info">
                <h3><?php echo number_format($total_customers); ?></h3>
                <p>Total <?php echo htmlspecialchars(getCustomerTermPlural('Customers')); ?></p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon"><ion-icon name="calendar"></ion-icon></div>
            <div class="stat-info">
                <h3><?php echo number_format($visits_30_days); ?></h3>
                <p>Visits (Last 30 Days)</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon"><ion-icon name="stats-chart"></ion-icon></div>
            <div class="stat-info">
                <h3><?php echo number_format($visits_7_days); ?></h3>
                <p>Visits (Last 7 Days)</p>
            </div>
        </div>
    </div>

    <div class="report-section">
        <h2>Top Customers by Visit Count</h2>
        <?php if (count($top_customers) > 0): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Total Visits</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_customers as $index => $customer): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($customer['name']); ?></td>
                            <td><?php echo htmlspecialchars($customer['phone']); ?></td>
                            <td><?php echo $customer['visit_count']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="no-data">No visit data available.</p>
        <?php endif; ?>
    </div>

    <div class="report-section">
        <h2>Monthly Visit Trends (Last 12 Months)</h2>
        <?php if (count($monthly_trends) > 0): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Visit Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthly_trends as $trend): ?>
                        <tr>
                            <td><?php echo date('F Y', strtotime($trend['month'] . '-01')); ?></td>
                            <td><?php echo $trend['count']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="no-data">No visit data available.</p>
        <?php endif; ?>
    </div>

    <div class="report-section">
        <h2>Voucher Statistics</h2>
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="ticket"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($total_vouchers); ?></h3>
                    <p>Total Vouchers</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="checkmark-circle"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($active_vouchers); ?></h3>
                    <p>Active Vouchers</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="cash"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($redeemed_vouchers); ?></h3>
                    <p>Redeemed Vouchers</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="dollar"></ion-icon></div>
                <div class="stat-info">
                    <h3>$<?php echo number_format($total_redeemed_amount, 2); ?></h3>
                    <p>Total Redeemed Value</p>
                </div>
            </div>
        </div>

        <div class="search-box" style="margin-bottom: 2rem;">
            <form method="GET" action="" class="filter-form">
                <input type="hidden" name="voucher_search" value="">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="voucher_search">Search Vouchers</label>
                        <input type="text" name="voucher_search" id="voucher_search" placeholder="Voucher code, customer name, phone, or household name..." value="<?php echo htmlspecialchars($filter_search); ?>" class="search-input">
                    </div>
                    
                    <div class="filter-group">
                        <label for="voucher_issued_date">Issued On Date</label>
                        <input type="date" name="voucher_issued_date" id="voucher_issued_date" value="<?php echo htmlspecialchars($filter_issued_date); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="voucher_redeemed_date">Redeemed On Date</label>
                        <input type="date" name="voucher_redeemed_date" id="voucher_redeemed_date" value="<?php echo htmlspecialchars($filter_redeemed_date); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="voucher_status">Status</label>
                        <select name="voucher_status" id="voucher_status">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="redeemed" <?php echo $filter_status === 'redeemed' ? 'selected' : ''; ?>>Redeemed</option>
                            <option value="expired" <?php echo $filter_status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <?php if (!empty($filter_search) || !empty($filter_issued_date) || !empty($filter_redeemed_date) || !empty($filter_status)): ?>
                                <a href="reports.php" class="btn btn-secondary">Clear</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <h3>Vouchers</h3>
        <?php if (count($recent_vouchers) > 0): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Voucher Code</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Issued Date</th>
                        <th>Status</th>
                        <th>Redeemed Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_vouchers as $voucher): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($voucher['voucher_code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($voucher['customer_name']); ?></td>
                            <td>$<?php echo number_format($voucher['amount'], 2); ?></td>
                            <td><?php echo date('M d, Y g:i A', strtotime($voucher['issued_date'])); ?></td>
                            <td>
                                <?php if ($voucher['status'] === 'active'): ?>
                                    <span style="color: green;">Active</span>
                                <?php elseif ($voucher['status'] === 'redeemed'): ?>
                                    <span style="color: blue;">Redeemed</span>
                                <?php else: ?>
                                    <span style="color: red;">Expired</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($voucher['redeemed_date']): ?>
                                    <?php echo date('M d, Y g:i A', strtotime($voucher['redeemed_date'])); ?>
                                <?php elseif ($voucher['status'] === 'active'): ?>
                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to revoke this voucher? This action cannot be undone.');">
                                        <input type="hidden" name="voucher_code" value="<?php echo htmlspecialchars($voucher['voucher_code']); ?>">
                                        <button type="submit" name="revoke_voucher" class="btn btn-small" style="background-color: #d32f2f; color: white;">Revoke</button>
                                    </form>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="no-data">No vouchers have been issued yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>


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

// Get dashboard statistics (moved from index.php)
$current_month = date('Y-m');
$dashboard_stats = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM customers) as total_customers,
        (SELECT COUNT(*) FROM customers WHERE DATE(signup_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as recent_customers,
        (SELECT COUNT(*) FROM visits WHERE visit_type = 'food' AND DATE_FORMAT(visit_date, '%Y-%m') = '$current_month' AND (is_invalid = 0 OR is_invalid IS NULL)) as food_visits_month,
        (SELECT COUNT(*) FROM visits WHERE visit_type = 'money' AND DATE_FORMAT(visit_date, '%Y-%m') = '$current_month' AND (is_invalid = 0 OR is_invalid IS NULL)) as money_visits_month,
        (SELECT COUNT(*) FROM visits WHERE visit_type = 'voucher' AND DATE_FORMAT(visit_date, '%Y-%m') = '$current_month' AND (is_invalid = 0 OR is_invalid IS NULL)) as voucher_visits_month
")->fetch();

// Get filter parameters
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_visit_type = $_GET['visit_type'] ?? '';

// Build date filter for queries
$date_filter = "";
$date_params = [];
if (!empty($filter_date_from) && !empty($filter_date_to)) {
    $date_filter = " AND DATE(visit_date) BETWEEN ? AND ?";
    $date_params = [$filter_date_from, $filter_date_to];
} elseif (!empty($filter_date_from)) {
    $date_filter = " AND DATE(visit_date) >= ?";
    $date_params = [$filter_date_from];
} elseif (!empty($filter_date_to)) {
    $date_filter = " AND DATE(visit_date) <= ?";
    $date_params = [$filter_date_to];
}

// ===== CUSTOMER STATISTICS =====
$stmt = $db->query("SELECT COUNT(*) as total FROM customers");
$total_customers = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM customers WHERE signup_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$customers_30_days = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM customers WHERE signup_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$customers_7_days = $stmt->fetch()['total'];

// ===== VISIT STATISTICS (ALL TYPES) =====
$visit_date_filter = $date_filter;
$visit_params = $date_params;

$stmt = $db->prepare("SELECT COUNT(*) as total FROM visits WHERE (is_invalid = 0 OR is_invalid IS NULL)" . $visit_date_filter);
$stmt->execute($visit_params);
$total_visits = $stmt->fetch()['total'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM visits WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND (is_invalid = 0 OR is_invalid IS NULL)" . $visit_date_filter);
$stmt->execute($visit_params);
$visits_30_days = $stmt->fetch()['total'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM visits WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND (is_invalid = 0 OR is_invalid IS NULL)" . $visit_date_filter);
$stmt->execute($visit_params);
$visits_7_days = $stmt->fetch()['total'];

// Monthly visit trends (excluding invalid)
$trend_date_filter = $date_filter;
$trend_params = $date_params;
$stmt = $db->prepare("SELECT DATE_FORMAT(visit_date, '%Y-%m') as month, COUNT(*) as count 
                   FROM visits 
                   WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND (is_invalid = 0 OR is_invalid IS NULL)" . $trend_date_filter . "
                   GROUP BY DATE_FORMAT(visit_date, '%Y-%m')
                   ORDER BY month");
$stmt->execute($trend_params);
$monthly_trends = $stmt->fetchAll();

// ===== FOOD VISIT STATISTICS =====
$food_date_filter = $date_filter;
$food_params = $date_params;
if (!empty($filter_visit_type) && $filter_visit_type !== 'food' && $filter_visit_type !== 'all') {
    $food_date_filter = " AND 1=0"; // Exclude if filtering for other types
    $food_params = [];
}

$stmt = $db->prepare("SELECT COUNT(*) as total FROM visits WHERE visit_type = 'food' AND (is_invalid = 0 OR is_invalid IS NULL)" . $food_date_filter);
$stmt->execute($food_params);
$total_food_visits = $stmt->fetch()['total'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM visits WHERE visit_type = 'food' AND visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND (is_invalid = 0 OR is_invalid IS NULL)" . $food_date_filter);
$stmt->execute($food_params);
$food_visits_30_days = $stmt->fetch()['total'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM visits WHERE visit_type = 'food' AND visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND (is_invalid = 0 OR is_invalid IS NULL)" . $food_date_filter);
$stmt->execute($food_params);
$food_visits_7_days = $stmt->fetch()['total'];

// ===== MONEY VISIT STATISTICS =====
$money_date_filter = $date_filter;
$money_params = $date_params;
if (!empty($filter_visit_type) && $filter_visit_type !== 'money' && $filter_visit_type !== 'all') {
    $money_date_filter = " AND 1=0";
    $money_params = [];
}

$stmt = $db->prepare("SELECT COUNT(*) as total, COALESCE(SUM(amount), 0) as total_amount FROM visits WHERE visit_type = 'money' AND (is_invalid = 0 OR is_invalid IS NULL)" . $money_date_filter);
$stmt->execute($money_params);
$money_result = $stmt->fetch();
$total_money_visits = $money_result['total'];
$total_money_amount = $money_result['total_amount'] ?? 0;

$stmt = $db->prepare("SELECT COUNT(*) as total, COALESCE(SUM(amount), 0) as total_amount FROM visits WHERE visit_type = 'money' AND visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND (is_invalid = 0 OR is_invalid IS NULL)" . $money_date_filter);
$stmt->execute($money_params);
$money_30_result = $stmt->fetch();
$money_visits_30_days = $money_30_result['total'];
$money_amount_30_days = $money_30_result['total_amount'] ?? 0;

$stmt = $db->prepare("SELECT COUNT(*) as total, COALESCE(SUM(amount), 0) as total_amount FROM visits WHERE visit_type = 'money' AND visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND (is_invalid = 0 OR is_invalid IS NULL)" . $money_date_filter);
$stmt->execute($money_params);
$money_7_result = $stmt->fetch();
$money_visits_7_days = $money_7_result['total'];
$money_amount_7_days = $money_7_result['total_amount'] ?? 0;

// ===== VOUCHER STATISTICS =====
$voucher_date_filter = "";
$voucher_params = [];
if (!empty($filter_date_from) && !empty($filter_date_to)) {
    $voucher_date_filter = " AND DATE(v.issued_date) BETWEEN ? AND ?";
    $voucher_params = [$filter_date_from, $filter_date_to];
} elseif (!empty($filter_date_from)) {
    $voucher_date_filter = " AND DATE(v.issued_date) >= ?";
    $voucher_params = [$filter_date_from];
} elseif (!empty($filter_date_to)) {
    $voucher_date_filter = " AND DATE(v.issued_date) <= ?";
    $voucher_params = [$filter_date_to];
}

$stmt = $db->prepare("SELECT COUNT(*) as total FROM vouchers v WHERE 1=1" . $voucher_date_filter);
$stmt->execute($voucher_params);
$total_vouchers = $stmt->fetch()['total'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM vouchers v WHERE status = 'active'" . $voucher_date_filter);
$stmt->execute($voucher_params);
$active_vouchers = $stmt->fetch()['total'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM vouchers v WHERE status = 'redeemed'" . $voucher_date_filter);
$stmt->execute($voucher_params);
$redeemed_vouchers = $stmt->fetch()['total'];

$stmt = $db->prepare("SELECT SUM(amount) as total FROM vouchers v WHERE status = 'redeemed'" . $voucher_date_filter);
$stmt->execute($voucher_params);
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

// Apply date range filter to vouchers
if (!empty($voucher_date_filter)) {
    $where_conditions[] = substr($voucher_date_filter, 5); // Remove " AND " prefix
    $params = array_merge($params, $voucher_params);
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
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <!-- Dashboard Overview Statistics -->
    <div class="report-section">
        <h2>Overview</h2>
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="people"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($dashboard_stats['total_customers']); ?></h3>
                    <p>Total <?php echo htmlspecialchars(getCustomerTermPlural('Customers')); ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="calendar"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($dashboard_stats['recent_customers']); ?></h3>
                    <p>New (Last 30 Days)</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="restaurant"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($dashboard_stats['food_visits_month']); ?></h3>
                    <p>Food Visits This Month</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="cash"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($dashboard_stats['money_visits_month']); ?></h3>
                    <p>Money Visits This Month</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="ticket"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($dashboard_stats['voucher_visits_month']); ?></h3>
                    <p>Voucher Visits This Month</p>
                </div>
            </div>
        </div>
    </div>

    <!-- CUSTOMER STATISTICS -->
    <div class="report-section">
        <h2>Customer Statistics</h2>
        <div class="stats-grid" style="margin-bottom: 2rem;">
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
                    <h3><?php echo number_format($customers_30_days); ?></h3>
                    <p>New Customers (Last 30 Days)</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="stats-chart"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($customers_7_days); ?></h3>
                    <p>New Customers (Last 7 Days)</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Global Date Range Filter -->
    <div class="report-section" style="margin-bottom: 2rem;">
        <h2>Date Range Filter</h2>
        <form method="GET" action="" class="filter-form">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="date_from">From Date</label>
                    <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>
                <div class="filter-group">
                    <label for="date_to">To Date</label>
                    <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>
                <div class="filter-group">
                    <label for="visit_type">Visit Type</label>
                    <select name="visit_type" id="visit_type">
                        <option value="all" <?php echo $filter_visit_type === 'all' || empty($filter_visit_type) ? 'selected' : ''; ?>>All Types</option>
                        <option value="food" <?php echo $filter_visit_type === 'food' ? 'selected' : ''; ?>>Food</option>
                        <option value="money" <?php echo $filter_visit_type === 'money' ? 'selected' : ''; ?>>Money</option>
                        <option value="voucher" <?php echo $filter_visit_type === 'voucher' ? 'selected' : ''; ?>>Voucher</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        <?php if (!empty($filter_date_from) || !empty($filter_date_to) || !empty($filter_visit_type)): ?>
                            <a href="reports.php" class="btn btn-secondary">Clear</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- VISIT STATISTICS (ALL TYPES) -->
    <div class="report-section">
        <h2>Visit Statistics (All Types)</h2>
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="calendar"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($total_visits); ?></h3>
                    <p>Total Visits<?php echo !empty($filter_date_from) || !empty($filter_date_to) ? ' (Filtered)' : ''; ?></p>
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

        <h3>Monthly Visit Trends (Last 12 Months)</h3>
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

    <!-- FOOD VISIT STATISTICS -->
    <div class="report-section">
        <h2>Food Visit Statistics</h2>
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="restaurant"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($total_food_visits); ?></h3>
                    <p>Total Food Visits<?php echo !empty($filter_date_from) || !empty($filter_date_to) ? ' (Filtered)' : ''; ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="calendar"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($food_visits_30_days); ?></h3>
                    <p>Food Visits (Last 30 Days)</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="stats-chart"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($food_visits_7_days); ?></h3>
                    <p>Food Visits (Last 7 Days)</p>
                </div>
            </div>
        </div>
    </div>

    <!-- MONEY VISIT STATISTICS -->
    <div class="report-section">
        <h2>Money Visit Statistics</h2>
        <div style="margin-bottom: 1rem;">
            <form method="GET" action="money_export.php" style="display: inline-block;">
                <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                    <div class="form-group" style="margin: 0;">
                        <label for="export_date_from" style="display: block; margin-bottom: 0.25rem; font-size: 0.9rem;">From Date (Optional)</label>
                        <input type="date" name="date_from" id="export_date_from" style="padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;">
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label for="export_date_to" style="display: block; margin-bottom: 0.25rem; font-size: 0.9rem;">To Date (Optional)</label>
                        <input type="date" name="date_to" id="export_date_to" style="padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;">
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <button type="submit" class="btn btn-primary">Export Money Visits to CSV</button>
                    </div>
                </div>
                <small style="display: block; margin-top: 0.5rem; color: var(--text-color-muted);">Leave dates empty to export all money visits</small>
            </form>
        </div>
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="cash"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($total_money_visits); ?></h3>
                    <p>Total Money Visits<?php echo !empty($filter_date_from) || !empty($filter_date_to) ? ' (Filtered)' : ''; ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="dollar"></ion-icon></div>
                <div class="stat-info">
                    <h3>$<?php echo number_format($total_money_amount, 2); ?></h3>
                    <p>Total Money Distributed<?php echo !empty($filter_date_from) || !empty($filter_date_to) ? ' (Filtered)' : ''; ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="calendar"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($money_visits_30_days); ?></h3>
                    <p>Money Visits (Last 30 Days)</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="dollar"></ion-icon></div>
                <div class="stat-info">
                    <h3>$<?php echo number_format($money_amount_30_days, 2); ?></h3>
                    <p>Money Distributed (Last 30 Days)</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="stats-chart"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($money_visits_7_days); ?></h3>
                    <p>Money Visits (Last 7 Days)</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="dollar"></ion-icon></div>
                <div class="stat-info">
                    <h3>$<?php echo number_format($money_amount_7_days, 2); ?></h3>
                    <p>Money Distributed (Last 7 Days)</p>
                </div>
            </div>
        </div>
    </div>

    <!-- VOUCHER STATISTICS -->
    <div class="report-section">
        <h2>Voucher Statistics</h2>
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-card">
                <div class="stat-icon"><ion-icon name="ticket"></ion-icon></div>
                <div class="stat-info">
                    <h3><?php echo number_format($total_vouchers); ?></h3>
                    <p>Total Vouchers<?php echo !empty($filter_date_from) || !empty($filter_date_to) ? ' (Filtered)' : ''; ?></p>
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
                <?php if (!empty($filter_date_from)): ?>
                    <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                <?php endif; ?>
                <?php if (!empty($filter_date_to)): ?>
                    <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                <?php endif; ?>
                <?php if (!empty($filter_visit_type)): ?>
                    <input type="hidden" name="visit_type" value="<?php echo htmlspecialchars($filter_visit_type); ?>">
                <?php endif; ?>
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
                                <a href="reports.php<?php echo !empty($filter_date_from) || !empty($filter_date_to) || !empty($filter_visit_type) ? '?date_from=' . urlencode($filter_date_from) . '&date_to=' . urlencode($filter_date_to) . '&visit_type=' . urlencode($filter_visit_type) : ''; ?>" class="btn btn-secondary">Clear Voucher Filters</a>
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

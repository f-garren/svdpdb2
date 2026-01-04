<?php
require_once 'config.php';
require_once 'auth.php';

requireLogin();

// Get statistics
$db = getDB();
$current_month = date('Y-m');

// Get all statistics in fewer queries (excluding invalid visits)
$stats = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM customers) as total_customers,
        (SELECT COUNT(*) FROM customers WHERE DATE(signup_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as recent_customers,
        (SELECT COUNT(*) FROM visits WHERE visit_type = 'food' AND DATE_FORMAT(visit_date, '%Y-%m') = '$current_month' AND (is_invalid = 0 OR is_invalid IS NULL)) as food_visits_month,
        (SELECT COUNT(*) FROM visits WHERE visit_type = 'money' AND DATE_FORMAT(visit_date, '%Y-%m') = '$current_month' AND (is_invalid = 0 OR is_invalid IS NULL)) as money_visits_month,
        (SELECT COUNT(*) FROM visits WHERE visit_type = 'voucher' AND DATE_FORMAT(visit_date, '%Y-%m') = '$current_month' AND (is_invalid = 0 OR is_invalid IS NULL)) as voucher_visits_month
")->fetch();

$total_customers = $stats['total_customers'];
$recent_customers = $stats['recent_customers'];
$food_visits_month = $stats['food_visits_month'];
$money_visits_month = $stats['money_visits_month'];
$voucher_visits_month = $stats['voucher_visits_month'];

$page_title = "Dashboard";
include 'header.php';
?>

<div class="container">
    <div class="page-header">
        <h1><?php echo htmlspecialchars(getSetting('organization_name', 'NexusDB')); ?> Dashboard</h1>
        <p class="lead">Food Distribution Service Management System</p>
    </div>

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
                <h3><?php echo number_format($recent_customers); ?></h3>
                <p>New (Last 30 Days)</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon"><ion-icon name="restaurant"></ion-icon></div>
            <div class="stat-info">
                <h3><?php echo number_format($food_visits_month); ?></h3>
                <p>Food Visits This Month</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon"><ion-icon name="cash"></ion-icon></div>
            <div class="stat-info">
                <h3><?php echo number_format($money_visits_month); ?></h3>
                <p>Money Visits This Month</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon"><ion-icon name="ticket"></ion-icon></div>
            <div class="stat-info">
                <h3><?php echo number_format($voucher_visits_month); ?></h3>
                <p>Voucher Visits This Month</p>
            </div>
        </div>
    </div>

    <div class="action-buttons">
        <a href="signup.php" class="btn btn-primary btn-large">
            <span class="btn-icon"><ion-icon name="add"></ion-icon></span>
            <span>New <?php echo htmlspecialchars(getCustomerTerm('Customer')); ?> Signup</span>
        </a>
        <a href="customers.php" class="btn btn-secondary btn-large">
            <span class="btn-icon"><ion-icon name="search"></ion-icon></span>
            <span>Search <?php echo htmlspecialchars(getCustomerTermPlural('Customers')); ?></span>
        </a>
        <a href="visits_food.php" class="btn btn-secondary btn-large">
            <span class="btn-icon"><ion-icon name="restaurant"></ion-icon></span>
            <span>Record Food Visit</span>
        </a>
        <a href="reports.php" class="btn btn-secondary btn-large">
            <span class="btn-icon"><ion-icon name="stats-chart"></ion-icon></span>
            <span>Reports</span>
        </a>
    </div>

    <div class="recent-section">
        <h2>Recent <?php echo htmlspecialchars(getCustomerTermPlural('Customers')); ?></h2>
        <?php
        $recent_customers_list = $db->query("SELECT c.*, 
                           (SELECT COUNT(*) FROM visits WHERE customer_id = c.id AND (is_invalid = 0 OR is_invalid IS NULL)) as visit_count
                           FROM customers c 
                           ORDER BY c.created_at DESC 
                           LIMIT 10")->fetchAll();
        
        if (count($recent_customers_list) > 0) {
            echo '<table class="data-table">';
            echo '<thead><tr><th>Name</th><th>Phone</th><th>Signup Date</th><th>City</th><th>Visits</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            foreach ($recent_customers_list as $customer) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($customer['name']) . '</td>';
                echo '<td>' . htmlspecialchars($customer['phone']) . '</td>';
                echo '<td>' . date('M d, Y g:i A', strtotime($customer['signup_date'])) . '</td>';
                echo '<td>' . htmlspecialchars($customer['city']) . '</td>';
                echo '<td>' . $customer['visit_count'] . '</td>';
                echo '<td><a href="customer_view.php?id=' . $customer['id'] . '" class="btn btn-small">View</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p class="no-data">No ' . strtolower(getCustomerTermPlural('customers')) . ' yet. <a href="signup.php">Add ' . htmlspecialchars(getCustomerTerm('Customer')) . '</a></p>';
        }
        ?>
    </div>
</div>

<?php include 'footer.php'; ?>


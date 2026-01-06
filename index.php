<?php
require_once 'config.php';
require_once 'auth.php';

requireLogin();

$db = getDB();
$page_title = "Dashboard";
include 'header.php';
?>

<div class="container">
    <div class="page-header">
        <h1><?php echo htmlspecialchars(getSetting('organization_name', 'NexusDB')); ?> Dashboard</h1>
    </div>

    <div class="action-buttons" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <a href="signup.php" class="btn btn-primary btn-large" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem; text-align: center;">
            <span class="btn-icon" style="font-size: 2.5rem; margin-bottom: 0.5rem;"><ion-icon name="add"></ion-icon></span>
            <span>New <?php echo htmlspecialchars(getCustomerTerm('Customer')); ?> Signup</span>
        </a>
        <a href="customers.php" class="btn btn-secondary btn-large" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem; text-align: center;">
            <span class="btn-icon" style="font-size: 2.5rem; margin-bottom: 0.5rem;"><ion-icon name="search"></ion-icon></span>
            <span>Search <?php echo htmlspecialchars(getCustomerTermPlural('Customers')); ?></span>
        </a>
        <?php if (hasPermission('food_visit') || isAdmin()): ?>
        <a href="visits_food.php" class="btn btn-secondary btn-large" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem; text-align: center;">
            <span class="btn-icon" style="font-size: 2.5rem; margin-bottom: 0.5rem;"><ion-icon name="restaurant"></ion-icon></span>
            <span>Record Food Visit</span>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('money_visit') || isAdmin()): ?>
        <a href="visits_money.php" class="btn btn-secondary btn-large" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem; text-align: center;">
            <span class="btn-icon" style="font-size: 2.5rem; margin-bottom: 0.5rem;"><ion-icon name="cash"></ion-icon></span>
            <span>Record Money Visit</span>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('voucher_create') || isAdmin()): ?>
        <a href="visits_voucher.php" class="btn btn-secondary btn-large" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem; text-align: center;">
            <span class="btn-icon" style="font-size: 2.5rem; margin-bottom: 0.5rem;"><ion-icon name="ticket"></ion-icon></span>
            <span>Record Voucher Visit</span>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('report_access') || isAdmin()): ?>
        <a href="reports.php" class="btn btn-secondary btn-large" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem; text-align: center;">
            <span class="btn-icon" style="font-size: 2.5rem; margin-bottom: 0.5rem;"><ion-icon name="stats-chart"></ion-icon></span>
            <span>Reports</span>
        </a>
        <?php endif; ?>
    </div>

    <div class="recent-section">
        <h2>Recent <?php echo htmlspecialchars(getCustomerTermPlural('Customers')); ?></h2>
        <?php
        $recent_customers_list = $db->query("SELECT c.*, 
                           (SELECT COUNT(*) FROM visits WHERE customer_id = c.id AND (is_invalid = 0 OR is_invalid IS NULL)) as visit_count
                           FROM customers c 
                           ORDER BY c.created_at DESC 
                           LIMIT 15")->fetchAll();
        
        if (count($recent_customers_list) > 0) {
            echo '<table class="data-table">';
            echo '<thead><tr><th>Name</th><th>Phone</th><th>Signup Date</th><th>Visits</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            foreach ($recent_customers_list as $customer) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($customer['name']) . '</td>';
                echo '<td>' . htmlspecialchars($customer['phone']) . '</td>';
                echo '<td>' . date('M d, Y g:i A', strtotime($customer['signup_date'])) . '</td>';
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


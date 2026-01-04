<?php
require_once 'config.php';
require_once 'auth.php';

requirePermission('money_visit');

$error = '';
$success = '';
$show_confirmation = false;
$form_data = [];
$customer_id = $_GET['customer_id'] ?? 0;
$db = getDB();

// Get money limit
$money_limit = intval(getSetting('money_distribution_limit', 3));

// Get customer if ID provided
$customer = null;
if ($customer_id) {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
}

// Handle confirmation submission (final save)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_submit'])) {
    $p = $_POST;
    $customer_id = intval($p['customer_id']);
    $visit_type = 'money'; // Hardcoded for money visits
    
    try {
        // Validate customer exists
        $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        
        if (!$customer) {
            throw new Exception("Customer not found");
        }
        
        $visit_date = getVisitDate($p);
        $household_customer_ids = getHouseholdCustomerIds($db, $customer_id);
        
        // Check limits using helper functions
        $money_limit = intval(getSetting('money_distribution_limit', 3));
        $total_check = checkVisitLimit($db, $household_customer_ids, 'money', 'total', $money_limit, $visit_date);
        if (!$total_check['allowed']) {
            throw new Exception("Money assistance limit reached. This household has received money assistance {$total_check['count']} times (limit: " . formatLimitText($total_check['limit']) . " times total).");
        }
        
        $money_limit_month = intval(getSetting('money_distribution_limit_month', -1));
        $month_check = checkVisitLimit($db, $household_customer_ids, 'money', 'month', $money_limit_month, $visit_date);
        if (!$month_check['allowed']) {
            throw new Exception("Monthly money assistance limit reached. This household has received money assistance {$month_check['count']} times this month (limit: " . formatLimitText($month_check['limit']) . ").");
        }
        
        $money_limit_year = intval(getSetting('money_distribution_limit_year', -1));
        $year_check = checkVisitLimit($db, $household_customer_ids, 'money', 'year', $money_limit_year, $visit_date);
        if (!$year_check['allowed']) {
            throw new Exception("Yearly money assistance limit reached. This household has received money assistance {$year_check['count']} times this year (limit: " . formatLimitText($year_check['limit']) . ").");
        }
        
        $money_min_days_between = intval(getSetting('money_min_days_between', -1));
        $days_check = checkMinDaysBetween($db, $household_customer_ids, 'money', $money_min_days_between, $visit_date);
        if (!$days_check['allowed']) {
            throw new Exception("Minimum {$days_check['min_days']} days required between money visits. Last money visit was {$days_check['days_since']} days ago.");
        }
        
        // Validate and get amount
        if (empty($p['amount'])) {
            throw new Exception("Amount is required for money visits");
        }
        $amount = floatval($p['amount']);
        if ($amount <= 0) {
            throw new Exception("Amount must be greater than 0");
        }
        
        // Insert visit
        $notes = $p['notes'] ?? '';
        $stmt = $db->prepare("INSERT INTO visits (customer_id, visit_date, visit_type, amount, notes) VALUES (?, ?, 'money', ?, ?)");
        $stmt->execute([$customer_id, $visit_date, $amount, $notes]);
        
        $success = "Money visit recorded successfully! Amount: $" . number_format($amount, 2) . " <a href='customer_view.php?id=" . $customer_id . "'>View customer</a>";
        
        // Clear customer selection
        $customer = null;
        $customer_id = 0;
        $show_confirmation = false;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle initial form submission - show confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['confirm_submit'])) {
    $form_data = $_POST;
    $customer_id = intval($_POST['customer_id']);
    
    // Get customer for confirmation screen
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    
    if ($customer) {
        $show_confirmation = true;
    }
}

$page_title = "Record Money Visit";
include 'header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>Record Money Visit</h1>
        <p class="lead">Track money assistance with automatic limit checking</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <?php if ($show_confirmation && $customer): ?>
        <!-- Confirmation Screen -->
        <div class="confirmation-screen">
            <h2>Confirm Visit</h2>
            <p class="lead">Please review the visit information before submitting.</p>
            
            <div class="confirmation-summary">
                <h3>Visit Summary</h3>
                <table class="info-table">
                    <tr><th>Customer:</th><td><?php echo htmlspecialchars($customer['name']); ?></td></tr>
                    <tr><th>Phone:</th><td><?php echo htmlspecialchars($customer['phone']); ?></td></tr>
                    <tr><th>Visit Type:</th><td><strong>Money</strong></td></tr>
                    <?php if (!empty($form_data['amount'])): ?>
                    <tr><th>Amount:</th><td>$<?php echo number_format(floatval($form_data['amount']), 2); ?></td></tr>
                    <?php endif; ?>
                    <tr><th>Visit Date & Time:</th><td><?php 
                        if (!empty($form_data['override_visit_date']) && !empty($form_data['manual_visit_datetime'])) {
                            echo date('F d, Y \a\t g:i A', strtotime($form_data['manual_visit_datetime']));
                        } else {
                            echo date('F d, Y \a\t g:i A');
                        }
                    ?></td></tr>
                    <?php if (!empty($form_data['notes'])): ?>
                    <tr><th>Notes:</th><td><?php echo nl2br(htmlspecialchars($form_data['notes'])); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            
            <!-- Hidden form to preserve data -->
            <form method="POST" action="" style="display: none;" id="confirm_form">
                <?php echo generateHiddenFields($form_data); ?>
                <input type="hidden" name="confirm_submit" value="1">
            </form>
            
            <div class="form-actions">
                <button type="button" onclick="document.getElementById('confirm_form').submit();" class="btn btn-primary btn-large">Confirm and Submit</button>
                <button type="button" onclick="window.location.href='visits_money.php';" class="btn btn-secondary btn-large">Cancel / Edit</button>
            </div>
        </div>
    <?php else: ?>
        <!-- Visit Form -->
        <div class="visit-limits-info">
            <h3>Money Visit Limits</h3>
            <ul>
                <li>Maximum <?php echo $money_limit; ?> times per household (all time)</li>
            </ul>
        </div>

        <form method="POST" action="" class="visit-form">
            <div class="form-group" style="position: relative;">
                <label for="customer_search">Search <?php echo htmlspecialchars(getCustomerTerm('Customer')); ?> <span class="required">*</span></label>
                <input type="text" id="customer_search" placeholder="Type <?php echo strtolower(getCustomerTerm('customer')); ?> name or phone..." autocomplete="off" value="<?php echo $customer ? htmlspecialchars($customer['name']) : ''; ?>">
                <input type="hidden" id="customer_id" name="customer_id" value="<?php echo $customer ? $customer['id'] : ''; ?>" required>
                <div id="customer_results" class="search-results"></div>
            </div>
            
            <?php if ($customer): ?>
                <div class="selected-customer" id="customer_info">
                    <strong>Selected:</strong> <?php echo htmlspecialchars($customer['name']); ?> 
                    (<?php echo htmlspecialchars($customer['phone']); ?>)
                    <div id="eligibility_errors" style="margin-top: 0.5rem;"></div>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="amount">Amount ($) <span class="required">*</span></label>
                <input type="number" id="amount" name="amount" step="0.01" min="0.01" placeholder="0.00" required>
                <small class="help-text">Enter the money distribution amount</small>
            </div>

            <div class="form-group">
                <label for="visit_date">Visit Date & Time</label>
                <div class="checkbox-group">
                    <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                        <input type="checkbox" id="override_visit_date" name="override_visit_date" value="1">
                        <span>Override automatic date/time</span>
                    </label>
                </div>
                <div id="auto_visit_datetime">
                    <input type="text" value="<?php echo date('F d, Y \a\t g:i A'); ?>" readonly class="readonly-field">
                    <small class="help-text">Automatically recorded from system time</small>
                </div>
                <div id="manual_visit_datetime" style="display: none;">
                    <input type="datetime-local" id="manual_visit_datetime_input" name="manual_visit_datetime" value="<?php echo date('Y-m-d\TH:i'); ?>" class="datetime-input" tabindex="-1">
                    <small class="help-text">Enter the actual visit date and time</small>
                </div>
                <input type="hidden" name="visit_date" value="<?php echo date('Y-m-d H:i:s'); ?>">
            </div>

            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="4" placeholder="Optional notes about this visit..."></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-large">Review & Submit</button>
                <a href="index.php" class="btn btn-secondary btn-large">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<script src="js/customer_search.js"></script>
<script>
initCustomerSearch('customer_search', 'customer_id', 'customer_results', 'money', 'eligibility_errors');
initDateTimeOverride('override_visit_date', 'auto_visit_datetime', 'manual_visit_datetime', 'manual_visit_datetime_input');

<?php if ($customer): ?>
checkEligibility(<?php echo $customer['id']; ?>, 'money', 'eligibility_errors');
<?php endif; ?>
</script>

<?php include 'footer.php'; ?>

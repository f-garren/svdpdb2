<?php
require_once 'config.php';
require_once 'auth.php';

requirePermission('voucher_create');

$error = '';
$success = '';
$show_confirmation = false;
$form_data = [];
$customer_id = $_GET['customer_id'] ?? 0;
$db = getDB();

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
    $visit_type = 'voucher'; // Hardcoded for voucher visits
    
    try {
        // Validate customer exists
        $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        
        if (!$customer) {
            throw new Exception("Customer not found");
        }
        
        $visit_date = date('Y-m-d H:i:s'); // Vouchers use current time only
        $customer_ids = [$customer_id];
        
        // Check limits using helper functions
        $voucher_limit_month = intval(getSetting('voucher_limit_month', -1));
        $month_check = checkVisitLimit($db, $customer_ids, 'voucher', 'month', $voucher_limit_month, $visit_date);
        if (!$month_check['allowed']) {
            throw new Exception("Monthly voucher limit reached. This customer has {$month_check['count']} voucher visits this month (limit: " . formatLimitText($month_check['limit']) . ").");
        }
        
        $voucher_limit_year = intval(getSetting('voucher_limit_year', -1));
        $year_check = checkVisitLimit($db, $customer_ids, 'voucher', 'year', $voucher_limit_year, $visit_date);
        if (!$year_check['allowed']) {
            throw new Exception("Yearly voucher limit reached. This customer has {$year_check['count']} voucher visits this year (limit: " . formatLimitText($year_check['limit']) . ").");
        }
        
        $voucher_min_days_between = intval(getSetting('voucher_min_days_between', -1));
        $days_check = checkMinDaysBetween($db, $customer_ids, 'voucher', $voucher_min_days_between, $visit_date);
        if (!$days_check['allowed']) {
            throw new Exception("Minimum {$days_check['min_days']} days required between voucher visits. Last voucher visit was {$days_check['days_since']} days ago.");
        }
        
        // Voucher creation - create voucher record
        if (empty($p['voucher_amount'])) {
            throw new Exception("Voucher amount is required");
        }
        $voucher_amount = floatval($p['voucher_amount']);
        if ($voucher_amount <= 0) {
            throw new Exception("Voucher amount must be greater than 0");
        }
        
        // Generate unique voucher code
        $voucher_prefix = getSetting('voucher_prefix', 'VCH-');
        $voucher_code = $voucher_prefix . strtoupper(substr(md5(time() . $customer_id . rand()), 0, 8));
        
        // Check if code exists (unlikely but possible)
        $stmt = $db->prepare("SELECT id FROM vouchers WHERE voucher_code = ?");
        $stmt->execute([$voucher_code]);
        while ($stmt->fetch()) {
            $voucher_code = $voucher_prefix . strtoupper(substr(md5(time() . $customer_id . rand()), 0, 8));
            $stmt->execute([$voucher_code]);
        }
        
        // Insert visit
        $notes = $p['notes'] ?? '';
        $stmt = $db->prepare("INSERT INTO visits (customer_id, visit_date, visit_type, notes) VALUES (?, ?, 'voucher', ?)");
        $stmt->execute([$customer_id, $visit_date, $notes]);
        $visit_id = $db->lastInsertId();
        
        // Create voucher record
        $expiry_date = !empty($p['voucher_expiry_date']) ? date('Y-m-d', strtotime($p['voucher_expiry_date'])) : null;
        $stmt = $db->prepare("INSERT INTO vouchers (voucher_code, customer_id, amount, issued_date, expiry_date, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$voucher_code, $customer_id, $voucher_amount, $visit_date, $expiry_date, $notes]);
        $voucher_id = $db->lastInsertId();
        
        // Log audit
        logEmployeeAction($db, getCurrentEmployeeId(), 'voucher_create', 'voucher', $voucher_id, "Created voucher {$voucher_code} for customer ID {$customer_id}, amount: $" . number_format($voucher_amount, 2));
        logEmployeeAction($db, getCurrentEmployeeId(), 'visit_create', 'visit', $visit_id, "Created voucher visit for customer ID {$customer_id}");
        
        $success = "Voucher created successfully! Voucher Code: <strong>{$voucher_code}</strong> - Amount: $" . number_format($voucher_amount, 2) . " <a href='customer_view.php?id=" . $customer_id . "'>View customer</a>";
        
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

$page_title = "Create Voucher";
include 'header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>Create Voucher</h1>
        <p class="lead">Create a new voucher for a customer</p>
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
                    <tr><th>Visit Type:</th><td><strong>Voucher</strong></td></tr>
                    <tr><th>Visit Date & Time:</th><td><?php echo date('F d, Y \a\t g:i A'); ?></td></tr>
                    <?php if (!empty($form_data['voucher_amount'])): ?>
                    <tr><th>Voucher Amount:</th><td>$<?php echo number_format(floatval($form_data['voucher_amount']), 2); ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($form_data['voucher_expiry_date'])): ?>
                    <tr><th>Expiration Date:</th><td><?php echo date('F d, Y', strtotime($form_data['voucher_expiry_date'])); ?></td></tr>
                    <?php endif; ?>
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
                <button type="button" onclick="window.location.href='visits_voucher.php';" class="btn btn-secondary btn-large">Cancel / Edit</button>
            </div>
        </div>
    <?php else: ?>
        <!-- Voucher Form -->
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
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="voucher_amount">Voucher Amount ($) <span class="required">*</span></label>
                <input type="number" id="voucher_amount" name="voucher_amount" step="0.01" min="0.01" placeholder="0.00" required>
                <small class="help-text">Enter the voucher dollar amount</small>
            </div>

            <div class="form-group">
                <label for="voucher_expiry_date">Expiration Date (Optional)</label>
                <input type="date" id="voucher_expiry_date" name="voucher_expiry_date">
                <small class="help-text">Leave empty for no expiration</small>
            </div>

            <input type="hidden" name="visit_date" value="<?php echo date('Y-m-d H:i:s'); ?>">

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
initCustomerSearch('customer_search', 'customer_id', 'customer_results', 'voucher', null);
</script>

<?php include 'footer.php'; ?>

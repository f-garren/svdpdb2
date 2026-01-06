<?php
require_once 'config.php';

$error = '';
$success = '';
$show_confirmation = false;
$form_data = [];
$customer_id = $_GET['customer_id'] ?? 0;
$db = getDB();

// Get visit limits
$visits_per_month = intval(getSetting('visits_per_month_limit', 2));
$visits_per_year = intval(getSetting('visits_per_year_limit', 12));
$min_days_between = intval(getSetting('min_days_between_visits', 14));

// Get customer if ID provided
$customer = null;
$household_members = [];
if ($customer_id) {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    if ($customer) {
        // Get household members
        $stmt = $db->prepare("SELECT name, birthdate, relationship FROM household_members WHERE customer_id = ? ORDER BY name");
        $stmt->execute([$customer_id]);
        $household_members = $stmt->fetchAll();
    }
}

// Handle confirmation submission (final save)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_submit'])) {
    $p = $_POST;
    $customer_id = intval($p['customer_id']);
    $visit_type = $p['visit_type'] ?? 'food';
    
    try {
        // Validate customer exists
        $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        
        if (!$customer) {
            throw new Exception("Customer not found");
        }
        
        // Use current timestamp or manual override for visit
        if (!empty($p['override_visit_date']) && !empty($p['manual_visit_datetime'])) {
            $visit_date = date('Y-m-d H:i:s', strtotime($p['manual_visit_datetime']));
            $visit_timestamp = strtotime($p['manual_visit_datetime']);
        } else {
            $visit_timestamp = time();
            $visit_date = date('Y-m-d H:i:s');
        }
        
        // Check visit limits based on type
        if ($visit_type === 'food') {
            // Food visits use existing limits
            $visit_month = date('Y-m', $visit_timestamp);
            $visit_year = date('Y', $visit_timestamp);
            
            // Count food visits in the same month
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM visits WHERE customer_id = ? AND visit_type = 'food' AND DATE_FORMAT(visit_date, '%Y-%m') = ?");
            $stmt->execute([$customer_id, $visit_month]);
            $month_visits = $stmt->fetch()['count'];
            
            if ($month_visits >= $visits_per_month) {
                throw new Exception("Monthly food visit limit reached. This customer has {$month_visits} food visits this month (limit: {$visits_per_month}).");
            }
            
            // Count food visits in the same year
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM visits WHERE customer_id = ? AND visit_type = 'food' AND YEAR(visit_date) = ?");
            $stmt->execute([$customer_id, $visit_year]);
            $year_visits = $stmt->fetch()['count'];
            
            if ($year_visits >= $visits_per_year) {
                throw new Exception("Yearly food visit limit reached. This customer has {$year_visits} food visits this year (limit: {$visits_per_year}).");
            }
            
            // Check minimum days between food visits
            $stmt = $db->prepare("SELECT MAX(visit_date) as last_visit FROM visits WHERE customer_id = ? AND visit_type = 'food' AND visit_date < ?");
            $stmt->execute([$customer_id, $visit_date]);
            $last_visit = $stmt->fetch()['last_visit'];
            
            if ($last_visit) {
                $days_since = floor(($visit_timestamp - strtotime($last_visit)) / 86400);
                if ($days_since < $min_days_between) {
                    throw new Exception("Minimum {$min_days_between} days required between food visits. Last food visit was {$days_since} days ago.");
                }
            }
        } elseif ($visit_type === 'money') {
            // Money visits: 3 times per household total (all time)
            // Get household member names for this customer
            $stmt = $db->prepare("SELECT name FROM household_members WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $household_names = array_column($stmt->fetchAll(), 'name');
            
            // Find all customers in the same household (share at least one household member name)
            $household_customer_ids = [$customer_id]; // Include the current customer
            
            if (!empty($household_names)) {
                $placeholders = str_repeat('?,', count($household_names) - 1) . '?';
                $stmt = $db->prepare("SELECT DISTINCT customer_id FROM household_members WHERE name IN ($placeholders)");
                $stmt->execute($household_names);
                $related_customers = array_column($stmt->fetchAll(), 'customer_id');
                $household_customer_ids = array_unique(array_merge($household_customer_ids, $related_customers));
            }
            
            // Count money visits for all household members
            $placeholders = str_repeat('?,', count($household_customer_ids) - 1) . '?';
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM visits WHERE customer_id IN ($placeholders) AND visit_type = 'money'");
            $stmt->execute($household_customer_ids);
            $money_visits_count = $stmt->fetch()['count'];
            
            $money_limit = intval(getSetting('money_distribution_limit', 3));
            if ($money_visits_count >= $money_limit) {
                throw new Exception("Money assistance limit reached. This household has received money assistance {$money_visits_count} times (limit: {$money_limit} times total).");
            }
        } elseif ($visit_type === 'voucher') {
            // Voucher creation - create voucher record
            if (empty($p['voucher_amount'])) {
                throw new Exception("Voucher amount is required for voucher visits");
            }
            $voucher_amount = floatval($p['voucher_amount']);
            if ($voucher_amount <= 0) {
                throw new Exception("Voucher amount must be greater than 0");
            }
            
            // Generate unique voucher code
            $voucher_code = 'VCH-' . strtoupper(substr(md5(time() . $customer_id . rand()), 0, 8));
            
            // Check if code exists (unlikely but possible)
            $stmt = $db->prepare("SELECT id FROM vouchers WHERE voucher_code = ?");
            $stmt->execute([$voucher_code]);
            while ($stmt->fetch()) {
                $voucher_code = 'VCH-' . strtoupper(substr(md5(time() . $customer_id . rand()), 0, 8));
                $stmt->execute([$voucher_code]);
            }
        }
        
        // Insert visit
        $notes = $p['notes'] ?? '';
        $stmt = $db->prepare("INSERT INTO visits (customer_id, visit_date, visit_type, notes) VALUES (?, ?, ?, ?)");
        $stmt->execute([$customer_id, $visit_date, $visit_type, $notes]);
        
        // If voucher, create voucher record
        if ($visit_type === 'voucher') {
            $stmt = $db->prepare("INSERT INTO vouchers (voucher_code, customer_id, amount, issued_date, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$voucher_code, $customer_id, $voucher_amount, $visit_date, $notes]);
        }
        
        $success = "Visit recorded successfully!";
        if ($visit_type === 'voucher') {
            $success .= " Voucher Code: <strong>{$voucher_code}</strong> - Amount: $" . number_format($voucher_amount, 2);
        }
        $success .= " <a href='customer_view.php?id=" . $customer_id . "'>View customer</a>";
        
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

$page_title = "Record Visit";
include 'header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>Record Visit</h1>
        <p class="lead">Track customer visits with automatic limit checking</p>
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
                    <tr><th>Visit Type:</th><td><strong><?php echo ucfirst($form_data['visit_type'] ?? 'food'); ?></strong></td></tr>
                    <tr><th>Visit Date & Time:</th><td><?php 
                        if (!empty($form_data['override_visit_date']) && !empty($form_data['manual_visit_datetime'])) {
                            echo date('F d, Y \a\t g:i A', strtotime($form_data['manual_visit_datetime']));
                        } else {
                            echo date('F d, Y \a\t g:i A');
                        }
                    ?></td></tr>
                    <?php if ($form_data['visit_type'] === 'voucher' && !empty($form_data['voucher_amount'])): ?>
                    <tr><th>Voucher Amount:</th><td>$<?php echo number_format(floatval($form_data['voucher_amount']), 2); ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($form_data['notes'])): ?>
                    <tr><th>Notes:</th><td><?php echo nl2br(htmlspecialchars($form_data['notes'])); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            
            <!-- Hidden form to preserve data -->
            <form method="POST" action="" style="display: none;" id="confirm_form">
                <?php foreach ($form_data as $key => $value): ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
                <?php endforeach; ?>
                <input type="hidden" name="confirm_submit" value="1">
            </form>
            
            <div class="form-actions">
                <button type="button" onclick="document.getElementById('confirm_form').submit();" class="btn btn-primary btn-large">Confirm and Submit</button>
                <button type="button" onclick="window.location.href='visits.php';" class="btn btn-secondary btn-large">Cancel / Edit</button>
            </div>
        </div>
    <?php else: ?>
        <!-- Visit Form -->
        <div class="visit-limits-info">
            <h3>Visit Limits</h3>
            <ul>
                <li>Food Visits - Per Month: <?php echo $visits_per_month; ?> | Per Year: <?php echo $visits_per_year; ?> | Min Days Between: <?php echo $min_days_between; ?></li>
                <li>Money Visits - Maximum 3 times per household (all time)</li>
                <li>Voucher Visits - No limit</li>
            </ul>
        </div>

        <form method="POST" action="" class="visit-form">
            <div class="form-group" style="position: relative;">
                <label for="customer_search">Search Customer <span class="required">*</span></label>
                <input type="text" id="customer_search" placeholder="Type customer name or phone..." autocomplete="off" value="<?php echo $customer ? htmlspecialchars($customer['name']) : ''; ?>">
                <input type="hidden" id="customer_id" name="customer_id" value="<?php echo $customer ? $customer['id'] : ''; ?>" required>
                <div id="customer_results" class="search-results"></div>
            </div>
            
            <?php if ($customer): ?>
                <div class="selected-customer" id="customer_info">
                    <strong>Selected:</strong> <?php echo htmlspecialchars($customer['name']); ?> 
                    (<?php echo htmlspecialchars($customer['phone']); ?>)
                    <?php if (!empty($household_members)): ?>
                        <div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid var(--border-color);">
                            <strong>Household Members:</strong>
                            <ul style="margin: 0.25rem 0 0 1.5rem; padding: 0;">
                                <?php foreach ($household_members as $member): ?>
                                    <li><em><?php echo htmlspecialchars($member['name']); ?></em><?php if ($member['relationship']): ?> (<?php echo htmlspecialchars($member['relationship']); ?>)<?php endif; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <div id="eligibility_errors" style="margin-top: 0.5rem;"></div>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="visit_type">Visit Type <span class="required">*</span></label>
                <select id="visit_type" name="visit_type" required>
                    <option value="food">Food</option>
                    <option value="money">Money</option>
                    <option value="voucher">Voucher</option>
                </select>
            </div>

            <div id="voucher_amount_field" style="display: none;">
                <div class="form-group">
                    <label for="voucher_amount">Voucher Amount ($) <span class="required">*</span></label>
                    <input type="number" id="voucher_amount" name="voucher_amount" step="0.01" min="0.01" placeholder="0.00">
                    <small class="help-text">Enter the voucher dollar amount</small>
                </div>
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

<script>
let searchTimeout;
const customerSearch = document.getElementById('customer_search');
const customerIdInput = document.getElementById('customer_id');
const visitTypeSelect = document.getElementById('visit_type');

function checkEligibility(customerId, visitType) {
    fetch(`check_eligibility.php?customer_id=${customerId}&visit_type=${visitType}`)
        .then(response => response.json())
        .then(data => {
            const errorDiv = document.getElementById('eligibility_errors');
            if (errorDiv) {
                if (data.eligible === false && data.errors && data.errors.length > 0) {
                    errorDiv.innerHTML = '<div class="alert alert-error">' + data.errors.join('<br>') + '</div>';
                } else {
                    errorDiv.innerHTML = '';
                }
            }
        })
        .catch(error => {
            console.error('Eligibility check error:', error);
        });
}

if (customerSearch) {
    const customerResults = document.getElementById('customer_results');
    
    customerSearch.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        
        if (query.length < 2) {
            customerResults.innerHTML = '';
            customerIdInput.value = '';
            const errorDiv = document.getElementById('eligibility_errors');
            if (errorDiv) errorDiv.innerHTML = '';
            return;
        }
        
        searchTimeout = setTimeout(() => {
            fetch(`customer_search.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    customerResults.innerHTML = '';
                    if (data.length === 0) {
                        customerResults.innerHTML = '<div class="no-results">No customers found</div>';
                        return;
                    }
                    
                    data.forEach(customer => {
                        const div = document.createElement('div');
                        div.className = 'customer-result';
                        div.innerHTML = `
                            <strong>${customer.name}</strong><br>
                            <small>${customer.phone} - ${customer.city || ''}, ${customer.state || ''}</small>
                        `;
                        div.addEventListener('click', () => {
                            customerIdInput.value = customer.id;
                            customerSearch.value = customer.name;
                            customerResults.innerHTML = '';
                            const visitType = visitTypeSelect ? visitTypeSelect.value : 'food';
                            checkEligibility(customer.id, visitType);
                            // Reload page with customer selected
                            window.location.href = `visits.php?customer_id=${customer.id}`;
                        });
                        customerResults.appendChild(div);
                    });
                })
                .catch(error => {
                    console.error('Search error:', error);
                });
        }, 300);
    });
    
    // Hide results when clicking outside
    document.addEventListener('click', function(e) {
        if (!customerSearch.contains(e.target) && !customerResults.contains(e.target)) {
            customerResults.innerHTML = '';
        }
    });
}

// Check eligibility when customer is pre-selected or visit type changes
if (visitTypeSelect) {
    visitTypeSelect.addEventListener('change', function() {
        const customerId = customerIdInput ? customerIdInput.value : null;
        if (customerId) {
            checkEligibility(customerId, this.value);
        }
    });
}

<?php if ($customer): ?>
const visitType = document.getElementById('visit_type') ? document.getElementById('visit_type').value : 'food';
checkEligibility(<?php echo $customer['id']; ?>, visitType);
<?php endif; ?>

// Handle visit date/time override
const overrideCheckbox = document.getElementById('override_visit_date');
if (overrideCheckbox) {
    overrideCheckbox.addEventListener('change', function() {
        if (this.checked) {
            document.getElementById('auto_visit_datetime').style.display = 'none';
            document.getElementById('manual_visit_datetime').style.display = 'block';
            document.getElementById('manual_visit_datetime_input').required = true;
        } else {
            document.getElementById('auto_visit_datetime').style.display = 'block';
            document.getElementById('manual_visit_datetime').style.display = 'none';
            document.getElementById('manual_visit_datetime_input').required = false;
        }
    });
}

// Handle visit type change
const visitTypeSelect = document.getElementById('visit_type');
if (visitTypeSelect) {
    visitTypeSelect.addEventListener('change', function() {
        if (this.value === 'voucher') {
            document.getElementById('voucher_amount_field').style.display = 'block';
            document.getElementById('voucher_amount').required = true;
        } else {
            document.getElementById('voucher_amount_field').style.display = 'none';
            document.getElementById('voucher_amount').required = false;
        }
    });
}
</script>

<?php include 'footer.php'; ?>

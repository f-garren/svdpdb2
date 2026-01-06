<?php
require_once 'config.php';
require_once 'auth.php';

requirePermission('customer_create');

$error = '';
$success = '';
$show_confirmation = false;
$potential_duplicates = [];
$form_data = [];

// Handle confirmation submission (final save)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_submit'])) {
    $db = getDB();
    
        // Use $_POST data (from hidden form fields)
        $p = $_POST;
        
        try {
        $db->beginTransaction();
        
        // Insert customer with automatic or manual signup timestamp
        $signup_timestamp = getSignupDate($p);
        
        // Format phone number using helper function
        $phone = formatPhone($p['phone']);
        
        $stmt = $db->prepare("INSERT INTO customers (signup_date, name, address, city, state, zip, phone, description_of_need, applied_before) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $signup_timestamp,
            $p['name'],
            $p['address'],
            $p['city'],
            $p['state'],
            $p['zip'],
            $phone,
            $p['description_of_need'] ?? '',
            $p['applied_before']
        ]);
        
        $customer_id = $db->lastInsertId();
        
        // Handle previous applications
        if ($p['applied_before'] === 'yes' && !empty($p['prev_app_date']) && !empty($p['prev_app_name'])) {
            $stmt = $db->prepare("INSERT INTO previous_applications (customer_id, application_date, name_used) VALUES (?, ?, ?)");
            $stmt->execute([$customer_id, $p['prev_app_date'], $p['prev_app_name']]);
        }
        
        // Handle household members (always include the customer as first household member)
        $stmt = $db->prepare("INSERT INTO household_members (customer_id, name, birthdate, relationship) VALUES (?, ?, ?, ?)");
        
        // Always add the customer themselves as "Self" if name is provided
        if (!empty($p['name'])) {
            $customer_birthdate = !empty($p['household_birthdates'][0]) ? $p['household_birthdates'][0] : '1900-01-01';
            $customer_relationship = !empty($p['household_relationships'][0]) ? $p['household_relationships'][0] : 'Self';
            
            $stmt->execute([
                $customer_id,
                $p['name'],
                $customer_birthdate,
                $customer_relationship
            ]);
        }
        
        // Add additional household members (skip first one as it's the customer)
        if (!empty($p['household_names']) && is_array($p['household_names']) && count($p['household_names']) > 1) {
            foreach ($p['household_names'] as $index => $name) {
                if ($index == 0) continue; // Skip first one (customer)
                if (!empty($name) && !empty($p['household_birthdates'][$index]) && !empty($p['household_relationships'][$index])) {
                    $stmt->execute([
                        $customer_id,
                        $name,
                        $p['household_birthdates'][$index],
                        $p['household_relationships'][$index]
                    ]);
                }
            }
        }
        
        // Handle subsidized housing
        $rent_amount = null;
        if ($p['subsidized_housing'] === 'yes' && !empty($p['rent_amount'])) {
            $rent_amount = floatval($p['rent_amount']);
        }
        $stmt = $db->prepare("INSERT INTO subsidized_housing (customer_id, in_subsidized_housing, rent_amount) VALUES (?, ?, ?)");
        $stmt->execute([
            $customer_id,
            $p['subsidized_housing'],
            $rent_amount
        ]);
        
        // Handle income
        $child_support = floatval($p['child_support'] ?? 0);
        $pension = floatval($p['pension'] ?? 0);
        $wages = floatval($p['wages'] ?? 0);
        $ss_ssd_ssi = floatval($p['ss_ssd_ssi'] ?? 0);
        $unemployment = floatval($p['unemployment'] ?? 0);
        $food_stamps = floatval($p['food_stamps'] ?? 0);
        $other = floatval($p['other'] ?? 0);
        $total = $child_support + $pension + $wages + $ss_ssd_ssi + $unemployment + $food_stamps + $other;
        
        $stmt = $db->prepare("INSERT INTO income_sources (customer_id, child_support, pension, wages, ss_ssd_ssi, unemployment, food_stamps, other, other_description, total_household_income) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $customer_id,
            $child_support,
            $pension,
            $wages,
            $ss_ssd_ssi,
            $unemployment,
            $food_stamps,
            $other,
            $p['other_description'] ?? null,
            $total
        ]);
        
        $db->commit();
        
        // Log audit
        logEmployeeAction($db, getCurrentEmployeeId(), 'customer_create', 'customer', $customer_id, "Created new customer: {$p['name']}");
        
        $success = "Customer successfully registered! <a href='customer_view.php?id=" . $customer_id . "'>View customer details</a>";
        // Clear form data on success
        $form_data = [];
        $show_confirmation = false;
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Handle initial form submission - check for duplicates and show confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['confirm_submit'])) {
    $db = getDB();
    
    // Store form data for confirmation screen
    $form_data = $_POST;
    
    // Check for duplicate customers by name
    $customer_name = trim($_POST['name'] ?? '');
    if (!empty($customer_name)) {
        // Check exact name match
        $stmt = $db->prepare("SELECT c.*, 
                             (SELECT GROUP_CONCAT(name) FROM household_members WHERE customer_id = c.id) as household_names
                             FROM customers c 
                             WHERE LOWER(c.name) = LOWER(?) 
                             OR LOWER(c.name) LIKE LOWER(?)
                             OR LOWER(c.name) LIKE LOWER(?)");
        $name_like_start = $customer_name . '%';
        $name_like_end = '%' . $customer_name;
        $stmt->execute([$customer_name, $name_like_start, $name_like_end]);
        $exact_matches = $stmt->fetchAll();
        
        // Check household members - if new customer or household member name matches existing household members
        if (!empty($_POST['household_names'])) {
            foreach ($_POST['household_names'] as $household_name) {
                if (empty(trim($household_name))) continue;
                
                $stmt = $db->prepare("SELECT DISTINCT c.*, hm.name as matched_household_member,
                                     (SELECT GROUP_CONCAT(name) FROM household_members WHERE customer_id = c.id) as household_names
                                     FROM customers c
                                     INNER JOIN household_members hm ON c.id = hm.customer_id
                                     WHERE LOWER(hm.name) = LOWER(?)");
                $stmt->execute([trim($household_name)]);
                $household_matches = $stmt->fetchAll();
                $potential_duplicates = array_merge($potential_duplicates, $household_matches);
            }
        }
        
        // Also check if new customer name matches any existing household member
        $stmt = $db->prepare("SELECT DISTINCT c.*, hm.name as matched_household_member,
                             (SELECT GROUP_CONCAT(name) FROM household_members WHERE customer_id = c.id) as household_names
                             FROM customers c
                             INNER JOIN household_members hm ON c.id = hm.customer_id
                             WHERE LOWER(hm.name) = LOWER(?)");
        $stmt->execute([$customer_name]);
        $customer_as_household_matches = $stmt->fetchAll();
        $potential_duplicates = array_merge($potential_duplicates, $customer_as_household_matches);
        
        // Merge and deduplicate
        $all_matches = array_merge($exact_matches, $potential_duplicates);
        $seen_ids = [];
        $potential_duplicates = [];
        foreach ($all_matches as $match) {
            if (!in_array($match['id'], $seen_ids)) {
                $potential_duplicates[] = $match;
                $seen_ids[] = $match['id'];
            }
        }
    }
    
    // Show confirmation screen
    $show_confirmation = true;
}

$page_title = "New " . getCustomerTerm('Customer') . " Signup";
include 'header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>New <?php echo htmlspecialchars(getCustomerTerm('Customer')); ?> Signup</h1>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <?php if ($show_confirmation): ?>
        <!-- Confirmation Screen -->
        <div class="confirmation-screen">
            <h2>Confirm Customer Registration</h2>
            <p class="lead">Please review the information below and confirm or make changes.</p>
            
            <?php if (count($potential_duplicates) > 0): ?>
                <div class="alert alert-warning">
                    <h3>⚠️ Potential Duplicate Customers Found</h3>
                    <p>The following customers in the system have similar names or household members. Please review to ensure this is not the same person:</p>
                    <div class="duplicate-list">
                        <?php foreach ($potential_duplicates as $dup): ?>
                            <div class="duplicate-item">
                                <strong><?php echo htmlspecialchars($dup['name']); ?></strong><br>
                                <small>
                                    Phone: <?php echo htmlspecialchars($dup['phone']); ?> | 
                                    Address: <?php echo htmlspecialchars($dup['address']); ?>, <?php echo htmlspecialchars($dup['city']); ?>, <?php echo htmlspecialchars($dup['state']); ?><br>
                                    Signup: <?php echo date('M d, Y', strtotime($dup['signup_date'])); ?><br>
                                    <?php if (!empty($dup['household_names'])): ?>
                                        Household: <?php echo htmlspecialchars($dup['household_names']); ?>
                                    <?php endif; ?>
                                </small>
                                <a href="customer_view.php?id=<?php echo $dup['id']; ?>" target="_blank" class="btn btn-small">View Customer</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p><strong>Is this the same person?</strong> If yes, please cancel and use the existing customer record.</p>
                </div>
            <?php endif; ?>
            
            <!-- Summary of Information -->
            <div class="confirmation-summary">
                <h3>Registration Summary</h3>
                <div class="summary-section">
                    <h4>Basic Information</h4>
                    <table class="info-table">
                        <tr><th>Sign Up Date:</th><td><?php echo isset($form_data['override_signup_date']) && !empty($form_data['manual_signup_datetime']) ? date('F d, Y \a\t g:i A', strtotime($form_data['manual_signup_datetime'])) : date('F d, Y \a\t g:i A'); ?></td></tr>
                        <tr><th>Name:</th><td><?php echo htmlspecialchars($form_data['name'] ?? ''); ?></td></tr>
                        <tr><th>Address:</th><td><?php echo htmlspecialchars($form_data['address'] ?? ''); ?></td></tr>
                        <tr><th>City, State, ZIP:</th><td><?php echo htmlspecialchars(($form_data['city'] ?? '') . ', ' . ($form_data['state'] ?? '') . ' ' . ($form_data['zip'] ?? '')); ?></td></tr>
                        <tr><th>Phone:</th><td><?php echo htmlspecialchars($form_data['phone'] ?? ''); ?></td></tr>
                        <?php if (!empty($form_data['description_of_need'])): ?>
                        <tr><th>Description:</th><td><?php echo nl2br(htmlspecialchars($form_data['description_of_need'])); ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
                
                <?php if (!empty($form_data['household_names']) && !empty(array_filter($form_data['household_names']))): ?>
                <div class="summary-section">
                    <h4>Household Members</h4>
                    <table class="data-table">
                        <thead><tr><th>Name</th><th>Birthdate</th><th>Relationship</th></tr></thead>
                        <tbody>
                            <?php foreach ($form_data['household_names'] as $idx => $name): ?>
                                <?php if (!empty(trim($name))): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($name); ?></td>
                                    <td><?php echo !empty($form_data['household_birthdates'][$idx]) ? date('M d, Y', strtotime($form_data['household_birthdates'][$idx])) : 'N/A'; ?></td>
                                    <td><?php echo htmlspecialchars($form_data['household_relationships'][$idx] ?? ''); ?></td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <?php 
                $has_income = (!empty($form_data['child_support']) || !empty($form_data['wages']) || !empty($form_data['ss_ssd_ssi']) || 
                              !empty($form_data['pension']) || !empty($form_data['unemployment']) || !empty($form_data['food_stamps']) || 
                              !empty($form_data['other']));
                if ($has_income): 
                ?>
                <div class="summary-section">
                    <h4>Household Income</h4>
                    <table class="info-table">
                        <?php if (!empty($form_data['child_support'])): ?><tr><th>Child Support:</th><td>$<?php echo number_format(floatval($form_data['child_support']), 2); ?></td></tr><?php endif; ?>
                        <?php if (!empty($form_data['pension'])): ?><tr><th>Pension:</th><td>$<?php echo number_format(floatval($form_data['pension']), 2); ?></td></tr><?php endif; ?>
                        <?php if (!empty($form_data['wages'])): ?><tr><th>Wages:</th><td>$<?php echo number_format(floatval($form_data['wages']), 2); ?></td></tr><?php endif; ?>
                        <?php if (!empty($form_data['ss_ssd_ssi'])): ?><tr><th>SS/SSD/SSI:</th><td>$<?php echo number_format(floatval($form_data['ss_ssd_ssi']), 2); ?></td></tr><?php endif; ?>
                        <?php if (!empty($form_data['unemployment'])): ?><tr><th>Unemployment:</th><td>$<?php echo number_format(floatval($form_data['unemployment']), 2); ?></td></tr><?php endif; ?>
                        <?php if (!empty($form_data['food_stamps'])): ?><tr><th>Food Stamps:</th><td>$<?php echo number_format(floatval($form_data['food_stamps']), 2); ?></td></tr><?php endif; ?>
                        <?php if (!empty($form_data['other'])): ?><tr><th>Other:</th><td>$<?php echo number_format(floatval($form_data['other']), 2); ?></td></tr><?php endif; ?>
                        <tr class="total-row"><th>Total:</th><td><strong>$<?php echo number_format((floatval($form_data['child_support'] ?? 0) + floatval($form_data['pension'] ?? 0) + floatval($form_data['wages'] ?? 0) + floatval($form_data['ss_ssd_ssi'] ?? 0) + floatval($form_data['unemployment'] ?? 0) + floatval($form_data['food_stamps'] ?? 0) + floatval($form_data['other'] ?? 0)), 2); ?></strong></td></tr>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Hidden form fields to preserve data -->
            <form method="POST" action="" style="display: none;" id="confirm_form">
                <?php echo generateHiddenFields($form_data); ?>
                <input type="hidden" name="confirm_submit" value="1">
            </form>
            
            <div class="form-actions">
                <button type="button" onclick="document.getElementById('confirm_form').submit();" class="btn btn-primary btn-large">Confirm and Submit</button>
                <button type="button" onclick="window.location.href='signup.php';" class="btn btn-secondary btn-large">Cancel / Edit Information</button>
            </div>
        </div>
    <?php else: ?>
        <!-- Regular Signup Form -->
        <form method="POST" action="" class="signup-form">
        <div class="form-section">
            <h2>Basic Information</h2>
            
            <div class="form-group">
                <label>Sign Up Date & Time</label>
                <div class="checkbox-group">
                    <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                        <input type="checkbox" id="override_signup_date" name="override_signup_date" value="1">
                        <span>Override automatic date/time (for backporting entries)</span>
                    </label>
                </div>
                <div id="auto_signup_datetime">
                    <input type="text" value="<?php echo date('F d, Y \a\t g:i A'); ?>" readonly class="readonly-field">
                    <small class="help-text">Automatically recorded from system time</small>
                </div>
                <div id="manual_signup_datetime" style="display: none;">
                    <input type="datetime-local" id="manual_signup_datetime_input" name="manual_signup_datetime" value="<?php echo date('Y-m-d\TH:i', time()); ?>" step="1" class="datetime-input" tabindex="-1">
                    <small class="help-text">Enter the actual signup date and time</small>
                </div>
            </div>
            
            <div class="form-group">
                <label for="name">Name <span class="required">*</span></label>
                <input type="text" id="name" name="name" required>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="address">Address <span class="required">*</span></label>
                    <input type="text" id="address" name="address" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="city">City <span class="required">*</span></label>
                    <input type="text" id="city" name="city" required>
                </div>
                
                <div class="form-group">
                    <label for="state">State <span class="required">*</span></label>
                    <input type="text" id="state" name="state" maxlength="2" placeholder="XX" required>
                </div>
                
                <div class="form-group">
                    <label for="zip">ZIP <span class="required">*</span></label>
                    <input type="text" id="zip" name="zip" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="phone">Phone Number <span class="required">*</span></label>
                <input type="tel" id="phone" name="phone" required>
            </div>
            
            <div class="form-group">
                <label for="description_of_need">Description of Need and Situation</label>
                <textarea id="description_of_need" name="description_of_need" rows="4"></textarea>
            </div>
        </div>

        <div class="form-section">
            <h2>Previous Applications</h2>
            
            <div class="form-group">
                <label for="applied_before">Have you ever applied for assistance from <?php echo htmlspecialchars(getSetting('organization_name', 'NexusDB')); ?> before? <span class="required">*</span></label>
                <select id="applied_before" name="applied_before" required>
                    <option value="no">No</option>
                    <option value="yes">Yes</option>
                </select>
            </div>
            
            <div id="previous_app_details" style="display: none;">
                <div class="form-group">
                    <label for="prev_app_date">When?</label>
                    <input type="date" id="prev_app_date" name="prev_app_date">
                </div>
                
                <div class="form-group">
                    <label for="prev_app_name">What name was used?</label>
                    <input type="text" id="prev_app_name" name="prev_app_name">
                </div>
            </div>
        </div>

        <div class="form-section">
            <h2>Household Members</h2>
            <p class="help-text">List ALL persons living in the household (name, birthdate, relationship). Ensure all fields are filled.</p>
            
            <div id="household_members">
                <div class="household-member">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" name="household_names[]" id="household_name_0" placeholder="Auto-filled with customer name">
                        </div>
                        <div class="form-group">
                            <label>Birthdate</label>
                            <input type="date" name="household_birthdates[]" id="household_birthdate_0">
                        </div>
                        <div class="form-group">
                            <label>Relationship</label>
                            <input type="text" name="household_relationships[]" id="household_relationship_0" value="Self" readonly class="readonly-field">
                        </div>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-secondary btn-small" onclick="addHouseholdMember()">+ Add Household Member</button>
        </div>

        <div class="form-section">
            <h2>Subsidized Housing</h2>
            
            <div class="form-group">
                <label for="subsidized_housing">Are you in subsidized housing? <span class="required">*</span></label>
                <select id="subsidized_housing" name="subsidized_housing" required>
                    <option value="no">No</option>
                    <option value="yes">Yes</option>
                </select>
            </div>
            
            <div id="housing_details" style="display: none;">
                <div class="form-group">
                    <label for="rent_amount">Amount of Rent</label>
                    <input type="number" id="rent_amount" name="rent_amount" step="0.01" min="0" placeholder="0.00">
                </div>
            </div>
        </div>

        <div class="form-section">
            <h2>Total Household Income</h2>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="child_support">Child Support</label>
                    <input type="number" id="child_support" name="child_support" step="0.01" min="0" value="0">
                </div>
                
                <div class="form-group">
                    <label for="pension">Pension</label>
                    <input type="number" id="pension" name="pension" step="0.01" min="0" value="0">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="wages">Wages</label>
                    <input type="number" id="wages" name="wages" step="0.01" min="0" value="0">
                </div>
                
                <div class="form-group">
                    <label for="ss_ssd_ssi">SS/SSD/SSI</label>
                    <input type="number" id="ss_ssd_ssi" name="ss_ssd_ssi" step="0.01" min="0" value="0">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="unemployment">Unemployment</label>
                    <input type="number" id="unemployment" name="unemployment" step="0.01" min="0" value="0">
                </div>
                
                <div class="form-group">
                    <label for="food_stamps">Food Stamps</label>
                    <input type="number" id="food_stamps" name="food_stamps" step="0.01" min="0" value="0">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="other">Other</label>
                    <input type="number" id="other" name="other" step="0.01" min="0" value="0">
                </div>
                
                <div class="form-group">
                    <label for="other_description">Other Description</label>
                    <input type="text" id="other_description" name="other_description" placeholder="Describe other income">
                </div>
            </div>
            
            <div class="form-group">
                <label>Total Household Income</label>
                <input type="text" id="total_income" readonly value="$0.00" class="total-display">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-large">Submit Registration</button>
            <a href="index.php" class="btn btn-secondary btn-large">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
document.getElementById('applied_before').addEventListener('change', function() {
    document.getElementById('previous_app_details').style.display = this.value === 'yes' ? 'block' : 'none';
});

document.getElementById('subsidized_housing').addEventListener('change', function() {
    document.getElementById('housing_details').style.display = this.value === 'yes' ? 'block' : 'none';
});

// Handle signup date/time override
document.getElementById('override_signup_date').addEventListener('change', function() {
    if (this.checked) {
        document.getElementById('auto_signup_datetime').style.display = 'none';
        document.getElementById('manual_signup_datetime').style.display = 'block';
        document.getElementById('manual_signup_datetime_input').required = true;
    } else {
        document.getElementById('auto_signup_datetime').style.display = 'block';
        document.getElementById('manual_signup_datetime').style.display = 'none';
        document.getElementById('manual_signup_datetime_input').required = false;
    }
});

function addHouseholdMember() {
    const container = document.getElementById('household_members');
    const member = document.createElement('div');
    member.className = 'household-member';
    member.innerHTML = `
        <div class="form-row">
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="household_names[]">
            </div>
            <div class="form-group">
                <label>Birthdate</label>
                <input type="date" name="household_birthdates[]">
            </div>
            <div class="form-group" style="flex: 1;">
                <label>Relationship</label>
                <div style="display: flex; gap: 0.5rem; align-items: flex-end;">
                    <input type="text" name="household_relationships[]" placeholder="e.g., Son, Daughter, Spouse" style="flex: 1;">
                    <button type="button" class="btn btn-small btn-danger" onclick="this.closest('.household-member').remove();" style="white-space: nowrap; flex-shrink: 0;">Remove</button>
                </div>
            </div>
        </div>
    `;
    container.appendChild(member);
}

function updateTotalIncome() {
    const fields = ['child_support', 'pension', 'wages', 'ss_ssd_ssi', 'unemployment', 'food_stamps', 'other'];
    let total = 0;
    fields.forEach(field => {
        total += parseFloat(document.getElementById(field).value) || 0;
    });
    document.getElementById('total_income').value = '$' + total.toFixed(2);
}

['child_support', 'pension', 'wages', 'ss_ssd_ssi', 'unemployment', 'food_stamps', 'other'].forEach(field => {
    document.getElementById(field).addEventListener('input', updateTotalIncome);
});

// Auto-populate first household member with customer name
document.getElementById('name').addEventListener('input', function() {
    document.getElementById('household_name_0').value = this.value;
});

// Real-time duplicate checking
function checkDuplicate(field, value, callback) {
    if (!value || value.length < 2) {
        callback(null);
        return;
    }
    
    let url = `check_duplicate.php?field=${field}&value=${encodeURIComponent(value)}`;
    if (field === 'name') {
        let phone = document.getElementById('phone').value;
        if (phone) url += `&phone=${encodeURIComponent(phone)}`;
    }
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            callback(data);
        })
        .catch(error => {
            console.error('Duplicate check error:', error);
            callback(null);
        });
}

// Add duplicate checking to name and phone fields
document.getElementById('name').addEventListener('blur', function() {
    let nameValue = this.value.trim();
    let phoneValue = document.getElementById('phone').value.trim();
    
    if (nameValue.length >= 2) {
        checkDuplicate('name', nameValue, function(result) {
            let duplicateDiv = document.getElementById('name_duplicate');
            if (!duplicateDiv) {
                duplicateDiv = document.createElement('div');
                duplicateDiv.id = 'name_duplicate';
                duplicateDiv.className = 'duplicate-warning';
                duplicateDiv.style.marginTop = '0.5rem';
                document.getElementById('name').parentElement.appendChild(duplicateDiv);
            }
            
            if (result && result.duplicate) {
                duplicateDiv.innerHTML = '<span style="color: #d32f2f;"><ion-icon name="warning"></ion-icon> ' + result.message + '</span>';
            } else {
                duplicateDiv.innerHTML = '';
            }
        });
    }
});

document.getElementById('phone').addEventListener('blur', function() {
    let phoneValue = this.value.replace(/[^0-9]/g, '');
    
    if (phoneValue.length >= 10) {
        // Format phone first
        let digits = phoneValue;
        if (digits.length >= 10) {
            let phoneNumber = digits.substring(digits.length - 10);
            let countryCode = digits.length > 10 ? digits.substring(0, digits.length - 10) : '1';
            let formatted = '+' + countryCode + ' (' + phoneNumber.substring(0, 3) + ') ' + phoneNumber.substring(3, 6) + '-' + phoneNumber.substring(6);
            this.value = formatted;
        }
        
        // Then check for duplicates
        checkDuplicate('phone', this.value, function(result) {
            let duplicateDiv = document.getElementById('phone_duplicate');
            if (!duplicateDiv) {
                duplicateDiv = document.createElement('div');
                duplicateDiv.id = 'phone_duplicate';
                duplicateDiv.className = 'duplicate-warning';
                duplicateDiv.style.marginTop = '0.5rem';
                document.getElementById('phone').parentElement.appendChild(duplicateDiv);
            }
            
            if (result && result.duplicate) {
                duplicateDiv.innerHTML = '<span style="color: #d32f2f;"><ion-icon name="warning"></ion-icon> ' + result.message + '</span>';
            } else {
                duplicateDiv.innerHTML = '';
            }
        });
    }
});
</script>

<?php include 'footer.php'; ?>


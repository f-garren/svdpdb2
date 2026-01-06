<?php
require_once 'config.php';
require_once 'auth.php';

requireLogin();

$customer_id = $_GET['id'] ?? 0;
$edit_mode = isset($_GET['edit']) && $_GET['edit'] == '1';
$error = '';
$success = '';
$db = getDB();

// Handle visit soft delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invalidate_visit'])) {
    requirePermission('visit_invalidate');
    
    $visit_id = intval($_POST['visit_id'] ?? 0);
    $reason = trim($_POST['invalid_reason'] ?? '');
    
    if (empty($reason)) {
        $error = "Reason is required for invalidating a visit";
    } elseif ($visit_id > 0) {
        try {
            // Get visit info for audit
            $stmt = $db->prepare("SELECT visit_type, customer_id FROM visits WHERE id = ?");
            $stmt->execute([$visit_id]);
            $visit_info = $stmt->fetch();
            
            $stmt = $db->prepare("UPDATE visits SET is_invalid = 1, invalid_reason = ?, invalidated_by = ?, invalidated_at = NOW() WHERE id = ? AND customer_id = ?");
            $stmt->execute([$reason, getCurrentEmployeeId(), $visit_id, $customer_id]);
            
            // Log audit
            logEmployeeAction($db, getCurrentEmployeeId(), 'visit_invalidate', 'visit', $visit_id, "Invalidated {$visit_info['visit_type']} visit. Reason: {$reason}");
            
            $success = "Visit marked as invalid successfully.";
        } catch (Exception $e) {
            $error = "Error invalidating visit: " . $e->getMessage();
        }
    }
}

// Handle voucher revocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_voucher'])) {
    $voucher_code = trim($_POST['voucher_code'] ?? '');
    
    if (!empty($voucher_code)) {
        try {
            // Check if voucher exists and is active
            $stmt = $db->prepare("SELECT id, status, customer_id FROM vouchers WHERE voucher_code = ?");
            $stmt->execute([$voucher_code]);
            $voucher = $stmt->fetch();
            
            if (!$voucher) {
                $error = "Voucher not found.";
            } elseif ($voucher['customer_id'] != $customer_id) {
                $error = "This voucher does not belong to this customer.";
            } elseif ($voucher['status'] !== 'active') {
                $error = "Only active vouchers can be revoked.";
            } else {
                // Revoke voucher by setting status to expired
                $stmt = $db->prepare("UPDATE vouchers SET status = 'expired' WHERE voucher_code = ?");
                $stmt->execute([$voucher_code]);
                
                // Log audit
                logEmployeeAction($db, getCurrentEmployeeId(), 'voucher_revoke', 'voucher', $voucher['id'], "Revoked voucher {$voucher_code}");
                
                $success = "Voucher {$voucher_code} has been revoked successfully.";
            }
        } catch (Exception $e) {
            $error = "Error revoking voucher: " . $e->getMessage();
        }
    } else {
        $error = "Invalid voucher code.";
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_customer'])) {
    requirePermission('customer_edit');
    
    try {
        $db->beginTransaction();
        
        // Get current customer data for audit trail
        $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $old_customer = $stmt->fetch();
        
        $p = $_POST;
        
        // Format phone number using helper function
        $phone = formatPhone($p['phone']);
        
        // Update customer and log changes
        $fields_to_check = [
            'name' => $p['name'],
            'address' => $p['address'],
            'city' => $p['city'],
            'state' => $p['state'],
            'zip' => $p['zip'],
            'phone' => $phone,
            'description_of_need' => $p['description_of_need'] ?? null,
            'applied_before' => $p['applied_before'] ?? 'no'
        ];
        
        foreach ($fields_to_check as $field => $new_value) {
            $old_value = $old_customer[$field] ?? null;
            if ($old_value != $new_value) {
                logAuditTrail($db, $customer_id, $field, $old_value, $new_value);
            }
        }
        
        $stmt = $db->prepare("UPDATE customers SET name = ?, address = ?, city = ?, state = ?, zip = ?, phone = ?, description_of_need = ?, applied_before = ? WHERE id = ?");
        $stmt->execute([
            $p['name'],
            $p['address'],
            $p['city'],
            $p['state'],
            $p['zip'],
            $phone,
            $p['description_of_need'] ?? null,
            $p['applied_before'] ?? 'no',
            $customer_id
        ]);
        
        // Handle signup date override
        if (!empty($p['override_signup_date']) && !empty($p['manual_signup_datetime'])) {
            $signup_timestamp = date('Y-m-d H:i:s', strtotime($p['manual_signup_datetime']));
            if ($old_customer['signup_date'] != $signup_timestamp) {
                logAuditTrail($db, $customer_id, 'signup_date', $old_customer['signup_date'], $signup_timestamp);
            }
            $stmt = $db->prepare("UPDATE customers SET signup_date = ? WHERE id = ?");
            $stmt->execute([$signup_timestamp, $customer_id]);
        }
        
        // Get existing household members for audit trail
        $stmt = $db->prepare("SELECT name, birthdate, relationship FROM household_members WHERE customer_id = ? ORDER BY name");
        $stmt->execute([$customer_id]);
        $old_household = $stmt->fetchAll();
        
        // Build new household members array from form data
        $new_household = [];
        if (!empty($p['household_names'])) {
            foreach ($p['household_names'] as $idx => $name) {
                if (!empty(trim($name))) {
                    $birthdate = !empty($p['household_birthdates'][$idx]) ? date('Y-m-d', strtotime($p['household_birthdates'][$idx])) : date('Y-m-d');
                    $new_household[] = [
                        'name' => trim($name),
                        'birthdate' => $birthdate,
                        'relationship' => $p['household_relationships'][$idx] ?? ''
                    ];
                }
            }
        }
        
        // Log household member changes
        // Create normalized arrays for comparison (name + birthdate as key)
        $old_normalized = [];
        foreach ($old_household as $member) {
            $key = strtolower(trim($member['name'])) . '|' . $member['birthdate'];
            $old_normalized[$key] = $member;
        }
        
        $new_normalized = [];
        foreach ($new_household as $member) {
            $key = strtolower(trim($member['name'])) . '|' . $member['birthdate'];
            $new_normalized[$key] = $member;
        }
        
        // Find removed members
        foreach ($old_normalized as $key => $old_member) {
            if (!isset($new_normalized[$key])) {
                $member_info = $old_member['name'] . ' (' . $old_member['relationship'] . ', ' . date('M d, Y', strtotime($old_member['birthdate'])) . ')';
                logAuditTrail($db, $customer_id, 'household_member', $member_info, '[REMOVED]');
            }
        }
        
        // Find added members
        foreach ($new_normalized as $key => $new_member) {
            if (!isset($old_normalized[$key])) {
                $member_info = $new_member['name'] . ' (' . $new_member['relationship'] . ', ' . date('M d, Y', strtotime($new_member['birthdate'])) . ')';
                logAuditTrail($db, $customer_id, 'household_member', '[ADDED]', $member_info);
            }
        }
        
        // Find edited members (same name/birthdate but different relationship)
        foreach ($old_normalized as $key => $old_member) {
            if (isset($new_normalized[$key])) {
                $new_member = $new_normalized[$key];
                if ($old_member['relationship'] !== $new_member['relationship']) {
                    $old_info = $old_member['name'] . ' - Relationship: ' . $old_member['relationship'];
                    $new_info = $new_member['name'] . ' - Relationship: ' . $new_member['relationship'];
                    logAuditTrail($db, $customer_id, 'household_member', $old_info, $new_info);
                }
                // Check if name changed (same birthdate)
                if (strtolower(trim($old_member['name'])) !== strtolower(trim($new_member['name']))) {
                    $old_info = 'Name: ' . $old_member['name'] . ' (' . date('M d, Y', strtotime($old_member['birthdate'])) . ')';
                    $new_info = 'Name: ' . $new_member['name'] . ' (' . date('M d, Y', strtotime($new_member['birthdate'])) . ')';
                    logAuditTrail($db, $customer_id, 'household_member', $old_info, $new_info);
                }
            }
        }
        
        // Delete existing household members and insert new ones
        $stmt = $db->prepare("DELETE FROM household_members WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        
        if (!empty($new_household)) {
            foreach ($new_household as $member) {
                $stmt = $db->prepare("INSERT INTO household_members (customer_id, name, birthdate, relationship) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $customer_id,
                    $member['name'],
                    $member['birthdate'],
                    $member['relationship']
                ]);
            }
        }
        
        // Do not modify previous applications when editing (only set during signup)
        // Previous applications remain unchanged in edit mode
        
        // Update or create subsidized housing
        $stmt = $db->prepare("DELETE FROM subsidized_housing WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        
        if (!empty($p['subsidized_housing']) && $p['subsidized_housing'] === 'yes') {
            $rent_amount = !empty($p['rent_amount']) ? floatval($p['rent_amount']) : null;
            $stmt = $db->prepare("INSERT INTO subsidized_housing (customer_id, in_subsidized_housing, rent_amount) VALUES (?, 'yes', ?)");
            $stmt->execute([$customer_id, $rent_amount]);
        } else {
            $stmt = $db->prepare("INSERT INTO subsidized_housing (customer_id, in_subsidized_housing) VALUES (?, 'no')");
            $stmt->execute([$customer_id]);
        }
        
        // Update or create income
        $child_support = floatval($p['child_support'] ?? 0);
        $pension = floatval($p['pension'] ?? 0);
        $wages = floatval($p['wages'] ?? 0);
        $ss_ssd_ssi = floatval($p['ss_ssd_ssi'] ?? 0);
        $unemployment = floatval($p['unemployment'] ?? 0);
        $food_stamps = floatval($p['food_stamps'] ?? 0);
        $other = floatval($p['other'] ?? 0);
        $total = $child_support + $pension + $wages + $ss_ssd_ssi + $unemployment + $food_stamps + $other;
        
        $stmt = $db->prepare("DELETE FROM income_sources WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        
        $stmt = $db->prepare("INSERT INTO income_sources (customer_id, child_support, pension, wages, ss_ssd_ssi, unemployment, food_stamps, other, other_description, total_household_income) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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
        $success = "Customer information updated successfully!";
        $edit_mode = false; // Switch back to view mode
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error updating customer: " . $e->getMessage();
    }
}

$stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch();

if (!$customer) {
    header('Location: customers.php');
    exit;
}

// Get household members
$stmt = $db->prepare("SELECT * FROM household_members WHERE customer_id = ? ORDER BY birthdate");
$stmt->execute([$customer_id]);
$household = $stmt->fetchAll();

// Get previous applications
$stmt = $db->prepare("SELECT * FROM previous_applications WHERE customer_id = ?");
$stmt->execute([$customer_id]);
$prev_apps = $stmt->fetchAll();

// Get subsidized housing
$stmt = $db->prepare("SELECT * FROM subsidized_housing WHERE customer_id = ?");
$stmt->execute([$customer_id]);
$housing = $stmt->fetch();

// Get income
$stmt = $db->prepare("SELECT * FROM income_sources WHERE customer_id = ?");
$stmt->execute([$customer_id]);
$income = $stmt->fetch();

// Get visits (including invalid ones)
$stmt = $db->prepare("SELECT v.*, 
    e.username as invalidated_by_username, e.full_name as invalidated_by_name
    FROM visits v 
    LEFT JOIN employees e ON v.invalidated_by = e.id
    WHERE v.customer_id = ? 
    ORDER BY v.visit_date DESC");
$stmt->execute([$customer_id]);
$visits = $stmt->fetchAll();

// Pagination for audit trail
$audit_page = max(1, intval($_GET['audit_page'] ?? 1));
$audit_logs_per_page = 100;
$audit_offset = ($audit_page - 1) * $audit_logs_per_page;

// Get total count for audit trail
$stmt = $db->prepare("SELECT COUNT(*) as total FROM customer_audit WHERE customer_id = ?");
$stmt->execute([$customer_id]);
$total_audit_logs = $stmt->fetch()['total'];
$total_audit_pages = ceil($total_audit_logs / $audit_logs_per_page);

// Get audit trail with pagination
$stmt = $db->prepare("SELECT ca.*, e.username, e.full_name 
    FROM customer_audit ca 
    LEFT JOIN employees e ON ca.changed_by = e.id 
    WHERE ca.customer_id = ? 
    ORDER BY ca.changed_at DESC 
    LIMIT ? OFFSET ?");
$stmt->execute([$customer_id, $audit_logs_per_page, $audit_offset]);
$audit_trail = $stmt->fetchAll();

// Count visits by type (excluding invalid)
$stmt = $db->prepare("SELECT visit_type, COUNT(*) as count FROM visits WHERE customer_id = ? AND (is_invalid = 0 OR is_invalid IS NULL) GROUP BY visit_type");
$stmt->execute([$customer_id]);
$visit_counts = [];
while ($row = $stmt->fetch()) {
    $visit_counts[$row['visit_type']] = $row['count'];
}

// Get visit limits
$visits_per_month = intval(getSetting('visits_per_month_limit', 2));
$visits_per_year = intval(getSetting('visits_per_year_limit', 12));
$min_days_between = intval(getSetting('min_days_between_visits', 14));

$money_limit_total = intval(getSetting('money_distribution_limit', 3));
$money_limit_month = intval(getSetting('money_distribution_limit_month', -1));
$money_limit_year = intval(getSetting('money_distribution_limit_year', -1));
$money_min_days_between = intval(getSetting('money_min_days_between', -1));

$voucher_limit_month = intval(getSetting('voucher_limit_month', -1));
$voucher_limit_year = intval(getSetting('voucher_limit_year', -1));
$voucher_min_days_between = intval(getSetting('voucher_min_days_between', -1));

// Calculate visit statistics by type
$food_visits_this_month = 0;
$food_visits_this_year = 0;
$money_visits_total = 0;
$money_visits_this_month = 0;
$money_visits_this_year = 0;
$voucher_visits_total = 0;
$voucher_visits_this_month = 0;
$voucher_visits_this_year = 0;
$last_food_visit_date = null;
$last_money_visit_date = null;
$last_voucher_visit_date = null;
$last_visit_date = null;

foreach ($visits as $visit) {
    // Skip invalid visits for statistics
    if ($visit['is_invalid']) {
        continue;
    }
    
    $visit_date = strtotime($visit['visit_date']);
    $now = time();
    $visit_type = $visit['visit_type'] ?? 'food';
    
    if ($visit_type === 'food') {
        if (date('Y-m', $visit_date) === date('Y-m', $now)) {
            $food_visits_this_month++;
        }
        if (date('Y', $visit_date) === date('Y', $now)) {
            $food_visits_this_year++;
        }
        
        if ($last_food_visit_date === null || $visit_date > $last_food_visit_date) {
            $last_food_visit_date = $visit_date;
        }
    } elseif ($visit_type === 'money') {
        $money_visits_total++;
        if (date('Y-m', $visit_date) === date('Y-m', $now)) {
            $money_visits_this_month++;
        }
        if (date('Y', $visit_date) === date('Y', $now)) {
            $money_visits_this_year++;
        }
        
        if ($last_money_visit_date === null || $visit_date > $last_money_visit_date) {
            $last_money_visit_date = $visit_date;
        }
    } elseif ($visit_type === 'voucher') {
        $voucher_visits_total++;
        if (date('Y-m', $visit_date) === date('Y-m', $now)) {
            $voucher_visits_this_month++;
        }
        if (date('Y', $visit_date) === date('Y', $now)) {
            $voucher_visits_this_year++;
        }
        
        if ($last_voucher_visit_date === null || $visit_date > $last_voucher_visit_date) {
            $last_voucher_visit_date = $visit_date;
        }
    }
    
    if ($last_visit_date === null || $visit_date > $last_visit_date) {
        $last_visit_date = $visit_date;
    }
}

// Get household customer IDs for money visit calculations
$stmt = $db->prepare("SELECT DISTINCT hm2.customer_id 
                   FROM household_members hm1
                   INNER JOIN household_members hm2 ON hm1.name = hm2.name
                   WHERE hm1.customer_id = ?");
$stmt->execute([$customer_id]);
$household_customer_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($household_customer_ids)) {
    $household_customer_ids = [$customer_id];
} else {
    $household_customer_ids[] = $customer_id;
    $household_customer_ids = array_unique($household_customer_ids);
}

// Calculate household money visits
$household_money_total = 0;
$household_money_month = 0;
$household_money_year = 0;
if (!empty($household_customer_ids)) {
    $placeholders = str_repeat('?,', count($household_customer_ids) - 1) . '?';
    $stmt = $db->prepare("SELECT COUNT(DISTINCT v.id) as count 
                       FROM visits v 
                       WHERE v.customer_id IN ($placeholders) AND v.visit_type = 'money' AND (v.is_invalid = 0 OR v.is_invalid IS NULL)");
    $stmt->execute($household_customer_ids);
    $household_money_total = $stmt->fetch()['count'];
    
    $current_month = date('Y-m');
    $current_year = date('Y');
    $stmt = $db->prepare("SELECT COUNT(DISTINCT v.id) as count 
                       FROM visits v 
                       WHERE v.customer_id IN ($placeholders) AND v.visit_type = 'money' AND DATE_FORMAT(v.visit_date, '%Y-%m') = ? AND (v.is_invalid = 0 OR v.is_invalid IS NULL)");
    $stmt->execute(array_merge($household_customer_ids, [$current_month]));
    $household_money_month = $stmt->fetch()['count'];
    
    $stmt = $db->prepare("SELECT COUNT(DISTINCT v.id) as count 
                       FROM visits v 
                       WHERE v.customer_id IN ($placeholders) AND v.visit_type = 'money' AND YEAR(v.visit_date) = ? AND (v.is_invalid = 0 OR v.is_invalid IS NULL)");
    $stmt->execute(array_merge($household_customer_ids, [$current_year]));
    $household_money_year = $stmt->fetch()['count'];
    
    // Get last household money visit date
    $stmt = $db->prepare("SELECT MAX(v.visit_date) as last_date 
                       FROM visits v 
                       WHERE v.customer_id IN ($placeholders) AND v.visit_type = 'money' AND (v.is_invalid = 0 OR v.is_invalid IS NULL)");
    $stmt->execute($household_customer_ids);
    $result = $stmt->fetch();
    if ($result && $result['last_date']) {
        $last_money_visit_date = strtotime($result['last_date']);
    }
}

$days_since_last_food_visit = $last_food_visit_date ? floor((time() - $last_food_visit_date) / 86400) : null;
$days_since_last_money_visit = $last_money_visit_date ? floor((time() - $last_money_visit_date) / 86400) : null;
$days_since_last_voucher_visit = $last_voucher_visit_date ? floor((time() - $last_voucher_visit_date) / 86400) : null;

$page_title = "Customer Details";
include 'header.php';
?>

<div class="container">
    <div class="page-header">
        <div class="header-actions">
            <a href="customers.php" class="btn btn-secondary">← Back to Customers</a>
            <?php if (!$edit_mode && (hasPermission('customer_edit') || isAdmin())): ?>
                <a href="customer_view.php?id=<?php echo $customer_id; ?>&edit=1" class="btn btn-primary">
                    <ion-icon name="create"></ion-icon> Edit <?php echo htmlspecialchars(getCustomerTerm('Customer')); ?>
                </a>
                <?php 
                $has_any_visit_permission = (hasPermission('food_visit') || hasPermission('money_visit') || hasPermission('voucher_create') || isAdmin());
                if ($has_any_visit_permission): ?>
                <div class="action-dropdown">
                    <button type="button" class="btn btn-primary">Record Visit <ion-icon name="chevron-down"></ion-icon></button>
                    <ul class="action-dropdown-menu">
                        <?php if (hasPermission('food_visit') || isAdmin()): ?>
                            <li><a href="visits_food.php?customer_id=<?php echo $customer_id; ?>">Food Visit</a></li>
                        <?php endif; ?>
                        <?php if (hasPermission('money_visit') || isAdmin()): ?>
                            <li><a href="visits_money.php?customer_id=<?php echo $customer_id; ?>">Money Visit</a></li>
                        <?php endif; ?>
                        <?php if (hasPermission('voucher_create') || isAdmin()): ?>
                            <li><a href="visits_voucher.php?customer_id=<?php echo $customer_id; ?>">Voucher Visit</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <a href="#visit-history" class="btn btn-secondary">
                    <ion-icon name="arrow-down"></ion-icon> Jump to Visit History
                </a>
            <?php else: ?>
                <a href="customer_view.php?id=<?php echo $customer_id; ?>" class="btn btn-secondary">
                    <ion-icon name="eye"></ion-icon> View Mode
                </a>
                <?php 
                $has_any_visit_permission = (hasPermission('food_visit') || hasPermission('money_visit') || hasPermission('voucher_create') || isAdmin());
                if ($has_any_visit_permission): ?>
                <div class="action-dropdown">
                    <button type="button" class="btn btn-primary">Record Visit <ion-icon name="chevron-down"></ion-icon></button>
                    <ul class="action-dropdown-menu">
                        <?php if (hasPermission('food_visit') || isAdmin()): ?>
                            <li><a href="visits_food.php?customer_id=<?php echo $customer_id; ?>">Food Visit</a></li>
                        <?php endif; ?>
                        <?php if (hasPermission('money_visit') || isAdmin()): ?>
                            <li><a href="visits_money.php?customer_id=<?php echo $customer_id; ?>">Money Visit</a></li>
                        <?php endif; ?>
                        <?php if (hasPermission('voucher_create') || isAdmin()): ?>
                            <li><a href="visits_voucher.php?customer_id=<?php echo $customer_id; ?>">Voucher Visit</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <h1><?php echo htmlspecialchars($customer['name']); ?></h1>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <!-- Visit Limits Box (only show in view mode) -->
    <?php if (!$edit_mode): ?>
        <div class="report-section">
            <h2>Visit Limits</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
                <!-- Food Visit Limits -->
                <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 1.5rem; background: var(--light-bg);">
                    <h3 style="margin-top: 0; margin-bottom: 1rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem;">
                        <ion-icon name="restaurant"></ion-icon> Food Visits
                    </h3>
                    <ul style="list-style: none; padding: 0; margin: 0;">
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid var(--border-color);">
                            <strong>This Month:</strong> 
                            <span style="<?php echo $food_visits_this_month >= $visits_per_month ? 'color: #d32f2f; font-weight: bold;' : ''; ?>">
                                <?php echo $food_visits_this_month; ?>/<?php echo $visits_per_month < 0 ? '∞' : $visits_per_month; ?>
                            </span>
                        </li>
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid var(--border-color);">
                            <strong>This Year:</strong> 
                            <span style="<?php echo $food_visits_this_year >= $visits_per_year ? 'color: #d32f2f; font-weight: bold;' : ''; ?>">
                                <?php echo $food_visits_this_year; ?>/<?php echo $visits_per_year < 0 ? '∞' : $visits_per_year; ?>
                            </span>
                        </li>
                        <li style="padding: 0.5rem 0;">
                            <strong>Min Days Between:</strong> 
                            <?php echo $min_days_between < 0 ? '∞' : $min_days_between; ?> days
                            <?php if ($days_since_last_food_visit !== null): ?>
                                <br><small style="color: var(--text-color-muted);">Last visit: <?php echo $days_since_last_food_visit; ?> days ago</small>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
                
                <!-- Money Visit Limits -->
                <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 1.5rem; background: var(--light-bg);">
                    <h3 style="margin-top: 0; margin-bottom: 1rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem;">
                        <ion-icon name="cash"></ion-icon> Money Visits (Household)
                    </h3>
                    <ul style="list-style: none; padding: 0; margin: 0;">
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid var(--border-color);">
                            <strong>Total:</strong> 
                            <span style="<?php echo $money_limit_total >= 0 && $household_money_total >= $money_limit_total ? 'color: #d32f2f; font-weight: bold;' : ''; ?>">
                                <?php echo $household_money_total; ?>/<?php echo $money_limit_total < 0 ? '∞' : $money_limit_total; ?>
                            </span>
                        </li>
                        <?php if ($money_limit_month >= 0): ?>
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid var(--border-color);">
                            <strong>This Month:</strong> 
                            <span style="<?php echo $household_money_month >= $money_limit_month ? 'color: #d32f2f; font-weight: bold;' : ''; ?>">
                                <?php echo $household_money_month; ?>/<?php echo $money_limit_month; ?>
                            </span>
                        </li>
                        <?php endif; ?>
                        <?php if ($money_limit_year >= 0): ?>
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid var(--border-color);">
                            <strong>This Year:</strong> 
                            <span style="<?php echo $household_money_year >= $money_limit_year ? 'color: #d32f2f; font-weight: bold;' : ''; ?>">
                                <?php echo $household_money_year; ?>/<?php echo $money_limit_year; ?>
                            </span>
                        </li>
                        <?php endif; ?>
                        <?php if ($money_min_days_between >= 0): ?>
                        <li style="padding: 0.5rem 0;">
                            <strong>Min Days Between:</strong> 
                            <?php echo $money_min_days_between; ?> days
                            <?php if ($days_since_last_money_visit !== null): ?>
                                <br><small style="color: var(--text-color-muted);">Last visit: <?php echo $days_since_last_money_visit; ?> days ago</small>
                            <?php endif; ?>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <!-- Voucher Visit Limits -->
                <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 1.5rem; background: var(--light-bg);">
                    <h3 style="margin-top: 0; margin-bottom: 1rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem;">
                        <ion-icon name="ticket"></ion-icon> Voucher Visits
                    </h3>
                    <ul style="list-style: none; padding: 0; margin: 0;">
                        <?php if ($voucher_limit_month >= 0): ?>
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid var(--border-color);">
                            <strong>This Month:</strong> 
                            <span style="<?php echo $voucher_visits_this_month >= $voucher_limit_month ? 'color: #d32f2f; font-weight: bold;' : ''; ?>">
                                <?php echo $voucher_visits_this_month; ?>/<?php echo $voucher_limit_month; ?>
                            </span>
                        </li>
                        <?php endif; ?>
                        <?php if ($voucher_limit_year >= 0): ?>
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid var(--border-color);">
                            <strong>This Year:</strong> 
                            <span style="<?php echo $voucher_visits_this_year >= $voucher_limit_year ? 'color: #d32f2f; font-weight: bold;' : ''; ?>">
                                <?php echo $voucher_visits_this_year; ?>/<?php echo $voucher_limit_year; ?>
                            </span>
                        </li>
                        <?php endif; ?>
                        <?php if ($voucher_min_days_between >= 0): ?>
                        <li style="padding: 0.5rem 0;">
                            <strong>Min Days Between:</strong> 
                            <?php echo $voucher_min_days_between; ?> days
                            <?php if ($days_since_last_voucher_visit !== null): ?>
                                <br><small style="color: var(--text-color-muted);">Last visit: <?php echo $days_since_last_voucher_visit; ?> days ago</small>
                            <?php endif; ?>
                        </li>
                        <?php endif; ?>
                        <?php if ($voucher_limit_month < 0 && $voucher_limit_year < 0 && $voucher_min_days_between < 0): ?>
                        <li style="padding: 0.5rem 0; color: var(--text-color-muted);">
                            No limits configured
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Warning Banners (only show when limits are exceeded) -->
        <?php if ($days_since_last_food_visit !== null && $days_since_last_food_visit < $min_days_between): ?>
            <div class="alert alert-warning">
                <ion-icon name="warning"></ion-icon> Last food visit was <?php echo $days_since_last_food_visit; ?> days ago. Minimum <?php echo $min_days_between; ?> days required between food visits.
            </div>
        <?php endif; ?>
        
        <?php if ($food_visits_this_month >= $visits_per_month && $visits_per_month >= 0): ?>
            <div class="alert alert-error">
                <ion-icon name="close-circle"></ion-icon> Monthly food visit limit reached (<?php echo $food_visits_this_month; ?>/<?php echo $visits_per_month; ?>)
            </div>
        <?php endif; ?>
        
        <?php if ($food_visits_this_year >= $visits_per_year && $visits_per_year >= 0): ?>
            <div class="alert alert-error">
                <ion-icon name="close-circle"></ion-icon> Yearly food visit limit reached (<?php echo $food_visits_this_year; ?>/<?php echo $visits_per_year; ?>)
            </div>
        <?php endif; ?>
        
        <?php if ($money_limit_total >= 0 && $household_money_total >= $money_limit_total): ?>
            <div class="alert alert-error">
                <ion-icon name="close-circle"></ion-icon> Total money visit limit reached (household: <?php echo $household_money_total; ?>/<?php echo $money_limit_total; ?>)
            </div>
        <?php endif; ?>
        
        <?php if ($money_limit_month >= 0 && $household_money_month >= $money_limit_month): ?>
            <div class="alert alert-error">
                <ion-icon name="close-circle"></ion-icon> Monthly money visit limit reached (household: <?php echo $household_money_month; ?>/<?php echo $money_limit_month; ?>)
            </div>
        <?php endif; ?>
        
        <?php if ($money_limit_year >= 0 && $household_money_year >= $money_limit_year): ?>
            <div class="alert alert-error">
                <ion-icon name="close-circle"></ion-icon> Yearly money visit limit reached (household: <?php echo $household_money_year; ?>/<?php echo $money_limit_year; ?>)
            </div>
        <?php endif; ?>
        
        <?php if ($days_since_last_money_visit !== null && $money_min_days_between >= 0 && $days_since_last_money_visit < $money_min_days_between): ?>
            <div class="alert alert-warning">
                <ion-icon name="warning"></ion-icon> Last money visit was <?php echo $days_since_last_money_visit; ?> days ago. Minimum <?php echo $money_min_days_between; ?> days required between money visits.
            </div>
        <?php endif; ?>
        
        <?php if ($voucher_limit_month >= 0 && $voucher_visits_this_month >= $voucher_limit_month): ?>
            <div class="alert alert-error">
                <ion-icon name="close-circle"></ion-icon> Monthly voucher visit limit reached (<?php echo $voucher_visits_this_month; ?>/<?php echo $voucher_limit_month; ?>)
            </div>
        <?php endif; ?>
        
        <?php if ($voucher_limit_year >= 0 && $voucher_visits_this_year >= $voucher_limit_year): ?>
            <div class="alert alert-error">
                <ion-icon name="close-circle"></ion-icon> Yearly voucher visit limit reached (<?php echo $voucher_visits_this_year; ?>/<?php echo $voucher_limit_year; ?>)
            </div>
        <?php endif; ?>
        
        <?php if ($days_since_last_voucher_visit !== null && $voucher_min_days_between >= 0 && $days_since_last_voucher_visit < $voucher_min_days_between): ?>
            <div class="alert alert-warning">
                <ion-icon name="warning"></ion-icon> Last voucher visit was <?php echo $days_since_last_voucher_visit; ?> days ago. Minimum <?php echo $voucher_min_days_between; ?> days required between voucher visits.
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($edit_mode): ?>
        <?php if (!hasPermission('customer_edit') && !isAdmin()): ?>
            <div class="alert alert-error">You do not have permission to edit customers.</div>
            <?php $edit_mode = false; ?>
        <?php else: ?>
        <!-- EDIT MODE -->
        <form method="POST" action="" class="customer-edit-form">
            <input type="hidden" name="save_customer" value="1">
            
            <div class="customer-details-grid">
                <div class="detail-section">
                    <h2>Basic Information</h2>
                    <div class="form-group">
                        <label for="signup_date">Signup Date & Time</label>
                        <div class="checkbox-group">
                            <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                                <input type="checkbox" id="override_signup_date" name="override_signup_date" value="1">
                                <span>Override signup date/time</span>
                            </label>
                        </div>
                        <div id="auto_signup_datetime">
                            <input type="text" value="<?php echo date('F d, Y \a\t g:i A', strtotime($customer['signup_date'])); ?>" readonly class="readonly-field">
                            <small class="help-text">Current signup date and time</small>
                        </div>
                        <div id="manual_signup_datetime" style="display: none;">
                            <input type="datetime-local" id="manual_signup_datetime_input" name="manual_signup_datetime" value="<?php echo date('Y-m-d\TH:i', strtotime($customer['signup_date'])); ?>" class="datetime-input" tabindex="-1">
                            <small class="help-text">Enter the actual signup date and time</small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="name">Name <span class="required">*</span></label>
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($customer['name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Address <span class="required">*</span></label>
                        <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($customer['address']); ?>" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="city">City <span class="required">*</span></label>
                            <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($customer['city']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="state">State <span class="required">*</span></label>
                            <input type="text" id="state" name="state" value="<?php echo htmlspecialchars($customer['state']); ?>" maxlength="2" required>
                        </div>
                        <div class="form-group">
                            <label for="zip">ZIP <span class="required">*</span></label>
                            <input type="text" id="zip" name="zip" value="<?php echo htmlspecialchars($customer['zip']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">Phone <span class="required">*</span></label>
                        <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($customer['phone']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="description_of_need">Description of Need</label>
                        <textarea id="description_of_need" name="description_of_need" rows="4"><?php echo htmlspecialchars($customer['description_of_need'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="applied_before">Applied Before?</label>
                        <select id="applied_before" name="applied_before">
                            <option value="no" <?php echo $customer['applied_before'] === 'no' ? 'selected' : ''; ?>>No</option>
                            <option value="yes" <?php echo $customer['applied_before'] === 'yes' ? 'selected' : ''; ?>>Yes</option>
                        </select>
                    </div>
                </div>

                <div class="detail-section">
                    <h2>Household Members</h2>
                    <div id="household_members">
                        <?php foreach ($household as $idx => $member): ?>
                            <div class="household-member-item" style="margin-bottom: 1rem; padding: 1rem; border: 1px solid var(--border-color); border-radius: 4px; position: relative;">
                                <?php if ($idx > 0): ?>
                                <button type="button" class="btn btn-small" onclick="removeHouseholdMember(this)" style="position: absolute; top: 0.5rem; right: 0.5rem; background-color: var(--danger-color); color: white; border: none; padding: 0.25rem 0.5rem; border-radius: 4px; cursor: pointer;" title="Delete this member">
                                    <ion-icon name="trash"></ion-icon>
                                </button>
                                <?php endif; ?>
                                <div class="form-row">
                                    <div class="form-group" style="flex: 2;">
                                        <label>Name</label>
                                        <input type="text" name="household_names[]" value="<?php echo htmlspecialchars($member['name']); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Birthdate</label>
                                        <input type="date" name="household_birthdates[]" value="<?php echo date('Y-m-d', strtotime($member['birthdate'])); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Relationship</label>
                                        <input type="text" name="household_relationships[]" value="<?php echo htmlspecialchars($member['relationship']); ?>">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addHouseholdMember()">Add Another Member</button>
                </div>


                <div class="detail-section">
                    <h2>Subsidized Housing</h2>
                    <div class="form-group">
                        <label for="subsidized_housing">In Subsidized Housing?</label>
                        <select id="subsidized_housing" name="subsidized_housing">
                            <option value="no" <?php echo (!$housing || $housing['in_subsidized_housing'] === 'no') ? 'selected' : ''; ?>>No</option>
                            <option value="yes" <?php echo ($housing && $housing['in_subsidized_housing'] === 'yes') ? 'selected' : ''; ?>>Yes</option>
                        </select>
                    </div>
                    <div class="form-group" id="rent_amount_group" style="display: <?php echo ($housing && $housing['in_subsidized_housing'] === 'yes') ? 'block' : 'none'; ?>;">
                        <label for="rent_amount">Amount of Rent</label>
                        <input type="number" id="rent_amount" name="rent_amount" step="0.01" min="0" value="<?php echo $housing && $housing['rent_amount'] ? $housing['rent_amount'] : ''; ?>">
                    </div>
                </div>

                <?php if ($income): ?>
                <div class="detail-section">
                    <h2>Household Income</h2>
                    <div class="form-group">
                        <label for="child_support">Child Support</label>
                        <input type="number" id="child_support" name="child_support" step="0.01" min="0" value="<?php echo $income['child_support']; ?>">
                    </div>
                    <div class="form-group">
                        <label for="pension">Pension</label>
                        <input type="number" id="pension" name="pension" step="0.01" min="0" value="<?php echo $income['pension']; ?>">
                    </div>
                    <div class="form-group">
                        <label for="wages">Wages</label>
                        <input type="number" id="wages" name="wages" step="0.01" min="0" value="<?php echo $income['wages']; ?>">
                    </div>
                    <div class="form-group">
                        <label for="ss_ssd_ssi">SS/SSD/SSI</label>
                        <input type="number" id="ss_ssd_ssi" name="ss_ssd_ssi" step="0.01" min="0" value="<?php echo $income['ss_ssd_ssi']; ?>">
                    </div>
                    <div class="form-group">
                        <label for="unemployment">Unemployment</label>
                        <input type="number" id="unemployment" name="unemployment" step="0.01" min="0" value="<?php echo $income['unemployment']; ?>">
                    </div>
                    <div class="form-group">
                        <label for="food_stamps">Food Stamps</label>
                        <input type="number" id="food_stamps" name="food_stamps" step="0.01" min="0" value="<?php echo $income['food_stamps']; ?>">
                    </div>
                    <div class="form-group">
                        <label for="other">Other</label>
                        <input type="number" id="other" name="other" step="0.01" min="0" value="<?php echo $income['other']; ?>">
                    </div>
                    <div class="form-group">
                        <label for="other_description">Other Description</label>
                        <input type="text" id="other_description" name="other_description" value="<?php echo htmlspecialchars($income['other_description'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Total Household Income (calculated automatically)</label>
                        <input type="text" id="total_income" readonly class="readonly-field" value="$0.00">
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="form-actions" style="margin-top: 2rem; padding-top: 2rem; border-top: 2px solid var(--border-color);">
                <button type="submit" class="btn btn-primary btn-large">Save Changes</button>
                <a href="customer_view.php?id=<?php echo $customer_id; ?>" class="btn btn-secondary btn-large">Cancel</a>
            </div>
        </form>
        
        <script>
            // Handle signup date override
            document.getElementById('override_signup_date').addEventListener('change', function() {
                const manualDiv = document.getElementById('manual_signup_datetime');
                const autoDiv = document.getElementById('auto_signup_datetime');
                if (this.checked) {
                    manualDiv.style.display = 'block';
                    autoDiv.style.display = 'none';
                } else {
                    manualDiv.style.display = 'none';
                    autoDiv.style.display = 'block';
                }
            });
            
            // Handle subsidized housing
            document.getElementById('subsidized_housing').addEventListener('change', function() {
                document.getElementById('rent_amount_group').style.display = this.value === 'yes' ? 'block' : 'none';
            });
            
            // Calculate total income
            function updateTotalIncome() {
                const fields = ['child_support', 'pension', 'wages', 'ss_ssd_ssi', 'unemployment', 'food_stamps', 'other'];
                let total = 0;
                fields.forEach(field => {
                    const value = parseFloat(document.getElementById(field).value) || 0;
                    total += value;
                });
                document.getElementById('total_income').value = '$' + total.toFixed(2);
            }
            
            ['child_support', 'pension', 'wages', 'ss_ssd_ssi', 'unemployment', 'food_stamps', 'other'].forEach(field => {
                const el = document.getElementById(field);
                if (el) el.addEventListener('input', updateTotalIncome);
            });
            updateTotalIncome();
            
            // Add household member
            function addHouseholdMember() {
                const container = document.getElementById('household_members');
                const newItem = document.createElement('div');
                newItem.className = 'household-member-item';
                newItem.style.cssText = 'margin-bottom: 1rem; padding: 1rem; border: 1px solid var(--border-color); border-radius: 4px; position: relative;';
                newItem.innerHTML = `
                    <button type="button" class="btn btn-small" onclick="removeHouseholdMember(this)" style="position: absolute; top: 0.5rem; right: 0.5rem; background-color: var(--danger-color); color: white; border: none; padding: 0.25rem 0.5rem; border-radius: 4px; cursor: pointer;" title="Delete this member">
                        <ion-icon name="trash"></ion-icon>
                    </button>
                    <div class="form-row">
                        <div class="form-group" style="flex: 2;">
                            <label>Name</label>
                            <input type="text" name="household_names[]" placeholder="Member name...">
                        </div>
                        <div class="form-group">
                            <label>Birthdate</label>
                            <input type="date" name="household_birthdates[]">
                        </div>
                        <div class="form-group">
                            <label>Relationship</label>
                            <input type="text" name="household_relationships[]" placeholder="e.g., Spouse, Child">
                        </div>
                    </div>
                `;
                container.appendChild(newItem);
            }
            
            // Remove household member
            function removeHouseholdMember(button) {
                if (confirm('Are you sure you want to delete this household member?')) {
                    const memberItem = button.closest('.household-member-item');
                    memberItem.remove();
                }
            }
            
            // Phone formatting (same as signup.php)
            document.getElementById('phone').addEventListener('blur', function() {
                let phoneValue = this.value.replace(/[^0-9]/g, '');
                if (phoneValue.length >= 10) {
                    let phoneNumber = phoneValue.substring(phoneValue.length - 10);
                    let countryCode = phoneValue.length > 10 ? phoneValue.substring(0, phoneValue.length - 10) : '1';
                    let formatted = '+' + countryCode + ' (' + phoneNumber.substring(0, 3) + ') ' + phoneNumber.substring(3, 6) + '-' + phoneNumber.substring(6);
                    this.value = formatted;
                }
            });
        </script>
        <?php endif; ?>
    <?php else: ?>
        <!-- VIEW MODE -->
        <div class="customer-details-grid">
            <div class="detail-section">
                <h2>Basic Information</h2>
                <table class="info-table">
                    <tr>
                        <th>Signup Date & Time:</th>
                        <td><?php echo date('F d, Y \a\t g:i A', strtotime($customer['signup_date'])); ?></td>
                    </tr>
                    <tr>
                        <th>Name:</th>
                        <td><?php echo htmlspecialchars($customer['name']); ?></td>
                    </tr>
                    <tr>
                        <th>Address:</th>
                        <td><?php echo htmlspecialchars($customer['address']); ?></td>
                    </tr>
                    <tr>
                        <th>City, State, ZIP:</th>
                        <td><?php echo htmlspecialchars($customer['city'] . ', ' . $customer['state'] . ' ' . $customer['zip']); ?></td>
                    </tr>
                    <tr>
                        <th>Phone:</th>
                        <td><?php echo htmlspecialchars($customer['phone']); ?></td>
                    </tr>
                    <?php if ($customer['description_of_need']): ?>
                    <tr>
                        <th>Description of Need:</th>
                        <td><?php echo nl2br(htmlspecialchars($customer['description_of_need'])); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>

            <?php if (count($household) > 0): ?>
            <div class="detail-section">
                <h2>Household Members</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Birthdate</th>
                            <th>Relationship</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($household as $member): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($member['name']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($member['birthdate'])); ?></td>
                                <td><?php echo htmlspecialchars($member['relationship']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($customer['applied_before'] === 'yes' && count($prev_apps) > 0): ?>
            <div class="detail-section">
                <h2>Previous Applications</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Name Used</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prev_apps as $app): ?>
                            <tr>
                                <td><?php echo $app['application_date'] ? date('M d, Y', strtotime($app['application_date'])) : 'N/A'; ?></td>
                                <td><?php echo htmlspecialchars($app['name_used']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($housing && $housing['in_subsidized_housing'] === 'yes'): ?>
            <div class="detail-section">
                <h2>Subsidized Housing</h2>
                <table class="info-table">
                    <tr>
                        <th>In Subsidized Housing:</th>
                        <td>Yes</td>
                    </tr>
                    <?php if ($housing['rent_amount']): ?>
                    <tr>
                        <th>Amount of Rent:</th>
                        <td>$<?php echo number_format($housing['rent_amount'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($income): ?>
            <div class="detail-section">
                <h2>Household Income</h2>
                <table class="info-table">
                    <tr>
                        <th>Child Support:</th>
                        <td>$<?php echo number_format($income['child_support'], 2); ?></td>
                    </tr>
                    <tr>
                        <th>Pension:</th>
                        <td>$<?php echo number_format($income['pension'], 2); ?></td>
                    </tr>
                    <tr>
                        <th>Wages:</th>
                        <td>$<?php echo number_format($income['wages'], 2); ?></td>
                    </tr>
                    <tr>
                        <th>SS/SSD/SSI:</th>
                        <td>$<?php echo number_format($income['ss_ssd_ssi'], 2); ?></td>
                    </tr>
                    <tr>
                        <th>Unemployment:</th>
                        <td>$<?php echo number_format($income['unemployment'], 2); ?></td>
                    </tr>
                    <tr>
                        <th>Food Stamps:</th>
                        <td>$<?php echo number_format($income['food_stamps'], 2); ?></td>
                    </tr>
                    <tr>
                        <th>Other:</th>
                        <td>$<?php echo number_format($income['other'], 2); ?></td>
                    </tr>
                    <?php if ($income['other_description']): ?>
                    <tr>
                        <th>Other Description:</th>
                        <td><?php echo htmlspecialchars($income['other_description']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <th>Total Household Income:</th>
                        <td><strong>$<?php echo number_format($income['total_household_income'], 2); ?></strong></td>
                    </tr>
                </table>
            </div>
            <?php endif; ?>

            <div class="detail-section" id="visit-history">
                <h2>Visit History</h2>
                <?php if (count($visits) > 0): ?>
                    <div style="margin-bottom: 1.5rem;">
                        <strong>Visit Summary:</strong>
                        <?php 
                        $visit_summary = [];
                        
                        // Food visits - show total and this month/year
                        if (!empty($visit_counts['food'])) {
                            $visit_summary[] = "Food visits (total): " . $visit_counts['food'];
                        }
                        if ($food_visits_this_month > 0) {
                            $visit_summary[] = "Food visits this month: {$food_visits_this_month}/{$visits_per_month}";
                        }
                        if ($food_visits_this_year > 0) {
                            $visit_summary[] = "Food visits this year: {$food_visits_this_year}/{$visits_per_year}";
                        }
                        
                        // Money visits with limit counter
                        $money_limit = intval(getSetting('money_distribution_limit', 3));
                        if (!empty($visit_counts['money'])) {
                            // Get household money count - first get all customer IDs that share household members
                            $stmt = $db->prepare("SELECT DISTINCT hm2.customer_id 
                                               FROM household_members hm1
                                               INNER JOIN household_members hm2 ON hm1.name = hm2.name
                                               WHERE hm1.customer_id = ?");
                            $stmt->execute([$customer_id]);
                            $household_customer_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            
                            if (!empty($household_customer_ids)) {
                                // Count distinct money visits for all household customers (excluding invalid)
                                $placeholders = str_repeat('?,', count($household_customer_ids) - 1) . '?';
                                $stmt = $db->prepare("SELECT COUNT(DISTINCT v.id) as count 
                                                   FROM visits v 
                                                   WHERE v.customer_id IN ($placeholders) AND v.visit_type = 'money' AND (v.is_invalid = 0 OR v.is_invalid IS NULL)");
                                $stmt->execute($household_customer_ids);
                                $household_money = $stmt->fetch()['count'];
                            } else {
                                $household_money = $visit_counts['money'] ?? 0;
                            }
                            $visit_summary[] = "Money visits (household): {$household_money}/{$money_limit}";
                        }
                        
                        if (!empty($visit_counts['voucher'])) {
                            $visit_summary[] = "Voucher visits (total): " . $visit_counts['voucher'];
                        }
                        
                        echo !empty($visit_summary) ? implode(" | ", $visit_summary) : "No visits";
                        ?>
                    </div>
                    
                    <!-- Visit Type Filter -->
                    <div class="form-group" style="margin-bottom: 1.5rem;">
                        <label for="visit_type_filter">Filter by Visit Type:</label>
                        <select id="visit_type_filter">
                            <option value="all">All Types</option>
                            <option value="food">Food</option>
                            <option value="money">Money</option>
                            <option value="voucher">Voucher</option>
                        </select>
                    </div>
                    
                    <?php 
                    // Separate visits by type
                    $food_visits = array_filter($visits, function($v) { return ($v['visit_type'] ?? 'food') === 'food'; });
                    $money_visits = array_filter($visits, function($v) { return ($v['visit_type'] ?? 'food') === 'money'; });
                    $voucher_visits = array_filter($visits, function($v) { return ($v['visit_type'] ?? 'food') === 'voucher'; });
                    ?>
                    
                    <!-- Food Visits Section -->
                    <div class="visit-type-section" data-visit-type="food" style="margin-bottom: 2rem;">
                        <h3 style="margin-bottom: 1rem; color: var(--primary-color);">Food Visits</h3>
                        <?php if (count($food_visits) > 0): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($food_visits as $visit): ?>
                                        <tr style="<?php echo $visit['is_invalid'] ? 'opacity: 0.6; background-color: #ffebee;' : ''; ?>">
                                            <td><?php echo date('M d, Y \a\t g:i A', strtotime($visit['visit_date'])); ?></td>
                                            <td>
                                                <?php if ($visit['is_invalid']): ?>
                                                    <span style="color: #d32f2f; font-weight: bold;">Invalid</span>
                                                <?php else: ?>
                                                    <span style="color: green;">Valid</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo nl2br(htmlspecialchars($visit['notes'] ?? '')); ?>
                                                <?php if ($visit['is_invalid']): ?>
                                                    <div style="margin-top: 0.5rem; padding: 0.5rem; background: #ffcdd2; border-left: 3px solid #d32f2f;">
                                                        <strong>INVALID:</strong> <?php echo nl2br(htmlspecialchars($visit['invalid_reason'])); ?>
                                                        <br><small>Invalidated by <?php echo htmlspecialchars($visit['invalidated_by_name'] ?? $visit['invalidated_by_username'] ?? 'Unknown'); ?> on <?php echo date('M d, Y g:i A', strtotime($visit['invalidated_at'])); ?></small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                    <?php if (!$visit['is_invalid']): ?>
                                                        <?php if (hasPermission('visit_invalidate') || isAdmin()): ?>
                                                            <button type="button" class="btn btn-small" onclick="showInvalidateVisit(<?php echo $visit['id']; ?>)" style="background-color: #d32f2f; color: white;">Invalidate</button>
                                                        <?php endif; ?>
                                                        <a href="print_visit.php?id=<?php echo $visit['id']; ?>" target="_blank" class="btn btn-small">Print</a>
                                                    <?php else: ?>
                                                        <a href="print_visit.php?id=<?php echo $visit['id']; ?>" target="_blank" class="btn btn-small">Print</a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="no-data">No food visits recorded yet.</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Money Visits Section -->
                    <div class="visit-type-section" data-visit-type="money" style="margin-bottom: 2rem;">
                        <h3 style="margin-bottom: 1rem; color: var(--primary-color);">Money Visits</h3>
                        <?php if (count($money_visits) > 0): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($money_visits as $visit): ?>
                                        <tr style="<?php echo $visit['is_invalid'] ? 'opacity: 0.6; background-color: #ffebee;' : ''; ?>">
                                            <td><?php echo date('M d, Y \a\t g:i A', strtotime($visit['visit_date'])); ?></td>
                                            <td><?php echo !empty($visit['amount']) ? '$' . number_format($visit['amount'], 2) : '-'; ?></td>
                                            <td>
                                                <?php if ($visit['is_invalid']): ?>
                                                    <span style="color: #d32f2f; font-weight: bold;">Invalid</span>
                                                <?php else: ?>
                                                    <span style="color: green;">Valid</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo nl2br(htmlspecialchars($visit['notes'] ?? '')); ?>
                                                <?php if ($visit['is_invalid']): ?>
                                                    <div style="margin-top: 0.5rem; padding: 0.5rem; background: #ffcdd2; border-left: 3px solid #d32f2f;">
                                                        <strong>INVALID:</strong> <?php echo nl2br(htmlspecialchars($visit['invalid_reason'])); ?>
                                                        <br><small>Invalidated by <?php echo htmlspecialchars($visit['invalidated_by_name'] ?? $visit['invalidated_by_username'] ?? 'Unknown'); ?> on <?php echo date('M d, Y g:i A', strtotime($visit['invalidated_at'])); ?></small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                    <?php if (!$visit['is_invalid']): ?>
                                                        <?php if (hasPermission('visit_invalidate') || isAdmin()): ?>
                                                            <button type="button" class="btn btn-small" onclick="showInvalidateVisit(<?php echo $visit['id']; ?>)" style="background-color: #d32f2f; color: white;">Invalidate</button>
                                                        <?php endif; ?>
                                                        <a href="print_visit.php?id=<?php echo $visit['id']; ?>" target="_blank" class="btn btn-small">Print</a>
                                                    <?php else: ?>
                                                        <a href="print_visit.php?id=<?php echo $visit['id']; ?>" target="_blank" class="btn btn-small">Print</a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="no-data">No money visits recorded yet.</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Voucher Visits Section -->
                    <div class="visit-type-section" data-visit-type="voucher" style="margin-bottom: 2rem;">
                        <h3 style="margin-bottom: 1rem; color: var(--primary-color);">Voucher Visits</h3>
                        <?php if (count($voucher_visits) > 0): ?>
                            <?php
                            // Get all vouchers for this customer first
                            $stmt = $db->prepare("SELECT * FROM vouchers WHERE customer_id = ? ORDER BY issued_date DESC");
                            $stmt->execute([$customer_id]);
                            $all_vouchers = $stmt->fetchAll();
                            
                            // Match vouchers to visits by finding the closest issued_date to visit_date
                            $voucher_details = [];
                            foreach ($voucher_visits as $visit) {
                                $visit_timestamp = strtotime($visit['visit_date']);
                                $best_match = null;
                                $smallest_diff = null;
                                
                                foreach ($all_vouchers as $voucher) {
                                    $voucher_timestamp = strtotime($voucher['issued_date']);
                                    $diff = abs($visit_timestamp - $voucher_timestamp);
                                    
                                    // Match if issued within 5 minutes of visit (vouchers are created right after visits)
                                    if ($diff <= 300) {
                                        if ($best_match === null || $diff < $smallest_diff) {
                                            $best_match = $voucher;
                                            $smallest_diff = $diff;
                                        }
                                    }
                                }
                                
                                if ($best_match) {
                                    $voucher_details[$visit['id']] = $best_match;
                                }
                            }
                            ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Code</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($voucher_visits as $visit): ?>
                                        <tr style="<?php echo $visit['is_invalid'] ? 'opacity: 0.6; background-color: #ffebee;' : ''; ?>">
                                            <td><?php echo date('M d, Y \a\t g:i A', strtotime($visit['visit_date'])); ?></td>
                                            <td>
                                                <?php 
                                                if (isset($voucher_details[$visit['id']])) {
                                                    echo '<strong>' . htmlspecialchars($voucher_details[$visit['id']]['voucher_code']) . '</strong>';
                                                } else {
                                                    echo '-';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if (isset($voucher_details[$visit['id']])) {
                                                    echo '$' . number_format($voucher_details[$visit['id']]['amount'], 2);
                                                } else {
                                                    echo '-';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if ($visit['is_invalid']) {
                                                    echo '<span style="color: #d32f2f; font-weight: bold;">Invalid</span>';
                                                } elseif (isset($voucher_details[$visit['id']])) {
                                                    $status = $voucher_details[$visit['id']]['status'];
                                                    if ($status === 'active') {
                                                        echo '<span style="color: green;">Active</span>';
                                                    } elseif ($status === 'redeemed') {
                                                        echo '<span style="color: blue;">Redeemed</span>';
                                                    } else {
                                                        echo '<span style="color: red;">Expired</span>';
                                                    }
                                                } else {
                                                    echo '-';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php echo nl2br(htmlspecialchars($visit['notes'] ?? '')); ?>
                                                <?php if ($visit['is_invalid']): ?>
                                                    <div style="margin-top: 0.5rem; padding: 0.5rem; background: #ffcdd2; border-left: 3px solid #d32f2f;">
                                                        <strong>INVALID:</strong> <?php echo nl2br(htmlspecialchars($visit['invalid_reason'])); ?>
                                                        <br><small>Invalidated by <?php echo htmlspecialchars($visit['invalidated_by_name'] ?? $visit['invalidated_by_username'] ?? 'Unknown'); ?> on <?php echo date('M d, Y g:i A', strtotime($visit['invalidated_at'])); ?></small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                    <?php if (isset($voucher_details[$visit['id']])): ?>
                                                        <?php if ($voucher_details[$visit['id']]['status'] === 'active'): ?>
                                                            <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to revoke this voucher?');">
                                                                <input type="hidden" name="voucher_code" value="<?php echo htmlspecialchars($voucher_details[$visit['id']]['voucher_code']); ?>">
                                                                <button type="submit" name="revoke_voucher" class="btn btn-small" style="background-color: #d32f2f; color: white;">Revoke</button>
                                                            </form>
                                                            <?php if (hasPermission('voucher_redeem') || isAdmin()): ?>
                                                                <a href="voucher_redemption.php?code=<?php echo urlencode($voucher_details[$visit['id']]['voucher_code']); ?>" class="btn btn-small" style="background-color: var(--success-color); color: white;">Redeem</a>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                        <a href="print_voucher.php?code=<?php echo urlencode($voucher_details[$visit['id']]['voucher_code']); ?>" target="_blank" class="btn btn-small">Print</a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="no-data">No voucher visits recorded yet.</p>
                        <?php endif; ?>
                    </div>
                    
                    <script>
                        document.getElementById('visit_type_filter').addEventListener('change', function() {
                            const filterValue = this.value;
                            const sections = document.querySelectorAll('.visit-type-section');
                            
                            sections.forEach(section => {
                                if (filterValue === 'all' || section.dataset.visitType === filterValue) {
                                    section.style.display = 'block';
                                } else {
                                    section.style.display = 'none';
                                }
                            });
                        });
                    </script>
                <?php else: ?>
                    <p class="no-data">No visits recorded yet.</p>
                <?php endif; ?>
            </div>

            <!-- Audit Trail Section -->
            <?php if ($total_audit_logs > 0 && (hasPermission('customer_history_view') || isAdmin())): ?>
            <div class="detail-section">
                <h2>Change History</h2>
                <p style="margin-bottom: 1rem; color: var(--text-color-muted);">
                    Showing <?php echo number_format($audit_offset + 1); ?>-<?php echo number_format(min($audit_offset + $audit_logs_per_page, $total_audit_logs)); ?> of <?php echo number_format($total_audit_logs); ?> total changes
                </p>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Field</th>
                            <th>Old Value</th>
                            <th>New Value</th>
                            <th>Changed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audit_trail as $audit): ?>
                            <tr>
                                <td><?php echo date('M d, Y \a\t g:i A', strtotime($audit['changed_at'])); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $audit['field_name']))); ?></td>
                                <td><?php echo htmlspecialchars($audit['old_value'] ?? '(empty)'); ?></td>
                                <td><?php echo htmlspecialchars($audit['new_value'] ?? '(empty)'); ?></td>
                                <td><?php echo htmlspecialchars($audit['full_name'] ?? $audit['username'] ?? 'Unknown'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($total_audit_pages > 1): ?>
                    <div style="margin-top: 2rem; display: flex; justify-content: center; align-items: center; gap: 1rem;">
                        <?php if ($audit_page > 1): ?>
                            <a href="?id=<?php echo $customer_id; ?>&audit_page=<?php echo $audit_page - 1; ?>" class="btn btn-secondary">← Previous</a>
                        <?php endif; ?>
                        
                        <span style="color: var(--text-color-muted);">
                            Page <?php echo $audit_page; ?> of <?php echo $total_audit_pages; ?>
                        </span>
                        
                        <?php if ($audit_page < $total_audit_pages): ?>
                            <a href="?id=<?php echo $customer_id; ?>&audit_page=<?php echo $audit_page + 1; ?>" class="btn btn-secondary">Next →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Invalidate Visit Modal -->
<div id="invalidateVisitModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; justify-content: center; align-items: center;">
    <div style="background: white; padding: 2rem; border-radius: 8px; max-width: 500px; width: 90%;">
        <h2>Invalidate Visit</h2>
        <p style="color: #d32f2f; margin-bottom: 1rem;"><strong>Warning:</strong> This will mark the visit as invalid. The visit will remain in the system but will be clearly marked as invalid with the reason provided.</p>
        <form method="POST" action="">
            <input type="hidden" id="invalidate_visit_id" name="visit_id">
            <div class="form-group">
                <label for="invalid_reason">Reason for Invalidation <span class="required">*</span></label>
                <textarea id="invalid_reason" name="invalid_reason" rows="4" required placeholder="e.g., Mistakenly submitted, duplicate entry, etc."></textarea>
                <small class="help-text">This reason will be displayed alongside the visit record</small>
            </div>
            <div class="form-actions">
                <button type="submit" name="invalidate_visit" class="btn btn-primary">Mark as Invalid</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('invalidateVisitModal').style.display='none';">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function showInvalidateVisit(visitId) {
    document.getElementById('invalidate_visit_id').value = visitId;
    document.getElementById('invalid_reason').value = '';
    document.getElementById('invalidateVisitModal').style.display = 'flex';
}
</script>

<style>
.action-dropdown {
    position: relative;
    display: inline-block;
    vertical-align: middle;
    margin-left: 0.5rem;
}

.action-dropdown button {
    cursor: pointer;
    border: var(--border-width) var(--border-style) var(--accent-color);
    font-family: inherit;
    font-size: inherit;
    line-height: inherit;
    vertical-align: baseline;
    margin: 0;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
}

.action-dropdown button ion-icon {
    font-size: 0.8rem;
    vertical-align: middle;
    margin-left: 0.25rem;
}

.action-dropdown-menu {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    background-color: var(--white);
    box-shadow: var(--shadow-lg);
    border-radius: 4px;
    min-width: 160px;
    padding: 0.5rem 0;
    margin-top: 0.25rem;
    padding-top: 0.75rem;
    padding-bottom: 0.75rem;
    list-style: none;
    z-index: 1000;
    white-space: nowrap;
}

.action-dropdown-menu::before {
    content: '';
    position: absolute;
    top: -10px;
    left: -10px;
    right: -10px;
    height: 10px;
    background: transparent;
    pointer-events: auto;
}

.action-dropdown:hover .action-dropdown-menu,
.action-dropdown-menu:hover {
    display: block;
}

.action-dropdown-menu li {
    margin: 0;
}

.action-dropdown-menu a {
    display: block;
    padding: 0.5rem 1rem;
    color: var(--text-color);
    text-decoration: none;
    transition: background-color 0.2s;
}

.action-dropdown-menu a:hover {
    background-color: var(--light-bg);
}
</style>

<?php include 'footer.php'; ?>

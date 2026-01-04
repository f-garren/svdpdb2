<?php
/**
 * Helper Functions for NexusDB
 * Common utilities used across the application
 */

// Format phone number to standard format
function formatPhone($phone) {
    $phone_digits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone_digits) >= 10) {
        $phone_number = substr($phone_digits, -10);
        $country_code = strlen($phone_digits) > 10 ? substr($phone_digits, 0, -10) : '1';
        return '+' . $country_code . ' (' . substr($phone_number, 0, 3) . ') ' . substr($phone_number, 3, 3) . '-' . substr($phone_number, 6);
    }
    return $phone;
}

// Get visit date from POST data (handles override)
function getVisitDate($post_data) {
    if (!empty($post_data['override_visit_date']) && !empty($post_data['manual_visit_datetime'])) {
        return date('Y-m-d H:i:s', strtotime($post_data['manual_visit_datetime']));
    }
    return date('Y-m-d H:i:s');
}

// Get signup date from POST data (handles override)
function getSignupDate($post_data) {
    if (!empty($post_data['override_signup_date']) && !empty($post_data['manual_signup_datetime'])) {
        return date('Y-m-d H:i:s', strtotime($post_data['manual_signup_datetime']));
    }
    return date('Y-m-d H:i:s');
}

// Generate hidden form fields for confirmation screen
function generateHiddenFields($form_data) {
    $html = '';
    foreach ($form_data as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $sub_key => $sub_value) {
                $html .= '<input type="hidden" name="' . htmlspecialchars($key . '[' . $sub_key . ']') . '" value="' . htmlspecialchars($sub_value) . '">' . "\n";
            }
        } else {
            $html .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">' . "\n";
        }
    }
    return $html;
}

// Get household customer IDs (for money visit limits)
function getHouseholdCustomerIds($db, $customer_id) {
    $stmt = $db->prepare("SELECT name FROM household_members WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $household_names = array_column($stmt->fetchAll(), 'name');
    
    $household_customer_ids = [$customer_id];
    if (!empty($household_names)) {
        $placeholders = str_repeat('?,', count($household_names) - 1) . '?';
        $stmt = $db->prepare("SELECT DISTINCT customer_id FROM household_members WHERE name IN ($placeholders)");
        $stmt->execute($household_names);
        $related_customers = array_column($stmt->fetchAll(), 'customer_id');
        $household_customer_ids = array_unique(array_merge($household_customer_ids, $related_customers));
    }
    return $household_customer_ids;
}

// Check visit limit (monthly/yearly/total) - excludes invalid visits
function checkVisitLimit($db, $customer_ids, $visit_type, $period, $limit, $visit_date) {
    if ($limit < 0) return ['allowed' => true]; // Unlimited
    
    $placeholders = str_repeat('?,', count($customer_ids) - 1) . '?';
    
    if ($period === 'month') {
        $visit_month = date('Y-m', strtotime($visit_date));
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM visits WHERE customer_id IN ($placeholders) AND visit_type = ? AND DATE_FORMAT(visit_date, '%Y-%m') = ? AND (is_invalid = 0 OR is_invalid IS NULL)");
        $stmt->execute(array_merge($customer_ids, [$visit_type, $visit_month]));
    } elseif ($period === 'year') {
        $visit_year = date('Y', strtotime($visit_date));
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM visits WHERE customer_id IN ($placeholders) AND visit_type = ? AND YEAR(visit_date) = ? AND (is_invalid = 0 OR is_invalid IS NULL)");
        $stmt->execute(array_merge($customer_ids, [$visit_type, $visit_year]));
    } else { // total or other
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM visits WHERE customer_id IN ($placeholders) AND visit_type = ? AND (is_invalid = 0 OR is_invalid IS NULL)");
        $stmt->execute(array_merge($customer_ids, [$visit_type]));
    }
    
    $count = $stmt->fetch()['count'];
    $allowed = $limit == 0 ? false : ($count < $limit);
    
    return [
        'allowed' => $allowed,
        'count' => $count,
        'limit' => $limit
    ];
}

// Check minimum days between visits - excludes invalid visits
function checkMinDaysBetween($db, $customer_ids, $visit_type, $min_days, $visit_date) {
    if ($min_days <= 0) return ['allowed' => true]; // Unlimited or disabled
    
    $placeholders = str_repeat('?,', count($customer_ids) - 1) . '?';
    $stmt = $db->prepare("SELECT MAX(visit_date) as last_visit FROM visits WHERE customer_id IN ($placeholders) AND visit_type = ? AND visit_date < ? AND (is_invalid = 0 OR is_invalid IS NULL)");
    $stmt->execute(array_merge($customer_ids, [$visit_type, $visit_date]));
    $last_visit = $stmt->fetch()['last_visit'];
    
    if (!$last_visit) return ['allowed' => true];
    
    $days_since = floor((strtotime($visit_date) - strtotime($last_visit)) / 86400);
    return [
        'allowed' => $days_since >= $min_days,
        'days_since' => $days_since,
        'min_days' => $min_days
    ];
}

// Format limit text for display
function formatLimitText($limit) {
    if ($limit < 0) return 'unlimited';
    if ($limit == 0) return 'disabled';
    return (string)$limit;
}

// Log audit trail entry
function logAuditTrail($db, $customer_id, $field_name, $old_value, $new_value, $changed_by = null) {
    if ($old_value === $new_value) {
        return; // No change, don't log
    }
    
    if ($changed_by === null) {
        require_once __DIR__ . '/auth.php';
        $changed_by = getCurrentEmployeeId();
    }
    
    $stmt = $db->prepare("INSERT INTO customer_audit (customer_id, field_name, old_value, new_value, changed_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $customer_id,
        $field_name,
        $old_value !== null ? (string)$old_value : null,
        $new_value !== null ? (string)$new_value : null,
        $changed_by
    ]);
    
    // Also log to employee audit
    logEmployeeAction($db, $changed_by, 'customer_edit', 'customer', $customer_id, "Field '{$field_name}' changed from '{$old_value}' to '{$new_value}'");
}

// Log employee action
function logEmployeeAction($db, $employee_id, $action_type, $target_type = null, $target_id = null, $details = null) {
    if ($employee_id === null) {
        require_once __DIR__ . '/auth.php';
        $employee_id = getCurrentEmployeeId();
    }
    
    if ($employee_id === null) {
        return; // Can't log if no employee ID
    }
    
    $stmt = $db->prepare("INSERT INTO employee_audit (employee_id, action_type, target_type, target_id, details) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $employee_id,
        $action_type,
        $target_type,
        $target_id,
        $details
    ]);
}


<?php
require_once 'config.php';

$customer_id = intval($_GET['customer_id'] ?? 0);
$visit_type = $_GET['visit_type'] ?? 'food';
$db = getDB();

$response = ['eligible' => true, 'errors' => []];

if ($customer_id <= 0) {
    echo json_encode(['eligible' => false, 'errors' => ['Invalid customer ID']]);
    exit;
}

try {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    
    if (!$customer) {
        $response['eligible'] = false;
        $response['errors'][] = 'Customer not found';
        echo json_encode($response);
        exit;
    }
    
    if ($visit_type === 'food') {
        $visits_per_month = intval(getSetting('visits_per_month_limit', 2));
        $visits_per_year = intval(getSetting('visits_per_year_limit', 12));
        $min_days_between = intval(getSetting('min_days_between_visits', 14));
        
        $visit_date = date('Y-m-d H:i:s');
        $customer_ids = [$customer_id];
        
        $month_check = checkVisitLimit($db, $customer_ids, 'food', 'month', $visits_per_month, $visit_date);
        if (!$month_check['allowed']) {
            $response['eligible'] = false;
            $response['errors'][] = "Monthly food visit limit reached ({$month_check['count']}/" . formatLimitText($month_check['limit']) . ")";
        }
        
        $year_check = checkVisitLimit($db, $customer_ids, 'food', 'year', $visits_per_year, $visit_date);
        if (!$year_check['allowed']) {
            $response['eligible'] = false;
            $response['errors'][] = "Yearly food visit limit reached ({$year_check['count']}/" . formatLimitText($year_check['limit']) . ")";
        }
        
        $days_check = checkMinDaysBetween($db, $customer_ids, 'food', $min_days_between, $visit_date);
        if (!$days_check['allowed']) {
                    $response['eligible'] = false;
            $response['errors'][] = "Minimum {$days_check['min_days']} days required between visits (last visit was {$days_check['days_since']} days ago)";
        }
    } elseif ($visit_type === 'money') {
        $money_limit = intval(getSetting('money_distribution_limit', 3));
        $money_limit_month = intval(getSetting('money_distribution_limit_month', -1));
        $money_limit_year = intval(getSetting('money_distribution_limit_year', -1));
        $money_min_days_between = intval(getSetting('money_min_days_between', -1));
        
        $visit_date = date('Y-m-d H:i:s');
        $household_customer_ids = getHouseholdCustomerIds($db, $customer_id);
        
        $total_check = checkVisitLimit($db, $household_customer_ids, 'money', 'total', $money_limit, $visit_date);
        if (!$total_check['allowed']) {
            $response['eligible'] = false;
            $response['errors'][] = "Money assistance limit reached ({$total_check['count']}/" . formatLimitText($total_check['limit']) . ")";
        }
        
        $month_check = checkVisitLimit($db, $household_customer_ids, 'money', 'month', $money_limit_month, $visit_date);
        if (!$month_check['allowed']) {
            $response['eligible'] = false;
            $response['errors'][] = "Monthly money assistance limit reached ({$month_check['count']}/" . formatLimitText($month_check['limit']) . ")";
        }
        
        $year_check = checkVisitLimit($db, $household_customer_ids, 'money', 'year', $money_limit_year, $visit_date);
        if (!$year_check['allowed']) {
            $response['eligible'] = false;
            $response['errors'][] = "Yearly money assistance limit reached ({$year_check['count']}/" . formatLimitText($year_check['limit']) . ")";
        }
        
        $days_check = checkMinDaysBetween($db, $household_customer_ids, 'money', $money_min_days_between, $visit_date);
        if (!$days_check['allowed']) {
                    $response['eligible'] = false;
            $response['errors'][] = "Minimum {$days_check['min_days']} days required between money visits (last visit was {$days_check['days_since']} days ago)";
        }
    } elseif ($visit_type === 'voucher') {
        $voucher_limit_month = intval(getSetting('voucher_limit_month', -1));
        $voucher_limit_year = intval(getSetting('voucher_limit_year', -1));
        $voucher_min_days_between = intval(getSetting('voucher_min_days_between', -1));
        
        $visit_date = date('Y-m-d H:i:s');
        $customer_ids = [$customer_id];
        
        $month_check = checkVisitLimit($db, $customer_ids, 'voucher', 'month', $voucher_limit_month, $visit_date);
        if (!$month_check['allowed']) {
            $response['eligible'] = false;
            $response['errors'][] = "Monthly voucher limit reached ({$month_check['count']}/" . formatLimitText($month_check['limit']) . ")";
        }
        
        $year_check = checkVisitLimit($db, $customer_ids, 'voucher', 'year', $voucher_limit_year, $visit_date);
        if (!$year_check['allowed']) {
            $response['eligible'] = false;
            $response['errors'][] = "Yearly voucher limit reached ({$year_check['count']}/" . formatLimitText($year_check['limit']) . ")";
        }
        
        $days_check = checkMinDaysBetween($db, $customer_ids, 'voucher', $voucher_min_days_between, $visit_date);
        if (!$days_check['allowed']) {
                    $response['eligible'] = false;
            $response['errors'][] = "Minimum {$days_check['min_days']} days required between voucher visits (last visit was {$days_check['days_since']} days ago)";
        }
    }
} catch (Exception $e) {
    $response['eligible'] = false;
    $response['errors'][] = 'Error checking eligibility: ' . $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response);
?>


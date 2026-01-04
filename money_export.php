<?php
require_once 'config.php';

$db = getDB();

// Get all money visits with household money count
$stmt = $db->query("SELECT v.*, c.name as customer_name, c.phone, c.address, c.city, c.state,
                   (SELECT COUNT(DISTINCT v2.id) 
                    FROM visits v2
                    INNER JOIN household_members hm1 ON v2.customer_id = hm1.customer_id
                    INNER JOIN household_members hm2 ON hm1.name = hm2.name
                    WHERE hm2.customer_id = c.id 
                    AND v2.visit_type = 'money') as household_money_count
                   FROM visits v 
                   INNER JOIN customers c ON v.customer_id = c.id 
                   WHERE v.visit_type = 'money'
                   ORDER BY v.visit_date DESC");
$money_visits = $stmt->fetchAll();

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="money_visits_' . date('Y-m-d_His') . '.csv"');

// Open output stream
$output = fopen('php://output', 'w');

// Write CSV header
fputcsv($output, ['Date', 'Customer Name', 'Phone', 'Address', 'City', 'State', 'Household Money Visits', 'Notes']);

// Write data rows
foreach ($money_visits as $visit) {
    fputcsv($output, [
        date('Y-m-d H:i:s', strtotime($visit['visit_date'])),
        $visit['customer_name'],
        $visit['phone'],
        $visit['address'],
        $visit['city'],
        $visit['state'],
        $visit['household_money_count'] ?? 0,
        $visit['notes'] ?? ''
    ]);
}

fclose($output);
exit;
?>


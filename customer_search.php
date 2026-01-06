<?php
require_once 'config.php';

header('Content-Type: application/json');

$query = $_GET['q'] ?? '';
$customer_id = $_GET['id'] ?? null;
$db = getDB();

// If ID is provided, return full customer details with household members
if (!empty($customer_id)) {
    $stmt = $db->prepare("SELECT c.*, 
                         (SELECT COUNT(*) FROM visits WHERE customer_id = c.id AND (is_invalid = 0 OR is_invalid IS NULL)) as visit_count
                         FROM customers c 
                         WHERE c.id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    if ($customer) {
        // Get household members
        $stmt = $db->prepare("SELECT name, birthdate, relationship FROM household_members WHERE customer_id = ? ORDER BY name");
        $stmt->execute([$customer_id]);
        $household_members = $stmt->fetchAll();
        $customer['household_members'] = $household_members;
        echo json_encode([$customer]);
    } else {
        echo json_encode([]);
    }
    exit;
}

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// Search customers by name, phone, address, city, state, or household member names
// When a household member matches, we need to return the primary customer info with indication
$search_term = "%$query%";

// First, find customers that match directly
$stmt = $db->prepare("SELECT DISTINCT c.id, c.name, c.phone, c.city, c.state, c.address
                      FROM customers c
                      WHERE c.name LIKE ? 
                         OR c.phone LIKE ? 
                         OR c.address LIKE ?
                         OR c.city LIKE ?
                         OR c.state LIKE ?
                      ORDER BY c.name 
                      LIMIT 10");
$stmt->execute([$search_term, $search_term, $search_term, $search_term, $search_term]);
$direct_matches = $stmt->fetchAll();

// Then, find customers that match via household members
$stmt = $db->prepare("SELECT DISTINCT c.id, c.name as customer_name, c.phone, c.city, c.state, c.address,
                      hm.name as household_member_name
                      FROM customers c
                      INNER JOIN household_members hm ON c.id = hm.customer_id
                      WHERE hm.name LIKE ?
                      ORDER BY c.name 
                      LIMIT 10");
$stmt->execute([$search_term]);
$household_matches = $stmt->fetchAll();

// Combine and deduplicate results
$results = [];
$seen_customer_ids = [];

// Add direct matches
foreach ($direct_matches as $row) {
    if (!in_array($row['id'], $seen_customer_ids)) {
        $results[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'phone' => $row['phone'],
            'city' => $row['city'],
            'state' => $row['state'],
            'address' => $row['address'],
            'is_household_match' => false
        ];
        $seen_customer_ids[] = $row['id'];
    }
}

// Add household member matches
foreach ($household_matches as $row) {
    if (!in_array($row['id'], $seen_customer_ids)) {
        $results[] = [
            'id' => $row['id'],
            'name' => $row['customer_name'],
            'phone' => $row['phone'],
            'city' => $row['city'],
            'state' => $row['state'],
            'address' => $row['address'],
            'household_member_name' => $row['household_member_name'],
            'is_household_match' => true
        ];
        $seen_customer_ids[] = $row['id'];
    }
}

// Limit to 10 total results
$results = array_slice($results, 0, 10);

echo json_encode($results);


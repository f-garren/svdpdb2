<?php
require_once 'config.php';

header('Content-Type: application/json');

$query = $_GET['q'] ?? '';
$customer_id = $_GET['id'] ?? null;
$db = getDB();

// If ID is provided, return full customer details
if (!empty($customer_id)) {
    $stmt = $db->prepare("SELECT c.*, 
                         (SELECT COUNT(*) FROM visits WHERE customer_id = c.id AND (is_invalid = 0 OR is_invalid IS NULL)) as visit_count
                         FROM customers c 
                         WHERE c.id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    if ($customer) {
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
$stmt = $db->prepare("SELECT DISTINCT c.id, c.name, c.phone, c.city, c.state, c.address
                      FROM customers c
                      LEFT JOIN household_members hm ON c.id = hm.customer_id
                      WHERE c.name LIKE ? 
                         OR c.phone LIKE ? 
                         OR c.address LIKE ?
                         OR c.city LIKE ?
                         OR c.state LIKE ?
                         OR hm.name LIKE ?
                      ORDER BY c.name 
                      LIMIT 10");
$search_term = "%$query%";
$stmt->execute([$search_term, $search_term, $search_term, $search_term, $search_term, $search_term]);
$results = $stmt->fetchAll();

echo json_encode($results);


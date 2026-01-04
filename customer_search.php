<?php
require_once 'config.php';

header('Content-Type: application/json');

$query = $_GET['q'] ?? '';
$db = getDB();

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


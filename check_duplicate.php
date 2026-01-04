<?php
require_once 'config.php';

$field = $_GET['field'] ?? '';
$value = $_GET['value'] ?? '';
$db = getDB();

$response = ['duplicate' => false, 'message' => ''];

if (empty($field) || empty($value)) {
    echo json_encode($response);
    exit;
}

try {
    switch ($field) {
        case 'phone':
            $stmt = $db->prepare("SELECT id, name FROM customers WHERE phone = ? LIMIT 1");
            $stmt->execute([$value]);
            $customer = $stmt->fetch();
            if ($customer) {
                $response['duplicate'] = true;
                $response['message'] = "Phone number already exists for customer: " . htmlspecialchars($customer['name']);
                $response['customer_id'] = $customer['id'];
            }
            break;
        
        case 'name':
            // Check name + phone combination
            $phone = $_GET['phone'] ?? '';
            if (!empty($phone)) {
                $stmt = $db->prepare("SELECT id, name, phone FROM customers WHERE name = ? AND phone = ? LIMIT 1");
                $stmt->execute([$value, $phone]);
                $customer = $stmt->fetch();
                if ($customer) {
                    $response['duplicate'] = true;
                    $response['message'] = "Customer with this name and phone already exists";
                    $response['customer_id'] = $customer['id'];
                }
            }
            break;
    }
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response);
?>


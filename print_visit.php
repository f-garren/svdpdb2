<?php
require_once 'config.php';
require_once 'auth.php';

requireLogin();

$visit_id = intval($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("SELECT v.*, c.name as customer_name, c.address, c.city, c.state, c.zip, c.phone,
    e.username as invalidated_by_username, e.full_name as invalidated_by_name
    FROM visits v
    INNER JOIN customers c ON v.customer_id = c.id
    LEFT JOIN employees e ON v.invalidated_by = e.id
    WHERE v.id = ?");
$stmt->execute([$visit_id]);
$visit = $stmt->fetch();

if (!$visit) {
    die('Visit not found');
}

$organization_name = getSetting('organization_name', 'NexusDB');
$shop_name = getSetting('shop_name', 'Partner Store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visit Receipt - <?php echo htmlspecialchars($organization_name); ?></title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
        }
        body {
            font-family: Arial, sans-serif;
            max-width: 8.5in;
            margin: 0 auto;
            padding: 0.5in;
        }
        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 1rem;
            margin-bottom: 1rem;
        }
        .receipt-header h1 {
            margin: 0;
            font-size: 24pt;
        }
        .receipt-info {
            margin: 1rem 0;
        }
        .receipt-info table {
            width: 100%;
            border-collapse: collapse;
        }
        .receipt-info td {
            padding: 0.5rem;
            border-bottom: 1px solid #ddd;
        }
        .receipt-info td:first-child {
            font-weight: bold;
            width: 40%;
        }
        .invalid-notice {
            background: #ffebee;
            border: 2px solid #d32f2f;
            padding: 1rem;
            margin: 1rem 0;
            text-align: center;
        }
        .footer {
            margin-top: 2rem;
            text-align: center;
            font-size: 10pt;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 1rem;">
        <button onclick="window.print()" style="padding: 0.5rem 1rem; font-size: 16px; cursor: pointer;">Print Receipt</button>
        <button onclick="window.close()" style="padding: 0.5rem 1rem; font-size: 16px; cursor: pointer; margin-left: 1rem;">Close</button>
    </div>

    <div class="receipt-header">
        <h1><?php echo htmlspecialchars($organization_name); ?></h1>
        <p>Visit Receipt</p>
    </div>

    <?php if ($visit['is_invalid']): ?>
        <div class="invalid-notice">
            <strong style="color: #d32f2f; font-size: 18pt;">INVALID VISIT</strong>
            <p><strong>Reason:</strong> <?php echo nl2br(htmlspecialchars($visit['invalid_reason'])); ?></p>
            <p>Invalidated by <?php echo htmlspecialchars($visit['invalidated_by_name'] ?? $visit['invalidated_by_username'] ?? 'Unknown'); ?> on <?php echo date('F d, Y \a\t g:i A', strtotime($visit['invalidated_at'])); ?></p>
        </div>
    <?php endif; ?>

    <div class="receipt-info">
        <table>
            <tr>
                <td>Visit Date & Time:</td>
                <td><?php echo date('F d, Y \a\t g:i A', strtotime($visit['visit_date'])); ?></td>
            </tr>
            <tr>
                <td>Visit Type:</td>
                <td><?php echo ucfirst($visit['visit_type']); ?></td>
            </tr>
            <tr>
                <td>Customer Name:</td>
                <td><?php echo htmlspecialchars($visit['customer_name']); ?></td>
            </tr>
            <tr>
                <td>Address:</td>
                <td><?php echo htmlspecialchars($visit['address'] . ', ' . $visit['city'] . ', ' . $visit['state'] . ' ' . $visit['zip']); ?></td>
            </tr>
            <tr>
                <td>Phone:</td>
                <td><?php echo htmlspecialchars($visit['phone']); ?></td>
            </tr>
            <?php if ($visit['visit_type'] === 'money' && !empty($visit['amount'])): ?>
            <tr>
                <td>Amount:</td>
                <td><strong>$<?php echo number_format($visit['amount'], 2); ?></strong></td>
            </tr>
            <?php endif; ?>
            <?php if (!empty($visit['notes'])): ?>
            <tr>
                <td>Notes:</td>
                <td><?php echo nl2br(htmlspecialchars($visit['notes'])); ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="footer">
        <p>Generated on <?php echo date('F d, Y \a\t g:i A'); ?></p>
        <p><?php echo htmlspecialchars($organization_name); ?></p>
    </div>
</body>
</html>


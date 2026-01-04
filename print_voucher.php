<?php
require_once 'config.php';
require_once 'auth.php';

requireLogin();

$voucher_code = $_GET['code'] ?? '';
$db = getDB();

$stmt = $db->prepare("SELECT v.*, c.name as customer_name, c.address, c.city, c.state, c.zip, c.phone
    FROM vouchers v
    INNER JOIN customers c ON v.customer_id = c.id
    WHERE v.voucher_code = ?");
$stmt->execute([$voucher_code]);
$voucher = $stmt->fetch();

if (!$voucher) {
    die('Voucher not found');
}

$organization_name = getSetting('organization_name', 'NexusDB');
$shop_name = getSetting('shop_name', 'Partner Store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voucher Receipt - <?php echo htmlspecialchars($organization_name); ?></title>
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
        .voucher-code {
            text-align: center;
            font-size: 32pt;
            font-weight: bold;
            color: var(--primary-color, #2c5aa0);
            margin: 2rem 0;
            padding: 1rem;
            border: 3px dashed #000;
        }
        .voucher-amount {
            text-align: center;
            font-size: 48pt;
            font-weight: bold;
            color: #2e7d32;
            margin: 2rem 0;
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
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            font-weight: bold;
        }
        .status-active { background: #c8e6c9; color: #2e7d32; }
        .status-redeemed { background: #bbdefb; color: #1565c0; }
        .status-expired { background: #ffcdd2; color: #c62828; }
        .footer {
            margin-top: 2rem;
            text-align: center;
            font-size: 10pt;
            color: #666;
        }
        .redemption-info {
            background: #fff3cd;
            border: 2px solid #ffc107;
            padding: 1rem;
            margin: 1rem 0;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 1rem;">
        <button onclick="window.print()" style="padding: 0.5rem 1rem; font-size: 16px; cursor: pointer;">Print Voucher</button>
        <button onclick="window.close()" style="padding: 0.5rem 1rem; font-size: 16px; cursor: pointer; margin-left: 1rem;">Close</button>
    </div>

    <div class="receipt-header">
        <h1><?php echo htmlspecialchars($organization_name); ?></h1>
        <p>Voucher Receipt</p>
    </div>

    <div class="voucher-code">
        <?php echo htmlspecialchars($voucher['voucher_code']); ?>
    </div>

    <div class="voucher-amount">
        $<?php echo number_format($voucher['amount'], 2); ?>
    </div>

    <div class="receipt-info">
        <table>
            <tr>
                <td>Status:</td>
                <td>
                    <?php if ($voucher['status'] === 'active'): ?>
                        <span class="status-badge status-active">Active</span>
                    <?php elseif ($voucher['status'] === 'redeemed'): ?>
                        <span class="status-badge status-redeemed">Redeemed</span>
                    <?php else: ?>
                        <span class="status-badge status-expired">Expired</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td>Issued Date:</td>
                <td><?php echo date('F d, Y \a\t g:i A', strtotime($voucher['issued_date'])); ?></td>
            </tr>
            <?php if ($voucher['expiry_date']): ?>
            <tr>
                <td>Expiry Date:</td>
                <td><?php echo date('F d, Y', strtotime($voucher['expiry_date'])); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td>Customer Name:</td>
                <td><?php echo htmlspecialchars($voucher['customer_name']); ?></td>
            </tr>
            <tr>
                <td>Address:</td>
                <td><?php echo htmlspecialchars($voucher['address'] . ', ' . $voucher['city'] . ', ' . $voucher['state'] . ' ' . $voucher['zip']); ?></td>
            </tr>
            <tr>
                <td>Phone:</td>
                <td><?php echo htmlspecialchars($voucher['phone']); ?></td>
            </tr>
            <?php if ($voucher['status'] === 'redeemed' && $voucher['redeemed_date']): ?>
            <tr>
                <td>Redeemed Date:</td>
                <td><?php echo date('F d, Y \a\t g:i A', strtotime($voucher['redeemed_date'])); ?></td>
            </tr>
            <?php if ($voucher['redeemed_by']): ?>
            <tr>
                <td>Redeemed By:</td>
                <td><?php echo htmlspecialchars($voucher['redeemed_by']); ?></td>
            </tr>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($voucher['notes'])): ?>
            <tr>
                <td>Notes:</td>
                <td><?php echo nl2br(htmlspecialchars($voucher['notes'])); ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <?php if ($voucher['status'] === 'active'): ?>
    <div class="redemption-info">
        <p><strong>This voucher can be redeemed at:</strong></p>
        <p style="font-size: 18pt; font-weight: bold;"><?php echo htmlspecialchars($shop_name); ?></p>
    </div>
    <?php endif; ?>

    <div class="footer">
        <p>Generated on <?php echo date('F d, Y \a\t g:i A'); ?></p>
        <p><?php echo htmlspecialchars($organization_name); ?></p>
    </div>
</body>
</html>


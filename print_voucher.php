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
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        @media print {
            body { 
                margin: 0; 
                padding: 0.25in;
            }
            .no-print { display: none; }
            @page {
                margin: 0.25in;
            }
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
            padding-bottom: 0.5rem;
            margin-bottom: 0.75rem;
        }
        .receipt-header h1 {
            margin: 0;
            font-size: 20pt;
        }
        @media print {
            .receipt-header h1 {
                font-size: 18pt;
            }
        }
        .voucher-code {
            text-align: center;
            font-size: 28pt;
            font-weight: bold;
            color: var(--primary-color, #2c5aa0);
            margin: 1rem 0;
            padding: 0.75rem;
            border: 3px dashed #000;
        }
        @media print {
            .voucher-code {
                font-size: 24pt;
                margin: 0.75rem 0;
                padding: 0.5rem;
            }
        }
        .voucher-amount {
            text-align: center;
            font-size: 42pt;
            font-weight: bold;
            color: #2e7d32;
            margin: 1rem 0;
        }
        @media print {
            .voucher-amount {
                font-size: 36pt;
                margin: 0.75rem 0;
            }
        }
        .receipt-info {
            margin: 0.75rem 0;
        }
        @media print {
            .receipt-info {
                margin: 0.5rem 0;
            }
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
            margin-top: 1rem;
            text-align: center;
            font-size: 9pt;
            color: #666;
        }
        @media print {
            .footer {
                margin-top: 0.5rem;
                font-size: 8pt;
            }
        }
        .barcode-container {
            text-align: center;
            margin: 1rem 0;
            padding: 0.5rem;
        }
        @media print {
            .barcode-container {
                margin: 0.75rem 0;
                padding: 0.25rem;
            }
        }
        .barcode-container svg {
            max-width: 100%;
            height: auto;
        }
        @media print {
            .barcode-container svg {
                height: 60px;
            }
        }
        .barcode-label {
            margin-top: 0.5rem;
            font-size: 12pt;
            font-weight: bold;
            letter-spacing: 0.1em;
        }
        @media print {
            .barcode-label {
                font-size: 10pt;
                margin-top: 0.25rem;
            }
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

    <div class="barcode-container">
        <svg id="barcode"></svg>
        <div class="barcode-label"><?php echo htmlspecialchars($voucher['voucher_code']); ?></div>
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
                <td>Expiration Date:</td>
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

    <div class="footer">
        <p>Generated on <?php echo date('F d, Y \a\t g:i A'); ?></p>
        <p><?php echo htmlspecialchars($organization_name); ?></p>
    </div>

    <script>
        // Generate barcode with voucher code
        JsBarcode("#barcode", "<?php echo htmlspecialchars($voucher['voucher_code'], ENT_QUOTES); ?>", {
            format: "CODE128",
            width: 2,
            height: window.matchMedia('print').matches ? 60 : 80,
            displayValue: false,
            margin: 10
        });
    </script>
</body>
</html>


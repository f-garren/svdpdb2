<?php
require_once 'config.php';
require_once 'auth.php';

requirePermission('voucher_redeem');

$error = '';
$success = '';
$voucher_info = null;
$voucher_code = $_GET['code'] ?? '';
$db = getDB();

// Function to check voucher
function checkVoucher($db, $code) {
    if (empty($code)) {
        return ['error' => "Please enter a voucher code.", 'voucher' => null];
    }
    
    $stmt = $db->prepare("SELECT v.*, c.name as customer_name, c.phone, c.address 
                         FROM vouchers v 
                         INNER JOIN customers c ON v.customer_id = c.id 
                         WHERE v.voucher_code = ?");
    $stmt->execute([$code]);
    $voucher = $stmt->fetch();
    
    if (!$voucher) {
        return ['error' => "Voucher code not found.", 'voucher' => null];
    } elseif ($voucher['status'] !== 'active') {
        if ($voucher['status'] === 'redeemed') {
            return ['error' => "This voucher has already been redeemed on " . date('M d, Y', strtotime($voucher['redeemed_date'])) . ".", 'voucher' => $voucher];
        } else {
            return ['error' => "This voucher has expired or is no longer valid.", 'voucher' => $voucher];
        }
    }
    
    return ['error' => null, 'voucher' => $voucher];
}

// Check voucher if code is in URL
if (!empty($voucher_code) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $result = checkVoucher($db, $voucher_code);
    $voucher_info = $result['voucher'];
    if ($result['error']) {
        $error = $result['error'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $voucher_code = trim($_POST['voucher_code'] ?? '');
    
    if (!empty($voucher_code)) {
        $result = checkVoucher($db, $voucher_code);
        $voucher_info = $result['voucher'];
        if ($result['error']) {
            $error = $result['error'];
        }
    } else {
        $error = "Please enter a voucher code.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_voucher']) && $voucher_info && $voucher_info['status'] === 'active') {
    try {
        // Automatically use logged-in employee's name
        $redeemed_by = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown';
        
        // Mark voucher as redeemed
        $stmt = $db->prepare("UPDATE vouchers SET status = 'redeemed', redeemed_date = NOW(), redeemed_by = ? WHERE voucher_code = ?");
        $stmt->execute([$redeemed_by, $voucher_code]);
        
        // Log audit
        $voucher_id = $voucher_info['id'] ?? null;
        logEmployeeAction($db, getCurrentEmployeeId(), 'voucher_redeem', 'voucher', $voucher_id, "Redeemed voucher {$voucher_code} by {$redeemed_by}, amount: $" . number_format($voucher_info['amount'], 2));
        
        $success = "Voucher redeemed successfully! Amount: $" . number_format($voucher_info['amount'], 2);
        $voucher_info = null; // Clear for new search
        $voucher_code = '';
    } catch (Exception $e) {
        $error = "Error redeeming voucher: " . $e->getMessage();
    }
}

$shop_name = getSetting('shop_name', 'Partner Store');
$voucher_prefix = getSetting('voucher_prefix', 'VCH-');

// Get all active vouchers for the collapsible list
$db = getDB();
$stmt = $db->query("SELECT v.*, c.name as customer_name, c.phone 
                    FROM vouchers v 
                    INNER JOIN customers c ON v.customer_id = c.id 
                    WHERE v.status = 'active' 
                    ORDER BY v.issued_date DESC 
                    LIMIT 100");
$active_vouchers = $stmt->fetchAll();

$page_title = "Redeem Voucher";
include 'header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>Redeem Voucher</h1>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <div class="search-box">
        <form method="POST" action="">
            <div class="form-group">
                <label for="voucher_code">Voucher Code <span class="required">*</span></label>
                <input type="text" id="voucher_code" name="voucher_code" value="<?php echo htmlspecialchars($voucher_code); ?>" placeholder="Enter voucher code (e.g., <?php echo htmlspecialchars($voucher_prefix); ?>XXXXXXXX)" required autofocus style="margin-bottom: 1rem;">
                <button type="submit" class="btn btn-primary">Check Voucher</button>
            </div>
        </form>
    </div>

    <!-- Active Vouchers Collapsible List -->
    <div class="report-section" style="margin-top: 2rem;">
        <button type="button" class="btn btn-secondary" onclick="toggleVouchersList()" style="width: 100%; text-align: left; display: flex; justify-content: space-between; align-items: center;">
            <span>
                <ion-icon name="list"></ion-icon> Active Vouchers (<?php echo count($active_vouchers); ?>)
            </span>
            <ion-icon name="chevron-down" id="vouchers-list-toggle-icon"></ion-icon>
        </button>
        <div id="active-vouchers-list" style="display: none; margin-top: 1rem;">
            <?php if (count($active_vouchers) > 0): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Voucher Code</th>
                            <th>Customer</th>
                            <th>Amount</th>
                            <th>Issued Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($active_vouchers as $voucher): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($voucher['voucher_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($voucher['customer_name']); ?></td>
                                <td>$<?php echo number_format($voucher['amount'], 2); ?></td>
                                <td><?php echo date('M d, Y g:i A', strtotime($voucher['issued_date'])); ?></td>
                                <td>
                                    <a href="?code=<?php echo urlencode($voucher['voucher_code']); ?>" class="btn btn-small btn-primary">Check This Voucher</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="no-data">No active vouchers available.</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleVouchersList() {
            const list = document.getElementById('active-vouchers-list');
            const icon = document.getElementById('vouchers-list-toggle-icon');
            if (list.style.display === 'none') {
                list.style.display = 'block';
                icon.setAttribute('name', 'chevron-up');
            } else {
                list.style.display = 'none';
                icon.setAttribute('name', 'chevron-down');
            }
        }
    </script>

    <?php if ($voucher_info && $voucher_info['status'] === 'active'): ?>
        <div class="voucher-info-card">
            <h2>Voucher Information</h2>
            <table class="info-table">
                <tr>
                    <th>Voucher Code:</th>
                    <td><strong><?php echo htmlspecialchars($voucher_info['voucher_code']); ?></strong></td>
                </tr>
                <tr>
                    <th>Credit Amount:</th>
                    <td><strong style="font-size: 1.5rem; color: var(--success-color);">$<?php echo number_format($voucher_info['amount'], 2); ?></strong></td>
                </tr>
                <tr>
                    <th>Customer:</th>
                    <td><?php echo htmlspecialchars($voucher_info['customer_name']); ?></td>
                </tr>
                <tr>
                    <th>Phone:</th>
                    <td><?php echo htmlspecialchars($voucher_info['phone']); ?></td>
                </tr>
                <tr>
                    <th>Address:</th>
                    <td><?php echo htmlspecialchars($voucher_info['address']); ?></td>
                </tr>
                <tr>
                    <th>Issued Date:</th>
                    <td><?php echo date('F d, Y \a\t g:i A', strtotime($voucher_info['issued_date'])); ?></td>
                </tr>
                <?php if ($voucher_info['expiry_date']): ?>
                <tr>
                    <th>Expiration Date:</th>
                    <td><?php echo date('F d, Y', strtotime($voucher_info['expiry_date'])); ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($voucher_info['notes'])): ?>
                <tr>
                    <th>Notes:</th>
                    <td><?php echo nl2br(htmlspecialchars($voucher_info['notes'])); ?></td>
                </tr>
                <?php endif; ?>
            </table>
            
            <form method="POST" action="" style="margin-top: 1.5rem;">
                <input type="hidden" name="voucher_code" value="<?php echo htmlspecialchars($voucher_code); ?>">
                <div class="form-actions">
                    <button type="submit" name="redeem_voucher" class="btn btn-primary btn-large">Redeem Voucher</button>
                    <a href="voucher_redemption.php" class="btn btn-secondary btn-large">Cancel</a>
                </div>
            </form>
        </div>
    <?php elseif ($voucher_info && $voucher_info['status'] !== 'active'): ?>
        <div class="voucher-info-card">
            <h2>Voucher Information</h2>
            <div class="alert alert-warning">
                <p><strong>Status:</strong> <?php echo ucfirst($voucher_info['status']); ?></p>
                <?php if ($voucher_info['redeemed_date']): ?>
                    <p>Redeemed on: <?php echo date('F d, Y \a\t g:i A', strtotime($voucher_info['redeemed_date'])); ?></p>
                    <?php if ($voucher_info['redeemed_by']): ?>
                        <p>Redeemed by: <?php echo htmlspecialchars($voucher_info['redeemed_by']); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
                <p><strong>This voucher cannot be redeemed.</strong></p>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>


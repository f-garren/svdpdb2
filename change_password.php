<?php
require_once 'config.php';
require_once 'auth.php';

requireLogin();

$error = '';
$success = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $requires_reset = requiresPasswordReset();
    
    // Validate required fields (current password only required if not a forced reset)
    if ((!$requires_reset && empty($current_password)) || empty($new_password) || empty($confirm_password)) {
        $error = 'All fields are required';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New passwords do not match';
    } elseif (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters long';
    } else {
        $db = getDB();
        $employee = getCurrentEmployee();
        
        // Verify current password (only if not a forced password reset)
        if (!$requires_reset) {
            if (!verifyPassword($current_password, $employee['password_hash'])) {
                $error = 'Current password is incorrect';
            }
        }
        
        if (empty($error)) {
            // Update password
            $new_hash = hashPassword($new_password);
            $stmt = $db->prepare("UPDATE employees SET password_hash = ?, force_password_reset = 0 WHERE id = ?");
            $stmt->execute([$new_hash, getCurrentEmployeeId()]);
            
            // Update session
            $_SESSION['force_password_reset'] = 0;
            
            $success = 'Password changed successfully!';
            
            // Redirect after 2 seconds
            header('Refresh: 2; url=index.php');
        }
    }
}

$page_title = "Change Password";
include 'header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>Change Password</h1>
        <?php if (requiresPasswordReset()): ?>
            <p class="lead">You must change your password before continuing</p>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="POST" action="" class="signup-form" style="max-width: 500px;">
        <?php if (!requiresPasswordReset()): ?>
        <div class="form-group">
            <label for="current_password">Current Password <span class="required">*</span></label>
            <input type="password" id="current_password" name="current_password" required>
        </div>
        <?php endif; ?>
        
        <div class="form-group">
            <label for="new_password">New Password <span class="required">*</span></label>
            <input type="password" id="new_password" name="new_password" required minlength="8">
            <small class="help-text">Must be at least 8 characters long</small>
        </div>
        
        <div class="form-group">
            <label for="confirm_password">Confirm New Password <span class="required">*</span></label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-large">Change Password</button>
            <?php if (!requiresPasswordReset()): ?>
                <a href="index.php" class="btn btn-secondary btn-large">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php include 'footer.php'; ?>


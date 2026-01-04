<?php
require_once 'config.php';
require_once 'auth.php';

requireAdmin();

$db = getDB();
$error = '';
$success = '';

// Handle employee creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_employee'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $is_admin = isset($_POST['is_admin']) ? 1 : 0;
    $permissions = $_POST['permissions'] ?? [];
    
    if (empty($username) || empty($password) || empty($full_name)) {
        $error = 'Username, password, and full name are required';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long';
    } else {
        try {
            $db->beginTransaction();
            
            // Check if username exists
            $stmt = $db->prepare("SELECT id FROM employees WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                throw new Exception('Username already exists');
            }
            
            // Create employee
            $password_hash = hashPassword($password);
            $stmt = $db->prepare("INSERT INTO employees (username, password_hash, full_name, email, is_admin, force_password_reset) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt->execute([$username, $password_hash, $full_name, $email ?: null, $is_admin]);
            $employee_id = $db->lastInsertId();
            
            // Add permissions if not admin
            if (!$is_admin && !empty($permissions)) {
                $stmt = $db->prepare("INSERT INTO employee_permissions (employee_id, permission) VALUES (?, ?)");
                foreach ($permissions as $permission) {
                    $stmt->execute([$employee_id, $permission]);
                }
            }
            
            $db->commit();
            $success = "Employee created successfully!";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error creating employee: " . $e->getMessage();
        }
    }
}

// Handle employee deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_employee'])) {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    
    if ($employee_id == getCurrentEmployeeId()) {
        $error = 'You cannot delete your own account';
    } elseif ($employee_id > 0) {
        try {
            // Soft delete by setting is_active = 0
            $stmt = $db->prepare("UPDATE employees SET is_active = 0 WHERE id = ?");
            $stmt->execute([$employee_id]);
            $success = "Employee deleted successfully!";
        } catch (Exception $e) {
            $error = "Error deleting employee: " . $e->getMessage();
        }
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';
    
    if (empty($new_password) || strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters long';
    } elseif ($employee_id > 0) {
        try {
            $password_hash = hashPassword($new_password);
            $stmt = $db->prepare("UPDATE employees SET password_hash = ?, force_password_reset = 1 WHERE id = ?");
            $stmt->execute([$password_hash, $employee_id]);
            $success = "Password reset successfully! Employee will be required to change password on next login.";
        } catch (Exception $e) {
            $error = "Error resetting password: " . $e->getMessage();
        }
    }
}

// Get all employees
$employees = $db->query("SELECT e.*, 
    (SELECT GROUP_CONCAT(permission) FROM employee_permissions WHERE employee_id = e.id) as permissions
    FROM employees e 
    ORDER BY e.created_at DESC")->fetchAll();

// Get permissions for each employee
foreach ($employees as &$emp) {
    if ($emp['is_admin']) {
        $emp['permissions'] = 'All (Admin)';
    } elseif ($emp['permissions']) {
        $emp['permissions'] = str_replace(',', ', ', $emp['permissions']);
    } else {
        $emp['permissions'] = 'None';
    }
}

$page_title = "Employee Management";
include 'header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>Employee Management</h1>
        <p class="lead">Manage employee accounts and permissions</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <!-- Create Employee Form -->
    <div class="report-section" style="margin-bottom: 2rem;">
        <h2>Create New Employee</h2>
        <form method="POST" action="" class="signup-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="username">Username <span class="required">*</span></label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password <span class="required">*</span></label>
                    <input type="password" id="password" name="password" required minlength="8">
                    <small class="help-text">Minimum 8 characters</small>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="full_name">Full Name <span class="required">*</span></label>
                    <input type="text" id="full_name" name="full_name" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email">
                </div>
            </div>
            
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem;">
                    <input type="checkbox" id="is_admin" name="is_admin" value="1">
                    <span>Administrator (has all permissions)</span>
                </label>
            </div>
            
            <div id="permissions_section">
                <label>Permissions:</label>
                <div class="form-row" style="flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1 1 200px;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                            <input type="checkbox" name="permissions[]" value="customer_create">
                            <span>Customer Creation</span>
                        </label>
                    </div>
                    <div class="form-group" style="flex: 1 1 200px;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                            <input type="checkbox" name="permissions[]" value="food_visit">
                            <span>Food Visit Entry</span>
                        </label>
                    </div>
                    <div class="form-group" style="flex: 1 1 200px;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                            <input type="checkbox" name="permissions[]" value="money_visit">
                            <span>Money Visit Entry</span>
                        </label>
                    </div>
                    <div class="form-group" style="flex: 1 1 200px;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                            <input type="checkbox" name="permissions[]" value="voucher_create">
                            <span>Voucher Creation</span>
                        </label>
                    </div>
                    <div class="form-group" style="flex: 1 1 200px;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                            <input type="checkbox" name="permissions[]" value="settings_access">
                            <span>Settings Access</span>
                        </label>
                    </div>
                    <div class="form-group" style="flex: 1 1 200px;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                            <input type="checkbox" name="permissions[]" value="report_access">
                            <span>Report Access</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="create_employee" class="btn btn-primary">Create Employee</button>
            </div>
        </form>
    </div>

    <!-- Employees List -->
    <div class="report-section">
        <h2>Existing Employees</h2>
        <?php if (count($employees) > 0): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Permissions</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $emp): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($emp['username']); ?></td>
                            <td><?php echo htmlspecialchars($emp['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($emp['email'] ?: '-'); ?></td>
                            <td><?php echo $emp['is_admin'] ? '<strong>Admin</strong>' : 'Employee'; ?></td>
                            <td><?php echo htmlspecialchars($emp['permissions']); ?></td>
                            <td>
                                <?php if ($emp['is_active']): ?>
                                    <span style="color: green;">Active</span>
                                <?php else: ?>
                                    <span style="color: red;">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $emp['last_login'] ? date('M d, Y g:i A', strtotime($emp['last_login'])) : 'Never'; ?></td>
                            <td style="white-space: nowrap;">
                                <button type="button" class="btn btn-small" onclick="showResetPassword(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['username'], ENT_QUOTES); ?>')">Reset Password</button>
                                <?php if ($emp['id'] != getCurrentEmployeeId()): ?>
                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this employee?');">
                                        <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                        <button type="submit" name="delete_employee" class="btn btn-small" style="background-color: #d32f2f; color: white;">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="no-data">No employees found.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Password Reset Modal -->
<div id="resetPasswordModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; justify-content: center; align-items: center;">
    <div style="background: white; padding: 2rem; border-radius: 8px; max-width: 400px; width: 90%;">
        <h2>Reset Password for <span id="reset_username"></span></h2>
        <form method="POST" action="">
            <input type="hidden" id="reset_employee_id" name="employee_id">
            <div class="form-group">
                <label for="new_password">New Password <span class="required">*</span></label>
                <input type="password" id="new_password" name="new_password" required minlength="8">
                <small class="help-text">Minimum 8 characters</small>
            </div>
            <div class="form-actions">
                <button type="submit" name="reset_password" class="btn btn-primary">Reset Password</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('resetPasswordModal').style.display='none';">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function showResetPassword(employeeId, username) {
    document.getElementById('reset_employee_id').value = employeeId;
    document.getElementById('reset_username').textContent = username;
    document.getElementById('resetPasswordModal').style.display = 'flex';
}

// Hide permissions section if admin is checked
document.getElementById('is_admin').addEventListener('change', function() {
    document.getElementById('permissions_section').style.display = this.checked ? 'none' : 'block';
});
</script>

<?php include 'footer.php'; ?>


<?php
require_once 'config.php';
require_once 'auth.php';

requireAdmin();

$db = getDB();
$error = '';
$success = $_GET['success'] ?? '';

// Handle employee creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_employee'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
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
            $stmt = $db->prepare("INSERT INTO employees (username, password_hash, full_name, is_admin, force_password_reset) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$username, $password_hash, $full_name, $is_admin]);
            $employee_id = $db->lastInsertId();
            
            // Add permissions if not admin
            if (!$is_admin && !empty($permissions)) {
                $stmt = $db->prepare("INSERT INTO employee_permissions (employee_id, permission) VALUES (?, ?)");
                foreach ($permissions as $permission) {
                    $stmt->execute([$employee_id, $permission]);
                }
            }
            
            // Log audit
            logEmployeeAction($db, getCurrentEmployeeId(), 'employee_create', 'employee', $employee_id, "Created employee: {$username} ({$full_name}), role: " . ($is_admin ? 'Admin' : 'Employee'));
            
            $db->commit();
            
            // Redirect to prevent form resubmission
            header('Location: employees.php?success=' . urlencode('Employee created successfully!'));
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error creating employee: " . $e->getMessage();
        }
    }
}

// Handle employee deactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_employee'])) {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    
    if ($employee_id == getCurrentEmployeeId()) {
        $error = 'You cannot deactivate your own account';
    } elseif ($employee_id > 0) {
        try {
            // Get employee info for audit
            $stmt = $db->prepare("SELECT username, full_name FROM employees WHERE id = ?");
            $stmt->execute([$employee_id]);
            $emp_info = $stmt->fetch();
            
            // Soft delete by setting is_active = 0
            $stmt = $db->prepare("UPDATE employees SET is_active = 0 WHERE id = ?");
            $stmt->execute([$employee_id]);
            
            // Log audit
            logEmployeeAction($db, getCurrentEmployeeId(), 'employee_deactivate', 'employee', $employee_id, "Deactivated employee: {$emp_info['username']} ({$emp_info['full_name']})");
            
            $success = "Employee deactivated successfully!";
        } catch (Exception $e) {
            $error = "Error deactivating employee: " . $e->getMessage();
        }
    }
}

// Handle employee reactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reactivate_employee'])) {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    
    if ($employee_id > 0) {
        try {
            // Get employee info for audit
            $stmt = $db->prepare("SELECT username, full_name FROM employees WHERE id = ?");
            $stmt->execute([$employee_id]);
            $emp_info = $stmt->fetch();
            
            $stmt = $db->prepare("UPDATE employees SET is_active = 1 WHERE id = ?");
            $stmt->execute([$employee_id]);
            
            // Log audit
            logEmployeeAction($db, getCurrentEmployeeId(), 'employee_reactivate', 'employee', $employee_id, "Reactivated employee: {$emp_info['username']} ({$emp_info['full_name']})");
            
            $success = "Employee reactivated successfully!";
        } catch (Exception $e) {
            $error = "Error reactivating employee: " . $e->getMessage();
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
            // Get employee info for audit
            $stmt = $db->prepare("SELECT username, full_name FROM employees WHERE id = ?");
            $stmt->execute([$employee_id]);
            $emp_info = $stmt->fetch();
            
            $password_hash = hashPassword($new_password);
            $stmt = $db->prepare("UPDATE employees SET password_hash = ?, force_password_reset = 1 WHERE id = ?");
            $stmt->execute([$password_hash, $employee_id]);
            
            // Log audit
            logEmployeeAction($db, getCurrentEmployeeId(), 'employee_password_reset', 'employee', $employee_id, "Reset password for employee: {$emp_info['username']} ({$emp_info['full_name']})");
            
            $success = "Password reset successfully! Employee will be required to change password on next login.";
        } catch (Exception $e) {
            $error = "Error resetting password: " . $e->getMessage();
        }
    }
}

// Get active employees
$employees = $db->query("SELECT e.*, 
    (SELECT GROUP_CONCAT(permission) FROM employee_permissions WHERE employee_id = e.id) as permissions
    FROM employees e 
    WHERE e.is_active = 1
    ORDER BY e.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Get inactive employees
$inactive_employees = $db->query("SELECT e.*, 
    (SELECT GROUP_CONCAT(permission) FROM employee_permissions WHERE employee_id = e.id) as permissions
    FROM employees e 
    WHERE e.is_active = 0
    ORDER BY e.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Format permissions for each employee
foreach ($employees as &$emp) {
    if ($emp['is_admin']) {
        $emp['permissions'] = 'All Permissions (Admin)';
    } elseif ($emp['permissions']) {
        $emp['permissions'] = str_replace(',', ', ', $emp['permissions']);
    } else {
        $emp['permissions'] = 'No Permissions';
    }
}
unset($emp); // Break reference

// Format permissions for inactive employees
foreach ($inactive_employees as &$emp) {
    if ($emp['is_admin']) {
        $emp['permissions'] = 'All Permissions (Admin)';
    } elseif ($emp['permissions']) {
        $emp['permissions'] = str_replace(',', ', ', $emp['permissions']);
    } else {
        $emp['permissions'] = 'No Permissions';
    }
}
unset($emp); // Break reference

$page_title = "Employee Management";
include 'header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>Employee Management</h1>
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
            
            <div class="form-group">
                <label for="full_name">Full Name <span class="required">*</span></label>
                <input type="text" id="full_name" name="full_name" required>
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
                            <input type="checkbox" name="permissions[]" value="customer_edit">
                            <span>Customer Editing</span>
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
                            <input type="checkbox" name="permissions[]" value="voucher_redeem">
                            <span>Voucher Redemption</span>
                        </label>
                    </div>
                    <div class="form-group" style="flex: 1 1 200px;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                            <input type="checkbox" name="permissions[]" value="visit_invalidate">
                            <span>Visit Invalidation</span>
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
                    <div class="form-group" style="flex: 1 1 200px;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                            <input type="checkbox" name="permissions[]" value="customer_history_view">
                            <span>Audit Log Access</span>
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
            <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
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
                            <td><?php echo htmlspecialchars($emp['permissions'] ?: ($emp['is_admin'] ? 'All Permissions (Admin)' : 'No Permissions')); ?></td>
                            <td>
                                <span style="color: green;">Active</span>
                            </td>
                            <td><?php echo $emp['last_login'] ? date('M d, Y g:i A', strtotime($emp['last_login'])) : 'Never'; ?></td>
                            <td style="white-space: nowrap;">
                                <button type="button" class="btn btn-small" onclick="showResetPassword(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['username'], ENT_QUOTES); ?>')">Reset Password</button>
                                <button type="button" class="btn btn-small" onclick="showEmployeeAudit(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['username'], ENT_QUOTES); ?>')">View Audit</button>
                                <?php if ($emp['id'] != getCurrentEmployeeId()): ?>
                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to deactivate this employee? They will not be able to log in but their data will be preserved for auditing.');">
                                        <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                        <button type="submit" name="deactivate_employee" class="btn btn-small" style="background-color: #d32f2f; color: white;">Deactivate</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <p class="no-data">No active employees found.</p>
        <?php endif; ?>
    </div>

    <!-- Inactive Employees Section -->
    <?php if (count($inactive_employees) > 0): ?>
    <div class="report-section" style="margin-top: 2rem;">
        <button type="button" class="btn btn-secondary" onclick="toggleInactiveEmployees()" style="width: 100%; text-align: left; display: flex; justify-content: space-between; align-items: center;">
            <span>
                <ion-icon name="people-outline"></ion-icon> Inactive Employees (<?php echo count($inactive_employees); ?>)
            </span>
            <ion-icon name="chevron-down" id="inactive-employees-toggle-icon"></ion-icon>
        </button>
        <div id="inactive-employees-list" style="display: none; margin-top: 1rem;">
            <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Permissions</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inactive_employees as $emp): ?>
                        <tr style="opacity: 0.7;">
                            <td><?php echo htmlspecialchars($emp['username']); ?></td>
                            <td><?php echo htmlspecialchars($emp['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($emp['permissions'] ?: ($emp['is_admin'] ? 'All Permissions (Admin)' : 'No Permissions')); ?></td>
                            <td><?php echo $emp['last_login'] ? date('M d, Y g:i A', strtotime($emp['last_login'])) : 'Never'; ?></td>
                            <td style="white-space: nowrap;">
                                <button type="button" class="btn btn-small" onclick="showEmployeeAudit(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['username'], ENT_QUOTES); ?>')">View Audit</button>
                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to reactivate this employee?');">
                                    <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                    <button type="submit" name="reactivate_employee" class="btn btn-small" style="background-color: var(--success-color); color: white;">Reactivate</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleInactiveEmployees() {
    const list = document.getElementById('inactive-employees-list');
    const icon = document.getElementById('inactive-employees-toggle-icon');
    if (list.style.display === 'none') {
        list.style.display = 'block';
        icon.setAttribute('name', 'chevron-up');
    } else {
        list.style.display = 'none';
        icon.setAttribute('name', 'chevron-down');
    }
}

function showEmployeeAudit(employeeId, username) {
    window.location.href = 'employee_audit.php?employee_id=' + employeeId;
}
</script>

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


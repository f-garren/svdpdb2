<?php
/**
 * Authentication and Authorization Functions
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['employee_id']) && isset($_SESSION['username']);
}

// Get current employee ID
function getCurrentEmployeeId() {
    return $_SESSION['employee_id'] ?? null;
}

// Get current employee data
function getCurrentEmployee() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_active = 1");
    $stmt->execute([getCurrentEmployeeId()]);
    return $stmt->fetch();
}

// Check if current user is admin
function isAdmin() {
    if (!isLoggedIn()) {
        return false;
    }
    $employee = getCurrentEmployee();
    return $employee && $employee['is_admin'] == 1;
}

// Check if user has specific permission
function hasPermission($permission) {
    if (!isLoggedIn()) {
        return false;
    }
    
    // Admins have all permissions
    if (isAdmin()) {
        return true;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM employee_permissions WHERE employee_id = ? AND permission = ?");
    $stmt->execute([getCurrentEmployeeId(), $permission]);
    $result = $stmt->fetch();
    return $result['count'] > 0;
}

// Require login (redirect to login if not logged in)
function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: login.php');
        exit;
    }
}

// Require admin (redirect if not admin)
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: index.php');
        exit;
    }
}

// Require permission (redirect if doesn't have permission)
function requirePermission($permission) {
    requireLogin();
    if (!hasPermission($permission) && !isAdmin()) {
        header('Location: index.php');
        exit;
    }
}

// Hash password using Argon2ID (preferred) or bcrypt (fallback)
function hashPassword($password) {
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($password, PASSWORD_ARGON2ID);
    } else {
        return password_hash($password, PASSWORD_BCRYPT);
    }
}

// Verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Login user
function loginUser($username, $password) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM employees WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $employee = $stmt->fetch();
    
    if (!$employee) {
        return ['success' => false, 'message' => 'Invalid username or password'];
    }
    
    if (!verifyPassword($password, $employee['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid username or password'];
    }
    
    // Update last login
    $stmt = $db->prepare("UPDATE employees SET last_login = NOW() WHERE id = ?");
    $stmt->execute([$employee['id']]);
    
    // Set session
    $_SESSION['employee_id'] = $employee['id'];
    $_SESSION['username'] = $employee['username'];
    $_SESSION['full_name'] = $employee['full_name'];
    $_SESSION['is_admin'] = $employee['is_admin'];
    $_SESSION['force_password_reset'] = $employee['force_password_reset'];
    
    return ['success' => true, 'employee' => $employee];
}

// Logout user
function logoutUser() {
    $_SESSION = array();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();
}

// Check if password reset is required
function requiresPasswordReset() {
    if (!isLoggedIn()) {
        return false;
    }
    return isset($_SESSION['force_password_reset']) && $_SESSION['force_password_reset'] == 1;
}


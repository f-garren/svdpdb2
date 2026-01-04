<?php
/**
 * Reset Admin Password
 * This script resets the admin account password to 'admin' and forces a password reset
 * Usage: php reset_admin.php
 */

require_once 'config.php';
require_once 'auth.php';

$db = getDB();

// Check if admin exists
$stmt = $db->prepare("SELECT id FROM employees WHERE username = 'admin'");
$stmt->execute();
$admin = $stmt->fetch();

if (!$admin) {
    echo "Admin account not found. Creating...\n";
    // Create admin if it doesn't exist
    $password_hash = hashPassword('admin');
    $stmt = $db->prepare("INSERT INTO employees (username, password_hash, full_name, is_admin, force_password_reset, is_active) VALUES ('admin', ?, 'Administrator', 1, 1, 1)");
    $stmt->execute([$password_hash]);
    echo "Admin account created successfully!\n";
} else {
    echo "Admin account found. Resetting password...\n";
    // Reset password hash to 'admin'
    $password_hash = hashPassword('admin');
    $stmt = $db->prepare("UPDATE employees SET password_hash = ?, force_password_reset = 1, is_active = 1 WHERE username = 'admin'");
    $stmt->execute([$password_hash]);
    echo "Admin password reset successfully!\n";
}

echo "\nDefault admin credentials:\n";
echo "Username: admin\n";
echo "Password: admin\n";
echo "\nIMPORTANT: Change the password on first login!\n";
echo "\nYou can now log in with admin/admin\n";


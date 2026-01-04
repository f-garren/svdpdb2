<?php
/**
 * Migration Script: Add Authentication and New Features
 * Run this script once to upgrade existing databases
 * Usage: php migrate_to_auth.php
 */

require_once 'config.php';

$db = getDB();

echo "Starting migration...\n\n";

try {
    $db->beginTransaction();
    
    // Create employees table if it doesn't exist
    echo "Creating employees table...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS `employees` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `username` varchar(100) NOT NULL UNIQUE,
      `password_hash` varchar(255) NOT NULL,
      `full_name` varchar(255) NOT NULL,
      `email` varchar(255) DEFAULT NULL,
      `is_admin` tinyint(1) NOT NULL DEFAULT 0,
      `force_password_reset` tinyint(1) NOT NULL DEFAULT 0,
      `is_active` tinyint(1) NOT NULL DEFAULT 1,
      `last_login` datetime DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_username` (`username`),
      KEY `idx_is_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Create employee permissions table
    echo "Creating employee_permissions table...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS `employee_permissions` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `employee_id` int(11) NOT NULL,
      `permission` enum('customer_create','food_visit','money_visit','voucher_create','settings_access','report_access') NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_employee_permission` (`employee_id`, `permission`),
      KEY `employee_id` (`employee_id`),
      CONSTRAINT `emp_perm_employee_fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Create customer audit table
    echo "Creating customer_audit table...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS `customer_audit` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `customer_id` int(11) NOT NULL,
      `field_name` varchar(100) NOT NULL,
      `old_value` text,
      `new_value` text,
      `changed_by` int(11) DEFAULT NULL,
      `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `customer_id` (`customer_id`),
      KEY `changed_by` (`changed_by`),
      KEY `changed_at` (`changed_at`),
      CONSTRAINT `audit_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
      CONSTRAINT `audit_employee_fk` FOREIGN KEY (`changed_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Create employee audit table
    echo "Creating employee_audit table...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS `employee_audit` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `employee_id` int(11) NOT NULL,
      `action_type` varchar(50) NOT NULL,
      `target_type` varchar(50) DEFAULT NULL,
      `target_id` int(11) DEFAULT NULL,
      `details` text,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `employee_id` (`employee_id`),
      KEY `action_type` (`action_type`),
      KEY `target_type` (`target_type`),
      KEY `created_at` (`created_at`),
      CONSTRAINT `emp_audit_employee_fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add soft delete columns to visits table (if they don't exist)
    echo "Adding soft delete columns to visits table...\n";
    try {
        $db->exec("ALTER TABLE `visits` 
          ADD COLUMN `is_invalid` tinyint(1) NOT NULL DEFAULT 0 AFTER `notes`");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            throw $e;
        }
        echo "  Column is_invalid already exists, skipping...\n";
    }
    
    try {
        $db->exec("ALTER TABLE `visits` 
          ADD COLUMN `invalid_reason` text DEFAULT NULL AFTER `is_invalid`");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            throw $e;
        }
        echo "  Column invalid_reason already exists, skipping...\n";
    }
    
    try {
        $db->exec("ALTER TABLE `visits` 
          ADD COLUMN `invalidated_by` int(11) DEFAULT NULL AFTER `invalid_reason`");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            throw $e;
        }
        echo "  Column invalidated_by already exists, skipping...\n";
    }
    
    try {
        $db->exec("ALTER TABLE `visits` 
          ADD COLUMN `invalidated_at` datetime DEFAULT NULL AFTER `invalidated_by`");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            throw $e;
        }
        echo "  Column invalidated_at already exists, skipping...\n";
    }
    
    // Add indexes and foreign key (if they don't exist)
    try {
        $db->exec("ALTER TABLE `visits` ADD KEY `idx_is_invalid` (`is_invalid`)");
    } catch (PDOException $e) {
        echo "  Index idx_is_invalid may already exist, skipping...\n";
    }
    
    try {
        $db->exec("ALTER TABLE `visits` 
          ADD CONSTRAINT `visits_invalidated_by_fk` FOREIGN KEY (`invalidated_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key') === false && strpos($e->getMessage(), 'already exists') === false) {
            throw $e;
        }
        echo "  Foreign key may already exist, skipping...\n";
    }
    
    // Initialize admin account if it doesn't exist
    echo "Initializing admin account...\n";
    require_once __DIR__ . '/auth.php';
    $stmt = $db->prepare("SELECT id FROM employees WHERE username = 'admin'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $password_hash = hashPassword('admin');
        $stmt = $db->prepare("INSERT INTO employees (username, password_hash, full_name, is_admin, force_password_reset) VALUES ('admin', ?, 'Administrator', 1, 1)");
        $stmt->execute([$password_hash]);
        echo "  Admin account created!\n";
    } else {
        // Update password hash if it's still placeholder
        $stmt = $db->prepare("SELECT password_hash FROM employees WHERE username = 'admin'");
        $stmt->execute();
        $admin = $stmt->fetch();
        if ($admin && ($admin['password_hash'] === 'PLACEHOLDER' || strlen($admin['password_hash']) < 20)) {
            $password_hash = hashPassword('admin');
            $stmt = $db->prepare("UPDATE employees SET password_hash = ?, force_password_reset = 1 WHERE username = 'admin'");
            $stmt->execute([$password_hash]);
            echo "  Admin password hash updated!\n";
        } else {
            echo "  Admin account already exists with proper password hash.\n";
        }
    }
    
    $db->commit();
    echo "\nMigration completed successfully!\n";
    echo "\nDefault admin credentials:\n";
    echo "Username: admin\n";
    echo "Password: admin\n";
    echo "\nIMPORTANT: Change the password on first login!\n";
    
} catch (Exception $e) {
    $db->rollBack();
    echo "\nMigration failed: " . $e->getMessage() . "\n";
    exit(1);
}


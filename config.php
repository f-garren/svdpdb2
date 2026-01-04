<?php
/**
 * NexusDB Configuration File
 * Database connection settings
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'nexusdb');
define('DB_USER', 'nexusdb_user');
define('DB_PASS', 'nexusdb_password');

// Application settings
define('APP_NAME', 'NexusDB');
define('APP_VERSION', '0.2.1');

// Error reporting (set to 0 in production)
define('DEBUG_MODE', 1);

// Database connection class
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
}

// Helper function to get database connection
function getDB() {
    return Database::getInstance()->getConnection();
}

// Helper function to get settings
function getSetting($key, $default = null) {
    $db = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    return $result ? $result['setting_value'] : $default;
}

// Helper function to set settings
function setSetting($key, $value) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    return $stmt->execute([$key, $value, $value]);
}

// Helper function to get customer term (singular)
function getCustomerTerm($default = 'Customer') {
    return getSetting('customer_term', $default);
}

// Helper function to get customer term plural
function getCustomerTermPlural($default = 'Customers') {
    return getSetting('customer_term_plural', $default);
}

// Helper function to convert hex to RGB
function hex2rgb($hex) {
    $hex = str_replace('#', '', $hex);
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return ['r' => $r, 'g' => $g, 'b' => $b];
}

// Load timezone from settings if available, otherwise use default
// This is called after Database class and getSetting function are defined
try {
    $timezone = getSetting('timezone', 'America/Boise');
} catch (Exception $e) {
    $timezone = 'America/Boise';
}
date_default_timezone_set($timezone);

// IP/DNS Access Control
function checkAccessControl() {
    try {
        $allowed_ips = getSetting('allowed_ips', '');
        $allowed_dns = getSetting('allowed_dns', '');
        
        // If no restrictions set, allow all access
        if (empty($allowed_ips) && empty($allowed_dns)) {
            return true;
        }
        
        // Get client IP address
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        // Handle forwarded IPs (X-Forwarded-For header)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded_ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $client_ip = trim($forwarded_ips[0]);
        }
        
        // Check IP whitelist
        if (!empty($allowed_ips)) {
            $allowed_ip_list = array_map('trim', explode(',', $allowed_ips));
            if (in_array($client_ip, $allowed_ip_list)) {
                return true;
            }
            
            // Check CIDR notation (e.g., 192.168.1.0/24)
            foreach ($allowed_ip_list as $allowed_ip) {
                if (strpos($allowed_ip, '/') !== false) {
                    list($network, $prefix) = explode('/', $allowed_ip);
                    if (ipInRange($client_ip, $network, $prefix)) {
                        return true;
                    }
                }
            }
        }
        
        // Check DNS whitelist
        if (!empty($allowed_dns)) {
            $allowed_dns_list = array_map('trim', explode(',', $allowed_dns));
            
            // Get hostname from IP
            $hostname = @gethostbyaddr($client_ip);
            
            // Check if hostname matches any allowed DNS
            foreach ($allowed_dns_list as $allowed_dns_name) {
                $allowed_dns_name = strtolower(trim($allowed_dns_name));
                $hostname_lower = strtolower($hostname);
                
                // Exact match or domain match
                if ($hostname_lower === $allowed_dns_name || 
                    substr($hostname_lower, -(strlen($allowed_dns_name) + 1)) === '.' . $allowed_dns_name ||
                    substr($hostname_lower, -strlen($allowed_dns_name)) === $allowed_dns_name) {
                    return true;
                }
            }
        }
        
        // Access denied
        http_response_code(403);
        die('
<!DOCTYPE html>
<html>
<head>
    <title>Access Denied</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            height: 100vh; 
            margin: 0; 
            background: #f5f5f5; 
        }
        .error-container { 
            background: white; 
            padding: 2rem; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
            text-align: center; 
        }
        h1 { color: #d32f2f; margin: 0 0 1rem 0; }
        p { color: #666; margin: 0.5rem 0; }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>Access Denied</h1>
        <p>Your IP address (' . htmlspecialchars($client_ip) . ') is not authorized to access this system.</p>
        <p>Please contact the administrator if you believe this is an error.</p>
    </div>
</body>
</html>');
        
    } catch (Exception $e) {
        // On error, allow access (fail open) to prevent lockout
        // In production, you might want to log this error
        return true;
    }
}

// Helper function to check if IP is in CIDR range
function ipInRange($ip, $network, $prefix) {
    $ip_long = ip2long($ip);
    $network_long = ip2long($network);
    $mask = -1 << (32 - (int)$prefix);
    return ($ip_long & $mask) === ($network_long & $mask);
}

// Check access control (after database connection is available)
try {
    checkAccessControl();
} catch (Exception $e) {
    // If database isn't ready yet, skip access control check
    // This allows the system to work during initial setup
}

// Include helper functions
require_once __DIR__ . '/helpers.php';

// Include authentication (after database is available)
require_once __DIR__ . '/auth.php';

// Check if password reset is required (except on login/change_password pages)
$current_page = basename($_SERVER['PHP_SELF']);
if (isLoggedIn() && requiresPasswordReset() && !in_array($current_page, ['login.php', 'change_password.php', 'logout.php'])) {
    header('Location: change_password.php');
    exit;
}
?>


<?php

// Security: Prevent direct access to this helper file
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    header('Location: login.php');
    exit;
}

// Hope Baptist Church Treasurer System
// Simple LAMP Application
// Based on Treasurer's Guide Conceptual Edition Rev 1.0

define('APP_NAME', 'Hope Baptist Treasurer');
define('APP_VERSION', '1.0.0');
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'treasurer_db');
define('DB_USER', 'treasurer_user');
define('DB_PASS', 'treasurer_password');

require_once __DIR__ . '/includes/storage_paths.php';

// Database connection function following GAAP principles
function getDbConnection() {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($mysqli->connect_error) {
        error_log("Database connection failed: " . $mysqli->connect_error);
        die("Connection failed: " . $mysqli->connect_error);
    }
    
    // Set charset to prevent injection
    $mysqli->set_charset("utf8mb4");
    return $mysqli;
}

// Enable error reporting during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log all errors to the resolved writable logs directory
ini_set('log_errors', 1);
ini_set('error_log', getLogsDir() . '/app.log');
?>

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
/** Application version string (hybrid: also stored in app_version table + VERSION.md). */
define('APP_VERSION', '0.801');
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'temper_db');
define('DB_USER', 'temper_user');
define('DB_PASS', 'temper_password');

/**
 * Application environment: 'production' | 'development' (and aliases).
 * Override via environment variable APP_ENV, or define before including config.
 * Defaults to development on local/private hosts; production otherwise.
 */
if (!defined('APP_ENV')) {
    $envFromServer = getenv('APP_ENV');
    if (is_string($envFromServer) && $envFromServer !== '') {
        define('APP_ENV', strtolower(trim($envFromServer)));
    } else {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
        $host = preg_replace('/:\d+$/', '', $host) ?: 'localhost';
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost');
        define('APP_ENV', $isLocal ? 'development' : 'production');
    }
}

/**
 * Explicit hard-delete override: '1'/'true'/'yes' enables, '0'/'false'/'no' disables.
 * When unset, hard delete follows APP_ENV (allowed only in development).
 */
if (!defined('ALLOW_HARD_DELETE')) {
    $hardFlag = getenv('ALLOW_HARD_DELETE');
    if (is_string($hardFlag) && $hardFlag !== '') {
        $normalized = strtolower(trim($hardFlag));
        define('ALLOW_HARD_DELETE', in_array($normalized, ['1', 'true', 'yes', 'on'], true));
    } else {
        define('ALLOW_HARD_DELETE', null); // null = derive from APP_ENV
    }
}

require_once __DIR__ . '/includes/storage_paths.php';
require_once __DIR__ . '/includes/system_config.php';

/**
 * Whether the app is running in a development (non-production) environment.
 * Based on APP_ENV / host detection only (not the admin Developer Mode toggle).
 */
function isDevelopmentEnvironment(): bool {
    $env = strtolower((string)APP_ENV);
    return in_array($env, ['development', 'dev', 'local', 'testing', 'test'], true);
}

/**
 * Hard delete of users — gated by:
 * 1. ALLOW_HARD_DELETE env (true forces on, false forces off)
 * 2. Otherwise requires both Developer Mode (system config) AND a non-production APP_ENV
 *
 * Production hosts never allow hard delete unless ALLOW_HARD_DELETE=1 is set explicitly.
 */
function allowHardDeleteUsers(): bool {
    if (ALLOW_HARD_DELETE === true) {
        return true;
    }
    if (ALLOW_HARD_DELETE === false) {
        return false;
    }
    // Admin toggle is the primary runtime switch; still blocked outside development APP_ENV
    return isDeveloperModeEnabled() && isDevelopmentEnvironment();
}

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

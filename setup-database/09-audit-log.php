<?php
// Safety: This file should only be executed from the master setup_db.php
if (!defined('RUNNING_FROM_MASTER_SETUP') && !defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    die("❌ ERROR: This script should only be run from setup_db.php\n");
}

function setupSchemaAuditLog(): array
{
    return [
        'tables' => [
            'audit_log' => "CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    username VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_log_created_at (created_at),
    INDEX idx_audit_log_action (action)
)",
        ],
    ];
}

if (defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    return;
}

require_once 'config.php';

$db = getDbConnection();

$createSql = setupSchemaAuditLog()['tables']['audit_log'];

if ($db->query($createSql) === TRUE) {
    echo "Table 'audit_log' created successfully\n";
} else {
    echo "Error creating table 'audit_log': " . $db->error . "\n";
    exit(1);
}

$db->close();
?>

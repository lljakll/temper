<?php
// Safety: This file should only be executed from the master setup_db.php
if (!defined('RUNNING_FROM_MASTER_SETUP') && !defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    die("❌ ERROR: This script should only be run from setup_db.php\n");
}

function setupSchemaWorkflows(): array
{
    return [
        'tables' => [
            'workflow_instances' => "CREATE TABLE IF NOT EXISTS workflow_instances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workflow_type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    status VARCHAR(80) NOT NULL,
    current_step VARCHAR(80) NOT NULL,
    created_by_user_id INT NOT NULL,
    payload JSON NOT NULL,
    transaction_detail_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)",
            'workflow_steps' => "CREATE TABLE IF NOT EXISTS workflow_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workflow_instance_id INT NOT NULL,
    step_key VARCHAR(80) NOT NULL,
    step_order INT NOT NULL DEFAULT 0,
    status ENUM('pending','completed','rejected') NOT NULL DEFAULT 'pending',
    required_role VARCHAR(50) NULL,
    completed_by_user_id INT NULL,
    completed_at DATETIME NULL,
    signature_username VARCHAR(50) NULL,
    notes TEXT NULL,
    payload JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_instance_id) REFERENCES workflow_instances(id) ON DELETE CASCADE
)",
            'workflow_events' => "CREATE TABLE IF NOT EXISTS workflow_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workflow_instance_id INT NOT NULL,
    workflow_step_id INT NULL,
    event_type VARCHAR(80) NOT NULL,
    user_id INT NULL,
    username VARCHAR(50) NOT NULL,
    summary VARCHAR(255) NOT NULL,
    details JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_instance_id) REFERENCES workflow_instances(id) ON DELETE CASCADE
)",
        ],
    ];
}

if (defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    return;
}

require_once 'config.php';

$db = getDbConnection();
$schema = setupSchemaWorkflows()['tables'];

foreach ($schema as $tableName => $createSql) {
    if ($db->query($createSql) === TRUE) {
        echo "Table '{$tableName}' created successfully\n";
    } else {
        echo "Error creating table '{$tableName}': " . $db->error . "\n";
        exit(1);
    }
}

$db->query("CREATE INDEX IF NOT EXISTS idx_workflow_type ON workflow_instances(workflow_type)");
$db->query("CREATE INDEX IF NOT EXISTS idx_workflow_status ON workflow_instances(status)");
$db->query("CREATE INDEX IF NOT EXISTS idx_workflow_created_by ON workflow_instances(created_by_user_id)");
$db->query("CREATE INDEX IF NOT EXISTS idx_workflow_steps_instance ON workflow_steps(workflow_instance_id)");
$db->query("CREATE INDEX IF NOT EXISTS idx_workflow_steps_key ON workflow_steps(step_key)");
$db->query("CREATE INDEX IF NOT EXISTS idx_workflow_events_instance ON workflow_events(workflow_instance_id)");
$db->query("CREATE INDEX IF NOT EXISTS idx_workflow_events_type ON workflow_events(event_type)");

// Sync full predefined roles (includes Teller / Treasurer / etc. with complete permission sets)
require_once __DIR__ . '/../includes/permissions.php';
$sync = ensureDefaultRoles($db);
echo "Workflow tables ready; roles synced (inserted={$sync['inserted']}, updated={$sync['updated']})\n";
$db->close();
?>
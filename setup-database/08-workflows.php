<?php
// Safety: This file should only be executed from the master setup_db.php
if (!defined('RUNNING_FROM_MASTER_SETUP') && !defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    die("❌ ERROR: This script should only be run from setup_db.php\n");
}

/**
 * Workflow System schema (clean-sheet design).
 *
 * - workflow_definitions: index of immutable YAML files (checksum + path)
 * - workflow_instances: disposable runtime state (not the audit record)
 * - workflow_steps / workflow_events: operational trail for in-flight work
 *
 * Final auditable results belong in ledger tables + storage/attachments/{ref}/.
 */
function setupSchemaWorkflows(): array
{
    return [
        'tables' => [
            'workflow_definitions' => "CREATE TABLE IF NOT EXISTS workflow_definitions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workflow_id VARCHAR(64) NOT NULL,
    version INT NOT NULL DEFAULT 1,
    title VARCHAR(200) NOT NULL,
    file_path VARCHAR(512) NOT NULL COMMENT 'Relative to storage root, e.g. workflow-definitions/id.v1.yaml',
    checksum CHAR(64) NOT NULL COMMENT 'SHA-256 of file bytes as imported',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    meta JSON NULL COMMENT 'Cached index meta only — not a copy of the YAML',
    imported_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workflow_def_id_version (workflow_id, version),
    KEY idx_workflow_def_active (workflow_id, is_active),
    KEY idx_workflow_def_checksum (checksum)
)",
            'workflow_instances' => "CREATE TABLE IF NOT EXISTS workflow_instances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workflow_id VARCHAR(64) NOT NULL,
    definition_version INT NOT NULL DEFAULT 1,
    definition_checksum CHAR(64) NOT NULL COMMENT 'Pin instance to exact definition bytes',
    title VARCHAR(200) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'running',
    current_step VARCHAR(80) NOT NULL,
    created_by_user_id INT NOT NULL,
    payload JSON NOT NULL,
    transaction_detail_id INT NULL COMMENT 'Optional link to ledger entry when finalized',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_wf_inst_workflow (workflow_id),
    KEY idx_wf_inst_status (status),
    KEY idx_wf_inst_checksum (definition_checksum),
    KEY idx_wf_inst_created_by (created_by_user_id),
    KEY idx_wf_inst_tx (transaction_detail_id)
)",
            'workflow_steps' => "CREATE TABLE IF NOT EXISTS workflow_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workflow_instance_id INT NOT NULL,
    step_key VARCHAR(80) NOT NULL,
    step_order INT NOT NULL DEFAULT 0,
    status ENUM('pending','active','completed','skipped','rejected') NOT NULL DEFAULT 'pending',
    required_role VARCHAR(50) NULL,
    completed_by_user_id INT NULL,
    completed_at DATETIME NULL,
    signature_username VARCHAR(50) NULL,
    notes TEXT NULL,
    payload JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_wf_steps_instance (workflow_instance_id),
    KEY idx_wf_steps_key (step_key),
    CONSTRAINT fk_wf_steps_instance FOREIGN KEY (workflow_instance_id)
        REFERENCES workflow_instances(id) ON DELETE CASCADE
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
    KEY idx_wf_events_instance (workflow_instance_id),
    KEY idx_wf_events_type (event_type),
    CONSTRAINT fk_wf_events_instance FOREIGN KEY (workflow_instance_id)
        REFERENCES workflow_instances(id) ON DELETE CASCADE
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

// Ensure storage directory for immutable YAML definitions
require_once __DIR__ . '/../includes/storage_paths.php';
$dir = getWorkflowDefinitionsDir();
if (!empty($dir['error'])) {
    echo "Warning: workflow-definitions storage: {$dir['error']}\n";
} else {
    echo "Workflow definitions directory: {$dir['path']}\n";
}

// Sync roles (includes Workflow Manager)
require_once __DIR__ . '/../includes/permissions.php';
$sync = ensureDefaultRoles($db);
echo "Workflow tables ready; roles synced (inserted={$sync['inserted']}, updated={$sync['updated']})\n";
$db->close();

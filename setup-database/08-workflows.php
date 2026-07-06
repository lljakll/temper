<?php
if (!defined('RUNNING_FROM_MASTER_SETUP')) {
    die("❌ ERROR: This script should only be run from setup_db.php\n");
}

require_once 'config.php';
require_once __DIR__ . '/../includes/workflow_engine.php';

$db = getDbConnection();
workflowEnsureTables($db);

$roles = [
    ['Teller', 'Performs initial contribution count and data entry', '["workflow.view","workflow.contribution.create"]'],
    ['Second Teller', 'Verifies dual count and signs off', '["workflow.view","workflow.contribution.second_sign"]'],
    ['Treasurer', 'Official validation and deposit approval', '["workflow.view","workflow.contribution.official"]'],
    ['Financial Secretary', 'Official validation and deposit approval', '["workflow.view","workflow.contribution.official"]'],
];

$ins = $db->prepare('INSERT IGNORE INTO roles (name, description, permissions) VALUES (?, ?, ?)');
foreach ($roles as $role) {
    $ins->bind_param('sss', $role[0], $role[1], $role[2]);
    $ins->execute();
}
$ins->close();

echo "Workflow tables and roles ensured successfully!\n";
$db->close();
?>
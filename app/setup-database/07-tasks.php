<?php
// Safety: This file should only be executed from the master setup_db.php
if (!defined('RUNNING_FROM_MASTER_SETUP')) {
    die("❌ ERROR: This script should only be run from setup_db.php\n");
}

require_once 'config.php';

$db = getDbConnection();

$tasks_table = "CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    due_date DATE NULL,
    status ENUM('upcoming', 'due_soon', 'overdue', 'in_progress', 'done') NOT NULL DEFAULT 'upcoming',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($db->query($tasks_table) === TRUE) {
    echo "Table 'tasks' created successfully\n";
} else {
    echo "Error creating table 'tasks': " . $db->error . "\n";
    exit(1);
}

$db->query("CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status)");
$db->query("CREATE INDEX IF NOT EXISTS idx_tasks_due_date ON tasks(due_date)");

$db->close();
?>
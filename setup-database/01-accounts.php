<?php
// Safety: This file should only be executed from the master setup_db.php
if (!defined('RUNNING_FROM_MASTER_SETUP') && !defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    die("❌ ERROR: This script should only be run from setup_db.php\n");
}

function setupSchemaAccounts(): array
{
    return [
        'tables' => [
            'accounts' => "CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    normal_balance ENUM('debit', 'credit') NOT NULL,
    coa_number VARCHAR(50) NULL,
    natural_category_id INT NULL,
    functional_category_id INT NULL,
    archived BOOLEAN DEFAULT FALSE,
    archived_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    mutable_fund BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (natural_category_id) REFERENCES natural_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (functional_category_id) REFERENCES functional_categories(id) ON DELETE SET NULL
)",
        ],
    ];
}

if (defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    return;
}

// Require config file to get database connection details
require_once 'config.php';

// Get database connection
$db = getDbConnection();

$accounts_table = setupSchemaAccounts()['tables']['accounts'];

if ($db->query($accounts_table) === TRUE) {
    echo "Table 'accounts' created successfully\n";
} else {
    echo "Error creating table 'accounts': " . $db->error . "\n";
    exit(1);
}

// Create indexes
$db->query("CREATE INDEX idx_accounts_normal_balance ON accounts(normal_balance)");
$db->query("CREATE INDEX idx_accounts_archived ON accounts(archived)");
$db->query("CREATE INDEX idx_accounts_natural_category_id ON accounts(natural_category_id)");
$db->query("CREATE INDEX idx_accounts_functional_category_id ON accounts(functional_category_id)");

// Beta baseline: no demo chart of accounts — empty structure only.
// Operators create real accounts via the Accounts setup UI.
echo "Accounts table ready (no demo accounts seeded)\n";
echo "Accounts setup completed successfully!\n";

// Close connection
$db->close();
?>
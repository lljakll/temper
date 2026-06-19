<?php
// Safety: This file should only be executed from the master setup_db.php
if (!defined('RUNNING_FROM_MASTER_SETUP')) {
    die("❌ ERROR: This script should only be run from setup_db.php\n");
}

// Require config file to get database connection details
require_once 'config.php';

// Get database connection
$db = getDbConnection();

// Create accounts table
$accounts_table = "CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    normal_balance ENUM('debit', 'credit') NOT NULL,
    archived BOOLEAN DEFAULT FALSE,
    archived_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    mutable_fund BOOLEAN DEFAULT TRUE
)";

if ($db->query($accounts_table) === TRUE) {
    echo "Table 'accounts' created successfully\n";
} else {
    echo "Error creating table 'accounts': " . $db->error . "\n";
    exit(1);
}

// Create indexes
$db->query("CREATE INDEX idx_accounts_normal_balance ON accounts(normal_balance)");
$db->query("CREATE INDEX idx_accounts_archived ON accounts(archived)");

// Insert seed data: Accounts
$accounts = "INSERT INTO accounts (name, description, normal_balance, mutable_fund) VALUES 
    ('Cash', 'Cash on hand', 'debit', TRUE),
    ('Bank Account', 'Primary bank account', 'debit', TRUE),
    ('Accounts Receivable', 'Amounts owed to the church', 'debit', TRUE),
    ('Prepaid Expenses', 'Prepaid expenses such as insurance', 'debit', TRUE),
    ('Fixed Assets', 'Property, equipment, and other fixed assets', 'debit', TRUE),
    ('Accounts Payable', 'Amounts owed to others', 'credit', TRUE),
    ('Accrued Expenses', 'Expenses that have been incurred but not yet paid', 'credit', TRUE),
    ('Unearned Revenue', 'Revenue received in advance', 'credit', TRUE),
    ('Retained Earnings', 'Cumulative earnings of the church', 'credit', FALSE),
    ('Contributions', 'Donations received', 'credit', TRUE)";

if ($db->query($accounts) === TRUE) {
    echo "Seed data for 'accounts' inserted successfully\n";
} else {
    echo "Error inserting accounts: " . $db->error . "\n";
    exit(1);
}

echo "Accounts setup completed successfully!\n";

// Close connection
$db->close();
?>
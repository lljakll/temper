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

// Insert seed data: Accounts with natural/functional classification from category lookups
// natural: 1 Contributions, 2 Program, 3 Administrative, 4 Capital, 8 Operating
// functional: 1 Worship, 4 Finance, 5 Facilities, 6 Stewardship, 7 Leadership
$accounts = "INSERT INTO accounts (name, description, normal_balance, natural_category_id, functional_category_id, mutable_fund) VALUES 
    ('Cash', 'Cash on hand', 'debit', 8, 4, TRUE),
    ('Bank Account', 'Primary bank account', 'debit', 8, 4, TRUE),
    ('Accounts Receivable', 'Amounts owed to the church', 'debit', 1, 6, TRUE),
    ('Prepaid Expenses', 'Prepaid expenses such as insurance', 'debit', 3, 5, TRUE),
    ('Fixed Assets', 'Property, equipment, and other fixed assets', 'debit', 4, 5, TRUE),
    ('Accounts Payable', 'Amounts owed to others', 'credit', 3, 4, TRUE),
    ('Accrued Expenses', 'Expenses that have been incurred but not yet paid', 'credit', 3, 4, TRUE),
    ('Unearned Revenue', 'Revenue received in advance', 'credit', 1, 6, TRUE),
    ('Retained Earnings', 'Cumulative earnings of the church', 'credit', 3, 4, FALSE),
    ('Contributions', 'Donations received', 'credit', 1, 6, TRUE)";

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
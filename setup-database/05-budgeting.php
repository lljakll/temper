<?php
// Safety: This file should only be executed from the master setup_db.php
if (!defined('RUNNING_FROM_MASTER_SETUP') && !defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    die("❌ ERROR: This script should only be run from setup_db.php\n");
}

function setupSchemaBudgeting(): array
{
    return [
        'tables' => [
            'budgets' => "CREATE TABLE IF NOT EXISTS budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fiscal_year INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    approved_date DATE NULL,
    reference_number VARCHAR(50) NULL,
    status ENUM('draft', 'approved', 'active', 'closed') DEFAULT 'draft',
    total_budgeted DECIMAL(15,2) DEFAULT 0.00,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)",
            // Categories come from the linked account (accounts.natural/functional_category_id).
            'budget_lines' => "CREATE TABLE IF NOT EXISTS budget_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    budget_id INT NOT NULL,
    account_id INT NOT NULL,
    budgeted_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT
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

$schema = setupSchemaBudgeting()['tables'];

if ($db->query($schema['budgets']) === TRUE) {
    echo "Table 'budgets' created successfully\n";
} else {
    echo "Error creating table 'budgets': " . $db->error . "\n";
    exit(1);
}

if ($db->query($schema['budget_lines']) === TRUE) {
    echo "Table 'budget_lines' created successfully\n";
} else {
    echo "Error creating table 'budget_lines': " . $db->error . "\n";
    exit(1);
}

// Link transactions to budgets now that budgets table exists
$txHasBudget = $db->query("SHOW COLUMNS FROM transaction_details LIKE 'budget_id'");
if ($txHasBudget && $txHasBudget->num_rows > 0) {
    @$db->query(
        'ALTER TABLE transaction_details
         ADD CONSTRAINT fk_transaction_details_budget
         FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE SET NULL'
    );
}
if ($txHasBudget) {
    $txHasBudget->close();
}

// Create indexes
$db->query("CREATE INDEX idx_budgets_fiscal_year ON budgets(fiscal_year)");
$db->query("CREATE INDEX idx_budgets_status ON budgets(status)");
$db->query("CREATE INDEX idx_budget_lines_budget_id ON budget_lines(budget_id)");
$db->query("CREATE INDEX idx_budget_lines_account_id ON budget_lines(account_id)");
$db->query("CREATE INDEX idx_budget_lines_budgeted_amount ON budget_lines(budgeted_amount)");

// Beta baseline: no demo budgets or budget lines — empty structure only.
echo "Budget tables ready (no demo budgets seeded)\n";
echo "Budgeting setup completed successfully!\n";

// Close connection
$db->close();
?>
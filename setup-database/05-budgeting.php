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
            'budget_lines' => "CREATE TABLE IF NOT EXISTS budget_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    budget_id INT NOT NULL,
    natural_category_id INT NULL,
    functional_category_id INT NULL,
    account_id INT NULL,
    budgeted_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
    FOREIGN KEY (natural_category_id) REFERENCES natural_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (functional_category_id) REFERENCES functional_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL
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

// Create indexes
$db->query("CREATE INDEX idx_budgets_fiscal_year ON budgets(fiscal_year)");
$db->query("CREATE INDEX idx_budgets_status ON budgets(status)");
$db->query("CREATE INDEX idx_budget_lines_budget_id ON budget_lines(budget_id)");
$db->query("CREATE INDEX idx_budget_lines_natural_category_id ON budget_lines(natural_category_id)");
$db->query("CREATE INDEX idx_budget_lines_functional_category_id ON budget_lines(functional_category_id)");
$db->query("CREATE INDEX idx_budget_lines_account_id ON budget_lines(account_id)");
$db->query("CREATE INDEX idx_budget_lines_budgeted_amount ON budget_lines(budgeted_amount)");

// Insert seed data: Budgets
$budgets = "INSERT INTO budgets (fiscal_year, name, start_date, end_date, approved_date, reference_number, status, total_budgeted, description) VALUES 
    (2024, '2024 Church Budget', '2024-01-01', '2024-12-31', '2023-12-15', '2023-12-15-001', 'active', 500000.00, 'Annual church budget for 2024')";

if ($db->query($budgets) === TRUE) {
    echo "Seed data for 'budgets' inserted successfully\n";
} else {
    echo "Error inserting budgets: " . $db->error . "\n";
    exit(1);
}

// Insert seed data: Budget lines
$budget_lines = "INSERT INTO budget_lines (budget_id, natural_category_id, functional_category_id, account_id, budgeted_amount, notes) VALUES 
    (1, 1, 1, 1, 200000.00, 'Contributions for worship and programs'),
    (1, 2, 2, 2, 150000.00, 'Program expenses'),
    (1, 3, 3, 3, 50000.00, 'Administrative expenses'),
    (1, 4, 4, 4, 100000.00, 'Capital expenditures')";

if ($db->query($budget_lines) === TRUE) {
    echo "Seed data for 'budget_lines' inserted successfully\n";
} else {
    echo "Error inserting budget_lines: " . $db->error . "\n";
    exit(1);
}

echo "Budgeting setup completed successfully!\n";

// Close connection
$db->close();
?>
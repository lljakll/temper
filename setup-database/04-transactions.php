<?php
// Safety: This file should only be executed from the master setup_db.php
if (!defined('RUNNING_FROM_MASTER_SETUP') && !defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    die("❌ ERROR: This script should only be run from setup_db.php\n");
}

function setupSchemaTransactions(): array
{
    return [
        'tables' => [
            // budget_id column is defined here; FK to budgets is added in 05-budgeting after budgets exist.
            'transaction_details' => "CREATE TABLE IF NOT EXISTS transaction_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_date DATE NOT NULL,
    cleared_date DATE NULL,
    check_number VARCHAR(20) NULL,
    pay_to VARCHAR(255) NULL,
    description TEXT,
    reference_number VARCHAR(50) NULL,
    budget_id INT NULL,
    status ENUM('pending', 'cleared', 'reconciled') DEFAULT 'pending',
    date_reconciled DATE NULL,
    created_by_user_id INT NULL,
    validated_by_user_id INT NULL,
    validated_at DATETIME NULL,
    transaction_data JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)",
            'transaction_documents' => "CREATE TABLE IF NOT EXISTS transaction_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_detail_id INT NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT NOT NULL DEFAULT 0,
    uploaded_by_user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_detail_id) REFERENCES transaction_details(id) ON DELETE CASCADE
)",
            'transaction_events' => "CREATE TABLE IF NOT EXISTS transaction_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_detail_id INT NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    user_id INT NULL,
    username VARCHAR(50) NOT NULL,
    summary VARCHAR(255) NOT NULL,
    details JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_detail_id) REFERENCES transaction_details(id) ON DELETE CASCADE
)",
            'transaction_lines' => "CREATE TABLE IF NOT EXISTS transaction_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_detail_id INT NOT NULL,
    account_id INT NOT NULL,
    fund_id INT NULL,
    amount DECIMAL(15,2) NOT NULL CHECK (amount > 0),
    type ENUM('debit', 'credit') NOT NULL,
    natural_category_id INT NULL,
    functional_category_id INT NULL,
    budget_line_id INT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_detail_id) REFERENCES transaction_details(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (fund_id) REFERENCES funds(id) ON DELETE SET NULL,
    FOREIGN KEY (natural_category_id) REFERENCES natural_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (functional_category_id) REFERENCES functional_categories(id) ON DELETE SET NULL
)",
        ],
    ];
}

if (defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    return;
}

// Require config file
require_once 'config.php';

// Get database connection
$db = getDbConnection();

$schema = setupSchemaTransactions()['tables'];

foreach (['transaction_details', 'transaction_documents', 'transaction_events', 'transaction_lines'] as $tableName) {
    if ($db->query($schema[$tableName]) === TRUE) {
        echo "Table '{$tableName}' created successfully\n";
    } else {
        echo "Error creating table '{$tableName}': " . $db->error . "\n";
        exit(1);
    }
}

// Create indexes
$db->query("CREATE INDEX IF NOT EXISTS idx_transaction_details_date ON transaction_details(transaction_date)");
$db->query("CREATE INDEX IF NOT EXISTS idx_transaction_details_status ON transaction_details(status)");
// Reference # (YY####) — non-unique so confirmed reuse is allowed
$db->query("CREATE INDEX IF NOT EXISTS idx_transaction_details_reference ON transaction_details(reference_number)");
$db->query("CREATE INDEX IF NOT EXISTS idx_transaction_details_budget_id ON transaction_details(budget_id)");
$db->query("CREATE INDEX IF NOT EXISTS idx_transaction_documents_tx ON transaction_documents(transaction_detail_id)");
$db->query("CREATE INDEX IF NOT EXISTS idx_transaction_events_tx ON transaction_events(transaction_detail_id)");
$db->query("CREATE INDEX IF NOT EXISTS idx_transaction_events_type ON transaction_events(event_type)");
$db->query("CREATE INDEX IF NOT EXISTS idx_transaction_lines_account_id ON transaction_lines(account_id)");
$db->query("CREATE INDEX IF NOT EXISTS idx_transaction_lines_fund_id ON transaction_lines(fund_id)");
$db->query("CREATE INDEX IF NOT EXISTS idx_transaction_lines_amount ON transaction_lines(amount)");
$db->query("CREATE INDEX IF NOT EXISTS idx_transaction_lines_type ON transaction_lines(type)");

// Beta baseline: no demo ledger/transaction data — empty structure only.
echo "Transaction tables ready (no demo transactions seeded)\n";
echo "Transactions setup completed successfully!\n";

// Close connection
$db->close();
?>
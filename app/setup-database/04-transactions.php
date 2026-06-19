<?php
// Safety: This file should only be executed from the master setup_db.php
if (!defined('RUNNING_FROM_MASTER_SETUP')) {
    die("❌ ERROR: This script should only be run from setup_db.php\n");
}

// Require config file
require_once 'config.php';

// Get database connection
$db = getDbConnection();

// Create transaction_details table
$transaction_details_table = "CREATE TABLE IF NOT EXISTS transaction_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
transaction_date DATE NOT NULL,
cleared_date DATE NULL,
check_number VARCHAR(20) NULL,
pay_to VARCHAR(255) NULL,
memo TEXT,
reference_number VARCHAR(50),
status ENUM('pending', 'cleared', 'reconciled') DEFAULT 'pending',
date_reconciled DATE NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($db->query($transaction_details_table) === TRUE) {
    echo "Table 'transaction_details' created successfully\n";
} else {
    echo "Error creating table 'transaction_details': " . $db->error . "\n";
    exit(1);
}

// Create transaction_lines table (without FK to budget_lines for now - we'll add it later)
$transaction_lines_table = "CREATE TABLE IF NOT EXISTS transaction_lines (
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
)";

if ($db->query($transaction_lines_table) === TRUE) {
    echo "Table 'transaction_lines' created successfully\n";
} else {
    echo "Error creating table 'transaction_lines': " . $db->error . "\n";
    exit(1);
}

// Create indexes
$db->query("CREATE INDEX idx_transaction_details_date ON transaction_details(transaction_date)");
$db->query("CREATE INDEX idx_transaction_details_status ON transaction_details(status)");
$db->query("CREATE INDEX idx_transaction_lines_account_id ON transaction_lines(account_id)");
$db->query("CREATE INDEX idx_transaction_lines_fund_id ON transaction_lines(fund_id)");
$db->query("CREATE INDEX idx_transaction_lines_amount ON transaction_lines(amount)");
$db->query("CREATE INDEX idx_transaction_lines_type ON transaction_lines(type)");

echo "Transactions setup completed successfully!\n";

// Close connection
$db->close();
?>

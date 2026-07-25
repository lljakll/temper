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
    memo TEXT,
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

// Insert seed data: realistic church transactions (donations, expenses, transfers)
// At least 30 transactions, each with balanced debit/credit lines (dr == cr)
$transaction_details = "INSERT INTO transaction_details (transaction_date, cleared_date, check_number, pay_to, memo, reference_number, status, date_reconciled) VALUES
    ('2025-01-05', '2025-01-06', NULL, 'Worship Service Offering', 'First Sunday tithes and offerings of the year', 'OFF-250105', 'cleared', NULL),
    ('2025-01-08', '2025-01-09', '1201', 'City Electric Co.', 'Monthly electric utility bill', NULL, 'cleared', NULL),
    ('2025-01-12', '2025-01-15', NULL, 'Global Missions Outreach', 'January support payment', 'MSN-202501', 'reconciled', '2025-02-01'),
    ('2025-01-15', '2025-01-16', '1202', 'Metro Water Authority', 'Water and sewer services', NULL, 'cleared', NULL),
    ('2025-01-19', '2025-01-20', NULL, 'Worship Service Offering', 'Second Sunday of January', 'OFF-250119', 'cleared', NULL),
    ('2025-01-22', '2025-01-23', '1203', 'Office Depot', 'Office supplies, printer paper and ink', NULL, 'cleared', NULL),
    ('2025-01-25', '2025-01-27', '1204', 'Rev. Michael Thompson', 'Pastoral compensation - January', NULL, 'cleared', NULL),
    ('2025-01-28', '2025-01-29', NULL, 'Benevolence Assistance', 'Emergency housing support for member family', 'BEN-250128', 'cleared', NULL),
    ('2025-02-02', '2025-02-03', NULL, 'Worship Service Offering', 'February opening Sunday', 'OFF-250202', 'cleared', NULL),
    ('2025-02-05', '2025-02-06', NULL, 'Anonymous Donor', 'Designated gift for building repairs', 'BLD-250205', 'reconciled', '2025-02-20'),
    ('2025-02-08', '2025-02-10', '1205', 'Acme Insurance Agency', 'Property and liability insurance premium', NULL, 'cleared', NULL),
    ('2025-02-09', '2025-02-11', NULL, 'Regional Youth Camp', 'Deposit for summer youth camp (10 campers)', 'EVT-2025YC', 'cleared', NULL),
    ('2025-02-12', '2025-02-13', NULL, 'Worship Service Offering', 'Cash and check offerings', 'OFF-250212', 'cleared', NULL),
    ('2025-02-15', '2025-02-17', '1206', 'Sparkle Clean Janitorial', 'Monthly cleaning services', NULL, 'cleared', NULL),
    ('2025-02-20', '2025-02-21', NULL, 'Missions Designated Gift', 'Smith family missions pledge', 'DON-MSN-15', 'cleared', NULL),
    ('2025-02-22', '2025-02-24', '1207', 'Green Thumb Landscaping', 'Lawn care and snow removal', NULL, 'cleared', NULL),
    ('2025-02-28', '2025-03-01', '1208', 'Rev. Michael Thompson', 'Pastoral compensation - February', NULL, 'cleared', NULL),
    ('2025-03-02', '2025-03-03', NULL, 'Worship Service Offering', 'First Sunday March', 'OFF-250302', 'cleared', NULL),
    ('2025-03-05', '2025-03-06', '1209', 'Faith Book & Supply', 'Sunday school and VBS curriculum', NULL, 'cleared', NULL),
    ('2025-03-10', '2025-03-10', NULL, 'Internal Fund Transfer', 'Allocate reserves to building fund', 'XFR-250310', 'reconciled', '2025-03-15'),
    ('2025-03-16', '2025-03-17', NULL, 'Worship Service Offering', 'Mid March offerings', 'OFF-250316', 'cleared', NULL),
    ('2025-03-18', '2025-03-19', NULL, 'Hope Food Pantry', 'Monthly benevolence allocation', 'BEN-250318', 'cleared', NULL),
    ('2025-03-22', '2025-03-24', '1210', 'Comcast Business', 'Internet and phone service', NULL, 'cleared', NULL),
    ('2025-03-25', '2025-03-27', '1211', 'Rev. Michael Thompson', 'Pastoral compensation - March', NULL, 'cleared', NULL),
    ('2025-03-30', '2025-03-31', NULL, 'Easter Offering', 'Special resurrection Sunday collection', 'OFF-EAST25', 'cleared', NULL),
    ('2025-04-02', '2025-04-03', '1212', 'Harmony Piano Service', 'Annual piano tuning and maintenance', NULL, 'cleared', NULL),
    ('2025-04-06', '2025-04-07', NULL, 'Worship Service Offering', 'Palm Sunday offerings', 'OFF-250406', 'cleared', NULL),
    ('2025-04-10', '2025-04-11', NULL, 'Central Seminary Scholarship Fund', 'Leadership development grant', 'EDU-0425', 'cleared', NULL),
    ('2025-04-15', '2025-04-16', '1213', 'Sarah Kline - Admin', 'Administrative assistant wages', NULL, 'cleared', NULL),
    ('2025-04-20', '2025-04-21', NULL, 'Worship Service Offering', 'Regular Sunday giving', 'OFF-250420', 'cleared', NULL),
    ('2025-04-25', '2025-04-28', '1214', 'A+ Plumbing & Heating', 'Fellowship hall bathroom repair', NULL, 'cleared', NULL),
    ('2025-05-01', '2025-05-02', NULL, 'Global Missions Outreach', 'Q2 missions support payment', 'MSN-2025Q2', 'cleared', NULL),
    ('2025-05-04', '2025-05-05', NULL, 'Worship Service Offering', 'May the fourth Sunday offerings', 'OFF-250504', 'cleared', NULL),
    ('2026-06-10', NULL, '1219', 'Corner Market Supplies', 'Fellowship supplies and coffee', NULL, 'pending', NULL)";

if ($db->query($transaction_details) === TRUE) {
    echo "Seed data for 'transaction_details' inserted successfully (" . $db->affected_rows . " transactions)\n";
} else {
    echo "Error inserting transaction_details: " . $db->error . "\n";
    exit(1);
}

$transaction_lines = "INSERT INTO transaction_lines (transaction_detail_id, account_id, fund_id, amount, type, natural_category_id, functional_category_id, description) VALUES
    (1, 2, 1, 2845.50, 'debit', 1, 6, 'Cash and checks deposit'),
    (1, 10, 1, 2845.50, 'credit', 1, 6, 'General contributions'),
    (2, 9, 1, 378.40, 'debit', 8, 5, 'Utilities - electric'),
    (2, 2, 1, 378.40, 'credit', 8, 5, 'Payment to utility'),
    (3, 9, 2, 1500.00, 'debit', 2, 3, 'Missions disbursement'),
    (3, 2, 1, 1500.00, 'credit', 2, 3, 'Bank payment'),
    (4, 9, 1, 92.30, 'debit', 8, 5, 'Water and sewer'),
    (4, 2, 1, 92.30, 'credit', 8, 5, 'Payment to utility'),
    (5, 2, 1, 3050.00, 'debit', 1, 6, 'Weekly deposit'),
    (5, 10, 1, 3050.00, 'credit', 1, 6, 'General contributions'),
    (6, 9, 1, 67.80, 'debit', 3, 4, 'Admin supplies'),
    (6, 2, 1, 67.80, 'credit', 3, 4, 'Payment'),
    (7, 9, 1, 4250.00, 'debit', 6, 7, 'Pastoral salary'),
    (7, 2, 1, 4250.00, 'credit', 6, 7, 'Bank payment'),
    (8, 9, 3, 500.00, 'debit', 2, 3, 'Benevolence aid - housing'),
    (8, 2, 1, 500.00, 'credit', 2, 3, 'Bank payment'),
    (9, 2, 1, 2890.00, 'debit', 1, 6, 'Weekly deposit'),
    (9, 10, 1, 2890.00, 'credit', 1, 6, 'General contributions'),
    (10, 2, 4, 10000.00, 'debit', 1, 6, 'Designated building gift'),
    (10, 10, 4, 10000.00, 'credit', 1, 6, 'Building fund contribution'),
    (11, 9, 1, 1250.00, 'debit', 8, 5, 'Insurance premium'),
    (11, 2, 1, 1250.00, 'credit', 8, 5, 'Payment'),
    (12, 9, 1, 850.00, 'debit', 5, 2, 'Youth camp deposit'),
    (12, 2, 1, 850.00, 'credit', 5, 2, 'Payment'),
    (13, 1, 1, 485.00, 'debit', 1, 6, 'Cash in plate'),
    (13, 2, 1, 2620.00, 'debit', 1, 6, 'Checks and online gifts'),
    (13, 10, 1, 3105.00, 'credit', 1, 6, 'Total contributions'),
    (14, 9, 1, 320.00, 'debit', 8, 5, 'Janitorial services'),
    (14, 2, 1, 320.00, 'credit', 8, 5, 'Payment'),
    (15, 2, 2, 750.00, 'debit', 1, 6, 'Restricted missions gift'),
    (15, 10, 2, 750.00, 'credit', 1, 6, 'Missions contribution'),
    (16, 9, 1, 275.00, 'debit', 8, 5, 'Grounds maintenance'),
    (16, 2, 1, 275.00, 'credit', 8, 5, 'Payment'),
    (17, 9, 1, 4250.00, 'debit', 6, 7, 'Pastoral salary'),
    (17, 2, 1, 4250.00, 'credit', 6, 7, 'Bank payment'),
    (18, 2, 1, 3125.75, 'debit', 1, 6, 'Weekly deposit'),
    (18, 10, 1, 3125.75, 'credit', 1, 6, 'General contributions'),
    (19, 9, 1, 412.60, 'debit', 5, 2, 'Education supplies'),
    (19, 2, 1, 412.60, 'credit', 5, 2, 'Payment'),
    (20, 2, 4, 3000.00, 'debit', 4, 5, 'Transfer in to building'),
    (20, 2, 1, 3000.00, 'credit', 4, 5, 'Transfer out from general'),
    (21, 2, 1, 2765.00, 'debit', 1, 6, 'Weekly deposit'),
    (21, 10, 1, 2765.00, 'credit', 1, 6, 'General contributions'),
    (22, 9, 3, 325.00, 'debit', 2, 3, 'Benevolence - food pantry'),
    (22, 2, 1, 325.00, 'credit', 2, 3, 'Bank payment'),
    (23, 9, 1, 89.99, 'debit', 3, 4, 'Communications'),
    (23, 2, 1, 89.99, 'credit', 3, 4, 'Payment'),
    (24, 9, 1, 4250.00, 'debit', 6, 7, 'Pastoral salary'),
    (24, 2, 1, 4250.00, 'credit', 6, 7, 'Bank payment'),
    (25, 2, 1, 1925.50, 'debit', 1, 6, 'Special Easter offering'),
    (25, 10, 1, 1925.50, 'credit', 1, 6, 'General contributions'),
    (26, 9, 1, 175.00, 'debit', 8, 1, 'Worship support'),
    (26, 2, 1, 175.00, 'credit', 8, 1, 'Payment'),
    (27, 2, 1, 2540.00, 'debit', 1, 6, 'Weekly deposit'),
    (27, 10, 1, 2540.00, 'credit', 1, 6, 'General contributions'),
    (28, 9, 1, 1200.00, 'debit', 2, 2, 'Seminary scholarship'),
    (28, 2, 1, 1200.00, 'credit', 2, 2, 'Payment'),
    (29, 9, 1, 2100.00, 'debit', 6, 7, 'Admin wages'),
    (29, 2, 1, 2100.00, 'credit', 6, 7, 'Bank payment'),
    (30, 2, 1, 2995.00, 'debit', 1, 6, 'Weekly deposit'),
    (30, 10, 1, 2995.00, 'credit', 1, 6, 'General contributions'),
    (31, 9, 1, 685.00, 'debit', 4, 5, 'Facilities capital repair'),
    (31, 2, 1, 685.00, 'credit', 4, 5, 'Payment'),
    (32, 9, 2, 4500.00, 'debit', 2, 3, 'Q2 missions support'),
    (32, 2, 1, 4500.00, 'credit', 2, 3, 'Bank payment'),
    (33, 2, 1, 2680.00, 'debit', 1, 6, 'Weekly deposit'),
    (33, 10, 1, 2680.00, 'credit', 1, 6, 'General contributions'),
    (34, 9, 1, 58.75, 'debit', 8, 1, 'Fellowship supplies'),
    (34, 2, 1, 58.75, 'credit', 8, 1, 'Payment')";

if ($db->query($transaction_lines) === TRUE) {
    echo "Seed data for 'transaction_lines' inserted successfully (" . $db->affected_rows . " lines)\n";
} else {
    echo "Error inserting transaction_lines: " . $db->error . "\n";
    exit(1);
}

echo "Transactions setup completed successfully!\n";

// Close connection
$db->close();
?>
<?php
// Safety: This file should only be executed from the master setup_db.php
if (!defined('RUNNING_FROM_MASTER_SETUP') && !defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    die("❌ ERROR: This script should only be run from setup_db.php\n");
}

function setupSchemaFunds(): array
{
    return [
        'tables' => [
            'funds' => "CREATE TABLE IF NOT EXISTS funds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) UNIQUE NULL,
    type ENUM('WODR','WDR') NOT NULL,
    current_balance DECIMAL(15,2) DEFAULT 0.00,
    description TEXT,
    purpose TEXT,
    donor_reference VARCHAR(100) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    archived BOOLEAN DEFAULT FALSE,
    archived_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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

$funds_table = setupSchemaFunds()['tables']['funds'];

if ($db->query($funds_table) === TRUE) {
    echo "Table 'funds' created successfully\n";
} else {
    echo "Error creating table 'funds': " . $db->error . "\n";
    exit(1);
}

// Create indexes
$db->query("CREATE INDEX idx_funds_type ON funds(type)");
$db->query("CREATE INDEX idx_funds_is_active ON funds(is_active)");
$db->query("CREATE INDEX idx_funds_archived ON funds(archived)");

// Insert seed data: Funds
$general_fund = "INSERT INTO funds (name, code, type, description, purpose) VALUES
('General Operating Fund', 'GOF', 'WODR', 'Main operating fund for general church activities', 'General church operations')";

if ($db->query($general_fund) === TRUE) {
    echo "General Operating Fund inserted successfully\n";
} else {
    echo "Error inserting General Operating Fund: " . $db->error . "\n";
    exit(1);
}

// Insert seed data: 3 sample WDR funds
$wdr_funds = "INSERT INTO funds (name, code, type, description, purpose) VALUES
('Missions Fund', 'MF', 'WDR', 'Donor-restricted funds for missionary work', 'Mission work'),
('Benevolence Fund', 'BF', 'WDR', 'Donor-restricted funds for assistance to members in need', 'Member assistance'),
('Building Fund', 'BLD', 'WDR', 'Donor-restricted funds for church building projects', 'Building projects')";

if ($db->query($wdr_funds) === TRUE) {
    echo "WDR funds inserted successfully\n";
} else {
    echo "Error inserting WDR funds: " . $db->error . "\n";
    exit(1);
}
echo "Funds setup completed successfully!\n";

// Close connection
$db->close();
?>
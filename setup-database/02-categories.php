<?php
// Safety: This file should only be executed from the master setup_db.php
if (!defined('RUNNING_FROM_MASTER_SETUP')) {
    die("❌ ERROR: This script should only be run from setup_db.php\n");
}

// Require config file to get database connection details
require_once 'config.php';

// Get database connection
$db = getDbConnection();

// Create natural_categories table
$natural_categories_table = "CREATE TABLE IF NOT EXISTS natural_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    archived BOOLEAN DEFAULT FALSE,
    archived_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($db->query($natural_categories_table) === TRUE) {
    echo "Table 'natural_categories' created successfully\n";
} else {
    echo "Error creating table 'natural_categories': " . $db->error . "\n";
    exit(1);
}

// Create functional_categories table
$functional_categories_table = "CREATE TABLE IF NOT EXISTS functional_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    archived BOOLEAN DEFAULT FALSE,
    archived_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($db->query($functional_categories_table) === TRUE) {
    echo "Table 'functional_categories' created successfully\n";
} else {
    echo "Error creating table 'functional_categories': " . $db->error . "\n";
    exit(1);
}

// Create indexes
$db->query("CREATE INDEX idx_natural_categories_archived ON natural_categories(archived)");
$db->query("CREATE INDEX idx_functional_categories_archived ON functional_categories(archived)");

// Insert seed data: Natural Categories
$natural_categories = "INSERT INTO natural_categories (name, description) VALUES 
    ('Contributions', 'Donations and offerings'),
    ('Program', 'Expenses for church programs'),
    ('Administrative', 'Administrative expenses'),
    ('Capital Expenditure', 'Purchases of equipment or improvement'),
    ('Events', 'Expenses related to church events'),
    ('Salaries', 'Employee salaries and wages'),
    ('Benefits', 'Employee benefits'), 
    ('Operating', 'General operating expenses')";

if ($db->query($natural_categories) === TRUE) {
    echo "Seed data for 'natural_categories' inserted successfully\n";
} else {
    echo "Error inserting natural_categories: " . $db->error . "\n";
    exit(1);
}

// Insert seed data: Functional Categories
$functional_categories = "INSERT INTO functional_categories (name, description) VALUES 
    ('Worship', 'Expenses related to worship services'),
    ('Education', 'Expenses related to educational programs'),
    ('Community Outreach', 'Expenses related to community outreach'),
    ('Finance', 'Expenses related to financial operations'),
    ('Facilities', 'Expenses related to facilities maintenance'),
    ('Stewardship', 'Expenses related to stewardship and giving'),
    ('Leadership', 'Expenses related to leadership development')";

if ($db->query($functional_categories) === TRUE) {
    echo "Seed data for 'functional_categories' inserted successfully\n";
} else {
    echo "Error inserting functional_categories: " . $db->error . "\n";
    exit(1);
}

echo "Categories setup completed successfully!\n";

// Close connection
$db->close();
?>
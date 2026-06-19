<?php
// Church Treasurer System - Database Setup
// Based on Treasurer's Guide Rev 1.0

// Require config file to get database connection details
require_once 'config.php';

// Get database connection
$db = getDbConnection();

// Drop tables if they exist (makes script safe to run multiple times)
$db->query("DROP TABLE IF EXISTS budget_lines");
$db->query("DROP TABLE IF EXISTS budgets");
$db->query("DROP TABLE IF EXISTS transaction_lines");
$db->query("DROP TABLE IF EXISTS transaction_details");
$db->query("DROP TABLE IF EXISTS funds");
$db->query("DROP TABLE IF EXISTS functional_categories");
$db->query("DROP TABLE IF EXISTS natural_categories");
$db->query("DROP TABLE IF EXISTS accounts");
$db->query("DROP TABLE IF EXISTS roles");
$db->query("DROP TABLE IF EXISTS users");

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

// Create funds table
$funds_table = "CREATE TABLE IF NOT EXISTS funds (
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
)";

if ($db->query($funds_table) === TRUE) {
    echo "Table 'funds' created successfully\n";
} else {
    echo "Error creating table 'funds': " . $db->error . "\n";
    exit(1);
}

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

// Create transaction_lines table
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
    FOREIGN KEY (functional_category_id) REFERENCES functional_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (budget_line_id) REFERENCES budget_lines(id) ON DELETE SET NULL
)";

if ($db->query($transaction_lines_table) === TRUE) {
    echo "Table 'transaction_lines' created successfully\n";
} else {
    echo "Error creating table 'transaction_lines': " . $db->error . "\n";
    exit(1);
}

// Create budgets table
$budgets_table = "CREATE TABLE IF NOT EXISTS budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fiscal_year INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    approved_date DATE NULL,
    reference_number VARCHAR(50) NULL,
    status ENUM('draft', 'active', 'closed') DEFAULT 'draft',
    total_budgeted DECIMAL(15,2) DEFAULT 0.00,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($db->query($budgets_table) === TRUE) {
    echo "Table 'budgets' created successfully\n";
} else {
    echo "Error creating table 'budgets': " . $db->error . "\n";
    exit(1);
}

// Create budget_lines table
$budget_lines_table = "CREATE TABLE IF NOT EXISTS budget_lines (
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
)";

if ($db->query($budget_lines_table) === TRUE) {
    echo "Table 'budget_lines' created successfully\n";
} else {
    echo "Error creating table 'budget_lines': " . $db->error . "\n";
    exit(1);
}

// Create roles table
$roles_table = "CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    permissions JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($db->query($roles_table) === TRUE) {
    echo "Table 'roles' created successfully\n";
} else {
    echo "Error creating table 'roles': " . $db->error . "\n";
    exit(1);
}

// Create users table
$users_table = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NULL,
    role_id INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
)";

if ($db->query($users_table) === TRUE) {
    echo "Table 'users' created successfully\n";
} else {
    echo "Error creating table 'users': " . $db->error . "\n";
    exit(1);
}

// Create indexes
$db->query("CREATE INDEX idx_accounts_normal_balance ON accounts(normal_balance)");
$db->query("CREATE INDEX idx_accounts_archived ON accounts(archived)");
$db->query("CREATE INDEX idx_funds_type ON funds(type)");
$db->query("CREATE INDEX idx_funds_is_active ON funds(is_active)");
$db->query("CREATE INDEX idx_funds_archived ON funds(archived)");
$db->query("CREATE INDEX idx_transaction_details_date ON transaction_details(transaction_date)");
$db->query("CREATE INDEX idx_transaction_details_status ON transaction_details(status)");
$db->query("CREATE INDEX idx_transaction_lines_account_id ON transaction_lines(account_id)");
$db->query("CREATE INDEX idx_transaction_lines_fund_id ON transaction_lines(fund_id)");
$db->query("CREATE INDEX idx_transaction_lines_amount ON transaction_lines(amount)");
$db->query("CREATE INDEX idx_transaction_lines_type ON transaction_lines(type)");
$db->query("CREATE INDEX idx_budgets_fiscal_year ON budgets(fiscal_year)");
$db->query("CREATE INDEX idx_budgets_status ON budgets(status)");
$db->query("CREATE INDEX idx_budget_lines_budget_id ON budget_lines(budget_id)");
$db->query("CREATE INDEX idx_budget_lines_natural_category_id ON budget_lines(natural_category_id)");
$db->query("CREATE INDEX idx_budget_lines_functional_category_id ON budget_lines(functional_category_id)");
$db->query("CREATE INDEX idx_budget_lines_account_id ON budget_lines(account_id)");
$db->query("CREATE INDEX idx_budget_lines_budgeted_amount ON budget_lines(budgeted_amount)");

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
    ('Building Fund', 'BF', 'WDR', 'Donor-restricted funds for church building projects', 'Building projects')";

if ($db->query($wdr_funds) === TRUE) {
    echo "WDR funds inserted successfully\n";
} else {
    echo "Error inserting WDR funds: " . $db->error . "\n";
    exit(1);
}

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

// Insert seed data: Roles
$roles = "INSERT INTO roles (name, description) VALUES 
    ('Administrator', 'Full system access and management'),
    ('Treasurer', 'Access to financial data and transactions'),
    ('Board Member', 'Access to reports and financial summaries'),
    ('Read Only', 'Read-only access to financial data')";

if ($db->query($roles) === TRUE) {
    echo "Seed data for 'roles' inserted successfully\n";
} else {
    echo "Error inserting roles: " . $db->error . "\n";
    exit(1);
}

// Insert seed data: Sample User
$admin_password_hash = password_hash('admin123', PASSWORD_DEFAULT);
$users = "INSERT INTO users (username, password_hash, full_name, email, role_id) VALUES 
    ('admin', '$admin_password_hash', 'Admin User', 'admin@church.org', 1)";

if ($db->query($users) === TRUE) {
    echo "Seed data for 'users' inserted successfully\n";
} else {
    echo "Error inserting users: " . $db->error . "\n";
    exit(1);
}

echo "Database setup completed successfully!\n";

// Close connection
$db->close();
?>
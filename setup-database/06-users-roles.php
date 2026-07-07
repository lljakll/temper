<?php
// Safety: This file should only be executed from the master setup_db.php
if (!defined('RUNNING_FROM_MASTER_SETUP') && !defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    die("❌ ERROR: This script should only be run from setup_db.php\n");
}

function setupSchemaUsersRoles(): array
{
    return [
        'tables' => [
            'roles' => "CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    permissions JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)",
            'users' => "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
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

$schema = setupSchemaUsersRoles()['tables'];

if ($db->query($schema['roles']) === TRUE) {
    echo "Table 'roles' created successfully\n";
} else {
    echo "Error creating table 'roles': " . $db->error . "\n";
    exit(1);
}

if ($db->query($schema['users']) === TRUE) {
    echo "Table 'users' created successfully\n";
} else {
    echo "Error creating table 'users': " . $db->error . "\n";
    exit(1);
}

// Create indexes
$db->query("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)");
$db->query("CREATE INDEX IF NOT EXISTS idx_users_role_id ON users(role_id)");
$db->query("CREATE INDEX IF NOT EXISTS idx_users_is_active ON users(is_active)");

// Insert seed data: Roles
$roles = "INSERT INTO roles (name, description, permissions) VALUES 
    ('Administrator', 'System administrator with full access', '[]'),
    ('Finance Manager', 'Finance manager with access to financial data', '[]'),
    ('Member', 'Regular church member with limited access', '[]')";

if ($db->query($roles) === TRUE) {
    echo "Seed data for 'roles' inserted successfully\n";
} else {
    echo "Error inserting roles: " . $db->error . "\n";
    exit(1);
}

// Insert seed data: Users
$users = "INSERT INTO users (role_id, username, first_name, last_name, email, password, is_active) VALUES 
    (1, 'admin', 'Admin', 'User', 'admin@church.org', '\$2y\$12\$hElsAKEKx9CLXDqzYsxEeOLXq2V7vr.OY1qgi2RjTq19MqWII.Ute', TRUE),
    (2, 'finance', 'Finance', 'Manager', 'finance@church.org', '\$2y\$12\$hElsAKEKx9CLXDqzYsxEeOLXq2V7vr.OY1qgi2RjTq19MqWII.Ute', TRUE),
    (3, 'member', 'Regular', 'Member', 'member@church.org', '\$2y\$12\$hElsAKEKx9CLXDqzYsxEeOLXq2V7vr.OY1qgi2RjTq19MqWII.Ute', TRUE)";

if ($db->query($users) === TRUE) {
    echo "Seed data for 'users' inserted successfully\n";
} else {
    echo "Error inserting users: " . $db->error . "\n";
    exit(1);
}

echo "Users & Roles setup completed successfully!\n";

// Close connection
$db->close();
?>
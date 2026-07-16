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
    is_system TINYINT(1) NOT NULL DEFAULT 0,
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
    phone VARCHAR(30) NULL,
    password VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    custom_permissions JSON NULL,
    last_login DATETIME NULL,
    archived_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
)",
            'user_roles' => "CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    KEY idx_user_roles_role_id (role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
)",
        ],
    ];
}

if (defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    return;
}

// Require config file to get database connection details
require_once 'config.php';
require_once __DIR__ . '/../includes/permissions.php';

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

if ($db->query($schema['user_roles']) === TRUE) {
    echo "Table 'user_roles' created successfully\n";
} else {
    echo "Error creating table 'user_roles': " . $db->error . "\n";
    exit(1);
}

// Create indexes
$db->query("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)");
$db->query("CREATE INDEX IF NOT EXISTS idx_users_role_id ON users(role_id)");
$db->query("CREATE INDEX IF NOT EXISTS idx_users_is_active ON users(is_active)");

// Apply any additive migrations + seed predefined roles
ensureUsersRolesSchema($db);
$sync = ensureDefaultRoles($db);
echo "Roles seeded/synced (inserted={$sync['inserted']}, updated={$sync['updated']})\n";

// Default password for all seed users: "password"
$defaultHash = '$2y$12$hElsAKEKx9CLXDqzYsxEeOLXq2V7vr.OY1qgi2RjTq19MqWII.Ute';

// Resolve role IDs by name
$roleIds = [];
$res = $db->query('SELECT id, name FROM roles');
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $roleIds[$row['name']] = (int)$row['id'];
    }
    $res->close();
}

$seedUsers = [
    ['admin', 'Admin', 'User', 'admin@church.org', 'Administrator'],
    ['treasurer', 'Church', 'Treasurer', 'treasurer@church.org', 'Treasurer'],
    ['finance', 'Finance', 'Manager', 'finance@church.org', 'Finance Manager'],
    ['teller', 'First', 'Teller', 'teller@church.org', 'Teller'],
    ['board', 'Board', 'Member', 'board@church.org', 'Board Member'],
    ['member', 'Regular', 'Member', 'member@church.org', 'Member'],
];

$ins = $db->prepare(
    'INSERT INTO users (role_id, username, first_name, last_name, email, password, is_active, must_change_password)
     VALUES (?, ?, ?, ?, ?, ?, TRUE, 0)'
);

foreach ($seedUsers as $u) {
    [$username, $first, $last, $email, $roleName] = $u;
    $roleId = $roleIds[$roleName] ?? null;
    if ($roleId === null) {
        echo "Warning: role '{$roleName}' not found; skipping user {$username}\n";
        continue;
    }
    $ins->bind_param('isssss', $roleId, $username, $first, $last, $email, $defaultHash);
    if ($ins->execute()) {
        $uid = (int)$ins->insert_id;
        setUserRoles($db, $uid, [$roleId]);
        echo "Seed user '{$username}' ({$roleName}) created\n";
    } else {
        // Unique conflicts are OK on re-runs that somehow reach here
        echo "Seed user '{$username}': " . $ins->error . "\n";
    }
}
$ins->close();

echo "Users & Roles setup completed successfully!\n";

$db->close();
?>

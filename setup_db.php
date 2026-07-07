<?php
// Church Treasurer System - Run All Database Setup Scripts
require_once 'config.php';

$checkOnly = in_array('--check-only', $argv ?? [], true);

$setupFiles = [
    'setup-database/01-accounts.php',
    'setup-database/02-categories.php',
    'setup-database/03-funds.php',
    'setup-database/04-transactions.php',
    'setup-database/05-budgeting.php',
    'setup-database/06-users-roles.php',
    'setup-database/07-tasks.php',
    'setup-database/08-workflows.php',
];

if ($checkOnly) {
    echo "=== Database Check-Only Mode ===\n\n";
    echo "Validating database schema (no changes will be made)...\n\n";

    define('SETUP_DB_COLLECT_SCHEMA_ONLY', true);
    require_once 'setup-database/schema_validator.php';

    foreach ($setupFiles as $file) {
        if (file_exists($file)) {
            include $file;
        } else {
            echo "Warning: $file not found\n";
        }
    }

    $schemaProviders = [
        'setupSchemaAccounts',
        'setupSchemaCategories',
        'setupSchemaFunds',
        'setupSchemaTransactions',
        'setupSchemaBudgeting',
        'setupSchemaUsersRoles',
        'setupSchemaTasks',
        'setupSchemaWorkflows',
    ];

    $tables = setupDbCollectSchemas($schemaProviders);
    $db = getDbConnection();
    $validator = new DbSchemaValidator($db);
    $validator->validateAll($tables);
    $exitCode = $validator->printReport();
    $db->close();
    exit($exitCode);
}

echo "=== Starting Full Database Setup ===\n\n";

// Get database connection once
$db = getDbConnection();

// Drop all tables in reverse dependency order with IF EXISTS
echo "Dropping existing tables...\n";
$db->query("SET FOREIGN_KEY_CHECKS = 0;");

$drop_queries = [
    "DROP TABLE IF EXISTS workflow_events;",
    "DROP TABLE IF EXISTS workflow_documents;",
    "DROP TABLE IF EXISTS workflow_steps;",
    "DROP TABLE IF EXISTS workflow_instances;",
    "DROP TABLE IF EXISTS transaction_lines;",
    "DROP TABLE IF EXISTS tasks;",
    "DROP TABLE IF EXISTS budget_lines;",
    "DROP TABLE IF EXISTS users;",
    "DROP TABLE IF EXISTS transaction_details;",
    "DROP TABLE IF EXISTS budgets;",
    "DROP TABLE IF EXISTS roles;",
    "DROP TABLE IF EXISTS funds;",
    "DROP TABLE IF EXISTS accounts;",
    "DROP TABLE IF EXISTS natural_categories;",
    "DROP TABLE IF EXISTS functional_categories;",
];

foreach ($drop_queries as $query) {
    echo "Executing: $query\n";
    mysqli_query($db, $query);
}

$db->query("SET FOREIGN_KEY_CHECKS = 1;");

echo "\n";

// Run setup scripts in proper creation order
foreach ($setupFiles as $file) {
    if (file_exists($file)) {
        echo "Running $file...\n";
        if (!defined('RUNNING_FROM_MASTER_SETUP')) {
            define('RUNNING_FROM_MASTER_SETUP', true);
        }
        include $file;
        echo "\n";
    } else {
        echo "Warning: $file not found\n";
    }
}

echo "=== Full Database Setup Completed ===\n";
?>
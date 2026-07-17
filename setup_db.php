<?php
// Church Treasurer System - Run All Database Setup Scripts
require_once 'config.php';
require_once 'setup-database/setup_cli.php';

$cli = SetupDbCli::parse($argv ?? []);

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

$dropQueries = [
    'workflow_events',
    'workflow_steps',
    'workflow_instances',
    'workflow_definitions',
    'transaction_documents',
    'transaction_events',
    'transaction_lines',
    'tasks',
    'budget_lines',
    'users',
    'transaction_details',
    'budgets',
    'roles',
    'funds',
    'accounts',
    'natural_categories',
    'functional_categories',
];

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

/**
 * Require two quirky, case-sensitive confirmations before destructive setup.
 */
function setupDbRequireDestructiveConfirmation(int $tableCount): void
{
    echo "\n";
    echo "================================================================================\n";
    echo "                                                                                \n";
    echo "              !!!  DESTRUCTIVE DATABASE SETUP — READ CAREFULLY  !!!             \n";
    echo "                                                                                \n";
    echo "================================================================================\n";
    echo "\n";
    echo "  This operation will PERMANENTLY DELETE all data in the application tables.\n";
    echo "\n";
    echo "  Actions that will be performed:\n";
    echo "    • DROP all {$tableCount} application tables\n";
    echo "    • Recreate schema from setup-database/*.php scripts\n";
    echo "    • Re-seed default data (accounts, users, transactions, etc.)\n";
    echo "\n";
    echo "  Target database : " . DB_NAME . " @ " . DB_HOST . "\n";
    echo "  This CANNOT be undone.\n";
    echo "\n";
    echo "================================================================================\n";
    echo "\n";

    echo 'Type exactly "yEaH" to continue (case sensitive): ';
    $first = rtrim((string) fgets(STDIN), "\r\n");
    if ($first !== 'yEaH') {
        echo "\nAborted: first confirmation did not match. No changes were made.\n";
        exit(1);
    }

    echo 'Type exactly "YeP" to confirm again (case sensitive): ';
    $second = rtrim((string) fgets(STDIN), "\r\n");
    if ($second !== 'YeP') {
        echo "\nAborted: second confirmation did not match. No changes were made.\n";
        exit(1);
    }

    echo "\nDouble confirmation accepted. Proceeding with full database setup...\n\n";
}

if ($cli->help) {
    SetupDbCli::printHelp();
    exit(0);
}

if ($cli->check) {
    echo "=== Database Check ===\n\n";

    if ($cli->dryRun) {
        echo "Note: --dry-run is ignored in check mode (validation is always read-only).\n\n";
    }

    if (!$cli->verbose) {
        echo "Validating database schema (no changes will be made)...\n";
    }

    define('SETUP_DB_COLLECT_SCHEMA_ONLY', true);
    require_once 'setup-database/schema_validator.php';

    foreach ($setupFiles as $file) {
        if (file_exists($file)) {
            include $file;
        } else {
            echo "Warning: $file not found\n";
        }
    }

    $tables = setupDbCollectSchemas($schemaProviders);
    $db = getDbConnection();
    $validator = new DbSchemaValidator($db, $cli->verbose);
    $validator->validateAll($tables);
    $exitCode = $validator->printReport($cli->verbose);
    $db->close();
    exit($exitCode);
}

if ($cli->dryRun) {
    echo "=== Dry Run: Full Database Setup ===\n\n";
    echo "The following actions would be performed:\n\n";

    echo "1. Connect to database: " . DB_NAME . "@" . DB_HOST . "\n";
    echo "2. SET FOREIGN_KEY_CHECKS = 0\n";
    echo "3. Drop " . count($dropQueries) . " tables:\n";
    foreach ($dropQueries as $table) {
        $prefix = $cli->verbose ? '     ' : '   - ';
        echo "{$prefix}DROP TABLE IF EXISTS {$table};\n";
    }
    echo "4. SET FOREIGN_KEY_CHECKS = 1\n";
    echo "5. Run " . count($setupFiles) . " setup scripts:\n";
    foreach ($setupFiles as $file) {
        $prefix = $cli->verbose ? '     ' : '   - ';
        echo "{$prefix}{$file}\n";
    }

    echo "\nWARNING: Full setup is destructive — all existing data in these tables will be lost.\n";
    echo "\nNo changes were made.\n";
    exit(0);
}

setupDbRequireDestructiveConfirmation(count($dropQueries));

echo "=== Starting Full Database Setup ===\n\n";

// Get database connection once
$db = getDbConnection();

// Drop all tables in reverse dependency order with IF EXISTS
echo "Dropping existing tables...\n";
$db->query("SET FOREIGN_KEY_CHECKS = 0;");

foreach ($dropQueries as $table) {
    $query = "DROP TABLE IF EXISTS {$table};";
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
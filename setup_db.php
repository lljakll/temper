<?php
// Church Treasurer System - Run All Database Setup Scripts
//
// SETUP BASELINE (app v0.944): this script + setup-database/* establish the
// current schema (accounts.account_type, users.preferences, and all earlier
// shape) and seed app_version history through 0.944. No demo accounts, budgets,
// or transactions — lookup data (roles, categories, structural funds) and
// default users only. Releases after 0.944 are applied solely via updates/*.sql
// after setup (see VERSION.md). Pre-0.944 patches live in updates/archive/.
//
require_once 'config.php';
require_once 'setup-database/setup_cli.php';

$cli = SetupDbCli::parse($argv ?? []);

// Categories before accounts so account natural/functional FKs can be created.
$setupFiles = [
    'setup-database/02-categories.php',
    'setup-database/01-accounts.php',
    'setup-database/03-funds.php',
    'setup-database/04-transactions.php',
    'setup-database/05-budgeting.php',
    'setup-database/06-users-roles.php',
    'setup-database/07-tasks.php',
    'setup-database/08-app-version.php',
    'setup-database/09-audit-log.php',
];

$dropQueries = [
    'transaction_documents',
    'transaction_events',
    'transaction_lines',
    'tasks',
    'budget_lines',
    'user_roles',
    'users',
    'transaction_details',
    'budgets',
    'roles',
    'funds',
    'accounts',
    'natural_categories',
    'functional_categories',
    'audit_log',
    'app_version',
];

$schemaProviders = [
    'setupSchemaAccounts',
    'setupSchemaCategories',
    'setupSchemaFunds',
    'setupSchemaTransactions',
    'setupSchemaBudgeting',
    'setupSchemaUsersRoles',
    'setupSchemaTasks',
    'setupSchemaAppVersion',
    'setupSchemaAuditLog',
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
    echo "    • Re-seed lookup data (roles, categories, funds) and default users\n";
    echo "    • Leave accounts, budgets, and transactions empty (no demo ledger data)\n";
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
    require_once __DIR__ . '/includes/app_version.php';

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
    $structureExit = $validator->printReport($cli->verbose);

    // Baseline awareness: compare highest app_version to setup ceiling (0.944).
    // Also prints pending updates/*.sql status (messaging only).
    // Read-only — never runs destructive setup or applies patches.
    $baselineOk = setupDbPrintBaselineVersionReport($db);
    $updatesCurrent = !getDatabaseVersionLagStatus($db)['behind'];

    $db->close();

    if ($structureExit !== 0) {
        exit($structureExit);
    }
    if (!$baselineOk) {
        echo "=== Overall ===\n";
        echo "Structure validation passed, but setup baseline version check FAILED.\n";
        echo "Resolve the baseline warning before relying on this database for upgrades.\n";
        exit(1);
    }
    if (!$updatesCurrent) {
        echo "=== Overall ===\n";
        echo "Structure validation and setup baseline checks passed.\n";
        echo "SCHEMA UPDATES ARE PENDING — apply the listed updates/*.sql patch(es) and re-run --check.\n";
        exit(0);
    }
    echo "=== Overall ===\n";
    echo "Structure validation passed. Setup baseline OK. No schema updates are pending.\n";
    exit(0);
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
<?php
// Safety: This file should only be executed from the master setup_db.php
if (!defined('RUNNING_FROM_MASTER_SETUP') && !defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    die("❌ ERROR: This script should only be run from setup_db.php\n");
}

function setupSchemaAppVersion(): array
{
    return [
        'tables' => [
            'app_version' => "CREATE TABLE IF NOT EXISTS app_version (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(32) NOT NULL,
    schema_version VARCHAR(128) NOT NULL,
    patch_file VARCHAR(128) NULL DEFAULT NULL,
    notes VARCHAR(512) NULL DEFAULT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_app_version_applied (applied_at),
    KEY idx_app_version_schema (schema_version)
)",
        ],
    ];
}

if (defined('SETUP_DB_COLLECT_SCHEMA_ONLY')) {
    return;
}

require_once 'config.php';
require_once __DIR__ . '/../includes/app_version.php';

$db = getDbConnection();

$createSql = setupSchemaAppVersion()['tables']['app_version'];

if ($db->query($createSql) === TRUE) {
    echo "Table 'app_version' created successfully\n";
} else {
    echo "Error creating table 'app_version': " . $db->error . "\n";
    exit(1);
}

// Fresh install: seed baseline history only (through v0.944).
// Releases after 0.944 are applied solely via updates/*.sql after setup — not here.
if (!seedAppVersionHistory($db)) {
    echo "Error seeding app_version history\n";
    exit(1);
}

$latest = getAppVersionInfo($db);
echo "app_version baseline seeded (" . count(TEMPER_VERSION_HISTORY) . " rows through v"
    . TEMPER_SETUP_BASELINE_APP_VERSION . "); ";
echo "current v{$latest['version']} (schema {$latest['schema_version']})\n";
echo "Note: post-baseline releases (after 0.944) require updates/*.sql patches — see VERSION.md\n";

$db->close();
?>

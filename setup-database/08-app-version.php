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
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    version VARCHAR(32) NOT NULL,
    schema_version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
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

// Seed / refresh single-row version record to match this codebase
$version = TEMPER_DEFAULT_APP_VERSION;
$schema = TEMPER_EXPECTED_SCHEMA_VERSION;

// Upsert id=1
$res = $db->query('SELECT id FROM app_version WHERE id = 1 LIMIT 1');
$exists = $res && $res->num_rows > 0;
if ($res) {
    $res->close();
}

if ($exists) {
    $stmt = $db->prepare(
        'UPDATE app_version SET version = ?, schema_version = ? WHERE id = 1'
    );
    if ($stmt) {
        $stmt->bind_param('si', $version, $schema);
        $stmt->execute();
        $stmt->close();
    }
    echo "app_version row updated to v{$version} (schema {$schema})\n";
} else {
    $stmt = $db->prepare(
        'INSERT INTO app_version (id, version, schema_version) VALUES (1, ?, ?)'
    );
    if ($stmt) {
        $stmt->bind_param('si', $version, $schema);
        $stmt->execute();
        $stmt->close();
    }
    echo "app_version row seeded as v{$version} (schema {$schema})\n";
}

$db->close();
?>

<?php
/**
 * Application version helpers — hybrid versioning (VERSION.md + DB row).
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

/** Default / seed application version string (first tracked alpha). */
const TEMPER_DEFAULT_APP_VERSION = '0.801';

/**
 * Expected database schema version for this codebase.
 * Bump when migrations change table structure in a way that needs matching.
 */
const TEMPER_EXPECTED_SCHEMA_VERSION = 1;

/**
 * CREATE TABLE SQL for app_version (single-row store).
 */
function temperAppVersionCreateSql(): string
{
    return "CREATE TABLE IF NOT EXISTS app_version (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    version VARCHAR(32) NOT NULL,
    schema_version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

/**
 * Ensure app_version table exists and has the single seed row.
 * Safe to call repeatedly (idempotent).
 */
function ensureAppVersionTable(mysqli $db): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!$db->query(temperAppVersionCreateSql())) {
        error_log('[app_version] Failed to create table: ' . $db->error);
        return;
    }

    $res = $db->query('SELECT id FROM app_version WHERE id = 1 LIMIT 1');
    $exists = $res && $res->num_rows > 0;
    if ($res) {
        $res->close();
    }

    if (!$exists) {
        $version = TEMPER_DEFAULT_APP_VERSION;
        if (defined('APP_VERSION') && is_string(APP_VERSION) && APP_VERSION !== '') {
            // Prefer config constant when it looks like our tracked format
            $version = APP_VERSION;
        }
        $schema = TEMPER_EXPECTED_SCHEMA_VERSION;
        $stmt = $db->prepare(
            'INSERT INTO app_version (id, version, schema_version) VALUES (1, ?, ?)'
        );
        if ($stmt) {
            $stmt->bind_param('si', $version, $schema);
            $stmt->execute();
            $stmt->close();
        }
    }

    $done = true;
}

/**
 * Read the current application version string from the database.
 * Falls back to TEMPER_DEFAULT_APP_VERSION / APP_VERSION if unavailable.
 */
function getAppVersion(?mysqli $db = null): string
{
    $fallback = defined('APP_VERSION') && is_string(APP_VERSION) && APP_VERSION !== ''
        ? APP_VERSION
        : TEMPER_DEFAULT_APP_VERSION;

    if (!$db instanceof mysqli) {
        return $fallback;
    }

    ensureAppVersionTable($db);

    $res = $db->query('SELECT version FROM app_version WHERE id = 1 LIMIT 1');
    if ($res) {
        $row = $res->fetch_assoc();
        $res->close();
        if ($row && isset($row['version']) && $row['version'] !== '') {
            return (string)$row['version'];
        }
    }

    return $fallback;
}

/**
 * Full version row for diagnostics / future schema matching.
 *
 * @return array{version: string, schema_version: int, updated_at: ?string}
 */
function getAppVersionInfo(?mysqli $db = null): array
{
    $fallback = [
        'version' => getAppVersion(null),
        'schema_version' => TEMPER_EXPECTED_SCHEMA_VERSION,
        'updated_at' => null,
    ];

    if (!$db instanceof mysqli) {
        return $fallback;
    }

    ensureAppVersionTable($db);

    $res = $db->query(
        'SELECT version, schema_version, updated_at FROM app_version WHERE id = 1 LIMIT 1'
    );
    if ($res) {
        $row = $res->fetch_assoc();
        $res->close();
        if ($row) {
            return [
                'version' => (string)($row['version'] ?? $fallback['version']),
                'schema_version' => (int)($row['schema_version'] ?? TEMPER_EXPECTED_SCHEMA_VERSION),
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }
    }

    return $fallback;
}

/**
 * Update the stored application version (and optionally schema_version).
 */
function setAppVersion(mysqli $db, string $version, ?int $schemaVersion = null): bool
{
    $version = trim($version);
    if ($version === '') {
        return false;
    }

    ensureAppVersionTable($db);

    if ($schemaVersion === null) {
        $stmt = $db->prepare('UPDATE app_version SET version = ? WHERE id = 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $version);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    $stmt = $db->prepare(
        'UPDATE app_version SET version = ?, schema_version = ? WHERE id = 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('si', $version, $schemaVersion);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

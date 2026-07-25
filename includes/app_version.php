<?php
/**
 * Application version helpers — hybrid versioning
 * (VERSION.md + app_version history table + manual SQL patches in updates/).
 *
 * Schema updates are fully manual: operators apply files under updates/ via mysql.
 * This module only reads/writes version history rows; it does not apply patches.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

/** Default / seed application version string (current release). */
const TEMPER_DEFAULT_APP_VERSION = '0.802';

/**
 * Expected database schema version for this codebase.
 * Bump when a patch under updates/ changes table structure.
 * Matches the schema_version recorded in app_version history.
 */
const TEMPER_EXPECTED_SCHEMA_VERSION = 2;

/**
 * Complete version history for fresh installs (oldest → newest).
 * Each entry: version, schema_version, optional patch_file, optional notes.
 * setup_db.php / ensureAppVersionTable seed this list when the table is empty.
 *
 * @var list<array{version: string, schema_version: int, patch_file?: ?string, notes?: ?string}>
 */
const TEMPER_VERSION_HISTORY = [
    [
        'version' => '0.801',
        'schema_version' => 1,
        'patch_file' => null,
        'notes' => 'First tracked alpha; schema established by setup_db.php',
    ],
    [
        'version' => '0.802',
        'schema_version' => 2,
        'patch_file' => '20260725_01_app_version_history.sql',
        'notes' => 'app_version full history; formalized manual schema patches (updates/)',
    ],
];

/**
 * CREATE TABLE SQL for app_version (append-only version history).
 */
function temperAppVersionCreateSql(): string
{
    return "CREATE TABLE IF NOT EXISTS app_version (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(32) NOT NULL,
    schema_version INT NOT NULL DEFAULT 1,
    patch_file VARCHAR(128) NULL DEFAULT NULL,
    notes VARCHAR(512) NULL DEFAULT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_app_version_applied (applied_at),
    KEY idx_app_version_schema (schema_version)
)";
}

/**
 * Ensure app_version table exists and seed full history when empty.
 * Safe to call repeatedly (idempotent). Does not re-seed if any row exists.
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

    $res = $db->query('SELECT id FROM app_version LIMIT 1');
    $hasRows = $res && $res->num_rows > 0;
    if ($res) {
        $res->close();
    }

    if (!$hasRows) {
        seedAppVersionHistory($db);
    }

    $done = true;
}

/**
 * Insert the full TEMPER_VERSION_HISTORY seed set (fresh installs only).
 */
function seedAppVersionHistory(mysqli $db): bool
{
    $stmt = $db->prepare(
        'INSERT INTO app_version (version, schema_version, patch_file, notes)
         VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('[app_version] Failed to prepare history seed: ' . $db->error);
        return false;
    }

    foreach (TEMPER_VERSION_HISTORY as $entry) {
        $version = (string)($entry['version'] ?? '');
        $schema = (int)($entry['schema_version'] ?? 1);
        $patch = isset($entry['patch_file']) && $entry['patch_file'] !== null && $entry['patch_file'] !== ''
            ? (string)$entry['patch_file']
            : null;
        $notes = isset($entry['notes']) && $entry['notes'] !== null && $entry['notes'] !== ''
            ? (string)$entry['notes']
            : null;

        if ($version === '') {
            continue;
        }

        $stmt->bind_param('siss', $version, $schema, $patch, $notes);
        if (!$stmt->execute()) {
            error_log('[app_version] Failed to seed history row v' . $version . ': ' . $stmt->error);
            $stmt->close();
            return false;
        }
    }

    $stmt->close();
    return true;
}

/**
 * Read the current (latest) application version string from the database.
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

    $res = $db->query(
        'SELECT version FROM app_version ORDER BY id DESC LIMIT 1'
    );
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
 * Full latest version row for diagnostics / operator checks.
 *
 * @return array{version: string, schema_version: int, patch_file: ?string, notes: ?string, applied_at: ?string}
 */
function getAppVersionInfo(?mysqli $db = null): array
{
    $fallback = [
        'version' => getAppVersion(null),
        'schema_version' => TEMPER_EXPECTED_SCHEMA_VERSION,
        'patch_file' => null,
        'notes' => null,
        'applied_at' => null,
    ];

    if (!$db instanceof mysqli) {
        return $fallback;
    }

    ensureAppVersionTable($db);

    // Prefer history-shaped table (v0.802+). Fall back to legacy single-row columns
    // so sidebar/diagnostics still work if the manual schema patch has not been applied yet.
    $res = $db->query(
        'SELECT version, schema_version, patch_file, notes, applied_at
         FROM app_version
         ORDER BY id DESC
         LIMIT 1'
    );
    if (!$res) {
        $res = $db->query(
            'SELECT version, schema_version, updated_at AS applied_at
             FROM app_version
             ORDER BY id DESC
             LIMIT 1'
        );
    }
    if ($res) {
        $row = $res->fetch_assoc();
        $res->close();
        if ($row) {
            return [
                'version' => (string)($row['version'] ?? $fallback['version']),
                'schema_version' => (int)($row['schema_version'] ?? TEMPER_EXPECTED_SCHEMA_VERSION),
                'patch_file' => isset($row['patch_file']) && $row['patch_file'] !== ''
                    ? (string)$row['patch_file']
                    : null,
                'notes' => isset($row['notes']) && $row['notes'] !== ''
                    ? (string)$row['notes']
                    : null,
                'applied_at' => $row['applied_at'] ?? null,
            ];
        }
    }

    return $fallback;
}

/**
 * Return full version history (oldest → newest). Empty array if unavailable.
 *
 * @return list<array{id: int, version: string, schema_version: int, patch_file: ?string, notes: ?string, applied_at: ?string}>
 */
function getAppVersionHistory(?mysqli $db = null): array
{
    if (!$db instanceof mysqli) {
        return [];
    }

    ensureAppVersionTable($db);

    $res = $db->query(
        'SELECT id, version, schema_version, patch_file, notes, applied_at
         FROM app_version
         ORDER BY id ASC'
    );
    if (!$res) {
        return [];
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$row['id'],
            'version' => (string)$row['version'],
            'schema_version' => (int)$row['schema_version'],
            'patch_file' => isset($row['patch_file']) && $row['patch_file'] !== ''
                ? (string)$row['patch_file']
                : null,
            'notes' => isset($row['notes']) && $row['notes'] !== ''
                ? (string)$row['notes']
                : null,
            'applied_at' => $row['applied_at'] ?? null,
        ];
    }
    $res->close();

    return $rows;
}

/**
 * Append a version history row (manual patch / release record).
 * Prefer recording via the SQL patch itself; this helper is for setup tooling.
 */
function recordAppVersion(
    mysqli $db,
    string $version,
    int $schemaVersion,
    ?string $patchFile = null,
    ?string $notes = null
): bool {
    $version = trim($version);
    if ($version === '') {
        return false;
    }

    ensureAppVersionTable($db);

    $patch = ($patchFile !== null && trim($patchFile) !== '') ? trim($patchFile) : null;
    $note = ($notes !== null && trim($notes) !== '') ? trim($notes) : null;

    $stmt = $db->prepare(
        'INSERT INTO app_version (version, schema_version, patch_file, notes)
         VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('siss', $version, $schemaVersion, $patch, $note);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * @deprecated Use recordAppVersion(). Kept for callers that still pass a single current version.
 * Updates by appending a history row (does not mutate prior rows).
 */
function setAppVersion(mysqli $db, string $version, ?int $schemaVersion = null): bool
{
    $schema = $schemaVersion ?? TEMPER_EXPECTED_SCHEMA_VERSION;
    return recordAppVersion($db, $version, $schema, null, null);
}

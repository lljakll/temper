<?php
/**
 * Application version helpers — hybrid versioning
 * (VERSION.md + app_version history table + manual SQL patches in updates/).
 *
 * Schema updates are fully manual: operators apply files under updates/ via mysql.
 * This module only reads/writes version history rows; it does not apply patches.
 *
 * Schema version identity = patch filename stem (no .sql), e.g.
 *   20260725_01_app_version_history
 * Pre-patch baseline (setup_db only) uses TEMPER_SCHEMA_BASELINE.
 * App releases with no schema change carry forward the previous schema version.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

/** Default / seed application version string (current release). */
const TEMPER_DEFAULT_APP_VERSION = '0.803';

/**
 * Schema id for the initial setup_db.php shape (no updates/*.sql patch yet).
 * Used by v0.801 and any row that predates named patches.
 */
const TEMPER_SCHEMA_BASELINE = 'setup_baseline';

/**
 * Expected database schema version for this codebase (patch filename stem).
 * Equals the newest required schema id; carry forward when a release has no DDL.
 */
const TEMPER_EXPECTED_SCHEMA_VERSION = '20260725_02_schema_version_as_filename';

/**
 * Complete version history for fresh installs (oldest → newest).
 * schema_version is always set (patch stem, or TEMPER_SCHEMA_BASELINE).
 * patch_file is the .sql applied with that app version, or null if none
 * (schema_version is still set — carried forward or baseline).
 *
 * @var list<array{version: string, schema_version: string, patch_file?: ?string, notes?: ?string}>
 */
const TEMPER_VERSION_HISTORY = [
    [
        'version' => '0.801',
        'schema_version' => TEMPER_SCHEMA_BASELINE,
        'patch_file' => null,
        'notes' => 'First tracked alpha; schema established by setup_db.php',
    ],
    [
        'version' => '0.802',
        'schema_version' => '20260725_01_app_version_history',
        'patch_file' => '20260725_01_app_version_history.sql',
        'notes' => 'app_version full history; formalized manual schema patches (updates/)',
    ],
    [
        'version' => '0.803',
        'schema_version' => '20260725_02_schema_version_as_filename',
        'patch_file' => '20260725_02_schema_version_as_filename.sql',
        'notes' => 'schema_version stores patch filename stem (not integer)',
    ],
];

/**
 * Normalize a patch path/filename to the canonical schema version id (stem, no .sql).
 */
function temperSchemaVersionId(string $patchFileOrStem): string
{
    $base = basename(trim($patchFileOrStem));
    if ($base === '') {
        return TEMPER_SCHEMA_BASELINE;
    }
    if (preg_match('/\.sql$/i', $base)) {
        $base = substr($base, 0, -4);
    }
    return $base !== '' ? $base : TEMPER_SCHEMA_BASELINE;
}

/**
 * CREATE TABLE SQL for app_version (append-only version history).
 */
function temperAppVersionCreateSql(): string
{
    return "CREATE TABLE IF NOT EXISTS app_version (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(32) NOT NULL,
    schema_version VARCHAR(128) NOT NULL,
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
        $schema = temperSchemaVersionId((string)($entry['schema_version'] ?? TEMPER_SCHEMA_BASELINE));
        $patch = isset($entry['patch_file']) && $entry['patch_file'] !== null && $entry['patch_file'] !== ''
            ? (string)$entry['patch_file']
            : null;
        $notes = isset($entry['notes']) && $entry['notes'] !== null && $entry['notes'] !== ''
            ? (string)$entry['notes']
            : null;

        if ($version === '') {
            continue;
        }

        $stmt->bind_param('ssss', $version, $schema, $patch, $notes);
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
 * @return array{version: string, schema_version: string, patch_file: ?string, notes: ?string, applied_at: ?string}
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
    // so sidebar/diagnostics still work if a manual schema patch has not been applied yet.
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
            $rawSchema = (string)($row['schema_version'] ?? '');
            $schema = temperNormalizeStoredSchemaVersion($rawSchema, $row['patch_file'] ?? null);

            return [
                'version' => (string)($row['version'] ?? $fallback['version']),
                'schema_version' => $schema,
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
 * Map legacy integer / empty schema_version values to canonical filename stems.
 * Does not mutate the database (read path only).
 */
function temperNormalizeStoredSchemaVersion(string $rawSchema, mixed $patchFile = null): string
{
    $rawSchema = trim($rawSchema);
    if ($rawSchema === '' || $rawSchema === '0') {
        if (is_string($patchFile) && $patchFile !== '') {
            return temperSchemaVersionId($patchFile);
        }
        return TEMPER_SCHEMA_BASELINE;
    }

    // Legacy integer schema generations (pre-0.803)
    if (preg_match('/^\d+$/', $rawSchema)) {
        $map = [
            '1' => TEMPER_SCHEMA_BASELINE,
            '2' => '20260725_01_app_version_history',
        ];
        if (isset($map[$rawSchema])) {
            return $map[$rawSchema];
        }
        if (is_string($patchFile) && $patchFile !== '') {
            return temperSchemaVersionId($patchFile);
        }
        return TEMPER_SCHEMA_BASELINE;
    }

    return temperSchemaVersionId($rawSchema);
}

/**
 * Return full version history (oldest → newest). Empty array if unavailable.
 *
 * @return list<array{id: int, version: string, schema_version: string, patch_file: ?string, notes: ?string, applied_at: ?string}>
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
            'schema_version' => temperNormalizeStoredSchemaVersion(
                (string)($row['schema_version'] ?? ''),
                $row['patch_file'] ?? null
            ),
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
 *
 * @param string $schemaVersion Patch filename stem (or TEMPER_SCHEMA_BASELINE). Required.
 * @param string|null $patchFile Full .sql basename when this release applied a patch; null if carry-forward.
 */
function recordAppVersion(
    mysqli $db,
    string $version,
    string $schemaVersion,
    ?string $patchFile = null,
    ?string $notes = null
): bool {
    $version = trim($version);
    if ($version === '') {
        return false;
    }

    $schema = temperSchemaVersionId($schemaVersion);
    if ($schema === '') {
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

    $stmt->bind_param('ssss', $version, $schema, $patch, $note);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * @deprecated Use recordAppVersion(). Kept for callers that still pass a single current version.
 * Updates by appending a history row (does not mutate prior rows).
 * When $schemaVersion is null, carries forward TEMPER_EXPECTED_SCHEMA_VERSION.
 */
function setAppVersion(mysqli $db, string $version, ?string $schemaVersion = null): bool
{
    $schema = $schemaVersion !== null && trim($schemaVersion) !== ''
        ? $schemaVersion
        : TEMPER_EXPECTED_SCHEMA_VERSION;
    return recordAppVersion($db, $version, $schema, null, null);
}

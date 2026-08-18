<?php
/**
 * Backup / restore utilities — data-only (SQL, CSV) and full schema+data dumps.
 * All backups live under storage/backups/ with optional .sha256 sidecars.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/storage_paths.php';

/** Auto-backup runtime state (last run), relative to backup dir. */
const TEMPER_AUTO_BACKUP_STATE_FILE = '.auto_backup_state.json';

/**
 * Allowed data-only / full backup basename patterns.
 * - Legacy full: backup_YYYY-MM-DD_HHMMSS.sql
 * - Data SQL:    backup_data_YYYY-MM-DD_HHMMSS.sql
 * - Data CSV:    backup_data_YYYY-MM-DD_HHMMSS.zip
 * - Full:        backup_full_YYYY-MM-DD_HHMMSS.sql
 */
function safeBackupFilename(string $filename): ?string {
    $base = basename($filename);
    if (@preg_match(
        '/^backup_(?:data_|full_)?[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.(?:sql|zip)$/',
        $base
    ) === 1) {
        return $base;
    }
    // Uploaded restore archives written next to created backups
    if (@preg_match(
        '/^restored_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}_[A-Za-z0-9._-]+\.(?:sql|zip)$/',
        $base
    ) === 1) {
        return $base;
    }
    return null;
}

/**
 * Classify a safe backup filename.
 *
 * @return 'data_sql'|'data_csv'|'full'|'legacy_full'|null
 */
function backupFileKind(string $filename): ?string {
    $safe = safeBackupFilename($filename);
    if ($safe === null) {
        return null;
    }
    if (str_starts_with($safe, 'backup_data_') && str_ends_with($safe, '.zip')) {
        return 'data_csv';
    }
    if (str_starts_with($safe, 'backup_data_') && str_ends_with($safe, '.sql')) {
        return 'data_sql';
    }
    if (str_starts_with($safe, 'backup_full_') && str_ends_with($safe, '.sql')) {
        return 'full';
    }
    if (str_starts_with($safe, 'restored_') && str_ends_with($safe, '.zip')) {
        return 'restored_csv';
    }
    if (str_starts_with($safe, 'restored_') && str_ends_with($safe, '.sql')) {
        return 'restored_sql';
    }
    if (preg_match('/^backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.sql$/', $safe) === 1) {
        return 'legacy_full';
    }
    return null;
}

function backupIsDataOnly(string $filename): bool {
    $kind = backupFileKind($filename);
    return $kind === 'data_sql' || $kind === 'data_csv';
}

function backupIsFullSchema(string $filename): bool {
    $kind = backupFileKind($filename);
    return $kind === 'full' || $kind === 'legacy_full';
}

function formatBackupFilenameDatetime(string $filename): ?string {
    $safe = safeBackupFilename($filename);
    if ($safe === null) {
        return null;
    }
    if (@preg_match(
        '/^backup_(?:data_|full_)?([0-9]{4}-[0-9]{2}-[0-9]{2})_([0-9]{2})([0-9]{2})[0-9]{2}\.(?:sql|zip)$/',
        $safe,
        $matches
    ) === 1) {
        return $matches[1] . ' ' . $matches[2] . ':' . $matches[3] . ' UTC';
    }
    if (@preg_match(
        '/^restored_([0-9]{4}-[0-9]{2}-[0-9]{2})_([0-9]{2})([0-9]{2})[0-9]{2}_/',
        $safe,
        $matches
    ) === 1) {
        return $matches[1] . ' ' . $matches[2] . ':' . $matches[3] . ' UTC';
    }
    return null;
}

function parseBackupFilenameTimestamp(string $filename): ?int {
    $safe = safeBackupFilename($filename);
    if ($safe === null) {
        return null;
    }
    if (@preg_match(
        '/^backup_(?:data_|full_)?([0-9]{4}-[0-9]{2}-[0-9]{2})_([0-9]{6})\.(?:sql|zip)$/',
        $safe,
        $matches
    ) !== 1) {
        if (@preg_match(
            '/^restored_([0-9]{4}-[0-9]{2}-[0-9]{2})_([0-9]{6})_/',
            $safe,
            $matches
        ) !== 1) {
            return null;
        }
    }

    $dt = DateTimeImmutable::createFromFormat(
        'Y-m-d His',
        $matches[1] . ' ' . $matches[2],
        new DateTimeZone('UTC')
    );

    return $dt !== false ? $dt->getTimestamp() : null;
}

/**
 * Build a timestamp token for backup filenames (UTC).
 */
function backupTimestampToken(?DateTimeInterface $when = null): string {
    $when = $when ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $when->format('Y-m-d_His');
}

function backupDataSqlFilename(string $token): string {
    return 'backup_data_' . $token . '.sql';
}

function backupDataCsvFilename(string $token): string {
    return 'backup_data_' . $token . '.zip';
}

function backupFullFilename(string $token): string {
    return 'backup_full_' . $token . '.sql';
}

function backupKindLabel(string $filename): string {
    return match (backupFileKind($filename)) {
        'data_sql' => 'Data (SQL)',
        'data_csv' => 'Data (CSV)',
        'full' => 'Full (schema + data)',
        'legacy_full' => 'Full (legacy)',
        'restored_sql' => 'Restored (SQL)',
        'restored_csv' => 'Restored (CSV)',
        default => 'Backup',
    };
}

function listRecentBackupSummaries(string $dir, int $limit = 4): array {
    $all = listBackupFiles($dir, false);
    $out = [];
    foreach (array_slice($all, 0, max(0, $limit)) as $entry) {
        $out[] = [
            'name' => $entry['name'],
            'modified' => $entry['modified'],
            'display_datetime' => $entry['display_datetime'] ?? 'Unknown',
            'kind' => $entry['kind'] ?? null,
            'kind_label' => $entry['kind_label'] ?? '',
        ];
    }
    return $out;
}

function backupChecksumFilename(string $backupFilename): string {
    return $backupFilename . '.sha256';
}

function computeFileSha256(string $path): string|false {
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    $hash = @hash_file('sha256', $path);
    if (!is_string($hash) || $hash === '') {
        return false;
    }
    return strtolower($hash);
}

function readBackupChecksum(string $backupDir, string $backupFilename): ?string {
    if (safeBackupFilename($backupFilename) === null) {
        return null;
    }

    $path = rtrim($backupDir, '/\\') . '/' . backupChecksumFilename($backupFilename);
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $content = @file_get_contents($path);
    if (!is_string($content)) {
        return null;
    }
    $content = trim($content);
    if ($content === '') {
        return null;
    }

    if (@preg_match('/^([a-f0-9]{64})\b/i', $content, $matches) === 1) {
        $hash = $matches[1] ?? null;
        if (is_string($hash) && $hash !== '') {
            return strtolower($hash);
        }
    }

    return null;
}

function writeBackupChecksumFile(string $backupDir, string $backupFilename): array {
    if (safeBackupFilename($backupFilename) === null) {
        return ['success' => false, 'error' => 'Invalid backup filename for checksum generation.'];
    }

    $filePath = rtrim($backupDir, '/\\') . '/' . $backupFilename;
    if (!is_file($filePath) || !is_readable($filePath)) {
        return ['success' => false, 'error' => 'Backup file not found for checksum generation.'];
    }

    $hash = computeFileSha256($filePath);
    if ($hash === false) {
        return ['success' => false, 'error' => 'Could not compute SHA256 checksum.'];
    }

    $content = $hash . '  ' . $backupFilename . "\n";
    $checksumPath = rtrim($backupDir, '/\\') . '/' . backupChecksumFilename($backupFilename);
    $write = writeStorageFile($checksumPath, $content);
    if (!$write['success']) {
        return $write;
    }

    return ['success' => true, 'checksum' => $hash, 'path' => $checksumPath];
}

function pruneOrphanedChecksumFiles(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }

    $dir = rtrim($dir, '/\\');
    $paths = glob($dir . '/*.sha256', GLOB_NOSORT);
    if ($paths === false) {
        return;
    }

    foreach ($paths as $path) {
        if (!is_string($path) || !is_file($path)) {
            continue;
        }

        $checksumName = basename($path);
        if (!str_ends_with(strtolower($checksumName), '.sha256')) {
            continue;
        }

        $backupName = substr($checksumName, 0, -strlen('.sha256'));
        if (safeBackupFilename($backupName) === null || !is_file($dir . '/' . $backupName)) {
            @unlink($path);
        }
    }
}

/**
 * List tables in the current database (stable order).
 *
 * @return list<string>
 */
function listDatabaseTables(mysqli $db): array {
    $tables = [];
    $res = $db->query('SHOW TABLES');
    if ($res) {
        while ($row = $res->fetch_array()) {
            if (isset($row[0]) && is_string($row[0]) && $row[0] !== '') {
                $tables[] = $row[0];
            }
        }
        $res->close();
    }
    return $tables;
}

/**
 * System tables omitted from data-only backup and restore.
 * Full (schema + data) dumps still include every table.
 * roles and all operational tables remain in the data-only set.
 *
 * @return list<string>
 */
function dataOnlyBackupExcludedTables(): array {
    return ['app_version', 'audit_log'];
}

function isDataOnlyExcludedTable(string $table): bool {
    $normalized = strtolower(trim($table, " \t\n\r\0\x0B`\"'"));
    return in_array($normalized, dataOnlyBackupExcludedTables(), true);
}

/**
 * Tables included in a data-only backup (all current tables minus system exclusions).
 *
 * @return list<string>
 */
function listDataOnlyBackupTables(mysqli $db): array {
    $out = [];
    foreach (listDatabaseTables($db) as $table) {
        if (!isDataOnlyExcludedTable($table)) {
            $out[] = $table;
        }
    }
    return $out;
}

/**
 * Remove TRUNCATE / INSERT / DELETE / UPDATE statements (and table-data
 * comments) that target data-only excluded system tables so a restore of an
 * older dump cannot wipe app_version history or audit_log.
 */
function stripExcludedTablesFromDataOnlySql(string $sql): string {
    $lines = preg_split("/\r\n|\r|\n/", $sql);
    if (!is_array($lines)) {
        return $sql;
    }

    $out = [];
    $skipUntilSemicolon = false;
    foreach ($lines as $line) {
        if ($skipUntilSemicolon) {
            if (str_contains($line, ';')) {
                $skipUntilSemicolon = false;
            }
            continue;
        }

        $trim = ltrim($line);
        if (@preg_match('/^--\s*Table data:\s*`?([A-Za-z0-9_]+)`?/i', $trim, $commentMatch) === 1
            && isDataOnlyExcludedTable($commentMatch[1])) {
            continue;
        }

        if (@preg_match(
            '/^(?:TRUNCATE\s+TABLE|INSERT\s+INTO|REPLACE\s+INTO|DELETE\s+FROM|UPDATE)\s+`?([A-Za-z0-9_]+)`?/i',
            $trim,
            $stmtMatch
        ) === 1 && isDataOnlyExcludedTable($stmtMatch[1])) {
            if (!str_contains($line, ';')) {
                $skipUntilSemicolon = true;
            }
            continue;
        }

        $out[] = $line;
    }

    return implode("\n", $out);
}

function backupSqlValue(mysqli $db, mixed $value): string {
    if ($value === null) {
        return 'NULL';
    }
    return "'" . $db->real_escape_string((string)$value) . "'";
}

/**
 * Data-only SQL dump: TRUNCATE + INSERT (no CREATE/DROP).
 */
function generateDataOnlySqlBackup(mysqli $db): string {
    $excluded = dataOnlyBackupExcludedTables();
    $sql = "-- Hope Baptist Treasurer Data-Only Backup\n";
    $sql .= "-- Type: data-only\n";
    $sql .= "-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n";
    $sql .= "-- Database: " . DB_NAME . "\n";
    $sql .= "-- Excluded system tables: " . implode(', ', $excluded) . "\n\n";
    $sql .= "SET NAMES utf8mb4;\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach (listDataOnlyBackupTables($db) as $table) {
        $safeTable = str_replace('`', '``', $table);
        $sql .= "-- Table data: `$safeTable`\n";
        $sql .= "TRUNCATE TABLE `$safeTable`;\n";

        $dataRes = $db->query("SELECT * FROM `$safeTable`");
        if ($dataRes && $dataRes->num_rows > 0) {
            $first = $dataRes->fetch_assoc();
            if (is_array($first)) {
                $columns = array_keys($first);
                $dataRes->data_seek(0);
                $colList = '`' . implode('`, `', array_map(
                    static fn($c) => str_replace('`', '``', (string)$c),
                    $columns
                )) . '`';

                while ($row = $dataRes->fetch_assoc()) {
                    $values = array_map(static fn($col) => backupSqlValue($db, $row[$col] ?? null), $columns);
                    $sql .= "INSERT INTO `$safeTable` ($colList) VALUES (" . implode(', ', $values) . ");\n";
                }
            }
            $dataRes->close();
        } elseif ($dataRes) {
            $dataRes->close();
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $sql;
}

/**
 * Full schema + data dump (CREATE/DROP + INSERT). Used from Database Maintenance.
 */
function generateFullSchemaBackup(mysqli $db): string {
    $sql = "-- Hope Baptist Treasurer Full Database Backup\n";
    $sql .= "-- Type: full (schema + data)\n";
    $sql .= "-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n";
    $sql .= "-- Database: " . DB_NAME . "\n\n";
    $sql .= "SET NAMES utf8mb4;\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach (listDatabaseTables($db) as $table) {
        $safeTable = str_replace('`', '``', $table);
        $createRes = $db->query("SHOW CREATE TABLE `$safeTable`");
        if (!$createRes) {
            continue;
        }
        $createRow = $createRes->fetch_assoc();
        $createRes->close();
        if (!is_array($createRow) || empty($createRow['Create Table'])) {
            continue;
        }

        $sql .= "DROP TABLE IF EXISTS `$safeTable`;\n";
        $sql .= $createRow['Create Table'] . ";\n\n";

        $dataRes = $db->query("SELECT * FROM `$safeTable`");
        if ($dataRes && $dataRes->num_rows > 0) {
            $first = $dataRes->fetch_assoc();
            if (is_array($first)) {
                $columns = array_keys($first);
                $dataRes->data_seek(0);
                $colList = '`' . implode('`, `', array_map(
                    static fn($c) => str_replace('`', '``', (string)$c),
                    $columns
                )) . '`';

                while ($row = $dataRes->fetch_assoc()) {
                    $values = array_map(static fn($col) => backupSqlValue($db, $row[$col] ?? null), $columns);
                    $sql .= "INSERT INTO `$safeTable` ($colList) VALUES (" . implode(', ', $values) . ");\n";
                }
            }
            $dataRes->close();
        } elseif ($dataRes) {
            $dataRes->close();
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $sql;
}

/**
 * @deprecated Use generateFullSchemaBackup()
 */
function generateDatabaseBackup(mysqli $db): string {
    return generateFullSchemaBackup($db);
}

/**
 * Build CSV zip binary (data-only). One CSV per table + manifest.json.
 *
 * @return array{success:bool,error?:string,binary?:string,tables?:list<string>}
 */
function generateDataOnlyCsvZip(mysqli $db): array {
    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'error' => 'PHP ZipArchive extension is required for CSV backups.'];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'temper_csv_');
    if ($tmp === false) {
        return ['success' => false, 'error' => 'Could not create temporary file for CSV backup.'];
    }
    $zipPath = $tmp . '.zip';
    @unlink($tmp);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['success' => false, 'error' => 'Could not open temporary zip for CSV backup.'];
    }

    $tables = listDataOnlyBackupTables($db);
    $tableMeta = [];

    foreach ($tables as $table) {
        $safeTable = str_replace('`', '``', $table);
        $dataRes = $db->query("SELECT * FROM `$safeTable`");
        if (!$dataRes) {
            continue;
        }

        $csvHandle = fopen('php://temp', 'r+');
        if ($csvHandle === false) {
            $dataRes->close();
            $zip->close();
            @unlink($zipPath);
            return ['success' => false, 'error' => 'Could not allocate CSV buffer.'];
        }

        $rowCount = 0;
        $columns = [];
        while ($row = $dataRes->fetch_assoc()) {
            if ($rowCount === 0) {
                $columns = array_keys($row);
                fputcsv($csvHandle, $columns, ',', '"', '\\');
            }
            $line = [];
            foreach ($columns as $col) {
                $val = $row[$col] ?? null;
                $line[] = $val === null ? '' : (string)$val;
            }
            fputcsv($csvHandle, $line, ',', '"', '\\');
            $rowCount++;
        }

        // Empty table: still write header if we can get columns
        if ($rowCount === 0) {
            $colRes = $db->query("SHOW COLUMNS FROM `$safeTable`");
            if ($colRes) {
                while ($colRow = $colRes->fetch_assoc()) {
                    if (!empty($colRow['Field'])) {
                        $columns[] = $colRow['Field'];
                    }
                }
                $colRes->close();
            }
            if ($columns !== []) {
                fputcsv($csvHandle, $columns, ',', '"', '\\');
            }
        }

        $dataRes->close();
        rewind($csvHandle);
        $csvBody = stream_get_contents($csvHandle);
        fclose($csvHandle);
        if (!is_string($csvBody)) {
            $csvBody = '';
        }

        $entryName = preg_replace('/[^a-zA-Z0-9_]/', '_', $table) . '.csv';
        $zip->addFromString($entryName, $csvBody);
        $tableMeta[] = [
            'table' => $table,
            'file' => $entryName,
            'columns' => $columns,
            'rows' => $rowCount,
        ];
    }

    $manifest = [
        'type' => 'data-only',
        'format' => 'csv-zip',
        'generated' => gmdate('c'),
        'database' => defined('DB_NAME') ? DB_NAME : '',
        'tables' => $tableMeta,
    ];
    $zip->addFromString(
        'manifest.json',
        (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
    );
    $zip->close();

    $binary = @file_get_contents($zipPath);
    @unlink($zipPath);
    if (!is_string($binary) || $binary === '') {
        return ['success' => false, 'error' => 'CSV zip backup was empty or unreadable.'];
    }

    return [
        'success' => true,
        'binary' => $binary,
        'tables' => $tables,
    ];
}

/**
 * Write a backup file + checksum into storage/backups/.
 *
 * @return array{success:bool,error?:string,file?:string,path?:string,size?:int,checksum?:string}
 */
function saveBackupArtifact(string $filename, string $contents): array {
    if (safeBackupFilename($filename) === null) {
        return ['success' => false, 'error' => 'Invalid backup filename.'];
    }

    $dirInfo = ensureStorageSubdir('backups');
    if ($dirInfo['error'] !== null) {
        return ['success' => false, 'error' => 'Backup storage is not writable: ' . $dirInfo['error']];
    }

    $dir = rtrim($dirInfo['path'], '/\\');
    $path = $dir . '/' . $filename;
    $write = writeStorageFile($path, $contents);
    if (!$write['success']) {
        return $write;
    }

    $checksumWrite = writeBackupChecksumFile($dir, $filename);
    if (!$checksumWrite['success']) {
        return [
            'success' => false,
            'error' => $checksumWrite['error'] ?? 'Checksum write failed.',
            'file' => $filename,
            'path' => $path,
        ];
    }

    return [
        'success' => true,
        'file' => $filename,
        'path' => $path,
        'size' => (int)($write['bytes'] ?? strlen($contents)),
        'checksum' => $checksumWrite['checksum'] ?? null,
    ];
}

/**
 * Create data-only backup(s) for the given format: sql | csv | both.
 *
 * @return array{
 *   success:bool,
 *   error?:string,
 *   files?:list<array{file:string,size:int,checksum:?string,kind:string}>,
 *   message?:string
 * }
 */
function createDataOnlyBackup(mysqli $db, string $format = 'sql', ?string $token = null): array {
    $format = strtolower(trim($format));
    if (!in_array($format, ['sql', 'csv', 'both'], true)) {
        return ['success' => false, 'error' => 'Invalid backup format. Use sql, csv, or both.'];
    }

    $token = $token ?? backupTimestampToken();
    $files = [];

    if ($format === 'sql' || $format === 'both') {
        $sql = generateDataOnlySqlBackup($db);
        if (trim($sql) === '') {
            return ['success' => false, 'error' => 'Data-only SQL backup generated empty content.'];
        }
        $saved = saveBackupArtifact(backupDataSqlFilename($token), $sql);
        if (!$saved['success']) {
            return $saved;
        }
        $files[] = [
            'file' => $saved['file'],
            'size' => (int)$saved['size'],
            'checksum' => $saved['checksum'] ?? null,
            'kind' => 'data_sql',
        ];
    }

    if ($format === 'csv' || $format === 'both') {
        $csv = generateDataOnlyCsvZip($db);
        if (!$csv['success']) {
            return ['success' => false, 'error' => $csv['error'] ?? 'CSV backup failed.', 'files' => $files];
        }
        $saved = saveBackupArtifact(backupDataCsvFilename($token), $csv['binary']);
        if (!$saved['success']) {
            return ['success' => false, 'error' => $saved['error'] ?? 'CSV save failed.', 'files' => $files];
        }
        $files[] = [
            'file' => $saved['file'],
            'size' => (int)$saved['size'],
            'checksum' => $saved['checksum'] ?? null,
            'kind' => 'data_csv',
        ];
    }

    $names = array_map(static fn($f) => $f['file'], $files);
    return [
        'success' => true,
        'files' => $files,
        'message' => 'Data-only backup created: ' . implode(', ', $names),
    ];
}

/**
 * Create full schema+data SQL backup.
 *
 * @return array{success:bool,error?:string,file?:string,size?:int,checksum?:string,message?:string}
 */
function createFullSchemaBackup(mysqli $db, ?string $token = null): array {
    $token = $token ?? backupTimestampToken();
    $sql = generateFullSchemaBackup($db);
    if (trim($sql) === '') {
        return ['success' => false, 'error' => 'Full backup generated empty content.'];
    }
    $saved = saveBackupArtifact(backupFullFilename($token), $sql);
    if (!$saved['success']) {
        return $saved;
    }
    return [
        'success' => true,
        'file' => $saved['file'],
        'size' => (int)$saved['size'],
        'checksum' => $saved['checksum'] ?? null,
        'message' => 'Full schema+data backup created: ' . $saved['file'],
    ];
}

function sanitizeSqlBackupContent(string $sql): string {
    if (str_starts_with($sql, "\xEF\xBB\xBF")) {
        $sql = substr($sql, 3);
    }

    $warningPattern = '/^(?:\s*<br\s*\/?>\s*)*(?:Warning|Notice|Deprecated|Fatal error|Parse error):.*?(?:\r?\n|<br\s*\/?>)/is';
    $previous = null;
    while ($previous !== $sql) {
        $previous = $sql;
        $sql = @preg_replace($warningPattern, '', $sql) ?? $sql;
        $sql = ltrim($sql);
    }

    $startPattern = '/^\s*(?:--|\/\*|(?:SET|DROP|CREATE|INSERT|DELETE|UPDATE|ALTER|USE|LOCK|UNLOCK|START|TRUNCATE)\b)/i';
    if (@preg_match($startPattern, $sql) !== 1) {
        $markers = [
            '-- Hope Baptist',
            '-- Dump',
            '-- MySQL dump',
            '-- Type:',
            'SET NAMES',
            'SET FOREIGN_KEY',
            'DROP TABLE',
            'CREATE TABLE',
            'INSERT INTO',
            'TRUNCATE TABLE',
            'DELETE FROM',
        ];
        $earliest = null;
        foreach ($markers as $marker) {
            $pos = stripos($sql, $marker);
            if ($pos !== false && ($earliest === null || $pos < $earliest)) {
                $earliest = $pos;
            }
        }
        if ($earliest !== null && $earliest > 0) {
            $sql = substr($sql, $earliest);
        }
    }

    return trim($sql);
}

function describeSqlSnippet(string $sql, int $length = 120): string {
    $snippet = preg_replace('/\s+/', ' ', substr($sql, 0, $length)) ?? substr($sql, 0, $length);
    return trim($snippet);
}

/**
 * Validate SQL content. $mode: 'data' | 'full' | 'any'
 *
 * @return array{valid:bool,error:?string,details:array,mode_detected?:string}
 */
function validateSqlBackupContent(string $rawSql, string $cleanedSql, string $mode = 'any'): array {
    $rawSize = strlen($rawSql);
    $cleanedSize = strlen($cleanedSql);

    if ($cleanedSql === '') {
        return [
            'valid' => false,
            'error' => 'Backup file is empty or only contained unexpected output after cleaning.',
            'details' => [
                'raw_bytes' => $rawSize,
                'cleaned_bytes' => 0,
                'preview' => describeSqlSnippet($rawSql),
            ],
        ];
    }

    $rejectPatterns = [
        'html_doctype' => ['pattern' => '/<!DOCTYPE\s+html/i', 'label' => 'HTML document (<!DOCTYPE html>)'],
        'html_page' => ['pattern' => '/<html[\s>]/i', 'label' => 'HTML page markup'],
        'php_tag' => ['pattern' => '/<\?php/i', 'label' => 'PHP source code'],
        'php_warning' => ['pattern' => '/(?:Warning|Notice|Deprecated|Fatal error|Parse error):/i', 'label' => 'PHP error output'],
    ];

    foreach ($rejectPatterns as $key => $rule) {
        if (@preg_match($rule['pattern'], $cleanedSql) === 1) {
            return [
                'valid' => false,
                'error' => 'Backup file contains ' . $rule['label'] . ' and does not look like a SQL dump.',
                'details' => [
                    'raw_bytes' => $rawSize,
                    'cleaned_bytes' => $cleanedSize,
                    'rejected_by' => $key,
                    'preview' => describeSqlSnippet($cleanedSql),
                ],
            ];
        }
    }

    $hasCreate = @preg_match('/\bCREATE\s+TABLE\b/i', $cleanedSql) === 1;
    $hasDrop = @preg_match('/\bDROP\s+TABLE\b/i', $cleanedSql) === 1;
    $hasInsert = @preg_match('/\bINSERT\s+INTO\b/i', $cleanedSql) === 1;
    $hasTruncate = @preg_match('/\bTRUNCATE\s+TABLE\b/i', $cleanedSql) === 1;
    $hasDelete = @preg_match('/\bDELETE\s+FROM\b/i', $cleanedSql) === 1;
    $hasAppHeader = @preg_match('/--\s*Hope Baptist/i', $cleanedSql) === 1;
    $hasDataHeader = @preg_match('/--\s*Type:\s*data-only/i', $cleanedSql) === 1;
    $hasFullHeader = @preg_match('/--\s*Type:\s*full/i', $cleanedSql) === 1;
    $hasSqlComment = @preg_match('/^\s*--/m', $cleanedSql) === 1;
    $hasSetNames = @preg_match('/\bSET\s+NAMES\b/i', $cleanedSql) === 1;

    $looksData = $hasDataHeader || (($hasInsert || $hasTruncate || $hasDelete) && !$hasCreate && !$hasDrop);
    $looksFull = $hasFullHeader || $hasCreate || $hasDrop
        || ($hasAppHeader && $hasCreate)
        || ($hasSqlComment && $hasCreate);

    $detected = $looksData && !$looksFull ? 'data' : ($looksFull ? 'full' : 'unknown');

    if ($mode === 'data') {
        if ($hasCreate || $hasDrop) {
            return [
                'valid' => false,
                'error' => 'This looks like a full schema backup. Use Database Maintenance for full restore, or export a data-only backup.',
                'details' => [
                    'raw_bytes' => $rawSize,
                    'cleaned_bytes' => $cleanedSize,
                    'mode_detected' => 'full',
                ],
                'mode_detected' => 'full',
            ];
        }
        if (!$hasInsert && !$hasTruncate && !$hasDelete) {
            return [
                'valid' => false,
                'error' => 'Data-only SQL backup must contain INSERT, TRUNCATE, or DELETE statements.',
                'details' => [
                    'raw_bytes' => $rawSize,
                    'cleaned_bytes' => $cleanedSize,
                    'preview' => describeSqlSnippet($cleanedSql),
                ],
                'mode_detected' => $detected,
            ];
        }
        return [
            'valid' => true,
            'error' => null,
            'details' => ['raw_bytes' => $rawSize, 'cleaned_bytes' => $cleanedSize],
            'mode_detected' => 'data',
        ];
    }

    if ($mode === 'full') {
        if (!$hasCreate && !$hasDrop && !$hasFullHeader) {
            return [
                'valid' => false,
                'error' => 'Full backup must include CREATE TABLE / DROP TABLE (schema).',
                'details' => [
                    'raw_bytes' => $rawSize,
                    'cleaned_bytes' => $cleanedSize,
                    'preview' => describeSqlSnippet($cleanedSql),
                ],
                'mode_detected' => $detected,
            ];
        }
        return [
            'valid' => true,
            'error' => null,
            'details' => ['raw_bytes' => $rawSize, 'cleaned_bytes' => $cleanedSize],
            'mode_detected' => 'full',
        ];
    }

    // any
    if ($looksData || $looksFull || $hasInsert || $hasSetNames || $hasAppHeader) {
        return [
            'valid' => true,
            'error' => null,
            'details' => ['raw_bytes' => $rawSize, 'cleaned_bytes' => $cleanedSize],
            'mode_detected' => $detected,
        ];
    }

    return [
        'valid' => false,
        'error' => 'Backup file does not look like a valid SQL dump.',
        'details' => [
            'raw_bytes' => $rawSize,
            'cleaned_bytes' => $cleanedSize,
            'preview' => describeSqlSnippet($cleanedSql),
            'hint' => 'Expected SQL comments, INSERT, TRUNCATE, CREATE TABLE, or a Hope Baptist header.',
        ],
        'mode_detected' => 'unknown',
    ];
}

function formatSqlValidationError(array $validation): string {
    $message = $validation['error'] ?? 'Backup file failed validation.';
    $details = $validation['details'] ?? [];

    $parts = [$message];
    if (!empty($details['preview'])) {
        $parts[] = 'File starts with: "' . $details['preview'] . '"';
    }
    if (isset($details['raw_bytes'])) {
        $parts[] = 'Size: ' . number_format((int)$details['raw_bytes']) . ' bytes';
    }
    if (!empty($details['hint'])) {
        $parts[] = $details['hint'];
    }

    return implode(' ', $parts);
}

function inspectSqlBackupIntegrity(string $rawSql, string $expectedMode = 'any'): array {
    $issues = [];
    $cleaned = sanitizeSqlBackupContent($rawSql);

    if ($cleaned === '') {
        return [
            'valid' => false,
            'issues' => ['Backup file is empty or unreadable'],
            'summary' => 'Backup file is empty or unreadable',
            'stats' => [
                'create_table' => 0,
                'drop_table' => 0,
                'insert_into' => 0,
                'truncate_table' => 0,
                'bytes' => strlen($rawSql),
            ],
            'mode' => 'unknown',
        ];
    }

    $contaminationPatterns = [
        'PHP error output' => '/(?:^|\n)\s*(?:Warning|Notice|Deprecated|Fatal error|Parse error):/im',
        'HTML markup' => '/<(?:!DOCTYPE|html|head|body|script)\b/i',
        'Stray <br> tag' => '/<br\s*\/?>/i',
        'PHP source code' => '/<\?php/i',
    ];

    foreach ($contaminationPatterns as $label => $pattern) {
        if (@preg_match($pattern, $rawSql) === 1) {
            $issues[] = $label;
        }
    }

    $validation = validateSqlBackupContent($rawSql, $cleaned, $expectedMode);
    if (!$validation['valid']) {
        $issues[] = $validation['error'] ?? 'Backup failed SQL structure validation';
    }

    $createCount = (int)(@preg_match_all('/\bCREATE\s+TABLE\b/i', $cleaned) ?: 0);
    $dropCount = (int)(@preg_match_all('/\bDROP\s+TABLE\b/i', $cleaned) ?: 0);
    $insertCount = (int)(@preg_match_all('/\bINSERT\s+INTO\b/i', $cleaned) ?: 0);
    $truncateCount = (int)(@preg_match_all('/\bTRUNCATE\s+TABLE\b/i', $cleaned) ?: 0);

    $mode = $validation['mode_detected'] ?? 'unknown';
    if ($mode === 'unknown') {
        $mode = ($createCount > 0 || $dropCount > 0) ? 'full' : 'data';
    }

    if ($mode === 'full' || $expectedMode === 'full') {
        if ($createCount === 0) {
            $issues[] = 'No CREATE TABLE statements found';
        }
        if ($dropCount > 0 && $createCount > 0 && $dropCount !== $createCount) {
            $issues[] = 'DROP TABLE count (' . $dropCount . ') does not match CREATE TABLE count (' . $createCount . ')';
        }
    } else {
        // data-only: create not expected
        if ($createCount > 0 || $dropCount > 0) {
            $issues[] = 'Data-only backup should not contain CREATE/DROP TABLE';
        }
        if ($insertCount === 0 && $truncateCount === 0) {
            $issues[] = 'No INSERT or TRUNCATE statements found';
        }
    }

    $trimmed = rtrim($cleaned);
    if ($trimmed !== '' && !str_ends_with($trimmed, ';')) {
        $issues[] = 'File may end with an incomplete SQL statement';
    }

    $issues = array_values(array_unique($issues));
    $valid = count($issues) === 0;

    if ($valid) {
        if ($mode === 'full') {
            $summary = 'Verified — ' . $createCount . ' table' . ($createCount === 1 ? '' : 's')
                . ', ' . number_format($insertCount) . ' INSERT' . ($insertCount === 1 ? '' : 's');
        } else {
            $summary = 'Verified data-only — '
                . number_format($truncateCount) . ' TRUNCATE'
                . ', ' . number_format($insertCount) . ' INSERT' . ($insertCount === 1 ? '' : 's');
        }
    } else {
        $summary = implode('; ', array_slice($issues, 0, 4));
        if (count($issues) > 4) {
            $summary .= '; +' . (count($issues) - 4) . ' more';
        }
    }

    return [
        'valid' => $valid,
        'issues' => $issues,
        'summary' => $summary,
        'stats' => [
            'create_table' => $createCount,
            'drop_table' => $dropCount,
            'insert_into' => $insertCount,
            'truncate_table' => $truncateCount,
            'bytes' => strlen($rawSql),
        ],
        'mode' => $mode,
    ];
}

function inspectCsvZipBackupIntegrity(string $path): array {
    if (!class_exists('ZipArchive')) {
        return [
            'valid' => false,
            'issues' => ['ZipArchive not available'],
            'summary' => 'ZipArchive not available',
            'stats' => ['bytes' => is_file($path) ? (int)@filesize($path) : 0],
            'mode' => 'data_csv',
        ];
    }
    if (!is_file($path) || !is_readable($path)) {
        return [
            'valid' => false,
            'issues' => ['CSV backup file not found'],
            'summary' => 'CSV backup file not found',
            'stats' => [],
            'mode' => 'data_csv',
        ];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [
            'valid' => false,
            'issues' => ['Not a valid zip archive'],
            'summary' => 'Not a valid zip archive',
            'stats' => ['bytes' => (int)@filesize($path)],
            'mode' => 'data_csv',
        ];
    }

    $csvCount = 0;
    $hasManifest = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!is_string($name)) {
            continue;
        }
        $base = basename($name);
        if (strtolower($base) === 'manifest.json') {
            $hasManifest = true;
        }
        if (str_ends_with(strtolower($base), '.csv')) {
            $csvCount++;
        }
    }
    $zip->close();

    $issues = [];
    if ($csvCount === 0) {
        $issues[] = 'Zip contains no CSV table files';
    }

    $valid = $issues === [];
    $summary = $valid
        ? 'Verified CSV zip — ' . $csvCount . ' table file' . ($csvCount === 1 ? '' : 's')
            . ($hasManifest ? ' + manifest' : '')
        : implode('; ', $issues);

    return [
        'valid' => $valid,
        'issues' => $issues,
        'summary' => $summary,
        'stats' => [
            'csv_files' => $csvCount,
            'has_manifest' => $hasManifest,
            'bytes' => (int)@filesize($path),
        ],
        'mode' => 'data_csv',
    ];
}

function inspectBackupFile(string $backupDir, string $filename, bool $ensureChecksum = true): array {
    $failed = static fn(string $message): array => [
        'valid' => false,
        'issues' => [$message],
        'summary' => $message,
        'stats' => [],
        'checksum' => null,
        'mode' => 'unknown',
    ];

    if (safeBackupFilename($filename) === null) {
        return $failed('Invalid backup filename');
    }

    $path = rtrim($backupDir, '/\\') . '/' . $filename;
    if (!is_file($path) || !is_readable($path)) {
        return $failed('Backup file not found');
    }

    $kind = backupFileKind($filename);
    if ($kind === 'data_csv' || $kind === 'restored_csv') {
        $integrity = inspectCsvZipBackupIntegrity($path);
    } else {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return $failed('Could not read backup file');
        }
        $expected = ($kind === 'data_sql' || $kind === 'restored_sql')
            ? 'data'
            : (($kind === 'full' || $kind === 'legacy_full') ? 'full' : 'any');
        $integrity = inspectSqlBackupIntegrity($raw, $expected);
    }

    $checksum = readBackupChecksum($backupDir, $filename);
    if ($checksum === null && $ensureChecksum) {
        $checksumWrite = writeBackupChecksumFile($backupDir, $filename);
        if (is_array($checksumWrite) && !empty($checksumWrite['success'])) {
            $candidate = $checksumWrite['checksum'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                $checksum = $candidate;
            }
        }
    }
    if ($checksum === null) {
        $computed = computeFileSha256($path);
        $checksum = $computed !== false ? $computed : null;
    }

    $integrity['checksum'] = $checksum;
    return $integrity;
}

/**
 * @return list<array{
 *   name:string,size:int,modified:int,display_datetime:string,
 *   kind:?string,kind_label:string,checksum:?string,integrity:array
 * }>
 */
function listBackupFiles(string $dir, bool $withIntegrity = true, ?string $filter = null): array {
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }

    pruneOrphanedChecksumFiles($dir);

    $dir = rtrim($dir, '/\\');
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return $files;
    }

    foreach ($entries as $name) {
        if (!is_string($name) || $name === '.' || $name === '..') {
            continue;
        }
        if (str_starts_with($name, '.') || str_ends_with(strtolower($name), '.sha256')) {
            continue;
        }
        if (safeBackupFilename($name) === null) {
            continue;
        }
        $path = $dir . '/' . $name;
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $kind = backupFileKind($name);
        if ($filter === 'data' && !backupIsDataOnly($name)) {
            continue;
        }
        if ($filter === 'full' && !backupIsFullSchema($name)) {
            continue;
        }

        $size = @filesize($path);
        $modified = @filemtime($path);
        if ($size === false || $modified === false) {
            continue;
        }

        $filenameTimestamp = parseBackupFilenameTimestamp($name);

        $entry = [
            'name' => $name,
            'size' => (int)$size,
            'modified' => $filenameTimestamp ?? (int)$modified,
            'display_datetime' => formatBackupFilenameDatetime($name) ?? 'Unknown',
            'kind' => $kind,
            'kind_label' => backupKindLabel($name),
            'checksum' => null,
            'integrity' => [
                'valid' => false,
                'summary' => 'Integrity check unavailable',
                'issues' => [],
                'stats' => [],
            ],
        ];

        if ($withIntegrity) {
            $inspection = inspectBackupFile($dir, $name);
            if (is_array($inspection)) {
                $checksum = $inspection['checksum'] ?? null;
                $entry['checksum'] = is_string($checksum) && $checksum !== '' ? $checksum : null;
                $entry['integrity'] = [
                    'valid' => (bool)($inspection['valid'] ?? false),
                    'summary' => (string)($inspection['summary'] ?? 'Integrity check unavailable'),
                    'issues' => is_array($inspection['issues'] ?? null) ? $inspection['issues'] : [],
                    'stats' => is_array($inspection['stats'] ?? null) ? $inspection['stats'] : [],
                ];
            }
        }

        $files[] = $entry;
    }

    usort($files, static fn($a, $b) => ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0));
    return $files;
}

function executeSqlMultiQuery(mysqli $db, string $sql): array {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $restoreOk = false;
    $error = null;

    if ($db->multi_query($sql)) {
        $restoreOk = true;
        do {
            if ($result = $db->store_result()) {
                $result->free();
            }
            if ($db->errno) {
                $restoreOk = false;
                $error = $db->error;
                break;
            }
        } while ($db->more_results() && $db->next_result());
        if ($restoreOk && $db->errno) {
            $restoreOk = false;
            $error = $db->error;
        }
    } else {
        $error = $db->error;
    }

    $db->query('SET FOREIGN_KEY_CHECKS = 1');

    return [
        'success' => $restoreOk,
        'error' => $error,
    ];
}

/**
 * Restore data-only SQL (no schema changes expected).
 *
 * @return array{success:bool,error?:string}
 */
function restoreDataOnlySql(mysqli $db, string $rawSql): array {
    $sql = sanitizeSqlBackupContent($rawSql);
    $validation = validateSqlBackupContent($rawSql, $sql, 'data');
    if (!$validation['valid']) {
        return ['success' => false, 'error' => formatSqlValidationError($validation)];
    }

    $sql = stripExcludedTablesFromDataOnlySql($sql);

    $result = executeSqlMultiQuery($db, $sql);
    if (!$result['success']) {
        return ['success' => false, 'error' => 'Restore failed: ' . ($result['error'] ?? $db->error)];
    }
    return ['success' => true];
}

/**
 * Restore full schema+data SQL.
 *
 * @return array{success:bool,error?:string}
 */
function restoreFullSql(mysqli $db, string $rawSql): array {
    $sql = sanitizeSqlBackupContent($rawSql);
    $validation = validateSqlBackupContent($rawSql, $sql, 'full');
    if (!$validation['valid']) {
        // Allow legacy dumps that validate as "any"
        $any = validateSqlBackupContent($rawSql, $sql, 'any');
        if (!$any['valid']) {
            return ['success' => false, 'error' => formatSqlValidationError($validation)];
        }
    }

    $result = executeSqlMultiQuery($db, $sql);
    if (!$result['success']) {
        return ['success' => false, 'error' => 'Restore failed: ' . ($result['error'] ?? $db->error)];
    }
    return ['success' => true];
}

/**
 * Restore CSV zip data-only backup into existing tables.
 *
 * @return array{success:bool,error?:string,tables?:list<string>}
 */
function restoreDataOnlyCsvZip(mysqli $db, string $zipPath): array {
    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'error' => 'PHP ZipArchive extension is required for CSV restore.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['success' => false, 'error' => 'Could not open CSV backup zip.'];
    }

    $existingTables = listDatabaseTables($db);
    $existingLookup = array_fill_keys($existingTables, true);

    // Map entry basename (lower) => content
    $csvEntries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!is_string($name) || str_ends_with($name, '/')) {
            continue;
        }
        $base = basename($name);
        if (!str_ends_with(strtolower($base), '.csv')) {
            continue;
        }
        $content = $zip->getFromIndex($i);
        if (!is_string($content)) {
            continue;
        }
        $csvEntries[strtolower($base)] = $content;
    }
    $zip->close();

    if ($csvEntries === []) {
        return ['success' => false, 'error' => 'CSV zip contains no table CSV files.'];
    }

    $restored = [];
    $db->query('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($existingTables as $table) {
        if (isDataOnlyExcludedTable($table)) {
            continue;
        }
        $entryKey = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $table) . '.csv');
        if (!isset($csvEntries[$entryKey])) {
            // also try exact table name
            $entryKey = strtolower($table . '.csv');
        }
        if (!isset($csvEntries[$entryKey])) {
            continue;
        }

        $safeTable = str_replace('`', '``', $table);
        if (!$db->query("TRUNCATE TABLE `$safeTable`")) {
            $db->query("DELETE FROM `$safeTable`");
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
            return ['success' => false, 'error' => 'Could not allocate CSV stream for ' . $table];
        }
        fwrite($stream, $csvEntries[$entryKey]);
        rewind($stream);

        $header = fgetcsv($stream, 0, ',', '"', '\\');
        if (!is_array($header) || $header === [] || $header === [null]) {
            fclose($stream);
            continue;
        }
        $header = array_map(static fn($h) => trim((string)$h), $header);
        $header = array_values(array_filter($header, static fn($h) => $h !== ''));
        if ($header === []) {
            fclose($stream);
            continue;
        }

        $colList = '`' . implode('`, `', array_map(
            static fn($c) => str_replace('`', '``', $c),
            $header
        )) . '`';
        $placeholders = implode(', ', array_fill(0, count($header), '?'));
        $types = str_repeat('s', count($header));
        $stmt = $db->prepare("INSERT INTO `$safeTable` ($colList) VALUES ($placeholders)");
        if (!$stmt) {
            fclose($stream);
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
            return ['success' => false, 'error' => 'Prepare failed for ' . $table . ': ' . $db->error];
        }

        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            // Pad / trim to header length
            $values = [];
            foreach ($header as $idx => $_col) {
                $values[] = array_key_exists($idx, $row) ? (string)$row[$idx] : '';
            }
            // Treat empty strings as empty (not NULL) — acceptable for restore
            $stmt->bind_param($types, ...$values);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                fclose($stream);
                $db->query('SET FOREIGN_KEY_CHECKS = 1');
                return ['success' => false, 'error' => 'Insert failed for ' . $table . ': ' . $err];
            }
        }
        $stmt->close();
        fclose($stream);
        $restored[] = $table;
    }

    $db->query('SET FOREIGN_KEY_CHECKS = 1');

    if ($restored === []) {
        return ['success' => false, 'error' => 'No matching tables found in CSV zip for this database.'];
    }

    return ['success' => true, 'tables' => $restored];
}

function archiveRestoredBackup(string $uploadName, string $contents, string $ext = 'sql'): array {
    $dirInfo = ensureStorageSubdir('backups');
    if ($dirInfo['error'] !== null) {
        return ['success' => false, 'error' => $dirInfo['error']];
    }

    $safeName = @preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($uploadName)) ?? ('upload.' . $ext);
    if ($safeName === '' || $safeName === '.' . $ext) {
        $safeName = 'upload.' . $ext;
    }
    $archiveName = 'restored_' . date('Y-m-d_His') . '_' . $safeName;
    $path = rtrim($dirInfo['path'], '/\\') . '/' . $archiveName;

    $write = writeStorageFile($path, $contents);
    if (!$write['success']) {
        return ['success' => false, 'error' => $write['error'], 'filename' => $archiveName];
    }

    return ['success' => true, 'filename' => $archiveName, 'path' => $path, 'size' => $write['bytes']];
}

// ── Session file-lock helpers (delete safety) ───────────────────────────────

function getUnlockedBackups(): array {
    if (!isset($_SESSION['unlocked_backups']) || !is_array($_SESSION['unlocked_backups'])) {
        $_SESSION['unlocked_backups'] = [];
    }
    return $_SESSION['unlocked_backups'];
}

function isBackupUnlocked(string $filename): bool {
    return in_array($filename, getUnlockedBackups(), true);
}

function unlockBackup(string $filename): void {
    $unlocked = getUnlockedBackups();
    if (!in_array($filename, $unlocked, true)) {
        $unlocked[] = $filename;
        $_SESSION['unlocked_backups'] = $unlocked;
    }
}

function lockBackup(string $filename): void {
    $_SESSION['unlocked_backups'] = array_values(array_filter(
        getUnlockedBackups(),
        static fn($f) => $f !== $filename
    ));
}

function pruneUnlockedBackups(string $backupDir): void {
    $dir = rtrim($backupDir, '/\\');
    $_SESSION['unlocked_backups'] = array_values(array_filter(
        getUnlockedBackups(),
        static function ($filename) use ($dir): bool {
            if (!is_string($filename) || safeBackupFilename($filename) === null) {
                return false;
            }
            return is_file($dir . '/' . $filename);
        }
    ));
}

// ── Auto-backup ─────────────────────────────────────────────────────────────

function getAutoBackupStatePath(): string {
    return rtrim(getBackupDir(), '/\\') . '/' . TEMPER_AUTO_BACKUP_STATE_FILE;
}

/**
 * @return array{last_run:?string,last_status:?string,last_files:list<string>,last_error:?string}
 */
function loadAutoBackupState(): array {
    $defaults = [
        'last_run' => null,
        'last_status' => null,
        'last_files' => [],
        'last_error' => null,
    ];
    $path = getAutoBackupStatePath();
    if (!is_file($path) || !is_readable($path)) {
        return $defaults;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    return [
        'last_run' => isset($decoded['last_run']) && is_string($decoded['last_run']) ? $decoded['last_run'] : null,
        'last_status' => isset($decoded['last_status']) && is_string($decoded['last_status']) ? $decoded['last_status'] : null,
        'last_files' => is_array($decoded['last_files'] ?? null)
            ? array_values(array_filter($decoded['last_files'], 'is_string'))
            : [],
        'last_error' => isset($decoded['last_error']) && is_string($decoded['last_error']) ? $decoded['last_error'] : null,
    ];
}

function saveAutoBackupState(array $state): void {
    $payload = [
        'last_run' => $state['last_run'] ?? null,
        'last_status' => $state['last_status'] ?? null,
        'last_files' => $state['last_files'] ?? [],
        'last_error' => $state['last_error'] ?? null,
        'updated_at' => gmdate('c'),
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    @writeStorageFile(getAutoBackupStatePath(), $json . "\n");
}

/**
 * Seconds between auto-backups for a frequency key.
 */
function autoBackupFrequencySeconds(string $frequency): int {
    return match (strtolower(trim($frequency))) {
        'hourly' => 3600,
        'daily' => 86400,
        'weekly' => 604800,
        default => 86400,
    };
}

function isAutoBackupEnabled(): bool {
    if (!function_exists('getSystemConfig')) {
        require_once __DIR__ . '/system_config.php';
    }
    return (bool)getSystemConfig('auto_backup_enabled', false);
}

function getAutoBackupFrequency(): string {
    if (!function_exists('getSystemConfig')) {
        require_once __DIR__ . '/system_config.php';
    }
    $freq = strtolower((string)getSystemConfig('auto_backup_frequency', 'daily'));
    if (!in_array($freq, ['hourly', 'daily', 'weekly'], true)) {
        $freq = 'daily';
    }
    return $freq;
}

function getAutoBackupFormat(): string {
    if (!function_exists('getSystemConfig')) {
        require_once __DIR__ . '/system_config.php';
    }
    $format = strtolower((string)getSystemConfig('auto_backup_format', 'sql'));
    if (!in_array($format, ['sql', 'csv', 'both'], true)) {
        $format = 'sql';
    }
    return $format;
}

function autoBackupIsDue(): bool {
    if (!isAutoBackupEnabled()) {
        return false;
    }
    $state = loadAutoBackupState();
    $last = $state['last_run'];
    if ($last === null || $last === '') {
        return true;
    }
    $lastTs = strtotime($last);
    if ($lastTs === false) {
        return true;
    }
    $interval = autoBackupFrequencySeconds(getAutoBackupFrequency());
    return (time() - $lastTs) >= $interval;
}

/**
 * Run auto data-only backup if enabled and due (or force).
 *
 * @return array{ran:bool,success?:bool,skipped?:bool,reason?:string,error?:string,files?:list,message?:string}
 */
function maybeRunAutoBackup(mysqli $db, bool $force = false): array {
    if (!$force && !isAutoBackupEnabled()) {
        return ['ran' => false, 'skipped' => true, 'reason' => 'disabled'];
    }
    if (!$force && !autoBackupIsDue()) {
        return ['ran' => false, 'skipped' => true, 'reason' => 'not_due'];
    }

    // Simple lock to avoid concurrent auto-backups
    $lockPath = rtrim(getBackupDir(), '/\\') . '/.auto_backup.lock';
    $lockFp = @fopen($lockPath, 'c+');
    if ($lockFp === false) {
        return ['ran' => false, 'success' => false, 'error' => 'Could not open auto-backup lock file.'];
    }
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        return ['ran' => false, 'skipped' => true, 'reason' => 'locked'];
    }

    try {
        // Re-check due under lock
        if (!$force && !autoBackupIsDue()) {
            return ['ran' => false, 'skipped' => true, 'reason' => 'not_due'];
        }

        $format = getAutoBackupFormat();
        $result = createDataOnlyBackup($db, $format);
        $now = gmdate('c');

        if (!$result['success']) {
            saveAutoBackupState([
                'last_run' => $now,
                'last_status' => 'error',
                'last_files' => [],
                'last_error' => $result['error'] ?? 'Auto-backup failed',
            ]);
            return [
                'ran' => true,
                'success' => false,
                'error' => $result['error'] ?? 'Auto-backup failed',
            ];
        }

        $fileNames = array_map(static fn($f) => $f['file'], $result['files'] ?? []);
        saveAutoBackupState([
            'last_run' => $now,
            'last_status' => 'ok',
            'last_files' => $fileNames,
            'last_error' => null,
        ]);

        return [
            'ran' => true,
            'success' => true,
            'files' => $result['files'] ?? [],
            'message' => $result['message'] ?? 'Auto-backup completed.',
        ];
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}

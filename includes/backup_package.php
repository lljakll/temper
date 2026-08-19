<?php
/**
 * Backup packages — zip archives that combine a database dump with
 * selected storage files (attachments, system config, legacy documents).
 *
 * Loaded from backup_utils.php. Do not include directly.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

/** Root entry name for the SQL dump inside a package zip. */
const TEMPER_BACKUP_PACKAGE_SQL = 'database.sql';

/** Manifest entry name inside a package zip. */
const TEMPER_BACKUP_PACKAGE_MANIFEST = 'manifest.json';

/** Prefix for user-data files inside a package zip. */
const TEMPER_BACKUP_PACKAGE_STORAGE_PREFIX = 'storage/';

/** Package format version written to manifest.json. */
const TEMPER_BACKUP_PACKAGE_VERSION = 1;

/**
 * Storage subdirectories included in data-only and full backup packages.
 * backups/, logs/, exports/, and working/ are intentionally omitted.
 *
 * @return list<string>
 */
function backupIncludedStorageSubdirs(): array {
    return ['attachments', 'config', 'transaction_documents'];
}

function isBackupIncludedStorageSubdir(string $name): bool {
    return in_array($name, backupIncludedStorageSubdirs(), true);
}

function backupDataPackageFilename(string $token): string {
    return 'backup_data_' . $token . '.zip';
}

function backupFullPackageFilename(string $token): string {
    return 'backup_full_' . $token . '.zip';
}

function backupRestoreMaxUploadBytes(): int {
    return 256 * 1024 * 1024;
}

function backupNormalizeZipEntryName(string $name): string {
    $name = str_replace('\\', '/', $name);
    return ltrim($name, '/');
}

/**
 * Map a zip entry to a safe storage relative path, or null if not allowed.
 *
 * @return array{subdir:string,relative:string,entry:string}|null
 */
function backupSafeStorageZipRelative(string $entryName): ?array {
    $name = backupNormalizeZipEntryName($entryName);
    if ($name === '' || str_contains($name, '..')) {
        return null;
    }
    if (!str_starts_with($name, TEMPER_BACKUP_PACKAGE_STORAGE_PREFIX)) {
        return null;
    }
    $rest = substr($name, strlen(TEMPER_BACKUP_PACKAGE_STORAGE_PREFIX));
    if ($rest === '' || str_ends_with($rest, '/')) {
        return null;
    }
    $parts = explode('/', $rest);
    $subdir = $parts[0] ?? '';
    if (!isBackupIncludedStorageSubdir($subdir) || count($parts) < 2) {
        return null;
    }
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return null;
        }
    }
    return [
        'subdir' => $subdir,
        'relative' => implode('/', array_slice($parts, 1)),
        'entry' => $name,
    ];
}

/**
 * Collect live storage files to include in a backup package.
 *
 * @return array{files:array<string,string>,dirs:list<string>}
 */
function collectBackupStorageFileMap(): array {
    $root = rtrim(getStoragePath(), '/\\');
    $files = [];
    $dirs = [];

    foreach (backupIncludedStorageSubdirs() as $subdir) {
        $absDir = $root . '/' . $subdir;
        if (!is_dir($absDir)) {
            continue;
        }
        $dirs[] = $subdir;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $absDir,
                FilesystemIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile() || $fileInfo->isLink()) {
                continue;
            }
            $base = $fileInfo->getFilename();
            if (str_starts_with($base, '.')) {
                continue;
            }
            $full = $fileInfo->getPathname();
            $rel = substr($full, strlen($absDir) + 1);
            $rel = str_replace('\\', '/', (string)$rel);
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $files[TEMPER_BACKUP_PACKAGE_STORAGE_PREFIX . $subdir . '/' . $rel] = $full;
        }
    }

    return ['files' => $files, 'dirs' => $dirs];
}

/**
 * @return array{success:bool,error?:string,files:int,dirs:list<string>}
 */
function addStorageFilesToZip(ZipArchive $zip): array {
    $collected = collectBackupStorageFileMap();
    foreach ($collected['dirs'] as $dir) {
        $zip->addEmptyDir(TEMPER_BACKUP_PACKAGE_STORAGE_PREFIX . $dir);
    }
    $added = 0;
    foreach ($collected['files'] as $zipPath => $abs) {
        if (!is_file($abs) || !is_readable($abs)) {
            return [
                'success' => false,
                'error' => 'Storage file is not readable: ' . $zipPath,
                'files' => $added,
                'dirs' => $collected['dirs'],
            ];
        }
        if (!@$zip->addFile($abs, $zipPath)) {
            return [
                'success' => false,
                'error' => 'Could not add storage file to backup: ' . $zipPath,
                'files' => $added,
                'dirs' => $collected['dirs'],
            ];
        }
        $added++;
    }
    return ['success' => true, 'files' => $added, 'dirs' => $collected['dirs']];
}

function removeDirectoryTree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo) {
            continue;
        }
        $path = $fileInfo->getPathname();
        if ($fileInfo->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Replace the contents of $destDir with the contents of $srcDir.
 *
 * @return array{success:bool,error?:string,files?:int}
 */
function replaceDirectoryContents(string $destDir, string $srcDir): array {
    if (!is_dir($srcDir)) {
        return ['success' => false, 'error' => 'Extracted directory is missing.'];
    }
    if (!is_dir($destDir) && !@mkdir($destDir, 0775, true)) {
        return ['success' => false, 'error' => 'Could not create directory ' . $destDir];
    }

    $existing = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($destDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($existing as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo) {
            continue;
        }
        $path = $fileInfo->getPathname();
        if ($fileInfo->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    $copied = 0;
    $srcIt = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );
    $srcPrefix = rtrim($srcDir, '/\\') . DIRECTORY_SEPARATOR;
    foreach ($srcIt as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }
        $rel = substr($fileInfo->getPathname(), strlen($srcPrefix));
        $rel = str_replace('\\', '/', (string)$rel);
        if ($rel === '' || str_contains($rel, '..')) {
            continue;
        }
        $to = rtrim($destDir, '/\\') . '/' . $rel;
        $toDir = dirname($to);
        if (!is_dir($toDir) && !@mkdir($toDir, 0775, true)) {
            return ['success' => false, 'error' => 'Could not create directory ' . $toDir];
        }
        if (!@copy($fileInfo->getPathname(), $to)) {
            return ['success' => false, 'error' => 'Could not copy restored file ' . $rel];
        }
        $copied++;
    }

    return ['success' => true, 'files' => $copied];
}

function writeBackupZipEntryToFile(ZipArchive $zip, int $index, string $destFile): bool {
    $name = $zip->getNameIndex($index);
    if (!is_string($name)) {
        return false;
    }
    $stream = $zip->getStream($name);
    if (is_resource($stream)) {
        $out = @fopen($destFile, 'wb');
        if ($out === false) {
            fclose($stream);
            return false;
        }
        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);
        return is_file($destFile);
    }
    $data = $zip->getFromIndex($index);
    return is_string($data) && @file_put_contents($destFile, $data) !== false;
}

/**
 * Restore storage/* entries from an open package zip into the live storage root
 * (or $targetRoot when provided, for tests).
 *
 * @param list<string> $forceDirs Subdirs to replace even when the zip has no files in them.
 * @return array{success:bool,error?:string,restored?:int,dirs?:list<string>,skipped?:bool}
 */
function restoreStorageFromZip(ZipArchive $zip, ?string $targetRoot = null, array $forceDirs = []): array {
    $entriesBySubdir = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!is_string($name)) {
            continue;
        }
        $safe = backupSafeStorageZipRelative($name);
        if ($safe === null) {
            continue;
        }
        $entriesBySubdir[$safe['subdir']][] = [
            'index' => $i,
            'relative' => $safe['relative'],
        ];
    }

    $dirsToRestore = array_unique(array_merge(array_keys($entriesBySubdir), $forceDirs));
    $dirsToRestore = array_values(array_filter(
        $dirsToRestore,
        static fn($d) => is_string($d) && isBackupIncludedStorageSubdir($d)
    ));

    if ($dirsToRestore === []) {
        return ['success' => true, 'restored' => 0, 'dirs' => [], 'skipped' => true];
    }

    $tmp = rtrim(sys_get_temp_dir(), '/\\') . '/temper_restore_storage_' . getmypid() . '_' . bin2hex(random_bytes(4));
    if (!@mkdir($tmp, 0775, true)) {
        return ['success' => false, 'error' => 'Could not create temporary directory for storage restore.'];
    }

    try {
        $restored = 0;
        $dirs = [];
        foreach ($dirsToRestore as $subdir) {
            $extractDir = $tmp . '/' . $subdir;
            if (!@mkdir($extractDir, 0775, true) && !is_dir($extractDir)) {
                return ['success' => false, 'error' => 'Could not prepare extract directory for ' . $subdir];
            }
            foreach ($entriesBySubdir[$subdir] ?? [] as $entry) {
                $destFile = $extractDir . '/' . $entry['relative'];
                $destDir = dirname($destFile);
                if (!is_dir($destDir) && !@mkdir($destDir, 0775, true)) {
                    return ['success' => false, 'error' => 'Could not create directory for ' . $subdir . '/' . $entry['relative']];
                }
                if (!writeBackupZipEntryToFile($zip, (int)$entry['index'], $destFile)) {
                    return ['success' => false, 'error' => 'Could not extract ' . $subdir . '/' . $entry['relative']];
                }
            }

            if ($targetRoot === null) {
                $liveInfo = ensureStorageSubdir($subdir);
                if ($liveInfo['error'] !== null) {
                    return ['success' => false, 'error' => 'Storage directory is not writable: ' . $subdir];
                }
                $liveDir = $liveInfo['path'];
            } else {
                $liveDir = rtrim($targetRoot, '/\\') . '/' . $subdir;
            }

            $replaced = replaceDirectoryContents($liveDir, $extractDir);
            if (!$replaced['success']) {
                return $replaced;
            }
            $restored += (int)($replaced['files'] ?? 0);
            $dirs[] = $subdir;
        }

        return ['success' => true, 'restored' => $restored, 'dirs' => $dirs];
    } finally {
        removeDirectoryTree($tmp);
    }
}

/**
 * Lightweight inventory of a backup zip (legacy CSV or new package).
 *
 * @return array{
 *   valid_zip:bool,
 *   has_sql:bool,
 *   has_csv:bool,
 *   has_storage:bool,
 *   has_manifest:bool,
 *   csv_count:int,
 *   storage_files:int,
 *   storage_dirs:list<string>,
 *   manifest:?array,
 *   type:?string,
 *   format:?string,
 *   package_version:?int
 * }
 */
function peekBackupPackage(string $path): array {
    $out = [
        'valid_zip' => false,
        'has_sql' => false,
        'has_csv' => false,
        'has_storage' => false,
        'has_manifest' => false,
        'csv_count' => 0,
        'storage_files' => 0,
        'storage_dirs' => [],
        'manifest' => null,
        'type' => null,
        'format' => null,
        'package_version' => null,
    ];

    if (!class_exists('ZipArchive') || !is_file($path) || !is_readable($path)) {
        return $out;
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return $out;
    }

    $out['valid_zip'] = true;
    $dirs = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!is_string($name)) {
            continue;
        }
        $norm = backupNormalizeZipEntryName($name);
        $base = basename($norm);

        if (strcasecmp($base, TEMPER_BACKUP_PACKAGE_MANIFEST) === 0) {
            $out['has_manifest'] = true;
            $raw = $zip->getFromIndex($i);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $out['manifest'] = $decoded;
                    $out['type'] = isset($decoded['type']) && is_string($decoded['type']) ? $decoded['type'] : null;
                    $out['format'] = isset($decoded['format']) && is_string($decoded['format']) ? $decoded['format'] : null;
                    $out['package_version'] = isset($decoded['package_version']) ? (int)$decoded['package_version'] : null;
                    if (is_array($decoded['storage_dirs'] ?? null)) {
                        foreach ($decoded['storage_dirs'] as $dir) {
                            if (is_string($dir) && isBackupIncludedStorageSubdir($dir)) {
                                $dirs[$dir] = true;
                            }
                        }
                    }
                }
            }
        }

        if (strcasecmp($norm, TEMPER_BACKUP_PACKAGE_SQL) === 0 || strcasecmp($base, TEMPER_BACKUP_PACKAGE_SQL) === 0) {
            $out['has_sql'] = true;
        }

        if (str_ends_with(strtolower($base), '.csv') && !str_ends_with($norm, '/')) {
            $out['has_csv'] = true;
            $out['csv_count']++;
        }

        $safe = backupSafeStorageZipRelative($norm);
        if ($safe !== null) {
            $out['has_storage'] = true;
            $out['storage_files']++;
            $dirs[$safe['subdir']] = true;
        } elseif (preg_match('#^storage/(attachments|config|transaction_documents)/?$#', $norm) === 1) {
            $dir = basename(rtrim($norm, '/'));
            if (isBackupIncludedStorageSubdir($dir)) {
                $dirs[$dir] = true;
            }
        }
    }

    $zip->close();
    $out['storage_dirs'] = array_keys($dirs);
    $out['has_storage'] = $out['has_storage'] || $out['storage_dirs'] !== [];

    if ($out['type'] === null) {
        if ($out['has_sql'] || $out['has_storage']) {
            $out['type'] = 'package';
        } elseif ($out['has_csv']) {
            $out['type'] = 'data-only';
            $out['format'] = $out['format'] ?? 'csv-zip';
        }
    }

    return $out;
}

function readBackupZipEntry(string $zipPath, string $entryName): string|false {
    if (!class_exists('ZipArchive')) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return false;
    }
    $data = $zip->getFromName($entryName);
    if ($data === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && strcasecmp(basename(backupNormalizeZipEntryName($name)), $entryName) === 0) {
                $data = $zip->getFromIndex($i);
                break;
            }
        }
    }
    $zip->close();
    return is_string($data) ? $data : false;
}

/**
 * Human label for a zip backup based on peek/inspect stats.
 */
function backupPackageKindLabelFromPeek(array $peek, string $fallback = 'Data (CSV)'): string {
    $type = (string)($peek['type'] ?? '');
    $hasSql = !empty($peek['has_sql']);
    $hasCsv = !empty($peek['has_csv']);
    $hasStorage = !empty($peek['has_storage']);
    $isFull = $type === 'full' || str_starts_with($type, 'full');

    if ($isFull) {
        return $hasStorage ? 'Full (schema + data + files)' : 'Full (schema + data)';
    }
    if ($hasSql && $hasCsv) {
        return $hasStorage ? 'Data (SQL + CSV + files)' : 'Data (SQL + CSV)';
    }
    if ($hasSql) {
        return $hasStorage ? 'Data (SQL + files)' : 'Data (SQL)';
    }
    if ($hasCsv) {
        return $hasStorage ? 'Data (CSV + files)' : 'Data (CSV)';
    }
    if ($hasStorage) {
        return 'Data (files)';
    }
    return $fallback;
}

/**
 * Add one CSV per operational table to an open zip (root-level *.csv).
 *
 * @return array{success:bool,error?:string,tables?:list<array{table:string,file:string,columns:list<string>,rows:int}>}
 */
function addDataOnlyCsvTablesToZip(mysqli $db, ZipArchive $zip): array {
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

    return ['success' => true, 'tables' => $tableMeta];
}

/**
 * Write checksum for a backup file already on disk.
 *
 * @return array{success:bool,error?:string,file?:string,path?:string,size?:int,checksum?:string}
 */
function finalizeBackupFile(string $filename): array {
    if (safeBackupFilename($filename) === null) {
        return ['success' => false, 'error' => 'Invalid backup filename.'];
    }

    $dirInfo = ensureStorageSubdir('backups');
    if ($dirInfo['error'] !== null) {
        return ['success' => false, 'error' => 'Backup storage is not writable: ' . $dirInfo['error']];
    }

    $dir = rtrim($dirInfo['path'], '/\\');
    $path = $dir . '/' . $filename;
    if (!is_file($path) || !is_readable($path)) {
        return ['success' => false, 'error' => 'Backup file was not written.'];
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

    $size = @filesize($path);

    return [
        'success' => true,
        'file' => $filename,
        'path' => $path,
        'size' => $size === false ? 0 : (int)$size,
        'checksum' => $checksumWrite['checksum'] ?? null,
    ];
}

/**
 * Build a zip package: optional SQL dump, optional CSVs, plus storage files.
 *
 * @param 'data-only'|'full' $type
 * @param 'sql'|'csv'|'both' $format
 * @return array{success:bool,error?:string,file?:string,path?:string,size?:int,checksum?:string}
 */
function createBackupPackage(mysqli $db, string $type, string $format, string $filename): array {
    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'error' => 'PHP ZipArchive extension is required for backups that include storage files.'];
    }
    if (safeBackupFilename($filename) === null) {
        return ['success' => false, 'error' => 'Invalid backup filename.'];
    }
    if ($type !== 'data-only' && $type !== 'full') {
        return ['success' => false, 'error' => 'Invalid backup package type.'];
    }
    if (!in_array($format, ['sql', 'csv', 'both'], true)) {
        return ['success' => false, 'error' => 'Invalid backup format. Use sql, csv, or both.'];
    }

    @set_time_limit(300);

    $dirInfo = ensureStorageSubdir('backups');
    if ($dirInfo['error'] !== null) {
        return ['success' => false, 'error' => 'Backup storage is not writable: ' . $dirInfo['error']];
    }

    $dir = rtrim($dirInfo['path'], '/\\');
    $path = $dir . '/' . $filename;
    if (is_file($path) && !@unlink($path)) {
        return ['success' => false, 'error' => describeFileOperationFailure('replace backup file', $path)];
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['success' => false, 'error' => 'Could not create backup package zip.'];
    }

    $includeSql = $type === 'full' || $format === 'sql' || $format === 'both';
    $includeCsv = $type === 'data-only' && ($format === 'csv' || $format === 'both');
    $tableMeta = [];

    if ($includeSql) {
        $sql = $type === 'full' ? generateFullSchemaBackup($db) : generateDataOnlySqlBackup($db);
        if (trim($sql) === '') {
            $zip->close();
            @unlink($path);
            return ['success' => false, 'error' => 'Database dump generated empty content.'];
        }
        $zip->addFromString(TEMPER_BACKUP_PACKAGE_SQL, $sql);
    }

    if ($includeCsv) {
        $csv = addDataOnlyCsvTablesToZip($db, $zip);
        if (!$csv['success']) {
            $zip->close();
            @unlink($path);
            return ['success' => false, 'error' => $csv['error'] ?? 'CSV export failed.'];
        }
        $tableMeta = $csv['tables'] ?? [];
    }

    $storage = addStorageFilesToZip($zip);
    if (empty($storage['success'])) {
        $zip->close();
        @unlink($path);
        return ['success' => false, 'error' => $storage['error'] ?? 'Could not add storage files to backup package.'];
    }

    $manifestFormat = $includeSql && $includeCsv
        ? 'sql-csv-zip'
        : ($includeCsv ? 'csv-zip' : 'sql-zip');

    $manifest = [
        'type' => $type,
        'format' => $manifestFormat,
        'package_version' => TEMPER_BACKUP_PACKAGE_VERSION,
        'includes_storage' => true,
        'storage_dirs' => $storage['dirs'],
        'storage_files' => $storage['files'],
        'database_file' => $includeSql ? TEMPER_BACKUP_PACKAGE_SQL : null,
        'generated' => gmdate('c'),
        'database' => defined('DB_NAME') ? DB_NAME : '',
        'tables' => $tableMeta,
    ];
    $zip->addFromString(
        TEMPER_BACKUP_PACKAGE_MANIFEST,
        (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
    );

    if (!$zip->close()) {
        @unlink($path);
        return ['success' => false, 'error' => 'Could not finalize backup package zip.'];
    }

    return finalizeBackupFile($filename);
}

/**
 * Restore a data-only zip: legacy CSV zip, or a package with database.sql + storage.
 *
 * @return array{success:bool,error?:string,tables?:list<string>,storage?:array,source?:string,partial?:bool}
 */
function restoreDataOnlyFromZip(mysqli $db, string $zipPath): array {
    @set_time_limit(300);
    $peek = peekBackupPackage($zipPath);
    if (empty($peek['valid_zip'])) {
        return ['success' => false, 'error' => 'Could not open backup zip.'];
    }

    $restore = null;
    $source = null;

    if (!empty($peek['has_sql'])) {
        $sql = readBackupZipEntry($zipPath, TEMPER_BACKUP_PACKAGE_SQL);
        if (!is_string($sql) || $sql === '') {
            return ['success' => false, 'error' => 'Backup package is missing a readable database.sql dump.'];
        }
        $restore = restoreDataOnlySql($db, $sql);
        if (!$restore['success']) {
            return $restore;
        }
        $source = 'SQL';
    } elseif (!empty($peek['has_csv'])) {
        $restore = restoreDataOnlyCsvZip($db, $zipPath);
        if (!$restore['success']) {
            return $restore;
        }
        $source = 'CSV';
    } else {
        return ['success' => false, 'error' => 'Zip is not a data-only backup (no database.sql or CSV table files).'];
    }

    $storage = ['success' => true, 'restored' => 0, 'dirs' => [], 'skipped' => true];
    $forceDirs = [];
    if (is_array($peek['manifest'] ?? null) && !empty($peek['manifest']['includes_storage'])) {
        $forceDirs = is_array($peek['manifest']['storage_dirs'] ?? null)
            ? $peek['manifest']['storage_dirs']
            : ($peek['storage_dirs'] ?? []);
    } elseif (!empty($peek['has_storage'])) {
        $forceDirs = $peek['storage_dirs'] ?? [];
    }

    if ($forceDirs !== [] || !empty($peek['has_storage'])) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return [
                'success' => false,
                'error' => 'Database restored, but the package could not be reopened to restore storage files.',
                'tables' => $restore['tables'] ?? null,
                'source' => $source,
                'partial' => true,
            ];
        }
        $storage = restoreStorageFromZip($zip, null, is_array($forceDirs) ? $forceDirs : []);
        $zip->close();
        if (!$storage['success']) {
            return [
                'success' => false,
                'error' => 'Database restored, but storage files failed: ' . ($storage['error'] ?? 'unknown error'),
                'tables' => $restore['tables'] ?? null,
                'storage' => $storage,
                'source' => $source,
                'partial' => true,
            ];
        }
    }

    return [
        'success' => true,
        'tables' => $restore['tables'] ?? null,
        'storage' => $storage,
        'source' => $source,
    ];
}

/**
 * Restore a full schema+data package zip (database.sql + optional storage).
 *
 * @return array{success:bool,error?:string,storage?:array,partial?:bool}
 */
function restoreFullFromZip(mysqli $db, string $zipPath): array {
    @set_time_limit(300);
    $peek = peekBackupPackage($zipPath);
    if (empty($peek['valid_zip'])) {
        return ['success' => false, 'error' => 'Could not open backup zip.'];
    }
    if (empty($peek['has_sql'])) {
        return ['success' => false, 'error' => 'Full backup zip must contain database.sql.'];
    }

    $sql = readBackupZipEntry($zipPath, TEMPER_BACKUP_PACKAGE_SQL);
    if (!is_string($sql) || $sql === '') {
        return ['success' => false, 'error' => 'Backup package is missing a readable database.sql dump.'];
    }

    $restore = restoreFullSql($db, $sql);
    if (!$restore['success']) {
        return $restore;
    }

    $storage = ['success' => true, 'restored' => 0, 'dirs' => [], 'skipped' => true];
    $forceDirs = [];
    if (is_array($peek['manifest'] ?? null) && !empty($peek['manifest']['includes_storage'])) {
        $forceDirs = is_array($peek['manifest']['storage_dirs'] ?? null)
            ? $peek['manifest']['storage_dirs']
            : ($peek['storage_dirs'] ?? []);
    } elseif (!empty($peek['has_storage'])) {
        $forceDirs = $peek['storage_dirs'] ?? [];
    }

    if ($forceDirs !== [] || !empty($peek['has_storage'])) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return [
                'success' => false,
                'error' => 'Database restored, but the package could not be reopened to restore storage files.',
                'partial' => true,
            ];
        }
        $storage = restoreStorageFromZip($zip, null, is_array($forceDirs) ? $forceDirs : []);
        $zip->close();
        if (!$storage['success']) {
            return [
                'success' => false,
                'error' => 'Database restored, but storage files failed: ' . ($storage['error'] ?? 'unknown error'),
                'storage' => $storage,
                'partial' => true,
            ];
        }
    }

    return ['success' => true, 'storage' => $storage];
}

/**
 * Archive an uploaded restore file by copying it (avoids loading large zips into memory).
 *
 * @return array{success:bool,error?:string,filename?:string,path?:string,size?:int}
 */
function archiveRestoredBackupFromPath(string $uploadName, string $sourcePath, string $ext = 'sql'): array {
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

    if (!@copy($sourcePath, $path)) {
        $raw = @file_get_contents($sourcePath);
        if (!is_string($raw)) {
            return ['success' => false, 'error' => describeFileOperationFailure('archive restored backup', $path), 'filename' => $archiveName];
        }
        $write = writeStorageFile($path, $raw);
        if (!$write['success']) {
            return ['success' => false, 'error' => $write['error'], 'filename' => $archiveName];
        }
        return ['success' => true, 'filename' => $archiveName, 'path' => $path, 'size' => $write['bytes']];
    }

    return ['success' => true, 'filename' => $archiveName, 'path' => $path, 'size' => (int)(@filesize($path) ?: 0)];
}

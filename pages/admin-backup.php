<?php
// Admin Backup & Restore - Inner content only for AJAX loading
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/backup_utils.php';

@ini_set('pcre.jit', '0');

    $backupDirInfo = ensureStorageSubdir('backups');
    $backupDir = $backupDirInfo['path'];

    function safePregMatch(string $pattern, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): int|false {
        $result = @preg_match($pattern, $subject, $matchResult, $flags, $offset);
        if (func_num_args() >= 3) {
            $matches = $matchResult;
        }
        return $result;
    }

    function safePregReplace(string $pattern, string $replacement, string $subject, int $limit = -1): string|null {
        return @preg_replace($pattern, $replacement, $subject, $limit);
    }

    function sendJsonResponse(array $payload, ?mysqli $db = null): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode($payload);
        if ($db) {
            $db->close();
        }
        exit;
    }

    function sqlDumpStartPattern(): string {
        return '/^\s*(?:--|\/\*|(?:SET|DROP|CREATE|INSERT|DELETE|UPDATE|ALTER|USE|LOCK|UNLOCK|START)\b)/i';
    }

    function sanitizeSqlBackupContent(string $sql): string {
        if (str_starts_with($sql, "\xEF\xBB\xBF")) {
            $sql = substr($sql, 3);
        }

        $warningPattern = '/^(?:\s*<br\s*\/?>\s*)*(?:Warning|Notice|Deprecated|Fatal error|Parse error):.*?(?:\r?\n|<br\s*\/?>)/is';
        $previous = null;
        while ($previous !== $sql) {
            $previous = $sql;
            $sql = safePregReplace($warningPattern, '', $sql) ?? $sql;
            $sql = ltrim($sql);
        }

        if (!safePregMatch(sqlDumpStartPattern(), $sql)) {
            $markers = [
                '-- Hope Baptist',
                '-- Dump',
                '-- MySQL dump',
                'SET NAMES',
                'SET FOREIGN_KEY',
                'DROP TABLE',
                'CREATE TABLE',
                'INSERT INTO',
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

    function validateSqlBackupContent(string $rawSql, string $cleanedSql): array {
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
            if (safePregMatch($rule['pattern'], $cleanedSql)) {
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

        $sqlMarkers = [
            'hope_baptist_header' => ['pattern' => '/--\s*Hope Baptist/i', 'label' => 'Hope Baptist backup header'],
            'dump_comment' => ['pattern' => '/--\s*(?:Dump|MySQL dump)/i', 'label' => 'mysqldump-style header'],
            'set_names' => ['pattern' => '/\bSET\s+NAMES\b/i', 'label' => 'SET NAMES'],
            'foreign_key_checks' => ['pattern' => '/\bSET\s+FOREIGN_KEY_CHECKS\b/i', 'label' => 'SET FOREIGN_KEY_CHECKS'],
            'drop_table' => ['pattern' => '/\bDROP\s+TABLE\b/i', 'label' => 'DROP TABLE'],
            'create_table' => ['pattern' => '/\bCREATE\s+TABLE\b/i', 'label' => 'CREATE TABLE'],
            'insert_into' => ['pattern' => '/\bINSERT\s+INTO\b/i', 'label' => 'INSERT INTO'],
            'sql_comment' => ['pattern' => '/^\s*--/m', 'label' => 'SQL comment'],
            'sql_keyword_start' => ['pattern' => sqlDumpStartPattern(), 'label' => 'recognized SQL statement at file start'],
        ];

        $found = [];
        foreach ($sqlMarkers as $key => $marker) {
            if (safePregMatch($marker['pattern'], $cleanedSql)) {
                $found[] = $key;
            }
        }

        $hasStructure = safePregMatch('/\b(?:CREATE\s+TABLE|DROP\s+TABLE)\b/i', $cleanedSql);
        $hasData = safePregMatch('/\bINSERT\s+INTO\b/i', $cleanedSql);
        $hasAppHeader = in_array('hope_baptist_header', $found, true);
        $hasDumpHeader = in_array('dump_comment', $found, true);
        $hasSqlStart = in_array('sql_keyword_start', $found, true) || in_array('sql_comment', $found, true);

        if ($hasAppHeader || $hasDumpHeader || ($hasStructure && ($hasData || $hasSqlStart)) || ($hasSqlStart && $hasStructure)) {
            return [
                'valid' => true,
                'error' => null,
                'details' => [
                    'raw_bytes' => $rawSize,
                    'cleaned_bytes' => $cleanedSize,
                    'markers_found' => $found,
                ],
            ];
        }

        $missing = [];
        foreach ($sqlMarkers as $key => $marker) {
            if (!in_array($key, $found, true)) {
                $missing[] = $marker['label'];
            }
        }

        return [
            'valid' => false,
            'error' => 'Backup file does not look like a valid SQL dump.',
            'details' => [
                'raw_bytes' => $rawSize,
                'cleaned_bytes' => $cleanedSize,
                'markers_found' => $found,
                'markers_missing' => $missing,
                'preview' => describeSqlSnippet($cleanedSql),
                'hint' => 'Expected SQL comments (--), CREATE TABLE, INSERT INTO, DROP TABLE, or a Hope Baptist / mysqldump header.',
            ],
        ];
    }

    function safePregMatchAll(string $pattern, string $subject): int {
        $count = @preg_match_all($pattern, $subject, $matches);
        return $count === false ? 0 : $count;
    }

    function backupChecksumFilename(string $sqlFilename): string {
        return $sqlFilename . '.sha256';
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

    function readBackupChecksum(string $backupDir, string $sqlFilename): ?string {
        if (safeBackupFilename($sqlFilename) === null) {
            return null;
        }

        $path = rtrim($backupDir, '/\\') . '/' . backupChecksumFilename($sqlFilename);
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

        if (safePregMatch('/^([a-f0-9]{64})\b/i', $content, $matches) === 1) {
            $hash = $matches[1] ?? null;
            if (is_string($hash) && $hash !== '') {
                return strtolower($hash);
            }
        }

        return null;
    }

    function writeBackupChecksumFile(string $backupDir, string $sqlFilename): array {
        if (safeBackupFilename($sqlFilename) === null) {
            return ['success' => false, 'error' => 'Invalid backup filename for checksum generation.'];
        }

        $sqlPath = rtrim($backupDir, '/\\') . '/' . $sqlFilename;
        if (!is_file($sqlPath) || !is_readable($sqlPath)) {
            return ['success' => false, 'error' => 'Backup file not found for checksum generation.'];
        }

        $hash = computeFileSha256($sqlPath);
        if ($hash === false) {
            return ['success' => false, 'error' => 'Could not compute SHA256 checksum.'];
        }

        $content = $hash . '  ' . $sqlFilename . "\n";
        $checksumPath = rtrim($backupDir, '/\\') . '/' . backupChecksumFilename($sqlFilename);
        $write = writeStorageFile($checksumPath, $content);
        if (!$write['success']) {
            return $write;
        }

        return ['success' => true, 'checksum' => $hash, 'path' => $checksumPath];
    }

    function inspectSqlBackupIntegrity(string $rawSql): array {
        $issues = [];
        $cleaned = sanitizeSqlBackupContent($rawSql);

        if ($cleaned === '') {
            return [
                'valid' => false,
                'issues' => ['Backup file is empty or unreadable'],
                'summary' => 'Backup file is empty or unreadable',
                'stats' => ['create_table' => 0, 'drop_table' => 0, 'insert_into' => 0, 'bytes' => strlen($rawSql)],
            ];
        }

        $contaminationPatterns = [
            'PHP error output' => '/(?:^|\n)\s*(?:Warning|Notice|Deprecated|Fatal error|Parse error):/im',
            'HTML markup' => '/<(?:!DOCTYPE|html|head|body|script)\b/i',
            'Stray <br> tag' => '/<br\s*\/?>/i',
            'PHP source code' => '/<\?php/i',
        ];

        foreach ($contaminationPatterns as $label => $pattern) {
            if (safePregMatch($pattern, $rawSql)) {
                $issues[] = $label;
            }
        }

        $validation = validateSqlBackupContent($rawSql, $cleaned);
        if (!$validation['valid']) {
            $issues[] = $validation['error'] ?? 'Backup failed SQL structure validation';
        }

        $createCount = safePregMatchAll('/\bCREATE\s+TABLE\b/i', $cleaned);
        $dropCount = safePregMatchAll('/\bDROP\s+TABLE\b/i', $cleaned);
        $insertCount = safePregMatchAll('/\bINSERT\s+INTO\b/i', $cleaned);

        if ($createCount === 0) {
            $issues[] = 'No CREATE TABLE statements found';
        }
        if ($dropCount > 0 && $createCount > 0 && $dropCount !== $createCount) {
            $issues[] = 'DROP TABLE count (' . $dropCount . ') does not match CREATE TABLE count (' . $createCount . ')';
        }

        $trimmed = rtrim($cleaned);
        if ($trimmed !== '' && !str_ends_with($trimmed, ';')) {
            $issues[] = 'File may end with an incomplete SQL statement';
        }

        $issues = array_values(array_unique($issues));
        $valid = count($issues) === 0;

        if ($valid) {
            $summary = 'Verified — ' . $createCount . ' table' . ($createCount === 1 ? '' : 's')
                . ', ' . number_format($insertCount) . ' INSERT' . ($insertCount === 1 ? '' : 's');
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
                'bytes' => strlen($rawSql),
            ],
        ];
    }

    function inspectBackupFile(string $backupDir, string $filename, bool $ensureChecksum = true): array {
        $failed = static fn(string $message): array => [
            'valid' => false,
            'issues' => [$message],
            'summary' => $message,
            'stats' => [],
            'checksum' => null,
        ];

        if (safeBackupFilename($filename) === null) {
            return $failed('Invalid backup filename');
        }

        $path = rtrim($backupDir, '/\\') . '/' . $filename;
        if (!is_file($path) || !is_readable($path)) {
            return $failed('Backup file not found');
        }

        $rawSql = @file_get_contents($path);
        if (!is_string($rawSql) || $rawSql === '') {
            return $failed('Could not read backup file');
        }

        $integrity = inspectSqlBackupIntegrity($rawSql);
        if (!is_array($integrity)) {
            return $failed('Could not validate backup file');
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
            if (!str_ends_with(strtolower($checksumName), '.sql.sha256')) {
                continue;
            }

            $sqlName = substr($checksumName, 0, -strlen('.sha256'));
            if (safeBackupFilename($sqlName) === null || !is_file($dir . '/' . $sqlName)) {
                @unlink($path);
            }
        }
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
        if (!empty($details['markers_missing'])) {
            $shown = array_slice($details['markers_missing'], 0, 4);
            $parts[] = 'Missing expected patterns: ' . implode(', ', $shown);
        }
        if (!empty($details['hint'])) {
            $parts[] = $details['hint'];
        }

        return implode(' ', $parts);
    }

    function archiveRestoredBackup(string $uploadName, string $sql): array {
        $dirInfo = ensureStorageSubdir('backups');
        if ($dirInfo['error'] !== null) {
            return ['success' => false, 'error' => $dirInfo['error']];
        }

        $safeName = safePregReplace('/[^a-zA-Z0-9._-]/', '_', basename($uploadName)) ?? 'upload.sql';
        if ($safeName === '' || $safeName === '.sql') {
            $safeName = 'upload.sql';
        }
        $archiveName = 'restored_' . date('Y-m-d_His') . '_' . $safeName;
        $path = rtrim($dirInfo['path'], '/\\') . '/' . $archiveName;

        $write = writeStorageFile($path, $sql);
        if (!$write['success']) {
            return ['success' => false, 'error' => $write['error'], 'filename' => $archiveName];
        }

        return ['success' => true, 'filename' => $archiveName, 'path' => $path, 'size' => $write['bytes']];
    }

    function backupSqlValue(mysqli $db, $value): string {
        if ($value === null) {
            return 'NULL';
        }
        return "'" . $db->real_escape_string((string)$value) . "'";
    }

    function generateDatabaseBackup(mysqli $db): string {
        $sql = "-- Hope Baptist Treasurer Database Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Database: " . DB_NAME . "\n\n";
        $sql .= "SET NAMES utf8mb4;\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        $tables = [];
        $res = $db->query('SHOW TABLES');
        if ($res) {
            while ($row = $res->fetch_array()) {
                $tables[] = $row[0];
            }
            $res->close();
        }

        foreach ($tables as $table) {
            $createRes = $db->query("SHOW CREATE TABLE `$table`");
            if (!$createRes) {
                continue;
            }
            $createRow = $createRes->fetch_assoc();
            $createRes->close();

            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $createRow['Create Table'] . ";\n\n";

            $dataRes = $db->query("SELECT * FROM `$table`");
            if ($dataRes && $dataRes->num_rows > 0) {
                $columns = array_keys($dataRes->fetch_assoc());
                $dataRes->data_seek(0);
                $colList = '`' . implode('`, `', $columns) . '`';

                while ($row = $dataRes->fetch_assoc()) {
                    $values = array_map(fn($col) => backupSqlValue($db, $row[$col]), $columns);
                    $sql .= "INSERT INTO `$table` ($colList) VALUES (" . implode(', ', $values) . ");\n";
                }
                $sql .= "\n";
                $dataRes->close();
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        return $sql;
    }

    function listBackupFiles(string $dir, bool $withIntegrity = true): array {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }

        pruneOrphanedChecksumFiles($dir);

        $dir = rtrim($dir, '/\\');
        $paths = glob($dir . '/backup_*.sql', GLOB_NOSORT);
        if ($paths === false) {
            return $files;
        }

        foreach ($paths as $path) {
            if (!is_string($path) || !is_file($path) || !is_readable($path)) {
                continue;
            }

            $name = basename($path);
            if (safeBackupFilename($name) === null) {
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

        usort($files, fn($a, $b) => ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0));
        return $files;
    }

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
            fn($f) => $f !== $filename
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

    function verifyCurrentUserPassword(mysqli $db, int $userId, string $password): bool {
        $stmt = $db->prepare('SELECT password FROM users WHERE id = ? AND is_active = TRUE');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return false;
        }
        return password_verify($password, $row['password']);
    }

    if (isset($_GET['action'])) {
        $action = $_GET['action'];

        if ($action === 'create') {
            ob_start();
            $sql = generateDatabaseBackup($db);
            ob_end_clean();
            $filename = 'backup_' . date('Y-m-d_His') . '.sql';
            $path = $backupDir . '/' . $filename;
            $write = writeStorageFile($path, $sql);
            if (!$write['success']) {
                header('HTTP/1.1 500 Internal Server Error');
                header('Content-Type: text/plain; charset=utf-8');
                echo $write['error'];
                $db->close();
                exit;
            }

            writeBackupChecksumFile($backupDir, $filename);

            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($sql));
            echo $sql;
            $db->close();
            exit;
        }

        if ($action === 'download' && isset($_GET['file'])) {
            $safe = safeBackupFilename($_GET['file']);
            if (!$safe) {
                header('HTTP/1.1 400 Bad Request');
                exit;
            }
            $path = $backupDir . '/' . $safe;
            if (!is_file($path)) {
                header('HTTP/1.1 404 Not Found');
                exit;
            }
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $safe . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            $db->close();
            exit;
        }

        if ($action === 'download_checksum' && isset($_GET['file'])) {
            $safe = safeBackupFilename($_GET['file']);
            if (!$safe) {
                header('HTTP/1.1 400 Bad Request');
                exit;
            }

            $checksumName = backupChecksumFilename($safe);
            $checksumPath = $backupDir . '/' . $checksumName;
            if (!is_file($checksumPath)) {
                $checksumWrite = writeBackupChecksumFile($backupDir, $safe);
                if (!$checksumWrite['success']) {
                    header('HTTP/1.1 500 Internal Server Error');
                    header('Content-Type: text/plain; charset=utf-8');
                    echo $checksumWrite['error'] ?? 'Could not generate checksum file.';
                    $db->close();
                    exit;
                }
            }

            if (!is_file($checksumPath)) {
                header('HTTP/1.1 404 Not Found');
                exit;
            }

            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $checksumName . '"');
            header('Content-Length: ' . filesize($checksumPath));
            readfile($checksumPath);
            $db->close();
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postAction = $_POST['action'] ?? '';

        if ($postAction === 'unlock_backup') {
            header('Content-Type: application/json');

            $safe = safeBackupFilename($_POST['file'] ?? '');
            $password = $_POST['password'] ?? '';
            $user = getCurrentUser();

            if (!$safe) {
                echo json_encode(['error' => 'Invalid backup file.']);
                $db->close();
                exit;
            }
            if (!is_file($backupDir . '/' . $safe)) {
                echo json_encode(['error' => 'Backup file not found.']);
                $db->close();
                exit;
            }
            if ($password === '') {
                echo json_encode(['error' => 'Password is required to unlock a backup.']);
                $db->close();
                exit;
            }
            if (!$user || !verifyCurrentUserPassword($db, (int)$user['id'], $password)) {
                echo json_encode(['error' => 'Incorrect password. Backup remains locked.']);
                $db->close();
                exit;
            }

            unlockBackup($safe);
            echo json_encode(['success' => true, 'message' => 'Backup unlocked. You may now delete it.', 'file' => $safe]);
            $db->close();
            exit;
        }

        if ($postAction === 'create_backup') {
            ob_start();
            $sql = generateDatabaseBackup($db);
            ob_end_clean();
            if (trim($sql) === '') {
                sendJsonResponse(['error' => 'Backup generated empty content.'], $db);
            }

            if ($backupDirInfo['error'] !== null) {
                sendJsonResponse(['error' => 'Backup storage is not writable: ' . $backupDirInfo['error']], $db);
            }

            $filename = 'backup_' . date('Y-m-d_His') . '.sql';
            $path = $backupDir . '/' . $filename;
            $write = writeStorageFile($path, $sql);
            if (!$write['success']) {
                sendJsonResponse(['error' => $write['error']], $db);
            }

            $checksumWrite = writeBackupChecksumFile($backupDir, $filename);
            if (!$checksumWrite['success']) {
                sendJsonResponse(['error' => $checksumWrite['error']], $db);
            }

            $integrity = inspectSqlBackupIntegrity($sql);
            $message = $integrity['valid']
                ? 'Backup created and verified ✓'
                : 'Backup created with integrity warnings';

            sendJsonResponse([
                'success' => true,
                'message' => $message,
                'file' => $filename,
                'size' => $write['bytes'],
                'checksum' => $checksumWrite['checksum'],
                'integrity' => [
                    'valid' => $integrity['valid'],
                    'summary' => $integrity['summary'],
                ],
                'download_url' => 'pages/admin-backup.php?action=download&file=' . urlencode($filename),
                'checksum_url' => 'pages/admin-backup.php?action=download_checksum&file=' . urlencode($filename),
            ], $db);
        }

        if ($postAction === 'delete_backup') {
            header('Content-Type: application/json');

            $safe = safeBackupFilename($_POST['file'] ?? '');

            if (!$safe) {
                echo json_encode(['error' => 'Invalid backup file.']);
                $db->close();
                exit;
            }
            if (!isBackupUnlocked($safe)) {
                echo json_encode(['error' => 'This backup is locked. Unlock it with your password before deleting.']);
                $db->close();
                exit;
            }

            $path = $backupDir . '/' . $safe;
            if (!is_file($path)) {
                lockBackup($safe);
                echo json_encode(['error' => 'Backup file not found.']);
                $db->close();
                exit;
            }

            $delete = deleteStorageFile($path);
            if (!$delete['success']) {
                sendJsonResponse(['error' => $delete['error']], $db);
            }

            $checksumPath = $backupDir . '/' . backupChecksumFilename($safe);
            if (is_file($checksumPath)) {
                deleteStorageFile($checksumPath);
            }

            lockBackup($safe);
            echo json_encode(['success' => true, 'message' => 'Backup deleted permanently.', 'file' => $safe]);
            $db->close();
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
        ob_start();

        if (empty($_POST['confirm_restore'])) {
            sendJsonResponse(['error' => 'You must confirm that you understand restore will overwrite current data.'], $db);
        }

        if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $uploadError = $_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $uploadMessages = [
                UPLOAD_ERR_INI_SIZE => 'Backup file exceeds server upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'Backup file exceeds form upload limit.',
                UPLOAD_ERR_PARTIAL => 'Backup file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'Please select a valid .sql backup file to upload.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder for uploads.',
                UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded backup to disk.',
                UPLOAD_ERR_EXTENSION => 'A server extension blocked the backup upload.',
            ];
            sendJsonResponse([
                'error' => $uploadMessages[$uploadError] ?? 'Please select a valid .sql backup file to upload.',
            ], $db);
        }

        $upload = $_FILES['backup_file'];
        $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            sendJsonResponse(['error' => 'Only .sql backup files are supported.'], $db);
        }

        if ($upload['size'] > 50 * 1024 * 1024) {
            sendJsonResponse(['error' => 'Backup file is too large (max 50 MB).'], $db);
        }

        $rawSql = file_get_contents($upload['tmp_name']);
        if ($rawSql === false || trim($rawSql) === '') {
            sendJsonResponse(['error' => 'Backup file is empty or could not be read.'], $db);
        }

        $sql = sanitizeSqlBackupContent($rawSql);
        $validation = validateSqlBackupContent($rawSql, $sql);
        if (!$validation['valid']) {
            sendJsonResponse(['error' => formatSqlValidationError($validation)], $db);
        }

        $archive = archiveRestoredBackup($upload['name'], $sql);

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $restoreOk = false;
        if ($db->multi_query($sql)) {
            $restoreOk = true;
            do {
                if ($result = $db->store_result()) {
                    $result->free();
                }
                if ($db->errno) {
                    $restoreOk = false;
                    break;
                }
            } while ($db->more_results() && $db->next_result());
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        if (!$restoreOk || $db->errno) {
            sendJsonResponse(['error' => 'Restore failed: ' . $db->error], $db);
        }

        $message = 'Database restored successfully.';
        if (!$archive['success']) {
            $message .= ' Warning: uploaded backup could not be archived on the server (' . ($archive['error'] ?? 'unknown error') . ').';
        } else {
            $message .= ' Archived as ' . $archive['filename'] . '.';
        }

        sendJsonResponse(['success' => true, 'message' => $message, 'archive' => $archive], $db);
    }

    pruneUnlockedBackups($backupDir);
    $backups = listBackupFiles($backupDir);
    $unlockedBackups = getUnlockedBackups();
    $tableCount = (int)($db->query('SHOW TABLES')->num_rows ?? 0);
?>

<style>
    .backup-checksum-line {
        font-size: 0.72rem;
        line-height: 1.35;
        word-break: break-all;
    }
    .backup-checksum-copy {
        cursor: pointer;
        color: var(--bs-secondary-color);
        background: transparent;
        border: 0;
        padding: 0;
        text-align: left;
    }
    .backup-checksum-copy:hover,
    .backup-checksum-copy:focus {
        color: var(--bs-primary);
        outline: none;
    }
    .backup-integrity-badge {
        line-height: 1;
        font-size: 1rem;
    }
</style>

<div class="row mb-3 align-items-center">
    <div class="col-md-8">
        <h2 class="h4 mb-0">Backup & Restore</h2>
        <p class="text-muted small mb-0">Export and import the full <?= htmlspecialchars(DB_NAME) ?> database</p>
    </div>
    <div class="col-md-4 text-md-end mt-2 mt-md-0">
        <a href="javascript:void(0)" onclick="loadPage('admin')" class="small text-decoration-none">
            <i class="bi bi-arrow-left"></i> Back to Admin
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-primary text-white py-2">
                <i class="bi bi-cloud-arrow-down"></i> <span class="fw-semibold">Backup</span>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    Create a full SQL backup of all <strong><?= $tableCount ?></strong> tables.
                    A copy is saved on the server and downloaded to your computer.
                </p>
                <button type="button" class="btn btn-primary btn-sm" id="createBackupBtn">
                    <i class="bi bi-download"></i> Create &amp; Download Backup
                </button>

                <div id="backupListSection">
                <?php if (count($backups) > 0): ?>
                    <hr class="my-3">
                    <h6 class="small fw-semibold text-uppercase text-muted mb-1">Recent Saved Backups</h6>
                    <p class="text-muted small mb-2">
                        <i class="bi bi-lock-fill"></i>
                        Backups are <strong>locked</strong> by default. Unlock with your password to enable deletion.
                    </p>
                    <div class="list-group list-group-flush" id="backupList">
                        <?php foreach (array_slice($backups, 0, 8) as $backup): ?>
                            <?php
                                $isUnlocked = in_array($backup['name'], $unlockedBackups, true);
                                $integrity = is_array($backup['integrity'] ?? null) ? $backup['integrity'] : [];
                                $integrityValid = (bool)($integrity['valid'] ?? false);
                                $integritySummary = (string)($integrity['summary'] ?? 'Integrity status unavailable');
                                $checksum = is_string($backup['checksum'] ?? null) ? $backup['checksum'] : '';
                            ?>
                            <div class="list-group-item px-0 py-2 backup-row" data-file="<?= htmlspecialchars($backup['name']) ?>">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="small flex-grow-1 min-w-0">
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <span class="fw-semibold"><?= htmlspecialchars($backup['name']) ?></span>
                                            <span class="backup-integrity-badge"
                                                  data-bs-toggle="tooltip"
                                                  data-bs-placement="top"
                                                  title="<?= htmlspecialchars($integritySummary) ?>">
                                                <?php if ($integrityValid): ?>
                                                    <i class="bi bi-patch-check-fill text-success" aria-label="Integrity verified"></i>
                                                <?php else: ?>
                                                    <i class="bi bi-x-circle-fill text-danger" aria-label="Integrity check failed"></i>
                                                <?php endif; ?>
                                            </span>
                                            <?php if ($isUnlocked): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle backup-status-badge">
                                                    <i class="bi bi-unlock"></i> Unlocked
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary border backup-status-badge">
                                                    <i class="bi bi-lock-fill"></i> Locked
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted">
                                            <?= htmlspecialchars((string)($backup['display_datetime'] ?? 'Unknown')) ?>
                                            &middot; <?= number_format($backup['size'] / 1024, 1) ?> KB
                                        </div>
                                        <?php if ($checksum !== ''): ?>
                                            <div class="backup-checksum-line mt-1">
                                                <span class="text-muted">SHA256:</span>
                                                <button type="button"
                                                        class="backup-checksum-copy font-monospace"
                                                        data-checksum="<?= htmlspecialchars($checksum) ?>"
                                                        title="Click to copy checksum">
                                                    <?= htmlspecialchars($checksum) ?>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex gap-1 flex-shrink-0 backup-actions">
                                        <a href="pages/admin-backup.php?action=download&amp;file=<?= urlencode($backup['name']) ?>"
                                           class="btn btn-outline-secondary btn-sm" title="Download backup">
                                            <i class="bi bi-download"></i>
                                        </a>
                                        <?php if ($checksum !== ''): ?>
                                            <a href="pages/admin-backup.php?action=download_checksum&amp;file=<?= urlencode($backup['name']) ?>"
                                               class="btn btn-outline-secondary btn-sm" title="Download checksum">
                                                <i class="bi bi-file-earmark-check"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($isUnlocked): ?>
                                            <button type="button"
                                                    class="btn btn-outline-danger btn-sm backup-delete-btn"
                                                    data-file="<?= htmlspecialchars($backup['name']) ?>"
                                                    title="Delete backup">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button"
                                                    class="btn btn-outline-warning btn-sm backup-unlock-btn"
                                                    data-file="<?= htmlspecialchars($backup['name']) ?>"
                                                    title="Unlock to delete">
                                                <i class="bi bi-unlock"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0 mt-3" id="backupListEmpty">No saved backups yet.</p>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm h-100 border-warning">
            <div class="card-header bg-warning text-dark py-2">
                <i class="bi bi-cloud-arrow-up"></i> <span class="fw-semibold">Restore</span>
            </div>
            <div class="card-body">
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="bi bi-exclamation-triangle"></i>
                    Restore will <strong>overwrite all current data</strong> in the database. Create a backup first.
                </div>

                <form id="restoreForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="restore">
                    <div class="mb-3">
                        <label for="backupFile" class="form-label small fw-semibold">SQL Backup File</label>
                        <input type="file" class="form-control form-control-sm" id="backupFile" name="backup_file" accept=".sql" required>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirmRestore" name="confirm_restore" value="1">
                        <label class="form-check-label small" for="confirmRestore">
                            I understand this will replace all current database contents.
                        </label>
                    </div>
                    <button type="submit" class="btn btn-warning btn-sm" id="restoreBtn">
                        <i class="bi bi-arrow-counterclockwise"></i> Restore Database
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="unlockBackupModal" tabindex="-1" aria-labelledby="unlockBackupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form id="unlockBackupForm">
                <div class="modal-header py-2">
                    <h5 class="modal-title h6" id="unlockBackupModalLabel">
                        <i class="bi bi-unlock"></i> Unlock Backup
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="bi bi-exclamation-triangle"></i>
                        Unlocking allows <strong>permanent deletion</strong> of this backup.
                    </div>
                    <p class="small mb-2">Backup: <strong id="unlockBackupName"></strong></p>
                    <input type="hidden" name="action" value="unlock_backup">
                    <input type="hidden" name="file" id="unlockBackupFile">
                    <div class="mb-0">
                        <label for="unlockPassword" class="form-label small fw-semibold">Your Password</label>
                        <input type="password" class="form-control form-control-sm" id="unlockPassword" name="password" required autocomplete="current-password">
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm" id="unlockSubmitBtn">
                        <i class="bi bi-unlock"></i> Unlock
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script type="text/plain" id="init-admin-backup-script">
(function() {
    const page = 'admin-backup';
    const createBackupBtn = document.getElementById('createBackupBtn');
    const createBackupBtnDefaultHtml = createBackupBtn ? createBackupBtn.innerHTML : '';
    const restoreForm = document.getElementById('restoreForm');
    const restoreBtn = document.getElementById('restoreBtn');
    const unlockModalEl = document.getElementById('unlockBackupModal');
    const unlockModal = unlockModalEl ? new bootstrap.Modal(unlockModalEl) : null;
    const unlockForm = document.getElementById('unlockBackupForm');
    const unlockSubmitBtn = document.getElementById('unlockSubmitBtn');

    function initBackupTooltips() {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            const existing = bootstrap.Tooltip.getInstance(el);
            if (existing) {
                existing.dispose();
            }
            new bootstrap.Tooltip(el);
        });
    }

    async function copyTextToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }
        const helper = document.createElement('textarea');
        helper.value = text;
        helper.setAttribute('readonly', '');
        helper.style.position = 'fixed';
        helper.style.left = '-9999px';
        document.body.appendChild(helper);
        helper.select();
        document.execCommand('copy');
        helper.remove();
    }

    document.querySelectorAll('.backup-checksum-copy').forEach(btn => {
        btn.addEventListener('click', async () => {
            const checksum = btn.dataset.checksum || '';
            if (!checksum) {
                return;
            }
            try {
                await copyTextToClipboard(checksum);
                showToast('Checksum copied to clipboard.', 'success', 2500);
            } catch {
                showToast('Could not copy checksum.', 'danger');
            }
        });
    });

    initBackupTooltips();

    function reloadPage() {
        fetch(`pages/${page}.php`)
            .then(r => r.text())
            .then(h => applyMainContent(h))
            .catch(() => showToast('Failed to refresh page.', 'danger'));
    }

    async function parseJsonResponse(r) {
        const text = await r.text();
        let body;
        try {
            body = JSON.parse(text);
        } catch {
            const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 180);
            throw new Error(snippet
                ? `Server returned an invalid response: ${snippet}`
                : 'Server returned an invalid response.');
        }
        if (!r.ok && body && !body.error) {
            body.error = 'Request failed (HTTP ' + r.status + ').';
        }
        return body;
    }

    function postAction(data) {
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        return fetch(`pages/${page}.php`, { method: 'POST', body: fd })
            .then(r => parseJsonResponse(r));
    }

    if (createBackupBtn) {
        createBackupBtn.addEventListener('click', () => {
            createBackupBtn.disabled = true;
            createBackupBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Creating...';

            postAction({ action: 'create_backup' })
                .then(res => {
                    if (res.error) {
                        showToast(res.error, 'danger');
                        return;
                    }
                    const toastType = res.integrity && res.integrity.valid === false ? 'warning' : 'success';
                    let toastMessage = res.message || 'Backup created successfully.';
                    if (res.integrity && res.integrity.valid === false && res.integrity.summary) {
                        toastMessage += ' — ' + res.integrity.summary;
                    }
                    showToast(toastMessage, toastType);
                    if (res.download_url) {
                        const link = document.createElement('a');
                        link.href = res.download_url;
                        link.style.display = 'none';
                        document.body.appendChild(link);
                        link.click();
                        link.remove();
                    }
                    reloadPage();
                })
                .catch(err => showToast(err.message || 'Backup creation failed. Please try again.', 'danger'))
                .finally(() => {
                    createBackupBtn.disabled = false;
                    createBackupBtn.innerHTML = createBackupBtnDefaultHtml;
                });
        });
    }

    document.querySelectorAll('.backup-unlock-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('unlockBackupFile').value = btn.dataset.file;
            document.getElementById('unlockBackupName').textContent = btn.dataset.file;
            document.getElementById('unlockPassword').value = '';
            unlockModal.show();
        });
    });

    if (unlockForm) {
        unlockForm.addEventListener('submit', e => {
            e.preventDefault();
            unlockSubmitBtn.disabled = true;

            postAction(Object.fromEntries(new FormData(unlockForm).entries()))
                .then(res => {
                    if (res.error) {
                        showToast(res.error, 'danger');
                        return;
                    }
                    unlockModal.hide();
                    showToast(res.message, 'success');
                    reloadPage();
                })
                .catch(() => showToast('Unlock failed. Please try again.', 'danger'))
                .finally(() => { unlockSubmitBtn.disabled = false; });
        });
    }

    document.querySelectorAll('.backup-delete-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const file = btn.dataset.file;
            if (!confirm(`Permanently delete backup "${file}"?\n\nThis action cannot be undone.`)) {
                return;
            }
            if (!confirm('Are you absolutely sure? The backup file will be removed from the server.')) {
                return;
            }

            btn.disabled = true;
            postAction({ action: 'delete_backup', file })
                .then(res => {
                    if (res.error) {
                        showToast(res.error, 'danger');
                        btn.disabled = false;
                        return;
                    }
                    showToast(res.message, 'success');
                    reloadPage();
                })
                .catch(() => {
                    showToast('Delete failed. Please try again.', 'danger');
                    btn.disabled = false;
                });
        });
    });

    restoreForm.addEventListener('submit', e => {
        e.preventDefault();
        if (!document.getElementById('confirmRestore').checked) {
            showToast('Please confirm that you understand restore will overwrite current data.', 'warning');
            return;
        }
        if (!confirm('This will overwrite ALL current database data. Continue with restore?')) {
            return;
        }

        restoreBtn.disabled = true;
        restoreBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Restoring...';

        fetch(`pages/${page}.php`, { method: 'POST', body: new FormData(restoreForm) })
            .then(async r => {
                const text = await r.text();
                let res;
                try {
                    res = JSON.parse(text);
                } catch {
                    const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 180);
                    throw new Error(snippet
                        ? `Server returned an invalid response: ${snippet}`
                        : 'Server returned an invalid response.');
                }
                if (!r.ok && res && !res.error) {
                    res.error = 'Request failed (HTTP ' + r.status + ').';
                }
                return res;
            })
            .then(res => {
                if (res.error) {
                    showToast(res.error, 'danger');
                    return;
                }
                const toastType = res.archive && res.archive.success === false ? 'warning' : 'success';
                showToast(res.message || 'Database restored successfully.', toastType);
                setTimeout(reloadPage, 1500);
            })
            .catch(err => showToast(err.message || 'Restore failed. Please try again.', 'danger'))
            .finally(() => {
                restoreBtn.disabled = false;
                restoreBtn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Restore Database';
            });
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-admin-backup-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>
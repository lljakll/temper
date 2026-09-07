<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

function getAppRoot(): string {
    return dirname(__DIR__);
}

/** Default writable root: <application root>/storage (never a parent folder). */
function getDefaultStoragePath(): string {
    return rtrim(str_replace('\\', '/', getAppRoot()), '/') . '/storage';
}

function temperNormalizeStoragePath(string $path): string {
    $path = rtrim(str_replace('\\', '/', trim($path)), '/');
    if ($path === '') {
        return '';
    }
    $real = realpath($path);
    return $real !== false ? rtrim(str_replace('\\', '/', $real), '/') : $path;
}

/**
 * Immediate parent-directory folder named storage (e.g. /var/www/storage when
 * the app lives at /var/www/temper). Never used as an automatic candidate.
 */
function getUndocumentedParentStoragePath(): ?string {
    $parent = dirname(rtrim(str_replace('\\', '/', getAppRoot()), '/')) . '/storage';
    if (!is_dir($parent)) {
        return null;
    }
    return temperNormalizeStoragePath($parent);
}

function getConfiguredStoragePath(): ?string {
    $sources = getConfiguredStoragePathSources();
    // Env (Apache SetEnv / process) wins over a config.php constant.
    foreach (['SERVER', 'ENV', 'getenv', 'CONSTANT'] as $key) {
        $value = $sources[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return rtrim(str_replace('\\', '/', trim($value)), '/');
        }
    }

    return null;
}

function getConfiguredStoragePathSources(): array {
    $constant = (defined('TEMPER_STORAGE_PATH') && is_string(TEMPER_STORAGE_PATH) && trim(TEMPER_STORAGE_PATH) !== '')
        ? trim(TEMPER_STORAGE_PATH)
        : null;

    return [
        'CONSTANT' => $constant,
        'SERVER' => $_SERVER['TEMPER_STORAGE_PATH'] ?? null,
        'ENV' => $_ENV['TEMPER_STORAGE_PATH'] ?? null,
        'getenv' => getenv('TEMPER_STORAGE_PATH') ?: null,
    ];
}

/**
 * Explicit override first (env / config.php constant), then the app's own
 * storage directory. No parent-folder walk, sibling /storage, or other
 * undocumented auto-discovery.
 *
 * @return list<string>
 */
function getStoragePathCandidates(): array {
    $configured = getConfiguredStoragePath();
    $default = getDefaultStoragePath();
    $candidates = [];

    if ($configured !== null) {
        $candidates[] = $configured;
    }
    $candidates[] = $default;

    $unique = [];
    $seen = [];
    foreach ($candidates as $path) {
        $key = temperNormalizeStoragePath($path);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $path;
    }
    return $unique;
}

function probeWritableDirectory(string $dir): array {
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true)) {
            $err = error_get_last();
            return [
                'writable' => false,
                'error' => is_array($err) ? ($err['message'] ?? 'Could not create directory') : 'Could not create directory',
            ];
        }
    }

    $probe = rtrim($dir, '/\\') . '/.write_probe_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $written = @file_put_contents($probe, 'ok');
    if ($written === false) {
        $err = error_get_last();
        return [
            'writable' => false,
            'error' => is_array($err) ? ($err['message'] ?? 'Write probe failed') : 'Write probe failed',
        ];
    }

    if (!@unlink($probe)) {
        $err = error_get_last();
        return [
            'writable' => false,
            'error' => is_array($err) ? ($err['message'] ?? 'Could not remove write probe file') : 'Could not remove write probe file',
        ];
    }

    return ['writable' => true, 'error' => null];
}

function buildStorageSelectionReason(?string $configured, string $candidate, string $default): string {
    $candNorm = temperNormalizeStoragePath($candidate);
    $defaultNorm = temperNormalizeStoragePath($default);
    $configuredNorm = $configured !== null ? temperNormalizeStoragePath($configured) : '';

    if ($configuredNorm !== '' && $candNorm === $configuredNorm) {
        return 'Selected configured TEMPER_STORAGE_PATH (environment or config.php constant).';
    }
    if ($candNorm === $defaultNorm) {
        return 'Selected default application storage directory (' . $default . ').';
    }
    return 'Selected application storage path.';
}

function resolveStorageRoot(bool $forceRecheck = false): array {
    static $resolved = null;
    static $loggedParentConflict = false;
    if ($resolved !== null && !$forceRecheck) {
        return $resolved;
    }

    $default = getDefaultStoragePath();
    $configured = getConfiguredStoragePath();
    $intended = $configured !== null ? $configured : $default;
    $errors = [];
    $probes = [];

    foreach (getStoragePathCandidates() as $candidate) {
        $probe = probeWritableDirectory($candidate);
        $probes[$candidate] = $probe;
        if (!$probe['writable']) {
            $errors[$candidate] = $probe['error'];
        }
    }

    $intendedProbe = $probes[$intended] ?? probeWritableDirectory($intended);
    $isConfigured = $configured !== null
        && temperNormalizeStoragePath($intended) === temperNormalizeStoragePath($configured);
    $reason = buildStorageSelectionReason($configured, $intended, $default);
    if (empty($intendedProbe['writable'])) {
        $reason .= ' This process cannot write to that directory.';
    }

    $chosen = [
        'path' => realpath($intended) ?: $intended,
        'source' => $intended,
        'configured_path' => $configured,
        'is_configured' => $isConfigured,
        'fallback' => !$isConfigured,
        'using_fallback' => !$isConfigured,
        'writable' => !empty($intendedProbe['writable']),
        'errors' => $errors,
        'probes' => $probes,
        'selection_reason' => $reason,
    ];

    $activeNorm = temperNormalizeStoragePath((string)$chosen['path']);
    $parent = getUndocumentedParentStoragePath();
    $unusedParent = null;
    if ($parent !== null && temperNormalizeStoragePath($parent) !== $activeNorm) {
        $unusedParent = $parent;
    }
    $chosen['default_path'] = $default;
    $chosen['unused_parent_storage'] = $unusedParent;
    $chosen['parent_storage_exists'] = $unusedParent !== null;

    if ($unusedParent !== null && !$loggedParentConflict) {
        $loggedParentConflict = true;
        error_log(
            '[temper-storage] Resolved storage root: ' . $chosen['path']
            . ' (' . $chosen['selection_reason'] . '). '
            . 'A parent/sibling storage directory exists at ' . $unusedParent
            . ' and is not used.'
        );
    }

    $resolved = $chosen;
    return $resolved;
}

function getStoragePath(): string {
    return resolveStorageRoot()['path'];
}

function ensureStorageSubdir(string $subdir): array {
    $root = resolveStorageRoot();
    $dir = rtrim($root['path'], '/\\') . '/' . trim($subdir, '/\\');
    $probe = probeWritableDirectory($dir);
    if (!$probe['writable']) {
        return [
            'path' => $dir,
            'error' => $probe['error'] ?? 'Storage subdirectory is not writable.',
            'root' => $root,
        ];
    }
    return [
        'path' => realpath($dir) ?: $dir,
        'error' => null,
        'root' => $root,
    ];
}

function getBackupDir(): string {
    return ensureStorageSubdir('backups')['path'];
}

function getExportsDir(): string {
    return ensureStorageSubdir('exports')['path'];
}

function getLogsDir(): string {
    return ensureStorageSubdir('logs')['path'];
}

/**
 * Directory for ledger file attachments.
 * Preferred layout: storage/attachments/{YY####}/ (manual Reference #).
 * Legacy: storage/attachments/{transactionId}/ and storage/transaction_documents/{id}/.
 */
function getTransactionDocumentsDir(): string {
    return ensureStorageSubdir('attachments')['path'];
}

/** Alias for clarity in newer call sites. */
function getAttachmentsDir(): string {
    return getTransactionDocumentsDir();
}

function describeFileOperationFailure(string $operation, string $path): string {
    $err = error_get_last();
    $detail = is_array($err) ? ($err['message'] ?? '') : '';
    $root = resolveStorageRoot();

    if (stripos($detail, 'Read-only file system') !== false) {
        return 'Backup storage is read-only for the web server at ' . $path . '. '
            . 'Apache (www-data) cannot write under /home when the app is served from a home-directory symlink. '
            . 'Set TEMPER_STORAGE_PATH to a writable directory such as ' . getDefaultStoragePath()
            . ', then restart Apache. '
            . 'Active storage root: ' . $root['path'] . '.';
    }

    if (stripos($detail, 'Permission denied') !== false) {
        return 'Permission denied while trying to ' . $operation . ' at ' . $path . '. '
            . 'Ensure the storage directory and parents are owned by www-data and chmod 775. '
            . 'Active storage root: ' . $root['path'] . '.';
    }

    $message = 'Could not ' . $operation . ' at ' . $path;
    if ($detail !== '') {
        $message .= ': ' . $detail;
    }
    $message .= ' Active storage root: ' . $root['path'] . '.';
    return $message;
}

function writeStorageFile(string $path, string $contents): array {
    $written = @file_put_contents($path, $contents, LOCK_EX);
    if ($written === false) {
        return [
            'success' => false,
            'error' => describeFileOperationFailure('save backup file', $path),
        ];
    }
    return ['success' => true, 'bytes' => $written];
}

function deleteStorageFile(string $path): array {
    if (!is_file($path)) {
        return ['success' => false, 'error' => 'File not found: ' . $path];
    }
    if (!@unlink($path)) {
        return [
            'success' => false,
            'error' => describeFileOperationFailure('delete backup file', $path),
        ];
    }
    return ['success' => true];
}

/**
 * Delete files and nested directories under a storage subdirectory, leaving the
 * subdirectory itself in place. Restricted to transaction attachment locations.
 *
 * @return array{success:bool,deleted_files:int,deleted_dirs:int,errors:list<string>}
 */
function emptyStorageSubdir(string $subdir): array {
    $allowed = ['attachments', 'transaction_documents'];
    $subdir = str_replace('\\', '/', trim($subdir, '/\\'));
    $result = [
        'success' => true,
        'deleted_files' => 0,
        'deleted_dirs' => 0,
        'errors' => [],
    ];
    if (!in_array($subdir, $allowed, true)) {
        $result['success'] = false;
        $result['errors'][] = 'Refusing to empty storage subdirectory: ' . $subdir;
        return $result;
    }

    $storageRoot = rtrim((string)(realpath(getStoragePath()) ?: getStoragePath()), '/\\');
    $dir = $storageRoot . '/' . $subdir;
    if (!is_dir($dir)) {
        return $result;
    }
    $dirReal = realpath($dir);
    if ($dirReal === false || !str_starts_with($dirReal, $storageRoot)) {
        $result['success'] = false;
        $result['errors'][] = 'Attachment storage path is not under the storage root.';
        return $result;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirReal, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo) {
            continue;
        }
        $path = $fileInfo->getPathname();
        $pathReal = realpath($path) ?: $path;
        if ($pathReal === $dirReal || !str_starts_with($pathReal, $dirReal . DIRECTORY_SEPARATOR)) {
            continue;
        }
        if ($fileInfo->isDir()) {
            if (@rmdir($path)) {
                $result['deleted_dirs']++;
            } else {
                $result['success'] = false;
                $result['errors'][] = 'Could not remove directory ' . $path;
            }
        } else {
            if (@unlink($path)) {
                $result['deleted_files']++;
            } else {
                $result['success'] = false;
                $result['errors'][] = 'Could not delete file ' . $path;
            }
        }
    }

    return $result;
}

/**
 * Remove all on-disk transaction attachment files (preferred + legacy locations).
 * Leaves the empty attachments / transaction_documents directories in place.
 *
 * @return array{success:bool,deleted_files:int,deleted_dirs:int,errors:list<string>,attachments:array,transaction_documents:array}
 */
function purgeTransactionAttachmentFiles(): array {
    $attachments = emptyStorageSubdir('attachments');
    $legacy = emptyStorageSubdir('transaction_documents');
    $errors = array_merge($attachments['errors'] ?? [], $legacy['errors'] ?? []);
    return [
        'success' => !empty($attachments['success']) && !empty($legacy['success']),
        'deleted_files' => (int)($attachments['deleted_files'] ?? 0) + (int)($legacy['deleted_files'] ?? 0),
        'deleted_dirs' => (int)($attachments['deleted_dirs'] ?? 0) + (int)($legacy['deleted_dirs'] ?? 0),
        'errors' => $errors,
        'attachments' => $attachments,
        'transaction_documents' => $legacy,
    ];
}

function getStorageDiagnostics(): array {
    $root = resolveStorageRoot(true);
    $base = rtrim((string)$root['path'], '/\\');

    return [
        'active_root' => $root['path'],
        'active_source' => $root['source'],
        'default_path' => $root['default_path'] ?? getDefaultStoragePath(),
        'configured_path' => $root['configured_path'],
        'is_configured' => $root['is_configured'],
        'using_fallback' => $root['using_fallback'],
        'selection_reason' => $root['selection_reason'],
        'writable' => $root['writable'],
        'unused_parent_storage' => $root['unused_parent_storage'] ?? null,
        'parent_storage_exists' => !empty($root['parent_storage_exists']),
        'backup_dir' => $base . '/backups',
        'subdirs' => [
            'attachments' => $base . '/attachments',
            'backups' => $base . '/backups',
            'config' => $base . '/config',
            'exports' => $base . '/exports',
            'logs' => $base . '/logs',
        ],
        'env_sources' => getConfiguredStoragePathSources(),
        'candidates' => getStoragePathCandidates(),
        'probes' => $root['probes'] ?? [],
        'errors' => $root['errors'] ?? [],
    ];
}
<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

function getAppRoot(): string {
    return dirname(__DIR__);
}

function getConfiguredStoragePath(): ?string {
    $sources = [
        'SERVER' => $_SERVER['TEMPER_STORAGE_PATH'] ?? null,
        'ENV' => $_ENV['TEMPER_STORAGE_PATH'] ?? null,
        'getenv' => getenv('TEMPER_STORAGE_PATH') ?: null,
    ];

    foreach ($sources as $value) {
        if (is_string($value) && trim($value) !== '') {
            return rtrim(trim($value), '/\\');
        }
    }

    return null;
}

function getConfiguredStoragePathSources(): array {
    return [
        'SERVER' => $_SERVER['TEMPER_STORAGE_PATH'] ?? null,
        'ENV' => $_ENV['TEMPER_STORAGE_PATH'] ?? null,
        'getenv' => getenv('TEMPER_STORAGE_PATH') ?: null,
    ];
}

function getStoragePathCandidates(): array {
    $appRoot = getAppRoot();
    $configured = getConfiguredStoragePath();
    $candidates = [];

    if ($configured !== null) {
        $candidates[] = $configured;
    }

    $candidates[] = dirname($appRoot) . '/storage';
    $candidates[] = $appRoot . '/storage';
    $candidates[] = '/var/www/temper/storage';
    $candidates[] = '/var/www/html/temper-data/storage';
    $candidates[] = rtrim(sys_get_temp_dir(), '/\\') . '/temper-storage';

    return array_values(array_unique($candidates));
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
    if ($configured !== null && $candidate === $configured) {
        return 'Selected configured TEMPER_STORAGE_PATH from server environment.';
    }
    if ($candidate === $default) {
        return 'Selected default app storage directory.';
    }
    if ($candidate === '/var/www/temper/storage') {
        return 'Selected built-in preferred path /var/www/temper/storage.';
    }
    return 'Selected automatic fallback because earlier candidates were not writable.';
}

function resolveStorageRoot(bool $forceRecheck = false): array {
    static $resolved = null;
    if ($resolved !== null && !$forceRecheck) {
        return $resolved;
    }

    $default = getAppRoot() . '/storage';
    $configured = getConfiguredStoragePath();
    $errors = [];
    $probes = [];

    foreach (getStoragePathCandidates() as $candidate) {
        $probe = probeWritableDirectory($candidate);
        $probes[$candidate] = $probe;

        if ($probe['writable']) {
            $isConfigured = $configured !== null && $candidate === $configured;
            $usingFallback = !$isConfigured;

            $resolved = [
                'path' => realpath($candidate) ?: $candidate,
                'source' => $candidate,
                'configured_path' => $configured,
                'is_configured' => $isConfigured,
                'fallback' => $usingFallback,
                'using_fallback' => $usingFallback,
                'writable' => true,
                'errors' => $errors,
                'probes' => $probes,
                'selection_reason' => buildStorageSelectionReason($configured, $candidate, $default),
            ];
            return $resolved;
        }

        $errors[$candidate] = $probe['error'];
    }

    $resolved = [
        'path' => $default,
        'source' => $default,
        'configured_path' => $configured,
        'is_configured' => false,
        'fallback' => true,
        'using_fallback' => true,
        'writable' => false,
        'errors' => $errors,
        'probes' => $probes,
        'selection_reason' => 'No writable storage candidate found; defaulting to app storage path.',
    ];
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

function getTransactionDocumentsDir(): string {
    return ensureStorageSubdir('transaction_documents')['path'];
}

/** @deprecated Use getTransactionDocumentsDir() */
function getWorkflowDocumentsDir(): string {
    return getTransactionDocumentsDir();
}

function describeFileOperationFailure(string $operation, string $path): string {
    $err = error_get_last();
    $detail = is_array($err) ? ($err['message'] ?? '') : '';
    $root = resolveStorageRoot();

    if (stripos($detail, 'Read-only file system') !== false) {
        return 'Backup storage is read-only for the web server at ' . $path . '. '
            . 'Apache (www-data) cannot write under /home when the app is served from a home-directory symlink. '
            . 'Set TEMPER_STORAGE_PATH to a writable directory such as /var/www/temper/storage or '
            . '/var/www/html/temper-data/storage, then restart Apache. '
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

function getStorageDiagnostics(): array {
    $root = resolveStorageRoot(true);

    return [
        'active_root' => $root['path'],
        'active_source' => $root['source'],
        'configured_path' => $root['configured_path'],
        'is_configured' => $root['is_configured'],
        'using_fallback' => $root['using_fallback'],
        'selection_reason' => $root['selection_reason'],
        'writable' => $root['writable'],
        'backup_dir' => ensureStorageSubdir('backups')['path'],
        'env_sources' => getConfiguredStoragePathSources(),
        'candidates' => getStoragePathCandidates(),
        'probes' => $root['probes'] ?? [],
        'errors' => $root['errors'] ?? [],
    ];
}
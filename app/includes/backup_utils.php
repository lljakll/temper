<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

function safeBackupFilename(string $filename): ?string {
    $base = basename($filename);
    if (!@preg_match('/^backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.sql$/', $base)) {
        return null;
    }
    return $base;
}

function formatBackupFilenameDatetime(string $filename): ?string {
    $safe = safeBackupFilename($filename);
    if ($safe === null) {
        return null;
    }
    if (!@preg_match('/^backup_([0-9]{4}-[0-9]{2}-[0-9]{2})_([0-9]{2})([0-9]{2})[0-9]{2}\.sql$/', $safe, $matches)) {
        return null;
    }

    return $matches[1] . ' ' . $matches[2] . ':' . $matches[3] . ' UTC';
}

function parseBackupFilenameTimestamp(string $filename): ?int {
    $safe = safeBackupFilename($filename);
    if ($safe === null) {
        return null;
    }
    if (!@preg_match('/^backup_([0-9]{4}-[0-9]{2}-[0-9]{2})_([0-9]{6})\.sql$/', $safe, $matches)) {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat(
        'Y-m-d His',
        $matches[1] . ' ' . $matches[2],
        new DateTimeZone('UTC')
    );

    return $dt !== false ? $dt->getTimestamp() : null;
}

function listRecentBackupSummaries(string $dir, int $limit = 4): array {
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }

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

        $timestamp = parseBackupFilenameTimestamp($name);
        if ($timestamp === null) {
            $timestamp = @filemtime($path);
            if ($timestamp === false) {
                continue;
            }
        }

        $files[] = [
            'name' => $name,
            'modified' => (int)$timestamp,
            'display_datetime' => formatBackupFilenameDatetime($name) ?? 'Unknown',
        ];
    }

    usort($files, fn($a, $b) => ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0));

    return array_slice($files, 0, max(0, $limit));
}
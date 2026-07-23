#!/usr/bin/env php
<?php
/**
 * CLI auto-backup runner for data-only backups.
 *
 * Usage:
 *   php scripts/auto_backup.php          # run if enabled and due
 *   php scripts/auto_backup.php --force  # run even if not due (still requires enabled unless --force-all)
 *   php scripts/auto_backup.php --force-all  # run regardless of enabled flag
 *
 * Cron example (hourly check):
 *   5 * * * * www-data php /var/www/temper/scripts/auto_backup.php >/dev/null 2>&1
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/backup_utils.php';
require_once $root . '/includes/system_config.php';

// --force / --force-all: run even if not due; works when disabled too (force bypasses enabled check)
$force = in_array('--force', $argv, true)
    || in_array('-f', $argv, true)
    || in_array('--force-all', $argv, true);

try {
    $db = getDbConnection();
} catch (Throwable $e) {
    fwrite(STDERR, 'DB connection failed: ' . $e->getMessage() . "\n");
    exit(2);
}

$result = maybeRunAutoBackup($db, $force);

if (!empty($result['skipped'])) {
    $reason = $result['reason'] ?? 'skipped';
    echo "Skipped: {$reason}\n";
    $db->close();
    exit(0);
}

if (!empty($result['success'])) {
    $files = array_map(static fn($f) => $f['file'], $result['files'] ?? []);
    echo ($result['message'] ?? 'Auto-backup OK') . "\n";
    if ($files !== []) {
        echo 'Files: ' . implode(', ', $files) . "\n";
    }
    $db->close();
    exit(0);
}

fwrite(STDERR, 'Auto-backup failed: ' . ($result['error'] ?? 'unknown') . "\n");
$db->close();
exit(3);

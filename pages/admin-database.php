<?php
// Admin Database Maintenance - Inner content only for AJAX loading
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/backup_utils.php';

// Destructive maintenance is administrator-only
requireAdministrator($db, 'Only administrators can run database maintenance.');

define('MAINTENANCE_PIN', '464546');
define('DEFAULT_ADMIN_PASSWORD_HASH', '$2y$12$hElsAKEKx9CLXDqzYsxEeOLXq2V7vr.OY1qgi2RjTq19MqWII.Ute');

$backupDir = getBackupDir();
$exportDir = getExportsDir();

    $clearActionTables = [
        'clear_transactions' => ['transaction_documents', 'transaction_events', 'transaction_lines', 'transaction_details'],
        'clear_tasks' => ['tasks'],
        'clear_budgets' => ['budget_lines', 'budgets'],
        'clear_accounts_funds' => ['accounts', 'funds'],
        'clear_categories' => ['functional_categories', 'natural_categories'],
        'clear_all_financial' => [
            'transaction_documents',
            'transaction_events',
            'transaction_lines',
            'transaction_details',
            'budget_lines',
            'budgets',
            'tasks',
            'accounts',
            'funds',
            'functional_categories',
            'natural_categories',
        ],
    ];

    $actionsRepopulateCategories = ['clear_categories', 'clear_all_financial'];

    $resetUsersRequiredEmptyTables = [
        'accounts',
        'budget_lines',
        'budgets',
        'funds',
        'tasks',
        'transaction_details',
        'transaction_lines',
    ];

    function getDefaultNaturalCategories(): array {
        return [
            'Offerings' => 'General worship offerings and collections',
            'Tithes' => 'Tithe contributions',
            'Special Gifts' => 'Designated and special-purpose gifts',
            'Interest Income' => 'Interest earned on accounts',
            'Salaries & Benefits' => 'Staff compensation and benefits',
            'Utilities' => 'Electric, water, gas, and utility expenses',
            'Rent/Mortgage' => 'Facility rent or mortgage payments',
            'Insurance' => 'Property, liability, and other insurance',
            'Maintenance & Repairs' => 'Building and equipment maintenance',
            'Office Supplies' => 'General office and administrative supplies',
            'Program Supplies' => 'Ministry and program materials',
            'Missions & Outreach' => 'Missions support and outreach expenses',
            'Scholarships' => 'Educational scholarships and grants',
        ];
    }

    function getDefaultFunctionalCategories(): array {
        return [
            'Worship' => 'Worship services and related activities',
            'Music' => 'Music ministry and worship arts',
            'Youth Ministry' => 'Youth programs and activities',
            'Children\'s Ministry' => 'Children\'s programs and activities',
            'Adult Education' => 'Adult discipleship and education',
            'Facilities & Maintenance' => 'Building and grounds operations',
            'Administration' => 'Administrative and finance operations',
            'Missions' => 'Mission work and partnerships',
            'Evangelism' => 'Evangelism and outreach efforts',
            'Stewardship' => 'Stewardship and giving programs',
        ];
    }

    function repopulateDefaultCategories(mysqli $db): array {
        $results = ['natural_categories' => 0, 'functional_categories' => 0];

        $stmt = $db->prepare('INSERT INTO natural_categories (name, description) VALUES (?, ?)');
        foreach (getDefaultNaturalCategories() as $name => $description) {
            $stmt->bind_param('ss', $name, $description);
            $stmt->execute();
            $results['natural_categories']++;
        }
        $stmt->close();

        $stmt = $db->prepare('INSERT INTO functional_categories (name, description) VALUES (?, ?)');
        foreach (getDefaultFunctionalCategories() as $name => $description) {
            $stmt->bind_param('ss', $name, $description);
            $stmt->execute();
            $results['functional_categories']++;
        }
        $stmt->close();

        return $results;
    }

    function refreshDefaultCategories(mysqli $db): array {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['functional_categories', 'natural_categories'] as $table) {
            if (!$db->query("TRUNCATE TABLE `$table`")) {
                $db->query("DELETE FROM `$table`");
            }
        }
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        return repopulateDefaultCategories($db);
    }

    function getClearSuccessMessage(string $action, bool $categoriesRestored = false): string {
        $labels = [
            'clear_transactions' => 'All transactions cleared.',
            'clear_tasks' => 'All tasks cleared.',
            'clear_budgets' => 'All budgets cleared.',
            'clear_accounts_funds' => 'All accounts and funds cleared.',
            'clear_categories' => 'All categories cleared.',
            'clear_all_financial' => 'All financial data cleared.',
        ];
        $base = $labels[$action] ?? 'Selected data cleared successfully.';
        if ($categoriesRestored) {
            return $base . ' Default category examples restored.';
        }
        return $base;
    }

    function getTablesWithData(mysqli $db, array $tables): array {
        $nonEmpty = [];
        foreach ($tables as $table) {
            $escaped = $db->real_escape_string($table);
            $res = $db->query("SELECT COUNT(*) AS row_count FROM `$escaped`");
            if ($res) {
                $count = (int)$res->fetch_assoc()['row_count'];
                $res->close();
                if ($count > 0) {
                    $nonEmpty[] = $table;
                }
            }
        }
        return $nonEmpty;
    }

    function isFinancialDataCleared(mysqli $db, array $tables): bool {
        return count(getTablesWithData($db, $tables)) === 0;
    }

    function getMaintenanceState(mysqli $db, string $backupDir, array $financialTables): array {
        // Any backup type (data-only or full) satisfies the 24h safety gate
        $backups = listBackupFiles($backupDir, false);
        $latestBackup = getLatestBackup($backups);
        $backupAllowed = hasRecentBackup($latestBackup);
        $tablesWithData = getTablesWithData($db, $financialTables);
        $financialDataCleared = count($tablesWithData) === 0;

        return [
            'backupAllowed' => $backupAllowed,
            'financialDataCleared' => $financialDataCleared,
            'resetUsersAllowed' => $backupAllowed && $financialDataCleared,
            'tablesWithData' => $tablesWithData,
            'latestBackup' => $latestBackup ? [
                'name' => $latestBackup['name'],
                'date' => date('M j, Y', $latestBackup['modified']),
                'time' => date('g:i A', $latestBackup['modified']),
                'within24h' => $backupAllowed,
            ] : null,
        ];
    }

    function getLatestBackup(?array $backups): ?array {
        return $backups[0] ?? null;
    }

    function hasRecentBackup(?array $latestBackup): bool {
        if (!$latestBackup) {
            return false;
        }
        return (time() - $latestBackup['modified']) <= 86400;
    }

    function maintSendJson(array $payload, ?mysqli $db = null): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        if ($db) {
            $db->close();
        }
        exit;
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

    function verifyMaintenanceCredentials(mysqli $db, string $pin, string $password): ?string {
        if ($pin !== MAINTENANCE_PIN) {
            return 'Incorrect maintenance PIN.';
        }
        $user = getCurrentUser();
        if (!$user || !verifyCurrentUserPassword($db, (int)$user['id'], $password)) {
            return 'Incorrect password.';
        }
        return null;
    }

    function clearTables(mysqli $db, array $tables): array {
        $results = [];
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            $escaped = $db->real_escape_string($table);
            if ($db->query("TRUNCATE TABLE `$escaped`")) {
                $results[$table] = 'truncated';
                continue;
            }

            $truncateError = $db->error;
            if ($db->query("DELETE FROM `$escaped`")) {
                $results[$table] = 'deleted';
                error_log("[DB_MAINT] TRUNCATE failed for $table ($truncateError); used DELETE instead.");
                continue;
            }

            $results[$table] = 'failed: ' . $db->error;
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        return $results;
    }

    function saveAuditExport(string $dir, string $content): array {
        $token = bin2hex(random_bytes(16));
        $filename = 'audit_export_' . date('Y-m-d_His') . '_' . $token . '.csv';
        $path = $dir . '/' . $filename;
        file_put_contents($path, $content);
        return ['filename' => $filename, 'token' => $token];
    }

    if (isset($_GET['action']) && $_GET['action'] === 'state') {
        header('Content-Type: application/json');
        echo json_encode(getMaintenanceState($db, $backupDir, $resetUsersRequiredEmptyTables));
        $db->close();
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['file'])) {
        $safe = safeBackupFilename($_GET['file']);
        if (!$safe || !backupIsFullSchema($safe)) {
            header('HTTP/1.1 400 Bad Request');
            exit;
        }
        $path = rtrim($backupDir, '/\\') . '/' . $safe;
        if (!is_file($path)) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
        header('Content-Type: ' . backupDownloadContentType($safe));
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        $db->close();
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'download_checksum' && isset($_GET['file'])) {
        $safe = safeBackupFilename($_GET['file']);
        if (!$safe || !backupIsFullSchema($safe)) {
            header('HTTP/1.1 400 Bad Request');
            exit;
        }
        $checksumName = backupChecksumFilename($safe);
        $checksumPath = rtrim($backupDir, '/\\') . '/' . $checksumName;
        if (!is_file($checksumPath)) {
            writeBackupChecksumFile($backupDir, $safe);
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

    if (isset($_GET['action']) && $_GET['action'] === 'download_audit') {
        $token = $_GET['token'] ?? '';
        $file = basename($_GET['file'] ?? '');

        if ($token === '' || $file === '' || !preg_match('/^audit_export_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}_[a-f0-9]{32}\.csv$/', $file)) {
            header('HTTP/1.1 400 Bad Request');
            exit;
        }
        if (!str_contains($file, $token)) {
            header('HTTP/1.1 403 Forbidden');
            exit;
        }

        $path = $exportDir . '/' . $file;
        if (!is_file($path)) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        unlink($path);
        $db->close();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postAction = $_POST['action'] ?? '';
        $pin = $_POST['pin'] ?? '';
        $password = $_POST['password'] ?? '';
        $user = getCurrentUser();
        $username = $_SESSION['username'] ?? ($user['name'] ?? 'unknown');
        $userId = $user ? (int)$user['id'] : null;

        // Full schema+data backup (no PIN; admin-only page already gated)
        if ($postAction === 'create_full_backup') {
            ob_start();
            $result = createFullSchemaBackup($db);
            ob_end_clean();
            if (!$result['success']) {
                maintSendJson(['error' => $result['error'] ?? 'Full backup failed.'], $db);
            }
            $integrity = inspectBackupFile($backupDir, $result['file']);
            logAuditAction($db, $userId, $username, 'create_full_backup', $result['file']);
            maintSendJson([
                'success' => true,
                'message' => $result['message'] ?? 'Full backup created.',
                'file' => $result['file'],
                'size' => $result['size'] ?? null,
                'checksum' => $result['checksum'] ?? null,
                'integrity' => [
                    'valid' => (bool)($integrity['valid'] ?? false),
                    'summary' => (string)($integrity['summary'] ?? ''),
                ],
                'download_url' => 'pages/admin-database.php?action=download&file=' . urlencode($result['file']),
                'checksum_url' => 'pages/admin-database.php?action=download_checksum&file=' . urlencode($result['file']),
                'state' => getMaintenanceState($db, $backupDir, $resetUsersRequiredEmptyTables),
            ], $db);
        }

        // Full schema+data restore (password + confirm; dangerous)
        if ($postAction === 'restore_full') {
            if (empty($_POST['confirm_restore'])) {
                maintSendJson(['error' => 'You must confirm that full restore will replace schema and data.'], $db);
            }
            if (!$user || !verifyCurrentUserPassword($db, (int)$user['id'], $password)) {
                logAuditAction($db, $userId, $username, 'restore_full_auth_failed', 'bad password');
                maintSendJson(['error' => 'Incorrect password. Full restore cancelled.'], $db);
            }
            if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                maintSendJson(['error' => 'Please select a valid full backup (.sql or .zip).'], $db);
            }
            $upload = $_FILES['backup_file'];
            $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['sql', 'zip'], true)) {
                maintSendJson(['error' => 'Only .sql (legacy full dump) or .zip (full package) backups are supported for schema restore.'], $db);
            }
            if ($upload['size'] > backupRestoreMaxUploadBytes()) {
                maintSendJson(['error' => 'Backup file is too large (max 256 MB).'], $db);
            }

            if ($ext === 'zip') {
                $archive = archiveRestoredBackupFromPath($upload['name'], $upload['tmp_name'], 'zip');
                $restore = restoreFullFromZip($db, $upload['tmp_name']);
                if (!$restore['success']) {
                    logAuditAction($db, $userId, $username, 'restore_full_failed', $restore['error'] ?? '');
                    maintSendJson(['error' => $restore['error'] ?? 'Full restore failed.'], $db);
                }
                logAuditAction($db, $userId, $username, 'restore_full', $upload['name']);
                $storage = is_array($restore['storage'] ?? null) ? $restore['storage'] : [];
                $storageRestored = (int)($storage['restored'] ?? 0);
                $storageDirs = is_array($storage['dirs'] ?? null) ? $storage['dirs'] : [];
                $message = 'Full schema+data restore completed.';
                if ($storageRestored > 0 || $storageDirs !== []) {
                    $message .= ' Restored ' . number_format($storageRestored) . ' storage file'
                        . ($storageRestored === 1 ? '' : 's');
                    if ($storageDirs !== []) {
                        $message .= ' (' . implode(', ', $storageDirs) . ')';
                    }
                    $message .= '.';
                } elseif (!empty($storage['skipped'])) {
                    $message .= ' No storage files were in this backup (database only).';
                }
                if (!$archive['success']) {
                    $message .= ' Warning: could not archive upload (' . ($archive['error'] ?? 'unknown') . ').';
                } else {
                    $message .= ' Archived as ' . $archive['filename'] . '.';
                }
                maintSendJson([
                    'success' => true,
                    'message' => $message,
                    'archive' => $archive,
                    'storage' => $storage,
                    'state' => getMaintenanceState($db, $backupDir, $resetUsersRequiredEmptyTables),
                ], $db);
            }

            $raw = file_get_contents($upload['tmp_name']);
            if ($raw === false || trim($raw) === '') {
                maintSendJson(['error' => 'Backup file is empty or unreadable.'], $db);
            }
            $cleaned = sanitizeSqlBackupContent($raw);
            $validation = validateSqlBackupContent($raw, $cleaned, 'full');
            if (!$validation['valid']) {
                // Allow legacy full dumps
                $any = validateSqlBackupContent($raw, $cleaned, 'any');
                $mode = $any['mode_detected'] ?? '';
                if (!$any['valid'] || $mode === 'data') {
                    maintSendJson([
                        'error' => formatSqlValidationError($validation)
                            . ' Data-only dumps should be restored from Backup & Restore.',
                    ], $db);
                }
            }

            $archive = archiveRestoredBackup($upload['name'], $cleaned, 'sql');
            $restore = restoreFullSql($db, $raw);
            if (!$restore['success']) {
                logAuditAction($db, $userId, $username, 'restore_full_failed', $restore['error'] ?? '');
                maintSendJson(['error' => $restore['error'] ?? 'Full restore failed.'], $db);
            }
            logAuditAction($db, $userId, $username, 'restore_full', $upload['name']);
            $message = 'Full schema+data restore completed. On-disk attachments and config were not changed (SQL-only dump).';
            if (!$archive['success']) {
                $message .= ' Warning: could not archive upload (' . ($archive['error'] ?? 'unknown') . ').';
            } else {
                $message .= ' Archived as ' . $archive['filename'] . '.';
            }
            maintSendJson([
                'success' => true,
                'message' => $message,
                'archive' => $archive,
                'state' => getMaintenanceState($db, $backupDir, $resetUsersRequiredEmptyTables),
            ], $db);
        }

        header('Content-Type: application/json');

        $backups = listBackupFiles($backupDir, false);
        $latestBackup = getLatestBackup($backups);
        if (!hasRecentBackup($latestBackup)) {
            echo json_encode(['error' => 'A backup from the last 24 hours is required before running maintenance actions.']);
            $db->close();
            exit;
        }

        $authError = verifyMaintenanceCredentials($db, $pin, $password);
        if ($authError) {
            logAuditAction($db, $userId, $username, 'maintenance_auth_failed', $postAction);
            echo json_encode(['error' => $authError]);
            $db->close();
            exit;
        }

        if (isset($clearActionTables[$postAction])) {
            $tables = $clearActionTables[$postAction];
            logAuditAction($db, $userId, $username, $postAction, 'Tables: ' . implode(', ', $tables));

            $results = clearTables($db, $tables);
            $failed = array_filter($results, fn($r) => str_starts_with($r, 'failed'));

            if (count($failed) > 0) {
                logAuditAction($db, $userId, $username, $postAction . '_partial', json_encode($results));
                echo json_encode(['error' => 'Some tables could not be cleared.', 'results' => $results]);
                $db->close();
                exit;
            }

            $categoriesRestored = in_array($postAction, $actionsRepopulateCategories, true);
            if ($categoriesRestored) {
                $seeded = repopulateDefaultCategories($db);
                $results['repopulated'] = $seeded;
                logAuditAction(
                    $db,
                    $userId,
                    $username,
                    $postAction . '_repopulate_categories',
                    json_encode($seeded)
                );
            }
            $message = getClearSuccessMessage($postAction, $categoriesRestored);

            if (in_array($postAction, ['clear_transactions', 'clear_all_financial'], true)) {
                $filePurge = purgeTransactionAttachmentFiles();
                $results['attachment_storage'] = $filePurge;
                $deletedFiles = (int)($filePurge['deleted_files'] ?? 0);
                logAuditAction(
                    $db,
                    $userId,
                    $username,
                    $postAction . '_attachment_files',
                    'Purged ' . $deletedFiles . ' attachment file(s) from storage.'
                    . (!empty($filePurge['errors']) ? ' Errors: ' . implode('; ', $filePurge['errors']) : '')
                );
                if (!empty($filePurge['errors'])) {
                    $message .= ' Warning: some attachment files could not be removed from storage.';
                } else {
                    $message .= ' Removed ' . $deletedFiles . ' attachment file(s) from storage.';
                }
            }

            logAuditAction($db, $userId, $username, $postAction . '_completed', json_encode($results));
            echo json_encode([
                'success' => true,
                'message' => $message,
                'results' => $results,
                'state' => getMaintenanceState($db, $backupDir, $resetUsersRequiredEmptyTables),
            ]);
            $db->close();
            exit;
        }

        if ($postAction === 'reset_users') {
            if (!isFinancialDataCleared($db, $resetUsersRequiredEmptyTables)) {
                $blockers = getTablesWithData($db, $resetUsersRequiredEmptyTables);
                logAuditAction($db, $userId, $username, 'reset_users_blocked', 'Tables with data: ' . implode(', ', $blockers));
                echo json_encode(['error' => 'All financial data must be cleared before resetting users.']);
                $db->close();
                exit;
            }

            logAuditAction($db, $userId, $username, 'reset_users', 'Starting user reset to default admin account.');

            $seeded = refreshDefaultCategories($db);
            logAuditAction($db, $userId, $username, 'reset_users_repopulate_categories', json_encode($seeded));

            $auditCsv = exportAuditLogCsv($db);
            $export = saveAuditExport($exportDir, $auditCsv);

            clearAuditLog($db);

            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            require_once __DIR__ . '/../includes/permissions.php';
            // Schema check only (no live DDL). Truncate junction when present.
            ensureUsersRolesSchema($db);
            if (temperTableExists($db, 'user_roles')) {
                if (!$db->query('TRUNCATE TABLE user_roles')) {
                    $db->query('DELETE FROM user_roles');
                }
            }
            if (!$db->query('TRUNCATE TABLE users')) {
                $db->query('DELETE FROM users');
            }
            $db->query('SET FOREIGN_KEY_CHECKS = 1');

            $stmt = $db->prepare(
                'INSERT INTO users (role_id, username, first_name, last_name, email, password, is_active, must_change_password)
                 VALUES (1, ?, ?, ?, ?, ?, TRUE, 0)'
            );
            $adminUsername = 'admin';
            $firstName = 'Admin';
            $lastName = 'User';
            $email = 'admin@church.org';
            $hash = DEFAULT_ADMIN_PASSWORD_HASH;
            $stmt->bind_param('sssss', $adminUsername, $firstName, $lastName, $email, $hash);
            if (!$stmt->execute()) {
                echo json_encode(['error' => 'Failed to recreate default admin account: ' . $stmt->error]);
                $stmt->close();
                $db->close();
                exit;
            }
            $newAdminId = (int)$stmt->insert_id;
            $stmt->close();
            require_once __DIR__ . '/../includes/permissions.php';
            setUserRoles($db, $newAdminId, [1]);

            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();

            echo json_encode([
                'success' => true,
                'logout' => true,
                'message' => 'All users reset. Default admin account and category examples restored. You will be logged out.',
                'audit_download' => 'pages/admin-database.php?action=download_audit&file=' . urlencode($export['filename']) . '&token=' . urlencode($export['token']),
            ]);
            $db->close();
            exit;
        }

        echo json_encode(['error' => 'Unknown action.']);
        $db->close();
        exit;
    }

    $maintenanceState = getMaintenanceState($db, $backupDir, $resetUsersRequiredEmptyTables);
    $backupAllowed = $maintenanceState['backupAllowed'];
    $financialDataCleared = $maintenanceState['financialDataCleared'];
    $resetUsersAllowed = $maintenanceState['resetUsersAllowed'];
    $latestBackupInfo = $maintenanceState['latestBackup'];
    $fullBackups = listBackupFiles($backupDir, true, 'full');
    $tableCount = (int)($db->query('SHOW TABLES')->num_rows ?? 0);
?>

<style>
    .admin-card {
        border-width: 1px;
        border-style: solid;
    }
    .admin-card--secondary {
        border-color: rgba(var(--bs-secondary-rgb), 0.25);
        background-color: rgba(var(--bs-secondary-rgb), 0.06);
    }
    .admin-card--danger {
        border-color: rgba(var(--bs-danger-rgb), 0.3);
        background-color: rgba(var(--bs-danger-rgb), 0.06);
    }
    .admin-card .card-header {
        background: transparent;
        padding: 0.5rem 0.75rem;
        border-bottom-color: var(--bs-border-color-translucent);
    }
    .admin-card .card-body {
        padding: 0.75rem;
    }
    .admin-backup-date {
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--bs-success);
    }
    .admin-backup-date .time {
        font-weight: 500;
        font-size: 0.8rem;
        color: var(--bs-secondary-color);
    }
    .db-maint-btn {
        text-align: left;
    }
</style>

<div class="row mb-3 align-items-center">
    <div class="col-md-8">
        <h2 class="h4 mb-0">Database Maintenance</h2>
        <p class="text-muted small mb-0">Full schema backups, destructive utilities, and user reset</p>
    </div>
    <div class="col-md-4 text-md-end mt-2 mt-md-0">
        <a href="javascript:void(0)" onclick="loadPage('admin')" class="small text-decoration-none">
            <i class="bi bi-arrow-left"></i> Back to System
        </a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100 border-primary">
            <div class="card-header text-bg-primary py-2">
                <i class="bi bi-database"></i>
                <span class="fw-semibold">Full Schema Backup</span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">
                    Create a <strong>full</strong> package (DROP/CREATE + data for all
                    <strong><?= (int)$tableCount ?></strong> tables, plus attachments and system config).
                    Use this before structural changes or for disaster recovery. Day-to-day data exports belong under
                    <a href="javascript:void(0)" onclick="loadPage('admin-backup')">Backup &amp; Restore</a>
                    (data-only).
                </p>
                <button type="button" class="btn btn-primary btn-sm" id="createFullBackupBtn">
                    <i class="bi bi-download"></i> Create Full Backup
                </button>
                <?php if (count($fullBackups) > 0): ?>
                    <hr class="my-3">
                    <h6 class="small fw-semibold text-uppercase text-muted mb-2">Recent full backups</h6>
                    <ul class="list-unstyled small mb-0">
                        <?php foreach (array_slice($fullBackups, 0, 5) as $fb): ?>
                            <li class="mb-2 d-flex justify-content-between align-items-start gap-2">
                                <div class="min-w-0">
                                    <div class="fw-semibold text-truncate"><?= htmlspecialchars($fb['name']) ?></div>
                                    <div class="text-muted">
                                        <?= htmlspecialchars((string)($fb['display_datetime'] ?? '')) ?>
                                        · <?= number_format(($fb['size'] ?? 0) / 1024, 1) ?> KB
                                    </div>
                                </div>
                                <a class="btn btn-outline-secondary btn-sm flex-shrink-0"
                                   href="pages/admin-database.php?action=download&amp;file=<?= urlencode($fb['name']) ?>"
                                   title="Download">
                                    <i class="bi bi-download"></i>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100 border-warning">
            <div class="card-header bg-warning text-dark py-2">
                <i class="bi bi-exclamation-triangle"></i>
                <span class="fw-semibold">Full Schema Restore</span>
            </div>
            <div class="card-body">
                <div class="alert alert-warning py-2 small mb-3">
                    Overwrites <strong>schema and data</strong> (DROP/CREATE tables). Password required.
                    A zip package also replaces attachments and system configuration files.
                    A legacy .sql dump restores the database only.
                    Prefer data-only restore when you only need row data.
                </div>
                <form id="fullRestoreForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="restore_full">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="fullBackupFile">Full backup (.zip package or legacy .sql)</label>
                        <input type="file" class="form-control form-control-sm" id="fullBackupFile" name="backup_file" accept=".sql,.zip" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="fullRestorePassword">Your password</label>
                        <input type="password" class="form-control form-control-sm" id="fullRestorePassword" name="password" required autocomplete="current-password">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="fullConfirmRestore" name="confirm_restore" value="1">
                        <label class="form-check-label small" for="fullConfirmRestore">
                            I understand this replaces schema, all data, and (for packages) attachments and config files.
                        </label>
                    </div>
                    <button type="submit" class="btn btn-warning btn-sm" id="fullRestoreBtn">
                        <i class="bi bi-arrow-counterclockwise"></i> Restore Full Backup
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if (!$backupAllowed): ?>
    <div class="alert alert-danger mb-3">
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-octagon-fill fs-5 flex-shrink-0"></i>
            <div>
                <strong>Maintenance disabled — no recent backup found.</strong>
                <p class="small mb-2 mt-1">
                    A database backup from the last 24 hours is required before any destructive maintenance action.
                    Create a backup now, then return to this page.
                </p>
                <a href="javascript:void(0)" onclick="loadPage('admin-backup')" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-cloud-arrow-up"></i> Go to Backup &amp; Restore
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card admin-card admin-card--danger h-100 shadow-sm <?= $backupAllowed ? '' : 'opacity-50' ?>">
            <div class="card-header border-bottom d-flex align-items-center gap-2">
                <i class="bi bi-trash text-danger"></i>
                <span class="fw-semibold small">Clear Financial / Test Data</span>
            </div>
            <div class="card-body">
                <div class="alert alert-danger py-2 small mb-3">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <strong>Warning:</strong> These actions permanently delete data. This cannot be undone except by restoring a backup.
                </div>

                <div class="mb-3">
                    <div class="text-muted text-uppercase mb-1" style="font-size: 0.7rem; letter-spacing: 0.03em;">Latest Backup</div>
                    <?php if ($latestBackupInfo): ?>
                        <div class="admin-backup-date">
                            <i class="bi bi-calendar-check me-1"></i><?= htmlspecialchars($latestBackupInfo['date']) ?>
                            <span class="time ms-1"><?= htmlspecialchars($latestBackupInfo['time']) ?></span>
                            <?php if ($backupAllowed): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle ms-1">Within 24h</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1">Older than 24h</span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <span class="text-danger small fw-semibold">No backups found</span>
                    <?php endif; ?>
                </div>

                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-danger btn-sm db-maint-btn db-clear-btn" data-action="clear_transactions" <?= $backupAllowed ? '' : 'disabled' ?>>
                        <i class="bi bi-receipt"></i> Clear All Transactions
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm db-maint-btn db-clear-btn" data-action="clear_tasks" <?= $backupAllowed ? '' : 'disabled' ?>>
                        <i class="bi bi-check2-square"></i> Clear Tasks
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm db-maint-btn db-clear-btn" data-action="clear_budgets" <?= $backupAllowed ? '' : 'disabled' ?>>
                        <i class="bi bi-pie-chart"></i> Clear Budgets
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm db-maint-btn db-clear-btn" data-action="clear_accounts_funds" <?= $backupAllowed ? '' : 'disabled' ?>>
                        <i class="bi bi-wallet2"></i> Clear Accounts &amp; Funds
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm db-maint-btn db-clear-btn" data-action="clear_categories" <?= $backupAllowed ? '' : 'disabled' ?>>
                        <i class="bi bi-tags"></i> Clear Categories
                    </button>
                    <button type="button" class="btn btn-danger btn-sm db-maint-btn db-clear-btn" data-action="clear_all_financial" <?= $backupAllowed ? '' : 'disabled' ?>>
                        <i class="bi bi-exclamation-octagon"></i> Clear All Financial Data
                    </button>
                </div>

                <p class="text-muted small mt-3 mb-0">
                    Affects: accounts, budget_lines, budgets, functional_categories, funds, natural_categories, tasks, transaction_details, transaction_lines, transaction_documents, transaction_events, and on-disk attachment files.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card admin-card admin-card--secondary h-100 shadow-sm <?= $backupAllowed ? '' : 'opacity-50' ?>">
            <div class="card-header border-bottom d-flex align-items-center gap-2">
                <i class="bi bi-people text-secondary"></i>
                <span class="fw-semibold small">Reset Users</span>
            </div>
            <div class="card-body">
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <strong>Warning:</strong> Deletes <em>all</em> user accounts and recreates only the default root admin (<code>admin</code> / <code>password</code>).
                    You will be logged out immediately. The audit log will be downloaded and cleared.
                </div>

                <div class="mb-3">
                    <div class="text-muted text-uppercase mb-1" style="font-size: 0.7rem; letter-spacing: 0.03em;">Latest Backup</div>
                    <?php if ($latestBackupInfo): ?>
                        <div class="admin-backup-date">
                            <i class="bi bi-calendar-check me-1"></i><?= htmlspecialchars($latestBackupInfo['date']) ?>
                            <span class="time ms-1"><?= htmlspecialchars($latestBackupInfo['time']) ?></span>
                            <?php if ($backupAllowed): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle ms-1">Within 24h</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1">Older than 24h</span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <span class="text-danger small fw-semibold">No backups found</span>
                    <?php endif; ?>
                </div>

                <div class="alert alert-secondary py-2 small mb-3 <?= ($backupAllowed && !$financialDataCleared) ? '' : 'd-none' ?>" id="resetUsersBlocker">
                    <i class="bi bi-info-circle"></i>
                    All financial data must be cleared before resetting users.
                    <span class="d-block mt-1 text-muted" id="resetUsersBlockerDetail">
                        <?php if (!empty($maintenanceState['tablesWithData'])): ?>
                            Tables with data: <?= htmlspecialchars(implode(', ', $maintenanceState['tablesWithData'])) ?>
                        <?php endif; ?>
                    </span>
                </div>

                <button type="button" class="btn btn-warning btn-sm" id="resetUsersBtn" <?= $resetUsersAllowed ? '' : 'disabled' ?>>
                    <i class="bi bi-arrow-counterclockwise"></i> Reset Users to Default
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="dbMaintModal" tabindex="-1" aria-labelledby="dbMaintModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="dbMaintForm">
                <div class="modal-header py-2 text-bg-danger">
                    <h5 class="modal-title h6" id="dbMaintModalLabel">
                        <i class="bi bi-shield-lock"></i> Confirm Destructive Action
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger py-2 small mb-3" id="dbMaintWarning"></div>
                    <input type="hidden" name="action" id="dbMaintAction">
                    <div class="mb-3">
                        <label for="dbMaintPin" class="form-label small fw-semibold">Maintenance PIN</label>
                        <input type="password" class="form-control form-control-sm" id="dbMaintPin" name="pin" required autocomplete="off" inputmode="numeric">
                    </div>
                    <div class="mb-0">
                        <label for="dbMaintPassword" class="form-label small fw-semibold">Your Password</label>
                        <input type="password" class="form-control form-control-sm" id="dbMaintPassword" name="password" required autocomplete="current-password">
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm" id="dbMaintSubmitBtn">
                        <i class="bi bi-check2-circle"></i> Confirm
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script type="text/plain" id="init-admin-database-script">
(function() {
    const page = 'admin-database';
    const toastContainer = document.getElementById('appToastContainer');
    let modalEl = document.getElementById('dbMaintModal');
    if (modalEl && typeof window.mountModalOnBody === 'function') {
        modalEl = window.mountModalOnBody(modalEl);
    }
    const modal = modalEl ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
    const form = document.getElementById('dbMaintForm');
    const warningEl = document.getElementById('dbMaintWarning');
    const actionInput = document.getElementById('dbMaintAction');
    const submitBtn = document.getElementById('dbMaintSubmitBtn');

    async function parseJsonResponse(r) {
        const text = await r.text();
        let body;
        try {
            body = JSON.parse(text);
        } catch {
            const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 180);
            throw new Error(snippet
                ? 'Server returned an invalid response: ' + snippet
                : 'Server returned an invalid response.');
        }
        if (!r.ok && body && !body.error) {
            body.error = 'Request failed (HTTP ' + r.status + ').';
        }
        return body;
    }

    function reloadDbMaintPage() {
        fetch('pages/' + page + '.php')
            .then(r => r.text())
            .then(h => applyMainContent(h))
            .catch(() => showToast('Failed to refresh page.', 'danger'));
    }

    const createFullBackupBtn = document.getElementById('createFullBackupBtn');
    if (createFullBackupBtn) {
        const defaultHtml = createFullBackupBtn.innerHTML;
        createFullBackupBtn.addEventListener('click', () => {
            createFullBackupBtn.disabled = true;
            createFullBackupBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Creating...';
            const fd = new FormData();
            fd.append('action', 'create_full_backup');
            fetch('pages/' + page + '.php', { method: 'POST', body: fd })
                .then(r => parseJsonResponse(r))
                .then(res => {
                    if (res.error) {
                        showToast(res.error, 'danger');
                        return;
                    }
                    const warn = res.integrity && res.integrity.valid === false;
                    showToast(res.message || 'Full backup created.', warn ? 'warning' : 'success');
                    if (res.download_url) {
                        const a = document.createElement('a');
                        a.href = res.download_url;
                        a.style.display = 'none';
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                    }
                    reloadDbMaintPage();
                })
                .catch(err => showToast(err.message || 'Full backup failed.', 'danger'))
                .finally(() => {
                    createFullBackupBtn.disabled = false;
                    createFullBackupBtn.innerHTML = defaultHtml;
                });
        });
    }

    const fullRestoreForm = document.getElementById('fullRestoreForm');
    const fullRestoreBtn = document.getElementById('fullRestoreBtn');
    if (fullRestoreForm && fullRestoreBtn) {
        const restoreDefaultHtml = fullRestoreBtn.innerHTML;
        fullRestoreForm.addEventListener('submit', e => {
            e.preventDefault();
            if (!document.getElementById('fullConfirmRestore').checked) {
                showToast('Please confirm full restore will replace schema and data.', 'warning');
                return;
            }
            if (!confirm('This will DROP/CREATE tables and reload all data. Zip packages also replace attachments and system config files. Continue?')) {
                return;
            }
            fullRestoreBtn.disabled = true;
            fullRestoreBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Restoring...';
            fetch('pages/' + page + '.php', { method: 'POST', body: new FormData(fullRestoreForm) })
                .then(r => parseJsonResponse(r))
                .then(res => {
                    if (res.error) {
                        showToast(res.error, 'danger');
                        return;
                    }
                    showToast(res.message || 'Full restore completed.', 'success');
                    setTimeout(reloadDbMaintPage, 1500);
                })
                .catch(err => showToast(err.message || 'Full restore failed.', 'danger'))
                .finally(() => {
                    fullRestoreBtn.disabled = false;
                    fullRestoreBtn.innerHTML = restoreDefaultHtml;
                });
        });
    }

    const actionLabels = {
        clear_transactions: 'Clear All Transactions',
        clear_tasks: 'Clear Tasks',
        clear_budgets: 'Clear Budgets',
        clear_accounts_funds: 'Clear Accounts & Funds',
        clear_categories: 'Clear Categories',
        clear_all_financial: 'Clear All Financial Data',
        reset_users: 'Reset Users to Default',
    };

    const actionWarnings = {
        clear_transactions: 'This will permanently delete all transaction lines, transaction details, and on-disk attachment files.',
        clear_tasks: 'This will permanently delete all tasks.',
        clear_budgets: 'This will permanently delete all budget lines and budgets.',
        clear_accounts_funds: 'This will permanently delete all accounts and funds.',
        clear_categories: 'This will permanently delete all natural and functional categories, then restore default example categories.',
        clear_all_financial: 'This will permanently delete ALL financial data including transactions, budgets, accounts, funds, categories, tasks, and on-disk attachment files, then restore default example categories.',
        reset_users: 'This will delete ALL users, recreate only admin/password, restore default category examples, download the audit log, clear the audit log, and log you out.',
    };

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showConfirmToast(message, onConfirm) {
        if (!toastContainer || typeof bootstrap === 'undefined' || !bootstrap.Toast) {
            if (window.confirm(message)) onConfirm();
            return;
        }
        const el = document.createElement('div');
        el.className = 'toast align-items-center text-bg-warning border-0';
        el.setAttribute('role', 'alert');
        el.innerHTML = `
            <div class="d-flex flex-column gap-2 p-2">
                <div class="toast-body pb-0">${escapeHtml(message)}</div>
                <div class="d-flex gap-2 justify-content-end px-2 pb-1">
                    <button type="button" class="btn btn-sm btn-light db-maint-toast-cancel">Cancel</button>
                    <button type="button" class="btn btn-sm btn-dark db-maint-toast-confirm">Confirm</button>
                </div>
            </div>`;
        toastContainer.appendChild(el);
        const toast = new bootstrap.Toast(el, { autohide: false });
        const cleanup = () => { toast.hide(); };

        el.querySelector('.db-maint-toast-cancel').addEventListener('click', cleanup, { once: true });
        el.querySelector('.db-maint-toast-confirm').addEventListener('click', () => {
            cleanup();
            onConfirm();
        }, { once: true });
        el.addEventListener('hidden.bs.toast', () => el.remove(), { once: true });
        toast.show();
    }

    const resetBtn = document.getElementById('resetUsersBtn');
    const resetBlocker = document.getElementById('resetUsersBlocker');
    const resetBlockerDetail = document.getElementById('resetUsersBlockerDetail');
    const clearButtons = document.querySelectorAll('.db-clear-btn');

    function applyMaintenanceState(state) {
        if (!state) return;

        const backupOk = !!state.backupAllowed;
        const resetOk = !!state.resetUsersAllowed;
        const financialCleared = !!state.financialDataCleared;

        clearButtons.forEach(btn => { btn.disabled = !backupOk; });

        if (resetBtn) {
            resetBtn.disabled = !resetOk;
        }

        if (resetBlocker) {
            if (backupOk && !financialCleared) {
                resetBlocker.classList.remove('d-none');
                if (resetBlockerDetail) {
                    const tables = state.tablesWithData || [];
                    resetBlockerDetail.textContent = tables.length
                        ? 'Tables with data: ' + tables.join(', ')
                        : '';
                }
            } else {
                resetBlocker.classList.add('d-none');
            }
        }
    }

    function fetchMaintenanceState() {
        return fetch(`pages/${page}.php?action=state&_=${Date.now()}`)
            .then(r => r.json())
            .then(state => {
                applyMaintenanceState(state);
                return state;
            });
    }

    function postAction(data) {
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        return fetch(`pages/${page}.php`, { method: 'POST', body: fd })
            .then(r => r.json().then(body => {
                if (!r.ok && !body.error) {
                    body.error = 'Request failed (HTTP ' + r.status + ').';
                }
                return body;
            }));
    }

    function openConfirm(action) {
        actionInput.value = action;
        document.getElementById('dbMaintModalLabel').innerHTML =
            '<i class="bi bi-shield-lock"></i> Confirm: ' + (actionLabels[action] || action);
        warningEl.innerHTML =
            '<i class="bi bi-exclamation-octagon-fill"></i> <strong>' + (actionLabels[action] || action) + ':</strong> ' +
            (actionWarnings[action] || 'This action is irreversible.');
        document.getElementById('dbMaintPin').value = '';
        document.getElementById('dbMaintPassword').value = '';
        submitBtn.className = action === 'reset_users' ? 'btn btn-warning btn-sm' : 'btn btn-danger btn-sm';
        if (!modal) return;
        if (modalEl && typeof window.mountModalOnBody === 'function') {
            modalEl = window.mountModalOnBody(modalEl);
        }
        modal.show();
    }

    document.querySelectorAll('.db-clear-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.disabled) return;
            const action = btn.dataset.action;
            const label = actionLabels[action] || action;
            showConfirmToast('Run "' + label + '"? This cannot be undone.', () => openConfirm(action));
        });
    });

    fetchMaintenanceState().catch(() => {});

    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            if (resetBtn.disabled) return;
            showConfirmToast('Reset ALL users to the default admin account? You will be logged out immediately.', () => {
                showConfirmToast('Final confirmation: this deletes every user account. Continue?', () => openConfirm('reset_users'));
            });
        });
    }

    if (form) {
        form.addEventListener('submit', e => {
            e.preventDefault();
            submitBtn.disabled = true;

            postAction(Object.fromEntries(new FormData(form).entries()))
                .then(res => {
                    if (res.error) {
                        window.showToast(res.error, 'danger', 6000);
                        return;
                    }

                    modal.hide();

                    if (res.logout && res.audit_download) {
                        window.showToast(res.message || 'Users reset. Downloading audit log...', 'warning', 6000);
                        const iframe = document.createElement('iframe');
                        iframe.style.display = 'none';
                        iframe.src = res.audit_download;
                        document.body.appendChild(iframe);
                        setTimeout(() => {
                            window.location.href = 'logout.php';
                        }, 1500);
                        return;
                    }

                    window.showToast(res.message || 'Action completed successfully.', 'success');
                    if (res.state) {
                        applyMaintenanceState(res.state);
                    } else {
                        fetchMaintenanceState().catch(() => window.showToast('Action completed, but state could not be refreshed.', 'warning'));
                    }
                })
                .catch(() => window.showToast('Request failed. Please try again.', 'danger', 6000))
                .finally(() => { submitBtn.disabled = false; });
        });
    }
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-admin-database-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>
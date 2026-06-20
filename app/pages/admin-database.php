<?php
    // Admin Database Maintenance - Inner content only for AJAX loading

    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../auth.php';
    require_once __DIR__ . '/../includes/audit.php';

    if (!isLoggedIn()) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Unauthorized';
        exit;
    }

    if (!isset($db)) {
        $db = getDbConnection();
    }

    define('MAINTENANCE_PIN', '464546');
    define('DEFAULT_ADMIN_PASSWORD_HASH', '$2y$12$hElsAKEKx9CLXDqzYsxEeOLXq2V7vr.OY1qgi2RjTq19MqWII.Ute');

    $backupDir = __DIR__ . '/../storage/backups';
    $exportDir = __DIR__ . '/../storage/exports';
    if (!is_dir($exportDir)) {
        mkdir($exportDir, 0750, true);
    }

    $clearActionTables = [
        'clear_transactions' => ['transaction_lines', 'transaction_details'],
        'clear_tasks' => ['tasks'],
        'clear_budgets' => ['budget_lines', 'budgets'],
        'clear_accounts_funds' => ['accounts', 'funds'],
        'clear_categories' => ['functional_categories', 'natural_categories'],
        'clear_all_financial' => [
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
        $backups = listBackupFiles($backupDir);
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

    function listBackupFiles(string $dir): array {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }
        foreach (glob($dir . '/backup_*.sql') as $path) {
            $files[] = [
                'name' => basename($path),
                'modified' => filemtime($path),
            ];
        }
        usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);
        return $files;
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
        header('Content-Type: application/json');

        $postAction = $_POST['action'] ?? '';
        $pin = $_POST['pin'] ?? '';
        $password = $_POST['password'] ?? '';
        $user = getCurrentUser();
        $username = $_SESSION['username'] ?? ($user['name'] ?? 'unknown');
        $userId = $user ? (int)$user['id'] : null;

        $backups = listBackupFiles($backupDir);
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
            if (!$db->query('TRUNCATE TABLE users')) {
                $db->query('DELETE FROM users');
            }
            $db->query('SET FOREIGN_KEY_CHECKS = 1');

            $stmt = $db->prepare(
                'INSERT INTO users (role_id, username, first_name, last_name, email, password, is_active)
                 VALUES (1, ?, ?, ?, ?, ?, TRUE)'
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
            $stmt->close();

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
        border-bottom-color: rgba(0, 0, 0, 0.06);
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
        color: #495057;
    }
    .db-maint-btn {
        text-align: left;
    }
</style>

<div class="row mb-3 align-items-center">
    <div class="col-md-8">
        <h2 class="h4 mb-0">Database Maintenance</h2>
        <p class="text-muted small mb-0">Destructive utilities for clearing test data and resetting users</p>
    </div>
    <div class="col-md-4 text-md-end mt-2 mt-md-0">
        <a href="javascript:void(0)" onclick="loadPage('admin')" class="small text-decoration-none">
            <i class="bi bi-arrow-left"></i> Back to Admin
        </a>
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
                    Affects: accounts, budget_lines, budgets, functional_categories, funds, natural_categories, tasks, transaction_details, transaction_lines.
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
                <div class="modal-header py-2 bg-danger text-white">
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
    const modalEl = document.getElementById('dbMaintModal');
    const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
    const form = document.getElementById('dbMaintForm');
    const warningEl = document.getElementById('dbMaintWarning');
    const actionInput = document.getElementById('dbMaintAction');
    const submitBtn = document.getElementById('dbMaintSubmitBtn');

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
        clear_transactions: 'This will permanently delete all transaction lines and transaction details.',
        clear_tasks: 'This will permanently delete all tasks.',
        clear_budgets: 'This will permanently delete all budget lines and budgets.',
        clear_accounts_funds: 'This will permanently delete all accounts and funds.',
        clear_categories: 'This will permanently delete all natural and functional categories, then restore default example categories.',
        clear_all_financial: 'This will permanently delete ALL financial data including transactions, budgets, accounts, funds, categories, and tasks, then restore default example categories.',
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
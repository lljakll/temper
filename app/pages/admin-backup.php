<?php
    // Admin Backup & Restore - Inner content only for AJAX loading

    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../auth.php';

    if (!isLoggedIn()) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Unauthorized';
        exit;
    }

    if (!isset($db)) {
        $db = getDbConnection();
    }

    $backupDir = __DIR__ . '/../storage/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0750, true);
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

    function safeBackupFilename(string $filename): ?string {
        $base = basename($filename);
        if (!preg_match('/^backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.sql$/', $base)) {
            return null;
        }
        return $base;
    }

    function listBackupFiles(string $dir): array {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }
        foreach (glob($dir . '/backup_*.sql') as $path) {
            $name = basename($path);
            $files[] = [
                'name' => $name,
                'size' => filesize($path),
                'modified' => filemtime($path),
            ];
        }
        usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);
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
            $sql = generateDatabaseBackup($db);
            $filename = 'backup_' . date('Y-m-d_His') . '.sql';
            $path = $backupDir . '/' . $filename;
            file_put_contents($path, $sql);

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
            header('Content-Type: application/json');

            $sql = generateDatabaseBackup($db);
            if (trim($sql) === '') {
                echo json_encode(['error' => 'Backup generated empty content.']);
                $db->close();
                exit;
            }

            $filename = 'backup_' . date('Y-m-d_His') . '.sql';
            $path = $backupDir . '/' . $filename;
            $written = file_put_contents($path, $sql);
            if ($written === false) {
                echo json_encode(['error' => 'Could not save backup file on the server.']);
                $db->close();
                exit;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Backup created successfully: ' . $filename,
                'file' => $filename,
                'size' => $written,
                'download_url' => 'pages/admin-backup.php?action=download&file=' . urlencode($filename),
            ]);
            $db->close();
            exit;
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

            if (!unlink($path)) {
                echo json_encode(['error' => 'Could not delete backup file.']);
                $db->close();
                exit;
            }

            lockBackup($safe);
            echo json_encode(['success' => true, 'message' => 'Backup deleted permanently.', 'file' => $safe]);
            $db->close();
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
        header('Content-Type: application/json');

        if (empty($_POST['confirm_restore'])) {
            echo json_encode(['error' => 'You must confirm that you understand restore will overwrite current data.']);
            $db->close();
            exit;
        }

        if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => 'Please select a valid .sql backup file to upload.']);
            $db->close();
            exit;
        }

        $upload = $_FILES['backup_file'];
        $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            echo json_encode(['error' => 'Only .sql backup files are supported.']);
            $db->close();
            exit;
        }

        if ($upload['size'] > 50 * 1024 * 1024) {
            echo json_encode(['error' => 'Backup file is too large (max 50 MB).']);
            $db->close();
            exit;
        }

        $sql = file_get_contents($upload['tmp_name']);
        if ($sql === false || trim($sql) === '') {
            echo json_encode(['error' => 'Backup file is empty or could not be read.']);
            $db->close();
            exit;
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        if ($db->multi_query($sql)) {
            do {
                if ($result = $db->store_result()) {
                    $result->free();
                }
            } while ($db->more_results() && $db->next_result());
        }

        if ($db->errno) {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
            echo json_encode(['error' => 'Restore failed: ' . $db->error]);
            $db->close();
            exit;
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $archiveName = 'restored_' . date('Y-m-d_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $upload['name']);
        copy($upload['tmp_name'], $backupDir . '/' . $archiveName);

        echo json_encode(['success' => true, 'message' => 'Database restored successfully.']);
        $db->close();
        exit;
    }

    $backups = listBackupFiles($backupDir);
    $unlockedBackups = getUnlockedBackups();
    $tableCount = (int)($db->query('SHOW TABLES')->num_rows ?? 0);
?>

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
                            <?php $isUnlocked = in_array($backup['name'], $unlockedBackups, true); ?>
                            <div class="list-group-item px-0 py-2 backup-row" data-file="<?= htmlspecialchars($backup['name']) ?>">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="small flex-grow-1">
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <span class="fw-semibold"><?= htmlspecialchars($backup['name']) ?></span>
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
                                            <?= date('M j, Y g:i A', $backup['modified']) ?>
                                            &middot; <?= number_format($backup['size'] / 1024, 1) ?> KB
                                        </div>
                                    </div>
                                    <div class="d-flex gap-1 flex-shrink-0 backup-actions">
                                        <a href="pages/admin-backup.php?action=download&amp;file=<?= urlencode($backup['name']) ?>"
                                           class="btn btn-outline-secondary btn-sm" title="Download">
                                            <i class="bi bi-download"></i>
                                        </a>
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

    function reloadPage() {
        fetch(`pages/${page}.php`)
            .then(r => r.text())
            .then(h => applyMainContent(h))
            .catch(() => showToast('Failed to refresh page.', 'danger'));
    }

    function postAction(data) {
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        return fetch(`pages/${page}.php`, { method: 'POST', body: fd })
            .then(r => r.json().then(body => {
                if (!r.ok && body && !body.error) {
                    body.error = 'Request failed (HTTP ' + r.status + ').';
                }
                return body;
            }));
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
                    showToast(res.message || 'Backup created successfully.', 'success');
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
                .catch(() => showToast('Backup creation failed. Please try again.', 'danger'))
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
            .then(r => r.json())
            .then(res => {
                if (res.error) {
                    showToast(res.error, 'danger');
                    return;
                }
                showToast(res.message || 'Database restored successfully.', 'success');
                setTimeout(reloadPage, 1500);
            })
            .catch(() => showToast('Restore failed. Please try again.', 'danger'))
            .finally(() => {
                restoreBtn.disabled = false;
                restoreBtn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Restore Database';
            });
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-admin-backup-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>
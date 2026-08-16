<?php
// Admin Backup & Restore — data-only (SQL / CSV / Both). Administrator role required.
// Full schema+data dumps live under Database Maintenance.
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/backup_utils.php';
require_once __DIR__ . '/../includes/system_config.php';

$actor = requireAdministrator($db, 'Only administrators can use Backup & Restore.');

@ini_set('pcre.jit', '0');

// Opportunistic auto-backup when an admin opens this page
maybeRunAutoBackup($db);

$backupDirInfo = ensureStorageSubdir('backups');
$backupDir = $backupDirInfo['path'];

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

function backupDownloadContentType(string $filename): string {
    $kind = backupFileKind($filename);
    if ($kind === 'data_csv') {
        return 'application/zip';
    }
    return 'application/sql';
}

// ── Downloads ───────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'download' && isset($_GET['file'])) {
        $safe = safeBackupFilename($_GET['file']);
        if (!$safe) {
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

    if ($action === 'download_checksum' && isset($_GET['file'])) {
        $safe = safeBackupFilename($_GET['file']);
        if (!$safe) {
            header('HTTP/1.1 400 Bad Request');
            exit;
        }

        $checksumName = backupChecksumFilename($safe);
        $checksumPath = rtrim($backupDir, '/\\') . '/' . $checksumName;
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

// ── POST actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'unlock_backup') {
        $safe = safeBackupFilename($_POST['file'] ?? '');
        $password = $_POST['password'] ?? '';
        $user = getCurrentUser();

        if (!$safe) {
            sendJsonResponse(['error' => 'Invalid backup file.'], $db);
        }
        if (!is_file(rtrim($backupDir, '/\\') . '/' . $safe)) {
            sendJsonResponse(['error' => 'Backup file not found.'], $db);
        }
        if ($password === '') {
            sendJsonResponse(['error' => 'Password is required to unlock a backup.'], $db);
        }
        if (!$user || !verifyCurrentUserPassword($db, (int)$user['id'], $password)) {
            sendJsonResponse(['error' => 'Incorrect password. Backup remains locked.'], $db);
        }

        unlockBackup($safe);
        sendJsonResponse([
            'success' => true,
            'message' => 'Backup unlocked. You may now delete it.',
            'file' => $safe,
        ], $db);
    }

    if ($postAction === 'create_backup') {
        $format = strtolower(trim((string)($_POST['format'] ?? 'sql')));
        if (!in_array($format, ['sql', 'csv', 'both'], true)) {
            sendJsonResponse(['error' => 'Invalid format. Choose sql, csv, or both.'], $db);
        }

        if ($backupDirInfo['error'] !== null) {
            sendJsonResponse(['error' => 'Backup storage is not writable: ' . $backupDirInfo['error']], $db);
        }

        ob_start();
        $result = createDataOnlyBackup($db, $format);
        ob_end_clean();

        if (!$result['success']) {
            sendJsonResponse(['error' => $result['error'] ?? 'Backup failed.'], $db);
        }

        $filesOut = [];
        $allValid = true;
        $summaries = [];
        foreach ($result['files'] ?? [] as $fileMeta) {
            $name = $fileMeta['file'] ?? '';
            $inspection = inspectBackupFile($backupDir, $name);
            $valid = (bool)($inspection['valid'] ?? false);
            if (!$valid) {
                $allValid = false;
            }
            $summaries[] = (string)($inspection['summary'] ?? '');
            $filesOut[] = [
                'file' => $name,
                'size' => (int)($fileMeta['size'] ?? 0),
                'checksum' => $fileMeta['checksum'] ?? null,
                'kind' => $fileMeta['kind'] ?? backupFileKind($name),
                'kind_label' => backupKindLabel($name),
                'download_url' => 'pages/admin-backup.php?action=download&file=' . urlencode($name),
                'checksum_url' => 'pages/admin-backup.php?action=download_checksum&file=' . urlencode($name),
                'integrity' => [
                    'valid' => $valid,
                    'summary' => (string)($inspection['summary'] ?? ''),
                ],
            ];
        }

        $message = $allValid
            ? 'Data-only backup created and verified ✓'
            : 'Data-only backup created with integrity warnings';

        sendJsonResponse([
            'success' => true,
            'message' => $message,
            'files' => $filesOut,
            // Primary download = first file (compat with older JS)
            'file' => $filesOut[0]['file'] ?? null,
            'download_url' => $filesOut[0]['download_url'] ?? null,
            'checksum_url' => $filesOut[0]['checksum_url'] ?? null,
            'integrity' => [
                'valid' => $allValid,
                'summary' => implode(' · ', array_filter($summaries)),
            ],
        ], $db);
    }

    if ($postAction === 'delete_backup') {
        $safe = safeBackupFilename($_POST['file'] ?? '');

        if (!$safe) {
            sendJsonResponse(['error' => 'Invalid backup file.'], $db);
        }
        if (!isBackupUnlocked($safe)) {
            sendJsonResponse(['error' => 'This backup is locked. Unlock it with your password before deleting.'], $db);
        }

        $path = rtrim($backupDir, '/\\') . '/' . $safe;
        if (!is_file($path)) {
            lockBackup($safe);
            sendJsonResponse(['error' => 'Backup file not found.'], $db);
        }

        $delete = deleteStorageFile($path);
        if (!$delete['success']) {
            sendJsonResponse(['error' => $delete['error']], $db);
        }

        $checksumPath = rtrim($backupDir, '/\\') . '/' . backupChecksumFilename($safe);
        if (is_file($checksumPath)) {
            deleteStorageFile($checksumPath);
        }

        lockBackup($safe);
        sendJsonResponse(['success' => true, 'message' => 'Backup deleted permanently.', 'file' => $safe], $db);
    }

    if ($postAction === 'restore') {
        if (empty($_POST['confirm_restore'])) {
            sendJsonResponse(['error' => 'You must confirm that you understand restore will overwrite current data.'], $db);
        }

        if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $uploadError = $_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $uploadMessages = [
                UPLOAD_ERR_INI_SIZE => 'Backup file exceeds server upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'Backup file exceeds form upload limit.',
                UPLOAD_ERR_PARTIAL => 'Backup file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'Please select a valid data-only backup (.sql or .zip).',
                UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder for uploads.',
                UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded backup to disk.',
                UPLOAD_ERR_EXTENSION => 'A server extension blocked the backup upload.',
            ];
            sendJsonResponse([
                'error' => $uploadMessages[$uploadError] ?? 'Please select a valid data-only backup (.sql or .zip).',
            ], $db);
        }

        $upload = $_FILES['backup_file'];
        $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['sql', 'zip'], true)) {
            sendJsonResponse(['error' => 'Only .sql (data-only) or .zip (CSV data-only) backups are supported here. Full schema dumps are restored from Database Maintenance.'], $db);
        }

        if ($upload['size'] > 50 * 1024 * 1024) {
            sendJsonResponse(['error' => 'Backup file is too large (max 50 MB).'], $db);
        }

        $raw = file_get_contents($upload['tmp_name']);
        if ($raw === false || $raw === '') {
            sendJsonResponse(['error' => 'Backup file is empty or could not be read.'], $db);
        }

        if ($ext === 'sql') {
            // Reject full schema dumps on this page
            $cleaned = sanitizeSqlBackupContent($raw);
            $validation = validateSqlBackupContent($raw, $cleaned, 'data');
            if (!$validation['valid']) {
                sendJsonResponse(['error' => formatSqlValidationError($validation)], $db);
            }

            $archive = archiveRestoredBackup($upload['name'], $cleaned, 'sql');
            $restore = restoreDataOnlySql($db, $raw);
            if (!$restore['success']) {
                sendJsonResponse(['error' => $restore['error'] ?? 'Restore failed.'], $db);
            }

            $message = 'Data restored successfully from SQL backup.';
            if (!$archive['success']) {
                $message .= ' Warning: uploaded backup could not be archived (' . ($archive['error'] ?? 'unknown') . ').';
            } else {
                $message .= ' Archived as ' . $archive['filename'] . '.';
            }
            sendJsonResponse(['success' => true, 'message' => $message, 'archive' => $archive], $db);
        }

        // CSV zip
        $tmpZip = $upload['tmp_name'];
        $integrity = inspectCsvZipBackupIntegrity($tmpZip);
        if (!$integrity['valid']) {
            sendJsonResponse(['error' => $integrity['summary'] ?? 'Invalid CSV zip backup.'], $db);
        }

        $archive = archiveRestoredBackup($upload['name'], $raw, 'zip');
        $restore = restoreDataOnlyCsvZip($db, $tmpZip);
        if (!$restore['success']) {
            sendJsonResponse(['error' => $restore['error'] ?? 'CSV restore failed.'], $db);
        }

        $tableList = !empty($restore['tables']) ? implode(', ', $restore['tables']) : 'tables';
        $message = 'Data restored successfully from CSV backup (' . $tableList . ').';
        if (!$archive['success']) {
            $message .= ' Warning: uploaded backup could not be archived (' . ($archive['error'] ?? 'unknown') . ').';
        } else {
            $message .= ' Archived as ' . $archive['filename'] . '.';
        }
        sendJsonResponse(['success' => true, 'message' => $message, 'archive' => $archive], $db);
    }

    sendJsonResponse(['error' => 'Unknown action.'], $db);
}

pruneUnlockedBackups($backupDir);
// List data-only backups primarily; also show full files that may exist for download/delete
$backups = listBackupFiles($backupDir, true, null);
// Prefer showing data-only first in the UI list, keep all for management
$unlockedBackups = getUnlockedBackups();
$tableCount = count(listDataOnlyBackupTables($db));
$autoEnabled = isAutoBackupEnabled();
$autoFreq = getAutoBackupFrequency();
$autoFormat = getAutoBackupFormat();
$autoState = loadAutoBackupState();
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
        <h2 class="h4 mb-0">Backup &amp; Restore</h2>
        <p class="text-muted small mb-0">
            Data-only export/import for <?= htmlspecialchars(DB_NAME) ?>
            (no schema). Full schema dumps are under
            <a href="javascript:void(0)" onclick="loadPage('admin-database')" class="text-decoration-none">Database Maintenance</a>.
        </p>
    </div>
    <div class="col-md-4 text-md-end mt-2 mt-md-0">
        <a href="javascript:void(0)" onclick="loadPage('admin')" class="small text-decoration-none">
            <i class="bi bi-arrow-left"></i> Back to System
        </a>
    </div>
</div>

<?php if ($autoEnabled): ?>
<div class="alert alert-info py-2 small mb-3">
    <i class="bi bi-clock-history"></i>
    <strong>Auto-Backup is on</strong>
    (<?= htmlspecialchars($autoFreq) ?>, format: <?= htmlspecialchars($autoFormat) ?>).
    <?php if (!empty($autoState['last_run'])): ?>
        Last run: <?= htmlspecialchars($autoState['last_run']) ?>
        <?php if (($autoState['last_status'] ?? '') === 'ok'): ?>
            <span class="text-success">✓</span>
        <?php elseif (($autoState['last_status'] ?? '') === 'error'): ?>
            <span class="text-danger">failed</span>
            <?php if (!empty($autoState['last_error'])): ?>
                — <?= htmlspecialchars($autoState['last_error']) ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php else: ?>
        No auto-backup has run yet.
    <?php endif; ?>
    Configure under
    <a href="javascript:void(0)" onclick="loadPage('admin-config')" class="alert-link">System Configuration</a>.
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header text-bg-primary py-2">
                <i class="bi bi-cloud-arrow-down"></i> <span class="fw-semibold">Data-Only Backup</span>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    Export row data from <strong><?= $tableCount ?></strong> operational tables without CREATE/DROP statements.
                    <code>app_version</code> and <code>audit_log</code> are omitted so a restore keeps the current version history and audit trail.
                    Roles and all other operational tables are included.
                    Choose SQL (INSERT dump), CSV (zip of per-table CSVs), or both.
                    Files are stored in <code>storage/backups/</code> and can be downloaded.
                </p>

                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="backupFormat">Format</label>
                    <select class="form-select form-select-sm" id="backupFormat" style="max-width: 14rem;">
                        <option value="sql" selected>SQL (data-only)</option>
                        <option value="csv">CSV (zip)</option>
                        <option value="both">Both SQL and CSV</option>
                    </select>
                </div>

                <button type="button" class="btn btn-primary btn-sm" id="createBackupBtn">
                    <i class="bi bi-download"></i> Create &amp; Download Backup
                </button>

                <div id="backupListSection">
                <?php if (count($backups) > 0): ?>
                    <hr class="my-3">
                    <h6 class="small fw-semibold text-uppercase text-muted mb-1">Saved Backups</h6>
                    <p class="text-muted small mb-2">
                        <i class="bi bi-lock-fill"></i>
                        Backups are <strong>locked</strong> by default. Unlock with your password to enable deletion.
                    </p>
                    <div class="list-group list-group-flush" id="backupList">
                        <?php foreach (array_slice($backups, 0, 12) as $backup): ?>
                            <?php
                                $isUnlocked = in_array($backup['name'], $unlockedBackups, true);
                                $integrity = is_array($backup['integrity'] ?? null) ? $backup['integrity'] : [];
                                $integrityValid = (bool)($integrity['valid'] ?? false);
                                $integritySummary = (string)($integrity['summary'] ?? 'Integrity status unavailable');
                                $checksum = is_string($backup['checksum'] ?? null) ? $backup['checksum'] : '';
                                $kindLabel = (string)($backup['kind_label'] ?? backupKindLabel($backup['name']));
                            ?>
                            <div class="list-group-item px-0 py-2 backup-row" data-file="<?= htmlspecialchars($backup['name']) ?>">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="small flex-grow-1 min-w-0">
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <span class="fw-semibold"><?= htmlspecialchars($backup['name']) ?></span>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= htmlspecialchars($kindLabel) ?></span>
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
                <i class="bi bi-cloud-arrow-up"></i> <span class="fw-semibold">Data-Only Restore</span>
            </div>
            <div class="card-body">
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="bi bi-exclamation-triangle"></i>
                    Restore will <strong>overwrite operational table data</strong> (TRUNCATE + load). Schema is not modified.
                    Existing <code>app_version</code> history and <code>audit_log</code> are left intact.
                    Create a backup first. Full schema restore is only available under Database Maintenance.
                </div>

                <form id="restoreForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="restore">
                    <div class="mb-3">
                        <label for="backupFile" class="form-label small fw-semibold">Data-only backup (.sql or .zip)</label>
                        <input type="file" class="form-control form-control-sm" id="backupFile" name="backup_file" accept=".sql,.zip" required>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirmRestore" name="confirm_restore" value="1">
                        <label class="form-check-label small" for="confirmRestore">
                            I understand this will replace current row data in the database.
                        </label>
                    </div>
                    <button type="submit" class="btn btn-warning btn-sm" id="restoreBtn">
                        <i class="bi bi-arrow-counterclockwise"></i> Restore Data
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
    let unlockModalEl = document.getElementById('unlockBackupModal');
    if (unlockModalEl && typeof window.mountModalOnBody === 'function') {
        unlockModalEl = window.mountModalOnBody(unlockModalEl);
    }
    const unlockModal = unlockModalEl ? bootstrap.Modal.getOrCreateInstance(unlockModalEl) : null;
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

    function triggerDownload(url) {
        if (!url) return;
        const link = document.createElement('a');
        link.href = url;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    if (createBackupBtn) {
        createBackupBtn.addEventListener('click', () => {
            createBackupBtn.disabled = true;
            createBackupBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Creating...';
            const formatEl = document.getElementById('backupFormat');
            const format = formatEl ? formatEl.value : 'sql';

            postAction({ action: 'create_backup', format })
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

                    const files = Array.isArray(res.files) ? res.files : [];
                    if (files.length > 0) {
                        files.forEach((f, idx) => {
                            if (f.download_url) {
                                setTimeout(() => triggerDownload(f.download_url), idx * 400);
                            }
                        });
                    } else if (res.download_url) {
                        triggerDownload(res.download_url);
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
            if (!unlockModal) return;
            if (unlockModalEl && typeof window.mountModalOnBody === 'function') {
                unlockModalEl = window.mountModalOnBody(unlockModalEl);
            }
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

    if (restoreForm) {
        restoreForm.addEventListener('submit', e => {
            e.preventDefault();
            if (!document.getElementById('confirmRestore').checked) {
                showToast('Please confirm that you understand restore will overwrite current data.', 'warning');
                return;
            }
            if (!confirm('This will overwrite current table data. Continue with data-only restore?')) {
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
                    showToast(res.message || 'Data restored successfully.', toastType);
                    setTimeout(reloadPage, 1500);
                })
                .catch(err => showToast(err.message || 'Restore failed. Please try again.', 'danger'))
                .finally(() => {
                    restoreBtn.disabled = false;
                    restoreBtn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Restore Data';
                });
        });
    }
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-admin-backup-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>

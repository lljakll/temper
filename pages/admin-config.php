<?php
/**
 * System — Configuration.
 * Administrator-only settings: Developer Mode (also controls application idle timeout),
 * auto-archive timer, and future preferences.
 * Settings persist under storage/config/system.json.
 * Login idle timeout: 10 min when Developer Mode is off; fully disabled when on
 * (host/system session cleaner ≈24 minutes still applies).
 */
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/system_config.php';
require_once __DIR__ . '/../includes/backup_utils.php';

// Strict: Administrator role only (not merely admin.config permission)
$actor = requireAdministrator($db, 'Only administrators can change system configuration.');

// Opportunistic data-only auto-backup when admin visits configuration
maybeRunAutoBackup($db);

function configSendJson(array $payload, ?mysqli $db = null): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($db) {
        $db->close();
    }
    exit;
}

function configParseBool(mixed $raw): bool {
    return $raw === '1' || $raw === 1 || $raw === true || $raw === 'true' || $raw === 'on' || $raw === 'yes';
}

function configPayload(): array {
    $autoState = loadAutoBackupState();
    return [
        'settings' => systemConfigSettingsForUi(),
        'developer_mode' => isDeveloperModeEnabled(),
        'auto_archive_disabled' => !isAutoArchiveEnabled(),
        'auto_archive_timer_hours' => getAutoArchiveTimerHours(),
        'auto_archive_enabled' => isAutoArchiveEnabled(),
        // Login timeout: enabled only when Developer Mode is off (fixed 10 minutes)
        'login_timeout_disabled' => !isLoginTimeoutEnabled(),
        'login_timeout_enabled' => isLoginTimeoutEnabled(),
        'login_timeout_seconds' => getLoginTimeoutSeconds(),
        'login_timeout_label' => getLoginTimeoutDisplayLabel(),
        'sidebar_hover_expand_delay_seconds' => getSidebarHoverExpandDelaySeconds(),
        'sidebar_hover_collapse_delay_seconds' => getSidebarHoverCollapseDelaySeconds(),
        'auto_backup_enabled' => isAutoBackupConfigEnabled(),
        'auto_backup_frequency' => getAutoBackupConfigFrequency(),
        'auto_backup_format' => getAutoBackupConfigFormat(),
        'auto_backup_last_run' => $autoState['last_run'],
        'auto_backup_last_status' => $autoState['last_status'],
        'allow_hard_delete' => allowHardDeleteUsers(),
        'app_env' => (string)APP_ENV,
        'is_development_env' => isDevelopmentEnvironment(),
        'config_path' => getSystemConfigFilePath(),
    ];
}

// ── JSON API ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    $actorId = (int)$actor['id'];
    $actorUsername = (string)$actor['username'];

    if ($action === 'get_config') {
        configSendJson(array_merge(['success' => true], configPayload()), $db);
    }

    if ($action === 'save_config') {
        $updates = [];

        // Checked switch posts "1"; missing/0/false posts as Off. On = developer mode enabled.
        if (array_key_exists('developer_mode', $_POST)) {
            $updates['developer_mode'] = configParseBool($_POST['developer_mode']);
        } else {
            // Explicit false if client omits the key (unchecked checkbox pattern)
            // JS always sends 0|1; this is a safe default for non-JS posts.
        }

        if (array_key_exists('auto_archive_disabled', $_POST)) {
            $updates['auto_archive_disabled'] = configParseBool($_POST['auto_archive_disabled']);
        }

        if (array_key_exists('auto_archive_timer_hours', $_POST)) {
            $hours = (int)$_POST['auto_archive_timer_hours'];
            if ($hours < 1) {
                configSendJson([
                    'success' => false,
                    'error' => 'Auto-Archive Timer must be at least 1 hour.',
                ], $db);
            }
            if ($hours > 8760) {
                configSendJson([
                    'success' => false,
                    'error' => 'Auto-Archive Timer cannot exceed 8760 hours (1 year).',
                ], $db);
            }
            $updates['auto_archive_timer_hours'] = $hours;
        }

        // Login timeout is not user-editable. Ignore any posted disable/seconds keys.
        // Effective duration is derived from Developer Mode after save.

        if (array_key_exists('sidebar_hover_expand_delay_seconds', $_POST)) {
            $expandSec = (float)$_POST['sidebar_hover_expand_delay_seconds'];
            if ($expandSec < 0) {
                configSendJson([
                    'success' => false,
                    'error' => 'Sidebar Hover Expand Delay cannot be negative.',
                ], $db);
            }
            if ($expandSec > 10) {
                configSendJson([
                    'success' => false,
                    'error' => 'Sidebar Hover Expand Delay cannot exceed 10 seconds.',
                ], $db);
            }
            $updates['sidebar_hover_expand_delay_seconds'] = $expandSec;
        }

        if (array_key_exists('sidebar_hover_collapse_delay_seconds', $_POST)) {
            $collapseSec = (float)$_POST['sidebar_hover_collapse_delay_seconds'];
            if ($collapseSec < 0) {
                configSendJson([
                    'success' => false,
                    'error' => 'Sidebar Hover Collapse Delay cannot be negative.',
                ], $db);
            }
            if ($collapseSec > 30) {
                configSendJson([
                    'success' => false,
                    'error' => 'Sidebar Hover Collapse Delay cannot exceed 30 seconds.',
                ], $db);
            }
            $updates['sidebar_hover_collapse_delay_seconds'] = $collapseSec;
        }

        if (array_key_exists('auto_backup_enabled', $_POST)) {
            $updates['auto_backup_enabled'] = configParseBool($_POST['auto_backup_enabled']);
        }

        if (array_key_exists('auto_backup_frequency', $_POST)) {
            $freq = strtolower(trim((string)$_POST['auto_backup_frequency']));
            if (!in_array($freq, ['hourly', 'daily', 'weekly'], true)) {
                configSendJson([
                    'success' => false,
                    'error' => 'Auto-Backup Frequency must be hourly, daily, or weekly.',
                ], $db);
            }
            $updates['auto_backup_frequency'] = $freq;
        }

        if (array_key_exists('auto_backup_format', $_POST)) {
            $fmt = strtolower(trim((string)$_POST['auto_backup_format']));
            if (!in_array($fmt, ['sql', 'csv', 'both'], true)) {
                configSendJson([
                    'success' => false,
                    'error' => 'Auto-Backup Format must be sql, csv, or both.',
                ], $db);
            }
            $updates['auto_backup_format'] = $fmt;
        }

        // Accept any other catalog keys posted in the future
        foreach (temperSystemConfigCatalog() as $key => $meta) {
            if (isset($updates[$key])) {
                continue;
            }
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $updates[$key] = $_POST[$key];
        }

        if ($updates === []) {
            configSendJson(['success' => false, 'error' => 'No settings to save.'], $db);
        }

        $before = loadSystemConfig(true);
        $result = saveSystemConfig($updates);
        if (!$result['success']) {
            configSendJson(['success' => false, 'error' => $result['error'] ?? 'Save failed.'], $db);
        }

        $after = $result['settings'] ?? loadSystemConfig(true);
        $changed = [];
        foreach ($updates as $k => $v) {
            $prev = $before[$k] ?? null;
            $next = $after[$k] ?? $v;
            if ($prev !== $next) {
                $changed[] = $k . '=' . (is_bool($next) ? ($next ? 'true' : 'false') : (string)$next);
            }
        }
        $detail = $changed !== []
            ? 'Updated: ' . implode(', ', $changed)
            : 'Saved (no value change)';

        logAuditAction(
            $db,
            $actorId,
            $actorUsername,
            'system_config_update',
            $detail
        );

        configSendJson(array_merge([
            'success' => true,
            'message' => 'Configuration saved.',
        ], configPayload()), $db);
    }

    configSendJson(['success' => false, 'error' => 'Unknown action.'], $db);
}

// ── HTML ────────────────────────────────────────────────────────────────────
$payload = configPayload();
$developerMode = (bool)$payload['developer_mode'];
$autoArchiveDisabled = (bool)$payload['auto_archive_disabled'];
$autoArchiveHours = (int)$payload['auto_archive_timer_hours'];
$loginTimeoutSeconds = (int)$payload['login_timeout_seconds'];
$loginTimeoutLabel = (string)$payload['login_timeout_label'];
$sidebarHoverExpandSec = (float)$payload['sidebar_hover_expand_delay_seconds'];
$sidebarHoverCollapseSec = (float)$payload['sidebar_hover_collapse_delay_seconds'];
$autoBackupEnabled = (bool)$payload['auto_backup_enabled'];
$autoBackupFrequency = (string)$payload['auto_backup_frequency'];
$autoBackupFormat = (string)$payload['auto_backup_format'];
$autoBackupLastRun = $payload['auto_backup_last_run'] ?? null;
$autoBackupLastStatus = $payload['auto_backup_last_status'] ?? null;
$allowHardDelete = (bool)$payload['allow_hard_delete'];
$appEnv = (string)$payload['app_env'];
$isDevEnv = (bool)$payload['is_development_env'];
?>

<div class="row mb-3">
    <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <a href="javascript:void(0)" onclick="loadPage('admin')" class="small text-decoration-none">
                <i class="bi bi-arrow-left"></i> System
            </a>
            <h2 class="h4 mb-0 mt-1">Configuration</h2>
            <p class="text-muted small mb-0">Application settings. Extensible for future preferences.</p>
        </div>
        <span class="badge text-bg-secondary" title="Detected application environment">
            APP_ENV: <?= htmlspecialchars($appEnv) ?>
        </span>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header py-2 d-flex align-items-center gap-2">
                <i class="bi bi-sliders"></i>
                <span class="fw-semibold small">Settings</span>
            </div>
            <div class="card-body">
                <form id="systemConfigForm" autocomplete="off" data-dirty-track>
                    <!-- Development group -->
                    <h3 class="h6 text-uppercase text-muted mb-3" style="letter-spacing: 0.04em; font-size: 0.75rem;">
                        Development
                    </h3>

                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 p-3 rounded border mb-4">
                        <div class="flex-grow-1">
                            <label class="form-label fw-semibold mb-1" for="cfgDeveloperMode">Developer Mode</label>
                            <p class="small text-muted mb-0">
                                When <strong>On</strong>, enables development-only tools (for example, permanent user delete on Users &amp; Roles)
                                and <strong>disables</strong> the application idle login timeout (host/system session timeout ≈24 minutes still applies).
                                When <strong>Off</strong>, application login timeout is <strong>10 minutes</strong> with a 60-second warning.
                                Keep Off for normal production use.
                            </p>
                            <?php if ($developerMode && !$isDevEnv): ?>
                            <p class="small text-warning mb-0 mt-2" id="cfgDeveloperModeEnvWarn">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Developer Mode is on, but <code>APP_ENV</code> is not development —
                                hard delete remains blocked unless <code>ALLOW_HARD_DELETE=1</code> is set in the environment.
                            </p>
                            <?php else: ?>
                            <p class="small text-warning mb-0 mt-2 d-none" id="cfgDeveloperModeEnvWarn">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Developer Mode is on, but <code>APP_ENV</code> is not development —
                                hard delete remains blocked unless <code>ALLOW_HARD_DELETE=1</code> is set in the environment.
                            </p>
                            <?php endif; ?>
                        </div>
                        <div class="form-check form-switch m-0 pt-1 text-nowrap">
                            <!-- Checked = Developer Mode enabled (On). Not a "Disable …" control. -->
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="cfgDeveloperMode" name="developer_mode" value="1"
                                   aria-checked="<?= $developerMode ? 'true' : 'false' ?>"
                                   <?= $developerMode ? 'checked' : '' ?>>
                            <label class="form-check-label small fw-semibold" for="cfgDeveloperMode" id="cfgDeveloperModeLabel">
                                <?= $developerMode ? 'On' : 'Off' ?>
                            </label>
                        </div>
                    </div>

                    <!-- Users / security group -->
                    <h3 class="h6 text-uppercase text-muted mb-3" style="letter-spacing: 0.04em; font-size: 0.75rem;">
                        Users
                    </h3>

                    <div class="p-3 rounded border mb-3">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                            <div class="flex-grow-1">
                                <label class="form-label fw-semibold mb-1" for="cfgAutoArchiveDisabled">Disable Auto-Archive</label>
                                <p class="small text-muted mb-0">
                                    When on, accounts with force-password are never auto-archived.
                                    The timer field below is disabled while this is enabled.
                                </p>
                            </div>
                            <div class="form-check form-switch m-0 pt-1">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="cfgAutoArchiveDisabled" <?= $autoArchiveDisabled ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="cfgAutoArchiveDisabled" id="cfgAutoArchiveDisabledLabel">
                                    <?= $autoArchiveDisabled ? 'On' : 'Off' ?>
                                </label>
                            </div>
                        </div>

                        <div class="border-top pt-3" id="cfgAutoArchiveTimerWrap">
                            <label class="form-label fw-semibold mb-1" for="cfgAutoArchiveHours">
                                Auto-Archive Timer (hours)
                            </label>
                            <p class="small text-muted mb-2">
                                Hours after force-password is set (or restarted on restore / password reset)
                                before an incomplete account is auto-archived. New users with force-password
                                start their countdown at this value. Default 24.
                            </p>
                            <div class="row g-2 align-items-center">
                                <div class="col-auto">
                                    <input type="number" class="form-control form-control-sm"
                                           id="cfgAutoArchiveHours" name="auto_archive_timer_hours"
                                           min="1" max="8760" step="1"
                                           value="<?= (int)$autoArchiveHours ?>"
                                           style="width: 7rem;"
                                           <?= $autoArchiveDisabled ? 'disabled' : '' ?>>
                                </div>
                                <div class="col-auto small text-muted" id="cfgAutoArchiveHoursHint">
                                    hour<?= $autoArchiveHours === 1 ? '' : 's' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Backup / auto-backup group -->
                    <h3 class="h6 text-uppercase text-muted mb-3" style="letter-spacing: 0.04em; font-size: 0.75rem;">
                        Backup
                    </h3>

                    <div class="p-3 rounded border mb-4">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                            <div class="flex-grow-1">
                                <label class="form-label fw-semibold mb-1" for="cfgAutoBackupEnabled">Enable Auto-Backup</label>
                                <p class="small text-muted mb-0">
                                    When on, the system creates <strong>data-only</strong> backups on a schedule
                                    (SQL, CSV, or both) under <code>storage/backups/</code>.
                                    Full schema dumps remain a manual action on Database Maintenance.
                                    Auto-backup also runs opportunistically when an administrator opens System pages,
                                    and via the CLI script <code>scripts/auto_backup.php</code> (cron).
                                </p>
                                <?php if ($autoBackupLastRun): ?>
                                <p class="small text-muted mb-0 mt-2" id="cfgAutoBackupLastRun">
                                    Last auto-backup:
                                    <strong><?= htmlspecialchars((string)$autoBackupLastRun) ?></strong>
                                    <?php if ($autoBackupLastStatus === 'ok'): ?>
                                        <span class="badge text-bg-success">ok</span>
                                    <?php elseif ($autoBackupLastStatus === 'error'): ?>
                                        <span class="badge text-bg-danger">error</span>
                                    <?php endif; ?>
                                </p>
                                <?php else: ?>
                                <p class="small text-muted mb-0 mt-2" id="cfgAutoBackupLastRun">No auto-backup has run yet.</p>
                                <?php endif; ?>
                            </div>
                            <div class="form-check form-switch m-0 pt-1">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="cfgAutoBackupEnabled" <?= $autoBackupEnabled ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="cfgAutoBackupEnabled" id="cfgAutoBackupEnabledLabel">
                                    <?= $autoBackupEnabled ? 'On' : 'Off' ?>
                                </label>
                            </div>
                        </div>

                        <div class="border-top pt-3" id="cfgAutoBackupOptionsWrap">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-1" for="cfgAutoBackupFrequency">Frequency</label>
                                    <select class="form-select form-select-sm" id="cfgAutoBackupFrequency"
                                            <?= $autoBackupEnabled ? '' : 'disabled' ?>>
                                        <option value="hourly" <?= $autoBackupFrequency === 'hourly' ? 'selected' : '' ?>>Hourly</option>
                                        <option value="daily" <?= $autoBackupFrequency === 'daily' ? 'selected' : '' ?>>Daily</option>
                                        <option value="weekly" <?= $autoBackupFrequency === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-1" for="cfgAutoBackupFormat">Format</label>
                                    <select class="form-select form-select-sm" id="cfgAutoBackupFormat"
                                            <?= $autoBackupEnabled ? '' : 'disabled' ?>>
                                        <option value="sql" <?= $autoBackupFormat === 'sql' ? 'selected' : '' ?>>SQL (data-only)</option>
                                        <option value="csv" <?= $autoBackupFormat === 'csv' ? 'selected' : '' ?>>CSV (zip)</option>
                                        <option value="both" <?= $autoBackupFormat === 'both' ? 'selected' : '' ?>>Both</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Interface / sidebar group -->
                    <h3 class="h6 text-uppercase text-muted mb-3" style="letter-spacing: 0.04em; font-size: 0.75rem;">
                        Interface
                    </h3>

                    <div class="p-3 rounded border mb-4">
                        <p class="small text-muted mb-3">
                            Desktop sidebar: when collapsed to icons, hover can temporarily show labels.
                            These delays control how quickly that peek opens and closes.
                        </p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold mb-1" for="cfgSidebarHoverExpand">
                                    Sidebar Hover Expand Delay (seconds)
                                </label>
                                <p class="small text-muted mb-2">
                                    Wait this long after the pointer enters the collapsed rail before expanding labels.
                                    Default 0.5. Use 0 for immediate expand.
                                </p>
                                <div class="row g-2 align-items-center">
                                    <div class="col-auto">
                                        <input type="number" class="form-control form-control-sm"
                                               id="cfgSidebarHoverExpand" name="sidebar_hover_expand_delay_seconds"
                                               min="0" max="10" step="0.1"
                                               value="<?= htmlspecialchars(rtrim(rtrim(number_format($sidebarHoverExpandSec, 2, '.', ''), '0'), '.') ?: '0') ?>"
                                               style="width: 7rem;">
                                    </div>
                                    <div class="col-auto small text-muted">seconds</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold mb-1" for="cfgSidebarHoverCollapse">
                                    Sidebar Hover Collapse Delay (seconds)
                                </label>
                                <p class="small text-muted mb-2">
                                    After the pointer leaves, wait this long before collapsing back to icons.
                                    Default 2.0. Use 0 for immediate collapse. Click-off still collapses immediately.
                                </p>
                                <div class="row g-2 align-items-center">
                                    <div class="col-auto">
                                        <input type="number" class="form-control form-control-sm"
                                               id="cfgSidebarHoverCollapse" name="sidebar_hover_collapse_delay_seconds"
                                               min="0" max="30" step="0.1"
                                               value="<?= htmlspecialchars(rtrim(rtrim(number_format($sidebarHoverCollapseSec, 2, '.', ''), '0'), '.') ?: '0') ?>"
                                               style="width: 7rem;">
                                    </div>
                                    <div class="col-auto small text-muted">seconds</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <button type="submit" class="btn btn-primary btn-sm" id="cfgSaveBtn">
                            <i class="bi bi-check-lg"></i> Save configuration
                        </button>
                        <span class="small text-muted" id="cfgSaveStatus"></span>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header py-2">
                <span class="fw-semibold small"><i class="bi bi-info-circle me-1"></i> Status</span>
            </div>
            <div class="card-body small">
                <p class="text-uppercase text-muted mb-2" style="letter-spacing: 0.04em; font-size: 0.7rem;">Developer</p>
                <dl class="row mb-3">
                    <dt class="col-6 text-muted">Developer Mode</dt>
                    <dd class="col-6" id="cfgStatusDevMode">
                        <?php if ($developerMode): ?>
                            <span class="badge text-bg-warning">On</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">Off</span>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-6 text-muted">Hard delete users</dt>
                    <dd class="col-6" id="cfgStatusHardDelete">
                        <?php if ($allowHardDelete): ?>
                            <span class="badge text-bg-danger">Allowed</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">Blocked</span>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-6 text-muted">Login timeout</dt>
                    <dd class="col-6" id="cfgStatusLoginTimeout">
                        <?php if ($developerMode): ?>
                            <span class="badge text-bg-warning" title="Application idle timer off"><?= htmlspecialchars($loginTimeoutLabel) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-success" title="<?= (int)$loginTimeoutSeconds ?> seconds"><?= htmlspecialchars($loginTimeoutLabel) ?></span>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-6 text-muted">Environment</dt>
                    <dd class="col-6" id="cfgStatusEnvironment"><code><?= htmlspecialchars($appEnv) ?></code></dd>
                </dl>
                <div id="cfgStatusLoginTimeoutWarn" class="alert alert-warning py-2 px-2 small mb-3<?= $developerMode ? '' : ' d-none' ?>" role="status">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>Application timer disabled.</strong>
                    With Developer Mode on, the app idle timeout and warning modal are off.
                    The host/system session timeout (≈24 minutes) is still in effect and may end the session without the in-app warning.
                </div>
                <p class="text-uppercase text-muted mb-2" style="letter-spacing: 0.04em; font-size: 0.7rem;">Other</p>
                <dl class="row mb-0">
                    <dt class="col-6 text-muted">Auto-archive</dt>
                    <dd class="col-6" id="cfgStatusAutoArchive">
                        <?php if ($autoArchiveDisabled): ?>
                            <span class="badge text-bg-secondary">Disabled</span>
                        <?php else: ?>
                            <span class="badge text-bg-success"><?= (int)$autoArchiveHours ?>h</span>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-6 text-muted">Sidebar hover</dt>
                    <dd class="col-6" id="cfgStatusSidebarHover">
                        <span class="badge text-bg-success" title="Expand / collapse delay">
                            <?= htmlspecialchars(rtrim(rtrim(number_format($sidebarHoverExpandSec, 2, '.', ''), '0'), '.') ?: '0') ?>s
                            /
                            <?= htmlspecialchars(rtrim(rtrim(number_format($sidebarHoverCollapseSec, 2, '.', ''), '0'), '.') ?: '0') ?>s
                        </span>
                    </dd>
                    <dt class="col-6 text-muted">Auto-backup</dt>
                    <dd class="col-6" id="cfgStatusAutoBackup">
                        <?php if ($autoBackupEnabled): ?>
                            <span class="badge text-bg-success"><?= htmlspecialchars($autoBackupFrequency) ?> / <?= htmlspecialchars($autoBackupFormat) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">Off</span>
                        <?php endif; ?>
                    </dd>
                </dl>
                <hr>
                <p class="text-muted mb-1" style="font-size: 0.75rem;">
                    Settings file (for operators):
                </p>
                <code class="d-block text-break" style="font-size: 0.7rem;"><?= htmlspecialchars((string)$payload['config_path']) ?></code>
            </div>
        </div>
    </div>
</div>

<script type="text/plain" id="init-admin-config-script">
(function() {
    const endpoint = 'pages/admin-config.php';
    const form = document.getElementById('systemConfigForm');
    const toggle = document.getElementById('cfgDeveloperMode');
    const toggleLabel = document.getElementById('cfgDeveloperModeLabel');
    const disableArchive = document.getElementById('cfgAutoArchiveDisabled');
    const disableArchiveLabel = document.getElementById('cfgAutoArchiveDisabledLabel');
    const hoursInput = document.getElementById('cfgAutoArchiveHours');
    const hoursHint = document.getElementById('cfgAutoArchiveHoursHint');
    const sidebarExpandInput = document.getElementById('cfgSidebarHoverExpand');
    const sidebarCollapseInput = document.getElementById('cfgSidebarHoverCollapse');
    const autoBackupToggle = document.getElementById('cfgAutoBackupEnabled');
    const autoBackupToggleLabel = document.getElementById('cfgAutoBackupEnabledLabel');
    const autoBackupFrequency = document.getElementById('cfgAutoBackupFrequency');
    const autoBackupFormat = document.getElementById('cfgAutoBackupFormat');
    const statusEl = document.getElementById('cfgSaveStatus');
    const statusDev = document.getElementById('cfgStatusDevMode');
    const statusHard = document.getElementById('cfgStatusHardDelete');
    const statusAuto = document.getElementById('cfgStatusAutoArchive');
    const statusLoginTimeout = document.getElementById('cfgStatusLoginTimeout');
    const statusSidebarHover = document.getElementById('cfgStatusSidebarHover');
    const statusAutoBackup = document.getElementById('cfgStatusAutoBackup');

    function toast(msg, type) {
        if (typeof showToast === 'function') showToast(msg, type || 'info');
    }

    function setOnOffLabel(el, on) {
        if (el) el.textContent = on ? 'On' : 'Off';
    }

    /** Developer Mode: checked switch means feature is On (enabled). */
    function syncDeveloperModeUi() {
        const on = !!(toggle && toggle.checked);
        setOnOffLabel(toggleLabel, on);
        if (toggle) {
            toggle.setAttribute('aria-checked', on ? 'true' : 'false');
        }
        const envWarn = document.getElementById('cfgDeveloperModeEnvWarn');
        // Show env warning only when DM is on and host is not a development APP_ENV
        if (envWarn) {
            const isDevEnv = <?= $isDevEnv ? 'true' : 'false' ?>;
            if (on && !isDevEnv) envWarn.classList.remove('d-none');
            else if (!on) envWarn.classList.add('d-none');
        }
    }

    function syncAutoArchiveUi() {
        const disabled = !!(disableArchive && disableArchive.checked);
        setOnOffLabel(disableArchiveLabel, disabled);
        if (hoursInput) {
            hoursInput.disabled = disabled;
        }
        if (hoursHint && hoursInput) {
            const h = parseInt(hoursInput.value, 10) || 0;
            hoursHint.textContent = 'hour' + (h === 1 ? '' : 's') + (disabled ? ' (disabled)' : '');
        }
    }

    function syncAutoBackupUi() {
        const on = !!(autoBackupToggle && autoBackupToggle.checked);
        setOnOffLabel(autoBackupToggleLabel, on);
        if (autoBackupFrequency) autoBackupFrequency.disabled = !on;
        if (autoBackupFormat) autoBackupFormat.disabled = !on;
    }

    /** Status badge for login timeout (Developer Mode off → 10 min; on → disabled). */
    function formatLoginTimeoutStatus(res) {
        const dmOn = res.developer_mode === true || res.developer_mode === 1
            || res.developer_mode === '1' || res.developer_mode === 'true';
        const timeoutOff = res.login_timeout_enabled === false || res.login_timeout_disabled === true || dmOn;
        if (timeoutOff) {
            const label = res.login_timeout_label || 'Disabled (host ≈24 min)';
            return '<span class="badge text-bg-warning" title="Application idle timer off">'
                + String(label) + '</span>';
        }
        const label = res.login_timeout_label
            || (res.login_timeout_seconds != null
                ? (Math.round(Number(res.login_timeout_seconds) / 60) + ' minutes')
                : '10 minutes');
        const sec = res.login_timeout_seconds != null ? Number(res.login_timeout_seconds) : 600;
        return '<span class="badge text-bg-success" title="' + String(sec) + ' seconds">'
            + String(label) + '</span>';
    }

    function syncLoginTimeoutWarn(res) {
        const warn = document.getElementById('cfgStatusLoginTimeoutWarn');
        if (!warn) return;
        const dmOn = res.developer_mode === true || res.developer_mode === 1
            || res.developer_mode === '1' || res.developer_mode === 'true';
        if (dmOn) warn.classList.remove('d-none');
        else warn.classList.add('d-none');
    }

    function applyPayload(res) {
        // Accept bool or 0/1 from API — checked means Developer Mode On
        if (toggle && res.developer_mode !== undefined && res.developer_mode !== null) {
            const on = res.developer_mode === true || res.developer_mode === 1
                || res.developer_mode === '1' || res.developer_mode === 'true';
            toggle.checked = on;
            syncDeveloperModeUi();
        }
        if (typeof res.auto_archive_disabled === 'boolean' && disableArchive) {
            disableArchive.checked = res.auto_archive_disabled;
        }
        if (typeof res.auto_archive_timer_hours === 'number' && hoursInput) {
            hoursInput.value = String(res.auto_archive_timer_hours);
        }
        if (res.sidebar_hover_expand_delay_seconds != null && sidebarExpandInput) {
            sidebarExpandInput.value = String(res.sidebar_hover_expand_delay_seconds);
        }
        if (res.sidebar_hover_collapse_delay_seconds != null && sidebarCollapseInput) {
            sidebarCollapseInput.value = String(res.sidebar_hover_collapse_delay_seconds);
        }
        if (typeof res.auto_backup_enabled === 'boolean' && autoBackupToggle) {
            autoBackupToggle.checked = res.auto_backup_enabled;
        }
        if (res.auto_backup_frequency && autoBackupFrequency) {
            autoBackupFrequency.value = String(res.auto_backup_frequency);
        }
        if (res.auto_backup_format && autoBackupFormat) {
            autoBackupFormat.value = String(res.auto_backup_format);
        }
        syncAutoArchiveUi();
        syncAutoBackupUi();

        // Live client idle timer: enabled only when Developer Mode is off (10 minutes)
        if (window.__temperLoginTimeout) {
            const timeoutOn = res.login_timeout_enabled !== false
                && res.login_timeout_disabled !== true
                && !(res.developer_mode === true || res.developer_mode === 1
                    || res.developer_mode === '1' || res.developer_mode === 'true');
            // Prefer explicit server flag when present
            if (typeof res.login_timeout_enabled === 'boolean') {
                window.__temperLoginTimeout.enabled = res.login_timeout_enabled;
            } else {
                window.__temperLoginTimeout.enabled = timeoutOn;
            }
            if (res.login_timeout_seconds != null) {
                window.__temperLoginTimeout.seconds = res.login_timeout_seconds;
            }
            if (typeof window.__temperRescheduleLoginTimeout === 'function') {
                try { window.__temperRescheduleLoginTimeout(); } catch (e) { /* ignore */ }
            } else if (typeof window.__temperIdlePing === 'function') {
                try { window.__temperIdlePing(); } catch (e) { /* ignore */ }
            }
        }

        // Live sidebar hover delays (same browser tab, no full reload)
        if (!window.__temperSidebarHover) window.__temperSidebarHover = {};
        if (res.sidebar_hover_expand_delay_seconds != null) {
            window.__temperSidebarHover.expandSeconds = Number(res.sidebar_hover_expand_delay_seconds);
        }
        if (res.sidebar_hover_collapse_delay_seconds != null) {
            window.__temperSidebarHover.collapseSeconds = Number(res.sidebar_hover_collapse_delay_seconds);
        }

        if (statusDev) {
            const dmOn = res.developer_mode === true || res.developer_mode === 1
                || res.developer_mode === '1' || res.developer_mode === 'true';
            statusDev.innerHTML = dmOn
                ? '<span class="badge text-bg-warning">On</span>'
                : '<span class="badge text-bg-secondary">Off</span>';
        }
        if (statusHard) {
            statusHard.innerHTML = res.allow_hard_delete
                ? '<span class="badge text-bg-danger">Allowed</span>'
                : '<span class="badge text-bg-secondary">Blocked</span>';
        }
        if (statusLoginTimeout) {
            statusLoginTimeout.innerHTML = formatLoginTimeoutStatus(res);
        }
        syncLoginTimeoutWarn(res);
        if (statusAuto) {
            if (res.auto_archive_disabled) {
                statusAuto.innerHTML = '<span class="badge text-bg-secondary">Disabled</span>';
            } else {
                const h = res.auto_archive_timer_hours != null ? res.auto_archive_timer_hours : 24;
                statusAuto.innerHTML = '<span class="badge text-bg-success">' + String(h) + 'h</span>';
            }
        }
        if (statusSidebarHover) {
            const ex = res.sidebar_hover_expand_delay_seconds != null
                ? res.sidebar_hover_expand_delay_seconds : 0.5;
            const cl = res.sidebar_hover_collapse_delay_seconds != null
                ? res.sidebar_hover_collapse_delay_seconds : 2;
            statusSidebarHover.innerHTML = '<span class="badge text-bg-success" title="Expand / collapse delay">'
                + String(ex) + 's / ' + String(cl) + 's</span>';
        }
        if (statusAutoBackup) {
            if (res.auto_backup_enabled) {
                const f = res.auto_backup_frequency || 'daily';
                const fmt = res.auto_backup_format || 'sql';
                statusAutoBackup.innerHTML = '<span class="badge text-bg-success">'
                    + String(f) + ' / ' + String(fmt) + '</span>';
            } else {
                statusAutoBackup.innerHTML = '<span class="badge text-bg-secondary">Off</span>';
            }
        }
        const lastRunEl = document.getElementById('cfgAutoBackupLastRun');
        if (lastRunEl && res.auto_backup_last_run) {
            let badge = '';
            if (res.auto_backup_last_status === 'ok') {
                badge = ' <span class="badge text-bg-success">ok</span>';
            } else if (res.auto_backup_last_status === 'error') {
                badge = ' <span class="badge text-bg-danger">error</span>';
            }
            lastRunEl.innerHTML = 'Last auto-backup: <strong>'
                + String(res.auto_backup_last_run) + '</strong>' + badge;
        }
    }

    if (toggle) {
        toggle.addEventListener('change', syncDeveloperModeUi);
        syncDeveloperModeUi();
    }
    if (disableArchive) {
        disableArchive.addEventListener('change', syncAutoArchiveUi);
    }
    if (hoursInput) {
        hoursInput.addEventListener('input', syncAutoArchiveUi);
    }
    if (autoBackupToggle) {
        autoBackupToggle.addEventListener('change', syncAutoBackupUi);
    }
    syncAutoArchiveUi();
    syncAutoBackupUi();

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const hours = hoursInput ? parseInt(hoursInput.value, 10) : 24;
            if (!disableArchive || !disableArchive.checked) {
                if (!hours || hours < 1) {
                    toast('Auto-Archive Timer must be at least 1 hour.', 'warning');
                    return;
                }
            }
            const expandSec = sidebarExpandInput ? parseFloat(sidebarExpandInput.value) : 0.5;
            const collapseSec = sidebarCollapseInput ? parseFloat(sidebarCollapseInput.value) : 2;
            if (isNaN(expandSec) || expandSec < 0) {
                toast('Sidebar Hover Expand Delay cannot be negative.', 'warning');
                return;
            }
            if (expandSec > 10) {
                toast('Sidebar Hover Expand Delay cannot exceed 10 seconds.', 'warning');
                return;
            }
            if (isNaN(collapseSec) || collapseSec < 0) {
                toast('Sidebar Hover Collapse Delay cannot be negative.', 'warning');
                return;
            }
            if (collapseSec > 30) {
                toast('Sidebar Hover Collapse Delay cannot exceed 30 seconds.', 'warning');
                return;
            }
            const fd = new FormData();
            fd.append('action', 'save_config');
            // Explicit 1/0: checked switch = Developer Mode On (disables application idle timeout)
            fd.append('developer_mode', (toggle && toggle.checked) ? '1' : '0');
            fd.append('auto_archive_disabled', disableArchive && disableArchive.checked ? '1' : '0');
            fd.append('auto_archive_timer_hours', String(hours > 0 ? hours : 24));
            fd.append('sidebar_hover_expand_delay_seconds', String(expandSec));
            fd.append('sidebar_hover_collapse_delay_seconds', String(collapseSec));
            fd.append('auto_backup_enabled', autoBackupToggle && autoBackupToggle.checked ? '1' : '0');
            fd.append('auto_backup_frequency', autoBackupFrequency ? autoBackupFrequency.value : 'daily');
            fd.append('auto_backup_format', autoBackupFormat ? autoBackupFormat.value : 'sql');
            if (statusEl) statusEl.textContent = 'Saving…';
            fetch(endpoint, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .then(function(r) {
                    return r.json().catch(function() {
                        return { success: false, error: 'Invalid server response' };
                    });
                })
                .then(function(res) {
                    if (!res.success) {
                        if (statusEl) statusEl.textContent = '';
                        toast(res.error || 'Save failed', 'danger');
                        return;
                    }
                    if (statusEl) statusEl.textContent = 'Saved.';
                    toast(res.message || 'Saved', 'success');
                    if (typeof window.TemperDirtyForms !== 'undefined') {
                        window.TemperDirtyForms.markClean(form);
                    }
                    applyPayload(res);
                    setTimeout(function() {
                        if (statusEl && statusEl.textContent === 'Saved.') statusEl.textContent = '';
                    }, 2500);
                })
                .catch(function() {
                    if (statusEl) statusEl.textContent = '';
                    toast('Request failed', 'danger');
                });
        });
    }
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-admin-config-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>

<?php
/**
 * System — Configuration.
 * Administrator-only settings: Developer Mode, auto-archive timer, login timeout, and future preferences.
 * Settings persist under storage/config/system.json.
 */
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/system_config.php';

// Strict: Administrator role only (not merely admin.config permission)
$actor = requireAdministrator($db, 'Only administrators can change system configuration.');

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
    return [
        'settings' => systemConfigSettingsForUi(),
        'developer_mode' => isDeveloperModeEnabled(),
        'auto_archive_disabled' => !isAutoArchiveEnabled(),
        'auto_archive_timer_hours' => getAutoArchiveTimerHours(),
        'auto_archive_enabled' => isAutoArchiveEnabled(),
        'login_timeout_disabled' => !isLoginTimeoutEnabled(),
        'login_timeout_seconds' => getLoginTimeoutSeconds(),
        'login_timeout_enabled' => isLoginTimeoutEnabled(),
        'sidebar_hover_expand_delay_seconds' => getSidebarHoverExpandDelaySeconds(),
        'sidebar_hover_collapse_delay_seconds' => getSidebarHoverCollapseDelaySeconds(),
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

        if (array_key_exists('login_timeout_disabled', $_POST)) {
            $updates['login_timeout_disabled'] = configParseBool($_POST['login_timeout_disabled']);
        }

        if (array_key_exists('login_timeout_seconds', $_POST)) {
            $seconds = (int)$_POST['login_timeout_seconds'];
            if ($seconds < 30) {
                configSendJson([
                    'success' => false,
                    'error' => 'Login Timeout must be at least 30 seconds.',
                ], $db);
            }
            if ($seconds > 86400) {
                configSendJson([
                    'success' => false,
                    'error' => 'Login Timeout cannot exceed 86400 seconds (24 hours).',
                ], $db);
            }
            $updates['login_timeout_seconds'] = $seconds;
        }

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
$loginTimeoutDisabled = (bool)$payload['login_timeout_disabled'];
$loginTimeoutSeconds = (int)$payload['login_timeout_seconds'];
$sidebarHoverExpandSec = (float)$payload['sidebar_hover_expand_delay_seconds'];
$sidebarHoverCollapseSec = (float)$payload['sidebar_hover_collapse_delay_seconds'];
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
                                When <strong>On</strong>, enables development-only tools (for example, permanent user delete on Users &amp; Roles).
                                Keep <strong>Off</strong> for normal production use.
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

                    <!-- Security / session group -->
                    <h3 class="h6 text-uppercase text-muted mb-3" style="letter-spacing: 0.04em; font-size: 0.75rem;">
                        Security
                    </h3>

                    <div class="p-3 rounded border mb-4">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                            <div class="flex-grow-1">
                                <label class="form-label fw-semibold mb-1" for="cfgLoginTimeoutDisabled">Disable Login Timeout</label>
                                <p class="small text-muted mb-0">
                                    When on, idle sessions do not expire automatically.
                                    The timeout field below is ignored while this is enabled.
                                </p>
                            </div>
                            <div class="form-check form-switch m-0 pt-1">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="cfgLoginTimeoutDisabled" <?= $loginTimeoutDisabled ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="cfgLoginTimeoutDisabled" id="cfgLoginTimeoutDisabledLabel">
                                    <?= $loginTimeoutDisabled ? 'On' : 'Off' ?>
                                </label>
                            </div>
                        </div>

                        <div class="border-top pt-3" id="cfgLoginTimeoutWrap">
                            <label class="form-label fw-semibold mb-1" for="cfgLoginTimeoutSeconds">
                                Login Timeout (seconds)
                            </label>
                            <p class="small text-muted mb-2">
                                Seconds of inactivity before the session ends and the browser redirects to the login page.
                                Enforced on the server and while any app page is open. Default 300 (5 minutes). Minimum 30.
                            </p>
                            <div class="row g-2 align-items-center">
                                <div class="col-auto">
                                    <input type="number" class="form-control form-control-sm"
                                           id="cfgLoginTimeoutSeconds" name="login_timeout_seconds"
                                           min="30" max="86400" step="1"
                                           value="<?= (int)$loginTimeoutSeconds ?>"
                                           style="width: 8rem;"
                                           <?= $loginTimeoutDisabled ? 'disabled' : '' ?>>
                                </div>
                                <div class="col-auto small text-muted" id="cfgLoginTimeoutSecondsHint">
                                    second<?= $loginTimeoutSeconds === 1 ? '' : 's' ?>
                                    <?php if (!$loginTimeoutDisabled && $loginTimeoutSeconds >= 60): ?>
                                        (≈ <?= $loginTimeoutSeconds >= 3600
                                            ? (round($loginTimeoutSeconds / 3600, 1) . ' h')
                                            : (round($loginTimeoutSeconds / 60, 1) . ' min') ?>)
                                    <?php endif; ?>
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
                <dl class="row mb-0">
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
                    <dt class="col-6 text-muted">Auto-archive</dt>
                    <dd class="col-6" id="cfgStatusAutoArchive">
                        <?php if ($autoArchiveDisabled): ?>
                            <span class="badge text-bg-secondary">Disabled</span>
                        <?php else: ?>
                            <span class="badge text-bg-success"><?= (int)$autoArchiveHours ?>h</span>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-6 text-muted">Login timeout</dt>
                    <dd class="col-6" id="cfgStatusLoginTimeout">
                        <?php if ($loginTimeoutDisabled): ?>
                            <span class="badge text-bg-secondary">Disabled</span>
                        <?php else: ?>
                            <span class="badge text-bg-success"><?= (int)$loginTimeoutSeconds ?>s</span>
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
                    <dt class="col-6 text-muted">Environment</dt>
                    <dd class="col-6"><code><?= htmlspecialchars($appEnv) ?></code></dd>
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
    const disableLoginTimeout = document.getElementById('cfgLoginTimeoutDisabled');
    const disableLoginTimeoutLabel = document.getElementById('cfgLoginTimeoutDisabledLabel');
    const timeoutSecondsInput = document.getElementById('cfgLoginTimeoutSeconds');
    const timeoutSecondsHint = document.getElementById('cfgLoginTimeoutSecondsHint');
    const sidebarExpandInput = document.getElementById('cfgSidebarHoverExpand');
    const sidebarCollapseInput = document.getElementById('cfgSidebarHoverCollapse');
    const statusEl = document.getElementById('cfgSaveStatus');
    const statusDev = document.getElementById('cfgStatusDevMode');
    const statusHard = document.getElementById('cfgStatusHardDelete');
    const statusAuto = document.getElementById('cfgStatusAutoArchive');
    const statusLoginTimeout = document.getElementById('cfgStatusLoginTimeout');
    const statusSidebarHover = document.getElementById('cfgStatusSidebarHover');

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

    function formatTimeoutHint(seconds, disabled) {
        const s = parseInt(seconds, 10) || 0;
        let text = 'second' + (s === 1 ? '' : 's');
        if (!disabled && s >= 60) {
            if (s >= 3600) {
                text += ' (≈ ' + (Math.round((s / 3600) * 10) / 10) + ' h)';
            } else {
                text += ' (≈ ' + (Math.round((s / 60) * 10) / 10) + ' min)';
            }
        }
        if (disabled) text += ' (disabled)';
        return text;
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

    function syncLoginTimeoutUi() {
        const disabled = !!(disableLoginTimeout && disableLoginTimeout.checked);
        setOnOffLabel(disableLoginTimeoutLabel, disabled);
        if (timeoutSecondsInput) {
            timeoutSecondsInput.disabled = disabled;
        }
        if (timeoutSecondsHint && timeoutSecondsInput) {
            timeoutSecondsHint.textContent = formatTimeoutHint(timeoutSecondsInput.value, disabled);
        }
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
        if (typeof res.login_timeout_disabled === 'boolean' && disableLoginTimeout) {
            disableLoginTimeout.checked = res.login_timeout_disabled;
        }
        if (typeof res.login_timeout_seconds === 'number' && timeoutSecondsInput) {
            timeoutSecondsInput.value = String(res.login_timeout_seconds);
        }
        if (res.sidebar_hover_expand_delay_seconds != null && sidebarExpandInput) {
            sidebarExpandInput.value = String(res.sidebar_hover_expand_delay_seconds);
        }
        if (res.sidebar_hover_collapse_delay_seconds != null && sidebarCollapseInput) {
            sidebarCollapseInput.value = String(res.sidebar_hover_collapse_delay_seconds);
        }
        syncAutoArchiveUi();
        syncLoginTimeoutUi();

        // Live client timer picks up new values after save (same browser tab)
        if (window.__temperLoginTimeout) {
            window.__temperLoginTimeout.enabled = !res.login_timeout_disabled;
            window.__temperLoginTimeout.seconds = res.login_timeout_seconds != null
                ? res.login_timeout_seconds
                : 300;
            if (typeof window.__temperIdlePing === 'function') {
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
        if (statusAuto) {
            if (res.auto_archive_disabled) {
                statusAuto.innerHTML = '<span class="badge text-bg-secondary">Disabled</span>';
            } else {
                const h = res.auto_archive_timer_hours != null ? res.auto_archive_timer_hours : 24;
                statusAuto.innerHTML = '<span class="badge text-bg-success">' + String(h) + 'h</span>';
            }
        }
        if (statusLoginTimeout) {
            if (res.login_timeout_disabled) {
                statusLoginTimeout.innerHTML = '<span class="badge text-bg-secondary">Disabled</span>';
            } else {
                const s = res.login_timeout_seconds != null ? res.login_timeout_seconds : 300;
                statusLoginTimeout.innerHTML = '<span class="badge text-bg-success">' + String(s) + 's</span>';
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
    if (disableLoginTimeout) {
        disableLoginTimeout.addEventListener('change', syncLoginTimeoutUi);
    }
    if (timeoutSecondsInput) {
        timeoutSecondsInput.addEventListener('input', syncLoginTimeoutUi);
    }
    syncAutoArchiveUi();
    syncLoginTimeoutUi();

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
            const timeoutSec = timeoutSecondsInput ? parseInt(timeoutSecondsInput.value, 10) : 300;
            if (!disableLoginTimeout || !disableLoginTimeout.checked) {
                if (!timeoutSec || timeoutSec < 30) {
                    toast('Login Timeout must be at least 30 seconds.', 'warning');
                    return;
                }
                if (timeoutSec > 86400) {
                    toast('Login Timeout cannot exceed 86400 seconds (24 hours).', 'warning');
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
            // Explicit 1/0: checked switch = Developer Mode On (enabled)
            fd.append('developer_mode', (toggle && toggle.checked) ? '1' : '0');
            fd.append('auto_archive_disabled', disableArchive && disableArchive.checked ? '1' : '0');
            fd.append('auto_archive_timer_hours', String(hours > 0 ? hours : 24));
            fd.append('login_timeout_disabled', disableLoginTimeout && disableLoginTimeout.checked ? '1' : '0');
            fd.append('login_timeout_seconds', String(timeoutSec >= 30 ? timeoutSec : 300));
            fd.append('sidebar_hover_expand_delay_seconds', String(expandSec));
            fd.append('sidebar_hover_collapse_delay_seconds', String(collapseSec));
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

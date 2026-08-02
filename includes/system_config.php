<?php
/**
 * System configuration store — key/value settings persisted under storage/config/.
 * Extensible for future admin settings; currently backs Developer Mode and related flags.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/storage_paths.php';

/** Filename for the JSON settings blob (relative to storage/config/). */
const TEMPER_SYSTEM_CONFIG_FILE = 'system.json';

/**
 * Catalog of known settings: defaults, types, and UI metadata.
 * Add new keys here when extending the Configuration screen.
 *
 * @return array<string, array{
 *   default: mixed,
 *   type: string,
 *   label: string,
 *   description: string,
 *   group: string
 * }>
 */
function temperSystemConfigCatalog(): array {
    return [
        'developer_mode' => [
            'default' => false,
            'type' => 'bool',
            'label' => 'Developer Mode',
            'description' => 'Enables development-only tools such as permanent user delete. '
                . 'Also sets idle login timeout: Off → 5 minutes, On → 20 minutes '
                . '(always under the host ~24-minute session cleaner). '
                . 'Keep off in production. Environment variables APP_ENV / ALLOW_HARD_DELETE still apply as hard limits.',
            'group' => 'development',
        ],
        'auto_archive_disabled' => [
            'default' => false,
            'type' => 'bool',
            'label' => 'Disable Auto-Archive',
            'description' => 'When enabled, users with force-password are never auto-archived. '
                . 'The timer input is ignored while this is on.',
            'group' => 'users',
            'min' => null,
            'max' => null,
        ],
        'auto_archive_timer_hours' => [
            'default' => 24,
            'type' => 'int',
            'label' => 'Auto-Archive Timer (hours)',
            'description' => 'Hours after force-password is set before an incomplete account is auto-archived. '
                . 'Also used as the starting countdown shown in Users & Roles. Default 24.',
            'group' => 'users',
            'min' => 1,
            'max' => 8760, // 1 year
        ],
        // Login timeout is not a free-form setting: duration is derived from Developer Mode
        // (see TEMPER_LOGIN_TIMEOUT_*_SECONDS and getLoginTimeoutSeconds()). Legacy keys may
        // still exist in system.json from older releases and are ignored for enforcement.
        'sidebar_hover_expand_delay_seconds' => [
            'default' => 0.5,
            'type' => 'float',
            'label' => 'Sidebar Hover Expand Delay (seconds)',
            'description' => 'How long the pointer must rest on the collapsed sidebar before labels expand. '
                . 'Helps prevent accidental activation. Default 0.5. Use 0 for immediate expand.',
            'group' => 'interface',
            'min' => 0,
            'max' => 10,
        ],
        'sidebar_hover_collapse_delay_seconds' => [
            'default' => 2.0,
            'type' => 'float',
            'label' => 'Sidebar Hover Collapse Delay (seconds)',
            'description' => 'How long after the pointer leaves a hover-expanded sidebar before it collapses back to icons. '
                . 'Default 2.0. Use 0 for immediate collapse.',
            'group' => 'interface',
            'min' => 0,
            'max' => 30,
        ],
        'auto_backup_enabled' => [
            'default' => false,
            'type' => 'bool',
            'label' => 'Enable Auto-Backup',
            'description' => 'When enabled, the system creates data-only backups on a schedule (SQL, CSV, or both). '
                . 'Files are stored under storage/backups/. Full schema dumps remain a manual Database Maintenance action.',
            'group' => 'backup',
        ],
        'auto_backup_frequency' => [
            'default' => 'daily',
            'type' => 'string',
            'label' => 'Auto-Backup Frequency',
            'description' => 'How often to create a data-only backup when auto-backup is enabled: hourly, daily, or weekly.',
            'group' => 'backup',
            'options' => ['hourly', 'daily', 'weekly'],
        ],
        'auto_backup_format' => [
            'default' => 'sql',
            'type' => 'string',
            'label' => 'Auto-Backup Format',
            'description' => 'Format for automatic data-only backups: sql (INSERT dump), csv (zip of table CSVs), or both.',
            'group' => 'backup',
            'options' => ['sql', 'csv', 'both'],
        ],
    ];
}

/**
 * Directory for system config files (storage/config).
 *
 * @return array{path:string,error:?string}
 */
function getSystemConfigDir(): array {
    return ensureStorageSubdir('config');
}

/**
 * Absolute path to the system.json file.
 */
function getSystemConfigFilePath(): string {
    $dir = getSystemConfigDir();
    return rtrim($dir['path'], '/\\') . '/' . TEMPER_SYSTEM_CONFIG_FILE;
}

/**
 * Coerce a raw value to the catalog type for a setting key.
 */
function temperCoerceSystemConfigValue(string $key, mixed $value): mixed {
    $catalog = temperSystemConfigCatalog();
    $meta = $catalog[$key] ?? null;
    $type = $meta['type'] ?? 'string';

    $coerced = match ($type) {
        'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool)$value,
        'int' => (int)$value,
        'float' => (float)$value,
        default => is_string($value) ? $value : (string)$value,
    };

    if (($type === 'int' || $type === 'float') && is_array($meta)) {
        $min = $meta['min'] ?? null;
        $max = $meta['max'] ?? null;
        if ($min !== null && $coerced < (float)$min) {
            $coerced = $type === 'int' ? (int)$min : (float)$min;
        }
        if ($max !== null && $coerced > (float)$max) {
            $coerced = $type === 'int' ? (int)$max : (float)$max;
        }
    }

    // Enum-style string options (e.g. auto_backup_frequency)
    if ($type === 'string' && is_array($meta) && !empty($meta['options']) && is_array($meta['options'])) {
        $allowed = $meta['options'];
        $normalized = strtolower(trim((string)$coerced));
        if (!in_array($normalized, $allowed, true)) {
            $coerced = $meta['default'] ?? $allowed[0];
        } else {
            $coerced = $normalized;
        }
    }

    return $coerced;
}

/**
 * Default values for all catalog keys.
 *
 * @return array<string, mixed>
 */
function temperSystemConfigDefaults(): array {
    $out = [];
    foreach (temperSystemConfigCatalog() as $key => $meta) {
        $out[$key] = $meta['default'];
    }
    return $out;
}

/**
 * Load merged settings (defaults + file overrides). Cached per request.
 *
 * @return array<string, mixed>
 */
function loadSystemConfig(bool $forceReload = false): array {
    static $cache = null;
    if ($cache !== null && !$forceReload) {
        return $cache;
    }

    $merged = temperSystemConfigDefaults();
    $path = getSystemConfigFilePath();
    if (is_file($path) && is_readable($path)) {
        $raw = @file_get_contents($path);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $settings = $decoded['settings'] ?? $decoded;
                if (is_array($settings)) {
                    foreach ($settings as $key => $value) {
                        if (!is_string($key) || !array_key_exists($key, $merged)) {
                            // Preserve unknown keys for forward compatibility
                            if (is_string($key)) {
                                $merged[$key] = $value;
                            }
                            continue;
                        }
                        $merged[$key] = temperCoerceSystemConfigValue($key, $value);
                    }
                }
            }
        }
    }

    $cache = $merged;
    return $cache;
}

/**
 * Read a single setting (catalog default if missing).
 */
function getSystemConfig(string $key, mixed $default = null): mixed {
    $all = loadSystemConfig();
    if (array_key_exists($key, $all)) {
        return $all[$key];
    }
    $catalog = temperSystemConfigCatalog();
    if (isset($catalog[$key])) {
        return $catalog[$key]['default'];
    }
    return $default;
}

/**
 * Whether Developer Mode is enabled in system configuration.
 */
function isDeveloperModeEnabled(): bool {
    return (bool)getSystemConfig('developer_mode', false);
}

/**
 * Idle login timeout when Developer Mode is off (5 minutes).
 * Kept well under the host ~24-minute PHP session file cleaner.
 */
const TEMPER_LOGIN_TIMEOUT_NORMAL_SECONDS = 300;

/**
 * Idle login timeout when Developer Mode is on (20 minutes).
 * Kept under the host ~24-minute PHP session file cleaner.
 */
const TEMPER_LOGIN_TIMEOUT_DEV_SECONDS = 1200;

/**
 * Host sessionclean / php.ini gc_maxlifetime floor (seconds). Timeouts and app GC
 * must stay below this so the OS cleaner does not kill sessions before app idle logic.
 */
const TEMPER_HOST_SESSION_CLEANER_SECONDS = 1440;

/**
 * Whether force-password auto-archive is active (not disabled in config).
 */
function isAutoArchiveEnabled(): bool {
    return !(bool)getSystemConfig('auto_archive_disabled', false);
}

/**
 * Configured auto-archive grace period in whole hours (default 24).
 * Clamped to catalog min/max.
 */
function getAutoArchiveTimerHours(): int {
    $hours = (int)getSystemConfig('auto_archive_timer_hours', 24);
    if ($hours < 1) {
        $hours = 24;
    }
    return $hours;
}

/**
 * Whether idle login timeout is active.
 * Always true: timeout cannot be disabled; duration follows Developer Mode.
 */
function isLoginTimeoutEnabled(): bool {
    return true;
}

/**
 * Effective idle login timeout in whole seconds.
 * Developer Mode off → 5 minutes; on → 20 minutes. Not a free-form config value.
 */
function getLoginTimeoutSeconds(): int {
    return isDeveloperModeEnabled()
        ? TEMPER_LOGIN_TIMEOUT_DEV_SECONDS
        : TEMPER_LOGIN_TIMEOUT_NORMAL_SECONDS;
}

/**
 * Human-readable label for Status panel / UI (e.g. "5 minutes", "20 minutes").
 */
function getLoginTimeoutDisplayLabel(): string {
    $minutes = (int)round(getLoginTimeoutSeconds() / 60);
    if ($minutes < 1) {
        $minutes = 1;
    }
    return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
}

/**
 * Seconds to wait before expanding a collapsed sidebar on hover (default 0.5).
 * Clamped to catalog min/max. Client multiplies by 1000 for setTimeout.
 */
function getSidebarHoverExpandDelaySeconds(): float {
    $sec = (float)getSystemConfig('sidebar_hover_expand_delay_seconds', 0.5);
    if ($sec < 0) {
        $sec = 0.0;
    }
    if ($sec > 10) {
        $sec = 10.0;
    }
    return $sec;
}

/**
 * Seconds to wait after leaving a hover-expanded sidebar before collapsing (default 2.0).
 * Clamped to catalog min/max. Client multiplies by 1000 for setTimeout.
 */
function getSidebarHoverCollapseDelaySeconds(): float {
    $sec = (float)getSystemConfig('sidebar_hover_collapse_delay_seconds', 2.0);
    if ($sec < 0) {
        $sec = 0.0;
    }
    if ($sec > 30) {
        $sec = 30.0;
    }
    return $sec;
}

/**
 * Whether scheduled data-only auto-backup is enabled.
 */
function isAutoBackupConfigEnabled(): bool {
    return (bool)getSystemConfig('auto_backup_enabled', false);
}

/**
 * Auto-backup frequency: hourly | daily | weekly.
 */
function getAutoBackupConfigFrequency(): string {
    $freq = strtolower((string)getSystemConfig('auto_backup_frequency', 'daily'));
    if (!in_array($freq, ['hourly', 'daily', 'weekly'], true)) {
        return 'daily';
    }
    return $freq;
}

/**
 * Auto-backup format: sql | csv | both.
 */
function getAutoBackupConfigFormat(): string {
    $format = strtolower((string)getSystemConfig('auto_backup_format', 'sql'));
    if (!in_array($format, ['sql', 'csv', 'both'], true)) {
        return 'sql';
    }
    return $format;
}

/**
 * Persist settings. Only catalog keys (plus previously stored unknown keys) are written.
 *
 * @param array<string, mixed> $updates Partial updates
 * @return array{success:bool,error:?string,settings?:array<string,mixed>}
 */
function saveSystemConfig(array $updates): array {
    $dirInfo = getSystemConfigDir();
    if (!empty($dirInfo['error'])) {
        return ['success' => false, 'error' => 'Config storage is not writable: ' . $dirInfo['error']];
    }

    $current = loadSystemConfig(true);
    $catalog = temperSystemConfigCatalog();

    foreach ($updates as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        if (isset($catalog[$key])) {
            $current[$key] = temperCoerceSystemConfigValue($key, $value);
        } else {
            // Allow future keys without catalog entry (string-stored)
            $current[$key] = $value;
        }
    }

    $payload = [
        'version' => 1,
        'updated_at' => date('c'),
        'settings' => $current,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['success' => false, 'error' => 'Failed to encode configuration JSON.'];
    }

    $path = getSystemConfigFilePath();
    $write = writeStorageFile($path, $json . "\n");
    if (!$write['success']) {
        return ['success' => false, 'error' => $write['error'] ?? 'Failed to write configuration file.'];
    }

    // Bust request cache
    loadSystemConfig(true);

    return ['success' => true, 'error' => null, 'settings' => $current];
}

/**
 * UI-ready list of settings for the Configuration screen.
 *
 * @return list<array{key:string,value:mixed,label:string,description:string,group:string,type:string}>
 */
function systemConfigSettingsForUi(): array {
    $values = loadSystemConfig();
    $out = [];
    foreach (temperSystemConfigCatalog() as $key => $meta) {
        $out[] = [
            'key' => $key,
            'value' => $values[$key] ?? $meta['default'],
            'label' => $meta['label'],
            'description' => $meta['description'],
            'group' => $meta['group'],
            'type' => $meta['type'],
        ];
    }
    return $out;
}

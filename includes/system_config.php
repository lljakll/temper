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

    if ($type === 'int' && is_array($meta)) {
        $min = $meta['min'] ?? null;
        $max = $meta['max'] ?? null;
        if ($min !== null && $coerced < (int)$min) {
            $coerced = (int)$min;
        }
        if ($max !== null && $coerced > (int)$max) {
            $coerced = (int)$max;
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

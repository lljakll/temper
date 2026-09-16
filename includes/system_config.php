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
                . 'Also controls application idle login timeout: Off → 10 minutes (enforced); '
                . 'On → application idle timeout fully disabled (host/system session cleaner ≈24 minutes remains). '
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
        // Login timeout is not a free-form setting: when Developer Mode is off the app uses a
        // fixed 10-minute idle window; when on, the app timer is fully disabled (host ~24 min
        // still applies). Legacy login_timeout_* keys in system.json are ignored.
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
            'description' => 'When enabled, the system creates data-only backup packages on a schedule (SQL, CSV, or both, plus attachments and system config). '
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
            'description' => 'Dump format inside automatic data-only backup packages: sql (INSERT dump + files), csv (table CSVs + files), or both.',
            'group' => 'backup',
            'options' => ['sql', 'csv', 'both'],
        ],
        'church_name' => [
            'default' => 'Hope Baptist Treasurer',
            'type' => 'string',
            'label' => 'Church / organization name',
            'description' => 'Display name used in the browser tab and the sidebar / mobile header. '
                . 'Default matches the current title (Hope Baptist Treasurer) until changed.',
            'group' => 'organization',
            'max_length' => 80,
        ],
        'church_icon' => [
            'default' => '',
            'type' => 'string',
            'label' => 'Church / organization icon',
            'description' => 'Optional graphic for the browser tab favicon and the sidebar / mobile header. '
                . 'Upload a new image or select one already in storage. Leave empty to keep the default icon.',
            'group' => 'organization',
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

    if ($type === 'string' && is_array($meta)) {
        $coerced = trim((string)$coerced);
        $maxLen = $meta['max_length'] ?? null;
        if ($maxLen !== null && (int)$maxLen > 0 && mb_strlen($coerced) > (int)$maxLen) {
            $coerced = mb_substr($coerced, 0, (int)$maxLen);
        }
        if ($key === 'church_name' && $coerced === '') {
            $coerced = (string)($meta['default'] ?? getDefaultChurchDisplayName());
        }
    }

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
 * Application idle login timeout when Developer Mode is off (10 minutes).
 * Kept well under the host ~24-minute PHP session file cleaner.
 */
const TEMPER_LOGIN_TIMEOUT_NORMAL_SECONDS = 600;

/**
 * Host sessionclean / php.ini gc_maxlifetime floor (seconds). App timeouts and GC
 * must stay at or below this so the OS cleaner does not kill sessions unexpectedly.
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
 * Whether application-level idle login timeout is active.
 * Authoritative: disabled only when Developer Mode is ON; otherwise always on.
 */
function isLoginTimeoutEnabled(): bool {
    return !isDeveloperModeEnabled();
}

/**
 * Effective application idle login timeout in whole seconds when the timer is enabled.
 * Fixed at 10 minutes (Developer Mode off). Not a free-form config value.
 * When Developer Mode is on the timer is disabled — callers should check isLoginTimeoutEnabled().
 */
function getLoginTimeoutSeconds(): int {
    return TEMPER_LOGIN_TIMEOUT_NORMAL_SECONDS;
}

/**
 * Human-readable label for Status panel / UI.
 */
function getLoginTimeoutDisplayLabel(): string {
    if (!isLoginTimeoutEnabled()) {
        return 'Disabled (host ≈24 min)';
    }
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
 * Fallback church / organization name when system config has no override.
 */
function getDefaultChurchDisplayName(): string {
    if (defined('APP_NAME') && is_string(APP_NAME) && trim(APP_NAME) !== '') {
        return APP_NAME;
    }
    return 'Hope Baptist Treasurer';
}

/**
 * Configured church / organization display name (sidebar, header, tab).
 */
function getChurchDisplayName(): string {
    $name = trim((string)getSystemConfig('church_name', ''));
    if ($name === '') {
        return getDefaultChurchDisplayName();
    }
    return $name;
}

/**
 * Browser tab title from the same church name setting.
 * Appends a short app label unless the name already mentions Temper or Treasurer.
 */
function getBrowserTabTitle(): string {
    $name = getChurchDisplayName();
    if ($name === '') {
        return getDefaultChurchDisplayName();
    }
    if (preg_match('/\b(temper|treasurer)\b/i', $name) === 1) {
        return $name;
    }
    return $name . ' — Temper';
}

/**
 * Allowed brand-icon extensions mapped to MIME types.
 *
 * @return array<string, string>
 */
function temperBrandIconAllowedTypes(): array {
    return [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'svg' => 'image/svg+xml',
    ];
}

function temperBrandIconMaxBytes(): int {
    return 2 * 1024 * 1024;
}

/**
 * Storage-relative path of the configured church icon, or empty when unset.
 */
function getChurchIconRelativePath(): string {
    $rel = trim(str_replace('\\', '/', (string)getSystemConfig('church_icon', '')));
    $rel = ltrim($rel, '/');
    if ($rel === '' || str_contains($rel, '..')) {
        return '';
    }
    return $rel;
}

/**
 * Absolute filesystem path of the configured church icon, or null.
 */
function getChurchIconAbsolutePath(): ?string {
    $rel = getChurchIconRelativePath();
    if ($rel === '') {
        return null;
    }
    return temperResolveReadableStorageImage($rel);
}

/**
 * Public URL for the configured church icon (favicon / header), or null when unset.
 */
function getChurchIconPublicUrl(): ?string {
    $path = getChurchIconAbsolutePath();
    if ($path === null) {
        return null;
    }
    $v = @filemtime($path) ?: time();
    return 'brand_icon.php?v=' . (int)$v;
}

/**
 * Client payload so header, tab, and favicon stay on one setting after a live save.
 *
 * @return array{name:string,title:string,icon:string,iconUrl:?string}
 */
function getBrandClientPayload(): array {
    $url = getChurchIconPublicUrl();
    return [
        'name' => getChurchDisplayName(),
        'title' => getBrowserTabTitle(),
        'icon' => getChurchIconRelativePath(),
        'iconUrl' => $url,
    ];
}

/**
 * Resolve a storage-relative path to a readable image file inside the storage root.
 */
function temperResolveReadableStorageImage(string $relative): ?string {
    $relative = str_replace('\\', '/', $relative);
    $relative = ltrim($relative, '/');
    if ($relative === '' || str_contains($relative, '..')) {
        return null;
    }
    $ext = strtolower((string)pathinfo($relative, PATHINFO_EXTENSION));
    $allowed = temperBrandIconAllowedTypes();
    if ($ext === '' || !isset($allowed[$ext])) {
        return null;
    }

    $root = realpath(getStoragePath());
    if ($root === false) {
        return null;
    }
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/');
    $candidate = $rootNorm . '/' . $relative;
    $real = realpath($candidate);
    if ($real === false || !is_file($real) || !is_readable($real)) {
        return null;
    }
    $realNorm = str_replace('\\', '/', $real);
    if ($realNorm !== $rootNorm && !str_starts_with($realNorm, $rootNorm . '/')) {
        return null;
    }
    return $real;
}

function temperBrandIconMimeForPath(string $path): string {
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    $allowed = temperBrandIconAllowedTypes();
    return $allowed[$ext] ?? 'application/octet-stream';
}

/**
 * Reject SVG that embeds script or event handlers (served as favicon / img).
 */
function temperBrandSvgLooksSafe(string $contents): bool {
    if ($contents === '') {
        return false;
    }
    if (preg_match('/<script\b/i', $contents) === 1) {
        return false;
    }
    if (preg_match('/\bon[a-z]+\s*=/i', $contents) === 1) {
        return false;
    }
    if (preg_match('/javascript\s*:/i', $contents) === 1) {
        return false;
    }
    return true;
}

/**
 * Image files already in storage that an administrator may pick as the church icon.
 * Skips backups, logs, and exports.
 *
 * @return list<array{relative:string,name:string,size:int}>
 */
function listStorageImagesForBrandPicker(): array {
    $root = realpath(getStoragePath());
    if ($root === false || !is_dir($root)) {
        return [];
    }
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/');
    $skipTop = ['backups' => true, 'logs' => true, 'exports' => true];
    $allowed = temperBrandIconAllowedTypes();
    $out = [];

    try {
        $dirIter = new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS
        );
        $iter = new RecursiveIteratorIterator($dirIter, RecursiveIteratorIterator::LEAVES_ONLY);
        $iter->setMaxDepth(8);
        foreach ($iter as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $full = str_replace('\\', '/', $file->getPathname());
            if (!str_starts_with($full, $rootNorm . '/')) {
                continue;
            }
            $rel = ltrim(substr($full, strlen($rootNorm)), '/');
            $top = explode('/', $rel)[0] ?? '';
            if ($top !== '' && isset($skipTop[$top])) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!isset($allowed[$ext])) {
                continue;
            }
            $out[] = [
                'relative' => $rel,
                'name' => $file->getFilename(),
                'size' => (int)$file->getSize(),
            ];
        }
    } catch (Throwable $e) {
        error_log('[temper-brand] Failed to list storage images: ' . $e->getMessage());
        return [];
    }

    usort($out, static function (array $a, array $b): int {
        return strcasecmp($a['relative'], $b['relative']);
    });
    return $out;
}

/**
 * Copy an image into storage/brand/ and return the new relative path.
 *
 * @return array{success:bool,error:?string,relative?:string,absolute?:string}
 */
function temperStoreChurchIconFromPath(string $sourceAbs, string $originalName): array {
    $dirInfo = ensureStorageSubdir('brand');
    if (!empty($dirInfo['error'])) {
        return ['success' => false, 'error' => 'Brand storage is not writable: ' . $dirInfo['error']];
    }

    $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = temperBrandIconAllowedTypes();
    if ($ext === '' || !isset($allowed[$ext])) {
        $ext = strtolower((string)pathinfo($sourceAbs, PATHINFO_EXTENSION));
    }
    if ($ext === '' || !isset($allowed[$ext])) {
        return ['success' => false, 'error' => 'Icon must be PNG, JPG, GIF, WEBP, ICO, or SVG.'];
    }

    if (!is_file($sourceAbs) || !is_readable($sourceAbs)) {
        return ['success' => false, 'error' => 'Selected image is missing or unreadable.'];
    }
    $size = (int)filesize($sourceAbs);
    if ($size <= 0) {
        return ['success' => false, 'error' => 'Selected image is empty.'];
    }
    if ($size > temperBrandIconMaxBytes()) {
        return ['success' => false, 'error' => 'Icon must be 2 MB or smaller.'];
    }
    if ($ext === 'svg') {
        $raw = (string)@file_get_contents($sourceAbs);
        if (!temperBrandSvgLooksSafe($raw)) {
            return ['success' => false, 'error' => 'SVG icon contains disallowed script content.'];
        }
    }

    $safeExt = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'png';
    $stored = 'icon_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $safeExt;
    $dest = rtrim((string)$dirInfo['path'], '/\\') . '/' . $stored;

    $copied = false;
    if (is_uploaded_file($sourceAbs)) {
        $copied = @move_uploaded_file($sourceAbs, $dest);
    } else {
        $copied = @copy($sourceAbs, $dest);
    }
    if (!$copied || !is_file($dest)) {
        return ['success' => false, 'error' => 'Failed to save church icon.'];
    }

    return [
        'success' => true,
        'error' => null,
        'relative' => 'brand/' . $stored,
        'absolute' => $dest,
    ];
}

/**
 * Persist a church icon: copy into storage/brand/ unless it is already there.
 *
 * @return array{success:bool,error:?string,relative?:string}
 */
function temperNormalizeChurchIconSetting(string $relativeOrEmpty): array {
    $relativeOrEmpty = trim(str_replace('\\', '/', $relativeOrEmpty));
    $relativeOrEmpty = ltrim($relativeOrEmpty, '/');
    if ($relativeOrEmpty === '') {
        return ['success' => true, 'error' => null, 'relative' => ''];
    }

    $abs = temperResolveReadableStorageImage($relativeOrEmpty);
    if ($abs === null) {
        return ['success' => false, 'error' => 'Selected image was not found in storage.'];
    }

    $relNorm = str_replace('\\', '/', $relativeOrEmpty);
    if (str_starts_with($relNorm, 'brand/')) {
        return ['success' => true, 'error' => null, 'relative' => $relNorm];
    }

    $stored = temperStoreChurchIconFromPath($abs, basename($relNorm));
    if (empty($stored['success'])) {
        return ['success' => false, 'error' => $stored['error'] ?? 'Failed to copy image into brand storage.'];
    }
    return [
        'success' => true,
        'error' => null,
        'relative' => (string)($stored['relative'] ?? ''),
    ];
}

/**
 * Admin preview URL for a storage-relative image (session-gated).
 */
function temperBrandPickerPreviewUrl(string $relative): string {
    return 'pages/admin-config.php?preview_image=1&path=' . rawurlencode($relative);
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

<?php
/**
 * Per-user preferences (users.preferences JSON).
 *
 * Storage and helpers only — there is no general preferences UI.
 * Schema: users.preferences (nullable JSON; in setup_db.php since 0.944).
 *
 * ## Key naming convention
 *
 * Keys are lowercase, dot-separated path segments:
 *
 *     <area>.<subject>[.<option>...]
 *
 * Stored as a nested JSON object matching that path (siblings are preserved).
 * Values must be small JSON-serializable scalars, lists, or maps — never HTML,
 * file blobs, or unrelated dumps under a key.
 *
 * Areas / examples (reuse these prefixes for near-future cards):
 *
 *     dashboard.<card_id>.<option>
 *         dashboard.total_cash.account_ids   list<int> Chart of Accounts ids
 *                                            included in Total Cash / Bank
 *
 *     ledger.<option>
 *         ledger.double_click                reserved (browser localStorage
 *                                            in 0.936; migrate here later)
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

/** Dashboard Total Cash / Bank: selected asset account ids. */
const USER_PREF_DASHBOARD_TOTAL_CASH_ACCOUNT_IDS = 'dashboard.total_cash.account_ids';

/** Soft cap on the whole preferences JSON document (bytes). */
const USER_PREFERENCE_MAX_BLOB_BYTES = 16384;

/**
 * Whether a preference key matches the documented convention.
 */
function userPreferenceKeyIsValid(string $key): bool
{
    return (bool)preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $key);
}

/**
 * Split a valid key into path segments. Empty array if invalid.
 *
 * @return list<string>
 */
function userPreferenceKeySegments(string $key): array
{
    if (!userPreferenceKeyIsValid($key)) {
        return [];
    }
    return explode('.', $key);
}

function userPreferencesColumnExists(mysqli $db): bool
{
    return function_exists('temperColumnExists')
        ? temperColumnExists($db, 'users', 'preferences')
        : false;
}

/**
 * @return array<string, mixed>
 */
function userPreferencesDecode(mixed $raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $prefs
 * @param list<string> $segments
 */
function userPreferencesWalk(array $prefs, array $segments): mixed
{
    $cur = $prefs;
    foreach ($segments as $seg) {
        if (!is_array($cur) || !array_key_exists($seg, $cur)) {
            return null;
        }
        $cur = $cur[$seg];
    }
    return $cur;
}

/**
 * @param array<string, mixed> $prefs
 * @param list<string> $segments
 * @return array<string, mixed>
 */
function userPreferencesAssign(array $prefs, array $segments, mixed $value): array
{
    if ($segments === []) {
        return $prefs;
    }
    $ref = &$prefs;
    $last = array_pop($segments);
    foreach ($segments as $seg) {
        if (!isset($ref[$seg]) || !is_array($ref[$seg])) {
            $ref[$seg] = [];
        }
        $ref = &$ref[$seg];
    }
    $ref[$last] = $value;
    unset($ref);
    return $prefs;
}

/**
 * Whether a value is a small JSON-serializable scalar / list / map.
 */
function userPreferenceValueIsAllowed(mixed $value, int $depth = 0): bool
{
    if ($depth > 8) {
        return false;
    }
    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
        return true;
    }
    if (is_string($value)) {
        return strlen($value) <= 2048;
    }
    if (!is_array($value)) {
        return false;
    }
    if (count($value) > 256) {
        return false;
    }
    foreach ($value as $k => $v) {
        if (!is_int($k) && !is_string($k)) {
            return false;
        }
        if (is_string($k) && strlen($k) > 64) {
            return false;
        }
        if (!userPreferenceValueIsAllowed($v, $depth + 1)) {
            return false;
        }
    }
    return true;
}

/**
 * Read one preference. Missing key / missing column → $default.
 */
function getUserPreference(mysqli $db, int $userId, string $key, mixed $default = null): mixed
{
    if ($userId <= 0 || !userPreferenceKeyIsValid($key) || !userPreferencesColumnExists($db)) {
        return $default;
    }
    $segments = userPreferenceKeySegments($key);
    if ($segments === []) {
        return $default;
    }

    $stmt = $db->prepare('SELECT preferences FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return $default;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return $default;
    }

    $walked = userPreferencesWalk(userPreferencesDecode($row['preferences'] ?? null), $segments);
    return $walked === null ? $default : $walked;
}

/**
 * Write one preference key (nested path). Other keys in the JSON blob are kept.
 *
 * @return bool true on success
 */
function setUserPreference(mysqli $db, int $userId, string $key, mixed $value): bool
{
    if ($userId <= 0 || !userPreferenceKeyIsValid($key) || !userPreferencesColumnExists($db)) {
        return false;
    }
    if (!userPreferenceValueIsAllowed($value)) {
        return false;
    }
    $segments = userPreferenceKeySegments($key);
    if ($segments === []) {
        return false;
    }

    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $started = false;
    try {
        $started = $db->begin_transaction();
        $stmt = $db->prepare('SELECT preferences FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        if (!$stmt) {
            if ($started) {
                $db->rollback();
            }
            return false;
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            if ($started) {
                $db->rollback();
            }
            return false;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            if ($started) {
                $db->rollback();
            }
            return false;
        }

        $prefs = userPreferencesAssign(userPreferencesDecode($row['preferences'] ?? null), $segments, $value);
        $blob = json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($blob === false || strlen($blob) > USER_PREFERENCE_MAX_BLOB_BYTES) {
            if ($started) {
                $db->rollback();
            }
            return false;
        }

        $upd = $db->prepare('UPDATE users SET preferences = ? WHERE id = ?');
        if (!$upd) {
            if ($started) {
                $db->rollback();
            }
            return false;
        }
        $upd->bind_param('si', $blob, $userId);
        $ok = $upd->execute() && $upd->affected_rows >= 0;
        $upd->close();
        if ($started) {
            if ($ok) {
                $db->commit();
            } else {
                $db->rollback();
            }
        }
        return $ok;
    } catch (Throwable $e) {
        if ($started) {
            $db->rollback();
        }
        error_log('[user_preferences] setUserPreference failed: ' . $e->getMessage());
        return false;
    }
}

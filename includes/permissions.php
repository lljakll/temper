<?php
/**
 * Role-based access control (RBAC) for Temper.
 *
 * Permissions are stored as a JSON array on roles.permissions.
 * Users may have multiple roles (user_roles) plus additive custom_permissions.
 * Special permission "*" grants full access (Administrator).
 *
 * Security: Prevent direct access to this helper file.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

/** @var array<string, string> Human labels for known permissions */
const TEMPER_PERMISSION_CATALOG = [
    '*' => 'Full system access',
    'page.dashboard' => 'View dashboard',
    'page.ledger' => 'View ledger',
    'page.ledger.write' => 'Create/edit ledger transactions',
    'page.reports' => 'View reports',
    'page.budget' => 'View budgets',
    'page.budget.write' => 'Create/edit budgets',
    'page.tasks' => 'View and manage tasks',
    'admin.access' => 'Access System overview',
    'admin.backup' => 'Backup and restore (data-only; Administrator role required)',
    'admin.database' => 'Database maintenance (includes full schema backup)',
    'admin.lookups' => 'Manage lookup tables',
    'admin.config' => 'System configuration',
    'users.manage' => 'Manage users and roles',
    'archive.import' => 'Archival data loader',
    'profile.self' => 'Manage own profile',
];

/**
 * Default SPA page → required permission map.
 *
 * @return array<string, string>
 */
function temperPagePermissionMap(): array {
    return [
        'dashboard' => 'page.dashboard',
        'ledger' => 'page.ledger',
        'reports' => 'page.reports',
        'budget' => 'page.budget',
        'tasks' => 'page.tasks',
        'admin' => 'admin.access',
        'admin-backup' => 'admin.backup',
        'admin-database' => 'admin.database',
        'admin-users' => 'users.manage',
        'admin-config' => 'admin.config',
        'setup_funds' => 'admin.lookups',
        'setup_accounts' => 'admin.lookups',
        'setup_naturalclasses' => 'admin.lookups',
        'setup_functionalclasses' => 'admin.lookups',
        'profile' => 'profile.self',
        'force-password' => 'profile.self',
    ];
}

/**
 * Canonical predefined roles and their permissions (seed only for missing roles).
 *
 * @return list<array{name:string,description:string,permissions:list<string>}>
 */
function temperDefaultRoles(): array {
    return [
        [
            'name' => 'Administrator',
            'description' => 'System administrator with full access',
            'permissions' => ['*'],
        ],
        [
            'name' => 'Treasurer',
            'description' => 'Church treasurer — full financial operations',
            'permissions' => [
                'page.dashboard', 'page.ledger', 'page.ledger.write',
                'page.reports', 'page.budget', 'page.budget.write', 'page.tasks',
                'admin.access', 'admin.lookups',
                'profile.self',
            ],
        ],
        [
            'name' => 'Financial Secretary',
            'description' => 'Financial secretary — deposits and ledger entry',
            'permissions' => [
                'page.dashboard', 'page.ledger', 'page.ledger.write',
                'page.reports', 'page.budget', 'page.tasks',
                'admin.access', 'admin.lookups',
                'profile.self',
            ],
        ],
        [
            'name' => 'Finance Manager',
            'description' => 'Finance manager with access to financial data and budgets',
            'permissions' => [
                'page.dashboard', 'page.ledger', 'page.ledger.write',
                'page.reports', 'page.budget', 'page.budget.write', 'page.tasks',
                'admin.access', 'admin.lookups',
                'profile.self',
            ],
        ],
        [
            'name' => 'Archivist',
            'description' => 'Historical data import only (no current-year Treasurer duties)',
            'permissions' => [
                'page.dashboard', 'page.ledger', 'page.reports', 'page.budget',
                'admin.access', 'admin.lookups', 'archive.import',
                'profile.self',
            ],
        ],
        [
            'name' => 'Board Member',
            'description' => 'Read-only access to dashboard, reports, and budgets',
            'permissions' => [
                'page.dashboard', 'page.reports', 'page.budget',
                'profile.self',
            ],
        ],
        [
            'name' => 'Member',
            'description' => 'Limited member access (profile only by default)',
            'permissions' => [
                'profile.self',
            ],
        ],
    ];
}

/**
 * Whether a table exists.
 */
function temperTableExists(mysqli $db, string $table): bool {
    $escaped = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$escaped}'");
    if (!$res) {
        return false;
    }
    $ok = $res->num_rows > 0;
    $res->close();
    return $ok;
}

/**
 * Whether a column exists on a table.
 */
function temperColumnExists(mysqli $db, string $table, string $column): bool {
    $t = $db->real_escape_string($table);
    $c = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    if (!$res) {
        return false;
    }
    $ok = $res->num_rows > 0;
    $res->close();
    return $ok;
}

/**
 * Read-only check: users/roles schema supports multi-role, phone, force-password, custom perms.
 * Does not CREATE/ALTER tables or seed data. Schema is owned by setup_db / updates/*.sql.
 *
 * @return list<string>
 */
function checkUsersRolesSchema(mysqli $db): array {
    $issues = [];

    foreach (['roles', 'users', 'user_roles'] as $table) {
        if (!temperTableExists($db, $table)) {
            $issues[] = "table {$table} is missing";
        }
    }

    if (temperTableExists($db, 'roles') && !temperColumnExists($db, 'roles', 'is_system')) {
        $issues[] = 'column roles.is_system is missing';
    }

    if (temperTableExists($db, 'users')) {
        foreach (['phone', 'must_change_password', 'custom_permissions', 'archived_at', 'force_password_set_at'] as $col) {
            if (!temperColumnExists($db, 'users', $col)) {
                $issues[] = "column users.{$col} is missing";
            }
        }
    }

    if (temperTableExists($db, 'user_roles')) {
        foreach (['user_id', 'role_id', 'is_primary'] as $col) {
            if (!temperColumnExists($db, 'user_roles', $col)) {
                $issues[] = "column user_roles.{$col} is missing";
            }
        }
    }

    return $issues;
}

/**
 * Ensure users/roles schema is present (read-only). Logs and throws if outdated.
 * Does not run live DDL, backfills, or role seeding.
 */
function ensureUsersRolesSchema(mysqli $db): void {
    static $done = false;
    if ($done) {
        return;
    }

    $issues = checkUsersRolesSchema($db);
    if ($issues !== []) {
        temperSchemaOutOfDate('users/roles', $issues);
    }

    $done = true;
}

/**
 * Decode a permissions JSON value into a list of permission strings.
 *
 * @return list<string>
 */
function decodeRolePermissions(mixed $raw): array {
    if (is_array($raw)) {
        $list = $raw;
    } elseif (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        $list = is_array($decoded) ? $decoded : [];
    } else {
        $list = [];
    }
    $out = [];
    foreach ($list as $item) {
        if (is_string($item) && $item !== '') {
            $out[] = $item;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Encode permission list for DB storage.
 *
 * @param list<string> $permissions
 */
function encodeRolePermissions(array $permissions): string {
    return json_encode(array_values(array_unique(array_map('strval', $permissions))), JSON_UNESCAPED_UNICODE);
}

/**
 * Filter a permission list to known catalog keys only.
 *
 * @param list<string>|array $permissions
 * @return list<string>
 */
function sanitizePermissionList(array $permissions, bool $allowStar = true): array {
    $out = [];
    foreach ($permissions as $p) {
        if (!is_string($p) || $p === '') {
            continue;
        }
        if ($p === '*') {
            if ($allowStar) {
                $out[] = '*';
            }
            continue;
        }
        if (array_key_exists($p, TEMPER_PERMISSION_CATALOG)) {
            $out[] = $p;
        }
    }
    return array_values(array_unique($out));
}

/**
 * True if a permission set grants the requested permission.
 *
 * @param list<string> $granted
 */
function permissionSetAllows(array $granted, string $permission): bool {
    if ($permission === '') {
        return false;
    }
    if (in_array('*', $granted, true)) {
        return true;
    }
    if (in_array($permission, $granted, true)) {
        return true;
    }
    foreach ($granted as $g) {
        if (str_ends_with($g, '.*')) {
            $base = substr($g, 0, -2);
            if ($permission === $base || str_starts_with($permission, $base . '.')) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Load roles assigned to a user.
 *
 * @return list<array{id:int,name:string,description:?string,permissions:list<string>,is_primary:bool,is_system:bool}>
 */
function getUserRoles(mysqli $db, int $userId): array {
    ensureUsersRolesSchema($db);
    $roles = [];

    if (temperTableExists($db, 'user_roles')) {
        $sql = 'SELECT r.id, r.name, r.description, r.permissions,
                       COALESCE(r.is_system, 0) AS is_system,
                       ur.is_primary
                FROM user_roles ur
                JOIN roles r ON r.id = ur.role_id
                WHERE ur.user_id = ?
                ORDER BY ur.is_primary DESC, r.name ASC';
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $roles[] = [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                    'description' => $row['description'] ?? null,
                    'permissions' => decodeRolePermissions($row['permissions'] ?? '[]'),
                    'is_primary' => (int)$row['is_primary'] === 1,
                    'is_system' => (int)$row['is_system'] === 1,
                ];
            }
            $stmt->close();
        }
    }

    // Legacy fallback: users.role_id only
    if ($roles === []) {
        $stmt = $db->prepare(
            'SELECT r.id, r.name, r.description, r.permissions, COALESCE(r.is_system, 0) AS is_system
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = ?
             LIMIT 1'
        );
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $roles[] = [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                    'description' => $row['description'] ?? null,
                    'permissions' => decodeRolePermissions($row['permissions'] ?? '[]'),
                    'is_primary' => true,
                    'is_system' => (int)$row['is_system'] === 1,
                ];
            }
        }
    }

    return $roles;
}

/**
 * Replace a user's role assignments. First role_id is primary; also updates users.role_id.
 *
 * @param list<int> $roleIds
 * @return string|null Error message or null on success
 */
function setUserRoles(mysqli $db, int $userId, array $roleIds): ?string {
    ensureUsersRolesSchema($db);
    $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds), static fn($id) => $id > 0)));
    if ($roleIds === []) {
        return 'At least one role is required.';
    }

    // Validate all roles exist
    $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
    $types = str_repeat('i', count($roleIds));
    $stmt = $db->prepare("SELECT id FROM roles WHERE id IN ({$placeholders})");
    $stmt->bind_param($types, ...$roleIds);
    $stmt->execute();
    $found = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $found[] = (int)$row['id'];
    }
    $stmt->close();
    if (count($found) !== count($roleIds)) {
        return 'One or more selected roles do not exist.';
    }

    $primaryId = $roleIds[0];

    $db->begin_transaction();
    try {
        $del = $db->prepare('DELETE FROM user_roles WHERE user_id = ?');
        $del->bind_param('i', $userId);
        $del->execute();
        $del->close();

        $ins = $db->prepare('INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, ?)');
        foreach ($roleIds as $rid) {
            $isPrimary = ($rid === $primaryId) ? 1 : 0;
            $ins->bind_param('iii', $userId, $rid, $isPrimary);
            $ins->execute();
        }
        $ins->close();

        $upd = $db->prepare('UPDATE users SET role_id = ? WHERE id = ?');
        $upd->bind_param('ii', $primaryId, $userId);
        $upd->execute();
        $upd->close();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return 'Failed to update roles: ' . $e->getMessage();
    }

    clearUserAclCache();
    return null;
}

/**
 * Load active user ACL: multi-role union + custom permissions.
 *
 * @return array{
 *   id:int,username:string,first_name:string,last_name:string,email:string,phone:?string,
 *   is_active:int|bool,must_change_password:bool,role_id:int,role_name:string,
 *   role_names:list<string>,roles:list<array>,custom_permissions:list<string>,
 *   permissions:list<string>,display_name:string
 * }|null
 */
function loadUserAcl(mysqli $db, int $userId): ?array {
    static $cache = [];
    static $generation = 0;
    $currentGen = (int)($GLOBALS['__temper_acl_cache_generation'] ?? 0);
    if ($generation !== $currentGen) {
        $cache = [];
        $generation = $currentGen;
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    ensureUsersRolesSchema($db);

    $cols = 'u.id, u.username, u.first_name, u.last_name, u.email, u.is_active, u.role_id';
    if (temperColumnExists($db, 'users', 'phone')) {
        $cols .= ', u.phone';
    }
    if (temperColumnExists($db, 'users', 'must_change_password')) {
        $cols .= ', u.must_change_password';
    }
    if (temperColumnExists($db, 'users', 'custom_permissions')) {
        $cols .= ', u.custom_permissions';
    }

    $stmt = $db->prepare("SELECT {$cols} FROM users u WHERE u.id = ? AND u.is_active = TRUE LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        $cache[$userId] = null;
        return null;
    }

    $roles = getUserRoles($db, $userId);
    if ($roles === []) {
        $cache[$userId] = null;
        return null;
    }

    $merged = [];
    $roleNames = [];
    $primary = $roles[0];
    foreach ($roles as $role) {
        $roleNames[] = $role['name'];
        if ($role['is_primary']) {
            $primary = $role;
        }
        if ($role['name'] === 'Administrator' || in_array('*', $role['permissions'], true)) {
            $merged = ['*'];
            break;
        }
        foreach ($role['permissions'] as $p) {
            $merged[] = $p;
        }
    }

    $custom = decodeRolePermissions($row['custom_permissions'] ?? '[]');
    if (!in_array('*', $merged, true)) {
        foreach ($custom as $p) {
            $merged[] = $p;
        }
    }
    $merged = array_values(array_unique($merged));

    // Administrator role always full access
    if (in_array('Administrator', $roleNames, true) && !in_array('*', $merged, true)) {
        $merged = ['*'];
    }

    $row['id'] = (int)$row['id'];
    $row['role_id'] = (int)($primary['id'] ?? $row['role_id']);
    $row['role_name'] = $primary['name'] ?? ($roleNames[0] ?? 'Unknown');
    $row['role_names'] = $roleNames;
    $row['roles'] = $roles;
    $row['custom_permissions'] = $custom;
    $row['permissions'] = $merged;
    $row['phone'] = $row['phone'] ?? null;
    $row['must_change_password'] = !empty($row['must_change_password']);
    $row['display_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: $row['username'];
    $cache[$userId] = $row;
    return $row;
}

/**
 * Invalidate static ACL cache.
 */
function clearUserAclCache(?int $userId = null): void {
    $GLOBALS['__temper_acl_cache_generation'] = ((int)($GLOBALS['__temper_acl_cache_generation'] ?? 0)) + 1;
}

/**
 * Check whether a user has a permission.
 */
function userHasPermission(mysqli $db, int $userId, string $permission): bool {
    $acl = loadUserAcl($db, $userId);
    if (!$acl) {
        return false;
    }
    return permissionSetAllows($acl['permissions'], $permission);
}

/**
 * Check whether the current session user has a permission.
 */
function currentUserHasPermission(mysqli $db, string $permission): bool {
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    return userHasPermission($db, (int)$user['id'], $permission);
}

/**
 * True if user is Administrator (role name or full * permission).
 */
function userIsAdministrator(mysqli $db, int $userId): bool {
    $acl = loadUserAcl($db, $userId);
    if (!$acl) {
        return false;
    }
    if (in_array('Administrator', $acl['role_names'] ?? [], true)) {
        return true;
    }
    if (($acl['role_name'] ?? '') === 'Administrator') {
        return true;
    }
    return permissionSetAllows($acl['permissions'], '*');
}

/**
 * Emit a permission-denied response and stop.
 */
function denyPermission(string $message = 'You do not have permission to perform this action.'): void {
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    $wantsJson = function_exists('wantsJsonAuthResponse') && wantsJsonAuthResponse();
    $isAjax = function_exists('isAjaxOrApiRequest') && isAjaxOrApiRequest();

    if ($wantsJson) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'permission_denied' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($isAjax) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="alert alert-warning mb-0" role="alert">'
            . '<i class="bi bi-shield-lock me-1"></i>'
            . htmlspecialchars($message)
            . '</div>';
        exit;
    }

    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Access Denied</title>'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">'
        . '</head><body class="p-4"><div class="alert alert-warning">'
        . htmlspecialchars($message)
        . ' <a href="index.php">Return home</a></div></body></html>';
    exit;
}

/**
 * Require the current user to hold a permission (after requireLogin).
 *
 * @return array ACL user row
 */
function requirePermission(mysqli $db, string $permission, string $message = 'You do not have permission to access this page.'): array {
    requireLogin($db);
    $user = getCurrentUser();
    if (!$user) {
        denyUnauthenticatedAccess();
    }
    $acl = loadUserAcl($db, (int)$user['id']);
    if (!$acl || !permissionSetAllows($acl['permissions'], $permission)) {
        denyPermission($message);
    }
    return $acl;
}

/**
 * Require Administrator role / full access.
 *
 * @return array ACL user row
 */
function requireAdministrator(mysqli $db, string $message = 'Administrator access required.'): array {
    requireLogin($db);
    $user = getCurrentUser();
    if (!$user) {
        denyUnauthenticatedAccess();
    }
    if (!userIsAdministrator($db, (int)$user['id'])) {
        denyPermission($message);
    }
    return loadUserAcl($db, (int)$user['id']);
}

/**
 * Infer SPA page key from SCRIPT_NAME and enforce its mapped permission.
 *
 * @return array|null ACL row when a permission was enforced; null otherwise
 */
function requirePagePermission(mysqli $db, ?string $pageKey = null): ?array {
    if ($pageKey === null || $pageKey === '') {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = basename($script, '.php');
        $pageKey = $base;
    }
    $map = temperPagePermissionMap();
    if (!isset($map[$pageKey])) {
        return null;
    }
    return requirePermission($db, $map[$pageKey]);
}

/**
 * Seed predefined system roles (setup_db.php only).
 * Does NOT overwrite permissions of existing roles (admin edits are preserved).
 * Only inserts missing system roles and marks known names as is_system.
 * Do not call from page load — runtime must not seed data.
 *
 * @return array{inserted:int,updated:int}
 */
function ensureDefaultRoles(mysqli $db): array {
    ensureUsersRolesSchema($db);
    $inserted = 0;
    $updated = 0;

    $select = $db->prepare('SELECT id, is_system FROM roles WHERE name = ? LIMIT 1');
    $hasIsSystem = temperColumnExists($db, 'roles', 'is_system');
    if ($hasIsSystem) {
        $insert = $db->prepare('INSERT INTO roles (name, description, permissions, is_system) VALUES (?, ?, ?, 1)');
    } else {
        $insert = $db->prepare('INSERT INTO roles (name, description, permissions) VALUES (?, ?, ?)');
    }
    $markSystem = $hasIsSystem
        ? $db->prepare('UPDATE roles SET is_system = 1 WHERE id = ? AND is_system = 0')
        : null;

    foreach (temperDefaultRoles() as $role) {
        $name = $role['name'];
        $description = $role['description'];
        $permJson = encodeRolePermissions($role['permissions']);

        $select->bind_param('s', $name);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc();

        if (!$existing) {
            if ($hasIsSystem) {
                $insert->bind_param('sss', $name, $description, $permJson);
            } else {
                $insert->bind_param('sss', $name, $description, $permJson);
            }
            if ($insert->execute()) {
                $inserted++;
            }
            continue;
        }

        // Mark system; never overwrite description/permissions of existing roles
        if ($markSystem) {
            $id = (int)$existing['id'];
            $markSystem->bind_param('i', $id);
            if ($markSystem->execute() && $markSystem->affected_rows > 0) {
                $updated++;
            }
        }
    }

    $select->close();
    $insert->close();
    if ($markSystem) {
        $markSystem->close();
    }

    return ['inserted' => $inserted, 'updated' => $updated];
}

/**
 * List all roles (for admin UI).
 *
 * @return list<array{id:int,name:string,description:?string,permissions:list<string>,is_system:bool,user_count:int}>
 */
function listRoles(mysqli $db): array {
    ensureUsersRolesSchema($db);
    $rows = [];
    $hasIsSystem = temperColumnExists($db, 'roles', 'is_system');
    $sysCol = $hasIsSystem ? 'COALESCE(is_system, 0) AS is_system' : '0 AS is_system';
    $res = $db->query("SELECT id, name, description, permissions, {$sysCol} FROM roles ORDER BY is_system DESC, name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['permissions'] = decodeRolePermissions($row['permissions'] ?? '[]');
            $row['is_system'] = (int)$row['is_system'] === 1;
            $row['user_count'] = 0;
            $rows[] = $row;
        }
        $res->close();
    }

    // Count active (non-archived) users per role via user_roles + legacy role_id
    $counts = [];
    if (temperTableExists($db, 'user_roles')) {
        $cr = $db->query(
            'SELECT ur.role_id, COUNT(DISTINCT ur.user_id) AS c
             FROM user_roles ur
             INNER JOIN users u ON u.id = ur.user_id AND u.is_active = TRUE
             GROUP BY ur.role_id'
        );
        if ($cr) {
            while ($c = $cr->fetch_assoc()) {
                $counts[(int)$c['role_id']] = (int)$c['c'];
            }
            $cr->close();
        }
    }
    foreach ($rows as &$r) {
        $r['user_count'] = $counts[$r['id']] ?? 0;
    }
    unset($r);

    return $rows;
}

/**
 * Count active administrators (any role Administrator or * permission).
 */
function countActiveAdministrators(mysqli $db, ?int $excludeUserId = null): int {
    ensureUsersRolesSchema($db);
    $sql = 'SELECT u.id FROM users u WHERE u.is_active = TRUE';
    $res = $db->query($sql);
    $count = 0;
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $uid = (int)$row['id'];
            if ($excludeUserId !== null && $uid === $excludeUserId) {
                continue;
            }
            if (userIsAdministrator($db, $uid)) {
                $count++;
            }
        }
        $res->close();
    }
    return $count;
}

/**
 * Whether assigning these role IDs (and optional custom perms) makes a user an admin.
 *
 * @param list<int> $roleIds
 */
function roleIdsIncludeAdministrator(mysqli $db, array $roleIds): bool {
    $roleIds = array_filter(array_map('intval', $roleIds));
    if ($roleIds === []) {
        return false;
    }
    $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
    $types = str_repeat('i', count($roleIds));
    $stmt = $db->prepare("SELECT name, permissions FROM roles WHERE id IN ({$placeholders})");
    $stmt->bind_param($types, ...array_values($roleIds));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if (($row['name'] ?? '') === 'Administrator') {
            $stmt->close();
            return true;
        }
        if (in_array('*', decodeRolePermissions($row['permissions'] ?? '[]'), true)) {
            $stmt->close();
            return true;
        }
    }
    $stmt->close();
    return false;
}

/**
 * Password policy: min 8 characters.
 *
 * @return string|null Error message or null if OK
 */
function validatePasswordStrength(string $password): ?string {
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    return null;
}

/**
 * Hash a password with PHP default algorithm.
 */
function hashUserPassword(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Normalize phone for storage (trim, max 30). Empty → null.
 */
function normalizePhone(?string $phone): ?string {
    $phone = trim((string)$phone);
    if ($phone === '') {
        return null;
    }
    if (strlen($phone) > 30) {
        $phone = substr($phone, 0, 30);
    }
    return $phone;
}

/**
 * Validate optional phone.
 *
 * @return string|null Error or null
 */
function validatePhone(?string $phone): ?string {
    $phone = trim((string)$phone);
    if ($phone === '') {
        return null;
    }
    if (strlen($phone) > 30) {
        return 'Phone number must be 30 characters or fewer.';
    }
    if (!preg_match('/^[0-9+().\-\s]{7,30}$/', $phone)) {
        return 'Phone number contains invalid characters.';
    }
    return null;
}

/**
 * Permission catalog for UI (JSON-serializable).
 *
 * @return list<array{key:string,label:string}>
 */
function permissionCatalogForUi(bool $includeStar = true): array {
    $out = [];
    foreach (TEMPER_PERMISSION_CATALOG as $key => $label) {
        if (!$includeStar && $key === '*') {
            continue;
        }
        $out[] = ['key' => $key, 'label' => $label];
    }
    return $out;
}

/**
 * True if session user must change password before using the app.
 */
function sessionMustChangePassword(): bool {
    return !empty($_SESSION['must_change_password']);
}

/**
 * Clear force-password flag in session and DB after successful change.
 */
function clearMustChangePassword(mysqli $db, int $userId): void {
    if (temperColumnExists($db, 'users', 'must_change_password')) {
        if (temperColumnExists($db, 'users', 'force_password_set_at')) {
            $stmt = $db->prepare('UPDATE users SET must_change_password = 0, force_password_set_at = NULL WHERE id = ?');
        } else {
            $stmt = $db->prepare('UPDATE users SET must_change_password = 0 WHERE id = ?');
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
    unset($_SESSION['must_change_password']);
    clearUserAclCache();
}

/**
 * Apply must_change_password flag and (re)start the auto-archive grace clock when enabling.
 */
function setUserMustChangePassword(mysqli $db, int $userId, bool $mustChange): void {
    ensureUsersRolesSchema($db);
    if (!temperColumnExists($db, 'users', 'must_change_password')) {
        return;
    }
    $flag = $mustChange ? 1 : 0;
    if ($mustChange && temperColumnExists($db, 'users', 'force_password_set_at')) {
        $stmt = $db->prepare(
            'UPDATE users SET must_change_password = 1, force_password_set_at = NOW() WHERE id = ?'
        );
        $stmt->bind_param('i', $userId);
    } elseif (!$mustChange && temperColumnExists($db, 'users', 'force_password_set_at')) {
        $stmt = $db->prepare(
            'UPDATE users SET must_change_password = 0, force_password_set_at = NULL WHERE id = ?'
        );
        $stmt->bind_param('i', $userId);
    } else {
        $stmt = $db->prepare('UPDATE users SET must_change_password = ? WHERE id = ?');
        $stmt->bind_param('ii', $flag, $userId);
    }
    $stmt->execute();
    $stmt->close();
}

/**
 * Restore (unarchive) a user: is_active=1, clear archived_at.
 * If they still must change password, restart the force-password grace period so
 * auto-archive does not immediately reverse an admin restore.
 *
 * @return string|null Error message or null on success
 */
function restoreArchivedUser(mysqli $db, int $userId): ?string {
    ensureUsersRolesSchema($db);
    $hasForceAt = temperColumnExists($db, 'users', 'force_password_set_at');
    $hasMust = temperColumnExists($db, 'users', 'must_change_password');

    if ($hasForceAt && $hasMust) {
        // Restart grace only when force-password is still required
        $stmt = $db->prepare(
            'UPDATE users
             SET is_active = 1,
                 archived_at = NULL,
                 force_password_set_at = CASE
                     WHEN must_change_password = 1 THEN NOW()
                     ELSE force_password_set_at
                 END
             WHERE id = ?'
        );
    } else {
        $stmt = $db->prepare('UPDATE users SET is_active = 1, archived_at = NULL WHERE id = ?');
    }
    if (!$stmt) {
        return 'Prepare failed: ' . $db->error;
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $msg = $stmt->error;
        $stmt->close();
        return 'Failed to restore user: ' . $msg;
    }
    $stmt->close();
    return null;
}

/**
 * Archive (soft-delete) a user: is_active=0, set archived_at.
 *
 * @return string|null Error message or null on success
 */
function archiveUserAccount(mysqli $db, int $userId): ?string {
    ensureUsersRolesSchema($db);
    $stmt = $db->prepare('UPDATE users SET is_active = 0, archived_at = NOW() WHERE id = ?');
    if (!$stmt) {
        return 'Prepare failed: ' . $db->error;
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $msg = $stmt->error;
        $stmt->close();
        return 'Failed to archive user: ' . $msg;
    }
    $stmt->close();
    return null;
}

/** Default temporary password offered when creating users (changeable by admin). */
const TEMPER_DEFAULT_TEMP_PASSWORD = 'hopebaptist';

/**
 * Default hours for force-password auto-archive when system config is unavailable.
 * Prefer getAutoArchiveTimerHours() at runtime.
 */
const TEMPER_FORCE_PASSWORD_GRACE_HOURS = 24;

/**
 * Effective auto-archive grace hours from System Configuration (fallback: constant).
 */
function getForcePasswordGraceHours(): int {
    if (function_exists('getAutoArchiveTimerHours')) {
        return getAutoArchiveTimerHours();
    }
    return (int)TEMPER_FORCE_PASSWORD_GRACE_HOURS;
}

/**
 * Auto-archive users who still must change password after the grace period
 * from when force-password was last set (not from original account creation).
 * No-op when Disable Auto-Archive is enabled in System Configuration.
 *
 * @return int Number of users archived
 */
function archiveExpiredForcePasswordUsers(mysqli $db): int {
    ensureUsersRolesSchema($db);
    if (!temperColumnExists($db, 'users', 'must_change_password')) {
        return 0;
    }

    static $ran = false;
    if ($ran) {
        return 0;
    }
    $ran = true;

    // System Configuration: Disable Auto-Archive
    if (function_exists('isAutoArchiveEnabled') && !isAutoArchiveEnabled()) {
        return 0;
    }

    $hours = getForcePasswordGraceHours();
    if ($hours < 1) {
        $hours = (int)TEMPER_FORCE_PASSWORD_GRACE_HOURS;
    }
    $hours = (int)$hours;

    $hasForceAt = temperColumnExists($db, 'users', 'force_password_set_at');
    // Prefer force_password_set_at (restarted on restore / password reset); fall back to created_at
    if ($hasForceAt) {
        $sql = "SELECT id, username FROM users
                WHERE is_active = TRUE
                  AND must_change_password = 1
                  AND COALESCE(force_password_set_at, created_at) IS NOT NULL
                  AND COALESCE(force_password_set_at, created_at) < (NOW() - INTERVAL {$hours} HOUR)";
    } else {
        $sql = "SELECT id, username FROM users
                WHERE is_active = TRUE
                  AND must_change_password = 1
                  AND created_at IS NOT NULL
                  AND created_at < (NOW() - INTERVAL {$hours} HOUR)";
    }
    $res = $db->query($sql);
    if (!$res) {
        return 0;
    }

    $ids = [];
    $names = [];
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['id'];
        $names[(int)$row['id']] = (string)$row['username'];
    }
    $res->close();
    if ($ids === []) {
        return 0;
    }

    require_once __DIR__ . '/audit.php';

    $archived = 0;
    $upd = $db->prepare(
        'UPDATE users SET is_active = 0, archived_at = NOW()
         WHERE id = ? AND is_active = TRUE AND must_change_password = 1'
    );
    foreach ($ids as $id) {
        // Never archive the last active administrator
        if (userIsAdministrator($db, $id) && countActiveAdministrators($db, $id) < 1) {
            continue;
        }
        $upd->bind_param('i', $id);
        if ($upd->execute() && $upd->affected_rows > 0) {
            $archived++;
            $uname = $names[$id] ?? ('id=' . $id);
            logAuditAction(
                $db,
                null,
                'system',
                'user_auto_archive',
                "Auto-archived user id={$id} username={$uname}: must_change_password not completed within {$hours}h of force-password set"
            );
        }
    }
    $upd->close();

    if ($archived > 0) {
        clearUserAclCache();
    }
    return $archived;
}
?>

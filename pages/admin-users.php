<?php
/**
 * Admin — Users & Roles management.
 * Administrator-only: users (multi-role, custom perms, phone, force password),
 * role create/edit, archive/deactivate, audit logging.
 */
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/audit.php';

$actor = requireAdministrator($db, 'Only administrators can manage users and roles.');
ensureUsersRolesSchema($db);
ensureDefaultRoles($db);
clearUserAclCache();

function usersSendJson(array $payload, ?mysqli $db = null): void {
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

/** @return list<int> */
function usersParseIdList(mixed $raw): array {
    if (is_string($raw)) {
        $trim = trim($raw);
        if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
            $decoded = json_decode($trim, true);
            $raw = is_array($decoded) ? $decoded : $raw;
        }
    }
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = preg_split('/[\s,]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $ids = [];
    foreach ($parts as $p) {
        $id = (int)$p;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

/** @return list<string> */
function usersParsePermList(mixed $raw): array {
    if (is_string($raw)) {
        $trim = trim($raw);
        if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
            $decoded = json_decode($trim, true);
            $raw = is_array($decoded) ? $decoded : $raw;
        }
    }
    if (is_array($raw)) {
        $parts = $raw;
    } elseif (is_string($raw) && $raw !== '') {
        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    } else {
        $parts = [];
    }
    return sanitizePermissionList(array_map('strval', $parts), false);
}

function usersFetchList(mysqli $db): array {
    $rows = [];
    $cols = 'u.id, u.username, u.first_name, u.last_name, u.email, u.is_active,
             u.last_login, u.created_at, u.role_id';
    if (temperColumnExists($db, 'users', 'phone')) {
        $cols .= ', u.phone';
    }
    if (temperColumnExists($db, 'users', 'must_change_password')) {
        $cols .= ', u.must_change_password';
    }
    if (temperColumnExists($db, 'users', 'custom_permissions')) {
        $cols .= ', u.custom_permissions';
    }
    if (temperColumnExists($db, 'users', 'archived_at')) {
        $cols .= ', u.archived_at';
    }

    $sql = "SELECT {$cols} FROM users u ORDER BY u.is_active DESC, u.username ASC";
    $res = $db->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $uid = (int)$row['id'];
            $roles = getUserRoles($db, $uid);
            $roleNames = array_map(static fn($r) => $r['name'], $roles);
            $roleIds = array_map(static fn($r) => $r['id'], $roles);
            $primary = null;
            foreach ($roles as $r) {
                if ($r['is_primary']) {
                    $primary = $r;
                    break;
                }
            }
            if (!$primary && $roles !== []) {
                $primary = $roles[0];
            }

            $rows[] = [
                'id' => $uid,
                'username' => $row['username'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'email' => $row['email'],
                'phone' => $row['phone'] ?? null,
                'is_active' => (int)$row['is_active'] === 1,
                'must_change_password' => !empty($row['must_change_password']),
                'custom_permissions' => decodeRolePermissions($row['custom_permissions'] ?? '[]'),
                'last_login' => $row['last_login'],
                'created_at' => $row['created_at'],
                'archived_at' => $row['archived_at'] ?? null,
                'role_id' => $primary ? (int)$primary['id'] : (int)$row['role_id'],
                'role_name' => $primary['name'] ?? '—',
                'role_names' => $roleNames,
                'role_ids' => $roleIds,
                'display_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: $row['username'],
            ];
        }
        $res->close();
    }
    return $rows;
}

function usersNormalizeUsername(string $username): string {
    return strtolower(trim($username));
}

function usersValidateIdentityFields(string $username, string $first, string $last, string $email): ?string {
    if ($username === '' || !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
        return 'Username must be 3–50 characters (letters, numbers, . _ -).';
    }
    if ($first === '' || strlen($first) > 50) {
        return 'First name is required (max 50 characters).';
    }
    if ($last === '' || strlen($last) > 50) {
        return 'Last name is required (max 50 characters).';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        return 'A valid email address is required.';
    }
    return null;
}

function usersGetById(mysqli $db, int $id): ?array {
    foreach (usersFetchList($db) as $u) {
        if ((int)$u['id'] === $id) {
            return $u;
        }
    }
    return null;
}

function usersPayloadExtras(mysqli $db): array {
    return [
        'users' => usersFetchList($db),
        'roles' => listRoles($db),
    ];
}

// ── JSON API ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    $actorId = (int)$actor['id'];
    $actorUsername = (string)$actor['username'];

    // ── Users ───────────────────────────────────────────────────────────────
    if ($action === 'create_user') {
        $username = usersNormalizeUsername((string)($_POST['username'] ?? ''));
        $first = trim((string)($_POST['first_name'] ?? ''));
        $last = trim((string)($_POST['last_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = normalizePhone($_POST['phone'] ?? null);
        $roleIds = usersParseIdList($_POST['role_ids'] ?? ($_POST['role_id'] ?? []));
        $customPerms = usersParsePermList($_POST['custom_permissions'] ?? []);
        $mustChange = !empty($_POST['must_change_password']) ? 1 : 0;
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

        $err = usersValidateIdentityFields($username, $first, $last, $email);
        if ($err) {
            usersSendJson(['success' => false, 'error' => $err], $db);
        }
        $phoneErr = validatePhone($phone);
        if ($phoneErr) {
            usersSendJson(['success' => false, 'error' => $phoneErr], $db);
        }
        if ($roleIds === []) {
            usersSendJson(['success' => false, 'error' => 'Select at least one role.'], $db);
        }
        if ($password !== $passwordConfirm) {
            usersSendJson(['success' => false, 'error' => 'Passwords do not match.'], $db);
        }
        $pwErr = validatePasswordStrength($password);
        if ($pwErr) {
            usersSendJson(['success' => false, 'error' => $pwErr], $db);
        }

        $primaryRoleId = $roleIds[0];
        $hash = hashUserPassword($password);
        $customJson = encodeRolePermissions($customPerms);
        $phoneVal = $phone; // null allowed via bind

        $stmt = $db->prepare(
            'INSERT INTO users (role_id, username, first_name, last_name, email, phone, password, is_active, must_change_password, custom_permissions)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
        );
        if (!$stmt) {
            usersSendJson(['success' => false, 'error' => 'Prepare failed: ' . $db->error], $db);
        }
        $stmt->bind_param(
            'issssssis',
            $primaryRoleId,
            $username,
            $first,
            $last,
            $email,
            $phoneVal,
            $hash,
            $mustChange,
            $customJson
        );
        if (!$stmt->execute()) {
            $msg = $stmt->error;
            $stmt->close();
            if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'unique') !== false) {
                usersSendJson(['success' => false, 'error' => 'Username or email already exists.'], $db);
            }
            usersSendJson(['success' => false, 'error' => 'Failed to create user: ' . $msg], $db);
        }
        $newId = (int)$stmt->insert_id;
        $stmt->close();

        $roleErr = setUserRoles($db, $newId, $roleIds);
        if ($roleErr) {
            usersSendJson(['success' => false, 'error' => $roleErr], $db);
        }

        $roleLabel = implode(', ', array_map(static function ($id) use ($db) {
            foreach (listRoles($db) as $r) {
                if ($r['id'] === $id) {
                    return $r['name'];
                }
            }
            return (string)$id;
        }, $roleIds));

        logAuditAction(
            $db,
            $actorId,
            $actorUsername,
            'user_create',
            "Created user id={$newId} username={$username} roles=[{$roleLabel}] must_change={$mustChange} custom_perms=" . count($customPerms)
        );
        usersSendJson(array_merge([
            'success' => true,
            'message' => "User \"{$username}\" created.",
        ], usersPayloadExtras($db)), $db);
    }

    if ($action === 'update_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $first = trim((string)($_POST['first_name'] ?? ''));
        $last = trim((string)($_POST['last_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = normalizePhone($_POST['phone'] ?? null);
        $roleIds = usersParseIdList($_POST['role_ids'] ?? ($_POST['role_id'] ?? []));
        $customPerms = usersParsePermList($_POST['custom_permissions'] ?? []);
        $mustChange = !empty($_POST['must_change_password']) ? 1 : 0;

        $target = usersGetById($db, $userId);
        if (!$target) {
            usersSendJson(['success' => false, 'error' => 'User not found.'], $db);
        }
        $err = usersValidateIdentityFields($target['username'], $first, $last, $email);
        if ($err) {
            usersSendJson(['success' => false, 'error' => $err], $db);
        }
        $phoneErr = validatePhone($phone);
        if ($phoneErr) {
            usersSendJson(['success' => false, 'error' => $phoneErr], $db);
        }
        if ($roleIds === []) {
            usersSendJson(['success' => false, 'error' => 'Select at least one role.'], $db);
        }

        $wasAdmin = roleIdsIncludeAdministrator($db, $target['role_ids'] ?? [$target['role_id']]);
        $willBeAdmin = roleIdsIncludeAdministrator($db, $roleIds);
        if ($wasAdmin && !$willBeAdmin && $target['is_active']) {
            if (countActiveAdministrators($db, $userId) < 1) {
                usersSendJson([
                    'success' => false,
                    'error' => 'Cannot remove the last active Administrator role assignment.',
                ], $db);
            }
        }

        $dup = $db->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $dup->bind_param('si', $email, $userId);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            $dup->close();
            usersSendJson(['success' => false, 'error' => 'Email is already in use by another account.'], $db);
        }
        $dup->close();

        $customJson = encodeRolePermissions($customPerms);
        $phoneVal = $phone ?? '';
        $primaryRoleId = $roleIds[0];
        $stmt = $db->prepare(
            'UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, role_id = ?,
             must_change_password = ?, custom_permissions = ? WHERE id = ?'
        );
        $stmt->bind_param(
            'ssssiisi',
            $first,
            $last,
            $email,
            $phoneVal,
            $primaryRoleId,
            $mustChange,
            $customJson,
            $userId
        );
        if (!$stmt->execute()) {
            $msg = $stmt->error;
            $stmt->close();
            usersSendJson(['success' => false, 'error' => 'Failed to update user: ' . $msg], $db);
        }
        $stmt->close();
        if ($phone === null) {
            $nullPhone = $db->prepare('UPDATE users SET phone = NULL WHERE id = ?');
            $nullPhone->bind_param('i', $userId);
            $nullPhone->execute();
            $nullPhone->close();
        }

        $roleErr = setUserRoles($db, $userId, $roleIds);
        if ($roleErr) {
            usersSendJson(['success' => false, 'error' => $roleErr], $db);
        }
        clearUserAclCache();

        logAuditAction(
            $db,
            $actorId,
            $actorUsername,
            'user_update',
            "Updated user id={$userId} username={$target['username']} roles=[" . implode(',', $roleIds) . "] must_change={$mustChange} custom_perms=" . count($customPerms)
        );
        usersSendJson(array_merge([
            'success' => true,
            'message' => 'User updated.',
        ], usersPayloadExtras($db)), $db);
    }

    if ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
        $mustChange = !empty($_POST['must_change_password']) ? 1 : 0;

        $target = usersGetById($db, $userId);
        if (!$target) {
            usersSendJson(['success' => false, 'error' => 'User not found.'], $db);
        }
        if ($password !== $passwordConfirm) {
            usersSendJson(['success' => false, 'error' => 'Passwords do not match.'], $db);
        }
        $pwErr = validatePasswordStrength($password);
        if ($pwErr) {
            usersSendJson(['success' => false, 'error' => $pwErr], $db);
        }

        $hash = hashUserPassword($password);
        $stmt = $db->prepare('UPDATE users SET password = ?, must_change_password = ? WHERE id = ?');
        $stmt->bind_param('sii', $hash, $mustChange, $userId);
        if (!$stmt->execute()) {
            $msg = $stmt->error;
            $stmt->close();
            usersSendJson(['success' => false, 'error' => 'Failed to reset password: ' . $msg], $db);
        }
        $stmt->close();

        logAuditAction(
            $db,
            $actorId,
            $actorUsername,
            'user_password_reset',
            "Reset password for user id={$userId} username={$target['username']} must_change={$mustChange}"
        );
        usersSendJson(array_merge([
            'success' => true,
            'message' => 'Password reset for ' . $target['username'] . '.',
        ], usersPayloadExtras($db)), $db);
    }

    if ($action === 'set_active') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $active = ((int)($_POST['is_active'] ?? 0)) === 1 ? 1 : 0;

        $target = usersGetById($db, $userId);
        if (!$target) {
            usersSendJson(['success' => false, 'error' => 'User not found.'], $db);
        }
        if ($userId === $actorId && $active === 0) {
            usersSendJson(['success' => false, 'error' => 'You cannot archive your own account.'], $db);
        }

        if ($active === 0 && roleIdsIncludeAdministrator($db, $target['role_ids'] ?? []) && $target['is_active']) {
            if (countActiveAdministrators($db, $userId) < 1) {
                usersSendJson([
                    'success' => false,
                    'error' => 'Cannot archive the last active Administrator.',
                ], $db);
            }
        }

        if ($active === 1) {
            $stmt = $db->prepare('UPDATE users SET is_active = 1, archived_at = NULL WHERE id = ?');
            $stmt->bind_param('i', $userId);
        } else {
            $stmt = $db->prepare('UPDATE users SET is_active = 0, archived_at = NOW() WHERE id = ?');
            $stmt->bind_param('i', $userId);
        }
        if (!$stmt->execute()) {
            $msg = $stmt->error;
            $stmt->close();
            usersSendJson(['success' => false, 'error' => 'Failed to update status: ' . $msg], $db);
        }
        $stmt->close();
        clearUserAclCache();

        $label = $active ? 'restored' : 'archived';
        logAuditAction(
            $db,
            $actorId,
            $actorUsername,
            $active ? 'user_activate' : 'user_archive',
            ucfirst($label) . " user id={$userId} username={$target['username']}"
        );
        usersSendJson(array_merge([
            'success' => true,
            'message' => 'User ' . $target['username'] . ' ' . $label . '.',
        ], usersPayloadExtras($db)), $db);
    }

    // ── Roles ───────────────────────────────────────────────────────────────
    if ($action === 'create_role') {
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $perms = usersParsePermList($_POST['permissions'] ?? []);

        if ($name === '' || strlen($name) > 50) {
            usersSendJson(['success' => false, 'error' => 'Role name is required (max 50 characters).'], $db);
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-]{1,49}$/', $name)) {
            usersSendJson(['success' => false, 'error' => 'Role name has invalid characters.'], $db);
        }
        if ($perms === []) {
            usersSendJson(['success' => false, 'error' => 'Select at least one permission (profile.self is recommended).'], $db);
        }
        // Always include profile.self for usability
        if (!in_array('profile.self', $perms, true) && !in_array('*', $perms, true)) {
            $perms[] = 'profile.self';
        }
        $permJson = encodeRolePermissions($perms);

        $stmt = $db->prepare('INSERT INTO roles (name, description, permissions, is_system) VALUES (?, ?, ?, 0)');
        $stmt->bind_param('sss', $name, $description, $permJson);
        if (!$stmt->execute()) {
            $msg = $stmt->error;
            $stmt->close();
            if (stripos($msg, 'Duplicate') !== false) {
                usersSendJson(['success' => false, 'error' => 'A role with that name already exists.'], $db);
            }
            usersSendJson(['success' => false, 'error' => 'Failed to create role: ' . $msg], $db);
        }
        $newRoleId = (int)$stmt->insert_id;
        $stmt->close();

        logAuditAction($db, $actorId, $actorUsername, 'role_create', "Created role id={$newRoleId} name={$name} perms=" . count($perms));
        usersSendJson(array_merge([
            'success' => true,
            'message' => "Role \"{$name}\" created.",
        ], usersPayloadExtras($db)), $db);
    }

    if ($action === 'update_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $perms = usersParsePermList($_POST['permissions'] ?? []);

        $stmt = $db->prepare('SELECT id, name, is_system FROM roles WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$existing) {
            usersSendJson(['success' => false, 'error' => 'Role not found.'], $db);
        }

        $isSystem = (int)($existing['is_system'] ?? 0) === 1;
        // System roles: allow description + permissions edit; name locked for Administrator
        if ($isSystem && ($existing['name'] ?? '') === 'Administrator') {
            $name = 'Administrator';
            $perms = ['*'];
        } else {
            if ($name === '' || strlen($name) > 50) {
                usersSendJson(['success' => false, 'error' => 'Role name is required (max 50 characters).'], $db);
            }
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-]{1,49}$/', $name)) {
                usersSendJson(['success' => false, 'error' => 'Role name has invalid characters.'], $db);
            }
            if ($perms === []) {
                usersSendJson(['success' => false, 'error' => 'Select at least one permission.'], $db);
            }
            if (!in_array('profile.self', $perms, true) && !in_array('*', $perms, true)) {
                $perms[] = 'profile.self';
            }
        }

        $permJson = encodeRolePermissions($perms);
        $upd = $db->prepare('UPDATE roles SET name = ?, description = ?, permissions = ? WHERE id = ?');
        $upd->bind_param('sssi', $name, $description, $permJson, $roleId);
        if (!$upd->execute()) {
            $msg = $upd->error;
            $upd->close();
            if (stripos($msg, 'Duplicate') !== false) {
                usersSendJson(['success' => false, 'error' => 'A role with that name already exists.'], $db);
            }
            usersSendJson(['success' => false, 'error' => 'Failed to update role: ' . $msg], $db);
        }
        $upd->close();
        clearUserAclCache();

        logAuditAction($db, $actorId, $actorUsername, 'role_update', "Updated role id={$roleId} name={$name} perms=" . count($perms));
        usersSendJson(array_merge([
            'success' => true,
            'message' => "Role \"{$name}\" updated.",
        ], usersPayloadExtras($db)), $db);
    }

    if ($action === 'delete_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $stmt = $db->prepare('SELECT id, name, is_system FROM roles WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$existing) {
            usersSendJson(['success' => false, 'error' => 'Role not found.'], $db);
        }
        if ((int)($existing['is_system'] ?? 0) === 1) {
            usersSendJson(['success' => false, 'error' => 'System roles cannot be deleted.'], $db);
        }

        // Block if assigned
        $cnt = 0;
        $c = $db->prepare('SELECT COUNT(*) AS c FROM user_roles WHERE role_id = ?');
        $c->bind_param('i', $roleId);
        $c->execute();
        $cnt = (int)($c->get_result()->fetch_assoc()['c'] ?? 0);
        $c->close();
        if ($cnt === 0) {
            $c2 = $db->prepare('SELECT COUNT(*) AS c FROM users WHERE role_id = ?');
            $c2->bind_param('i', $roleId);
            $c2->execute();
            $cnt = (int)($c2->get_result()->fetch_assoc()['c'] ?? 0);
            $c2->close();
        }
        if ($cnt > 0) {
            usersSendJson(['success' => false, 'error' => 'Cannot delete a role that is still assigned to users.'], $db);
        }

        $del = $db->prepare('DELETE FROM roles WHERE id = ? AND is_system = 0');
        $del->bind_param('i', $roleId);
        if (!$del->execute() || $del->affected_rows < 1) {
            $del->close();
            usersSendJson(['success' => false, 'error' => 'Failed to delete role.'], $db);
        }
        $del->close();

        logAuditAction($db, $actorId, $actorUsername, 'role_delete', "Deleted role id={$roleId} name={$existing['name']}");
        usersSendJson(array_merge([
            'success' => true,
            'message' => 'Role deleted.',
        ], usersPayloadExtras($db)), $db);
    }

    usersSendJson(['success' => false, 'error' => 'Unknown action.'], $db);
}

// ── HTML ────────────────────────────────────────────────────────────────────
$users = usersFetchList($db);
$roles = listRoles($db);
$permCatalog = permissionCatalogForUi(true);
$permCatalogNoStar = permissionCatalogForUi(false);
?>

<style>
    .users-table td, .users-table th { vertical-align: middle; font-size: 0.9rem; }
    .users-badge-inactive { opacity: 0.75; }
    .users-perm-chip {
        display: inline-block;
        font-size: 0.7rem;
        padding: 0.1rem 0.4rem;
        margin: 0.1rem;
        border-radius: 0.25rem;
        background: var(--bs-secondary-bg);
        border: 1px solid var(--bs-border-color);
        cursor: default;
    }
    .users-role-title {
        background: none;
        border: none;
        padding: 0;
        color: var(--bs-primary);
        font-weight: 600;
        font-size: 0.875rem;
        text-decoration: underline;
        text-underline-offset: 2px;
        cursor: pointer;
    }
    .users-role-title:hover { color: var(--bs-primary); opacity: 0.85; }
    .users-perm-collapsed .users-perm-extra { display: none; }
    .users-perm-toggle {
        font-size: 0.7rem;
        padding: 0.05rem 0.4rem;
        margin: 0.1rem;
    }
    .users-check-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.15rem;
        max-height: 220px;
        overflow-y: auto;
        border: 1px solid var(--bs-border-color);
        border-radius: 0.375rem;
        padding: 0.5rem;
        background: var(--bs-body-bg);
    }
    @media (min-width: 576px) {
        .users-check-grid { grid-template-columns: 1fr 1fr; }
    }
    .users-check-grid label {
        font-size: 0.8rem;
        margin: 0;
        display: flex;
        gap: 0.35rem;
        align-items: flex-start;
    }
</style>

<div class="row mb-3">
    <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <a href="javascript:void(0)" onclick="loadPage('admin')" class="small text-decoration-none">
                <i class="bi bi-arrow-left"></i> Admin
            </a>
            <h2 class="h4 mb-0 mt-1">Users &amp; Roles</h2>
            <p class="text-muted small mb-0">Accounts, multi-role assignment, custom permissions, and role management.</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-primary btn-sm" id="rolesBtnCreate">
                <i class="bi bi-shield-plus"></i> New Role
            </button>
            <button type="button" class="btn btn-primary btn-sm" id="usersBtnCreate">
                <i class="bi bi-person-plus"></i> New User
            </button>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <span class="fw-semibold small"><i class="bi bi-people me-1"></i> User Accounts</span>
                <span class="badge text-bg-secondary" id="usersCountBadge"><?= count($users) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 users-table" id="usersTable">
                        <thead class="table-light">
                            <tr>
                                <th>User</th>
                                <th>Roles</th>
                                <th>Status</th>
                                <th class="d-none d-md-table-cell">Last login</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <span class="fw-semibold small"><i class="bi bi-shield-lock me-1"></i> Roles</span>
                <span class="badge text-bg-secondary" id="rolesCountBadge"><?= count($roles) ?></span>
            </div>
            <div class="card-body" id="rolesListBody">
                <p class="small text-muted">Click a role name to edit its details and permissions.</p>
                <div class="list-group list-group-flush" id="rolesList"></div>
            </div>
        </div>
    </div>
</div>

<!-- User Create / Edit Modal -->
<div class="modal fade" id="usersEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" id="usersEditForm" autocomplete="off">
            <div class="modal-header py-2">
                <h5 class="modal-title h6" id="usersEditModalLabel">User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="usersEditId" value="">
                <div class="row g-2">
                    <div class="col-md-6" id="usersUsernameGroup">
                        <label class="form-label small" for="usersUsername">Username</label>
                        <input type="text" class="form-control form-control-sm" id="usersUsername"
                               minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]+" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small" for="usersEmail">Email</label>
                        <input type="email" class="form-control form-control-sm" id="usersEmail" maxlength="100" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small" for="usersFirst">First name</label>
                        <input type="text" class="form-control form-control-sm" id="usersFirst" maxlength="50" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small" for="usersLast">Last name</label>
                        <input type="text" class="form-control form-control-sm" id="usersLast" maxlength="50" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small" for="usersPhone">Phone</label>
                        <input type="tel" class="form-control form-control-sm" id="usersPhone" maxlength="30"
                               placeholder="Optional" autocomplete="tel">
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label small mb-1">Roles <span class="text-muted">(select one or more; first checked is primary)</span></label>
                    <div class="users-check-grid" id="usersRoleChecks"></div>
                </div>

                <div class="mt-3">
                    <label class="form-label small mb-1">Custom permissions <span class="text-muted">(additive to roles)</span></label>
                    <div class="users-check-grid" id="usersCustomPermChecks"></div>
                </div>

                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" id="usersMustChange" value="1">
                    <label class="form-check-label small" for="usersMustChange">
                        Force password change on next login
                    </label>
                    <div class="form-text">
                        If still required after 24 hours from account creation, the user is auto-archived.
                    </div>
                </div>

                <div id="usersPasswordFields" class="mt-3">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small" for="usersPassword">Password</label>
                            <input type="text" class="form-control form-control-sm" id="usersPassword"
                                   minlength="8" autocomplete="new-password" spellcheck="false">
                            <div class="form-text">Default temporary password is prefilled; change if needed.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small" for="usersPasswordConfirm">Confirm password</label>
                            <input type="text" class="form-control form-control-sm" id="usersPasswordConfirm"
                                   minlength="8" autocomplete="new-password" spellcheck="false">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="usersPwModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <form class="modal-content" id="usersPwForm" autocomplete="off">
            <div class="modal-header py-2">
                <h5 class="modal-title h6">Reset password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="usersPwId" value="">
                <p class="small text-muted" id="usersPwHint">Set a new password for this user.</p>
                <div class="mb-2">
                    <label class="form-label small" for="usersPwNew">New password</label>
                    <input type="password" class="form-control form-control-sm" id="usersPwNew" minlength="8" required autocomplete="new-password">
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="usersPwConfirm">Confirm</label>
                    <input type="password" class="form-control form-control-sm" id="usersPwConfirm" minlength="8" required autocomplete="new-password">
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="usersPwMustChange" value="1" checked>
                    <label class="form-check-label small" for="usersPwMustChange">Force change on next login</label>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning btn-sm">Reset password</button>
            </div>
        </form>
    </div>
</div>

<!-- Role Create / Edit Modal -->
<div class="modal fade" id="rolesEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" id="rolesEditForm" autocomplete="off">
            <div class="modal-header py-2">
                <h5 class="modal-title h6" id="rolesEditModalLabel">Role</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rolesEditId" value="">
                <div class="mb-2">
                    <label class="form-label small" for="rolesName">Name</label>
                    <input type="text" class="form-control form-control-sm" id="rolesName" maxlength="50" required>
                    <div class="form-text" id="rolesNameHint"></div>
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="rolesDescription">Description</label>
                    <textarea class="form-control form-control-sm" id="rolesDescription" rows="2"></textarea>
                </div>
                <div>
                    <label class="form-label small mb-1">Permissions</label>
                    <div class="users-check-grid" id="rolesPermChecks"></div>
                </div>
            </div>
            <div class="modal-footer py-2 d-flex justify-content-between">
                <button type="button" class="btn btn-outline-danger btn-sm" id="rolesDeleteBtn" style="display:none;">
                    <i class="bi bi-trash"></i> Delete role
                </button>
                <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save role</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script type="text/plain" id="init-admin-users-script">
(function() {
    const endpoint = 'pages/admin-users.php';
    const actorId = <?= (int)$actor['id'] ?>;
    const permCatalog = <?= json_encode($permCatalog, JSON_UNESCAPED_UNICODE) ?>;
    const permCatalogNoStar = <?= json_encode($permCatalogNoStar, JSON_UNESCAPED_UNICODE) ?>;
    const permLabels = {};
    permCatalog.forEach(function(p) { permLabels[p.key] = p.label; });

    let usersState = <?= json_encode($users, JSON_UNESCAPED_UNICODE) ?>;
    let rolesState = <?= json_encode($roles, JSON_UNESCAPED_UNICODE) ?>;

    const editModal = new bootstrap.Modal(document.getElementById('usersEditModal'));
    const pwModal = new bootstrap.Modal(document.getElementById('usersPwModal'));
    const roleModal = new bootstrap.Modal(document.getElementById('rolesEditModal'));
    let editMode = 'create';
    let roleMode = 'create';
    let roleIsSystem = false;
    let roleIsAdmin = false;

    function toast(msg, type) {
        if (typeof showToast === 'function') showToast(msg, type || 'info');
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    function postAction(data) {
        const fd = new FormData();
        Object.keys(data).forEach(function(k) {
            const v = data[k];
            if (Array.isArray(v)) {
                // JSON for reliable PHP parsing of lists
                if (k === 'role_ids' || k === 'custom_permissions' || k === 'permissions') {
                    fd.append(k, JSON.stringify(v));
                } else {
                    v.forEach(function(item) { fd.append(k + '[]', item); });
                }
            } else {
                fd.append(k, v);
            }
        });
        return fetch(endpoint, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function(r) { return r.json().catch(function() { return { success: false, error: 'Invalid server response' }; }); });
    }

    function applyState(res) {
        if (res.users) { usersState = res.users; renderUsers(); }
        if (res.roles) { rolesState = res.roles; renderRoles(); rebuildRoleChecks(); }
    }

    function renderPermChips(perms, containerIdPrefix) {
        if (!perms || !perms.length) return '<span class="text-muted small">No permissions</span>';
        if (perms.indexOf('*') !== -1) {
            return '<span class="users-perm-chip" title="' + escapeHtml(permLabels['*'] || 'Full access') + '">Full access (*)</span>';
        }
        const preview = 6;
        let html = '<div class="users-perm-collapsed" data-perm-wrap="1">';
        perms.forEach(function(p, i) {
            const label = permLabels[p] || p;
            const extra = i >= preview ? ' users-perm-extra' : '';
            html += '<span class="users-perm-chip' + extra + '" title="' + escapeHtml(label) + '">' + escapeHtml(p) + '</span>';
        });
        if (perms.length > preview) {
            html += '<button type="button" class="btn btn-link users-perm-toggle users-perm-expand-btn p-0 align-baseline">+' +
                (perms.length - preview) + ' more</button>';
            html += '<button type="button" class="btn btn-link users-perm-toggle users-perm-collapse-btn p-0 align-baseline users-perm-extra">Show less</button>';
        }
        html += '</div>';
        return html;
    }

    function bindPermToggles(root) {
        (root || document).querySelectorAll('[data-perm-wrap]').forEach(function(wrap) {
            const expand = wrap.querySelector('.users-perm-expand-btn');
            const collapse = wrap.querySelector('.users-perm-collapse-btn');
            if (expand) {
                expand.onclick = function() {
                    wrap.classList.remove('users-perm-collapsed');
                    expand.style.display = 'none';
                };
            }
            if (collapse) {
                collapse.onclick = function() {
                    wrap.classList.add('users-perm-collapsed');
                    if (expand) expand.style.display = '';
                };
            }
        });
    }

    function renderUsers() {
        const tbody = document.getElementById('usersTableBody');
        const badge = document.getElementById('usersCountBadge');
        if (!tbody) return;
        if (badge) badge.textContent = String(usersState.length);
        tbody.innerHTML = usersState.map(function(u) {
            const active = !!u.is_active;
            const rowClass = active ? '' : 'users-badge-inactive table-secondary';
            let status = active
                ? '<span class="badge text-bg-success">Active</span>'
                : '<span class="badge text-bg-secondary">Archived</span>';
            if (u.must_change_password && active) {
                status += ' <span class="badge text-bg-warning" title="Must change password">PW</span>';
            }
            if (u.custom_permissions && u.custom_permissions.length) {
                status += ' <span class="badge text-bg-info" title="Has custom permissions">+' + u.custom_permissions.length + '</span>';
            }
            const rolesHtml = (u.role_names || [u.role_name]).map(function(n) {
                return '<span class="badge text-bg-primary-subtle text-primary border me-1">' + escapeHtml(n) + '</span>';
            }).join('');
            const phoneLine = u.phone ? ' · ' + escapeHtml(u.phone) : '';
            let actions =
                '<button type="button" class="btn btn-outline-secondary btn-sm users-edit-btn" data-id="' + u.id + '" title="Edit">' +
                '<i class="bi bi-pencil"></i></button> ' +
                '<button type="button" class="btn btn-outline-warning btn-sm users-pw-btn" data-id="' + u.id +
                '" data-username="' + escapeHtml(u.username) + '" title="Reset password">' +
                '<i class="bi bi-key"></i></button>';
            if (u.id !== actorId) {
                actions += ' <button type="button" class="btn btn-outline-' + (active ? 'danger' : 'success') +
                    ' btn-sm users-active-btn" data-id="' + u.id + '" data-username="' + escapeHtml(u.username) +
                    '" data-active="' + (active ? '1' : '0') + '" title="' + (active ? 'Archive' : 'Restore') + '">' +
                    '<i class="bi bi-' + (active ? 'archive' : 'arrow-counterclockwise') + '"></i></button>';
            }
            return '<tr class="' + rowClass + '" data-user-id="' + u.id + '">' +
                '<td><div class="fw-semibold">' + escapeHtml(u.display_name) + '</div>' +
                '<div class="small text-muted">@' + escapeHtml(u.username) + ' · ' + escapeHtml(u.email) + phoneLine + '</div></td>' +
                '<td>' + rolesHtml + '</td>' +
                '<td>' + status + '</td>' +
                '<td class="d-none d-md-table-cell small text-muted">' + (u.last_login ? escapeHtml(u.last_login) : '—') + '</td>' +
                '<td class="text-end text-nowrap">' + actions + '</td></tr>';
        }).join('');

        tbody.querySelectorAll('.users-edit-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const u = usersState.find(function(x) { return String(x.id) === String(btn.dataset.id); });
                if (u) openEditUser(u);
            });
        });
        tbody.querySelectorAll('.users-pw-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('usersPwId').value = btn.dataset.id;
                document.getElementById('usersPwHint').textContent = 'Set a new password for @' + btn.dataset.username + '.';
                document.getElementById('usersPwNew').value = '';
                document.getElementById('usersPwConfirm').value = '';
                document.getElementById('usersPwMustChange').checked = true;
                pwModal.show();
            });
        });
        tbody.querySelectorAll('.users-active-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const currentlyActive = btn.dataset.active === '1';
                const verb = currentlyActive ? 'Archive (deactivate)' : 'Restore';
                if (!confirm(verb + ' user @' + btn.dataset.username + '?')) return;
                postAction({
                    action: 'set_active',
                    user_id: btn.dataset.id,
                    is_active: currentlyActive ? '0' : '1'
                }).then(function(res) {
                    if (!res.success) { toast(res.error || 'Failed', 'danger'); return; }
                    toast(res.message || 'Updated', 'success');
                    applyState(res);
                }).catch(function() { toast('Request failed', 'danger'); });
            });
        });
    }

    function renderRoles() {
        const list = document.getElementById('rolesList');
        const badge = document.getElementById('rolesCountBadge');
        if (!list) return;
        if (badge) badge.textContent = String(rolesState.length);
        list.innerHTML = rolesState.map(function(r) {
            const sys = r.is_system
                ? ' <span class="badge text-bg-secondary" style="font-size:0.65rem">system</span>'
                : '';
            return '<div class="list-group-item px-0">' +
                '<div class="d-flex justify-content-between align-items-start gap-2">' +
                '<button type="button" class="users-role-title roles-edit-btn text-start" data-id="' + r.id + '">' +
                escapeHtml(r.name) + '</button>' +
                '<span class="small text-muted text-nowrap">' + (r.user_count || 0) + ' user(s)' + sys + '</span>' +
                '</div>' +
                '<div class="small text-muted mb-1">' + escapeHtml(r.description || '') + '</div>' +
                renderPermChips(r.permissions || []) +
                '</div>';
        }).join('');

        list.querySelectorAll('.roles-edit-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const r = rolesState.find(function(x) { return String(x.id) === String(btn.dataset.id); });
                if (r) openEditRole(r);
            });
        });
        bindPermToggles(list);
    }

    function buildCheckGrid(container, items, selected, namePrefix) {
        const sel = {};
        (selected || []).forEach(function(k) { sel[String(k)] = true; });
        container.innerHTML = items.map(function(item) {
            const key = item.key != null ? item.key : item.id;
            const label = item.label != null ? item.label : item.name;
            const checked = sel[String(key)] ? ' checked' : '';
            const title = item.label || item.description || '';
            return '<label title="' + escapeHtml(title) + '">' +
                '<input type="checkbox" class="' + namePrefix + '-check" value="' + escapeHtml(String(key)) + '"' + checked + '>' +
                '<span><strong>' + escapeHtml(String(key)) + '</strong>' +
                (item.label ? ' <span class="text-muted">— ' + escapeHtml(item.label) + '</span>' : '') +
                '</span></label>';
        }).join('');
    }

    function rebuildRoleChecks(selectedIds) {
        const roleItems = rolesState.map(function(r) {
            return { id: r.id, name: r.name, description: r.description || '' };
        });
        // adapt to buildCheckGrid expecting key/label
        const items = roleItems.map(function(r) {
            return { key: r.id, label: r.name, description: r.description };
        });
        buildCheckGrid(document.getElementById('usersRoleChecks'), items, selectedIds || [], 'role');
    }

    function rebuildCustomPermChecks(selected) {
        buildCheckGrid(document.getElementById('usersCustomPermChecks'), permCatalogNoStar, selected || [], 'cperm');
    }

    function rebuildRolePermChecks(selected) {
        const items = roleIsAdmin ? permCatalog : permCatalogNoStar;
        buildCheckGrid(document.getElementById('rolesPermChecks'), items, selected || [], 'rperm');
        if (roleIsAdmin) {
            document.querySelectorAll('.rperm-check').forEach(function(cb) {
                cb.checked = cb.value === '*';
                cb.disabled = true;
            });
        } else {
            document.querySelectorAll('.rperm-check').forEach(function(cb) { cb.disabled = false; });
        }
    }

    function selectedValues(selector) {
        return Array.prototype.slice.call(document.querySelectorAll(selector + ':checked')).map(function(el) {
            return el.value;
        });
    }

    const DEFAULT_TEMP_PASSWORD = <?= json_encode(TEMPER_DEFAULT_TEMP_PASSWORD) ?>;

    function openCreateUser() {
        editMode = 'create';
        document.getElementById('usersEditModalLabel').textContent = 'Create user';
        document.getElementById('usersEditId').value = '';
        document.getElementById('usersUsername').value = '';
        document.getElementById('usersUsername').disabled = false;
        document.getElementById('usersUsernameGroup').style.display = '';
        document.getElementById('usersFirst').value = '';
        document.getElementById('usersLast').value = '';
        document.getElementById('usersEmail').value = '';
        document.getElementById('usersPhone').value = '';
        // Default temporary password (admin may change before save)
        document.getElementById('usersPassword').value = DEFAULT_TEMP_PASSWORD;
        document.getElementById('usersPasswordConfirm').value = DEFAULT_TEMP_PASSWORD;
        document.getElementById('usersPassword').required = true;
        document.getElementById('usersPasswordConfirm').required = true;
        document.getElementById('usersPasswordFields').style.display = '';
        // Force change on first login (auto-archive if not done within 24h)
        document.getElementById('usersMustChange').checked = true;
        rebuildRoleChecks([]);
        rebuildCustomPermChecks([]);
        editModal.show();
    }

    function openEditUser(u) {
        editMode = 'edit';
        document.getElementById('usersEditModalLabel').textContent = 'Edit user — ' + u.username;
        document.getElementById('usersEditId').value = u.id;
        document.getElementById('usersUsername').value = u.username;
        document.getElementById('usersUsername').disabled = true;
        document.getElementById('usersUsernameGroup').style.display = 'none';
        document.getElementById('usersFirst').value = u.first_name || '';
        document.getElementById('usersLast').value = u.last_name || '';
        document.getElementById('usersEmail').value = u.email || '';
        document.getElementById('usersPhone').value = u.phone || '';
        document.getElementById('usersPassword').value = '';
        document.getElementById('usersPasswordConfirm').value = '';
        document.getElementById('usersPassword').required = false;
        document.getElementById('usersPasswordConfirm').required = false;
        document.getElementById('usersPasswordFields').style.display = 'none';
        document.getElementById('usersMustChange').checked = !!u.must_change_password;
        rebuildRoleChecks(u.role_ids || [u.role_id]);
        rebuildCustomPermChecks(u.custom_permissions || []);
        editModal.show();
    }

    function openCreateRole() {
        roleMode = 'create';
        roleIsSystem = false;
        roleIsAdmin = false;
        document.getElementById('rolesEditModalLabel').textContent = 'Create role';
        document.getElementById('rolesEditId').value = '';
        document.getElementById('rolesName').value = '';
        document.getElementById('rolesName').disabled = false;
        document.getElementById('rolesNameHint').textContent = '';
        document.getElementById('rolesDescription').value = '';
        document.getElementById('rolesDeleteBtn').style.display = 'none';
        rebuildRolePermChecks(['profile.self']);
        roleModal.show();
    }

    function openEditRole(r) {
        roleMode = 'edit';
        roleIsSystem = !!r.is_system;
        roleIsAdmin = r.name === 'Administrator';
        document.getElementById('rolesEditModalLabel').textContent = 'Edit role — ' + r.name;
        document.getElementById('rolesEditId').value = r.id;
        document.getElementById('rolesName').value = r.name;
        document.getElementById('rolesName').disabled = roleIsAdmin;
        document.getElementById('rolesNameHint').textContent = roleIsSystem
            ? (roleIsAdmin ? 'Administrator always has full access (*).' : 'System role — name/permissions can be customized.')
            : 'Custom role';
        document.getElementById('rolesDescription').value = r.description || '';
        document.getElementById('rolesDeleteBtn').style.display = roleIsSystem ? 'none' : '';
        rebuildRolePermChecks(r.permissions || []);
        roleModal.show();
    }

    document.getElementById('usersBtnCreate').addEventListener('click', openCreateUser);
    document.getElementById('rolesBtnCreate').addEventListener('click', openCreateRole);

    document.getElementById('usersEditForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const roleIds = selectedValues('.role-check');
        const customPerms = selectedValues('.cperm-check');
        if (!roleIds.length) { toast('Select at least one role.', 'warning'); return; }
        const payload = {
            first_name: document.getElementById('usersFirst').value.trim(),
            last_name: document.getElementById('usersLast').value.trim(),
            email: document.getElementById('usersEmail').value.trim(),
            phone: document.getElementById('usersPhone').value.trim(),
            role_ids: roleIds,
            custom_permissions: customPerms,
            must_change_password: document.getElementById('usersMustChange').checked ? '1' : '0',
        };
        if (editMode === 'create') {
            payload.action = 'create_user';
            payload.username = document.getElementById('usersUsername').value.trim();
            payload.password = document.getElementById('usersPassword').value;
            payload.password_confirm = document.getElementById('usersPasswordConfirm').value;
        } else {
            payload.action = 'update_user';
            payload.user_id = document.getElementById('usersEditId').value;
        }
        postAction(payload).then(function(res) {
            if (!res.success) { toast(res.error || 'Failed', 'danger'); return; }
            toast(res.message || 'Saved', 'success');
            editModal.hide();
            applyState(res);
        }).catch(function() { toast('Request failed', 'danger'); });
    });

    document.getElementById('usersPwForm').addEventListener('submit', function(e) {
        e.preventDefault();
        postAction({
            action: 'reset_password',
            user_id: document.getElementById('usersPwId').value,
            password: document.getElementById('usersPwNew').value,
            password_confirm: document.getElementById('usersPwConfirm').value,
            must_change_password: document.getElementById('usersPwMustChange').checked ? '1' : '0',
        }).then(function(res) {
            if (!res.success) { toast(res.error || 'Failed', 'danger'); return; }
            toast(res.message || 'Password reset', 'success');
            pwModal.hide();
            applyState(res);
        }).catch(function() { toast('Request failed', 'danger'); });
    });

    document.getElementById('rolesEditForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const perms = roleIsAdmin ? ['*'] : selectedValues('.rperm-check');
        if (!perms.length) { toast('Select at least one permission.', 'warning'); return; }
        const payload = {
            name: document.getElementById('rolesName').value.trim(),
            description: document.getElementById('rolesDescription').value.trim(),
            permissions: perms,
        };
        if (roleMode === 'create') {
            payload.action = 'create_role';
        } else {
            payload.action = 'update_role';
            payload.role_id = document.getElementById('rolesEditId').value;
        }
        postAction(payload).then(function(res) {
            if (!res.success) { toast(res.error || 'Failed', 'danger'); return; }
            toast(res.message || 'Saved', 'success');
            roleModal.hide();
            applyState(res);
        }).catch(function() { toast('Request failed', 'danger'); });
    });

    document.getElementById('rolesDeleteBtn').addEventListener('click', function() {
        const id = document.getElementById('rolesEditId').value;
        const name = document.getElementById('rolesName').value;
        if (!confirm('Delete role "' + name + '"? This cannot be undone.')) return;
        postAction({ action: 'delete_role', role_id: id }).then(function(res) {
            if (!res.success) { toast(res.error || 'Failed', 'danger'); return; }
            toast(res.message || 'Deleted', 'success');
            roleModal.hide();
            applyState(res);
        }).catch(function() { toast('Request failed', 'danger'); });
    });

    rebuildRoleChecks([]);
    rebuildCustomPermChecks([]);
    renderUsers();
    renderRoles();
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-admin-users-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>

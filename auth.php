<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security: Prevent direct access to this helper file
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: login.php');
    exit;
}

/** User-facing message when the session is missing or expired. */
const AUTH_SESSION_EXPIRED_MESSAGE = 'Your session has expired. Please log in again.';

/**
 * Max idle seconds for application-level session expiry.
 * Defaults to PHP session.gc_maxlifetime (typically 1440).
 */
function getSessionMaxIdleSeconds(): int {
    $gc = (int)ini_get('session.gc_maxlifetime');
    return $gc > 60 ? $gc : 1440;
}

/**
 * Build a login URL relative to the current script location.
 *
 * @param string $query Optional query string (e.g. "expired=1"). Empty = clean login.
 */
function getLoginUrl(string $query = ''): string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = (strpos($script, '/pages/') !== false) ? '../login.php' : 'login.php';
    return $query !== '' ? ($base . '?' . ltrim($query, '?')) : $base;
}

/**
 * One-time session flash: true only after a real authenticated session ended.
 * Consumed by login.php so ?expired=1 alone (bookmark / fresh visit) does not show a message.
 */
function markAuthSessionExpiredFlash(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['auth_flash_expired'] = 1;
}

/**
 * Consume and return whether the login page should show the session-expired alert.
 */
function consumeAuthSessionExpiredFlash(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['auth_flash_expired'])) {
        return false;
    }
    unset($_SESSION['auth_flash_expired']);
    return true;
}

/**
 * True when the client expects a non-HTML (AJAX/fetch/JSON) response.
 */
function isAjaxOrApiRequest(): bool {
    $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if ($xrw === 'xmlhttprequest') {
        return true;
    }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (strpos($accept, 'application/json') !== false) {
        return true;
    }

    // SPA fragment loads and form posts from the shell use fetch() without special headers.
    // Treat any request under /pages/ as an application endpoint (not a full document navigation).
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (strpos($script, '/pages/') !== false) {
        return true;
    }

    // Content-Type of JSON body
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'application/json') !== false) {
        return true;
    }

    return false;
}

/**
 * Whether the caller wants a JSON auth failure body.
 */
function wantsJsonAuthResponse(): bool {
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (strpos($accept, 'application/json') !== false) {
        return true;
    }
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'application/json') !== false) {
        return true;
    }
    // fetch() FormData posts and many action endpoints expect JSON error payloads
    if (!empty($_POST['action']) || isset($_GET['api']) || isset($_GET['get_transaction'])
        || isset($_GET['get_budget']) || isset($_GET['run_report']) || isset($_GET['document_meta'])) {
        return true;
    }
    $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return $xrw === 'xmlhttprequest';
}

/**
 * Clear session data after expiration / forced logout.
 */
function clearAuthSession(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Keep cookie name but drop data; next login regenerates id.
        session_regenerate_id(true);
    }
}

/**
 * Emit unauthorized response and stop execution.
 * Full page navigations redirect to login; AJAX/API get 401 + X-Auth-Required.
 *
 * The "session expired" login banner is only set when the caller had an
 * authenticated session (user_id). Never-logged-in visitors get a clean login.
 */
function denyUnauthenticatedAccess(string $message = AUTH_SESSION_EXPIRED_MESSAGE): void {
    // Capture before clear: true expiry / forced logout of a logged-in user
    $hadAuthenticatedSession = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;

    clearAuthSession();

    if ($hadAuthenticatedSession) {
        markAuthSessionExpiredFlash();
    }

    // Query param is a UX hint; login.php also requires the one-time flash
    $loginUrl = $hadAuthenticatedSession ? getLoginUrl('expired=1') : getLoginUrl();
    $clientLoginUrl = $hadAuthenticatedSession ? 'login.php?expired=1' : 'login.php';

    // Always mark auth failures so the SPA can intercept without parsing body
    if (!headers_sent()) {
        header('X-Auth-Required: 1');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        // Expose custom header to browser JS (same-origin still fine; harmless)
        header('Access-Control-Expose-Headers: X-Auth-Required');
    }

    if (wantsJsonAuthResponse()) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'auth_required' => true,
            'redirect' => $clientLoginUrl,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (isAjaxOrApiRequest()) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'AUTH_REQUIRED';
        exit;
    }

    header('Location: ' . $loginUrl);
    exit;
}

/**
 * Return true if the current session has a logged-in user id.
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
}

/**
 * Whether the session is still within the idle timeout window.
 */
function isSessionWithinIdleLimit(): bool {
    if (!isset($_SESSION['last_activity'])) {
        // Legacy sessions without activity stamp: accept once, then stamp.
        return true;
    }
    $idle = time() - (int)$_SESSION['last_activity'];
    return $idle <= getSessionMaxIdleSeconds();
}

/**
 * Touch last_activity for idle timeout tracking.
 */
function touchAuthSession(): void {
    $_SESSION['last_activity'] = time();
}

/**
 * Central gate: require a valid authenticated session.
 * Call early on every protected page and endpoint.
 *
 * When $db is provided, also verifies the user still exists and is active.
 *
 * @return array{id:int,name:string,username:string} Minimal session user
 */
function requireLogin(?mysqli $db = null): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isLoggedIn() || !isSessionWithinIdleLimit()) {
        denyUnauthenticatedAccess();
    }

    $userId = (int)$_SESSION['user_id'];

    if ($db instanceof mysqli) {
        require_once __DIR__ . '/includes/permissions.php';
        // Periodic cleanup of accounts that never completed forced password change
        archiveExpiredForcePasswordUsers($db);

        static $validatedUserId = null;
        static $validatedOk = false;
        if ($validatedUserId !== $userId) {
            $validatedUserId = $userId;
            $validatedOk = false;
            $stmt = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = TRUE LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $validatedOk = (bool)$row;
            }
        }
        if (!$validatedOk) {
            denyUnauthenticatedAccess();
        }
    }

    touchAuthSession();

    // Block app use until forced password change completes
    enforceMustChangePasswordGate();

    return [
        'id' => $userId,
        'name' => (string)($_SESSION['user_name'] ?? 'User'),
        'username' => (string)($_SESSION['username'] ?? ''),
    ];
}

/**
 * Alias used by some call sites / docs.
 */
function requireAuthenticatedSession(?mysqli $db = null): array {
    return requireLogin($db);
}

function login($username, $password) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/includes/permissions.php';
    $db = getDbConnection();
    ensureUsersRolesSchema($db);
    // Expire accounts that never completed forced password change
    archiveExpiredForcePasswordUsers($db);

    $cols = 'id, username, first_name, email, password';
    if (temperColumnExists($db, 'users', 'must_change_password')) {
        $cols .= ', must_change_password';
    }

    $stmt = $db->prepare("SELECT {$cols} FROM users WHERE username = ? AND is_active = TRUE");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $verify = password_verify($password, $user['password']);

        if ($verify) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_name'] = $user['first_name'] ?? $user['username'];
            $_SESSION['username'] = $user['username'];
            if (!empty($user['must_change_password'])) {
                $_SESSION['must_change_password'] = 1;
            } else {
                unset($_SESSION['must_change_password']);
            }
            touchAuthSession();

            $update = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $update->bind_param("i", $user['id']);
            $update->execute();
            $update->close();

            $stmt->close();
            return true;
        }
    }

    $stmt->close();
    return false;
}

function logout() {
    clearAuthSession();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function getCurrentUser() {
    if (isLoggedIn()) {
        return [
            'id' => (int)$_SESSION['user_id'],
            'name' => $_SESSION['user_name'] ?? 'User',
            'username' => $_SESSION['username'] ?? '',
        ];
    }
    return null;
}

function getUserWithRole(mysqli $db, int $userId): ?array {
    require_once __DIR__ . '/includes/permissions.php';
    $acl = loadUserAcl($db, $userId);
    if (!$acl) {
        return null;
    }
    // Preserve legacy shape: permissions as JSON string for callers that json_decode
    $acl['permissions'] = encodeRolePermissions($acl['permissions']);
    return $acl;
}

function getCurrentUserWithRole(mysqli $db): ?array {
    $user = getCurrentUser();
    if (!$user) {
        return null;
    }
    return getUserWithRole($db, (int)$user['id']);
}

function verifyUserPassword(mysqli $db, int $userId, string $password): bool {
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

/**
 * Pages under /pages/ allowed while must_change_password is set.
 * Shell scripts (index, header, nav, footer) are never blocked.
 */
function isForcePasswordExemptPage(): bool {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = basename($script, '.php');
    return in_array($base, ['force-password', 'logout', 'login'], true);
}

/**
 * If the user must change password, block SPA page fragments except force-password.
 * Does not block the application shell (index.php / includes).
 */
function enforceMustChangePasswordGate(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['must_change_password'])) {
        return;
    }
    if (isForcePasswordExemptPage()) {
        return;
    }

    // Only gate AJAX-loaded application pages under /pages/
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (strpos($script, '/pages/') === false) {
        return;
    }

    $wantsJson = wantsJsonAuthResponse();

    if ($wantsJson) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'You must change your password before continuing.',
            'must_change_password' => true,
            'redirect' => 'force-password',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Must-Change-Password: 1');
    echo '<div class="alert alert-warning mb-0" role="alert">'
        . '<i class="bi bi-key me-1"></i>You must change your password before continuing. '
        . '<a href="javascript:void(0)" onclick="loadPage(\'force-password\')">Change password now</a>.'
        . '</div>'
        . '<script>if(typeof loadPage==="function"){loadPage("force-password");}</script>';
    exit;
}
?>

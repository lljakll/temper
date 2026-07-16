<?php
/**
 * Lightweight bootstrap for AJAX-loaded pages and form endpoints.
 * Ensures config, DB, auth session, early requireLogin(), and page-level RBAC.
 *
 * Usage at top of pages/*.php:
 *   require_once __DIR__ . '/../includes/page_bootstrap.php';
 *   // $db is available; user is authenticated and authorized for this page
 *
 * Optional before include:
 *   $temperPageKey = 'ledger';           // override page key for permission map
 *   $temperSkipPagePermission = true;    // skip auto page gate (rare)
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/permissions.php';

// Reuse an open connection only; a closed mysqli still passes instanceof
if (!isset($db) || !($db instanceof mysqli)) {
    $db = getDbConnection();
} else {
    try {
        // ping() returns false / warns if connection was closed
        if (!@$db->ping()) {
            $db = getDbConnection();
        }
    } catch (Throwable $e) {
        $db = getDbConnection();
    }
}

// Central session gate — redirects / 401 on expiration; enforces force-password
requireLogin($db);

// Page-level RBAC (unless caller opts out)
if (empty($temperSkipPagePermission)) {
    $pageKey = null;
    if (isset($temperPageKey) && is_string($temperPageKey) && $temperPageKey !== '') {
        $pageKey = $temperPageKey;
    }
    // force-password is always allowed for authenticated users who must change password
    $scriptBase = basename(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '.php');
    if ($scriptBase !== 'force-password') {
        requirePagePermission($db, $pageKey);
    }
}

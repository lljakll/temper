<?php
/**
 * Lightweight bootstrap for AJAX-loaded pages and form endpoints.
 * Ensures config, DB, auth session, and early requireLogin().
 *
 * Usage at top of pages/*.php:
 *   require_once __DIR__ . '/../includes/page_bootstrap.php';
 *   // $db is available; user is authenticated
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if (!isset($db) || !($db instanceof mysqli)) {
    $db = getDbConnection();
}

// Central session gate — redirects / 401 on expiration
requireLogin($db);

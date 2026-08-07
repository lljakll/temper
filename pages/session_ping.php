<?php
/**
 * Lightweight session keep-alive for the SPA idle-timeout warning modal.
 * requireLogin() touches last_activity; returns JSON only.
 */
$temperSkipPagePermission = true;
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/system_config.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$timeoutEnabled = function_exists('isLoginTimeoutEnabled') ? isLoginTimeoutEnabled() : true;
$timeoutSeconds = function_exists('getLoginTimeoutSeconds') ? getLoginTimeoutSeconds() : 600;

echo json_encode([
    'success' => true,
    'server_time' => time(),
    'login_timeout_enabled' => $timeoutEnabled,
    'login_timeout_seconds' => $timeoutSeconds,
], JSON_UNESCAPED_UNICODE);

if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
exit;

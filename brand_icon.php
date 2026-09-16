<?php
/**
 * Public church / organization icon (favicon and header graphic).
 * Serves only the image configured in System Configuration — no arbitrary paths.
 * No login required so the browser tab icon works on the login page.
 */
require_once __DIR__ . '/includes/system_config.php';

$path = function_exists('getChurchIconAbsolutePath') ? getChurchIconAbsolutePath() : null;
if ($path === null || !is_file($path)) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}

$mime = temperBrandIconMimeForPath($path);
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
readfile($path);
exit;

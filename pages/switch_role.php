<?php
/**
 * Switch the logged-in user's active role (session only).
 * GET or POST role_id of an assigned role; then reload the app shell.
 */
$temperSkipPagePermission = true;
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/audit.php';

$roleId = (int)($_POST['role_id'] ?? $_GET['role_id'] ?? 0);
$actor = getCurrentUser();
$actorId = $actor ? (int)$actor['id'] : 0;
$actorUsername = $actor ? (string)$actor['username'] : '';
$fromRole = (string)($_SESSION['active_role_name'] ?? '');

$error = null;
if ($actorId <= 0) {
    $error = 'You are not signed in.';
} else {
    $error = switchUserActiveRole($db, $actorId, $roleId);
}

if ($error === null) {
    $toRole = (string)($_SESSION['active_role_name'] ?? '');
    if ($fromRole !== $toRole) {
        logAuditAction(
            $db,
            $actorId,
            $actorUsername,
            'role_switch',
            'Switched active role from ' . $fromRole . ' to ' . $toRole
        );
    }
}

$wantsJson = function_exists('wantsJsonAuthResponse') && wantsJsonAuthResponse();
if ($wantsJson) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    if ($error !== null) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $error,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => true,
            'role_id' => (int)($_SESSION['active_role_id'] ?? $roleId),
            'role_name' => (string)($_SESSION['active_role_name'] ?? ''),
            'reload' => true,
        ], JSON_UNESCAPED_UNICODE);
    }
    if (isset($db) && $db instanceof mysqli) {
        $db->close();
    }
    exit;
}

if (isset($db) && $db instanceof mysqli) {
    $db->close();
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: ../index.php');
exit;

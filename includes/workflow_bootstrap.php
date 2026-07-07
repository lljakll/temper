<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/workflow_engine.php';

/**
 * Deny workflow page access and stop rendering.
 */
function workflowDenyAndExit(mysqli $db, string $message): void {
    echo '<div class="alert alert-warning">' . htmlspecialchars($message) . '</div>';
    $db->close();
    exit;
}

/**
 * Require a logged-in user with the given workflow capability.
 *
 * @return array Authenticated user row from getCurrentUserWithRole()
 */
function workflowRequireActor(mysqli $db, string $capability = 'workflow.view'): array {
    $actor = getCurrentUserWithRole($db);
    if (!$actor) {
        workflowDenyAndExit($db, 'Not authenticated.');
    }
    if (!userHasWorkflowCapability($db, (int)$actor['id'], $capability)) {
        workflowDenyAndExit($db, 'You do not have permission to access workflows.');
    }
    return $actor;
}
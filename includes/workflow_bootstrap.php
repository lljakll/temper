<?php
/**
 * Workflow system bootstrap for page fragments and CLI.
 *
 * Loads the clean-sheet engine (YAML definitions, importer, sequential runtime).
 * Does not contain accounting rules — those live in ledger_engine / definitions.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/storage_paths.php';
require_once __DIR__ . '/workflow/DefinitionDictionary.php';
require_once __DIR__ . '/workflow/YamlParser.php';
require_once __DIR__ . '/workflow/WorkflowDefinition.php';
require_once __DIR__ . '/workflow/WorkflowValidator.php';
require_once __DIR__ . '/workflow/WorkflowEvents.php';
require_once __DIR__ . '/workflow/WorkflowImporter.php';
require_once __DIR__ . '/workflow/WorkflowEngine.php';

/**
 * Deny workflow page access and stop rendering.
 */
function workflowDenyAndExit(string $message): void
{
    echo '<div class="alert alert-warning">' . htmlspecialchars($message) . '</div>';
    exit;
}

/**
 * Require a logged-in user with the given permission (workflow.view by default).
 *
 * @return array Authenticated ACL user row
 */
function workflowRequireActor(mysqli $db, string $permission = 'workflow.view'): array
{
    requireLogin($db);

    $actor = getCurrentUserWithRole($db);
    if (!$actor) {
        denyUnauthenticatedAccess();
    }
    if (!userHasPermission($db, (int)$actor['id'], $permission)) {
        // Legacy capability helper still available for transition
        if (!function_exists('userHasWorkflowCapability')
            || !userHasWorkflowCapability($db, (int)$actor['id'], $permission)
        ) {
            workflowDenyAndExit('You do not have permission to access workflows.');
        }
    }
    return $actor;
}

/**
 * Require Admin or Workflow Manager (or workflow.manage permission) for definition ops.
 *
 * @return array ACL user row
 */
function workflowRequireManager(mysqli $db): array
{
    requireLogin($db);
    $user = getCurrentUser();
    if (!$user) {
        denyUnauthenticatedAccess();
    }
    $userId = (int)$user['id'];
    if (!userCanManageWorkflows($db, $userId)) {
        denyPermission('Workflow Manager or Administrator access required to manage definitions.');
    }
    $acl = loadUserAcl($db, $userId);
    if (!$acl) {
        denyPermission('Workflow Manager or Administrator access required to manage definitions.');
    }
    return $acl;
}

/**
 * True if user may import/delete/version workflow definitions.
 * Admin (*), Workflow Manager role, or standalone workflow.manage permission.
 */
function userCanManageWorkflows(mysqli $db, int $userId): bool
{
    if (userIsAdministrator($db, $userId)) {
        return true;
    }
    if (userHasPermission($db, $userId, 'workflow.manage')) {
        return true;
    }
    $acl = loadUserAcl($db, $userId);
    if (!$acl) {
        return false;
    }
    $names = $acl['role_names'] ?? [($acl['role_name'] ?? '')];
    foreach (WorkflowDefinitionDictionary::managementRoles() as $roleName) {
        if ($roleName === 'Administrator') {
            continue;
        }
        if (in_array($roleName, $names, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Shared engine factory for pages.
 */
function workflowEngine(mysqli $db): WorkflowEngine
{
    static $engines = [];
    $key = spl_object_id($db);
    if (!isset($engines[$key])) {
        $engines[$key] = new WorkflowEngine($db);
    }
    return $engines[$key];
}

/**
 * Convenience: ensure tables exist.
 */
function workflowRequireTables(mysqli $db): void
{
    workflowEngine($db)->requireTables();
}

<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../../login.php');
    exit;
}

/**
 * Registered workflow types for the hub page and orchestration layer.
 * Add new workflow types here without changing core ledger or workflow engine code.
 *
 * @return array<string, array{
 *     title: string,
 *     description: string,
 *     page: string,
 *     icon: string,
 *     border_color: string,
 *     module: string,
 *     stats_fn: string,
 *     enabled: bool
 * }>
 */
function workflowTypeRegistry(): array {
    return [
        'contribution' => [
            'title' => 'Contribution Processing',
            'description' => 'Sunday offerings and similar contributions — dual teller count, official validation, deposit creation.',
            'page' => 'workflow_contribution',
            'icon' => 'bi-cash-coin',
            'border_color' => 'success',
            'module' => __DIR__ . '/contribution_workflow.php',
            'stats_fn' => 'contribWorkflowHubStats',
            'enabled' => true,
        ],
    ];
}

function workflowLoadTypeModules(): void {
    foreach (workflowTypeRegistry() as $def) {
        if (empty($def['enabled']) || empty($def['module']) || !is_file($def['module'])) {
            continue;
        }
        require_once $def['module'];
    }
}

/**
 * @return array<string, array{total: int, active: int, badges: list<array{label: string, class: string}>}>
 */
function workflowCollectHubStats(mysqli $db): array {
    workflowLoadTypeModules();
    $stats = [];

    foreach (workflowTypeRegistry() as $type => $def) {
        if (empty($def['enabled'])) {
            continue;
        }
        $fn = $def['stats_fn'] ?? null;
        if (is_string($fn) && function_exists($fn)) {
            $stats[$type] = $fn($db);
        } else {
            $stats[$type] = ['total' => 0, 'active' => 0, 'badges' => []];
        }
    }

    return $stats;
}

function workflowHubTotals(array $stats): array {
    $total = 0;
    $active = 0;
    foreach ($stats as $row) {
        $total += (int)($row['total'] ?? 0);
        $active += (int)($row['active'] ?? 0);
    }
    return ['total' => $total, 'active' => $active];
}
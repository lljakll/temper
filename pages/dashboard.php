<?php

    // Security and DB connection already handled by index.php
    // Light fallback in case
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/fund_utils.php';
require_once __DIR__ . '/../includes/user_preferences.php';

$today = date('Y-m-d');
    $tasksHorizon = date('Y-m-d', strtotime('+30 days'));
    $upcoming_tasks_limit = 10;
    $actorUser = getCurrentUser();
    require_once __DIR__ . '/../includes/permissions.php';
    $dashAcl = $actorUser ? loadUserAcl($db, (int)$actorUser['id']) : null;
    $dashPerms = $dashAcl['permissions'] ?? [];
    $dashUserId = $actorUser ? (int)$actorUser['id'] : 0;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_total_cash_accounts') {
        if ($dashUserId <= 0) {
            dashboardSendJson(['success' => false, 'error' => 'You must be signed in to save this setting.'], $db);
        }
        if (!userPreferencesColumnExists($db)) {
            dashboardSendJson([
                'success' => false,
                'error' => 'User preferences are not available. Apply the 0.938 schema patch (users.preferences).',
            ], $db);
        }

        $assetAccounts = dashboardActiveAssetAccounts($db);
        $allowed = [];
        foreach (dashboardAssetAccountIds($assetAccounts) as $id) {
            $allowed[$id] = $id;
        }
        $posted = dashboardParsePostedAccountIds($_POST['account_ids'] ?? []);
        $selected = [];
        foreach ($posted as $id) {
            if (isset($allowed[$id])) {
                $selected[] = $id;
            }
        }

        if (!setUserPreference($db, $dashUserId, USER_PREF_DASHBOARD_TOTAL_CASH_ACCOUNT_IDS, $selected)) {
            dashboardSendJson(['success' => false, 'error' => 'Could not save account selection.'], $db);
        }

        $cashTotal = dashboardSumAssetBalances($db, $selected, $today);
        dashboardSendJson([
            'success' => true,
            'account_ids' => $selected,
            'cash_total' => $cashTotal,
            'cash_total_formatted' => dashboardFormatMoney($cashTotal),
            'selected_count' => count($selected),
            'asset_count' => count($allowed),
        ], $db);
    }

/**
 * Quick-access dashboard links (easy to extend).
 * Optional keys: page (SPA), href (external), permission (RBAC), variant, icon, description
 *
 * @return list<array{label:string,icon:string,variant:string,page?:string,href?:string,description?:string,permission?:string}>
 */
function dashboardQuickLinks(): array {
    return [
        [
            'label' => 'View Ledger',
            'description' => 'Transactions & attachments',
            'page' => 'ledger',
            'icon' => 'bi-currency-dollar',
            'variant' => 'primary',
            'permission' => 'page.ledger',
        ],
        [
            'label' => 'Run Reports',
            'description' => 'Balances & activity',
            'page' => 'reports',
            'icon' => 'bi-file-earmark-bar-graph',
            'variant' => 'info',
            'permission' => 'page.reports',
        ],
        [
            'label' => 'Budget',
            'description' => 'Plans & tracking',
            'page' => 'budget',
            'icon' => 'bi-graph-up',
            'variant' => 'secondary',
            'permission' => 'page.budget',
        ],
        [
            'label' => 'Tasks',
            'description' => 'Reminders & to-dos',
            'page' => 'tasks',
            'icon' => 'bi-check2-square',
            'variant' => 'secondary',
            'permission' => 'page.tasks',
        ],
        [
            'label' => 'Funds Setup',
            'description' => 'Lookups · funds',
            'page' => 'setup_funds',
            'icon' => 'bi-wallet2',
            'variant' => 'outline-secondary',
            'permission' => 'admin.lookups',
        ],
        [
            'label' => 'Users & Roles',
            'description' => 'Admin · accounts',
            'page' => 'admin-users',
            'icon' => 'bi-people',
            'variant' => 'outline-secondary',
            'permission' => 'users.manage',
        ],
        [
            'label' => 'Backup / Restore',
            'description' => 'Admin · data-only export',
            'page' => 'admin-backup',
            'icon' => 'bi-cloud-arrow-up',
            'variant' => 'outline-secondary',
            // Administrator-only (role check below; permission kept for ACL catalog)
            'permission' => 'admin.backup',
            'admin_role_only' => true,
        ],
    ];
}

/**
 * Active Chart of Accounts rows with account_type = asset.
 *
 * @return list<array{id:int,name:string,coa_number:?string}>
 */
function dashboardActiveAssetAccounts(mysqli $db): array
{
    $rows = [];
    $res = $db->query(
        "SELECT id, name, coa_number
         FROM accounts
         WHERE archived = FALSE
           AND account_type = 'asset'
         ORDER BY COALESCE(coa_number, ''), name"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'coa_number' => $row['coa_number'] !== null && $row['coa_number'] !== ''
                    ? (string)$row['coa_number']
                    : null,
            ];
        }
        $res->close();
    }
    return $rows;
}

/**
 * @param list<array{id:int,name:string,coa_number:?string}> $assets
 * @return list<int>
 */
function dashboardAssetAccountIds(array $assets): array
{
    $ids = [];
    foreach ($assets as $a) {
        $id = (int)($a['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * Normalize a stored account-id list. Null = no preference (caller uses all assets).
 *
 * @return list<int>|null
 */
function dashboardNormalizeStoredAccountIds(mixed $raw): ?array
{
    if ($raw === null) {
        return null;
    }
    if (!is_array($raw)) {
        return null;
    }
    $ids = [];
    foreach ($raw as $v) {
        if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
            $id = (int)$v;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }
    return array_values($ids);
}

/**
 * Account ids included in Total Cash / Bank. Null preference → all active assets.
 *
 * @param list<int> $allAssetIds
 * @return list<int>
 */
function dashboardResolveCashAccountIds(mysqli $db, int $userId, array $allAssetIds): array
{
    $allowed = [];
    foreach ($allAssetIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $allowed[$id] = $id;
        }
    }
    if ($allowed === []) {
        return [];
    }

    $stored = null;
    if ($userId > 0) {
        $stored = dashboardNormalizeStoredAccountIds(
            getUserPreference($db, $userId, USER_PREF_DASHBOARD_TOTAL_CASH_ACCOUNT_IDS, null)
        );
    }
    if ($stored === null) {
        return array_values($allowed);
    }

    $resolved = [];
    foreach ($stored as $id) {
        if (isset($allowed[$id])) {
            $resolved[] = $id;
        }
    }
    return $resolved;
}

/**
 * Signed asset balance (debit − credit) for the given account ids as of $asOf.
 *
 * @param list<int> $accountIds
 */
function dashboardSumAssetBalances(mysqli $db, array $accountIds, string $asOf): float
{
    $ids = [];
    foreach ($accountIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $ids = array_values($ids);
    if ($ids === []) {
        return 0.0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "
        SELECT COALESCE(SUM(
            CASE WHEN tl.type = 'debit' THEN tl.amount ELSE -tl.amount END
        ), 0) AS balance
        FROM transaction_lines tl
        JOIN accounts a ON a.id = tl.account_id
        JOIN transaction_details td ON td.id = tl.transaction_detail_id
        WHERE a.archived = FALSE
          AND a.account_type = 'asset'
          AND a.id IN ({$placeholders})
          AND td.transaction_date <= ?
    ";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return 0.0;
    }
    $types = str_repeat('i', count($ids)) . 's';
    $params = array_merge($ids, [$asOf]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $balance = (float)($stmt->get_result()->fetch_assoc()['balance'] ?? 0);
    $stmt->close();
    return $balance;
}

function dashboardFormatMoney(float $amount): string
{
    return '$' . number_format($amount, 2);
}

function dashboardSendJson(array $payload, ?mysqli $db = null): void
{
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
function dashboardParsePostedAccountIds(mixed $raw): array
{
    if (is_string($raw)) {
        $trim = trim($raw);
        if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
            $decoded = json_decode($trim, true);
            $raw = is_array($decoded) ? $decoded : $raw;
        }
    }
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $v) {
        $id = (int)$v;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

    $fundBalanceSql = fundBalanceSelectSql('AND td.transaction_date <= ?');
    $assetAccounts = dashboardActiveAssetAccounts($db);
    $allAssetIds = dashboardAssetAccountIds($assetAccounts);
    $cashAccountIds = dashboardResolveCashAccountIds($db, $dashUserId, $allAssetIds);
    $cashSelectedSet = array_fill_keys($cashAccountIds, true);

    // Get all active funds with computed balances
    $funds_query = "SELECT id, name, code, type, description, purpose
                    FROM funds
                    WHERE is_active = TRUE AND archived = FALSE
                    ORDER BY type, name";

    $funds_result = $db->query($funds_query);
    $funds = [];
    $wodr_total = 0;
    $wdr_total = 0;

    $balStmt = $db->prepare($fundBalanceSql);
    if ($funds_result && $funds_result->num_rows > 0) {
        while ($fund = $funds_result->fetch_assoc()) {
            $fid = (int)$fund['id'];
            $balStmt->bind_param('is', $fid, $today);
            $balStmt->execute();
            $balance = (float)($balStmt->get_result()->fetch_assoc()['balance'] ?? 0);
            $fund['current_balance'] = $balance;
            $funds[] = $fund;

            if ($fund['type'] === 'WODR') {
                $wodr_total += $balance;
            } else {
                $wdr_total += $balance;
            }
        }
        $funds_result->close();
    }
    $balStmt->close();

    // Total cash and bank balances (selected asset accounts)
    $cash_total = dashboardSumAssetBalances($db, $cashAccountIds, $today);

    // Upcoming tasks from the tasks/reminders system (next 30 days, or all pending if fewer)
    $taskStatusMeta = [
        'upcoming' => ['label' => 'Upcoming', 'badge' => 'secondary'],
        'due_soon' => ['label' => 'Due Soon', 'badge' => 'warning'],
        'overdue' => ['label' => 'Overdue', 'badge' => 'danger'],
        'in_progress' => ['label' => 'In Progress', 'badge' => 'info'],
        'done' => ['label' => 'Done', 'badge' => 'success'],
    ];
    $upcomingTasks = [];
    $pendingTasks = [];
    $tasksTableOk = false;
    $tasksCheck = $db->query("SHOW TABLES LIKE 'tasks'");
    if ($tasksCheck && $tasksCheck->num_rows > 0) {
        $tasksTableOk = true;
        $tasksCheck->close();
        $taskSql = "
            SELECT id, title, description, due_date, status
            FROM tasks
            WHERE status <> 'done'
            ORDER BY
                CASE status
                    WHEN 'overdue' THEN 0
                    WHEN 'due_soon' THEN 1
                    WHEN 'in_progress' THEN 2
                    ELSE 3
                END,
                due_date IS NULL,
                due_date ASC,
                id ASC
        ";
        $taskRes = $db->query($taskSql);
        if ($taskRes) {
            while ($row = $taskRes->fetch_assoc()) {
                $pendingTasks[] = $row;
            }
            $taskRes->close();
        }

        foreach ($pendingTasks as $task) {
            $due = $task['due_date'] ?? null;
            if ($due !== null && $due !== '' && $due <= $tasksHorizon) {
                $upcomingTasks[] = $task;
            }
        }
        // If fewer tasks fall in the 30-day window than the display limit, fill with other pending
        if (count($upcomingTasks) < $upcoming_tasks_limit) {
            $seenIds = array_column($upcomingTasks, 'id');
            foreach ($pendingTasks as $task) {
                if (count($upcomingTasks) >= $upcoming_tasks_limit) {
                    break;
                }
                if (!in_array($task['id'], $seenIds, true)) {
                    $upcomingTasks[] = $task;
                }
            }
        } else {
            $upcomingTasks = array_slice($upcomingTasks, 0, $upcoming_tasks_limit);
        }
    } elseif ($tasksCheck) {
        $tasksCheck->close();
    }

    $isAdminUser = $actorUser && userIsAdministrator($db, (int)$actorUser['id']);
    $quickLinks = array_values(array_filter(
        dashboardQuickLinks(),
        static function (array $link) use ($dashPerms, $isAdminUser): bool {
            if (!empty($link['admin_role_only']) && !$isAdminUser) {
                return false;
            }
            $perm = $link['permission'] ?? null;
            if ($perm === null || $perm === '') {
                return true;
            }
            return permissionSetAllows($dashPerms, $perm);
        }
    ));
?>

<div class="row mb-3 mb-md-4 page-title-row">
    <div class="col-12">
        <h2 class="mb-0 h3 h-md-2">Financial Dashboard</h2>
        <p class="text-muted small mb-0 d-none d-sm-block">Stewardship &amp; Accountability Dashboard | Based on Treasurer’s Guide Rev 1.0</p>
        <p class="text-muted small mb-0 d-sm-none">Stewardship dashboard</p>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-3 mb-md-4 g-2 g-md-3">
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="card border-primary h-100 dashboard-summary-card">
            <div class="card-header text-bg-primary py-2 py-md-3">
                <h5 class="mb-0"><i class="bi bi-wallet"></i> <span class="d-none d-md-inline">Net Assets Without Donor Restrictions (WODR)</span><span class="d-md-none">WODR (Unrestricted)</span></h5>
            </div>
            <div class="card-body py-3">
                <h3 class="text-primary mb-1">$<?= number_format($wodr_total, 2) ?></h3>
                <p class="card-text text-body-secondary small mb-0">Unrestricted operating resources.</p>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="card border-success h-100 dashboard-summary-card">
            <div class="card-header text-bg-success py-2 py-md-3">
                <h5 class="mb-0"><i class="bi bi-wallet2"></i> <span class="d-none d-md-inline">Net Assets With Donor Restrictions (WDR)</span><span class="d-md-none">WDR (Restricted)</span></h5>
            </div>
            <div class="card-body py-3">
                <h3 class="text-success mb-1">$<?= number_format($wdr_total, 2) ?></h3>
                <p class="card-text text-body-secondary small mb-0">Donor-restricted resources.</p>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-12 col-lg-4">
        <div class="card border-info h-100 dashboard-summary-card">
            <div class="card-header text-bg-info py-2 py-md-3 d-flex align-items-center justify-content-between gap-2">
                <h5 class="mb-0"><i class="bi bi-bank"></i> <span class="d-none d-md-inline">Total Cash / Bank Balances</span><span class="d-md-none">Cash / Bank</span></h5>
                <button type="button"
                        class="btn btn-sm btn-link p-1 dashboard-card-setup-btn"
                        id="totalCashSetupBtn"
                        title="Choose accounts"
                        aria-label="Choose accounts included in Total Cash / Bank Balances">
                    <i class="bi bi-gear" aria-hidden="true"></i>
                </button>
            </div>
            <div class="card-body py-3">
                <h3 class="text-info mb-1" id="totalCashAmount"><?= htmlspecialchars(dashboardFormatMoney($cash_total)) ?></h3>
                <p class="card-text text-body-secondary small mb-0">Combined cash on hand and bank accounts.</p>
            </div>
        </div>
    </div>
</div>

<!-- Quick links -->
<div class="row mb-3 mb-md-4 g-2 g-md-3">
    <div class="col-12">
        <div class="card h-100 shadow-sm">
            <div class="card-header py-2">
                <h5 class="mb-0 h6"><i class="bi bi-lightning-charge text-primary"></i> Quick Links</h5>
            </div>
            <div class="card-body py-2">
                <div class="row g-2">
                    <?php foreach ($quickLinks as $link): ?>
                        <?php
                            $variant = $link['variant'] ?? 'secondary';
                            $btnClass = str_starts_with($variant, 'outline-')
                                ? 'btn-' . $variant
                                : 'btn-outline-' . $variant;
                            $page = $link['page'] ?? '';
                            $href = $link['href'] ?? '';
                        ?>
                        <div class="col-6 col-md-4 col-lg-3">
                            <?php if ($page !== ''): ?>
                                <a href="javascript:void(0)"
                                   onclick="loadPage('<?= htmlspecialchars($page, ENT_QUOTES) ?>')"
                                   class="btn <?= htmlspecialchars($btnClass) ?> w-100 h-100 text-start py-2 px-2 d-flex flex-column align-items-start gap-1">
                                    <span class="d-flex align-items-center gap-1 fw-semibold small">
                                        <i class="bi <?= htmlspecialchars($link['icon'] ?? 'bi-link-45deg') ?>"></i>
                                        <?= htmlspecialchars($link['label']) ?>
                                    </span>
                                    <?php if (!empty($link['description'])): ?>
                                        <span class="text-body-secondary" style="font-size:0.7rem;font-weight:400;white-space:normal;">
                                            <?= htmlspecialchars($link['description']) ?>
                                        </span>
                                    <?php endif; ?>
                                </a>
                            <?php elseif ($href !== ''): ?>
                                <a href="<?= htmlspecialchars($href) ?>"
                                   class="btn <?= htmlspecialchars($btnClass) ?> w-100 h-100 text-start py-2 px-2">
                                    <i class="bi <?= htmlspecialchars($link['icon'] ?? 'bi-link-45deg') ?>"></i>
                                    <?= htmlspecialchars($link['label']) ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- All Funds Table -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-coin"></i> All Active Funds</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Fund</th>
                                <th>Code</th>
                                <th>Type</th>
                                <th class="text-end">Balance</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($funds) > 0): ?>
                                <?php foreach ($funds as $fund): ?>
                                <tr>
                                    <td><?= htmlspecialchars($fund['name']) ?></td>
                                    <td><?= htmlspecialchars($fund['code'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($fund['type']) ?></td>
                                    <td class="text-end fw-bold <?= $fund['type'] === 'WODR' ? 'text-primary' : 'text-success' ?>">
                                        $<?= number_format($fund['current_balance'], 2) ?>
                                    </td>
                                    <td><?= htmlspecialchars($fund['description'] ?? '') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center py-4">No active funds found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mb-0 mt-2">Fund balances come from tagged income, expense, and net assets lines. Fund tags on asset accounts (Checking, cash/bank) are ignored.</p>
            </div>
        </div>
    </div>
</div>

<!-- Upcoming Tasks -->
<div class="row">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-1">
                <h5 class="mb-0"><i class="bi bi-check2-square"></i> Upcoming Tasks</h5>
                <a href="javascript:void(0)" onclick="loadPage('tasks')" class="small text-decoration-none">View all tasks &rarr;</a>
            </div>
            <div class="card-body p-0">
                <?php if (!$tasksTableOk): ?>
                    <p class="text-muted text-center py-4 mb-0">Tasks system is not set up yet.</p>
                <?php elseif (count($upcomingTasks) === 0): ?>
                    <div class="d-flex align-items-start gap-2 text-success small py-4 px-3 justify-content-center">
                        <i class="bi bi-check-circle-fill mt-1"></i>
                        <div>
                            <strong>No upcoming tasks.</strong>
                            <div class="text-body-secondary">You’re clear for the next 30 days.</div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0 align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>Task</th>
                                    <th>Description</th>
                                    <th class="text-nowrap">Due Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcomingTasks as $task): ?>
                                    <?php
                                        $statusKey = $task['status'] ?? 'upcoming';
                                        $meta = $taskStatusMeta[$statusKey] ?? ['label' => $statusKey, 'badge' => 'secondary'];
                                        $dueLabel = !empty($task['due_date']) ? $task['due_date'] : '—';
                                        $desc = trim((string)($task['description'] ?? ''));
                                    ?>
                                    <tr>
                                        <td class="fw-semibold"><?= htmlspecialchars($task['title'] ?? '') ?></td>
                                        <td class="text-muted small">
                                            <?php if ($desc !== ''): ?>
                                                <?= htmlspecialchars(mb_strlen($desc) > 120 ? mb_substr($desc, 0, 117) . '…' : $desc) ?>
                                            <?php else: ?>
                                                <span class="text-body-secondary">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-nowrap">
                                            <?php if (!empty($task['due_date'])): ?>
                                                <i class="bi bi-calendar3 text-body-secondary"></i>
                                                <?= htmlspecialchars($dueLabel) ?>
                                                <?php if ($task['due_date'] < $today): ?>
                                                    <span class="badge text-bg-danger ms-1">Past due</span>
                                                <?php elseif ($task['due_date'] === $today): ?>
                                                    <span class="badge text-bg-warning ms-1">Today</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-body-secondary">No due date</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= htmlspecialchars($meta['badge']) ?>">
                                                <?= htmlspecialchars($meta['label']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($pendingTasks) > count($upcomingTasks)): ?>
                        <div class="small text-body-secondary px-3 py-2 border-top">
                            Showing <?= count($upcomingTasks) ?> of <?= count($pendingTasks) ?> pending —
                            <a href="javascript:void(0)" onclick="loadPage('tasks')">open Tasks</a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="totalCashAccountsModal" tabindex="-1" aria-labelledby="totalCashAccountsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title h6 mb-0" id="totalCashAccountsModalLabel">
                    <i class="bi bi-bank me-1"></i> Total Cash / Bank accounts
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">Choose which asset accounts are included in this total. The selection is saved for your user.</p>
                <?php if (count($assetAccounts) > 0): ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted">Asset accounts</span>
                        <span class="small">
                            <button type="button" class="btn btn-link btn-sm p-0" id="totalCashSelectAll">All</button>
                            <span class="text-muted">·</span>
                            <button type="button" class="btn btn-link btn-sm p-0" id="totalCashSelectNone">None</button>
                        </span>
                    </div>
                    <div id="totalCashAccountList" class="d-flex flex-column gap-1">
                        <?php foreach ($assetAccounts as $acct): ?>
                            <?php
                                $acctId = (int)$acct['id'];
                                $coa = $acct['coa_number'] ?? null;
                                $label = $coa !== null ? $coa . ' · ' . $acct['name'] : $acct['name'];
                                $checked = isset($cashSelectedSet[$acctId]);
                            ?>
                            <div class="form-check">
                                <input class="form-check-input total-cash-account"
                                       type="checkbox"
                                       value="<?= $acctId ?>"
                                       id="totalCashAcct<?= $acctId ?>"
                                       <?= $checked ? 'checked' : '' ?>>
                                <label class="form-check-label" for="totalCashAcct<?= $acctId ?>">
                                    <?= htmlspecialchars($label) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0">No asset accounts are set up yet. Add them under Lookups → Accounts.</p>
                <?php endif; ?>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="totalCashSaveBtn" <?= count($assetAccounts) === 0 ? 'disabled' : '' ?>>Save</button>
            </div>
        </div>
    </div>
</div>

<script type="text/plain" id="init-dashboard-script">
(function() {
    let modalEl = document.getElementById('totalCashAccountsModal');
    if (modalEl && typeof window.mountModalOnBody === 'function') {
        modalEl = window.mountModalOnBody(modalEl);
    }
    const setupBtn = document.getElementById('totalCashSetupBtn');
    const saveBtn = document.getElementById('totalCashSaveBtn');
    const selectAllBtn = document.getElementById('totalCashSelectAll');
    const selectNoneBtn = document.getElementById('totalCashSelectNone');
    const amountEl = document.getElementById('totalCashAmount');
    let checkSnapshot = [];
    let savedOk = false;

    function accountBoxes() {
        const root = document.getElementById('totalCashAccountsModal');
        return root ? root.querySelectorAll('.total-cash-account') : [];
    }

    function setAllChecked(checked) {
        accountBoxes().forEach(function(el) { el.checked = !!checked; });
    }

    function snapshotChecks() {
        checkSnapshot = [];
        accountBoxes().forEach(function(el) {
            checkSnapshot.push({ el: el, checked: !!el.checked });
        });
    }

    function restoreChecks() {
        checkSnapshot.forEach(function(item) {
            if (item.el) item.el.checked = item.checked;
        });
    }

    function openModal() {
        if (!modalEl) return;
        if (typeof window.showFragmentModal === 'function') {
            window.showFragmentModal(modalEl);
            return;
        }
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    function closeModal() {
        if (!modalEl) return;
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const inst = bootstrap.Modal.getInstance(modalEl);
            if (inst) inst.hide();
        }
    }

    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function() {
            if (!savedOk) restoreChecks();
        });
    }
    if (setupBtn) {
        setupBtn.addEventListener('click', function() {
            savedOk = false;
            snapshotChecks();
            openModal();
        });
    }
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() { setAllChecked(true); });
    }
    if (selectNoneBtn) {
        selectNoneBtn.addEventListener('click', function() { setAllChecked(false); });
    }
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            const fd = new FormData();
            fd.append('action', 'save_total_cash_accounts');
            accountBoxes().forEach(function(el) {
                if (el.checked) fd.append('account_ids[]', el.value);
            });
            saveBtn.disabled = true;
            fetch('pages/dashboard.php', {
                method: 'POST',
                body: fd,
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(function(r) { return r.text(); })
                .then(function(text) {
                    if (window.__temperAuthRedirecting) return;
                    if (typeof window.isAuthExpiredPayload === 'function' && window.isAuthExpiredPayload(text)) {
                        window.redirectToLoginExpired();
                        return;
                    }
                    let data = null;
                    try { data = JSON.parse(text); } catch (e) { data = null; }
                    if (!data || !data.success) {
                        const err = (data && data.error) ? data.error : 'Could not save account selection.';
                        if (typeof showToast === 'function') showToast(err, 'danger');
                        return;
                    }
                    if (amountEl && data.cash_total_formatted) {
                        amountEl.textContent = data.cash_total_formatted;
                    }
                    savedOk = true;
                    snapshotChecks();
                    closeModal();
                    if (typeof showToast === 'function') {
                        showToast('Total Cash / Bank accounts updated.', 'success', 2500);
                    }
                })
                .catch(function() {
                    if (typeof showToast === 'function') {
                        showToast('Could not save account selection.', 'danger');
                    }
                })
                .finally(function() {
                    saveBtn.disabled = false;
                });
        });
    }
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-dashboard-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>
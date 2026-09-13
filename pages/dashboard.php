<?php

require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/fund_utils.php';
require_once __DIR__ . '/../includes/budget_utils.php';
require_once __DIR__ . '/../includes/user_preferences.php';
require_once __DIR__ . '/../includes/permissions.php';

$today = date('Y-m-d');
$actorUser = getCurrentUser();
$dashAcl = $actorUser ? loadUserAcl($db, (int)$actorUser['id']) : null;
$dashPerms = $dashAcl['permissions'] ?? [];
$dashUserId = $actorUser ? (int)$actorUser['id'] : 0;

$canLedger = permissionSetAllows($dashPerms, 'page.ledger');
$canReports = permissionSetAllows($dashPerms, 'page.reports');
$canTasks = permissionSetAllows($dashPerms, 'page.tasks');
$canBudget = permissionSetAllows($dashPerms, 'page.budget');

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
 * Account ids included in Cash / bank. Null preference → all active assets.
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

/**
 * @param list<int> $accountIds
 * @return list<int>
 */
function dashboardSanitizeAccountIds(array $accountIds): array
{
    $ids = [];
    foreach ($accountIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

/**
 * Signed asset balance (debit − credit) for the given account ids as of $asOf.
 *
 * @param list<int> $accountIds
 */
function dashboardSumAssetBalances(mysqli $db, array $accountIds, string $asOf): float
{
    $ids = dashboardSanitizeAccountIds($accountIds);
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

/**
 * Period cash in (asset debits) / out (asset credits) for selected accounts.
 *
 * @param list<int> $accountIds
 * @return array{in:float,out:float}
 */
function dashboardSumAssetPeriodActivity(mysqli $db, array $accountIds, string $dateFrom, string $dateTo): array
{
    $empty = ['in' => 0.0, 'out' => 0.0];
    $ids = dashboardSanitizeAccountIds($accountIds);
    if ($ids === [] || $dateFrom === '' || $dateTo === '') {
        return $empty;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "
        SELECT
            COALESCE(SUM(CASE WHEN tl.type = 'debit' THEN tl.amount ELSE 0 END), 0) AS inflow,
            COALESCE(SUM(CASE WHEN tl.type = 'credit' THEN tl.amount ELSE 0 END), 0) AS outflow
        FROM transaction_lines tl
        JOIN accounts a ON a.id = tl.account_id
        JOIN transaction_details td ON td.id = tl.transaction_detail_id
        WHERE a.archived = FALSE
          AND a.account_type = 'asset'
          AND a.id IN ({$placeholders})
          AND td.transaction_date >= ?
          AND td.transaction_date <= ?
    ";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return $empty;
    }
    $types = str_repeat('i', count($ids)) . 'ss';
    $params = array_merge($ids, [$dateFrom, $dateTo]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return [
        'in' => (float)($row['inflow'] ?? 0),
        'out' => (float)($row['outflow'] ?? 0),
    ];
}

function dashboardFormatMoney(float $amount): string
{
    $neg = $amount < 0;
    return ($neg ? '-' : '') . '$' . number_format(abs($amount), 2);
}

function dashboardFormatDateRange(string $from, string $to): string
{
    $a = DateTime::createFromFormat('Y-m-d', $from);
    $b = DateTime::createFromFormat('Y-m-d', $to);
    if (!$a instanceof DateTime || !$b instanceof DateTime) {
        return $from . ' – ' . $to;
    }
    if ($a->format('Y') === $b->format('Y')) {
        if ($a->format('Y-m') === $b->format('Y-m')) {
            return $a->format('M j') . ' – ' . $b->format('j, Y');
        }
        return $a->format('M j') . ' – ' . $b->format('M j, Y');
    }
    return $a->format('M j, Y') . ' – ' . $b->format('M j, Y');
}

function dashboardFormatShortDate(?string $iso): string
{
    $iso = trim((string)$iso);
    if ($iso === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    return $dt instanceof DateTime ? $dt->format('M j, Y') : $iso;
}

/**
 * @return array{pending:int,cleared:int,unreconciled:int}
 */
function dashboardTxnStatusCounts(mysqli $db): array
{
    $out = ['pending' => 0, 'cleared' => 0, 'unreconciled' => 0];
    $res = $db->query(
        "SELECT
            COALESCE(SUM(status = 'pending'), 0) AS pending,
            COALESCE(SUM(status = 'cleared'), 0) AS cleared,
            COALESCE(SUM(status <> 'reconciled'), 0) AS unreconciled
         FROM transaction_details"
    );
    if ($res) {
        $row = $res->fetch_assoc() ?: [];
        $out['pending'] = (int)($row['pending'] ?? 0);
        $out['cleared'] = (int)($row['cleared'] ?? 0);
        $out['unreconciled'] = (int)($row['unreconciled'] ?? 0);
        $res->close();
    }
    return $out;
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

function dashboardCashMetaText(int $selectedCount, int $assetCount): string
{
    if ($assetCount <= 0) {
        return 'No asset accounts';
    }
    if ($selectedCount === $assetCount) {
        return $assetCount === 1 ? 'All 1 asset account' : 'All ' . $assetCount . ' asset accounts';
    }
    return $selectedCount . ' of ' . $assetCount . ' asset accounts';
}

$period = budgetCurrentPeriodDates($db);
$dateFrom = (string)$period['start_date'];
$dateTo = (string)$period['end_date'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_total_cash_accounts') {
    if ($dashUserId <= 0) {
        dashboardSendJson(['success' => false, 'error' => 'You must be signed in to save this setting.'], $db);
    }
    if (!userPreferencesColumnExists($db)) {
        dashboardSendJson([
            'success' => false,
            'error' => 'User preferences are not available.',
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
    $activity = dashboardSumAssetPeriodActivity($db, $selected, $dateFrom, $dateTo);
    dashboardSendJson([
        'success' => true,
        'account_ids' => $selected,
        'cash_total' => $cashTotal,
        'cash_total_formatted' => dashboardFormatMoney($cashTotal),
        'cash_meta' => dashboardCashMetaText(count($selected), count($allowed)),
        'selected_count' => count($selected),
        'asset_count' => count($allowed),
        'period_in' => $activity['in'],
        'period_out' => $activity['out'],
        'period_in_formatted' => dashboardFormatMoney($activity['in']),
        'period_out_formatted' => dashboardFormatMoney($activity['out']),
    ], $db);
}

$assetAccounts = dashboardActiveAssetAccounts($db);
$allAssetIds = dashboardAssetAccountIds($assetAccounts);
$cashAccountIds = dashboardResolveCashAccountIds($db, $dashUserId, $allAssetIds);
$cashSelectedSet = array_fill_keys($cashAccountIds, true);
$cashTotal = dashboardSumAssetBalances($db, $cashAccountIds, $today);
$periodActivity = dashboardSumAssetPeriodActivity($db, $cashAccountIds, $dateFrom, $dateTo);
$txnCounts = dashboardTxnStatusCounts($db);

$funds = fundFetchRecords($db, false);
$fundBalances = fundBalancesMap($db, $today);
$wdrFunds = [];
$wdrTotal = 0.0;
foreach ($funds as $f) {
    if (strtoupper((string)($f['type'] ?? '')) !== 'WDR') {
        continue;
    }
    $id = (int)$f['id'];
    $bal = (float)($fundBalances[$id] ?? 0.0);
    $wdrFunds[] = [
        'id' => $id,
        'name' => (string)$f['name'],
        'balance' => $bal,
    ];
    $wdrTotal += $bal;
}
usort($wdrFunds, static function (array $a, array $b): int {
    $cmp = abs($b['balance']) <=> abs($a['balance']);
    if ($cmp !== 0) {
        return $cmp;
    }
    return strcasecmp($a['name'], $b['name']);
});
$wdrPreview = array_slice($wdrFunds, 0, 4);
$wdrMore = max(0, count($wdrFunds) - count($wdrPreview));

$taskStatusMeta = [
    'upcoming' => ['label' => 'Upcoming', 'badge' => 'secondary'],
    'due_soon' => ['label' => 'Due soon', 'badge' => 'warning'],
    'overdue' => ['label' => 'Overdue', 'badge' => 'danger'],
    'in_progress' => ['label' => 'In progress', 'badge' => 'info'],
    'done' => ['label' => 'Done', 'badge' => 'success'],
];
$upcomingTasks = [];
$pendingTaskCount = 0;
$showTasksCard = false;
if ($canTasks) {
    $tasksCheck = $db->query("SHOW TABLES LIKE 'tasks'");
    $tasksTableOk = $tasksCheck && $tasksCheck->num_rows > 0;
    if ($tasksCheck) {
        $tasksCheck->close();
    }
    if ($tasksTableOk) {
        $taskSql = "
            SELECT id, title, due_date, status
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
            LIMIT 5
        ";
        $taskRes = $db->query($taskSql);
        if ($taskRes) {
            while ($row = $taskRes->fetch_assoc()) {
                $upcomingTasks[] = $row;
            }
            $taskRes->close();
        }
        $cntRes = $db->query("SELECT COUNT(*) AS n FROM tasks WHERE status <> 'done'");
        if ($cntRes) {
            $pendingTaskCount = (int)($cntRes->fetch_assoc()['n'] ?? 0);
            $cntRes->close();
        }
        $showTasksCard = $pendingTaskCount > 0;
    }
}

$cashNav = $canLedger ? 'ledger' : '';
$periodNav = $canLedger ? 'ledger' : ($canBudget ? 'budget' : '');
$restrictedNav = $canLedger ? 'funds' : ($canReports ? 'reports' : '');
$unclearedNav = $canLedger ? 'ledger' : '';
$tasksNav = $canTasks ? 'tasks' : '';

$periodLabel = dashboardFormatDateRange($dateFrom, $dateTo);
$periodSource = (string)($period['source'] ?? 'calendar');
$budgetName = trim((string)($period['budget_name'] ?? ''));
if ($periodSource === 'budget' && $budgetName !== '') {
    $periodHint = $budgetName . ' · ' . $periodLabel;
} else {
    $periodHint = 'Calendar year · ' . $periodLabel;
}

$cashMeta = dashboardCashMetaText(count($cashAccountIds), count($allAssetIds));
$h = static function (mixed $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};
?>
<style>
    .dash-page .dash-card {
        height: 100%;
        border: 1px solid var(--bs-border-color);
        border-radius: 0.75rem;
        background: var(--bs-body-bg);
        box-shadow: 0 0.08rem 0.35rem rgba(0, 0, 0, 0.05);
        color: inherit;
        position: relative;
    }
    .dash-page .dash-card.is-nav {
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
    }
    .dash-page .dash-card.is-nav:hover,
    .dash-page .dash-card.is-nav:focus-visible {
        border-color: rgba(var(--bs-primary-rgb), 0.45);
        box-shadow: 0 0.12rem 0.5rem rgba(0, 0, 0, 0.08);
    }
    .dash-page .dash-card:focus-visible {
        outline: 2px solid rgba(var(--bs-primary-rgb), 0.55);
        outline-offset: 2px;
    }
    .dash-page .dash-card-body {
        padding: 0.95rem 1rem 1rem;
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        min-height: 100%;
    }
    .dash-page .dash-kicker {
        font-size: 0.78rem;
        font-weight: 600;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        color: var(--bs-secondary-color);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
    }
    .dash-page .dash-figure {
        font-size: 1.7rem;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        letter-spacing: -0.02em;
        line-height: 1.15;
        margin: 0.15rem 0 0;
    }
    .dash-page .dash-split {
        display: flex;
        gap: 1.1rem;
        margin-top: 0.15rem;
    }
    .dash-page .dash-split-item {
        min-width: 0;
        flex: 1;
    }
    .dash-page .dash-split-label {
        font-size: 0.75rem;
        color: var(--bs-secondary-color);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .dash-page .dash-in { color: var(--bs-success); }
    .dash-page .dash-out { color: var(--bs-danger); }
    .dash-page .dash-meta {
        font-size: 0.8rem;
        color: var(--bs-secondary-color);
        margin: 0;
    }
    .dash-page .dash-go {
        margin-top: auto;
        padding-top: 0.45rem;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--bs-primary);
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }
    .dash-page .dash-card:not(.is-nav) .dash-go { display: none; }
    .dash-page .dash-gear {
        border: 0;
        background: transparent;
        color: var(--bs-secondary-color);
        padding: 0.35rem;
        margin: -0.35rem -0.25rem -0.35rem 0;
        line-height: 1;
        border-radius: 0.4rem;
        min-width: 2.25rem;
        min-height: 2.25rem;
    }
    .dash-page .dash-gear:hover,
    .dash-page .dash-gear:focus-visible {
        color: var(--bs-body-color);
        background: var(--bs-tertiary-bg);
    }
    .dash-page .dash-fund-row {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        font-size: 0.9rem;
        padding: 0.22rem 0;
        border-bottom: 1px solid var(--bs-border-color-translucent);
    }
    .dash-page .dash-fund-row:last-child { border-bottom: 0; }
    .dash-page .dash-fund-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dash-page .dash-fund-amt {
        font-variant-numeric: tabular-nums;
        font-weight: 600;
        flex: 0 0 auto;
    }
    .dash-page .dash-task {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.6rem;
        padding: 0.28rem 0;
        border-bottom: 1px solid var(--bs-border-color-translucent);
    }
    .dash-page .dash-task:last-child { border-bottom: 0; }
    .dash-page .dash-task-title { font-weight: 600; font-size: 0.9rem; line-height: 1.25; }
    .dash-page .dash-task-due { font-size: 0.75rem; color: var(--bs-secondary-color); }
    @media (max-width: 767.98px) {
        .dash-page .dash-card-body {
            padding: 1.05rem 1rem 1.15rem;
        }
        .dash-page .dash-figure {
            font-size: 2.15rem;
        }
        .dash-page .dash-split .dash-figure {
            font-size: 1.85rem;
        }
        .dash-page .dash-gear {
            min-width: 2.5rem;
            min-height: 2.5rem;
        }
    }
</style>

<div class="dash-page" id="dashPage">
    <div class="row mb-3 mb-md-4 page-title-row">
        <div class="col-12">
            <h2 class="mb-1 h3 h-md-2">Home</h2>
            <p class="text-muted small mb-0"><?= $h($periodHint) ?></p>
        </div>
    </div>

    <div class="row g-2 g-md-3">
        <div class="col-12 col-md-6 col-xl-4">
            <div class="dash-card<?= $cashNav !== '' ? ' is-nav' : '' ?>"
                 <?= $cashNav !== '' ? ' data-nav="' . $h($cashNav) . '" role="link" tabindex="0"' : '' ?>>
                <div class="dash-card-body">
                    <div class="dash-kicker">
                        <span>Cash / bank</span>
                        <button type="button"
                                class="dash-gear"
                                id="totalCashSetupBtn"
                                title="Choose accounts"
                                aria-label="Choose accounts included in Cash / bank">
                            <i class="bi bi-gear" aria-hidden="true"></i>
                        </button>
                    </div>
                    <p class="dash-figure" id="dashCashAmount"><?= $h(dashboardFormatMoney($cashTotal)) ?></p>
                    <p class="dash-meta mb-0" id="dashCashMeta"><?= $h($cashMeta) ?></p>
                    <?php if ($cashNav !== ''): ?>
                        <span class="dash-go">Open Ledger <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-4">
            <div class="dash-card<?= $periodNav !== '' ? ' is-nav' : '' ?>"
                 <?= $periodNav !== '' ? ' data-nav="' . $h($periodNav) . '" role="link" tabindex="0"' : '' ?>>
                <div class="dash-card-body">
                    <div class="dash-kicker"><span>This period</span></div>
                    <p class="dash-meta mb-0"><?= $h($periodLabel) ?></p>
                    <div class="dash-split">
                        <div class="dash-split-item">
                            <div class="dash-split-label">In</div>
                            <p class="dash-figure dash-in" id="dashPeriodIn"><?= $h(dashboardFormatMoney($periodActivity['in'])) ?></p>
                        </div>
                        <div class="dash-split-item">
                            <div class="dash-split-label">Out</div>
                            <p class="dash-figure dash-out" id="dashPeriodOut"><?= $h(dashboardFormatMoney($periodActivity['out'])) ?></p>
                        </div>
                    </div>
                    <?php if ($periodNav !== ''): ?>
                        <span class="dash-go"><?= $periodNav === 'ledger' ? 'Open Ledger' : 'Open Budget' ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-4">
            <div class="dash-card<?= $restrictedNav !== '' ? ' is-nav' : '' ?>"
                 <?= $restrictedNav !== '' ? ' data-nav="' . $h($restrictedNav) . '" role="link" tabindex="0"' : '' ?>>
                <div class="dash-card-body">
                    <div class="dash-kicker"><span>Restricted (WDR)</span></div>
                    <p class="dash-figure"><?= $h(dashboardFormatMoney($wdrTotal)) ?></p>
                    <?php if ($wdrPreview === []): ?>
                        <p class="dash-meta mb-0">No restricted funds on file.</p>
                    <?php else: ?>
                        <div class="mt-1">
                            <?php foreach ($wdrPreview as $fund): ?>
                                <div class="dash-fund-row">
                                    <span class="dash-fund-name"><?= $h($fund['name']) ?></span>
                                    <span class="dash-fund-amt"><?= $h(dashboardFormatMoney($fund['balance'])) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($wdrMore > 0): ?>
                            <p class="dash-meta mb-0"><?= $h((string)$wdrMore) ?> more on Funds</p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($restrictedNav !== ''): ?>
                        <span class="dash-go"><?= $restrictedNav === 'funds' ? 'Open Funds' : 'Open Reports' ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-4">
            <div class="dash-card<?= $unclearedNav !== '' ? ' is-nav' : '' ?>"
                 <?= $unclearedNav !== '' ? ' data-nav="' . $h($unclearedNav) . '" role="link" tabindex="0"' : '' ?>>
                <div class="dash-card-body">
                    <div class="dash-kicker"><span>Uncleared / unreconciled</span></div>
                    <p class="dash-figure"><?= $h((string)$txnCounts['unreconciled']) ?></p>
                    <p class="dash-meta mb-0">
                        <?= $h((string)$txnCounts['pending']) ?> uncleared
                        · <?= $h((string)$txnCounts['cleared']) ?> cleared, not reconciled
                    </p>
                    <?php if ($unclearedNav !== ''): ?>
                        <span class="dash-go">Open Ledger <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($showTasksCard): ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="dash-card<?= $tasksNav !== '' ? ' is-nav' : '' ?>"
                 <?= $tasksNav !== '' ? ' data-nav="' . $h($tasksNav) . '" role="link" tabindex="0"' : '' ?>>
                <div class="dash-card-body">
                    <div class="dash-kicker"><span>Tasks</span></div>
                    <p class="dash-figure"><?= $h((string)$pendingTaskCount) ?></p>
                    <p class="dash-meta mb-0"><?= $pendingTaskCount === 1 ? 'Open task' : 'Open tasks' ?></p>
                    <div class="mt-1">
                        <?php foreach ($upcomingTasks as $task): ?>
                            <?php
                                $statusKey = (string)($task['status'] ?? 'upcoming');
                                $meta = $taskStatusMeta[$statusKey] ?? ['label' => $statusKey, 'badge' => 'secondary'];
                                $due = dashboardFormatShortDate($task['due_date'] ?? '');
                            ?>
                            <div class="dash-task">
                                <div>
                                    <div class="dash-task-title"><?= $h($task['title'] ?? '') ?></div>
                                    <div class="dash-task-due"><?= $due !== '' ? $h($due) : 'No due date' ?></div>
                                </div>
                                <span class="badge text-bg-<?= $h($meta['badge']) ?>"><?= $h($meta['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($tasksNav !== ''): ?>
                        <span class="dash-go">Open Tasks <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="totalCashAccountsModal" tabindex="-1" aria-labelledby="totalCashAccountsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title h6 mb-0" id="totalCashAccountsModalLabel">
                    <i class="bi bi-bank me-1"></i> Cash / bank accounts
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">Choose which asset accounts are included in Cash / bank and this period’s in / out. Saved for your user.</p>
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
                                    <?= $h($label) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0">No asset accounts are set up yet. Add them under Setup → Accounts.</p>
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
    const pageRoot = document.getElementById('dashPage');

    function goNav(page) {
        if (!page || typeof loadPage !== 'function') return;
        loadPage(page);
    }

    if (pageRoot) {
        pageRoot.querySelectorAll('.dash-card[data-nav]').forEach(function(card) {
            function go() {
                goNav(card.getAttribute('data-nav'));
            }
            card.addEventListener('click', function(e) {
                if (e.target.closest('.dash-gear, button, a, input, label')) return;
                go();
            });
            card.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    go();
                }
            });
        });
    }

    let modalEl = document.getElementById('totalCashAccountsModal');
    if (modalEl && typeof window.mountModalOnBody === 'function') {
        modalEl = window.mountModalOnBody(modalEl);
    }
    const setupBtn = document.getElementById('totalCashSetupBtn');
    const saveBtn = document.getElementById('totalCashSaveBtn');
    const selectAllBtn = document.getElementById('totalCashSelectAll');
    const selectNoneBtn = document.getElementById('totalCashSelectNone');
    const amountEl = document.getElementById('dashCashAmount');
    const metaEl = document.getElementById('dashCashMeta');
    const inEl = document.getElementById('dashPeriodIn');
    const outEl = document.getElementById('dashPeriodOut');
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
        setupBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
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
                    if (metaEl && data.cash_meta) {
                        metaEl.textContent = data.cash_meta;
                    }
                    if (inEl && data.period_in_formatted) {
                        inEl.textContent = data.period_in_formatted;
                    }
                    if (outEl && data.period_out_formatted) {
                        outEl.textContent = data.period_out_formatted;
                    }
                    savedOk = true;
                    snapshotChecks();
                    closeModal();
                    if (typeof showToast === 'function') {
                        showToast('Cash / bank accounts updated.', 'success', 2500);
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

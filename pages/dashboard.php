<?php

    // Security and DB connection already handled by index.php
    // Light fallback in case
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/workflow_engine.php';
require_once __DIR__ . '/../includes/workflows/registry.php';

$today = date('Y-m-d');
    $recent_limit = 6;
    $actorUser = getCurrentUser();
    $tellerLimited = $actorUser ? isTellerLimitedUser($db, (int)$actorUser['id']) : false;

/**
 * Quick-access dashboard links (easy to extend).
 * Optional keys: page (SPA), href (external), roles ('all'|'staff'), variant, icon, description
 *
 * @return list<array{label:string,icon:string,variant:string,page?:string,href?:string,description?:string,roles?:string}>
 */
function dashboardQuickLinks(): array {
    return [
        [
            'label' => 'Start Contribution',
            'description' => 'Dual-count offering workflow',
            'page' => 'workflow_contribution',
            'icon' => 'bi-cash-coin',
            'variant' => 'success',
            'roles' => 'all',
        ],
        [
            'label' => 'View Ledger',
            'description' => 'Transactions & attachments',
            'page' => 'ledger',
            'icon' => 'bi-currency-dollar',
            'variant' => 'primary',
            'roles' => 'staff',
        ],
        [
            'label' => 'Run Reports',
            'description' => 'Balances & activity',
            'page' => 'reports',
            'icon' => 'bi-file-earmark-bar-graph',
            'variant' => 'info',
            'roles' => 'staff',
        ],
        [
            'label' => 'Budget',
            'description' => 'Plans & tracking',
            'page' => 'budget',
            'icon' => 'bi-graph-up',
            'variant' => 'secondary',
            'roles' => 'staff',
        ],
        [
            'label' => 'Tasks',
            'description' => 'Reminders & to-dos',
            'page' => 'tasks',
            'icon' => 'bi-check2-square',
            'variant' => 'secondary',
            'roles' => 'staff',
        ],
        [
            'label' => 'Workflows Hub',
            'description' => 'All guided processes',
            'page' => 'workflows',
            'icon' => 'bi-diagram-3',
            'variant' => 'outline-secondary',
            'roles' => 'all',
        ],
        [
            'label' => 'Funds Setup',
            'description' => 'Lookups · funds',
            'page' => 'setup_funds',
            'icon' => 'bi-wallet2',
            'variant' => 'outline-secondary',
            'roles' => 'staff',
        ],
        [
            'label' => 'Backup / Restore',
            'description' => 'Admin · database export',
            'page' => 'admin-backup',
            'icon' => 'bi-cloud-arrow-up',
            'variant' => 'outline-secondary',
            'roles' => 'staff',
        ],
    ];
}

/**
 * Build prioritized pending workflow action cards for the dashboard.
 *
 * @return list<array{
 *   priority:int,type:string,title:string,action:string,action_badge:string,
 *   personnel:list<string>,page:string,instance_id:int,updated_at:?string
 * }>
 */
function dashboardWorkflowPendingItems(mysqli $db): array {
    $items = [];
    try {
        workflowRequireTables($db);
        workflowLoadTypeModules();
    } catch (Throwable $e) {
        return [];
    }

    $instances = workflowListInstances($db, null, 40);
    $userNameCache = [];
    $resolveUser = static function (?int $userId) use ($db, &$userNameCache): string {
        if (!$userId || $userId <= 0) {
            return '';
        }
        if (isset($userNameCache[$userId])) {
            return $userNameCache[$userId];
        }
        $name = '';
        $stmt = $db->prepare(
            'SELECT username, first_name, last_name FROM users WHERE id = ? LIMIT 1'
        );
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $full = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                $name = $full !== '' ? $full : (string)($row['username'] ?? '');
            }
        }
        $userNameCache[$userId] = $name;
        return $name;
    };

    foreach ($instances as $inst) {
        $type = (string)($inst['workflow_type'] ?? '');
        $status = (string)($inst['status'] ?? '');
        $id = (int)($inst['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        // Contribution pending actions
        if ($type === 'contribution') {
            if ($status === 'deposited' || $status === 'cancelled' || $status === 'complete') {
                continue;
            }

            $txData = [];
            $txId = (int)($inst['transaction_detail_id'] ?? 0);
            if ($txId > 0) {
                $td = $db->prepare('SELECT transaction_data FROM transaction_details WHERE id = ? LIMIT 1');
                if ($td) {
                    $td->bind_param('i', $txId);
                    $td->execute();
                    $trow = $td->get_result()->fetch_assoc();
                    $td->close();
                    if ($trow && !empty($trow['transaction_data'])) {
                        $txData = json_decode((string)$trow['transaction_data'], true) ?: [];
                    }
                }
            }

            $firstName = $resolveUser((int)($txData['first_teller_id'] ?? 0));
            $secondName = $resolveUser((int)($txData['second_teller_id'] ?? 0));
            $personnel = [];

            if ($status === 'draft_pending_second_count') {
                if ($firstName !== '') {
                    $personnel[] = 'Teller: ' . $firstName;
                }
                $personnel[] = 'Needed: Second Teller';
                $items[] = [
                    'priority' => 10,
                    'type' => 'contribution',
                    'title' => (string)($inst['title'] ?? 'Contribution'),
                    'action' => 'Dual count needed',
                    'action_badge' => 'warning',
                    'personnel' => $personnel,
                    'page' => 'workflow_contribution',
                    'instance_id' => $id,
                    'updated_at' => $inst['updated_at'] ?? null,
                ];
                continue;
            }

            if ($status === 'dual_count_complete_pending_official') {
                if ($firstName !== '') {
                    $personnel[] = 'Teller: ' . $firstName;
                }
                if ($secondName !== '') {
                    $personnel[] = '2nd Teller: ' . $secondName;
                }
                $personnel[] = 'Needed: Official (Treasurer / Finance)';
                $items[] = [
                    'priority' => 20,
                    'type' => 'contribution',
                    'title' => (string)($inst['title'] ?? 'Contribution'),
                    'action' => 'Official validation pending',
                    'action_badge' => 'info',
                    'personnel' => $personnel,
                    'page' => 'workflow_contribution',
                    'instance_id' => $id,
                    'updated_at' => $inst['updated_at'] ?? null,
                ];
                continue;
            }

            // Unknown active contribution status — still surface
            if ($status !== '' && $status !== 'deposited' && $status !== 'cancelled') {
                $items[] = [
                    'priority' => 50,
                    'type' => 'contribution',
                    'title' => (string)($inst['title'] ?? 'Contribution'),
                    'action' => function_exists('contribStatusLabel')
                        ? contribStatusLabel($status)
                        : $status,
                    'action_badge' => 'secondary',
                    'personnel' => $personnel,
                    'page' => 'workflow_contribution',
                    'instance_id' => $id,
                    'updated_at' => $inst['updated_at'] ?? null,
                ];
            }
            continue;
        }

        // Future workflow types: generic pending surface if not terminal
        $terminal = ['complete', 'completed', 'cancelled', 'deposited', 'closed'];
        if (!in_array($status, $terminal, true) && $status !== '') {
            $reg = workflowTypeRegistry()[$type] ?? null;
            $items[] = [
                'priority' => 40,
                'type' => $type,
                'title' => (string)($inst['title'] ?? ($reg['title'] ?? $type)),
                'action' => 'Action needed · ' . $status,
                'action_badge' => 'secondary',
                'personnel' => [],
                'page' => (string)($reg['page'] ?? 'workflows'),
                'instance_id' => $id,
                'updated_at' => $inst['updated_at'] ?? null,
            ];
        }
    }

    usort($items, static function ($a, $b) {
        if ($a['priority'] !== $b['priority']) {
            return $a['priority'] <=> $b['priority'];
        }
        return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
    });

    return $items;
}

    $fundBalanceSql = "
        SELECT COALESCE(SUM(
            CASE WHEN tl.type = 'debit' THEN tl.amount ELSE -tl.amount END
        ), 0) AS balance
        FROM transaction_lines tl
        JOIN accounts a ON a.id = tl.account_id AND a.normal_balance = 'debit'
        JOIN transaction_details td ON td.id = tl.transaction_detail_id
        WHERE tl.fund_id = ?
          AND td.transaction_date <= ?
    ";

    $cashBankSql = "
        SELECT COALESCE(SUM(
            CASE WHEN tl.type = 'debit' THEN tl.amount ELSE -tl.amount END
        ), 0) AS balance
        FROM transaction_lines tl
        JOIN accounts a ON a.id = tl.account_id
        JOIN transaction_details td ON td.id = tl.transaction_detail_id
        WHERE a.archived = FALSE
          AND a.normal_balance = 'debit'
          AND a.name IN ('Cash', 'Bank Account')
          AND td.transaction_date <= ?
    ";

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

    // Total cash and bank balances
    $cash_total = 0;
    $cashStmt = $db->prepare($cashBankSql);
    $cashStmt->bind_param('s', $today);
    $cashStmt->execute();
    $cash_total = (float)($cashStmt->get_result()->fetch_assoc()['balance'] ?? 0);
    $cashStmt->close();

    // Recent transactions (debit / credit totals for traditional two-column display)
    $recent_tx = [];
    $txStmt = $db->prepare("
        SELECT td.id, td.transaction_date, td.pay_to, td.reference_number, td.status,
               COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id = td.id AND type = 'debit'), 0) AS total_debits,
               COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id = td.id AND type = 'credit'), 0) AS total_credits
        FROM transaction_details td
        ORDER BY td.transaction_date DESC, td.id DESC
        LIMIT ?
    ");
    $txStmt->bind_param('i', $recent_limit);
    $txStmt->execute();
    $txRes = $txStmt->get_result();
    if ($txRes) {
        while ($tx = $txRes->fetch_assoc()) {
            $recent_tx[] = $tx;
        }
        $txRes->close();
    }
    $txStmt->close();

    $fmtLedgerAmt = static function ($amount): string {
        $a = (float)$amount;
        if (abs($a) < 0.005) {
            return '';
        }
        return '$' . number_format(abs($a), 2);
    };

    $workflowPending = dashboardWorkflowPendingItems($db);
    $quickLinks = array_values(array_filter(
        dashboardQuickLinks(),
        static function (array $link) use ($tellerLimited): bool {
            $roles = $link['roles'] ?? 'all';
            if ($tellerLimited && $roles === 'staff') {
                return false;
            }
            return true;
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
            <div class="card-header text-bg-info py-2 py-md-3">
                <h5 class="mb-0"><i class="bi bi-bank"></i> <span class="d-none d-md-inline">Total Cash / Bank Balances</span><span class="d-md-none">Cash / Bank</span></h5>
            </div>
            <div class="card-body py-3">
                <h3 class="text-info mb-1">$<?= number_format($cash_total, 2) ?></h3>
                <p class="card-text text-body-secondary small mb-0">Combined cash on hand and bank accounts.</p>
            </div>
        </div>
    </div>
</div>

<!-- Workflow summary + quick links -->
<div class="row mb-3 mb-md-4 g-2 g-md-3">
    <div class="col-12 col-lg-7">
        <div class="card h-100 shadow-sm border-warning border-opacity-25">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-1 py-2">
                <h5 class="mb-0 h6">
                    <i class="bi bi-diagram-3 text-warning"></i> Workflow Summary
                </h5>
                <a href="javascript:void(0)" onclick="loadPage('workflows')" class="small text-decoration-none">All workflows &rarr;</a>
            </div>
            <div class="card-body py-2">
                <?php if (count($workflowPending) === 0): ?>
                    <div class="d-flex align-items-start gap-2 text-success small py-1">
                        <i class="bi bi-check-circle-fill mt-1"></i>
                        <div>
                            <strong>No pending workflow actions.</strong>
                            <div class="text-body-secondary">Dual counts and official validations are clear.</div>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="small text-body-secondary mb-2">
                        <?= count($workflowPending) ?> item<?= count($workflowPending) === 1 ? '' : 's' ?> need attention
                        (highest priority first).
                    </p>
                    <ul class="list-group list-group-flush">
                        <?php foreach (array_slice($workflowPending, 0, 8) as $item): ?>
                            <li class="list-group-item px-0 py-2">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                    <div class="min-w-0">
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                            <span class="badge text-bg-<?= htmlspecialchars($item['action_badge']) ?>">
                                                <?= htmlspecialchars($item['action']) ?>
                                            </span>
                                            <span class="small text-body-secondary text-truncate">
                                                <?= htmlspecialchars($item['title']) ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($item['personnel'])): ?>
                                            <div class="small text-body-secondary">
                                                <?php foreach ($item['personnel'] as $pLine): ?>
                                                    <span class="me-2"><i class="bi bi-person"></i> <?= htmlspecialchars($pLine) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <a href="javascript:void(0)"
                                       class="btn btn-sm btn-outline-primary text-nowrap"
                                       onclick="loadPage('<?= htmlspecialchars($item['page'], ENT_QUOTES) ?>')">
                                        Open
                                    </a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (count($workflowPending) > 8): ?>
                        <div class="small text-body-secondary mt-2">
                            +<?= count($workflowPending) - 8 ?> more —
                            <a href="javascript:void(0)" onclick="loadPage('workflows')">view hub</a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-5">
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
                        <div class="col-6">
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
            </div>
        </div>
    </div>
</div>

<!-- Recent Transactions -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-1">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Transactions</h5>
                <a href="javascript:void(0)" onclick="loadPage('ledger')" class="small text-decoration-none">View all in Ledger &rarr;</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Pay To</th>
                                <th>Ref #</th>
                                <th class="text-end text-nowrap">Debit</th>
                                <th class="text-end text-nowrap">Credit</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recent_tx) > 0): ?>
                                <?php foreach ($recent_tx as $tx): ?>
                                    <?php
                                        $statusBadge = 'bg-secondary';
                                        $statusText = 'Pending';
                                        if ($tx['status'] === 'cleared') {
                                            $statusBadge = 'bg-success';
                                            $statusText = 'Cleared';
                                        } elseif ($tx['status'] === 'reconciled') {
                                            $statusBadge = 'bg-info';
                                            $statusText = 'Reconciled';
                                        }
                                        $deb = $fmtLedgerAmt($tx['total_debits'] ?? 0);
                                        $cred = $fmtLedgerAmt($tx['total_credits'] ?? 0);
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($tx['transaction_date']) ?></td>
                                        <td><?= htmlspecialchars($tx['pay_to'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($tx['reference_number'] ?? '-') ?></td>
                                        <td class="text-end font-monospace text-primary fw-semibold"><?= $deb !== '' ? htmlspecialchars($deb) : '' ?></td>
                                        <td class="text-end font-monospace text-success fw-semibold"><?= $cred !== '' ? htmlspecialchars($cred) : '' ?></td>
                                        <td><span class="badge <?= $statusBadge ?>"><?= $statusText ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center py-4">No transactions recorded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php $db->close(); ?>
<?php

    // Security and DB connection already handled by index.php
    // Light fallback in case
require_once __DIR__ . '/../includes/page_bootstrap.php';
$today = date('Y-m-d');
    $recent_limit = 6;

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
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">Financial Dashboard</h2>
        <p class="text-muted">Stewardship & Accountability Dashboard | Based on Treasurer’s Guide Rev 1.0</p>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4 g-3">
    <div class="col-md-4">
        <div class="card border-primary h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-wallet"></i> Net Assets Without Donor Restrictions (WODR)</h5>
            </div>
            <div class="card-body">
                <h3 class="text-primary mb-1">$<?= number_format($wodr_total, 2) ?></h3>
                <p class="card-text text-muted small mb-0">Unrestricted operating resources.</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-success h-100">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-wallet2"></i> Net Assets With Donor Restrictions (WDR)</h5>
            </div>
            <div class="card-body">
                <h3 class="text-success mb-1">$<?= number_format($wdr_total, 2) ?></h3>
                <p class="card-text text-muted small mb-0">Donor-restricted resources.</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-info h-100">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-bank"></i> Total Cash / Bank Balances</h5>
            </div>
            <div class="card-body">
                <h3 class="text-info mb-1">$<?= number_format($cash_total, 2) ?></h3>
                <p class="card-text text-muted small mb-0">Combined cash on hand and bank accounts.</p>
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
            <div class="card-header d-flex justify-content-between align-items-center">
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
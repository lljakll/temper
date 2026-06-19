<?php

    // Security and DB connection already handled by index.php
    // Light fallback in case
    if (!isset($db)) {
        require_once __DIR__ . '/../config.php';
        $db = getDbConnection();
    }

    // Get all active funds
    $funds_query = "SELECT id, name, code, type, current_balance, description, purpose
                    FROM funds
                    WHERE is_active = TRUE AND archived = FALSE
                    ORDER BY type, name";

    $funds_result = $db->query($funds_query);

    $wodr_total = 0;
    $wdr_total = 0;

    if ($funds_result && $funds_result->num_rows > 0) {
        while ($fund = $funds_result->fetch_assoc()) {
            if ($fund['type'] === 'WODR') {
                $wodr_total += $fund['current_balance'];
            } else {
                $wdr_total += $fund['current_balance'];
            }
        }
        $funds_result->data_seek(0); // Reset for reuse
    }
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">Financial Dashboard</h2>
        <p class="text-muted">Stewardship & Accountability Dashboard | Based on Treasurer’s Guide Rev 1.0</p>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card border-primary h-100">
            <div class="card-header bg-primary text-white">
                <h5><i class="bi bi-wallet"></i> Net Assets Without Donor Restrictions (WODR)</h5>
            </div>
            <div class="card-body">
                <h3 class="text-primary">$<?= number_format($wodr_total, 2) ?></h3>
                <p class="card-text">Unrestricted operating resources.</p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-success h-100">
            <div class="card-header bg-success text-white">
                <h5><i class="bi bi-wallet2"></i> Net Assets With Donor Restrictions (WDR)</h5>
            </div>
            <div class="card-body">
                <h3 class="text-success">$<?= number_format($wdr_total, 2) ?></h3>
                <p class="card-text">Donor-restricted resources.</p>
            </div>
        </div>
    </div>
</div>

<!-- All Funds Table -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-coin"></i> All Active Funds</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
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
                            <?php if ($funds_result && $funds_result->num_rows > 0): ?>
                                <?php while ($fund = $funds_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($fund['name']) ?></td>
                                    <td><?= htmlspecialchars($fund['code'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($fund['type']) ?></td>
                                    <td class="text-end fw-bold <?= $fund['type'] === 'WODR' ? 'text-primary' : 'text-success' ?>">
                                        $<?= number_format($fund['current_balance'], 2) ?>
                                    </td>
                                    <td><?= htmlspecialchars($fund['description'] ?? '') ?></td>
                                </tr>
                                <?php endwhile; ?>
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

<?php $db->close(); ?>
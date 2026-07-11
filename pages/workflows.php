<?php
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/workflow_bootstrap.php';
    require_once __DIR__ . '/../includes/workflows/registry.php';

    workflowRequireTables($db);
    $actor = workflowRequireActor($db, 'workflow.view');

    $typeStats = workflowCollectHubStats($db);
    $hubTotals = workflowHubTotals($typeStats);
    $registry = workflowTypeRegistry();
?>

<div class="container-fluid mt-2 px-0 px-sm-2">
    <div class="mb-3 mb-md-4">
        <h2 class="mb-1 h3">Workflows</h2>
        <p class="text-muted small mb-0">
            Guided, multi-person processes with approvals and full audit logging.
            Transactional data lives in the ledger; workflows orchestrate steps and sign-offs.
            Role: <strong><?= htmlspecialchars($actor['role_name'] ?? 'Unknown') ?></strong>
        </p>
    </div>

    <?php if ($hubTotals['total'] === 0): ?>
    <div class="alert alert-light border mb-4">
        <i class="bi bi-info-circle me-1"></i>
        No workflow instances yet. Choose a workflow below to start — for example, open
        <strong>Contribution Processing</strong> to record a Sunday offering with dual-count controls.
    </div>
    <?php elseif ($hubTotals['active'] === 0): ?>
    <div class="alert alert-light border mb-4">
        <i class="bi bi-check-circle me-1"></i>
        <?= (int)$hubTotals['total'] ?> workflow instance(s) on file; none are awaiting action right now.
    </div>
    <?php endif; ?>

    <div class="row g-2 g-md-3">
<?php foreach ($registry as $type => $def): ?>
<?php if (empty($def['enabled'])) continue; ?>
<?php $stats = $typeStats[$type] ?? ['total' => 0, 'active' => 0, 'badges' => []]; ?>
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card h-100 shadow-sm border-<?= htmlspecialchars($def['border_color']) ?> border-opacity-25">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <i class="bi <?= htmlspecialchars($def['icon']) ?> fs-3 text-<?= htmlspecialchars($def['border_color']) ?> me-3"></i>
                        <div>
                            <h5 class="card-title mb-1"><?= htmlspecialchars($def['title']) ?></h5>
                            <p class="card-text small text-muted mb-2">
                                <?= htmlspecialchars($def['description']) ?>
                            </p>
                            <div class="small">
                                <span class="badge bg-secondary"><?= (int)$stats['total'] ?> total</span>
<?php foreach ($stats['badges'] as $badge): ?>
                                <span class="badge <?= htmlspecialchars($badge['class']) ?>"><?= htmlspecialchars($badge['label']) ?></span>
<?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0 pt-0 pb-3 px-3">
                    <button type="button" class="btn btn-<?= htmlspecialchars($def['border_color']) ?> btn-sm" onclick="loadPage('<?= htmlspecialchars($def['page']) ?>')">
                        <i class="bi bi-arrow-right-circle"></i> Open <?= htmlspecialchars($def['title']) ?>
                    </button>
                </div>
            </div>
        </div>
<?php endforeach; ?>

        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card h-100 shadow-sm border-secondary border-opacity-25">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-receipt fs-3 text-secondary me-3"></i>
                        <div>
                            <h5 class="card-title mb-1 text-muted">More Workflows</h5>
                            <p class="card-text small text-muted mb-0">
                                Reimbursements, invoices, payroll, and other guided flows will appear here as they are implemented.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0 pt-0 pb-3 px-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled>Coming Soon</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header py-2">
            <h6 class="mb-0"><i class="bi bi-shield-check"></i> Workflow Principles</h6>
        </div>
        <div class="card-body small text-muted">
            <ul class="mb-0">
                <li>Workflows orchestrate steps and approvals; the ledger holds all transactional data.</li>
                <li>Every step is logged with user, timestamp, and summary for audit sampling.</li>
                <li>Multi-person sign-offs enforce segregation of duties.</li>
                <li>Ledger entries are finalized only after official validation; documents attach to ledger records.</li>
            </ul>
        </div>
    </div>
</div>

<?php $db->close(); ?>
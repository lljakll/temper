<?php
    if (!isset($db)) {
        require_once __DIR__ . '/../config.php';
        $db = getDbConnection();
    }
    require_once __DIR__ . '/../includes/workflow_engine.php';
    require_once __DIR__ . '/../includes/workflows/contribution_workflow.php';

    workflowEnsureTables($db);

    $actor = getCurrentUserWithRole($db);
    if (!$actor || !userHasWorkflowCapability($db, (int)$actor['id'], 'workflow.view')) {
        echo '<div class="alert alert-warning">You do not have permission to access workflows.</div>';
        $db->close();
        exit;
    }

    $contribCount = count(workflowListInstances($db, 'contribution', 200));
    $pendingSecond = 0;
    $pendingOfficial = 0;
    foreach (workflowListInstances($db, 'contribution', 200) as $row) {
        if ($row['status'] === CONTRIB_STATUS_DRAFT) {
            $pendingSecond++;
        } elseif ($row['status'] === CONTRIB_STATUS_DUAL_COMPLETE) {
            $pendingOfficial++;
        }
    }
?>

<div class="container-fluid mt-2">
    <div class="mb-4">
        <h2 class="mb-1">Workflows</h2>
        <p class="text-muted small mb-0">
            Guided, multi-person processes with approvals, document linking, and full audit logging.
            Role: <strong><?= htmlspecialchars($actor['role_name'] ?? 'Unknown') ?></strong>
        </p>
    </div>

    <div class="row g-3">
        <div class="col-md-6 col-xl-4">
            <div class="card h-100 shadow-sm border-success border-opacity-25">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-cash-coin fs-3 text-success me-3"></i>
                        <div>
                            <h5 class="card-title mb-1">Contribution Processing</h5>
                            <p class="card-text small text-muted mb-2">
                                Sunday offerings and similar contributions — dual teller count, official validation, deposit creation.
                            </p>
                            <div class="small">
                                <span class="badge bg-secondary"><?= (int)$contribCount ?> total</span>
                                <?php if ($pendingSecond > 0): ?>
                                    <span class="badge bg-warning text-dark"><?= (int)$pendingSecond ?> pending 2nd count</span>
                                <?php endif; ?>
                                <?php if ($pendingOfficial > 0): ?>
                                    <span class="badge bg-info text-dark"><?= (int)$pendingOfficial ?> pending official</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0 pt-0 pb-3 px-3">
                    <button type="button" class="btn btn-success btn-sm" onclick="loadPage('workflow_contribution')">
                        <i class="bi bi-arrow-right-circle"></i> Open Contribution Workflow
                    </button>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-4">
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
                <li>Every step is logged with user, timestamp, and summary for audit sampling.</li>
                <li>Multi-person sign-offs enforce segregation of duties.</li>
                <li>Ledger entries are created only after official validation.</li>
                <li>Supporting documents attach to the workflow instance as an auditable package.</li>
            </ul>
        </div>
    </div>
</div>

<?php $db->close(); ?>
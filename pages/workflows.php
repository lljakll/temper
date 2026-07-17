<?php
/**
 * Workflows hub — lists active YAML definitions and running instances.
 * Runtime UI (guided multipage) will load from definitions in a later pass.
 */
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/workflow_bootstrap.php';

$engine = null;
$defs = [];
$instances = [];
$loadError = null;
$actor = workflowRequireActor($db, 'workflow.view');
$canManage = userCanManageWorkflows($db, (int)$actor['id']);

try {
    $engine = workflowEngine($db);
    $engine->requireTables();
    $defs = $engine->listActiveDefinitions();
    $instances = $engine->listInstances(null, null, 50);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$running = array_values(array_filter(
    $instances,
    static fn($r) => ($r['status'] ?? '') === 'running'
));
?>

<div class="container-fluid mt-2 px-0 px-sm-2">
    <div class="mb-3 mb-md-4 d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h2 class="mb-1 h3">Workflows</h2>
            <p class="text-muted small mb-0">
                Guided processes defined by immutable YAML. Results land in the ledger;
                workflow data is disposable once the transaction is recorded.
            </p>
        </div>
        <?php if ($canManage): ?>
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadPage('admin-workflows')">
            <i class="bi bi-file-earmark-arrow-up"></i> Manage definitions
        </button>
        <?php endif; ?>
    </div>

    <?php if ($loadError): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <?= htmlspecialchars($loadError) ?>
    </div>
    <?php endif; ?>

    <div class="row g-2 g-md-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="card h-100 border-primary border-opacity-25">
                <div class="card-body">
                    <div class="text-muted small">Active definitions</div>
                    <div class="fs-3 fw-semibold"><?= count($defs) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card h-100 border-warning border-opacity-25">
                <div class="card-body">
                    <div class="text-muted small">Running instances</div>
                    <div class="fs-3 fw-semibold"><?= count($running) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card h-100 border-secondary border-opacity-25">
                <div class="card-body">
                    <div class="text-muted small">Recent instances</div>
                    <div class="fs-3 fw-semibold"><?= count($instances) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header py-2">
                    <h6 class="mb-0"><i class="bi bi-collection"></i> Available workflows</h6>
                </div>
                <div class="card-body p-0">
                    <?php if ($defs === []): ?>
                    <div class="p-3 text-muted small">
                        No active definitions. <?php if ($canManage): ?>
                        <a href="javascript:void(0)" onclick="loadPage('admin-workflows')">Import a YAML file</a>
                        to register a workflow.
                        <?php else: ?>
                        Ask a Workflow Manager or Administrator to import definitions.
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($defs as $def): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars((string)$def['title']) ?></div>
                                    <div class="small text-muted">
                                        <code><?= htmlspecialchars((string)$def['workflow_id']) ?></code>
                                        · v<?= (int)$def['version'] ?>
                                    </div>
                                </div>
                                <span class="badge text-bg-success">active</span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header py-2">
                    <h6 class="mb-0"><i class="bi bi-play-circle"></i> Instances</h6>
                </div>
                <div class="card-body p-0">
                    <?php if ($instances === []): ?>
                    <div class="p-3 text-muted small">
                        No instances yet. Runtime start UI arrives with the guided multipage runner.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Status</th>
                                    <th>Step</th>
                                    <th>Updated</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($instances as $row): ?>
                                <tr>
                                    <td>
                                        <div class="small fw-semibold"><?= htmlspecialchars((string)$row['title']) ?></div>
                                        <div class="text-muted" style="font-size:0.75rem">
                                            <?= htmlspecialchars((string)$row['workflow_id']) ?>
                                        </div>
                                    </td>
                                    <td><span class="badge text-bg-secondary"><?= htmlspecialchars((string)$row['status']) ?></span></td>
                                    <td class="small"><?= htmlspecialchars((string)$row['current_step']) ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars((string)($row['updated_at'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header py-2">
            <h6 class="mb-0"><i class="bi bi-shield-check"></i> Principles</h6>
        </div>
        <div class="card-body small text-muted">
            <ul class="mb-0">
                <li>Definitions are YAML files stored immutably under <code>storage/workflow-definitions/</code>.</li>
                <li>The engine is events-based (input, routing, validation) with sequential steps in v1.</li>
                <li>Workflow instances are disposable tools; the ledger holds the permanent record.</li>
                <li>Attachments for ledger entries: <code>storage/attachments/&lt;ledger sequence&gt;/</code>.</li>
            </ul>
        </div>
    </div>
</div>

<?php
/**
 * Admin: import / list / deactivate / delete workflow YAML definitions.
 * Restricted to Administrator, Workflow Manager, or workflow.manage permission.
 */
$temperPageKey = 'admin-workflows';
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/workflow_bootstrap.php';

$actor = workflowRequireManager($db);
$engine = workflowEngine($db);
$importer = $engine->getImporter();

$flash = null;
$flashType = 'info';

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'import') {
        if (empty($_FILES['yaml_file']['tmp_name']) || !is_uploaded_file($_FILES['yaml_file']['tmp_name'])) {
            $flash = 'Please choose a YAML file to upload.';
            $flashType = 'warning';
        } else {
            $tmp = (string)$_FILES['yaml_file']['tmp_name'];
            $name = (string)($_FILES['yaml_file']['name'] ?? 'definition.yaml');
            $activate = !empty($_POST['activate']);
            try {
                $engine->requireTables();
                $result = $importer->importFromFile($tmp, $actor, $activate);
                // Preserve original filename in audit via re-import message
                if ($result->success) {
                    $flashType = $result->warnings !== [] ? 'warning' : 'success';
                    $flash = $result->message;
                    if ($result->warnings !== []) {
                        $flash .= ' Warnings: ' . implode('; ', $result->warnings);
                    }
                } else {
                    $flashType = 'danger';
                    $flash = ($result->message ?? 'Import failed.') . ' '
                        . implode(' ', $result->errors);
                    if ($result->warnings !== []) {
                        $flash .= ' Warnings: ' . implode('; ', $result->warnings);
                    }
                }
            } catch (Throwable $e) {
                $flashType = 'danger';
                $flash = $e->getMessage();
            }
        }
    }

    if ($action === 'deactivate') {
        $wfId = trim((string)($_POST['workflow_id'] ?? ''));
        $ver = (int)($_POST['version'] ?? 0);
        $result = $importer->deactivate($wfId, $ver, $actor);
        $flashType = $result->success ? 'success' : 'danger';
        $flash = $result->message ?? implode(' ', $result->errors);
    }

    if ($action === 'delete') {
        $wfId = trim((string)($_POST['workflow_id'] ?? ''));
        $ver = (int)($_POST['version'] ?? 0);
        $result = $importer->delete($wfId, $ver, true, $actor);
        $flashType = $result->success ? 'success' : 'danger';
        $flash = $result->message ?? implode(' ', $result->errors);
    }
}

$definitions = [];
$dict = WorkflowDefinitionDictionary::summarize();
$schemaError = null;
try {
    $engine->requireTables();
    $definitions = $importer->listDefinitions(false);
} catch (Throwable $e) {
    $schemaError = $e->getMessage();
}

$storageDir = getWorkflowDefinitionsDir();
?>

<div class="container-fluid mt-2 px-0 px-sm-2">
    <div class="mb-3">
        <h2 class="mb-1 h3">Workflow Definitions</h2>
        <p class="text-muted small mb-0">
            Import externally authored YAML. Files are stored as-is under
            <code>storage/workflow-definitions/</code> with a checksum index in the database.
            No built-in editor — validate on import only.
        </p>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if ($schemaError): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($schemaError) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header py-2">
                    <h6 class="mb-0"><i class="bi bi-upload"></i> Import YAML</h6>
                </div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" action="javascript:void(0)"
                          onsubmit="return temperSubmitWorkflowImport(this)">
                        <input type="hidden" name="action" value="import">
                        <div class="mb-3">
                            <label class="form-label" for="yaml_file">Definition file</label>
                            <input type="file" class="form-control" name="yaml_file" id="yaml_file"
                                   accept=".yaml,.yml,text/yaml,application/x-yaml" required>
                            <div class="form-text">Example skeleton:
                                <code>storage/workflow-definitions/examples/minimal.example.yaml</code>
                            </div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="activate" value="1"
                                   id="activate" checked>
                            <label class="form-check-label" for="activate">
                                Activate this version (deactivates prior active version of same id)
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-check2-circle"></i> Validate &amp; import
                        </button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm mt-3">
                <div class="card-header py-2">
                    <h6 class="mb-0"><i class="bi bi-book"></i> Dictionary (v<?= (int)$dict['spec_version'] ?>)</h6>
                </div>
                <div class="card-body small">
                    <p class="mb-1"><strong>Required keys:</strong>
                        <?= htmlspecialchars(implode(', ', $dict['required_root_keys'])) ?></p>
                    <p class="mb-1"><strong>Actions:</strong>
                        <?= htmlspecialchars(implode(', ', $dict['action_types'])) ?></p>
                    <p class="mb-1"><strong>Field types:</strong>
                        <?= htmlspecialchars(implode(', ', $dict['field_types'])) ?></p>
                    <p class="mb-0 text-muted">
                        Storage:
                        <?php if (!empty($storageDir['error'])): ?>
                            <span class="text-danger"><?= htmlspecialchars((string)$storageDir['error']) ?></span>
                        <?php else: ?>
                            <code><?= htmlspecialchars((string)$storageDir['path']) ?></code>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-7">
            <div class="card shadow-sm">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-list-ul"></i> Indexed definitions</h6>
                    <span class="badge text-bg-secondary"><?= count($definitions) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if ($definitions === []): ?>
                    <div class="p-3 text-muted small">No definitions indexed yet.</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Workflow</th>
                                    <th>Ver</th>
                                    <th>Active</th>
                                    <th>Checksum</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($definitions as $row): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold small"><?= htmlspecialchars((string)$row['title']) ?></div>
                                        <code class="small"><?= htmlspecialchars((string)$row['workflow_id']) ?></code>
                                        <div class="text-muted" style="font-size:0.7rem">
                                            <?= htmlspecialchars((string)$row['file_path']) ?>
                                        </div>
                                    </td>
                                    <td><?= (int)$row['version'] ?></td>
                                    <td>
                                        <?php if (!empty($row['is_active'])): ?>
                                            <span class="badge text-bg-success">yes</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">no</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small font-monospace">
                                        <?= htmlspecialchars(substr((string)$row['checksum'], 0, 12)) ?>…
                                    </td>
                                    <td class="text-nowrap">
                                        <?php if (!empty($row['is_active'])): ?>
                                        <form method="post" class="d-inline"
                                              onsubmit="return temperWorkflowPost(this)">
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="workflow_id"
                                                   value="<?= htmlspecialchars((string)$row['workflow_id']) ?>">
                                            <input type="hidden" name="version" value="<?= (int)$row['version'] ?>">
                                            <button type="submit" class="btn btn-outline-secondary btn-sm">Deactivate</button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="post" class="d-inline"
                                              onsubmit="return confirm('Delete this definition index and file?') && temperWorkflowPost(this)">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="workflow_id"
                                                   value="<?= htmlspecialchars((string)$row['workflow_id']) ?>">
                                            <input type="hidden" name="version" value="<?= (int)$row['version'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                        </form>
                                    </td>
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
</div>

<script>
function temperWorkflowPost(form) {
    var page = 'admin-workflows';
    var url = 'pages/' + page + '.php';
    if (typeof submitFormAndReload === 'function') {
        submitFormAndReload(url, new FormData(form), url);
        return false;
    }
    form.setAttribute('action', url);
    form.setAttribute('method', 'post');
    return true;
}
function temperSubmitWorkflowImport(form) {
    return temperWorkflowPost(form);
}
</script>

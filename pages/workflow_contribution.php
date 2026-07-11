<?php
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/workflow_bootstrap.php';
    require_once __DIR__ . '/../includes/workflows/contribution_workflow.php';

    workflowRequireTables($db);
    $actor = workflowRequireActor($db, 'workflow.view');

    $actorJson = [
        'id' => (int)$actor['id'],
        'username' => $actor['username'],
        'display_name' => $actor['display_name'],
        'role_name' => $actor['role_name'],
        'can_create' => userHasWorkflowCapability($db, (int)$actor['id'], 'workflow.contribution.create'),
        'can_second' => userHasWorkflowCapability($db, (int)$actor['id'], 'workflow.contribution.second_sign'),
        'can_official' => userHasWorkflowCapability($db, (int)$actor['id'], 'workflow.contribution.official'),
    ];

    if (isset($_GET['api'])) {
        header('Content-Type: application/json');
        $api = $_GET['api'];

        if ($api === 'list') {
            $rows = workflowListInstances($db, 'contribution', 100);
            foreach ($rows as &$r) {
                $r['status_label'] = contribStatusLabel($r['status']);
            }
            echo json_encode(['items' => $rows, 'actor' => $actorJson]);
            exit;
        }

        if ($api === 'get' && isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            $instance = workflowFetchInstance($db, $id);
            if (!$instance || $instance['workflow_type'] !== 'contribution') {
                echo json_encode(['error' => 'Not found']);
                exit;
            }
            $instance = contribEnrichInstance($db, $instance);
            $instance['status_label'] = contribStatusLabel($instance['status']);
            echo json_encode(['instance' => $instance, 'actor' => $actorJson]);
            exit;
        }

        if ($api === 'eligible_users') {
            $cap = $_GET['capability'] ?? 'workflow.contribution.second_sign';
            echo json_encode(['users' => workflowEligibleUsers($db, $cap)]);
            exit;
        }

        if ($api === 'lookups') {
            $funds = [];
            $r = $db->query("SELECT id, name, code, type FROM funds WHERE is_active = TRUE AND archived = FALSE ORDER BY name");
            while ($row = $r->fetch_assoc()) {
                $funds[] = $row;
            }
            echo json_encode([
                'funds' => $funds,
                'denominations' => contribDenominationKeys(),
                'actor' => $actorJson,
            ]);
            exit;
        }

        echo json_encode(['error' => 'Unknown API']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json');
        $action = $_POST['action'];
        $act = ['id' => (int)$actor['id'], 'username' => $actor['username']];

        if ($action === 'create') {
            $payload = [
                'service_date' => $_POST['service_date'] ?? date('Y-m-d'),
                'description' => trim($_POST['description'] ?? 'Sunday Offering'),
                'cash_denominations' => json_decode($_POST['cash_denominations'] ?? '{}', true) ?: contribEmptyDenominations(),
                'checks' => json_decode($_POST['checks_json'] ?? '[]', true) ?: [],
                'fund_allocations' => json_decode($_POST['allocations_json'] ?? '[]', true) ?: [],
            ];
            echo json_encode(contribCreate($db, $payload, $act));
            exit;
        }

        if ($action === 'second_sign') {
            $id = (int)($_POST['workflow_id'] ?? 0);
            $signerId = (int)($_POST['signer_id'] ?? 0);
            $password = $_POST['password'] ?? '';
            $verify = json_decode($_POST['verify_denominations'] ?? '{}', true) ?: contribEmptyDenominations();
            echo json_encode(contribSecondTellerSign($db, $id, $signerId, $password, $verify, $act));
            exit;
        }

        if ($action === 'official_validate') {
            $id = (int)($_POST['workflow_id'] ?? 0);
            $officialId = (int)($_POST['official_id'] ?? (int)$actor['id']);
            $password = $_POST['password'] ?? '';
            $verified = [
                'denominations' => !empty($_POST['verify_denominations']),
                'checks' => !empty($_POST['verify_checks']),
                'funds' => !empty($_POST['verify_funds']),
            ];
            echo json_encode(contribOfficialValidate($db, $id, $officialId, $password, $verified, $act));
            exit;
        }

        if ($action === 'upload_document') {
            header('Content-Type: application/json; charset=utf-8');
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $id = (int)($_POST['workflow_id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Workflow ID is required.']);
                exit;
            }
            if (empty($_FILES['document']) || (int)($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                echo json_encode(['success' => false, 'error' => 'Please select a file to upload.']);
                exit;
            }
            $wf = workflowFetchInstance($db, $id);
            $txId = (int)($wf['transaction_detail_id'] ?? 0);
            if (!$wf || $txId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Ledger entry not found for this workflow.']);
                exit;
            }
            if (($wf['status'] ?? '') === 'deposited') {
                echo json_encode(['success' => false, 'error' => 'This contribution is deposited; documents are read-only on the ledger.']);
                exit;
            }
            require_once __DIR__ . '/../includes/ledger_engine.php';
            $stepKey = $wf['current_step'] ?? null;
            $result = ledgerStoreDocumentFromUpload(
                $db,
                $txId,
                (int)$actor['id'],
                $_FILES['document'],
                $stepKey
            );
            if (!empty($result['success'])) {
                try {
                    $stepId = workflowGetStepId($db, $id, $wf['current_step']);
                    $origName = basename((string)($_FILES['document']['name'] ?? 'file'));
                    ledgerLogEvent(
                        $db,
                        $txId,
                        'document_uploaded',
                        (int)$actor['id'],
                        $actor['username'],
                        'Attachment "' . $origName . '" added.',
                        ['doc_id' => $result['id'], 'workflow_instance_id' => $id, 'original_filename' => $origName]
                    );
                    workflowLogEvent(
                        $db,
                        $id,
                        $stepId,
                        'document_uploaded',
                        (int)$actor['id'],
                        $actor['username'],
                        'Attachment "' . $origName . '" added to ledger #' . $txId . '.',
                        ['doc_id' => $result['id'], 'transaction_detail_id' => $txId, 'original_filename' => $origName]
                    );
                } catch (Throwable $e) {
                    error_log('workflow upload audit failed: ' . $e->getMessage());
                }
                $result['success'] = true;
                $result['message'] = 'Upload Successful';
                $result['documents'] = ledgerFetchDocuments($db, $txId);
            } else {
                $result['success'] = false;
                if (empty($result['error'])) {
                    $result['error'] = 'Upload failed.';
                }
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'delete_document' || $action === 'delete_documents') {
            header('Content-Type: application/json; charset=utf-8');
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            require_once __DIR__ . '/../includes/ledger_engine.php';
            $id = (int)($_POST['workflow_id'] ?? 0);
            $docIds = [];
            if ($action === 'delete_documents') {
                $raw = json_decode($_POST['doc_ids'] ?? '[]', true);
                if (is_array($raw)) {
                    foreach ($raw as $v) {
                        $did = (int)$v;
                        if ($did > 0) {
                            $docIds[] = $did;
                        }
                    }
                }
            } else {
                $one = (int)($_POST['doc_id'] ?? 0);
                if ($one > 0) {
                    $docIds[] = $one;
                }
            }
            $docIds = array_values(array_unique($docIds));
            if ($id <= 0 || $docIds === []) {
                echo json_encode(['success' => false, 'error' => 'Workflow ID and document ID are required.']);
                exit;
            }
            $wf = workflowFetchInstance($db, $id);
            $txId = (int)($wf['transaction_detail_id'] ?? 0);
            if (!$wf || $txId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Ledger entry not found for this workflow.']);
                exit;
            }
            if (($wf['status'] ?? '') === 'deposited') {
                echo json_encode(['success' => false, 'error' => 'This contribution is deposited; documents are read-only.']);
                exit;
            }

            $deleted = [];
            $errors = [];
            foreach ($docIds as $docId) {
                $doc = ledgerFetchDocument($db, $docId);
                if (!$doc || (int)$doc['transaction_detail_id'] !== $txId) {
                    $errors[] = "Document #$docId not found for this workflow.";
                    continue;
                }
                $del = ledgerDeleteDocument($db, $docId);
                if (empty($del['success'])) {
                    $errors[] = $del['error'] ?? ("Failed to delete document #$docId.");
                    continue;
                }
                $deleted[] = [
                    'id' => $docId,
                    'original_filename' => $doc['original_filename'] ?? '',
                ];
                $delName = basename((string)($doc['original_filename'] ?? 'file'));
                try {
                    $stepId = workflowGetStepId($db, $id, $wf['current_step']);
                    ledgerLogEvent(
                        $db,
                        $txId,
                        'document_deleted',
                        (int)$actor['id'],
                        $actor['username'],
                        'Attachment "' . $delName . '" removed.',
                        ['doc_id' => $docId, 'workflow_instance_id' => $id, 'original_filename' => $delName]
                    );
                    workflowLogEvent(
                        $db,
                        $id,
                        $stepId,
                        'document_deleted',
                        (int)$actor['id'],
                        $actor['username'],
                        'Attachment "' . $delName . '" removed from ledger #' . $txId . '.',
                        ['doc_id' => $docId, 'transaction_detail_id' => $txId, 'original_filename' => $delName]
                    );
                } catch (Throwable $e) {
                    error_log('workflow delete audit failed: ' . $e->getMessage());
                }
            }

            $count = count($deleted);
            if ($count === 0) {
                echo json_encode([
                    'success' => false,
                    'error' => $errors[0] ?? 'Delete failed.',
                    'errors' => $errors,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $names = array_map(static fn($d) => $d['original_filename'] ?: ('#' . $d['id']), $deleted);
            $msg = $count === 1
                ? ('Deleted attachment: ' . $names[0])
                : ('Deleted ' . $count . ' attachments: ' . implode(', ', $names));

            echo json_encode([
                'success' => true,
                'message' => $msg,
                'deleted' => $deleted,
                'deleted_count' => $count,
                'errors' => $errors,
                'documents' => ledgerFetchDocuments($db, $txId),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }

    $denomKeys = contribDenominationKeys();
?>

<div class="container-fluid mt-2" id="contribApp">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div class="min-w-0 flex-grow-1">
            <h2 class="mb-1 h3">Contribution Workflow</h2>
            <p class="text-muted small mb-0">
                <a href="javascript:void(0)" onclick="loadPage('workflows')" class="text-decoration-none">&larr; All Workflows</a>
                <span class="d-none d-sm-inline">&nbsp;·&nbsp; Dual count → official validation → ledger deposit</span>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($actorJson['can_create']): ?>
            <button type="button" class="btn btn-success btn-sm" id="btnNewContrib"><i class="bi bi-plus-lg"></i> New Contribution</button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnRefreshList"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header py-2"><h6 class="mb-0">Contributions</h6></div>
                <div class="list-group list-group-flush" id="contribList" style="max-height: 70vh; overflow-y: auto;">
                    <div class="list-group-item text-muted small">Loading…</div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div id="contribDetail" class="card shadow-sm">
                <div class="card-body text-muted text-center py-5">
                    Select a contribution or create a new one.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Contribution Modal -->
<div class="modal fade" id="newContribModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">New Contribution — Teller Count</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="newContribForm">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Service Date</label>
                            <input type="date" class="form-control" name="service_date" id="ncServiceDate" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Description</label>
                            <input type="text" class="form-control" name="description" id="ncDescription" value="Sunday Offering" required>
                        </div>
                    </div>

                    <h6 class="border-bottom pb-1">Cash — Count by Denomination</h6>
                    <div class="row g-2 mb-3" id="ncDenoms"></div>
                    <div class="mb-3"><strong>Cash subtotal:</strong> <span id="ncCashTotal">$0.00</span></div>

                    <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-2">
                        <h6 class="mb-0">Checks</h6>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="ncAddCheck">Add Check</button>
                    </div>
                    <div id="ncChecks" class="mb-3"></div>
                    <div class="mb-3"><strong>Checks subtotal:</strong> <span id="ncCheckTotal">$0.00</span></div>

                    <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-2">
                        <h6 class="mb-0">Fund Allocations (WODR / WDR)</h6>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="ncAddAlloc">Add Fund Line</button>
                    </div>
                    <div id="ncAllocs" class="mb-3"></div>
                    <div class="alert alert-light border">
                        <strong>Grand total:</strong> <span id="ncGrandTotal">$0.00</span>
                        &nbsp;·&nbsp; <strong>Allocated:</strong> <span id="ncAllocTotal">$0.00</span>
                        <span id="ncAllocWarn" class="text-danger ms-2 d-none">Allocations must match grand total.</span>
                    </div>
                    <p class="small text-muted mb-0">
                        First teller: <strong><?= htmlspecialchars($actor['display_name']) ?></strong> (recorded automatically on save)
                    </p>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="ncSaveBtn">Save — Pending Second Count</button>
            </div>
        </div>
    </div>
</div>

<!-- Shared document preview modal -->
<div class="modal fade" id="wfDocPreviewModal" tabindex="-1" aria-labelledby="wfDocPreviewTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title text-truncate" id="wfDocPreviewTitle">Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="wfDocPreviewBody" style="min-height: 50vh;">
                <div class="text-center text-muted py-5">Loading…</div>
            </div>
            <div class="modal-footer py-2">
                <a href="#" id="wfDocPreviewDownload" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">Download</a>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Queue delete confirmation modal -->
<div class="modal fade" id="wfQueueDeleteModal" tabindex="-1" aria-labelledby="wfQueueDeleteTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="wfQueueDeleteTitle">Queue file for deletion?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">
                    This file will be queued for deletion. It will be removed only when you save the transaction.
                </p>
                <p class="small text-muted mb-0">File: <strong id="wfQueueDeleteFileName"></strong></p>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger" id="wfQueueDeleteConfirm">Queue Delete</button>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="contrib-config"><?= json_encode(['denominations' => $denomKeys, 'actor' => $actorJson]) ?></script>
<script type="text/plain" id="init-contrib-script">
(function() {
    const page = 'workflow_contribution';
    const CFG = JSON.parse(document.getElementById('contrib-config').textContent);
    const newModal = new bootstrap.Modal(document.getElementById('newContribModal'));
    let lookups = { funds: [] };
    let selectedId = null;
    /** @type {Object.<string,{id:number,name:string}>} */
    let pendingDocDeletes = {};
    let currentDetailDocs = [];

    function fmt(n) { return '$' + (parseFloat(n) || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function clearPendingDocDeletes() { pendingDocDeletes = {}; }
    function getPendingDocDeleteList() {
        return Object.keys(pendingDocDeletes).map(k => pendingDocDeletes[k]).filter(Boolean);
    }
    function isDocQueuedForDelete(docId) { return !!pendingDocDeletes[String(docId)]; }
    function queueDocForDelete(docId, name) {
        pendingDocDeletes[String(docId)] = { id: parseInt(docId, 10), name: name || ('#' + docId) };
    }
    function unqueueDocForDelete(docId) { delete pendingDocDeletes[String(docId)]; }
    function prunePendingDocDeletes(docs) {
        const alive = new Set((docs || []).map(d => String(d.id)));
        Object.keys(pendingDocDeletes).forEach(k => {
            if (!alive.has(k)) delete pendingDocDeletes[k];
        });
    }

    function parseJsonResponse(r) {
        return r.text().then(text => {
            const trimmed = (text || '').trim();
            if (!trimmed) throw new Error('Empty response');
            try {
                return JSON.parse(trimmed);
            } catch (e) {
                const start = trimmed.indexOf('{');
                const end = trimmed.lastIndexOf('}');
                if (start >= 0 && end > start) {
                    return JSON.parse(trimmed.slice(start, end + 1));
                }
                throw e;
            }
        });
    }

    function isApiSuccess(res) {
        if (!res || typeof res !== 'object') return false;
        if (res.error) return false;
        if (res.success === false || res.success === 0 || res.success === '0') return false;
        if (res.success === true || res.success === 1 || res.success === '1') return true;
        return !!(res.id && !res.error);
    }

    function fetchJson(url, opts) {
        return fetch(url, opts).then(parseJsonResponse);
    }

    function openWfDocumentPreview(docId, fallbackName) {
        const modalEl = document.getElementById('wfDocPreviewModal');
        const titleEl = document.getElementById('wfDocPreviewTitle');
        const bodyEl = document.getElementById('wfDocPreviewBody');
        const dlEl = document.getElementById('wfDocPreviewDownload');
        if (!modalEl || !bodyEl) return;
        titleEl.textContent = fallbackName || 'Document';
        bodyEl.innerHTML = '<div class="text-center text-muted py-5">Loading…</div>';
        dlEl.href = 'pages/ledger.php?download_document=' + docId;
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
        fetch('pages/ledger.php?document_meta=' + docId)
            .then(parseJsonResponse)
            .then(meta => {
                if (!isApiSuccess(meta)) {
                    bodyEl.innerHTML = '<div class="alert alert-danger m-3">' + esc(meta.error || 'Unable to load document.') + '</div>';
                    return;
                }
                titleEl.textContent = meta.original_filename || fallbackName || 'Document';
                dlEl.href = meta.download_url || ('pages/ledger.php?download_document=' + docId);
                const kind = meta.preview_kind || 'other';
                const url = meta.preview_url || ('pages/ledger.php?preview_document=' + docId);
                if (kind === 'image') {
                    bodyEl.innerHTML = `<div class="text-center p-2"><img src="${url}" alt="${esc(meta.original_filename || '')}" class="img-fluid" style="max-height:70vh"></div>`;
                } else if (kind === 'pdf') {
                    bodyEl.innerHTML = `<iframe src="${url}" title="PDF preview" class="w-100 border-0" style="height:70vh"></iframe>`;
                } else if (kind === 'text') {
                    fetch(url).then(r => r.text()).then(txt => {
                        bodyEl.innerHTML = `<pre class="small bg-light border rounded p-3 m-0" style="max-height:70vh;overflow:auto;white-space:pre-wrap">${esc(txt)}</pre>`;
                    }).catch(() => {
                        bodyEl.innerHTML = '<div class="alert alert-warning m-3">Could not load text preview. Use Download instead.</div>';
                    });
                } else {
                    bodyEl.innerHTML = `<div class="p-4 text-center">
                        <p class="mb-2">Preview is not available for this file type (<code>${esc(meta.mime_type || 'unknown')}</code>).</p>
                        <a class="btn btn-sm btn-primary" href="${dlEl.href}" target="_blank" rel="noopener">Download file</a>
                    </div>`;
                }
            })
            .catch(() => {
                bodyEl.innerHTML = '<div class="alert alert-danger m-3">Failed to load document preview.</div>';
            });
    }

    function renderDocsListHtml(docs, canDelete) {
        prunePendingDocDeletes(docs);
        if (!docs || !docs.length) {
            return '<li class="small text-muted">No documents attached (stored on ledger).</li>';
        }
        return docs.map(doc => {
            const name = esc(doc.original_filename || 'document');
            const when = esc(doc.created_at || '');
            const id = parseInt(doc.id, 10) || 0;
            const queued = isDocQueuedForDelete(id);
            const del = canDelete
                ? `<button type="button"
                        class="btn btn-sm ${queued ? 'btn-secondary' : 'btn-danger'} fw-bold px-2 py-0 wf-doc-delete"
                        style="font-size:1.15rem;line-height:1.2;min-width:2rem"
                        data-doc-id="${id}"
                        data-doc-name="${name}"
                        title="${queued ? 'Undo delete queue' : 'Queue for deletion'}"
                        aria-label="${queued ? 'Undo delete queue' : 'Queue for deletion'}">&times;</button>`
                : '';
            const nameClass = queued
                ? 'wf-doc-preview text-decoration-line-through text-muted'
                : 'wf-doc-preview text-decoration-none';
            const badge = queued
                ? '<span class="badge bg-warning text-dark">queued for deletion</span>'
                : '';
            return `<li class="small d-flex align-items-center flex-wrap gap-2 mb-1 ${queued ? 'opacity-75' : ''}">
                ${del}
                <a href="#" class="${nameClass}" data-doc-id="${id}" data-doc-name="${name}">${name}</a>
                <span class="text-muted">(${when})</span>
                ${badge}
            </li>`;
        }).join('');
    }

    function refreshWfDocumentsList(canDelete) {
        const list = document.getElementById('wfDocumentsList');
        if (list) list.innerHTML = renderDocsListHtml(currentDetailDocs, canDelete);
        const saveBtn = document.getElementById('wfDocSaveBtn');
        if (saveBtn) {
            const n = getPendingDocDeleteList().length;
            saveBtn.disabled = n === 0;
            saveBtn.textContent = n > 0
                ? ('Save document changes (' + n + ' queued)')
                : 'Save document changes';
        }
    }

    function confirmQueueDelete(fileName) {
        return new Promise(resolve => {
            const modalEl = document.getElementById('wfQueueDeleteModal');
            const nameEl = document.getElementById('wfQueueDeleteFileName');
            const confirmBtn = document.getElementById('wfQueueDeleteConfirm');
            if (!modalEl || !confirmBtn || typeof bootstrap === 'undefined') {
                resolve(window.confirm(
                    'This file will be queued for deletion. It will be removed only when you save the transaction.\n\nFile: '
                    + fileName
                ));
                return;
            }
            if (nameEl) nameEl.textContent = fileName || 'selected file';
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            let settled = false;
            const finish = (value) => {
                if (settled) return;
                settled = true;
                confirmBtn.removeEventListener('click', onConfirm);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                resolve(value);
            };
            const onConfirm = () => {
                finish(true);
                modal.hide();
            };
            const onHidden = () => finish(false);
            confirmBtn.addEventListener('click', onConfirm);
            modalEl.addEventListener('hidden.bs.modal', onHidden);
            modal.show();
        });
    }

    function humanizeWorkflowEvent(e) {
        const type = e.event_type || '';
        const details = e.details && typeof e.details === 'object' ? e.details : {};
        const summary = (e.summary || '').trim();
        if (type === 'document_uploaded') {
            const name = details.original_filename;
            if (name) return 'Attachment "' + name + '" added.';
        }
        if (type === 'document_deleted') {
            const name = details.original_filename;
            if (name) return 'Attachment "' + name + '" removed.';
        }
        return summary || type || 'Event recorded.';
    }

    function renderWorkflowAuditHtml(events) {
        const list = events || [];
        if (!list.length) {
            return '<li class="small text-muted">No workflow events.</li>';
        }
        return list.map(e => {
            const when = esc(e.created_at || '');
            const who = esc(e.username || 'system');
            const summary = esc(humanizeWorkflowEvent(e));
            return `<li class="small mb-2 border-bottom pb-1">
                <div><span class="text-muted">${when}</span> — <strong>${who}</strong></div>
                <div>${summary}</div>
            </li>`;
        }).join('');
    }

    function loadLookups() {
        return fetchJson(`pages/${page}.php?api=lookups`).then(d => { lookups = d; return d; });
    }

    function loadList() {
        return fetchJson(`pages/${page}.php?api=list`).then(d => {
            const el = document.getElementById('contribList');
            if (!d.items || !d.items.length) {
                el.innerHTML = '<div class="list-group-item text-muted small">No contributions yet.</div>';
                return;
            }
            el.innerHTML = d.items.map(it => {
                const active = it.id == selectedId ? ' active' : '';
                const badge = it.status === 'deposited' ? 'success' : (it.status.includes('pending') ? 'warning' : 'secondary');
                return `<button type="button" class="list-group-item list-group-item-action${active}" data-id="${it.id}">
                    <div class="d-flex justify-content-between"><strong class="small">${esc(it.title)}</strong>
                    <span class="badge bg-${badge}">${esc(it.status_label || it.status)}</span></div>
                    <div class="small text-muted">${it.updated_at || it.created_at}</div>
                </button>`;
            }).join('');
            el.querySelectorAll('[data-id]').forEach(btn => btn.addEventListener('click', () => loadDetail(btn.dataset.id)));
        });
    }

    function statusBadge(status) {
        const map = {
            'draft_pending_second_count': 'warning',
            'dual_count_complete_pending_official': 'info',
            'deposited': 'success'
        };
        return map[status] || 'secondary';
    }

    function renderDenomsReadonly(denoms) {
        return CFG.denominations.map(k => {
            const c = denoms[k] || 0;
            if (!c) return '';
            return `<span class="badge bg-light text-dark border me-1">$${k} × ${c}</span>`;
        }).filter(Boolean).join(' ') || '<span class="text-muted">None</span>';
    }

    function loadDetail(id) {
        // Clear delete queue when switching to a different workflow instance
        if (String(selectedId) !== String(id)) {
            clearPendingDocDeletes();
        }
        selectedId = id;
        loadList();
        fetchJson(`pages/${page}.php?api=get&id=${id}`).then(d => {
            if (d.error) { showToast(d.error, 'danger'); return; }
            const inst = d.instance;
            const p = inst.payload || {};
            const el = document.getElementById('contribDetail');
            let actionHtml = '';

            if (inst.status === 'draft_pending_second_count' && CFG.actor.can_second) {
                actionHtml = renderSecondSignForm(inst);
            } else if (inst.status === 'dual_count_complete_pending_official' && CFG.actor.can_official) {
                actionHtml = renderOfficialForm(inst);
            } else if (inst.status === 'deposited') {
                clearPendingDocDeletes();
                const txId = inst.transaction_detail_id || p.deposit_transaction_id || '—';
                actionHtml = `<div class="alert alert-success py-2 small">
                    Deposited. <a href="javascript:void(0)" onclick="loadPage('ledger')">Ledger transaction #${txId}</a> (read-only — view documents and audit trail there).
                </div>`;
            }

            const eventCount = (inst.events || []).length;
            const eventsHtml = renderWorkflowAuditHtml(inst.events || []);

            const txId = inst.transaction_detail_id || '';
            const canEditDocs = inst.status !== 'deposited' && !!txId;
            currentDetailDocs = inst.documents || [];
            const docsHtml = renderDocsListHtml(currentDetailDocs, canEditDocs);
            const pendingN = getPendingDocDeleteList().length;

            el.innerHTML = `
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <h6 class="mb-0">${esc(inst.title)}</h6>
                    <span class="badge bg-${statusBadge(inst.status)}">${esc(inst.status_label)}</span>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><div class="small text-muted">Service Date</div><div>${esc(p.service_date || '')}</div></div>
                        <div class="col-md-4"><div class="small text-muted">Grand Total</div><div class="fw-bold">${fmt(p.totals?.grand)}</div></div>
                        <div class="col-md-4"><div class="small text-muted">Cash / Checks</div><div>${fmt(p.totals?.cash)} / ${fmt(p.totals?.checks)}</div></div>
                    </div>
                    <h6 class="small text-muted">Cash Denominations (1st count)</h6>
                    <p>${renderDenomsReadonly(p.cash_denominations || {})}</p>
                    <h6 class="small text-muted">Checks</h6>
                    ${renderChecksTable(p.checks || [])}
                    <h6 class="small text-muted mt-3">Fund Allocations</h6>
                    ${renderAllocTable(p.fund_allocations || [])}
                    ${actionHtml}
                    <hr>
                    <h6 class="small">Documents</h6>
                    <ul class="list-unstyled mb-2" id="wfDocumentsList">${docsHtml}</ul>
                    ${canEditDocs ? `<div id="docUploadForm" class="d-flex flex-wrap gap-2 align-items-center mb-2">
                        <input type="file" id="docUploadFile" class="form-control form-control-sm" style="max-width:16rem" name="document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        <button type="button" id="docUploadBtn" class="btn btn-outline-secondary btn-sm" disabled>Upload</button>
                        <button type="button" id="wfDocSaveBtn" class="btn btn-primary btn-sm" ${pendingN ? '' : 'disabled'}>
                            ${pendingN > 0 ? ('Save document changes (' + pendingN + ' queued)') : 'Save document changes'}
                        </button>
                    </div>
                    <p class="small text-muted mb-0">Use the red <strong>&times;</strong> to queue files for deletion. They are removed only when you click <em>Save document changes</em>.</p>`
                    : (inst.status !== 'deposited' && !txId ? '<p class="small text-muted">Documents can be attached after the ledger draft is created.</p>' : '')}
                    <div class="accordion accordion-flush border rounded mt-3" id="wfAuditAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed py-2 px-3 small fw-semibold" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#wfAuditCollapse"
                                        aria-expanded="false" aria-controls="wfAuditCollapse">
                                    Workflow Audit Trail${eventCount ? (' (' + eventCount + ' event' + (eventCount === 1 ? '' : 's') + ')') : ''}
                                </button>
                            </h2>
                            <div id="wfAuditCollapse" class="accordion-collapse collapse" data-bs-parent="#wfAuditAccordion">
                                <div class="accordion-body p-2">
                                    <ul class="list-unstyled mb-0">${eventsHtml}</ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    ${txId ? `<p class="small text-muted mb-0 mt-2">Full transaction data, documents, and ledger audit trail: <a href="javascript:void(0)" onclick="loadPage('ledger')">Ledger #${txId}</a></p>` : ''}
                </div>`;

            const docsList = document.getElementById('wfDocumentsList');
            if (docsList) {
                docsList.addEventListener('click', e => {
                    const prev = e.target.closest('.wf-doc-preview');
                    if (prev) {
                        e.preventDefault();
                        const docId = parseInt(prev.dataset.docId, 10);
                        if (docId) openWfDocumentPreview(docId, prev.dataset.docName || prev.textContent);
                        return;
                    }
                    const del = e.target.closest('.wf-doc-delete');
                    if (del) {
                        e.preventDefault();
                        if (!canEditDocs) return;
                        const docId = parseInt(del.dataset.docId, 10);
                        const name = del.dataset.docName || 'this file';
                        if (!docId) return;
                        if (isDocQueuedForDelete(docId)) {
                            unqueueDocForDelete(docId);
                            showToast('Removed “' + name + '” from deletion queue.', 'info');
                            refreshWfDocumentsList(true);
                            return;
                        }
                        confirmQueueDelete(name).then(ok => {
                            if (!ok) return; // Cancel — leave queue and form untouched
                            queueDocForDelete(docId, name);
                            showToast('Queued “' + name + '” for deletion on save.', 'warning');
                            refreshWfDocumentsList(true);
                        });
                    }
                });
            }

            const docFile = document.getElementById('docUploadFile');
            const docBtn = document.getElementById('docUploadBtn');
            if (docFile && docBtn) {
                docFile.addEventListener('change', () => {
                    docBtn.disabled = !(docFile.files && docFile.files.length > 0);
                });
                docBtn.addEventListener('click', () => {
                    if (!docFile.files || docFile.files.length === 0) {
                        showToast('Please select a file to upload.', 'warning');
                        return;
                    }
                    const fd = new FormData();
                    fd.append('action', 'upload_document');
                    fd.append('workflow_id', id);
                    fd.append('document', docFile.files[0]);
                    docBtn.disabled = true;
                    fetch(`pages/${page}.php`, { method: 'POST', body: fd })
                        .then(parseJsonResponse)
                        .then(res => {
                            if (!isApiSuccess(res)) {
                                showToast(res.error || 'Upload failed.', 'danger');
                                docBtn.disabled = !(docFile.files && docFile.files.length > 0);
                                return;
                            }
                            showToast(res.message || 'Upload Successful', 'success');
                            // Refresh detail but keep deletion queue for other files
                            loadDetail(id);
                        })
                        .catch(() => {
                            showToast('Upload failed.', 'danger');
                            docBtn.disabled = !(docFile.files && docFile.files.length > 0);
                        });
                });
            }

            const wfSaveBtn = document.getElementById('wfDocSaveBtn');
            if (wfSaveBtn) {
                wfSaveBtn.addEventListener('click', () => {
                    const pending = getPendingDocDeleteList();
                    if (!pending.length) {
                        showToast('No document changes to save.', 'info');
                        return;
                    }
                    const lines = pending.map(p => '• ' + p.name).join('\n');
                    const msg = 'The following file(s) are queued for deletion and will be permanently removed:\n\n'
                        + lines
                        + '\n\nDelete these file(s) and save document changes?';
                    if (!confirm(msg)) {
                        showToast('Save cancelled. Queued files were not deleted.', 'info');
                        return;
                    }
                    wfSaveBtn.disabled = true;
                    const fd = new FormData();
                    fd.append('action', 'delete_documents');
                    fd.append('workflow_id', id);
                    fd.append('doc_ids', JSON.stringify(pending.map(p => p.id)));
                    fetch(`pages/${page}.php`, { method: 'POST', body: fd })
                        .then(parseJsonResponse)
                        .then(res => {
                            if (!isApiSuccess(res)) {
                                showToast(res.error || 'Delete failed.', 'danger');
                                wfSaveBtn.disabled = false;
                                return;
                            }
                            clearPendingDocDeletes();
                            const delMsg = res.message || ((res.deleted_count || pending.length) + ' attachment(s) deleted.');
                            showToast('Document changes saved. ' + delMsg, 'success', 6000);
                            loadDetail(id);
                        })
                        .catch(() => {
                            showToast('Failed to save document changes.', 'danger');
                            wfSaveBtn.disabled = false;
                        });
                });
            }
            bindDetailActions(inst);
        });
    }

    function renderChecksTable(checks) {
        if (!checks.length) return '<p class="text-muted small">No checks.</p>';
        let rows = checks.map(c => `<tr><td>${esc(c.payor||'')}</td><td>${esc(c.check_number||'')}</td><td>${esc(c.check_date||'')}</td><td class="text-end">${fmt(c.amount)}</td><td class="small">${esc(c.notes||'')}</td></tr>`).join('');
        return `<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Payor</th><th>Check #</th><th>Date</th><th class="text-end">Amount</th><th>Notes</th></tr></thead><tbody>${rows}</tbody></table></div>`;
    }

    function renderAllocTable(allocs) {
        if (!allocs.length) return '<p class="text-muted small">No allocations.</p>';
        const fundName = fid => (lookups.funds.find(f => f.id == fid) || {}).name || 'Fund #' + fid;
        let rows = allocs.map(a => `<tr><td>${esc(fundName(a.fund_id))}</td><td class="text-end">${fmt(a.amount)}</td></tr>`).join('');
        return `<table class="table table-sm table-striped w-auto"><thead><tr><th>Fund</th><th class="text-end">Amount</th></tr></thead><tbody>${rows}</tbody></table>`;
    }

    function renderSecondSignForm(inst) {
        const denoms = CFG.denominations.map(k =>
            `<div class="col-6 col-md-4 col-lg-3"><label class="form-label small">$${k}</label>
            <input type="number" min="0" class="form-control form-control-sm second-denom" data-denom="${k}" value="0"></div>`
        ).join('');
        return `<div class="card border-warning mt-3"><div class="card-header py-2 bg-warning bg-opacity-10"><strong>Second Teller Verification</strong></div>
            <div class="card-body">
                <p class="small text-muted">Re-enter denomination counts to verify the first teller count.</p>
                <div class="row g-2 mb-3">${denoms}</div>
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-5"><label class="form-label small">Second Teller</label><select class="form-select form-select-sm" id="secondSigner"></select></div>
                    <div class="col-12 col-md-4"><label class="form-label small">Password</label><input type="password" class="form-control form-control-sm" id="secondPassword" autocomplete="current-password"></div>
                    <div class="col-12 col-md-3"><button type="button" class="btn btn-warning btn-sm w-100" id="btnSecondSign">Sign &amp; Verify</button></div>
                </div>
            </div></div>`;
    }

    function renderOfficialForm(inst) {
        return `<div class="card border-primary mt-3"><div class="card-header py-2 bg-primary bg-opacity-10"><strong>Official Validation &amp; Deposit</strong></div>
            <div class="card-body">
                <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="offDenoms"><label class="form-check-label small" for="offDenoms">Denominations verified</label></div>
                <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="offChecks"><label class="form-check-label small" for="offChecks">Checks verified</label></div>
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="offFunds"><label class="form-check-label small" for="offFunds">Fund allocations verified</label></div>
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-4"><label class="form-label small">Password</label><input type="password" class="form-control form-control-sm" id="officialPassword" autocomplete="current-password"></div>
                    <div class="col-12 col-md-4"><button type="button" class="btn btn-primary btn-sm w-100" id="btnOfficial">Sign &amp; Create Deposit</button></div>
                </div>
            </div></div>`;
    }

    function bindDetailActions(inst) {
        if (inst.status === 'draft_pending_second_count') {
            fetchJson(`pages/${page}.php?api=eligible_users&capability=workflow.contribution.second_sign`).then(d => {
                const sel = document.getElementById('secondSigner');
                if (!sel) return;
                sel.innerHTML = (d.users || []).map(u =>
                    `<option value="${u.id}">${esc(u.display_name)} (${esc(u.role_name)})</option>`
                ).join('');
            });
            document.getElementById('btnSecondSign')?.addEventListener('click', () => {
                const denoms = {};
                document.querySelectorAll('.second-denom').forEach(inp => { denoms[inp.dataset.denom] = parseInt(inp.value, 10) || 0; });
                const fd = new FormData();
                fd.append('action', 'second_sign');
                fd.append('workflow_id', inst.id);
                fd.append('signer_id', document.getElementById('secondSigner').value);
                fd.append('password', document.getElementById('secondPassword').value);
                fd.append('verify_denominations', JSON.stringify(denoms));
                fetch(`pages/${page}.php`, { method: 'POST', body: fd }).then(r => r.json()).then(res => {
                    if (res.error) showToast(res.error, 'danger');
                    else { showToast('Dual count complete.', 'success'); loadList(); loadDetail(inst.id); }
                });
            });
        }
        document.getElementById('btnOfficial')?.addEventListener('click', () => {
            const fd = new FormData();
            fd.append('action', 'official_validate');
            fd.append('workflow_id', inst.id);
            fd.append('official_id', CFG.actor.id);
            fd.append('password', document.getElementById('officialPassword').value);
            if (document.getElementById('offDenoms').checked) fd.append('verify_denominations', '1');
            if (document.getElementById('offChecks').checked) fd.append('verify_checks', '1');
            if (document.getElementById('offFunds').checked) fd.append('verify_funds', '1');
            fetch(`pages/${page}.php`, { method: 'POST', body: fd }).then(r => r.json()).then(res => {
                if (res.error) showToast(res.error, 'danger');
                else { showToast('Deposit created. Transaction #' + res.transaction_id, 'success'); loadList(); loadDetail(inst.id); }
            });
        });
    }

    function buildDenomInputs(containerId, prefix) {
        const el = document.getElementById(containerId);
        el.innerHTML = CFG.denominations.map(k =>
            `<div class="col-6 col-md-4 col-lg-2"><label class="form-label small">$${k}</label>
            <input type="number" min="0" class="form-control form-control-sm ${prefix}-denom" data-denom="${k}" value="0"></div>`
        ).join('');
    }

    function readDenoms(prefix) {
        const d = {};
        document.querySelectorAll('.' + prefix + '-denom').forEach(inp => { d[inp.dataset.denom] = parseInt(inp.value, 10) || 0; });
        return d;
    }

    function sumDenoms(d) {
        let t = 0;
        CFG.denominations.forEach(k => { t += (parseInt(d[k], 10) || 0) * parseFloat(k); });
        return Math.round(t * 100) / 100;
    }

    function updateNewTotals() {
        const cash = sumDenoms(readDenoms('nc'));
        let checkT = 0;
        document.querySelectorAll('.nc-check-amt').forEach(inp => { checkT += parseFloat(inp.value) || 0; });
        let allocT = 0;
        document.querySelectorAll('.nc-alloc-amt').forEach(inp => { allocT += parseFloat(inp.value) || 0; });
        const grand = Math.round((cash + checkT) * 100) / 100;
        allocT = Math.round(allocT * 100) / 100;
        document.getElementById('ncCashTotal').textContent = fmt(cash);
        document.getElementById('ncCheckTotal').textContent = fmt(checkT);
        document.getElementById('ncGrandTotal').textContent = fmt(grand);
        document.getElementById('ncAllocTotal').textContent = fmt(allocT);
        document.getElementById('ncAllocWarn').classList.toggle('d-none', Math.abs(grand - allocT) < 0.005);
    }

    function addCheckRow() {
        const div = document.createElement('div');
        div.className = 'row g-2 mb-2 nc-check-row';
        div.innerHTML = `
            <div class="col-12 col-sm-6 col-md-3"><input class="form-control form-control-sm" placeholder="Payor" data-field="payor"></div>
            <div class="col-6 col-sm-3 col-md-2"><input class="form-control form-control-sm" placeholder="Check #" data-field="check_number"></div>
            <div class="col-6 col-sm-3 col-md-2"><input type="date" class="form-control form-control-sm" data-field="check_date"></div>
            <div class="col-6 col-sm-4 col-md-2"><input type="number" step="0.01" min="0" class="form-control form-control-sm nc-check-amt" placeholder="Amount"></div>
            <div class="col-6 col-sm-6 col-md-2"><input class="form-control form-control-sm" placeholder="Notes" data-field="notes"></div>
            <div class="col-12 col-sm-2 col-md-1"><button type="button" class="btn btn-outline-danger btn-sm w-100 rm-check">&times;</button></div>`;
        div.querySelector('.rm-check').addEventListener('click', () => { div.remove(); updateNewTotals(); });
        div.querySelector('.nc-check-amt').addEventListener('input', updateNewTotals);
        document.getElementById('ncChecks').appendChild(div);
    }

    function addAllocRow() {
        const opts = lookups.funds.map(f => `<option value="${f.id}">${esc(f.name)} (${f.type})</option>`).join('');
        const div = document.createElement('div');
        div.className = 'row g-2 mb-2 nc-alloc-row';
        div.innerHTML = `
            <div class="col-12 col-md-7"><select class="form-select form-select-sm nc-alloc-fund">${opts}</select></div>
            <div class="col-9 col-md-4"><input type="number" step="0.01" min="0" class="form-control form-control-sm nc-alloc-amt" placeholder="Amount"></div>
            <div class="col-3 col-md-1"><button type="button" class="btn btn-outline-danger btn-sm w-100 rm-alloc">&times;</button></div>`;
        div.querySelector('.rm-alloc').addEventListener('click', () => { div.remove(); updateNewTotals(); });
        div.querySelector('.nc-alloc-amt').addEventListener('input', updateNewTotals);
        document.getElementById('ncAllocs').appendChild(div);
    }

    function collectChecks() {
        return [...document.querySelectorAll('.nc-check-row')].map(row => ({
            payor: row.querySelector('[data-field=payor]')?.value || '',
            check_number: row.querySelector('[data-field=check_number]')?.value || '',
            check_date: row.querySelector('[data-field=check_date]')?.value || '',
            amount: parseFloat(row.querySelector('.nc-check-amt')?.value) || 0,
            notes: row.querySelector('[data-field=notes]')?.value || '',
        })).filter(c => c.amount > 0 || c.payor);
    }

    function collectAllocs() {
        return [...document.querySelectorAll('.nc-alloc-row')].map(row => ({
            fund_id: parseInt(row.querySelector('.nc-alloc-fund')?.value, 10) || 0,
            amount: parseFloat(row.querySelector('.nc-alloc-amt')?.value) || 0,
        })).filter(a => a.fund_id > 0 && a.amount > 0);
    }

    document.getElementById('btnNewContrib')?.addEventListener('click', () => {
        document.getElementById('ncServiceDate').value = new Date().toISOString().slice(0, 10);
        buildDenomInputs('ncDenoms', 'nc');
        document.querySelectorAll('.nc-denom').forEach(inp => inp.addEventListener('input', updateNewTotals));
        document.getElementById('ncChecks').innerHTML = '';
        document.getElementById('ncAllocs').innerHTML = '';
        addAllocRow();
        updateNewTotals();
        newModal.show();
    });

    document.getElementById('ncAddCheck')?.addEventListener('click', addCheckRow);
    document.getElementById('ncAddAlloc')?.addEventListener('click', addAllocRow);
    document.getElementById('ncSaveBtn')?.addEventListener('click', () => {
        const fd = new FormData();
        fd.append('action', 'create');
        fd.append('service_date', document.getElementById('ncServiceDate').value);
        fd.append('description', document.getElementById('ncDescription').value);
        fd.append('cash_denominations', JSON.stringify(readDenoms('nc')));
        fd.append('checks_json', JSON.stringify(collectChecks()));
        fd.append('allocations_json', JSON.stringify(collectAllocs()));
        fetch(`pages/${page}.php`, { method: 'POST', body: fd }).then(r => r.json()).then(res => {
            if (res.error) showToast(res.error, 'danger');
            else {
                newModal.hide();
                showToast('Contribution saved — pending second count.', 'success');
                selectedId = res.id;
                loadList().then(() => loadDetail(res.id));
            }
        });
    });

    document.getElementById('btnRefreshList')?.addEventListener('click', () => loadList());

    loadLookups().then(loadList);
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-contrib-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>
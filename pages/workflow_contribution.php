<?php
    if (!isset($db)) {
        require_once __DIR__ . '/../config.php';
        $db = getDbConnection();
    }
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
            $id = (int)($_POST['workflow_id'] ?? 0);
            if ($id <= 0 || empty($_FILES['document']['tmp_name'])) {
                echo json_encode(['error' => 'Workflow ID and document are required.']);
                exit;
            }
            $wf = workflowFetchInstance($db, $id);
            $txId = (int)($wf['transaction_detail_id'] ?? 0);
            if (!$wf || $txId <= 0) {
                echo json_encode(['error' => 'Ledger entry not found for this workflow.']);
                exit;
            }
            require_once __DIR__ . '/../includes/ledger_engine.php';
            $stepKey = $wf['current_step'] ?? null;
            $result = ledgerStoreDocument(
                $db,
                $txId,
                (int)$actor['id'],
                $_FILES['document']['name'],
                $_FILES['document']['tmp_name'],
                $_FILES['document']['type'] ?? 'application/octet-stream',
                $stepKey
            );
            if (!empty($result['success'])) {
                $stepId = workflowGetStepId($db, $id, $wf['current_step']);
                ledgerLogEvent(
                    $db,
                    $txId,
                    'document_uploaded',
                    (int)$actor['id'],
                    $actor['username'],
                    'Document uploaded: ' . $_FILES['document']['name'],
                    ['doc_id' => $result['id'], 'workflow_instance_id' => $id]
                );
                workflowLogEvent(
                    $db,
                    $id,
                    $stepId,
                    'document_uploaded',
                    (int)$actor['id'],
                    $actor['username'],
                    'Document attached to ledger #' . $txId . ': ' . $_FILES['document']['name'],
                    ['doc_id' => $result['id'], 'transaction_detail_id' => $txId]
                );
            }
            echo json_encode($result);
            exit;
        }

        echo json_encode(['error' => 'Unknown action']);
        exit;
    }

    $denomKeys = contribDenominationKeys();
?>

<div class="container-fluid mt-2" id="contribApp">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h2 class="mb-1">Contribution Workflow</h2>
            <p class="text-muted small mb-0">
                <a href="javascript:void(0)" onclick="loadPage('workflows')" class="text-decoration-none">&larr; All Workflows</a>
                &nbsp;·&nbsp; Dual count → official validation → ledger deposit
            </p>
        </div>
        <div class="d-flex gap-2">
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

<script type="application/json" id="contrib-config"><?= json_encode(['denominations' => $denomKeys, 'actor' => $actorJson]) ?></script>
<script type="text/plain" id="init-contrib-script">
(function() {
    const page = 'workflow_contribution';
    const CFG = JSON.parse(document.getElementById('contrib-config').textContent);
    const newModal = new bootstrap.Modal(document.getElementById('newContribModal'));
    let lookups = { funds: [] };
    let selectedId = null;

    function fmt(n) { return '$' + (parseFloat(n) || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function fetchJson(url, opts) {
        return fetch(url, opts).then(r => r.json());
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
                const txId = inst.transaction_detail_id || p.deposit_transaction_id || '—';
                actionHtml = `<div class="alert alert-success py-2 small">
                    Deposited. <a href="javascript:void(0)" onclick="loadPage('ledger')">Ledger transaction #${txId}</a> (read-only — view documents and audit trail there).
                </div>`;
            }

            const events = (inst.events || []).map(e =>
                `<li class="small"><span class="text-muted">${e.created_at}</span> — <strong>${esc(e.username)}</strong>: ${esc(e.summary)}</li>`
            ).join('');

            const txId = inst.transaction_detail_id || '';
            const docs = (inst.documents || []).map(doc =>
                `<li class="small"><a href="pages/ledger.php?download_document=${doc.id}" target="_blank">${esc(doc.original_filename)}</a> <span class="text-muted">(${doc.created_at})</span></li>`
            ).join('') || '<li class="small text-muted">No documents attached (stored on ledger).</li>';

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
                    <ul class="mb-2">${docs}</ul>
                    ${inst.status !== 'deposited' ? `<form id="docUploadForm" class="d-flex gap-2 align-items-center">
                        <input type="file" class="form-control form-control-sm" name="document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Upload</button>
                    </form>` : ''}
                    <h6 class="small mt-3">Workflow Audit Trail</h6>
                    <ul class="mb-2">${events || '<li class="small text-muted">No workflow events.</li>'}</ul>
                    ${txId ? `<p class="small text-muted mb-0">Full transaction data, documents, and ledger audit trail: <a href="javascript:void(0)" onclick="loadPage('ledger')">Ledger #${txId}</a></p>` : ''}
                </div>`;

            const docForm = document.getElementById('docUploadForm');
            if (docForm) {
                docForm.addEventListener('submit', e => {
                    e.preventDefault();
                    const fd = new FormData(docForm);
                    fd.append('action', 'upload_document');
                    fd.append('workflow_id', id);
                    fetch(`pages/${page}.php`, { method: 'POST', body: fd }).then(r => r.json()).then(res => {
                        if (res.error) showToast(res.error, 'danger');
                        else { showToast('Document uploaded.', 'success'); loadDetail(id); }
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
                    <div class="col-md-5"><label class="form-label small">Second Teller</label><select class="form-select form-select-sm" id="secondSigner"></select></div>
                    <div class="col-md-4"><label class="form-label small">Password</label><input type="password" class="form-control form-control-sm" id="secondPassword"></div>
                    <div class="col-md-3"><button type="button" class="btn btn-warning btn-sm w-100" id="btnSecondSign">Sign &amp; Verify</button></div>
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
                    <div class="col-md-4"><label class="form-label small">Password</label><input type="password" class="form-control form-control-sm" id="officialPassword"></div>
                    <div class="col-md-4"><button type="button" class="btn btn-primary btn-sm" id="btnOfficial">Sign &amp; Create Deposit</button></div>
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
            <div class="col-md-3"><input class="form-control form-control-sm" placeholder="Payor" data-field="payor"></div>
            <div class="col-md-2"><input class="form-control form-control-sm" placeholder="Check #" data-field="check_number"></div>
            <div class="col-md-2"><input type="date" class="form-control form-control-sm" data-field="check_date"></div>
            <div class="col-md-2"><input type="number" step="0.01" min="0" class="form-control form-control-sm nc-check-amt" placeholder="Amount"></div>
            <div class="col-md-2"><input class="form-control form-control-sm" placeholder="Notes" data-field="notes"></div>
            <div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm rm-check">&times;</button></div>`;
        div.querySelector('.rm-check').addEventListener('click', () => { div.remove(); updateNewTotals(); });
        div.querySelector('.nc-check-amt').addEventListener('input', updateNewTotals);
        document.getElementById('ncChecks').appendChild(div);
    }

    function addAllocRow() {
        const opts = lookups.funds.map(f => `<option value="${f.id}">${esc(f.name)} (${f.type})</option>`).join('');
        const div = document.createElement('div');
        div.className = 'row g-2 mb-2 nc-alloc-row';
        div.innerHTML = `
            <div class="col-md-7"><select class="form-select form-select-sm nc-alloc-fund">${opts}</select></div>
            <div class="col-md-4"><input type="number" step="0.01" min="0" class="form-control form-control-sm nc-alloc-amt" placeholder="Amount"></div>
            <div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm rm-alloc">&times;</button></div>`;
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
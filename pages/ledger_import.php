<?php
/**
 * TEMPORARY Beancount Mass Import page (SPA fragment).
 * Delete with includes/beancount_mass_import.php and the Ledger → Import nav item
 * after historical data has been loaded.
 */
$temperSkipPagePermission = true;
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/ledger_engine.php';
require_once __DIR__ . '/../includes/budget_utils.php';
require_once __DIR__ . '/../includes/beancount_mass_import.php';

$acl = beancountMassImportRequireAccess($db);

function bmiJson(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function bmiReadRequest(): array
{
    $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (str_contains($ct, 'application/json')) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $req = bmiReadRequest();
    $action = (string)($req['action'] ?? '');
    $lookups = beancountMassImportLoadLookups($db);

    if ($action === 'parse') {
        $text = (string)($req['text'] ?? '');
        $parsed = beancountMassImportParseBlock($db, $text);
        if (empty($parsed['ok'])) {
            bmiJson([
                'success' => false,
                'error' => $parsed['errors'][0] ?? 'Parse failed.',
                'errors' => $parsed['errors'] ?? [],
            ]);
        }
        $classified = beancountMassImportClassifyDuplicates($db, $parsed['items'], $parsed['lookups']);
        foreach ($classified['items'] as &$it) {
            $it['beancount_text'] = beancountMassImportFormatBeancount($it, $parsed['lookups']);
            $it['debit_total'] = beancountMassImportItemDebitTotal($it);
            $it['credit_total'] = beancountMassImportItemCreditTotal($it);
            $it['blocking'] = beancountMassImportItemBlockingErrors($it);
        }
        unset($it);
        $ledgerN = 0;
        $queueN = 0;
        foreach ($classified['items'] as $it) {
            if (($it['duplicate_kind'] ?? null) === 'ledger') {
                $ledgerN++;
            } elseif (($it['duplicate_kind'] ?? null) === 'queue') {
                $queueN++;
            }
        }
        bmiJson([
            'success' => true,
            'items' => $classified['items'],
            'lookups' => $parsed['lookups'],
            'duplicate_count' => $ledgerN + $queueN,
            'ledger_duplicate_count' => $ledgerN,
            'batch_duplicate_count' => $queueN,
        ]);
    }

    if ($action === 'detect') {
        $items = $req['items'] ?? [];
        if (!is_array($items)) {
            bmiJson(['success' => false, 'error' => 'Nothing to check.']);
        }
        $classified = beancountMassImportClassifyDuplicates($db, $items, $lookups);
        $out = [];
        foreach ($classified['items'] as $it) {
            $out[] = [
                'queue_id' => (string)($it['queue_id'] ?? ''),
                'duplicate_preview' => !empty($it['duplicate_preview']),
                'duplicate_kind' => $it['duplicate_kind'] ?? null,
                'duplicate_reasons' => $it['duplicate_reasons'] ?? [],
                'duplicate_score' => $it['duplicate_score'] ?? 0,
                'duplicate_primary' => !empty($it['duplicate_primary']),
                'duplicate_match_label' => (string)($it['duplicate_match_label'] ?? ''),
                'duplicate_match_queue_id' => $it['duplicate_match_queue_id'] ?? null,
            ];
        }
        bmiJson(['success' => true, 'items' => $out]);
    }

    if ($action === 'import_batch') {
        $items = $req['items'] ?? [];
        if (!is_array($items) || $items === []) {
            bmiJson(['success' => false, 'error' => 'Nothing to import.']);
        }
        $result = beancountMassImportWriteBatch($db, $items, $acl, $lookups);
        if (empty($result['ok'])) {
            bmiJson([
                'success' => false,
                'error' => $result['error'] ?? 'Batch import failed.',
                'rejected' => $result['rejected'] ?? [],
            ]);
        }
        bmiJson([
            'success' => true,
            'imported' => $result['imported'],
            'imported_count' => count($result['imported']),
            'duplicate_sets' => $result['duplicate_sets'],
            'rejected' => $result['rejected'],
            'skipped_same_batch' => $result['skipped_same_batch'] ?? [],
        ]);
    }

    if ($action === 'import_one') {
        $item = $req['item'] ?? null;
        if (!is_array($item)) {
            bmiJson(['success' => false, 'error' => 'Missing transaction.']);
        }
        $db->begin_transaction();
        try {
            $res = beancountMassImportWriteOne($db, $item, $acl, true);
            if (empty($res['ok'])) {
                $db->rollback();
                bmiJson(['success' => false, 'error' => $res['error'] ?? 'Import failed.']);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            bmiJson(['success' => false, 'error' => 'Import failed: ' . $e->getMessage()]);
        }
        bmiJson([
            'success' => true,
            'id' => (int)$res['id'],
            'queue_id' => (string)($item['queue_id'] ?? ''),
            'discard_queue_ids' => array_values(array_filter(array_map('strval', (array)($req['discard_queue_ids'] ?? [])))),
        ]);
    }

    bmiJson(['success' => false, 'error' => 'Unknown action.']);
}

$lookups = beancountMassImportLoadLookups($db);
?>
<style>
    .bmi-page { min-height: calc(100vh - 5.5rem); }
    .bmi-review {
        display: flex;
        gap: 0.75rem;
        min-height: 28rem;
        height: calc(100vh - 12rem);
        max-height: 48rem;
    }
    .bmi-list-pane {
        flex: 0 0 min(38%, 26rem);
        min-width: 16rem;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    .bmi-detail-pane {
        flex: 1 1 auto;
        min-width: 0;
        overflow: auto;
    }
    .bmi-list { overflow: auto; flex: 1 1 auto; }
    .bmi-row { cursor: pointer; }
    .bmi-row.active { border-color: var(--bs-primary); background-color: rgba(var(--bs-primary-rgb), 0.08); }
    .bmi-row.bmi-has-error { border-left: 3px solid var(--bs-danger); }
    .bmi-row.bmi-has-warn { border-left: 3px solid var(--bs-warning); }
    .bmi-row.bmi-has-dupe { border-left: 3px solid var(--bs-info); }
    .bmi-amt { font-variant-numeric: tabular-nums; }
    .bmi-bean {
        font-size: 0.75rem;
        white-space: pre-wrap;
        max-height: 12rem;
        overflow: auto;
    }
    .bmi-dup-pane { min-height: 12rem; }
    @media (max-width: 767.98px) {
        .bmi-review {
            flex-direction: column;
            height: auto;
            max-height: none;
        }
        .bmi-list-pane { flex: none; max-height: 16rem; min-width: 0; }
    }
</style>

<div class="bmi-page" id="bmiPage">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <h4 class="mb-1"><i class="bi bi-box-arrow-in-down me-1"></i> Mass Import</h4>
            <div class="text-muted small">Beancount text → review queue → ledger. Temporary historical loader; nothing is saved until you confirm.</div>
        </div>
        <a href="javascript:void(0)" onclick="loadPage('ledger')" class="small text-decoration-none align-self-center">
            <i class="bi bi-arrow-left"></i> Back to Ledger
        </a>
    </div>

    <div id="bmiPasteStep">
        <div class="alert alert-info small">
            <strong>Beancount transactions only</strong> — no attachments. Required accounts, funds, and budgets must already exist.
            Access is limited to Administrator, Treasurer, Finance Manager, and Archivist.
            The single-transaction Import from Text in Add Transaction is unchanged.
        </div>
        <label class="form-label" for="bmiPaste">Paste Beancount transactions</label>
        <textarea class="form-control font-monospace" id="bmiPaste" rows="16"
                  placeholder="2026-03-15 * &quot;Office Depot&quot; &quot;Printer paper&quot;&#10;  FMB: Checking Account        -87.43 USD&#10;  Supplies                     87.43 USD"></textarea>
        <div id="bmiPasteErrors" class="alert alert-danger small mt-3 d-none mb-0">
            <ul class="mb-0 ps-3" id="bmiPasteErrorList"></ul>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-primary" id="bmiParseBtn">
                <i class="bi bi-clipboard-data me-1"></i> Parse
            </button>
            <button type="button" class="btn btn-outline-secondary" id="bmiPasteClearBtn">Clear</button>
        </div>
    </div>

    <div id="bmiReviewStep" class="d-none">
        <form id="bmiReviewForm" data-dirty-track>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="bmiBackPasteBtn">
                <i class="bi bi-arrow-left"></i> Back to paste
            </button>
            <span class="small text-muted" id="bmiQueueSummary"></span>
            <div class="ms-auto d-flex gap-2">
                <button type="button" class="btn btn-sm btn-primary" id="bmiConfirmBtn">
                    <i class="bi bi-check2-circle me-1"></i> Confirm / Import
                </button>
            </div>
        </div>
        <div id="bmiReviewBanner" class="alert alert-warning small py-2 d-none"></div>
        <div class="bmi-review">
            <div class="bmi-list-pane border rounded bg-body">
                <div class="px-2 py-1 border-bottom small fw-semibold bg-body-tertiary">Transactions</div>
                <div class="list-group list-group-flush bmi-list" id="bmiQueueList"></div>
            </div>
            <div class="bmi-detail-pane border rounded p-3 bg-body" id="bmiDetailPane">
                <p class="text-muted small mb-0">Select a transaction on the left to review or correct it.</p>
            </div>
        </div>
        </form>
    </div>

    <div id="bmiDoneStep" class="d-none">
        <div class="alert alert-success" id="bmiDoneMsg"></div>
        <button type="button" class="btn btn-primary" onclick="loadPage('ledger')">Open Ledger</button>
        <button type="button" class="btn btn-outline-secondary" id="bmiStartOverBtn">Import more</button>
    </div>
</div>

<div class="modal fade" id="bmiDupModal" tabindex="-1" aria-labelledby="bmiDupTitle" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="bmiDupTitle">Resolve duplicate</h5>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2" id="bmiDupReason"></p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="badge text-bg-primary" id="bmiDupLeftLabel">Importing</span>
                        </div>
                        <div class="border rounded p-2 bmi-dup-pane" id="bmiDupLeftForm"></div>
                        <div class="mt-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-semibold">Beancount text</span>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-bmi-copy="bmiDupLeftBean">Copy</button>
                            </div>
                            <pre class="bmi-bean bg-body-secondary border rounded p-2 mb-0" id="bmiDupLeftBean"></pre>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="badge text-bg-secondary" id="bmiDupRightLabel">Existing in Ledger</span>
                        </div>
                        <div class="border rounded p-2 bmi-dup-pane" id="bmiDupRightForm"></div>
                        <div class="mt-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-semibold">Beancount text</span>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-bmi-copy="bmiDupRightBean">Copy</button>
                            </div>
                            <pre class="bmi-bean bg-body-secondary border rounded p-2 mb-0" id="bmiDupRightBean"></pre>
                        </div>
                    </div>
                </div>
                <div id="bmiDupChooseWrap" class="alert alert-info small mt-3 mb-0 d-none">
                    Both sides are from this import. Choose which transaction to write; the other will be discarded.
                    <div class="mt-2">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="bmiDupChoose" id="bmiDupChooseLeft" value="left" checked>
                            <label class="form-check-label" for="bmiDupChooseLeft">Import the left transaction; discard the right</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="bmiDupChoose" id="bmiDupChooseRight" value="right">
                            <label class="form-check-label" for="bmiDupChooseRight">Import the right transaction; discard the left</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2 flex-wrap">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="bmiDupPrevBtn">Previous</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="bmiDupNextBtn">Next</button>
                <span class="small text-muted me-auto" id="bmiDupPos"></span>
                <button type="button" class="btn btn-sm btn-outline-danger" id="bmiDupCancelBtn">Cancel this import item</button>
                <button type="button" class="btn btn-sm btn-primary" id="bmiDupConfirmBtn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script type="text/plain" id="init-ledger-import-script">
(function () {
    const PAGE = 'pages/ledger_import.php';
    let lookups = <?= json_encode($lookups, JSON_UNESCAPED_UNICODE) ?> || { accounts: [], funds: [], budgets: [] };
    let queue = [];
    let selectedId = null;
    let dupSets = [];
    let dupIndex = 0;
    let importedCount = 0;
    let discardedCount = 0;

    const pasteStep = document.getElementById('bmiPasteStep');
    const reviewStep = document.getElementById('bmiReviewStep');
    const doneStep = document.getElementById('bmiDoneStep');
    const pasteEl = document.getElementById('bmiPaste');
    const queueList = document.getElementById('bmiQueueList');
    const detailPane = document.getElementById('bmiDetailPane');

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function money(n) {
        const x = Number(n) || 0;
        return x.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function toast(msg, type) {
        if (typeof showToast === 'function') showToast(msg, type || 'info');
    }
    function blockingOf(item) {
        const errs = [];
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(item.transaction_date || ''))) errs.push('Date is required.');
        if (!/^\d{6}$/.test(String(item.reference_number || '').trim())) errs.push('Reference # must be YY####.');
        const lines = (item.lines || []).filter(l => Number(l.account_id) > 0 && Number(l.amount) > 0 && (l.type === 'debit' || l.type === 'credit'));
        if (lines.length < 2) errs.push('At least two valid lines are required.');
        let dt = 0, ct = 0;
        lines.forEach(l => { if (l.type === 'credit') ct += Number(l.amount); else dt += Number(l.amount); });
        if (lines.length >= 2 && Math.abs(dt - ct) > 0.005) errs.push('Debits do not equal credits.');
        return errs;
    }
    function totals(item) {
        let dt = 0, ct = 0;
        (item.lines || []).forEach(l => {
            const am = Number(l.amount) || 0;
            if (l.type === 'credit') ct += am; else dt += am;
        });
        item.debit_total = Math.round(dt * 100) / 100;
        item.credit_total = Math.round(ct * 100) / 100;
        return item;
    }
    function itemAmount(item) {
        totals(item);
        return Math.max(Number(item.debit_total) || 0, Number(item.credit_total) || 0);
    }
    function collapseKey(s) {
        return String(s || '').toLowerCase().replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
    }
    function amountsSubstantiallyDifferent(a, b) {
        a = Number(a) || 0; b = Number(b) || 0;
        if (a <= 0.004 || b <= 0.004) return false;
        const lo = Math.min(a, b), hi = Math.max(a, b);
        if ((hi - lo) < 1) return false;
        return (lo / hi) < 0.5;
    }
    function duplicateScore(a, b) {
        const amtA = itemAmount(a), amtB = itemAmount(b);
        if (amountsSubstantiallyDifferent(amtA, amtB)) {
            return { score: 0, reasons: ['substantially different amounts'], primary: false, amount_mismatch: true };
        }
        let score = 0, reasons = [], primary = false;
        const da = String(a.transaction_date || '').trim();
        const dbd = String(b.transaction_date || '').trim();
        if (da && da === dbd) { score += 50; reasons.push('same date'); }
        const ra = String(a.reference_number || '').trim();
        const rb = String(b.reference_number || '').trim();
        const sameRef = !!(ra && rb && ra === rb);
        if (sameRef) { score += 55; reasons.push('same Ref #'); primary = true; }
        const ca = String(a.check_number || '').trim();
        const cb = String(b.check_number || '').trim();
        const sameCheck = !!(ca && cb && ca.toLowerCase() === cb.toLowerCase());
        if (sameCheck) { score += 40; reasons.push('same Check #'); primary = true; }
        if (da && da === dbd && (sameRef || sameCheck)) primary = true;
        if (amtA > 0.004 && amtB > 0.004) {
            const diff = Math.abs(amtA - amtB);
            const hi = Math.max(amtA, amtB);
            const rel = hi > 0 ? diff / hi : 1;
            if (diff < 0.005) { score += 30; reasons.push('same amount'); }
            else if (rel <= 0.02 || diff <= 0.5) { score += 20; reasons.push('similar amount'); }
            else if (rel <= 0.05) { score += 12; reasons.push('similar amount'); }
        }
        const pa = collapseKey(a.pay_to), pb = collapseKey(b.pay_to);
        if (pa && pb && (pa === pb || pa.indexOf(pb) >= 0 || pb.indexOf(pa) >= 0)) {
            score += 12; reasons.push('similar Pay To');
        }
        return { score, reasons, primary, amount_mismatch: false };
    }
    function isDupHit(row) {
        if (row.amount_mismatch) return false;
        if (row.primary) return true;
        return (Number(row.score) || 0) >= 70;
    }
    function matchSummary(item, where) {
        const bits = [];
        if (item.pay_to) bits.push('"' + item.pay_to + '"');
        if (item.transaction_date) bits.push('on ' + item.transaction_date);
        const amt = itemAmount(item);
        if (amt > 0) bits.push('$' + amt.toFixed(2));
        return 'Matches ' + (bits.length ? bits.join(' ') : 'another transaction') + ' ' + where;
    }
    function recomputeLocalQueueFlags() {
        queue.forEach(q => {
            if (q.duplicate_kind === 'ledger') return;
            q.duplicate_preview = false;
            q.duplicate_kind = null;
            q.duplicate_reasons = [];
            q.duplicate_match_label = '';
            q.duplicate_match_queue_id = null;
        });
        for (let i = 0; i < queue.length; i++) {
            for (let j = i + 1; j < queue.length; j++) {
                if (queue[i].duplicate_kind === 'ledger' || queue[j].duplicate_kind === 'ledger') continue;
                const hit = duplicateScore(queue[i], queue[j]);
                if (!isDupHit(hit)) continue;
                [queue[i], queue[j]].forEach((item, idx) => {
                    const other = idx === 0 ? queue[j] : queue[i];
                    if (item.duplicate_kind === 'ledger') return;
                    item.duplicate_preview = true;
                    item.duplicate_kind = 'queue';
                    item.duplicate_reasons = hit.reasons;
                    item.duplicate_match_label = matchSummary(other, 'in this import');
                    item.duplicate_match_queue_id = other.queue_id;
                });
            }
        }
    }
    let detectSeq = 0;
    let detectTimer = null;
    function scheduleDetect() {
        clearTimeout(detectTimer);
        detectTimer = setTimeout(runDetect, 280);
    }
    async function runDetect() {
        const seq = ++detectSeq;
        const snapshot = queue.map(q => Object.assign({}, q));
        try {
            const data = await api({ action: 'detect', items: snapshot });
            if (seq !== detectSeq || !data.success) return;
            (data.items || []).forEach(ann => {
                const q = queue.find(x => x.queue_id === ann.queue_id);
                if (!q) return;
                q.duplicate_preview = !!ann.duplicate_preview;
                q.duplicate_kind = ann.duplicate_kind || null;
                q.duplicate_reasons = ann.duplicate_reasons || [];
                q.duplicate_match_label = ann.duplicate_match_label || '';
                q.duplicate_match_queue_id = ann.duplicate_match_queue_id || null;
                q.duplicate_primary = !!ann.duplicate_primary;
            });
            renderList();
            updateDupHint();
        } catch (e) { /* keep local flags */ }
    }
    function accountById(id) {
        id = String(id || '');
        return (lookups.accounts || []).find(a => String(a.id) === id) || null;
    }
    function fundById(id) {
        id = String(id || '');
        return (lookups.funds || []).find(f => String(f.id) === id) || null;
    }
    function accountOptions(selected) {
        const sel = String(selected || '');
        let html = '<option value="">— Select account —</option>';
        (lookups.accounts || []).forEach(a => {
            const coa = String(a.coa_number || '').trim();
            const label = coa ? (coa + ' · ' + a.name) : a.name;
            html += '<option value="' + esc(a.id) + '"' + (String(a.id) === sel ? ' selected' : '') + '>' + esc(label) + '</option>';
        });
        return html;
    }
    function fundOptions(selected) {
        const sel = String(selected || '');
        let html = '<option value="">— None —</option>';
        (lookups.funds || []).forEach(f => {
            const label = f.code ? (f.name + ' (' + f.code + ')') : f.name;
            html += '<option value="' + esc(f.id) + '"' + (String(f.id) === sel ? ' selected' : '') + '>' + esc(label) + '</option>';
        });
        return html;
    }
    function budgetOptions(selected) {
        const sel = String(selected || '');
        let html = '<option value="">— None —</option>';
        (lookups.budgets || []).forEach(b => {
            html += '<option value="' + esc(b.id) + '"' + (String(b.id) === sel ? ' selected' : '') + '>' + esc(b.label || b.name) + '</option>';
        });
        return html;
    }

    function formatBean(item) {
        const date = item.transaction_date || '0000-00-00';
        let header = date + ' *';
        if (item.pay_to) header += ' "' + String(item.pay_to).replace(/"/g, '\\"') + '"';
        if (item.description) header += ' "' + String(item.description).replace(/"/g, '\\"') + '"';
        const lines = [header];
        if (item.reference_number) lines.push('  reference: "' + item.reference_number + '"');
        if (item.check_number) lines.push('  check: "' + item.check_number + '"');
        (item.lines || []).forEach(l => {
            const acc = accountById(l.account_id);
            const name = (acc && acc.name) || l.account_name || l.account_name_raw || 'Unknown';
            const signed = (l.type === 'credit' ? -1 : 1) * (Number(l.amount) || 0);
            let row = '  ' + name + '  ' + signed.toFixed(2) + ' USD';
            const fund = fundById(l.fund_id);
            if (fund) row += ' ; fund: ' + fund.name;
            else if (l.fund_hint) row += ' ; fund: ' + l.fund_hint;
            lines.push(row);
        });
        return lines.join('\n');
    }

    async function api(payload) {
        const res = await fetch(PAGE, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });
        const text = await res.text();
        if (typeof window.isAuthExpiredPayload === 'function' && window.isAuthExpiredPayload(text)) {
            window.redirectToLoginExpired();
            throw new Error('Session expired');
        }
        let data;
        try { data = JSON.parse(text); } catch (e) {
            throw new Error('Unexpected server response');
        }
        return data;
    }

    function showStep(name) {
        pasteStep.classList.toggle('d-none', name !== 'paste');
        reviewStep.classList.toggle('d-none', name !== 'review');
        doneStep.classList.toggle('d-none', name !== 'done');
    }

    function setPasteErrors(msgs) {
        const box = document.getElementById('bmiPasteErrors');
        const list = document.getElementById('bmiPasteErrorList');
        const arr = msgs || [];
        list.innerHTML = arr.map(m => '<li>' + esc(m) + '</li>').join('');
        box.classList.toggle('d-none', arr.length === 0);
    }

    function collectDetailFrom(root) {
        if (!root) return null;
        const qid = root.getAttribute('data-queue-id');
        const item = queue.find(q => q.queue_id === qid) || { queue_id: qid, lines: [] };
        const val = (id) => {
            const el = root.querySelector('[data-f="' + id + '"]');
            return el ? el.value : '';
        };
        item.transaction_date = val('date');
        item.reference_number = val('ref');
        item.check_number = val('check');
        item.pay_to = val('pay');
        item.description = val('desc');
        item.budget_id = val('budget');
        const allowEl = root.querySelector('[data-f="allow_same_batch"]');
        if (allowEl) item.allow_same_batch = !!allowEl.checked;
        const lines = [];
        root.querySelectorAll('[data-line]').forEach(row => {
            const aid = row.querySelector('[data-f="account"]')?.value || '';
            const fid = row.querySelector('[data-f="fund"]')?.value || '';
            const debit = parseFloat(row.querySelector('[data-f="debit"]')?.value || '0') || 0;
            const credit = parseFloat(row.querySelector('[data-f="credit"]')?.value || '0') || 0;
            const acc = accountById(aid);
            let type = 'debit';
            let amount = debit;
            if (credit > 0 && !(debit > 0)) {
                type = 'credit';
                amount = credit;
            } else if (debit > 0) {
                type = 'debit';
                amount = debit;
            } else if (credit > 0) {
                type = 'credit';
                amount = credit;
            }
            lines.push({
                account_id: aid ? Number(aid) : '',
                account_name: acc ? acc.name : '',
                account_name_raw: acc ? acc.name : (row.getAttribute('data-raw') || ''),
                fund_id: fid ? Number(fid) : '',
                fund_name: fundById(fid) ? fundById(fid).name : '',
                amount: Math.round(amount * 100) / 100,
                type,
                natural_category_id: acc ? acc.natural_category_id : '',
                functional_category_id: acc ? acc.functional_category_id : '',
                natural_name: acc ? acc.natural_name : '—',
                functional_name: acc ? acc.functional_name : '—'
            });
        });
        item.lines = lines;
        totals(item);
        item.blocking = blockingOf(item);
        item.errors = item.blocking.slice();
        item.beancount_text = formatBean(item);
        item.parse_ok = item.blocking.length === 0;
        return item;
    }

    function flushSelected() {
        const root = detailPane.querySelector('[data-queue-id]');
        if (!root) return;
        const updated = collectDetailFrom(root);
        if (!updated) return;
        const idx = queue.findIndex(q => q.queue_id === updated.queue_id);
        if (idx >= 0) queue[idx] = Object.assign({}, queue[idx], updated);
    }

    function lineEditor(line, editable) {
        const dis = editable ? '' : ' disabled';
        const debit = (line.type === 'debit') ? (Number(line.amount) || '') : '';
        const credit = (line.type === 'credit') ? (Number(line.amount) || '') : '';
        const acc = accountById(line.account_id);
        const nat = acc ? acc.natural_name : (line.natural_name || '—');
        const fun = acc ? acc.functional_name : (line.functional_name || '—');
        return '<tr data-line data-raw="' + esc(line.account_name_raw || '') + '">'
            + '<td><select class="form-select form-select-sm" data-f="account"' + dis + '>' + accountOptions(line.account_id) + '</select>'
            + '<div class="small text-muted text-truncate" style="max-width:10rem" title="' + esc(nat + ' / ' + fun) + '">' + esc(nat) + ' · ' + esc(fun) + '</div></td>'
            + '<td><select class="form-select form-select-sm" data-f="fund"' + dis + '>' + fundOptions(line.fund_id) + '</select></td>'
            + '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" data-f="debit" value="' + esc(debit) + '"' + dis + '></td>'
            + '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" data-f="credit" value="' + esc(credit) + '"' + dis + '></td>'
            + (editable ? '<td><button type="button" class="btn btn-sm btn-outline-danger py-0 bmi-del-line" title="Remove line">&times;</button></td>' : '<td></td>')
            + '</tr>';
    }

    function editorHtml(item, editable, prefix) {
        const dis = editable ? '' : ' disabled';
        const block = blockingOf(item);
        const warns = item.warnings || [];
        let banner = '';
        if (block.length) {
            banner += '<div class="alert alert-danger small py-1 px-2">' + block.map(e => esc(e)).join('<br>') + '</div>';
        } else if (warns.length) {
            banner += '<div class="alert alert-warning small py-1 px-2">' + warns.map(e => esc(e)).join('<br>') + '</div>';
        }
        const linesHtml = (item.lines && item.lines.length)
            ? item.lines.map(l => lineEditor(l, editable)).join('')
            : '';
        return '<div data-queue-id="' + esc(item.queue_id || '') + '" data-prefix="' + esc(prefix || '') + '">'
            + banner
            + '<div class="row g-2">'
            + '<div class="col-6 col-lg-3"><label class="form-label small mb-1">Date *</label>'
            + '<input type="date" class="form-control form-control-sm" data-f="date" value="' + esc(item.transaction_date || '') + '"' + dis + '></div>'
            + '<div class="col-6 col-lg-3"><label class="form-label small mb-1">Ref # *</label>'
            + '<input type="text" maxlength="6" class="form-control form-control-sm font-monospace" data-f="ref" value="' + esc(item.reference_number || '') + '"' + dis + '></div>'
            + '<div class="col-6 col-lg-3"><label class="form-label small mb-1">Check #</label>'
            + '<input type="text" class="form-control form-control-sm" data-f="check" value="' + esc(item.check_number || '') + '"' + dis + '></div>'
            + '<div class="col-6 col-lg-3"><label class="form-label small mb-1">Budget</label>'
            + '<select class="form-select form-select-sm" data-f="budget"' + dis + '>' + budgetOptions(item.budget_id) + '</select></div>'
            + '<div class="col-12 col-lg-6"><label class="form-label small mb-1">Pay To</label>'
            + '<input type="text" class="form-control form-control-sm" data-f="pay" value="' + esc(item.pay_to || '') + '"' + dis + '></div>'
            + '<div class="col-12 col-lg-6"><label class="form-label small mb-1">Memo / Description</label>'
            + '<input type="text" class="form-control form-control-sm" data-f="desc" value="' + esc(item.description || '') + '"' + dis + '></div>'
            + '</div>'
            + '<div class="table-responsive mt-2"><table class="table table-sm table-bordered align-middle mb-1">'
            + '<thead class="table-light"><tr><th>Account *</th><th>Fund</th>'
            + '<th class="text-end text-primary">Debit</th><th class="text-end text-success">Credit</th><th style="width:2rem"></th></tr></thead>'
            + '<tbody class="bmi-lines">' + linesHtml + '</tbody></table></div>'
            + (editable ? '<button type="button" class="btn btn-sm btn-outline-primary bmi-add-line">+ Add line</button>' : '')
            + '<div class="small mt-1">Debits <span class="text-primary fw-semibold bmi-dt">$' + money(item.debit_total) + '</span>'
            + ' · Credits <span class="text-success fw-semibold bmi-ct">$' + money(item.credit_total) + '</span></div>'
            + '</div>';
    }

    function bindEditor(root, onChange) {
        if (!root) return;
        const recalc = () => {
            const item = collectDetailFrom(root);
            if (!item) return;
            const dt = root.querySelector('.bmi-dt');
            const ct = root.querySelector('.bmi-ct');
            if (dt) dt.textContent = '$' + money(item.debit_total);
            if (ct) ct.textContent = '$' + money(item.credit_total);
            if (onChange) onChange(item);
        };
        root.addEventListener('input', recalc);
        root.addEventListener('change', (ev) => {
            const accSel = ev.target.closest('[data-f="account"]');
            if (accSel) {
                const acc = accountById(accSel.value);
                const row = accSel.closest('[data-line]');
                const lab = row ? row.querySelector('.small.text-muted') : null;
                if (lab) lab.textContent = acc ? ((acc.natural_name || '—') + ' · ' + (acc.functional_name || '—')) : '— · —';
            }
            const debit = ev.target.closest('[data-f="debit"]');
            const credit = ev.target.closest('[data-f="credit"]');
            if (debit && Number(debit.value) > 0) {
                const row = debit.closest('[data-line]');
                const c = row && row.querySelector('[data-f="credit"]');
                if (c) c.value = '';
            }
            if (credit && Number(credit.value) > 0) {
                const row = credit.closest('[data-line]');
                const d = row && row.querySelector('[data-f="debit"]');
                if (d) d.value = '';
            }
            recalc();
        });
        root.addEventListener('click', (ev) => {
            if (ev.target.closest('.bmi-del-line')) {
                const row = ev.target.closest('[data-line]');
                if (row) row.remove();
                recalc();
            }
            if (ev.target.closest('.bmi-add-line')) {
                const tbody = root.querySelector('.bmi-lines');
                if (!tbody) return;
                tbody.insertAdjacentHTML('beforeend', lineEditor({ account_id: '', fund_id: '', amount: '', type: 'debit' }, true));
            }
        });
    }

    function renderList() {
        const errN = queue.filter(q => blockingOf(q).length).length;
        const ledgerN = queue.filter(q => q.duplicate_kind === 'ledger').length;
        const batchN = queue.filter(q => q.duplicate_kind === 'queue').length;
        const allowedN = queue.filter(q => q.duplicate_kind === 'queue' && q.allow_same_batch).length;
        document.getElementById('bmiQueueSummary').textContent =
            queue.length + ' transaction' + (queue.length === 1 ? '' : 's')
            + (errN ? (' · ' + errN + ' need correction') : '')
            + (ledgerN ? (' · ' + ledgerN + ' match the ledger') : '')
            + (batchN ? (' · ' + batchN + ' match this import') : '');
        const btn = document.getElementById('bmiConfirmBtn');
        btn.disabled = queue.length === 0 || errN > 0;
        btn.title = errN ? 'Correct highlighted rows before importing.' : 'Write non-duplicates to the ledger';

        const banner = document.getElementById('bmiReviewBanner');
        const parts = [];
        if (errN) {
            parts.push(errN + ' transaction' + (errN === 1 ? '' : 's') + ' still have errors (red). Fix them in the detail panel before Confirm / Import.');
        }
        if (ledgerN) {
            parts.push(ledgerN + ' match existing ledger transactions and will be resolved side-by-side after Confirm.');
        }
        if (batchN) {
            const pending = batchN - allowedN;
            parts.push(batchN + ' match other items in this import. Mark Legitimate / Allow in the detail pane to include them'
                + (pending ? ' (' + pending + ' not yet allowed).' : ', or edit so they no longer match.'));
        }
        if (parts.length) {
            banner.innerHTML = parts.map(p => esc(p)).join('<br>');
            banner.classList.remove('d-none');
            banner.classList.toggle('alert-danger', errN > 0);
            banner.classList.toggle('alert-warning', errN === 0);
        } else {
            banner.classList.add('d-none');
        }

        queueList.innerHTML = queue.map(item => {
            const block = blockingOf(item);
            const cls = ['list-group-item', 'list-group-item-action', 'bmi-row', 'py-2'];
            if (item.queue_id === selectedId) cls.push('active');
            if (block.length) cls.push('bmi-has-error');
            else if (item.duplicate_kind === 'ledger') cls.push('bmi-has-dupe');
            else if (item.duplicate_kind === 'queue') cls.push('bmi-has-dupe');
            else if (item.warnings && item.warnings.length) cls.push('bmi-has-warn');
            const amt = Number(item.debit_total) || 0;
            let badges = '';
            if (block.length) badges += '<span class="badge text-bg-danger ms-1">Error</span>';
            else if (item.duplicate_kind === 'ledger') badges += '<span class="badge text-bg-info ms-1">In ledger</span>';
            else if (item.duplicate_kind === 'queue' && item.allow_same_batch) badges += '<span class="badge text-bg-success ms-1">Allowed</span>';
            else if (item.duplicate_kind === 'queue') badges += '<span class="badge text-bg-warning ms-1">In this import</span>';
            else if (item.warnings && item.warnings.length) badges += '<span class="badge text-bg-warning ms-1">Warn</span>';
            return '<button type="button" class="' + cls.join(' ') + '" data-qid="' + esc(item.queue_id) + '">'
                + '<div class="d-flex justify-content-between gap-2">'
                + '<span class="text-nowrap">' + esc(item.transaction_date || '') + '</span>'
                + '<span class="bmi-amt fw-semibold">$' + money(amt) + '</span></div>'
                + '<div class="small d-flex justify-content-between gap-2">'
                + '<span class="text-truncate"><span class="font-monospace">' + esc(item.reference_number || '—') + '</span> · ' + esc(item.pay_to || '(no payee)') + '</span>'
                + badges + '</div></button>';
        }).join('');
    }

    function renderDetail() {
        const item = queue.find(q => q.queue_id === selectedId);
        if (!item) {
            detailPane.innerHTML = '<p class="text-muted small mb-0">Select a transaction on the left to review or correct it.</p>';
            return;
        }
        detailPane.innerHTML = '<h6 class="mb-2">Correction panel</h6><div id="bmiDupHint"></div>' + editorHtml(item, true, 'detail');
        bindEditor(detailPane, () => {
            flushSelected();
            recomputeLocalQueueFlags();
            renderList();
            updateDupHint();
            scheduleDetect();
        });
        updateDupHint();
        const allowEl = detailPane.querySelector('[data-f="allow_same_batch"]');
        if (allowEl) {
            allowEl.addEventListener('change', () => {
                flushSelected();
                renderList();
                updateDupHint();
            });
        }
    }

    function updateDupHint() {
        const box = document.getElementById('bmiDupHint');
        if (!box) return;
        const item = queue.find(q => q.queue_id === selectedId);
        if (!item) { box.innerHTML = ''; return; }
        const why = (item.duplicate_reasons || []).join(', ');
        const label = item.duplicate_match_label || '';
        if (item.duplicate_kind === 'ledger') {
            box.innerHTML = '<div class="alert alert-info small py-2">'
                + esc(label || 'This matches a transaction already in the ledger.')
                + (why ? ' <span class="text-muted">(' + esc(why) + ')</span>' : '')
                + '<div class="mt-1 mb-0">After Confirm / Import you will resolve it side-by-side. Editing fields re-checks immediately.</div></div>';
            return;
        }
        if (item.duplicate_kind === 'queue') {
            const checked = item.allow_same_batch ? ' checked' : '';
            box.innerHTML = '<div class="alert alert-warning small py-2 mb-2">'
                + esc(label || 'This matches another transaction in this import.')
                + (why ? ' <span class="text-muted">(' + esc(why) + ')</span>' : '')
                + '<div class="form-check mt-2 mb-0">'
                + '<input class="form-check-input" type="checkbox" id="bmiAllowSame" data-f="allow_same_batch"' + checked + '>'
                + '<label class="form-check-label" for="bmiAllowSame"><strong>Legitimate / Allow</strong> — import this item with Confirm even though it matches another in this paste</label>'
                + '</div></div>';
            const allowEl = box.querySelector('[data-f="allow_same_batch"]');
            if (allowEl) {
                allowEl.addEventListener('change', () => {
                    item.allow_same_batch = !!allowEl.checked;
                    renderList();
                });
            }
            return;
        }
        box.innerHTML = '';
    }

    function selectId(id) {
        flushSelected();
        selectedId = id;
        renderList();
        renderDetail();
    }

    queueList.addEventListener('click', (ev) => {
        const btn = ev.target.closest('[data-qid]');
        if (btn) selectId(btn.getAttribute('data-qid'));
    });

    document.getElementById('bmiParseBtn').addEventListener('click', async () => {
        setPasteErrors([]);
        const text = pasteEl.value || '';
        const btn = document.getElementById('bmiParseBtn');
        btn.disabled = true;
        try {
            const data = await api({ action: 'parse', text });
            if (!data.success) {
                setPasteErrors(data.errors && data.errors.length ? data.errors : [data.error || 'Parse failed.']);
                return;
            }
            lookups = data.lookups || lookups;
            queue = data.items || [];
            if (!queue.length) {
                setPasteErrors(['No transactions parsed.']);
                return;
            }
            selectedId = queue[0].queue_id;
            showStep('review');
            recomputeLocalQueueFlags();
            renderList();
            renderDetail();
            scheduleDetect();
            const form = document.getElementById('bmiReviewForm');
            if (form) form.setAttribute('data-dirty', '1');
            toast('Parsed ' + queue.length + ' transaction' + (queue.length === 1 ? '' : 's') + '. Review, then Confirm / Import.', 'success');
        } catch (e) {
            setPasteErrors([e.message || 'Parse failed.']);
        } finally {
            btn.disabled = false;
        }
    });

    document.getElementById('bmiPasteClearBtn').addEventListener('click', () => {
        pasteEl.value = '';
        setPasteErrors([]);
        pasteEl.focus();
    });

    document.getElementById('bmiBackPasteBtn').addEventListener('click', () => {
        if (typeof window.TemperDirtyForms !== 'undefined' && window.TemperDirtyForms.isDirty()) {
            if (!window.TemperDirtyForms.confirmLeave('Return to paste? Unimported corrections will be lost.')) return;
            window.TemperDirtyForms.clearAll();
        }
        queue = [];
        selectedId = null;
        showStep('paste');
    });

    document.getElementById('bmiStartOverBtn').addEventListener('click', () => {
        queue = [];
        selectedId = null;
        importedCount = 0;
        discardedCount = 0;
        dupSets = [];
        showStep('paste');
    });

    function finishProcess() {
        const parts = [];
        parts.push(importedCount + ' transaction' + (importedCount === 1 ? '' : 's') + ' written to the ledger.');
        if (discardedCount) parts.push(discardedCount + ' cancelled.');
        document.getElementById('bmiDoneMsg').textContent = parts.join(' ');
        if (typeof window.TemperDirtyForms !== 'undefined') window.TemperDirtyForms.clearAll();
        showStep('done');
        toast(parts.join(' '), 'success');
    }

    function dupModal() {
        const el = document.getElementById('bmiDupModal');
        if (!el || typeof bootstrap === 'undefined') return null;
        if (typeof window.mountModalOnBody === 'function') window.mountModalOnBody(el);
        return bootstrap.Modal.getOrCreateInstance(el, { backdrop: 'static', keyboard: false });
    }

    function currentDupSet() {
        return dupSets[dupIndex] || null;
    }

    function collectDupFormsIntoSet() {
        const set = currentDupSet();
        if (!set) return;
        const leftRoot = document.getElementById('bmiDupLeftForm').querySelector('[data-queue-id]');
        if (leftRoot) {
            const collected = collectDupPane(document.getElementById('bmiDupLeftForm'), set.left);
            set.left = Object.assign({}, set.left, collected);
        }
        if (set.right_editable) {
            const collected = collectDupPane(document.getElementById('bmiDupRightForm'), set.right);
            set.right = Object.assign({}, set.right, collected);
        }
        document.getElementById('bmiDupLeftBean').textContent = formatBean(set.left);
        document.getElementById('bmiDupRightBean').textContent = formatBean(set.right);
    }

    function collectDupPane(container, base) {
        const root = container.querySelector('[data-queue-id]');
        if (!root) return base;
        const hold = queue;
        const exists = hold.some(q => q.queue_id === (base.queue_id || root.getAttribute('data-queue-id')));
        if (!exists) queue = hold.concat([base]);
        const item = collectDetailFrom(root);
        queue = hold;
        return item || base;
    }

    function renderDupSet() {
        const set = currentDupSet();
        if (!set) return;
        document.getElementById('bmiDupTitle').textContent = 'Resolve duplicate (' + (dupIndex + 1) + ' of ' + dupSets.length + ')';
        document.getElementById('bmiDupPos').textContent = (dupIndex + 1) + ' / ' + dupSets.length;
        const why = (set.reasons || []).join(', ') || 'possible match';
        document.getElementById('bmiDupReason').textContent = 'Matched on ' + why + (set.primary ? ' (primary identifiers).' : '.');
        document.getElementById('bmiDupLeftLabel').textContent = set.left_label || 'Importing';
        document.getElementById('bmiDupRightLabel').textContent = set.right_label || 'Existing in Ledger';
        document.getElementById('bmiDupRightLabel').className = set.right_source === 'queue'
            ? 'badge text-bg-warning'
            : 'badge text-bg-secondary';
        document.getElementById('bmiDupLeftForm').innerHTML = editorHtml(set.left, true, 'dupL');
        document.getElementById('bmiDupRightForm').innerHTML = editorHtml(set.right, !!set.right_editable, 'dupR');
        bindEditor(document.getElementById('bmiDupLeftForm'), (item) => {
            set.left = Object.assign({}, set.left, item);
            document.getElementById('bmiDupLeftBean').textContent = formatBean(set.left);
        });
        if (set.right_editable) {
            bindEditor(document.getElementById('bmiDupRightForm'), (item) => {
                set.right = Object.assign({}, set.right, item);
                document.getElementById('bmiDupRightBean').textContent = formatBean(set.right);
            });
        }
        document.getElementById('bmiDupLeftBean').textContent = formatBean(set.left);
        document.getElementById('bmiDupRightBean').textContent = formatBean(set.right);
        const choose = document.getElementById('bmiDupChooseWrap');
        choose.classList.toggle('d-none', set.right_source !== 'queue');
        document.getElementById('bmiDupPrevBtn').disabled = dupIndex <= 0;
        document.getElementById('bmiDupNextBtn').disabled = dupIndex >= dupSets.length - 1;
    }

    function openDupModal() {
        if (!dupSets.length) {
            finishProcess();
            return;
        }
        dupIndex = 0;
        renderDupSet();
        const modal = dupModal();
        if (modal) modal.show();
    }

    function closeDupIfDone() {
        if (dupSets.length) {
            if (dupIndex >= dupSets.length) dupIndex = dupSets.length - 1;
            renderDupSet();
            return;
        }
        const el = document.getElementById('bmiDupModal');
        const inst = el && bootstrap.Modal.getInstance(el);
        if (inst) inst.hide();
        finishProcess();
    }

    document.getElementById('bmiDupPrevBtn').addEventListener('click', () => {
        collectDupFormsIntoSet();
        if (dupIndex > 0) { dupIndex--; renderDupSet(); }
    });
    document.getElementById('bmiDupNextBtn').addEventListener('click', () => {
        collectDupFormsIntoSet();
        if (dupIndex < dupSets.length - 1) { dupIndex++; renderDupSet(); }
    });

    document.getElementById('bmiDupCancelBtn').addEventListener('click', () => {
        collectDupFormsIntoSet();
        const set = currentDupSet();
        if (!set) return;
        discardedCount++;
        const leftId = set.left && set.left.queue_id;
        const rightIsQueue = set.right_source === 'queue';
        const rightItem = rightIsQueue ? set.right : null;
        dupSets.splice(dupIndex, 1);
        toast('Cancelled import of ' + (set.left.pay_to || set.left.reference_number || 'item') + '.', 'info');
        if (rightItem) {
            const stillPaired = dupSets.some(s =>
                (s.left && s.left.queue_id === rightItem.queue_id)
                || (s.right_source === 'queue' && s.right && s.right.queue_id === rightItem.queue_id)
            );
            if (!stillPaired) {
                dupSets.splice(Math.min(dupIndex, dupSets.length), 0, {
                    set_id: 'remain-' + rightItem.queue_id,
                    reasons: ['remaining after its pair was cancelled'],
                    primary: false,
                    left: rightItem,
                    left_label: 'Importing',
                    right: {
                        queue_id: '',
                        transaction_date: '',
                        reference_number: '',
                        check_number: '',
                        pay_to: '(no remaining match)',
                        description: 'Confirm to import this item, or cancel to discard it.',
                        lines: [],
                        debit_total: 0,
                        credit_total: 0
                    },
                    right_label: 'No remaining match',
                    right_source: 'none',
                    right_editable: false
                });
            }
        }
        closeDupIfDone();
    });

    document.getElementById('bmiDupConfirmBtn').addEventListener('click', async () => {
        collectDupFormsIntoSet();
        const set = currentDupSet();
        if (!set) return;
        let item = set.left;
        let discard = [];
        if (set.right_source === 'queue') {
            const choice = (document.querySelector('input[name="bmiDupChoose"]:checked') || {}).value || 'left';
            if (choice === 'right') {
                item = set.right;
                discard = [set.left.queue_id];
            } else {
                discard = [set.right.queue_id];
            }
        }
        const block = blockingOf(item);
        if (block.length) {
            toast(block[0], 'danger');
            return;
        }
        const btn = document.getElementById('bmiDupConfirmBtn');
        btn.disabled = true;
        try {
            const data = await api({ action: 'import_one', item, discard_queue_ids: discard });
            if (!data.success) {
                toast(data.error || 'Import failed.', 'danger');
                return;
            }
            importedCount++;
            discardedCount += discard.length;
            toast('Imported #' + data.id + ' immediately.', 'success');
            dupSets.splice(dupIndex, 1);
            dupSets = dupSets.filter(s => {
                const ids = [item.queue_id].concat(discard);
                if (s.left && ids.indexOf(s.left.queue_id) >= 0) return false;
                if (s.right_source === 'queue' && s.right && ids.indexOf(s.right.queue_id) >= 0) return false;
                return true;
            });
            closeDupIfDone();
        } catch (e) {
            toast(e.message || 'Import failed.', 'danger');
        } finally {
            btn.disabled = false;
        }
    });

    document.querySelectorAll('[data-bmi-copy]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.getAttribute('data-bmi-copy');
            const el = document.getElementById(id);
            const text = el ? el.textContent : '';
            try {
                await navigator.clipboard.writeText(text);
                toast('Copied Beancount text.', 'success');
            } catch (e) {
                toast('Could not copy.', 'warning');
            }
        });
    });

    document.getElementById('bmiConfirmBtn').addEventListener('click', async () => {
        flushSelected();
        const bad = queue.filter(q => blockingOf(q).length);
        if (bad.length) {
            toast('Fix errors before importing.', 'danger');
            selectId(bad[0].queue_id);
            return;
        }
        const btn = document.getElementById('bmiConfirmBtn');
        btn.disabled = true;
        try {
            const data = await api({ action: 'import_batch', items: queue });
            if (!data.success) {
                toast(data.error || 'Import failed.', 'danger');
                return;
            }
            importedCount = (data.imported || []).length;
            const rejected = data.rejected || [];
            const skipped = data.skipped_same_batch || [];
            if (rejected.length) {
                toast(rejected.length + ' item(s) were not written (validation).', 'warning');
            }
            if (skipped.length) {
                discardedCount += skipped.length;
                toast(skipped.length + ' same-batch duplicate' + (skipped.length === 1 ? '' : 's')
                    + ' skipped (not marked Legitimate / Allow).', 'info');
            }
            dupSets = (data.duplicate_sets || []).filter(s => s.right_source === 'ledger');
            if (typeof window.TemperDirtyForms !== 'undefined') window.TemperDirtyForms.markClean();
            if (dupSets.length) {
                toast(importedCount + ' imported. Resolve ' + dupSets.length + ' ledger duplicate'
                    + (dupSets.length === 1 ? '' : 's') + '.', 'warning');
                openDupModal();
            } else {
                finishProcess();
            }
        } catch (e) {
            toast(e.message || 'Import failed.', 'danger');
        } finally {
            btn.disabled = false;
        }
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-ledger-import-script');if(s){(new Function(s.textContent))();}this.remove();">

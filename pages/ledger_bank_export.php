<?php
/**
 * TEMPORARY Bank Export mass import page (SPA fragment).
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * Delete with includes/bank_export_import.php and the Ledger → Bank Export nav item
 * after historical bank CSV data has been loaded.
 */
$temperSkipPagePermission = true;
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/ledger_engine.php';
require_once __DIR__ . '/../includes/bank_export_import.php';

$acl = bankExportImportRequireAccess($db);

/** TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete */
function beiJson(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete */
function beiReadRequest(): array
{
    $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (str_contains($ct, 'application/json')) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $text = (string)file_get_contents($_FILES['csv_file']['tmp_name']);
        return [
            'action' => (string)($_POST['action'] ?? 'parse'),
            'csv' => $text,
        ];
    }
    return $_POST;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level() === 0) {
        ob_start();
    }
    $req = beiReadRequest();
    $action = (string)($req['action'] ?? '');
    $accounts = bankExportImportResolveAccounts($db);

    if ($action === 'parse') {
        if (empty($accounts['ok'])) {
            beiJson([
                'success' => false,
                'error' => $accounts['error'] ?? 'Required accounts were not found.',
                'errors' => [$accounts['error'] ?? 'Required accounts were not found.'],
            ]);
        }
        $csv = (string)($req['csv'] ?? $req['text'] ?? '');
        $parsed = bankExportImportParseCsv($csv);
        if (empty($parsed['ok'])) {
            beiJson([
                'success' => false,
                'error' => $parsed['errors'][0] ?? 'Parse failed.',
                'errors' => $parsed['errors'] ?? [],
            ]);
        }
        beiJson([
            'success' => true,
            'items' => $parsed['items'],
            'accounts' => [
                'checking' => $accounts['checking'],
                'imbalance' => $accounts['imbalance'],
            ],
        ]);
    }

    if ($action === 'import') {
        $items = $req['items'] ?? [];
        if (!is_array($items) || $items === []) {
            beiJson(['success' => false, 'error' => 'Nothing to import.']);
        }
        $result = bankExportImportWriteBatch($db, $items, $acl);
        if (empty($result['ok'])) {
            beiJson([
                'success' => false,
                'error' => $result['error'] ?? 'Import failed.',
                'rejected' => $result['rejected'] ?? [],
            ]);
        }
        beiJson([
            'success' => true,
            'imported' => $result['imported'],
            'imported_count' => count($result['imported']),
        ]);
    }

    beiJson(['success' => false, 'error' => 'Unknown action.']);
}

$accounts = bankExportImportResolveAccounts($db);
$checkingName = BANK_EXPORT_CHECKING_ACCOUNT_NAME;
$imbalanceName = BANK_EXPORT_IMBALANCE_ACCOUNT_NAME;
?>
<!-- TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete -->
<style>
    .bei-page { min-height: calc(100vh - 5.5rem); }
    .bei-preview { max-height: calc(100vh - 16rem); overflow: auto; }
    .bei-amt { font-variant-numeric: tabular-nums; text-align: right; }
    .bei-csv {
        font-family: var(--bs-font-monospace, monospace);
        font-size: 0.85rem;
    }
</style>

<div class="bei-page" id="beiPage">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <h4 class="mb-1"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Bank Export</h4>
            <div class="text-muted small">FMB Checking CSV → preview → ledger. Temporary loader; nothing is saved until you confirm.</div>
        </div>
        <a href="javascript:void(0)" onclick="loadPage('ledger')" class="small text-decoration-none align-self-center">
            <i class="bi bi-arrow-left"></i> Back to Ledger
        </a>
    </div>

    <div id="beiPasteStep">
        <div class="alert alert-info small">
            <strong>FMB Checking bank-export CSV only</strong> — no attachments, no duplicate check, no funds, no Reference #.
            Every row posts to <strong><?= htmlspecialchars($checkingName) ?></strong> vs
            <strong><?= htmlspecialchars($imbalanceName) ?></strong>.
            Bank <em>Credit</em> (money in) debits Checking and credits Imbalance;
            bank <em>Debit</em> (money out) credits Checking and debits Imbalance.
            Access is limited to Administrator, Treasurer, Finance Manager, and Archivist.
        </div>
        <?php if (empty($accounts['ok'])): ?>
        <div class="alert alert-danger small">
            <?= htmlspecialchars($accounts['error'] ?? 'Required accounts were not found.') ?>
        </div>
        <?php endif; ?>
        <div class="mb-3">
            <label class="form-label" for="beiFile">Upload CSV</label>
            <input type="file" class="form-control" id="beiFile" accept=".csv,text/csv,text/plain">
        </div>
        <label class="form-label" for="beiPaste">Or paste CSV</label>
        <textarea class="form-control bei-csv" id="beiPaste" rows="14"
                  placeholder="&quot;Account Name&quot;,&quot;Processed Date&quot;,&quot;Description&quot;,&quot;Check Number&quot;,&quot;Credit or Debit&quot;,&quot;Amount&quot;&#10;&quot;FMB Checking&quot;,&quot;08/14/2026&quot;,&quot;Deposit&quot;,&quot;&quot;,&quot;Credit&quot;,&quot;1250.00&quot;"></textarea>
        <div id="beiPasteErrors" class="alert alert-danger small mt-3 d-none mb-0">
            <ul class="mb-0 ps-3" id="beiPasteErrorList"></ul>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-primary" id="beiParseBtn" <?= empty($accounts['ok']) ? 'disabled' : '' ?>>
                <i class="bi bi-clipboard-data me-1"></i> Preview
            </button>
            <button type="button" class="btn btn-outline-secondary" id="beiPasteClearBtn">Clear</button>
        </div>
    </div>

    <div id="beiReviewStep" class="d-none">
        <form id="beiReviewForm" data-dirty-track>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="beiBackPasteBtn">
                <i class="bi bi-arrow-left"></i> Back to CSV
            </button>
            <span class="small text-muted" id="beiQueueSummary"></span>
            <div class="ms-auto d-flex gap-2">
                <button type="button" class="btn btn-sm btn-primary" id="beiConfirmBtn">
                    <i class="bi bi-check2-circle me-1"></i> Confirm / Import
                </button>
            </div>
        </div>
        <div class="small text-muted mb-2" id="beiAccountSummary"></div>
        <div class="bei-preview border rounded bg-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Check</th>
                            <th>Bank</th>
                            <th class="text-end">Amount</th>
                            <th>FMB Checking</th>
                            <th>Imbalance</th>
                        </tr>
                    </thead>
                    <tbody id="beiPreviewBody"></tbody>
                </table>
            </div>
        </div>
        </form>
    </div>

    <div id="beiDoneStep" class="d-none">
        <div class="alert alert-success" id="beiDoneMsg"></div>
        <button type="button" class="btn btn-primary" onclick="loadPage('ledger')">Open Ledger</button>
        <button type="button" class="btn btn-outline-secondary" id="beiStartOverBtn">Import more</button>
    </div>
</div>

<script type="text/plain" id="init-ledger-bank-export-script">
(function () {
    // TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
    const PAGE = 'pages/ledger_bank_export.php';
    let queue = [];
    let accounts = <?= json_encode([
        'checking' => $accounts['checking'] ?? null,
        'imbalance' => $accounts['imbalance'] ?? null,
    ], JSON_UNESCAPED_UNICODE) ?>;

    const pasteStep = document.getElementById('beiPasteStep');
    const reviewStep = document.getElementById('beiReviewStep');
    const doneStep = document.getElementById('beiDoneStep');
    const pasteEl = document.getElementById('beiPaste');
    const fileEl = document.getElementById('beiFile');

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
    function showStep(name) {
        pasteStep.classList.toggle('d-none', name !== 'paste');
        reviewStep.classList.toggle('d-none', name !== 'review');
        doneStep.classList.toggle('d-none', name !== 'done');
    }
    function setPasteErrors(msgs) {
        const box = document.getElementById('beiPasteErrors');
        const list = document.getElementById('beiPasteErrorList');
        const arr = (msgs || []).filter(Boolean);
        if (!arr.length) {
            box.classList.add('d-none');
            list.innerHTML = '';
            return;
        }
        list.innerHTML = arr.map(m => '<li>' + esc(m) + '</li>').join('');
        box.classList.remove('d-none');
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
    function dirLabel(d) {
        return d === 'credit' ? 'Credit (in)' : 'Debit (out)';
    }
    function typeLabel(t) {
        return t === 'debit' ? 'Debit' : 'Credit';
    }
    function renderPreview() {
        const body = document.getElementById('beiPreviewBody');
        let credits = 0, creditAmt = 0, debits = 0, debitAmt = 0;
        body.innerHTML = queue.map(it => {
            if (it.bank_direction === 'credit') { credits++; creditAmt += Number(it.amount) || 0; }
            else { debits++; debitAmt += Number(it.amount) || 0; }
            return '<tr>'
                + '<td>' + esc(it.transaction_date) + '</td>'
                + '<td>' + esc(it.pay_to || it.description || '') + '</td>'
                + '<td>' + esc(it.check_number || '—') + '</td>'
                + '<td>' + esc(dirLabel(it.bank_direction)) + '</td>'
                + '<td class="bei-amt">$' + money(it.amount) + '</td>'
                + '<td>' + esc(typeLabel(it.checking_type)) + '</td>'
                + '<td>' + esc(typeLabel(it.imbalance_type)) + '</td>'
                + '</tr>';
        }).join('');
        document.getElementById('beiQueueSummary').textContent =
            queue.length + ' transaction' + (queue.length === 1 ? '' : 's')
            + ' · ' + credits + ' credit / ' + debits + ' debit'
            + ' · in $' + money(creditAmt) + ' · out $' + money(debitAmt);
        const chk = accounts.checking || {};
        const imb = accounts.imbalance || {};
        document.getElementById('beiAccountSummary').textContent =
            'Leg 1: ' + (chk.name || 'FMB: Checking Account')
            + (chk.id ? ' (#' + chk.id + ')' : '')
            + ' · Leg 2: ' + (imb.name || 'Imbalance')
            + (imb.id ? ' (#' + imb.id + ')' : '')
            + ' · Funds and Reference # left blank.';
        document.getElementById('beiConfirmBtn').disabled = queue.length === 0;
    }

    if (fileEl) {
        fileEl.addEventListener('change', () => {
            const f = fileEl.files && fileEl.files[0];
            if (!f) return;
            const reader = new FileReader();
            reader.onload = () => {
                pasteEl.value = String(reader.result || '');
                toast('Loaded ' + f.name + '.', 'info');
            };
            reader.onerror = () => toast('Could not read that file.', 'danger');
            reader.readAsText(f);
        });
    }

    document.getElementById('beiParseBtn').addEventListener('click', async () => {
        const csv = String(pasteEl.value || '');
        setPasteErrors([]);
        const btn = document.getElementById('beiParseBtn');
        btn.disabled = true;
        try {
            const data = await api({ action: 'parse', csv: csv });
            if (!data.success) {
                setPasteErrors(data.errors && data.errors.length ? data.errors : [data.error || 'Parse failed.']);
                toast(data.error || 'Parse failed.', 'danger');
                return;
            }
            queue = data.items || [];
            if (data.accounts) accounts = data.accounts;
            renderPreview();
            showStep('review');
            toast(queue.length + ' row' + (queue.length === 1 ? '' : 's') + ' ready to import.', 'success');
        } catch (e) {
            toast(e.message || 'Parse failed.', 'danger');
        } finally {
            btn.disabled = false;
        }
    });

    document.getElementById('beiPasteClearBtn').addEventListener('click', () => {
        pasteEl.value = '';
        if (fileEl) fileEl.value = '';
        setPasteErrors([]);
    });

    document.getElementById('beiBackPasteBtn').addEventListener('click', () => {
        showStep('paste');
    });

    document.getElementById('beiConfirmBtn').addEventListener('click', async () => {
        if (!queue.length) return;
        if (!window.confirm('Import ' + queue.length + ' transaction(s) against FMB: Checking Account and Imbalance? Funds and Reference # will be left blank.')) {
            return;
        }
        const btn = document.getElementById('beiConfirmBtn');
        btn.disabled = true;
        try {
            const data = await api({ action: 'import', items: queue });
            if (!data.success) {
                toast(data.error || 'Import failed.', 'danger');
                btn.disabled = false;
                return;
            }
            const n = data.imported_count || (data.imported || []).length;
            document.getElementById('beiDoneMsg').textContent =
                'Imported ' + n + ' transaction' + (n === 1 ? '' : 's') + ' as pending. Assign funds and Reference # later in the Ledger.';
            if (typeof window.TemperDirtyForms !== 'undefined') window.TemperDirtyForms.markClean();
            showStep('done');
            toast('Imported ' + n + ' transaction' + (n === 1 ? '' : 's') + '.', 'success');
        } catch (e) {
            toast(e.message || 'Import failed.', 'danger');
            btn.disabled = false;
        }
    });

    document.getElementById('beiStartOverBtn').addEventListener('click', () => {
        queue = [];
        pasteEl.value = '';
        if (fileEl) fileEl.value = '';
        setPasteErrors([]);
        showStep('paste');
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-ledger-bank-export-script');if(s){(new Function(s.textContent))();}this.remove();">

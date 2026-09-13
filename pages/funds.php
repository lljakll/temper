<?php
    // Funds - read-only period totals with expandable transaction detail

require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/budget_utils.php';
require_once __DIR__ . '/../includes/fund_utils.php';

    $defaultPeriod = budgetCurrentPeriodDates($db);
    $defaultFrom = $defaultPeriod['start_date'];
    $defaultTo = $defaultPeriod['end_date'];

    $parseDate = static function (string $raw, string $fallback): string {
        $raw = trim($raw);
        return budgetValidIsoDate($raw) ? $raw : $fallback;
    };

    $dateFrom = $parseDate((string)($_GET['date_from'] ?? ''), $defaultFrom);
    $dateTo = $parseDate((string)($_GET['date_to'] ?? ''), $defaultTo);
    if ($dateFrom > $dateTo) {
        $tmp = $dateFrom;
        $dateFrom = $dateTo;
        $dateTo = $tmp;
    }

    $buildPayload = static function () use ($db, $dateFrom, $dateTo, $defaultPeriod, $defaultFrom, $defaultTo): array {
        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'default_from' => $defaultFrom,
            'default_to' => $defaultTo,
            'period_source' => $defaultPeriod['source'],
            'budget_name' => $defaultPeriod['budget_name'],
            'funds' => fundBuildPeriodSummaries($db, $dateFrom, $dateTo, false),
        ];
    };

    if (isset($_GET['fund_txns'])) {
        header('Content-Type: application/json');
        $fundId = (int)$_GET['fund_txns'];
        if ($fundId <= 0) {
            echo json_encode(['error' => 'Invalid fund']);
            exit;
        }
        try {
            echo json_encode([
                'fund_id' => $fundId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'rows' => fundPeriodTransactionRows($db, $fundId, $dateFrom, $dateTo),
            ]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if (isset($_GET['list'])) {
        header('Content-Type: application/json');
        try {
            echo json_encode($buildPayload());
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    $pageData = $buildPayload();
    $periodHint = $pageData['budget_name']
        ? 'Default period is the current budget (' . $pageData['budget_name'] . ').'
        : 'Default period is the current calendar year (no current budget).';
?>
<style>
    .funds-page .funds-activity {
        font-variant-numeric: tabular-nums;
        font-family: var(--bs-font-monospace);
        font-size: 0.86rem;
        white-space: nowrap;
    }
    .funds-page .funds-activity .is-in { color: var(--bs-success); }
    .funds-page .funds-activity .is-out { color: var(--bs-danger); }
    .funds-page .funds-activity .is-bal { color: var(--bs-body-color); font-weight: 600; }
    .funds-page .funds-notes {
        font-size: 0.8rem;
        color: var(--bs-secondary-color);
        max-width: 28rem;
    }
    .funds-page .funds-expand-btn {
        border: 0;
        background: transparent;
        color: inherit;
        padding: 0;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        text-align: left;
    }
    .funds-page .funds-expand-btn:focus-visible {
        outline: 2px solid rgba(var(--bs-primary-rgb), 0.55);
        outline-offset: 2px;
        border-radius: 0.25rem;
    }
    .funds-page .funds-row { cursor: pointer; }
    .funds-page .funds-row.is-open { background-color: rgba(var(--bs-primary-rgb), 0.06); }
    .funds-page .funds-detail-row td {
        background: var(--bs-tertiary-bg);
        padding: 0.65rem 0.85rem 0.9rem;
    }
    .funds-page .funds-detail-table {
        font-size: 0.85rem;
        margin: 0;
    }
    .funds-page .funds-detail-empty {
        color: var(--bs-secondary-color);
        font-size: 0.85rem;
        padding: 0.35rem 0;
    }
    .funds-page .funds-dir-in { color: var(--bs-success); font-weight: 600; }
    .funds-page .funds-dir-out { color: var(--bs-danger); font-weight: 600; }
    .funds-card-list { display: none; }
    .funds-filter-flyout,
    .funds-filter-backdrop,
    .funds-filter-flyout-head { display: none; }
    .funds-period-desktop .form-label { font-size: 0.75rem; color: var(--bs-secondary-color); }
    @media (max-width: 767.98px) {
        .funds-desktop-table { display: none !important; }
        .funds-card-list {
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
        }
        .funds-card {
            width: 100%;
            margin: 0;
            padding: 0.75rem 0.85rem;
            border: 1px solid var(--bs-border-color);
            border-radius: 0.7rem;
            background: var(--bs-body-bg);
            box-shadow: 0 0.08rem 0.35rem rgba(0, 0, 0, 0.05);
            color: inherit;
            text-align: left;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
        .funds-card.is-open {
            background-color: rgba(var(--bs-primary-rgb), 0.08);
            border-color: rgba(var(--bs-primary-rgb), 0.35);
        }
        .funds-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.6rem;
        }
        .funds-card-name { font-weight: 600; line-height: 1.25; }
        .funds-card-type {
            font-size: 0.78rem;
            color: var(--bs-secondary-color);
            margin-top: 0.1rem;
        }
        .funds-card-notes {
            font-size: 0.8rem;
            color: var(--bs-secondary-color);
            margin-top: 0.25rem;
        }
        .funds-card-start {
            font-size: 0.78rem;
            color: var(--bs-secondary-color);
            margin-top: 0.45rem;
        }
        .funds-card-activity {
            margin-top: 0.2rem;
            font-variant-numeric: tabular-nums;
            font-family: var(--bs-font-monospace);
            font-size: 0.8rem;
            line-height: 1.4;
        }
        .funds-card-activity .funds-activity {
            white-space: normal;
            word-break: break-word;
            font-size: inherit;
            line-height: inherit;
        }
        .funds-card-chevron { color: var(--bs-secondary-color); flex: 0 0 auto; }
        .funds-card-detail {
            margin-top: 0.65rem;
            padding-top: 0.55rem;
            border-top: 1px solid var(--bs-border-color);
        }
        .funds-txn {
            display: flex;
            justify-content: space-between;
            gap: 0.6rem;
            padding: 0.4rem 0;
            border-bottom: 1px solid var(--bs-border-color-translucent);
        }
        .funds-txn:last-child { border-bottom: 0; padding-bottom: 0; }
        .funds-txn-main { min-width: 0; }
        .funds-txn-date { font-size: 0.75rem; color: var(--bs-secondary-color); font-weight: 600; }
        .funds-txn-payee { font-weight: 600; font-size: 0.9rem; }
        .funds-txn-ref { font-size: 0.75rem; color: var(--bs-secondary-color); font-family: var(--bs-font-monospace); }
        .funds-txn-amt { text-align: right; flex: 0 0 auto; font-variant-numeric: tabular-nums; }
        .funds-filter-backdrop {
            display: block;
            position: fixed;
            inset: 0;
            z-index: 1035;
            background: rgba(0, 0, 0, 0.35);
        }
        .funds-filter-backdrop[hidden],
        .funds-filter-flyout[hidden] { display: none !important; }
        .funds-filter-flyout {
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
            position: fixed;
            top: 3.7rem;
            right: 0.5rem;
            left: auto;
            width: min(20rem, calc(100vw - 1rem));
            max-height: calc(100dvh - 8.5rem - env(safe-area-inset-bottom, 0px));
            overflow: auto;
            margin: 0;
            padding: 0.75rem;
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 0.75rem;
            box-shadow: 0 0.45rem 1.4rem rgba(0, 0, 0, 0.18);
            z-index: 1040;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transform: translateY(-0.35rem);
            transition: opacity 0.15s ease, transform 0.15s ease, visibility 0.15s;
        }
        .funds-page.funds-filter-open .funds-filter-flyout {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
            transform: none;
        }
        .funds-filter-flyout-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            padding-bottom: 0.15rem;
            margin-bottom: 0.15rem;
            border-bottom: 1px solid var(--bs-border-color);
        }
    }
</style>

<div class="container-fluid mt-2 mt-md-4 px-0 px-sm-2 funds-page" id="fundsPage">
    <div id="fundsMobileHeaderTools" hidden>
        <button type="button" id="fundsFilterFlyoutBtn" class="btn btn-outline-secondary btn-sm px-2"
                aria-expanded="false" aria-controls="fundsFilterFlyout" title="Period">
            <i class="bi bi-funnel" aria-hidden="true"></i>
            <span class="visually-hidden">Period</span>
        </button>
    </div>
    <div id="fundsFilterBackdrop" class="funds-filter-backdrop d-md-none" hidden></div>
    <div id="fundsFilterFlyout" class="funds-filter-flyout d-md-none" hidden>
        <div class="funds-filter-flyout-head">
            <strong>Period</strong>
            <button type="button" class="btn-close" id="fundsFilterFlyoutClose" aria-label="Close period filter"></button>
        </div>
        <div>
            <label class="form-label small mb-1" for="fundsDateFromMobile">From</label>
            <input type="date" class="form-control form-control-sm" id="fundsDateFromMobile" autocomplete="off">
        </div>
        <div>
            <label class="form-label small mb-1" for="fundsDateToMobile">To</label>
            <input type="date" class="form-control form-control-sm" id="fundsDateToMobile" autocomplete="off">
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="fundsApplyMobile">Apply</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="fundsResetMobile">Current budget dates</button>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3 page-title-row">
        <div>
            <h2 class="mb-1">Funds</h2>
            <p class="text-muted small mb-0" id="fundsPeriodHint"><?= htmlspecialchars($periodHint) ?> Asset-account fund tags are ignored. Fund records are maintained in Setup / Lookups.</p>
        </div>
        <form class="d-none d-md-flex flex-wrap align-items-end gap-2 funds-period-desktop" id="fundsPeriodForm">
            <div>
                <label class="form-label mb-1" for="fundsDateFrom">From</label>
                <input type="date" class="form-control form-control-sm" id="fundsDateFrom" autocomplete="off">
            </div>
            <div>
                <label class="form-label mb-1" for="fundsDateTo">To</label>
                <input type="date" class="form-control form-control-sm" id="fundsDateTo" autocomplete="off">
            </div>
            <button type="submit" class="btn btn-sm btn-primary">Apply</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="fundsResetDesktop">Current budget</button>
        </form>
    </div>

    <div class="table-responsive funds-desktop-table d-none d-md-block">
        <table class="table table-hover align-middle mb-0" id="fundsTable">
            <thead class="table-dark">
                <tr>
                    <th>Fund</th>
                    <th>Type</th>
                    <th class="text-end">Starting</th>
                    <th>Period activity</th>
                    <th class="text-end">Current</th>
                </tr>
            </thead>
            <tbody id="fundsTableBody"></tbody>
        </table>
    </div>
    <div id="fundsCardList" class="funds-card-list" aria-label="Funds"></div>
</div>

<script type="application/json" id="funds-page-data"><?= json_encode($pageData, JSON_UNESCAPED_UNICODE) ?></script>
<script type="text/plain" id="init-funds-view-script">
(function() {
    const pageRoot = document.getElementById('fundsPage');
    const dataEl = document.getElementById('funds-page-data');
    let state = { funds: [], date_from: '', date_to: '', default_from: '', default_to: '', budget_name: null, period_source: 'calendar' };
    try {
        state = JSON.parse(dataEl ? dataEl.textContent : '{}') || state;
    } catch (e) { /* keep defaults */ }

    const tableBody = document.getElementById('fundsTableBody');
    const cardList = document.getElementById('fundsCardList');
    const dateFromInput = document.getElementById('fundsDateFrom');
    const dateToInput = document.getElementById('fundsDateTo');
    const dateFromMobile = document.getElementById('fundsDateFromMobile');
    const dateToMobile = document.getElementById('fundsDateToMobile');
    const filterBtn = document.getElementById('fundsFilterFlyoutBtn');
    const filterClose = document.getElementById('fundsFilterFlyoutClose');
    const filterBackdrop = document.getElementById('fundsFilterBackdrop');
    const filterFlyout = document.getElementById('fundsFilterFlyout');
    const txnCache = {};
    let expandedId = 0;

    function isMobile() {
        return window.matchMedia('(max-width: 767.98px)').matches;
    }
    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function fmtMoney(n) {
        const v = parseFloat(n) || 0;
        const neg = v < 0;
        return (neg ? '-' : '') + '$' + Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fmtNum(n) {
        return (parseFloat(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fmtDate(iso) {
        const raw = String(iso || '');
        if (!raw) return '';
        const ts = Date.parse(raw + 'T00:00:00');
        if (Number.isNaN(ts)) return raw;
        return new Date(ts).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    function activityHtml(f) {
        const n = Number(f.txn_count) || 0;
        const txn = n === 1 ? '1 TXN' : n + ' TXNs';
        return '<span class="funds-activity">'
            + escHtml(txn)
            + ' | <span class="is-in">+' + fmtNum(f.inflows) + '</span>'
            + ' | <span class="is-out">−' + fmtNum(f.outflows) + '</span>'
            + ' | <span class="is-bal">Bal ' + fmtNum(f.current_balance) + '</span>'
            + '</span>';
    }
    function notesText(f) {
        return String(f.notes || f.description || f.purpose || '').trim();
    }
    function typeBadge(f) {
        const cls = f.type === 'WDR' ? 'bg-success' : 'bg-primary';
        return '<span class="badge ' + cls + '">' + escHtml(f.type || '') + '</span>';
    }
    function cacheKey(fundId) {
        return String(fundId) + '|' + state.date_from + '|' + state.date_to;
    }

    function directionCell(row) {
        if (row.direction === 'both') {
            return '<div class="funds-dir-in">+' + fmtNum(row.inflow) + ' In</div>'
                + '<div class="funds-dir-out">−' + fmtNum(row.outflow) + ' Out</div>';
        }
        if (row.direction === 'out') {
            return '<span class="funds-dir-out">−' + fmtNum(row.outflow || row.amount) + ' Out</span>';
        }
        return '<span class="funds-dir-in">+' + fmtNum(row.inflow || row.amount) + ' In</span>';
    }

    function detailTableHtml(rows) {
        if (!rows || !rows.length) {
            return '<div class="funds-detail-empty">No transactions assigned to this fund in the selected period.</div>';
        }
        let body = '';
        rows.forEach(function(r) {
            body += '<tr>'
                + '<td>' + escHtml(fmtDate(r.date)) + '</td>'
                + '<td class="font-monospace">' + escHtml(r.ref || '—') + '</td>'
                + '<td>' + escHtml(r.pay_to || '—') + '</td>'
                + '<td class="text-end">' + directionCell(r) + '</td>'
                + '</tr>';
        });
        return '<div class="table-responsive"><table class="table table-sm funds-detail-table">'
            + '<thead><tr><th>Date</th><th>Ref</th><th>Payee / description</th><th class="text-end">Amount</th></tr></thead>'
            + '<tbody>' + body + '</tbody></table></div>';
    }

    function detailCardsHtml(rows) {
        if (!rows || !rows.length) {
            return '<div class="funds-detail-empty">No transactions assigned to this fund in the selected period.</div>';
        }
        return rows.map(function(r) {
            return '<div class="funds-txn">'
                + '<div class="funds-txn-main">'
                + '<div class="funds-txn-date">' + escHtml(fmtDate(r.date)) + '</div>'
                + '<div class="funds-txn-payee">' + escHtml(r.pay_to || '—') + '</div>'
                + '<div class="funds-txn-ref">' + escHtml(r.ref || '—') + '</div>'
                + '</div>'
                + '<div class="funds-txn-amt">' + directionCell(r) + '</div>'
                + '</div>';
        }).join('');
    }

    function renderList() {
        if (!tableBody || !cardList) return;
        const funds = state.funds || [];
        if (!funds.length) {
            tableBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No funds found.</td></tr>';
            cardList.innerHTML = '<div class="text-muted text-center py-4">No funds found.</div>';
            return;
        }
        let tableHtml = '';
        let cardsHtml = '';
        funds.forEach(function(f) {
            const open = expandedId === f.id;
            const notes = notesText(f);
            const chev = open ? 'bi-chevron-down' : 'bi-chevron-right';
            tableHtml += '<tr class="funds-row' + (open ? ' is-open' : '') + '" data-fund-id="' + f.id + '" role="button" tabindex="0" aria-expanded="' + (open ? 'true' : 'false') + '">'
                + '<td><span class="funds-expand-btn">'
                + '<i class="bi ' + chev + '" aria-hidden="true"></i><span class="fw-semibold">' + escHtml(f.name) + '</span>'
                + '</span>'
                + (notes ? '<div class="funds-notes mt-1">' + escHtml(notes) + '</div>' : '')
                + '</td>'
                + '<td>' + typeBadge(f) + ' <span class="small text-muted">' + escHtml((f.type_label || '').replace(/^WDR — |^WODR — /, '')) + '</span></td>'
                + '<td class="text-end font-monospace">' + fmtMoney(f.starting_balance) + '</td>'
                + '<td>' + activityHtml(f) + '</td>'
                + '<td class="text-end font-monospace fw-semibold">' + fmtMoney(f.current_balance) + '</td>'
                + '</tr>';
            if (open) {
                tableHtml += '<tr class="funds-detail-row" data-detail-for="' + f.id + '"><td colspan="5"><div class="funds-detail-slot" data-fund-id="' + f.id + '">Loading…</div></td></tr>';
            }
            cardsHtml += '<article class="funds-card' + (open ? ' is-open' : '') + '" data-fund-id="' + f.id + '" role="button" tabindex="0" aria-expanded="' + (open ? 'true' : 'false') + '">'
                + '<div class="funds-card-top">'
                + '<div class="min-w-0">'
                + '<div class="funds-card-name">' + escHtml(f.name) + '</div>'
                + '<div class="funds-card-type">' + escHtml(f.type_label || f.type || '') + '</div>'
                + (notes ? '<div class="funds-card-notes">' + escHtml(notes) + '</div>' : '')
                + '</div>'
                + '<i class="bi ' + chev + ' funds-card-chevron" aria-hidden="true"></i>'
                + '</div>'
                + '<div class="funds-card-start">Starting ' + fmtMoney(f.starting_balance) + '</div>'
                + '<div class="funds-card-activity">' + activityHtml(f) + '</div>'
                + (open ? '<div class="funds-card-detail funds-detail-slot" data-fund-id="' + f.id + '">Loading…</div>' : '')
                + '</article>';
        });
        tableBody.innerHTML = tableHtml;
        cardList.innerHTML = cardsHtml;
        if (expandedId) {
            fillExpanded(expandedId);
        }
    }

    function fillExpanded(fundId) {
        const key = cacheKey(fundId);
        const slots = pageRoot ? pageRoot.querySelectorAll('.funds-detail-slot[data-fund-id="' + fundId + '"]') : [];
        const apply = function(rows) {
            slots.forEach(function(slot) {
                const inCard = slot.classList.contains('funds-card-detail');
                slot.innerHTML = inCard ? detailCardsHtml(rows) : detailTableHtml(rows);
            });
        };
        if (Object.prototype.hasOwnProperty.call(txnCache, key)) {
            apply(txnCache[key]);
            return;
        }
        const qs = new URLSearchParams({
            fund_txns: String(fundId),
            date_from: state.date_from,
            date_to: state.date_to
        });
        fetch('pages/funds.php?' + qs.toString())
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.error) {
                    if (typeof showToast === 'function') showToast(data.error, 'danger');
                    apply([]);
                    return;
                }
                txnCache[key] = (data && data.rows) ? data.rows : [];
                apply(txnCache[key]);
            })
            .catch(function(err) {
                if (typeof showToast === 'function') showToast('Could not load transactions: ' + err.message, 'danger');
                apply([]);
            });
    }

    function toggleFund(fundId) {
        expandedId = expandedId === fundId ? 0 : fundId;
        renderList();
    }

    function syncDateInputs() {
        [dateFromInput, dateFromMobile].forEach(function(el) { if (el) el.value = state.date_from; });
        [dateToInput, dateToMobile].forEach(function(el) { if (el) el.value = state.date_to; });
    }

    function closeFilterFlyout() {
        if (pageRoot) pageRoot.classList.remove('funds-filter-open');
        if (filterBtn) {
            filterBtn.setAttribute('aria-expanded', 'false');
            filterBtn.classList.remove('active');
        }
        if (filterBackdrop) filterBackdrop.hidden = true;
        if (filterFlyout) filterFlyout.hidden = true;
    }
    function openFilterFlyout() {
        if (!pageRoot) return;
        pageRoot.classList.add('funds-filter-open');
        if (filterBtn) {
            filterBtn.setAttribute('aria-expanded', 'true');
            filterBtn.classList.add('active');
        }
        if (filterBackdrop) filterBackdrop.hidden = false;
        if (filterFlyout) filterFlyout.hidden = false;
    }

    function applyPeriod(fromVal, toVal) {
        let from = String(fromVal || '').trim();
        let to = String(toVal || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to)) {
            if (typeof showToast === 'function') showToast('Enter a valid From and To date.', 'warning');
            return;
        }
        if (from > to) {
            const tmp = from; from = to; to = tmp;
        }
        const qs = new URLSearchParams({ list: '1', date_from: from, date_to: to });
        fetch('pages/funds.php?' + qs.toString())
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.error) {
                    if (typeof showToast === 'function') showToast(data.error, 'danger');
                    return;
                }
                state = data;
                expandedId = 0;
                Object.keys(txnCache).forEach(function(k) { delete txnCache[k]; });
                syncDateInputs();
                renderList();
                closeFilterFlyout();
            })
            .catch(function(err) {
                if (typeof showToast === 'function') showToast('Could not load funds: ' + err.message, 'danger');
            });
    }

    function resetPeriod() {
        applyPeriod(state.default_from, state.default_to);
    }

    function onListClick(e) {
        if (e.target.closest('.funds-detail-slot, .funds-card-detail, .funds-detail-row')) return;
        const row = e.target.closest('.funds-row, .funds-card');
        if (!row || !pageRoot.contains(row)) return;
        const id = parseInt(row.getAttribute('data-fund-id'), 10);
        if (id) toggleFund(id);
    }
    function onListKey(e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        if (e.target.closest('.funds-detail-slot, .funds-card-detail, .funds-detail-row')) return;
        const row = e.target.closest('.funds-row, .funds-card');
        if (!row || !pageRoot.contains(row)) return;
        e.preventDefault();
        const id = parseInt(row.getAttribute('data-fund-id'), 10);
        if (id) toggleFund(id);
    }

    function mountMobileTools() {
        const slot = document.getElementById('mobileTopbarEnd');
        const tools = document.getElementById('fundsMobileHeaderTools');
        if (!slot || !tools) return;
        while (tools.firstChild) slot.appendChild(tools.firstChild);
    }
    function unmountMobileTools() {
        const slot = document.getElementById('mobileTopbarEnd');
        const tools = document.getElementById('fundsMobileHeaderTools');
        if (!slot) return;
        if (tools) {
            while (slot.firstChild) tools.appendChild(slot.firstChild);
        } else {
            slot.replaceChildren();
        }
    }

    function onFilterKey(e) {
        if (e.key === 'Escape') closeFilterFlyout();
    }
    function onChromeResize() {
        if (!isMobile()) closeFilterFlyout();
    }

    if (pageRoot) {
        pageRoot.addEventListener('click', onListClick);
        pageRoot.addEventListener('keydown', onListKey);
    }
    const periodForm = document.getElementById('fundsPeriodForm');
    if (periodForm) {
        periodForm.addEventListener('submit', function(e) {
            e.preventDefault();
            applyPeriod(dateFromInput && dateFromInput.value, dateToInput && dateToInput.value);
        });
    }
    const resetDesktop = document.getElementById('fundsResetDesktop');
    if (resetDesktop) resetDesktop.addEventListener('click', resetPeriod);
    const applyMobile = document.getElementById('fundsApplyMobile');
    if (applyMobile) {
        applyMobile.addEventListener('click', function() {
            applyPeriod(dateFromMobile && dateFromMobile.value, dateToMobile && dateToMobile.value);
        });
    }
    const resetMobile = document.getElementById('fundsResetMobile');
    if (resetMobile) resetMobile.addEventListener('click', resetPeriod);
    if (filterBtn) {
        filterBtn.addEventListener('click', function() {
            if (pageRoot && pageRoot.classList.contains('funds-filter-open')) closeFilterFlyout();
            else openFilterFlyout();
        });
    }
    if (filterClose) filterClose.addEventListener('click', closeFilterFlyout);
    if (filterBackdrop) filterBackdrop.addEventListener('click', closeFilterFlyout);
    document.addEventListener('keydown', onFilterKey);
    window.addEventListener('resize', onChromeResize);

    function disposeFundsPage() {
        document.removeEventListener('keydown', onFilterKey);
        window.removeEventListener('resize', onChromeResize);
        closeFilterFlyout();
        unmountMobileTools();
        if (window.TemperFundsPage && window.TemperFundsPage._bound === disposeFundsPage) {
            window.TemperFundsPage = { disposeActive: function() {} };
        }
    }
    mountMobileTools();
    window.TemperFundsPage = {
        _bound: disposeFundsPage,
        disposeActive: disposeFundsPage
    };

    syncDateInputs();
    renderList();
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-funds-view-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>

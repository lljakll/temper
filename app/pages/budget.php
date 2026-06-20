<?php
    // Budget Page - Inner content only for AJAX loading

    if (!isset($db)) {
        require_once __DIR__ . '/../config.php';
        $db = getDbConnection();
    }

    if (isset($_GET['get_budget'])) {
        $id = (int)$_GET['get_budget'];
        header('Content-Type: application/json');
        if ($id <= 0) { echo json_encode(['error' => 'Invalid ID']); exit; }
        $stmt = $db->prepare("SELECT id, fiscal_year, name, start_date, end_date, approved_date, reference_number, status, description FROM budgets WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $budget = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$budget) { echo json_encode(['error' => 'Budget not found']); exit; }
        $lst = $db->prepare("SELECT id, natural_category_id, functional_category_id, account_id, budgeted_amount, notes FROM budget_lines WHERE budget_id = ? ORDER BY id");
        $lst->bind_param('i', $id);
        $lst->execute();
        $lines = [];
        $res = $lst->get_result();
        while ($l = $res->fetch_assoc()) {
            $lines[] = [
                'id' => (int)$l['id'],
                'natural_category_id' => $l['natural_category_id'] ? (int)$l['natural_category_id'] : '',
                'functional_category_id' => $l['functional_category_id'] ? (int)$l['functional_category_id'] : '',
                'account_id' => $l['account_id'] ? (int)$l['account_id'] : '',
                'budgeted_amount' => $l['budgeted_amount'],
                'notes' => $l['notes'] ?? ''
            ];
        }
        $lst->close();
        $budget['lines'] = $lines;
        echo json_encode($budget);
        exit;
    }

    if (isset($_GET['cycle_data'])) {
        header('Content-Type: application/json');
        $active = $db->query("SELECT id, name, fiscal_year, start_date, end_date FROM budgets WHERE status = 'active' LIMIT 1")->fetch_assoc() ?: null;
        $approved = [];
        $r = $db->query("SELECT id, name, fiscal_year, start_date, end_date, reference_number, approved_date FROM budgets WHERE status = 'approved' ORDER BY fiscal_year DESC, name");
        while ($row = $r->fetch_assoc()) $approved[] = $row;
        echo json_encode(['active' => $active, 'approved' => $approved]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $chk = $db->prepare("SELECT status FROM budgets WHERE id = ?");
                $chk->bind_param('i', $id);
                $chk->execute();
                $row = $chk->get_result()->fetch_assoc();
                $chk->close();
                if ($row && $row['status'] === 'draft') {
                    $stmt = $db->prepare("DELETE FROM budgets WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        } elseif ($action === 'save_notes') {
            $id = (int)($_POST['budget_id'] ?? 0);
            $chk = $db->prepare("SELECT status FROM budgets WHERE id = ?");
            $chk->bind_param('i', $id);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($existing && $existing['status'] === 'approved') {
                $desc = trim($_POST['description'] ?? '');
                $lines = json_decode($_POST['lines_json'] ?? '[]', true) ?: [];
                $stmt = $db->prepare("UPDATE budgets SET description = ? WHERE id = ?");
                $stmt->bind_param('si', $desc, $id);
                $stmt->execute();
                $stmt->close();
                $upd = $db->prepare("UPDATE budget_lines SET notes = ? WHERE id = ? AND budget_id = ?");
                foreach ($lines as $l) {
                    $lid = (int)($l['id'] ?? 0);
                    $notes = trim($l['notes'] ?? '');
                    if ($lid <= 0) continue;
                    $upd->bind_param('sii', $notes, $lid, $id);
                    $upd->execute();
                }
                $upd->close();
            }
        } elseif ($action === 'cycle_budget') {
            header('Content-Type: application/json');
            $promote_id = (int)($_POST['promote_id'] ?? 0);
            $new_start = $_POST['new_start_date'] ?? null;
            $old_end = $_POST['old_end_date'] ?? null;

            if ($promote_id <= 0) {
                echo json_encode(['error' => 'Select an Approved budget to promote.']);
                exit;
            }
            $chk = $db->prepare("SELECT id, status, start_date, reference_number, approved_date FROM budgets WHERE id = ?");
            $chk->bind_param('i', $promote_id);
            $chk->execute();
            $promote = $chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$promote || $promote['status'] !== 'approved') {
                echo json_encode(['error' => 'Only an Approved budget can be promoted to Active.']);
                exit;
            }
            if (empty(trim($promote['reference_number'] ?? '')) || empty($promote['approved_date'])) {
                echo json_encode(['error' => 'Cannot activate: Reference # and Approved Date are required. The Reference # should identify the business meeting minutes where this budget was approved.']);
                exit;
            }

            $active = $db->query("SELECT id, end_date FROM budgets WHERE status = 'active' LIMIT 1")->fetch_assoc();
            if ($active) {
                $approved_count = (int)($db->query("SELECT COUNT(*) FROM budgets WHERE status = 'approved'")->fetch_row()[0] ?? 0);
                if ($approved_count < 1) {
                    echo json_encode(['error' => 'Cannot close the Active budget unless at least one Approved budget is available to promote.']);
                    exit;
                }
                $end = $old_end ?: $active['end_date'];
                $stmt = $db->prepare("UPDATE budgets SET status = 'closed', end_date = ? WHERE id = ?");
                $stmt->bind_param('si', $end, $active['id']);
                $stmt->execute();
                $stmt->close();
            }

            $start = $new_start ?: $promote['start_date'];
            $stmt = $db->prepare("UPDATE budgets SET status = 'active', start_date = ? WHERE id = ?");
            $stmt->bind_param('si', $start, $promote_id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            exit;
        } elseif ($action === 'save') {
            $id = (int)($_POST['budget_id'] ?? 0);
            $canSave = true;
            if ($id > 0) {
                $chk = $db->prepare("SELECT status FROM budgets WHERE id = ?");
                $chk->bind_param('i', $id);
                $chk->execute();
                $existing = $chk->get_result()->fetch_assoc();
                $chk->close();
                $canSave = $existing && $existing['status'] === 'draft';
            }
            if ($canSave) {
                $fy = (int)($_POST['fiscal_year'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $start = $_POST['start_date'] ?? '';
                $end = $_POST['end_date'] ?? '';
                $approved = $_POST['approved_date'] ?: null;
                $ref = trim($_POST['reference_number'] ?? '');
                $status = $_POST['status'] ?? 'draft';
                if (!in_array($status, ['draft', 'approved'], true)) $status = 'draft';
                if ($status === 'approved' && (empty($ref) || empty($approved))) {
                    // Reference # and Approved Date required when approving
                } else {
                $desc = trim($_POST['description'] ?? '');
                $lines = json_decode($_POST['lines_json'] ?? '[]', true) ?: [];
                $total = 0.0;
                foreach ($lines as $l) { $total += (float)($l['budgeted_amount'] ?? 0); }

                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE budgets SET fiscal_year=?, name=?, start_date=?, end_date=?, approved_date=?, reference_number=?, status=?, total_budgeted=?, description=? WHERE id=?");
                    $stmt->bind_param('issssssdsi', $fy, $name, $start, $end, $approved, $ref, $status, $total, $desc, $id);
                    $stmt->execute();
                    $stmt->close();
                    $del = $db->prepare("DELETE FROM budget_lines WHERE budget_id = ?");
                    $del->bind_param('i', $id);
                    $del->execute();
                    $del->close();
                } else {
                    $stmt = $db->prepare("INSERT INTO budgets (fiscal_year, name, start_date, end_date, approved_date, reference_number, status, total_budgeted, description) VALUES (?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param('issssssds', $fy, $name, $start, $end, $approved, $ref, $status, $total, $desc);
                    $stmt->execute();
                    $id = (int)$stmt->insert_id;
                    $stmt->close();
                }
                $ins = $db->prepare("INSERT INTO budget_lines (budget_id, natural_category_id, functional_category_id, account_id, budgeted_amount, notes) VALUES (?,?,?,?,?,?)");
                foreach ($lines as $l) {
                    $nid = !empty($l['natural_category_id']) ? (int)$l['natural_category_id'] : null;
                    $fid = !empty($l['functional_category_id']) ? (int)$l['functional_category_id'] : null;
                    $aid = !empty($l['account_id']) ? (int)$l['account_id'] : null;
                    $amt = (float)($l['budgeted_amount'] ?? 0);
                    $notes = trim($l['notes'] ?? '');
                    if ($amt <= 0) continue;
                    $ins->bind_param('iiiids', $id, $nid, $fid, $aid, $amt, $notes);
                    $ins->execute();
                }
                $ins->close();
                }
            }
        }
    }

    $statusBadges = ['draft' => 'warning', 'approved' => 'info', 'active' => 'success', 'closed' => 'secondary'];
    $lookups = ['accounts' => [], 'natural' => [], 'functional' => []];
    foreach (['accounts' => 'accounts', 'natural' => 'natural_categories', 'functional' => 'functional_categories'] as $k => $tbl) {
        $r = $db->query("SELECT id, name FROM $tbl WHERE archived = FALSE ORDER BY name");
        while ($row = $r->fetch_assoc()) $lookups[$k][] = $row;
    }
    $budgets = $db->query("SELECT id, fiscal_year, name, start_date, end_date, approved_date, reference_number, status, total_budgeted FROM budgets ORDER BY fiscal_year DESC, name");
?>

<style>
    .budget-lines-table-wrap { overflow-x: auto; }
    #linesTable {
        table-layout: fixed;
        width: 100%;
        min-width: 860px;
    }
    #linesTable col.col-cat { width: 18%; }
    #linesTable col.col-amount,
    #linesTable col.col-remaining { width: 108px; }
    #linesTable col.col-notes { width: 22%; }
    #linesTable col.col-actions { width: 42px; }
    #linesTable th,
    #linesTable td {
        overflow: hidden;
        vertical-align: middle;
    }
    #linesTable .line-cell-cat,
    #linesTable .line-cell-amount,
    #linesTable .line-cell-remaining {
        white-space: nowrap;
        text-overflow: ellipsis;
    }
    #linesTable .line-cell-text {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    #linesTable .line-cell-notes {
        max-width: 0;
    }
    #linesTable .line-cell-notes .line-notes,
    #linesTable .line-cell-notes input,
    #linesTable select.form-select-sm,
    #linesTable input.line-amount {
        width: 100%;
        min-width: 0;
        max-width: 100%;
    }
</style>

<div class="container mt-4">
    <h2 class="mb-4">Budget</h2>

    <div class="row mb-3">
        <div class="col text-end">
            <button type="button" id="cycleBtn" class="btn btn-outline-primary me-2"><i class="bi bi-arrow-repeat"></i> Cycle Budget</button>
            <button id="addBtn" class="btn btn-primary me-2">New Budget</button>
            <button id="deleteBtn" class="btn btn-danger" disabled>Delete</button>
        </div>
    </div>

    <div class="table-responsive mb-4">
        <table class="table table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th>Year</th><th>Name</th><th>Period</th><th>Approved</th><th>Reference</th><th>Status</th><th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody id="budgetTableBody">
                <?php if ($budgets && $budgets->num_rows > 0): ?>
                    <?php while ($b = $budgets->fetch_assoc()): ?>
                        <tr data-id="<?= $b['id'] ?>" data-status="<?= htmlspecialchars($b['status']) ?>">
                            <td><?= (int)$b['fiscal_year'] ?></td>
                            <td><?= htmlspecialchars($b['name']) ?></td>
                            <td><?= htmlspecialchars($b['start_date']) ?> – <?= htmlspecialchars($b['end_date']) ?></td>
                            <td><?= htmlspecialchars($b['approved_date'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($b['reference_number'] ?? '') ?></td>
                            <td><span class="badge bg-<?= $statusBadges[$b['status']] ?? 'secondary' ?>"><?= htmlspecialchars($b['status']) ?></span></td>
                            <td class="text-end">$<?= number_format((float)$b['total_budgeted'], 2) ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="text-center text-muted">No budgets yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div id="budgetForm" class="card d-none">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 id="formTitle" class="mb-0">New Budget</h5>
            <span id="modeBadge" class="badge bg-secondary d-none"></span>
        </div>
        <div class="card-body">
            <form id="budgetFormContent" method="POST">
                <input type="hidden" name="action" id="formAction" value="save">
                <input type="hidden" name="budget_id" id="budgetId">
                <input type="hidden" name="lines_json" id="linesJson">

                <div class="row g-3 mb-3">
                    <div class="col-md-2">
                        <label class="form-label">Year</label>
                        <input type="number" class="form-control budget-field" name="fiscal_year" id="fiscalYear" required min="2000" max="2100">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control budget-field" name="name" id="budgetName" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select class="form-select budget-field" name="status" id="budgetStatus">
                            <option value="draft">Draft</option>
                            <option value="approved">Approved</option>
                        </select>
                        <input type="text" class="form-control d-none" id="budgetStatusDisplay" readonly disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Reference # <span class="text-danger">*</span></label>
                        <input type="text" class="form-control budget-field" name="reference_number" id="referenceNumber">
                        <div class="invalid-feedback">Required. Should identify the business meeting minutes where this budget was approved.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control budget-field" name="start_date" id="startDate" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control budget-field" name="end_date" id="endDate" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Approved Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control budget-field" name="approved_date" id="approvedDate">
                        <div class="invalid-feedback">Required when approving a budget.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control budget-field" name="description" id="budgetDesc">
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center py-2">
                        <h6 class="mb-0">Budget Lines</h6>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="addLineBtn"><i class="bi bi-plus"></i> Add Line</button>
                    </div>
                    <div class="card-body p-0">
                        <div class="budget-lines-table-wrap">
                            <table class="table table-sm table-bordered mb-0" id="linesTable">
                                <colgroup>
                                    <col class="col-cat"><col class="col-cat"><col class="col-cat">
                                    <col class="col-amount"><col class="col-remaining">
                                    <col class="col-notes"><col class="col-actions">
                                </colgroup>
                                <thead class="table-light">
                                    <tr>
                                        <th class="line-cell-cat">Natural Category</th>
                                        <th class="line-cell-cat">Functional Category</th>
                                        <th class="line-cell-cat">Account</th>
                                        <th class="text-end line-cell-amount">Amount</th>
                                        <th class="text-end line-cell-remaining">Remaining</th>
                                        <th class="line-cell-notes">Notes</th>
                                        <th class="line-actions"></th>
                                    </tr>
                                </thead>
                                <tbody id="linesBody"></tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3" class="text-end fw-bold">Total</td>
                                        <td class="text-end fw-bold" id="linesTotal">$0.00</td>
                                        <td class="text-end fw-bold text-muted" id="linesRemaining">—</td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="formActions">
                    <button type="submit" class="btn btn-primary" id="saveBtn">Save Budget</button>
                    <button type="button" class="btn btn-secondary" id="cancelBtn">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cycle Budget Modal -->
<div class="modal fade" id="cycleModal" tabindex="-1" aria-labelledby="cycleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cycleModalLabel">Cycle Budget</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="cycleError" class="alert alert-danger d-none"></div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Current Active Budget</label>
                    <div id="cycleActiveInfo" class="p-2 bg-light rounded border text-muted">Loading…</div>
                </div>
                <div class="mb-3">
                    <label for="cyclePromoteSelect" class="form-label fw-semibold">Promote to Active</label>
                    <select class="form-select" id="cyclePromoteSelect">
                        <option value="">— Select Approved budget —</option>
                    </select>
                </div>
                <div id="cycleDateWarning" class="alert alert-warning d-none">
                    <p class="mb-2 small" id="cycleDateWarningText"></p>
                    <div id="cycleDateOverrides" class="row g-2">
                        <div class="col-md-6" id="newStartGroup">
                            <label class="form-label small">New budget start date</label>
                            <input type="date" class="form-control form-control-sm" id="cycleNewStart">
                        </div>
                        <div class="col-md-6" id="oldEndGroup">
                            <label class="form-label small">Current active end date</label>
                            <input type="date" class="form-control form-control-sm" id="cycleOldEnd">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="cycleConfirmBtn" disabled>Activate Selected Budget</button>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="lookups-data"><?= json_encode($lookups) ?></script>
<script type="text/plain" id="init-budget-script">
(function() {
    const page = 'budget';
    const lookups = JSON.parse(document.getElementById('lookups-data').textContent);
    const lookupName = (list, id) => (list.find(o => o.id == id) || {}).name || '—';
    const tableBody = document.getElementById('budgetTableBody');
    const form = document.getElementById('budgetForm');
    const formEl = document.getElementById('budgetFormContent');
    const linesBody = document.getElementById('linesBody');
    const linesTotal = document.getElementById('linesTotal');
    const addBtn = document.getElementById('addBtn');
    const deleteBtn = document.getElementById('deleteBtn');
    const addLineBtn = document.getElementById('addLineBtn');
    const saveBtn = document.getElementById('saveBtn');
    const modeBadge = document.getElementById('modeBadge');
    const refInput = document.getElementById('referenceNumber');
    const approvedDateInput = document.getElementById('approvedDate');
    const cycleModal = new bootstrap.Modal(document.getElementById('cycleModal'));
    let selectedRow = null;
    let originalStatus = 'draft';
    let formMode = 'draft';
    let savedSnapshot = null;
    let cycleData = { active: null, approved: [] };

    function fmt(n) { return '$' + n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function opts(list, val) {
        return '<option value="">—</option>' + list.map(o => `<option value="${o.id}"${o.id == val ? ' selected' : ''}>${o.name}</option>`).join('');
    }
    function withinOneWeek(dateStr) {
        if (!dateStr) return false;
        const d = new Date(dateStr + 'T12:00:00');
        const t = new Date(); t.setHours(12, 0, 0, 0);
        return Math.abs(d - t) <= 7 * 86400000;
    }
    function updateTotal() {
        let t = 0;
        linesBody.querySelectorAll('tr.line-row').forEach(tr => {
            const input = tr.querySelector('.line-amount');
            if (input) t += parseFloat(input.dataset.amount || 0);
            else if (tr.dataset.amount) t += parseFloat(tr.dataset.amount);
        });
        linesTotal.textContent = fmt(t);
    }
    function bindAmountInput(input, amount) {
        const amt = parseFloat(amount) || 0;
        input.dataset.amount = amt;
        input.value = fmt(amt);
        input.addEventListener('input', () => {
            const digits = input.value.replace(/\D/g, '');
            const val = parseInt(digits || '0', 10) / 100;
            input.dataset.amount = val;
            input.value = fmt(val);
            updateTotal();
        });
        input.addEventListener('focus', () => input.select());
    }
    function collectLines() {
        return [...linesBody.querySelectorAll('tr')].map(tr => ({
            id: tr.dataset.lineId || '',
            natural_category_id: tr.querySelector('.line-natural')?.value || '',
            functional_category_id: tr.querySelector('.line-functional')?.value || '',
            account_id: tr.querySelector('.line-account')?.value || '',
            budgeted_amount: tr.querySelector('.line-amount')?.dataset.amount || '0',
            notes: tr.querySelector('.line-notes')?.value ?? tr.querySelector('.line-notes-readonly')?.value ?? ''
        }));
    }
    function getSnapshot() {
        const snap = {
            id: document.getElementById('budgetId').value,
            description: document.getElementById('budgetDesc').value,
            lines: collectLines().map(l => ({ id: l.id, notes: l.notes }))
        };
        if (formMode === 'draft') {
            Object.assign(snap, {
                fiscal_year: document.getElementById('fiscalYear').value,
                name: document.getElementById('budgetName').value,
                start_date: document.getElementById('startDate').value,
                end_date: document.getElementById('endDate').value,
                approved_date: document.getElementById('approvedDate').value,
                reference_number: refInput.value,
                status: document.getElementById('budgetStatus').value,
                lines: collectLines()
            });
        }
        return JSON.stringify(snap);
    }
    function isDirty() {
        return formMode !== 'locked' && savedSnapshot !== null && getSnapshot() !== savedSnapshot;
    }
    function confirmDiscard() {
        if (!isDirty()) return true;
        return confirm('You have unsaved changes. Switching away will discard them unless you save first. Continue?');
    }
    function validateApprovalFields() {
        let ok = true;
        if (!refInput.value.trim()) { refInput.classList.add('is-invalid'); ok = false; }
        else refInput.classList.remove('is-invalid');
        if (!approvedDateInput.value) { approvedDateInput.classList.add('is-invalid'); ok = false; }
        else approvedDateInput.classList.remove('is-invalid');
        if (!ok) {
            alert('Reference # and Approved Date are required.\n\nThe Reference # should identify the business meeting minutes where this budget was approved.');
            (refInput.value.trim() ? approvedDateInput : refInput).focus();
        }
        return ok;
    }
    function confirmApprovedStatus(newStatus) {
        if (newStatus !== 'approved' || newStatus === originalStatus) return true;
        if (!validateApprovalFields()) return false;
        return confirm('Setting status to Approved will lock budget amounts and categories. You may still edit notes. Continue?');
    }
    function addLine(data, mode) {
        data = data || {};
        const tr = document.createElement('tr');
        tr.classList.add('line-row');
        if (data.id) tr.dataset.lineId = data.id;
        const amt = parseFloat(data.budgeted_amount) || 0;
        if (mode === 'draft') {
            tr.innerHTML = `
                <td class="line-cell-cat"><select class="form-select form-select-sm line-natural">${opts(lookups.natural, data.natural_category_id)}</select></td>
                <td class="line-cell-cat"><select class="form-select form-select-sm line-functional">${opts(lookups.functional, data.functional_category_id)}</select></td>
                <td class="line-cell-cat"><select class="form-select form-select-sm line-account">${opts(lookups.accounts, data.account_id)}</select></td>
                <td class="line-cell-amount"><input type="text" class="form-control form-control-sm text-end line-amount" inputmode="numeric"></td>
                <td class="text-end text-muted line-cell-remaining">—</td>
                <td class="line-cell-notes"><input type="text" class="form-control form-control-sm line-notes" value="${data.notes || ''}"></td>
                <td class="line-actions"><button type="button" class="btn btn-sm btn-outline-danger rm-line"><i class="bi bi-x"></i></button></td>`;
            bindAmountInput(tr.querySelector('.line-amount'), data.budgeted_amount);
            tr.querySelector('.rm-line').addEventListener('click', () => { tr.remove(); updateTotal(); });
        } else {
            const nat = lookupName(lookups.natural, data.natural_category_id);
            const fun = lookupName(lookups.functional, data.functional_category_id);
            const acct = lookupName(lookups.accounts, data.account_id);
            const notesVal = data.notes || '';
            const notesCell = mode === 'approved'
                ? `<input type="text" class="form-control form-control-sm line-notes" value="${notesVal.replace(/"/g, '&quot;')}">`
                : `<span class="line-cell-text" title="${notesVal.replace(/"/g, '&quot;')}">${notesVal}</span>`;
            tr.innerHTML = `
                <td class="line-cell-cat"><span class="line-cell-text" title="${nat}">${nat}</span></td>
                <td class="line-cell-cat"><span class="line-cell-text" title="${fun}">${fun}</span></td>
                <td class="line-cell-cat"><span class="line-cell-text" title="${acct}">${acct}</span></td>
                <td class="text-end line-cell-amount">${fmt(amt)}</td>
                <td class="text-end text-muted line-cell-remaining">—</td>
                <td class="line-cell-notes">${notesCell}</td><td class="line-actions"></td>`;
            tr.dataset.amount = amt;
        }
        linesBody.appendChild(tr);
        updateTotal();
    }
    function setFormMode(mode) {
        formMode = mode;
        const labels = { draft: '', approved: 'Notes Only', locked: 'Read Only' };
        modeBadge.textContent = labels[mode] || '';
        modeBadge.className = 'badge ' + (mode === 'approved' ? 'bg-info' : 'bg-secondary') + (mode === 'draft' ? ' d-none' : '');
        const statusSel = document.getElementById('budgetStatus');
        const statusDisp = document.getElementById('budgetStatusDisplay');
        statusSel.classList.toggle('d-none', mode === 'locked');
        statusDisp.classList.toggle('d-none', mode !== 'locked');
        addLineBtn.classList.toggle('d-none', mode !== 'draft');
        saveBtn.classList.toggle('d-none', mode === 'locked');
        saveBtn.textContent = mode === 'approved' ? 'Save Notes' : 'Save Budget';
        document.getElementById('formAction').value = mode === 'approved' ? 'save_notes' : 'save';
        document.querySelectorAll('.line-actions').forEach(el => el.classList.toggle('d-none', mode !== 'draft'));
        formEl.querySelectorAll('.budget-field').forEach(el => {
            if (el.id === 'budgetDesc') el.disabled = mode === 'locked';
            else if (el.id === 'budgetStatus') el.disabled = mode !== 'draft';
            else el.disabled = mode !== 'draft';
        });
    }
    function updateActionButtons() {
        deleteBtn.disabled = !selectedRow || selectedRow.dataset.status !== 'draft';
    }
    function showForm(title) {
        document.getElementById('formTitle').textContent = title;
        form.classList.remove('d-none');
        updateActionButtons();
    }
    function hideForm() {
        form.classList.add('d-none');
        savedSnapshot = null;
        updateActionButtons();
    }
    function reload() {
        fetch(`pages/${page}.php`).then(r => r.text()).then(h => { document.getElementById('main-content').innerHTML = h; });
    }
    function populateForm(b) {
        originalStatus = b.status || 'draft';
        let mode = 'draft';
        if (b.status === 'approved') mode = 'approved';
        else if (b.status === 'active' || b.status === 'closed') mode = 'locked';
        document.getElementById('budgetId').value = b.id || '';
        document.getElementById('fiscalYear').value = b.fiscal_year || '';
        document.getElementById('budgetName').value = b.name || '';
        document.getElementById('startDate').value = b.start_date || '';
        document.getElementById('endDate').value = b.end_date || '';
        document.getElementById('approvedDate').value = b.approved_date || '';
        refInput.value = b.reference_number || '';
        refInput.classList.remove('is-invalid');
        approvedDateInput.classList.remove('is-invalid');
        document.getElementById('budgetStatus').value = (b.status === 'approved') ? 'approved' : 'draft';
        document.getElementById('budgetStatusDisplay').value = b.status ? b.status.charAt(0).toUpperCase() + b.status.slice(1) : '';
        document.getElementById('budgetDesc').value = b.description || '';
        linesBody.innerHTML = '';
        const lines = b.lines?.length ? b.lines : (mode === 'draft' ? [{}] : []);
        lines.forEach(l => addLine(l, mode));
        setFormMode(mode);
        const titles = { draft: b.id ? 'Edit Budget' : 'New Budget', approved: 'View Budget (Notes Editable)', locked: 'View Budget' };
        showForm(titles[mode]);
        savedSnapshot = getSnapshot();
    }
    function openBudget(id) {
        fetch(`pages/${page}.php?get_budget=${id}`).then(r => r.json()).then(b => { if (!b.error) populateForm(b); });
    }

    function updateCycleDateWarning() {
        const sel = document.getElementById('cyclePromoteSelect');
        const promote = cycleData.approved.find(b => b.id == sel.value);
        const warn = document.getElementById('cycleDateWarning');
        const warnText = document.getElementById('cycleDateWarningText');
        const newStartGrp = document.getElementById('newStartGroup');
        const oldEndGrp = document.getElementById('oldEndGroup');
        if (!promote) { warn.classList.add('d-none'); return; }
        const msgs = [];
        const needNewStart = !withinOneWeek(promote.start_date);
        const needOldEnd = cycleData.active && !withinOneWeek(cycleData.active.end_date);
        if (needNewStart) msgs.push('Today is not within one week of the selected budget\'s start date.');
        if (needOldEnd) msgs.push('Today is not within one week of the current active budget\'s end date.');
        if (msgs.length) {
            warnText.textContent = msgs.join(' ') + ' You may override the dates below:';
            document.getElementById('cycleNewStart').value = promote.start_date;
            document.getElementById('cycleOldEnd').value = cycleData.active?.end_date || '';
            newStartGrp.classList.toggle('d-none', !needNewStart);
            oldEndGrp.classList.toggle('d-none', !needOldEnd);
            warn.classList.remove('d-none');
        } else {
            warn.classList.add('d-none');
        }
    }
    function openCycleModal() {
        const errEl = document.getElementById('cycleError');
        errEl.classList.add('d-none');
        fetch(`pages/${page}.php?cycle_data=1`).then(r => r.json()).then(data => {
            cycleData = data;
            const activeEl = document.getElementById('cycleActiveInfo');
            if (data.active) {
                activeEl.innerHTML = `<strong>${data.active.name}</strong> (${data.active.fiscal_year})<br><small>${data.active.start_date} – ${data.active.end_date}</small>`;
                activeEl.classList.remove('text-muted');
            } else {
                activeEl.textContent = 'No active budget';
                activeEl.classList.add('text-muted');
            }
            const sel = document.getElementById('cyclePromoteSelect');
            sel.innerHTML = '<option value="">— Select Approved budget —</option>';
            data.approved.forEach(b => {
                sel.innerHTML += `<option value="${b.id}" data-start="${b.start_date}">${b.name} (${b.fiscal_year})</option>`;
            });
            const confirmBtn = document.getElementById('cycleConfirmBtn');
            if (data.active && data.approved.length === 0) {
                errEl.textContent = 'Cannot close the Active budget unless at least one Approved budget is available to promote.';
                errEl.classList.remove('d-none');
                confirmBtn.disabled = true;
            } else {
                confirmBtn.disabled = data.approved.length === 0;
            }
            document.getElementById('cycleDateWarning').classList.add('d-none');
            cycleModal.show();
        });
    }

    tableBody.addEventListener('click', e => {
        const row = e.target.closest('tr[data-id]');
        if (!row || row === selectedRow) return;
        if (!confirmDiscard()) return;
        if (selectedRow) selectedRow.classList.remove('table-primary');
        selectedRow = row;
        selectedRow.classList.add('table-primary');
        updateActionButtons();
        openBudget(row.dataset.id);
    });

    addBtn.addEventListener('click', () => {
        if (!confirmDiscard()) return;
        formEl.reset();
        originalStatus = 'draft';
        selectedRow = null;
        tableBody.querySelectorAll('tr.table-primary').forEach(r => r.classList.remove('table-primary'));
        const y = new Date().getFullYear();
        populateForm({ fiscal_year: y, start_date: `${y}-01-01`, end_date: `${y}-12-31`, status: 'draft', lines: [{}] });
    });

    deleteBtn.addEventListener('click', () => {
        if (!selectedRow || selectedRow.dataset.status !== 'draft') return;
        if (!confirm('Delete this draft budget and all its lines?')) return;
        if (!confirm('This action cannot be undone. Are you absolutely sure you want to delete this budget?')) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', selectedRow.dataset.id);
        fetch(`pages/${page}.php`, { method: 'POST', body: fd }).then(reload);
    });

    document.getElementById('cycleBtn').addEventListener('click', openCycleModal);
    document.getElementById('cyclePromoteSelect').addEventListener('change', () => {
        document.getElementById('cycleConfirmBtn').disabled = !document.getElementById('cyclePromoteSelect').value;
        updateCycleDateWarning();
    });
    document.getElementById('cycleConfirmBtn').addEventListener('click', () => {
        const promoteId = document.getElementById('cyclePromoteSelect').value;
        if (!promoteId) return;
        const promote = cycleData.approved.find(b => b.id == promoteId);
        if (!promote.reference_number?.trim() || !promote.approved_date) {
            document.getElementById('cycleError').textContent = 'Cannot activate: Reference # and Approved Date are required. The Reference # should identify the business meeting minutes where this budget was approved.';
            document.getElementById('cycleError').classList.remove('d-none');
            return;
        }
        const fd = new FormData();
        fd.append('action', 'cycle_budget');
        fd.append('promote_id', promoteId);
        const warn = document.getElementById('cycleDateWarning');
        if (!warn.classList.contains('d-none')) {
            if (!document.getElementById('newStartGroup').classList.contains('d-none'))
                fd.append('new_start_date', document.getElementById('cycleNewStart').value);
            if (!document.getElementById('oldEndGroup').classList.contains('d-none'))
                fd.append('old_end_date', document.getElementById('cycleOldEnd').value);
        }
        const msg = cycleData.active
            ? `Close "${cycleData.active.name}" and activate "${promote.name}"?`
            : `Activate "${promote.name}" as the active budget?`;
        if (!confirm(msg)) return;
        fetch(`pages/${page}.php`, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.error) {
                    document.getElementById('cycleError').textContent = res.error;
                    document.getElementById('cycleError').classList.remove('d-none');
                } else {
                    cycleModal.hide();
                    reload();
                }
            });
    });

    addLineBtn.addEventListener('click', () => addLine({}, 'draft'));
    document.getElementById('cancelBtn').addEventListener('click', () => { if (confirmDiscard()) hideForm(); });
    refInput.addEventListener('input', () => refInput.classList.remove('is-invalid'));
    approvedDateInput.addEventListener('input', () => approvedDateInput.classList.remove('is-invalid'));

    formEl.addEventListener('submit', e => {
        e.preventDefault();
        if (formMode === 'locked') return;
        if (formMode === 'draft') {
            const newStatus = document.getElementById('budgetStatus').value;
            if (!confirmApprovedStatus(newStatus)) return;
        }
        document.getElementById('linesJson').value = JSON.stringify(collectLines());
        fetch(`pages/${page}.php`, { method: 'POST', body: new FormData(formEl) }).then(reload);
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-budget-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>
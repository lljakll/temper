<?php
    // Budget Page - Inner content only for AJAX loading

require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/budget_utils.php';

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
        $summary = budgetActiveSummary($db);
        $approved = [];
        $r = $db->query("SELECT id, name, fiscal_year, start_date, end_date, reference_number, approved_date FROM budgets WHERE status = 'approved' ORDER BY fiscal_year DESC, name");
        while ($row = $r->fetch_assoc()) {
            $approved[] = $row;
        }
        echo json_encode([
            'active' => $summary['active'],
            'approved' => $approved,
            'current_fiscal_year' => $summary['current_fiscal_year'],
        ]);
        exit;
    }

    $pageFlash = null;

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
                    if ($stmt->execute()) {
                        $pageFlash = ['message' => 'Budget deleted successfully.', 'type' => 'success'];
                    } else {
                        $pageFlash = ['message' => 'Error deleting budget: ' . $db->error, 'type' => 'danger'];
                    }
                    $stmt->close();
                } else {
                    $pageFlash = ['message' => 'Only draft budgets can be deleted.', 'type' => 'warning'];
                }
            } else {
                $pageFlash = ['message' => 'Invalid budget ID.', 'type' => 'danger'];
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
                if ($stmt->execute()) {
                    $pageFlash = ['message' => 'Budget notes saved successfully.', 'type' => 'success'];
                } else {
                    $pageFlash = ['message' => 'Error saving notes: ' . $db->error, 'type' => 'danger'];
                }
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
            } else {
                $pageFlash = ['message' => 'Only approved budgets allow note edits.', 'type' => 'warning'];
            }
        } elseif ($action === 'close_budget') {
            header('Content-Type: application/json');
            $close_id = (int)($_POST['close_budget_id'] ?? 0);
            $old_end = $_POST['old_end_date'] ?? null;

            if ($close_id <= 0) {
                echo json_encode(['error' => 'Select an active budget to close.']);
                exit;
            }

            $chk = $db->prepare("SELECT id, status, end_date, name, fiscal_year FROM budgets WHERE id = ?");
            $chk->bind_param('i', $close_id);
            $chk->execute();
            $close = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$close || $close['status'] !== 'active') {
                echo json_encode(['error' => 'Only an active budget can be closed.']);
                exit;
            }

            $end = $old_end ?: $close['end_date'];
            $stmt = $db->prepare("UPDATE budgets SET status = 'closed', end_date = ? WHERE id = ?");
            $stmt->bind_param('si', $end, $close_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode([
                'success' => true,
                'message' => 'FY ' . (int)$close['fiscal_year'] . ' budget "' . $close['name'] . '" closed.',
            ]);
            exit;
        } elseif ($action === 'cycle_budget') {
            header('Content-Type: application/json');
            $promote_id = (int)($_POST['promote_id'] ?? 0);
            $new_start = $_POST['new_start_date'] ?? null;

            if ($promote_id <= 0) {
                echo json_encode(['error' => 'Select an Approved budget to activate.']);
                exit;
            }
            $chk = $db->prepare("SELECT id, status, start_date, fiscal_year, reference_number, approved_date, name FROM budgets WHERE id = ?");
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

            $start = $new_start ?: $promote['start_date'];
            $stmt = $db->prepare("UPDATE budgets SET status = 'active', start_date = ? WHERE id = ?");
            $stmt->bind_param('si', $start, $promote_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode([
                'success' => true,
                'message' => 'FY ' . (int)$promote['fiscal_year'] . ' budget "' . $promote['name'] . '" is now active. Other active budgets were not changed.',
            ]);
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
                    $pageFlash = ['message' => 'Reference # and Approved Date are required when approving a budget.', 'type' => 'warning'];
                } else {
                $desc = trim($_POST['description'] ?? '');
                $lines = json_decode($_POST['lines_json'] ?? '[]', true) ?: [];
                $total = 0.0;
                foreach ($lines as $l) { $total += (float)($l['budgeted_amount'] ?? 0); }

                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE budgets SET fiscal_year=?, name=?, start_date=?, end_date=?, approved_date=?, reference_number=?, status=?, total_budgeted=?, description=? WHERE id=?");
                    $stmt->bind_param('issssssdsi', $fy, $name, $start, $end, $approved, $ref, $status, $total, $desc, $id);
                    if (!$stmt->execute()) {
                        $pageFlash = ['message' => 'Error saving budget: ' . $db->error, 'type' => 'danger'];
                    }
                    $stmt->close();
                    $del = $db->prepare("DELETE FROM budget_lines WHERE budget_id = ?");
                    $del->bind_param('i', $id);
                    $del->execute();
                    $del->close();
                } else {
                    $stmt = $db->prepare("INSERT INTO budgets (fiscal_year, name, start_date, end_date, approved_date, reference_number, status, total_budgeted, description) VALUES (?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param('issssssds', $fy, $name, $start, $end, $approved, $ref, $status, $total, $desc);
                    if (!$stmt->execute()) {
                        $pageFlash = ['message' => 'Error saving budget: ' . $db->error, 'type' => 'danger'];
                    } else {
                        $id = (int)$stmt->insert_id;
                    }
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
                if (!$pageFlash) {
                    $pageFlash = [
                        'message' => $status === 'approved' ? 'Budget approved and saved successfully.' : 'Budget saved successfully.',
                        'type' => 'success',
                    ];
                }
                }
            } else {
                $pageFlash = ['message' => 'This budget can no longer be edited.', 'type' => 'warning'];
            }
        }
    }

    $statusBadges = ['draft' => 'warning', 'approved' => 'info', 'active' => 'success', 'closed' => 'secondary'];
    $lookups = ['accounts' => [], 'natural' => [], 'functional' => []];
    foreach (['accounts' => 'accounts', 'natural' => 'natural_categories', 'functional' => 'functional_categories'] as $k => $tbl) {
        $r = $db->query("SELECT id, name FROM $tbl WHERE archived = FALSE ORDER BY name");
        while ($row = $r->fetch_assoc()) $lookups[$k][] = $row;
    }
    $budgetSummary = budgetActiveSummary($db);
    $currentFiscalYear = $budgetSummary['current_fiscal_year'];
    $activeBudgets = $budgetSummary['active'];
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
    tr.budget-row-current-fy td {
        background-color: rgba(var(--bs-primary-rgb), 0.06);
    }
    tr.budget-row-current-fy.table-primary td {
        background-color: rgba(var(--bs-primary-rgb), 0.14);
    }
    .budget-active-list .budget-active-item + .budget-active-item {
        margin-top: 0.5rem;
        padding-top: 0.5rem;
        border-top: 1px solid var(--bs-border-color-translucent);
    }
</style>

<?php if (!empty($pageFlash)): ?>
<script type="application/json" id="page-flash"><?= json_encode($pageFlash) ?></script>
<?php endif; ?>
<div class="container-fluid mt-2 mt-md-4 px-0 px-sm-2">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h2 class="mb-1">Budget</h2>
            <p class="text-muted small mb-0">Current fiscal year: <strong><?= $currentFiscalYear ?></strong>. Multiple budgets may be active at once — including across fiscal years — for year-end entries.</p>
        </div>
        <?php if (count($activeBudgets) > 0): ?>
        <div class="small text-end">
            <span class="text-muted">Active:</span>
            <?php foreach ($activeBudgets as $ab): ?>
                <span class="badge bg-success ms-1"><?= htmlspecialchars($ab['name']) ?> · FY <?= (int)$ab['fiscal_year'] ?><?= (int)$ab['fiscal_year'] === $currentFiscalYear ? ' (Current)' : '' ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (count($activeBudgets) > 1): ?>
    <div class="alert alert-info py-2 small mb-3">
        <i class="bi bi-info-circle me-1"></i>
        <?= count($activeBudgets) ?> budgets are currently active:
        <?= implode(', ', array_map(fn($b) => htmlspecialchars($b['name']) . ' (FY ' . (int)$b['fiscal_year'] . ')', $activeBudgets)) ?>.
        Activate and close budgets individually when year-end work is complete.
    </div>
    <?php endif; ?>

    <div class="row mb-3">
        <div class="col d-flex flex-wrap gap-2 justify-content-md-end">
            <button type="button" id="cycleBtn" class="btn btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Activate / Close</button>
            <button id="addBtn" class="btn btn-primary">New Budget</button>
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
                        <?php $isCurrentFy = ((int)$b['fiscal_year'] === $currentFiscalYear); ?>
                        <tr data-id="<?= $b['id'] ?>" data-status="<?= htmlspecialchars($b['status']) ?>" data-fiscal-year="<?= (int)$b['fiscal_year'] ?>" class="<?= $isCurrentFy ? 'budget-row-current-fy' : '' ?>">
                            <td>
                                <?= (int)$b['fiscal_year'] ?>
                                <?php if ($isCurrentFy): ?><span class="badge bg-primary ms-1">Current FY</span><?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($b['name']) ?></td>
                            <td><?= htmlspecialchars($b['start_date']) ?> – <?= htmlspecialchars($b['end_date']) ?></td>
                            <td><?= htmlspecialchars($b['approved_date'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($b['reference_number'] ?? '') ?></td>
                            <td>
                                <span class="badge bg-<?= $statusBadges[$b['status']] ?? 'secondary' ?>"><?= htmlspecialchars($b['status']) ?></span>
                                <?php if ($b['status'] === 'active'): ?>
                                    <span class="badge bg-body-secondary text-body border ms-1">FY <?= (int)$b['fiscal_year'] ?></span>
                                <?php endif; ?>
                            </td>
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

                <div class="row g-2 g-md-3 mb-3">
                    <div class="col-6 col-md-2">
                        <label class="form-label">Year</label>
                        <input type="number" class="form-control budget-field" name="fiscal_year" id="fiscalYear" required min="2000" max="2100">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control budget-field" name="name" id="budgetName" required>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label">Status</label>
                        <select class="form-select budget-field" name="status" id="budgetStatus">
                            <option value="draft">Draft</option>
                            <option value="approved">Approved</option>
                        </select>
                        <input type="text" class="form-control d-none" id="budgetStatusDisplay" readonly disabled>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Reference # <span class="text-danger">*</span></label>
                        <input type="text" class="form-control budget-field" name="reference_number" id="referenceNumber">
                        <div class="invalid-feedback">Required. Should identify the business meeting minutes where this budget was approved.</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control budget-field" name="start_date" id="startDate" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control budget-field" name="end_date" id="endDate" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Approved Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control budget-field" name="approved_date" id="approvedDate">
                        <div class="invalid-feedback">Required when approving a budget.</div>
                    </div>
                    <div class="col-12 col-md-3">
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

<!-- Activate / Close Budget Modal -->
<div class="modal fade" id="cycleModal" tabindex="-1" aria-labelledby="cycleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cycleModalLabel">Activate / Close Budget</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">
                    Multiple budgets may be active at once, including more than one per fiscal year.
                    Activating a budget does <strong>not</strong> close any other budget — use Close when year-end entries are finished.
                </p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Currently Active Budgets</label>
                    <div id="cycleActiveInfo" class="p-2 bg-body-tertiary rounded border text-body-secondary budget-active-list">Loading…</div>
                </div>
                <div class="mb-3">
                    <label for="cyclePromoteSelect" class="form-label fw-semibold">Activate Approved Budget</label>
                    <select class="form-select" id="cyclePromoteSelect">
                        <option value="">— Select Approved budget —</option>
                    </select>
                </div>
                <div id="cycleDateWarning" class="alert alert-warning d-none">
                    <p class="mb-2 small" id="cycleDateWarningText"></p>
                    <div id="newStartGroup">
                        <label class="form-label small">Budget start date</label>
                        <input type="date" class="form-control form-control-sm" id="cycleNewStart">
                    </div>
                </div>
                <hr>
                <div class="mb-2">
                    <label for="cycleCloseSelect" class="form-label fw-semibold">Close Active Budget (Year-End)</label>
                    <select class="form-select" id="cycleCloseSelect">
                        <option value="">— Select active budget to close —</option>
                    </select>
                    <div class="form-text">Close a specific budget when its year-end entries are complete. Other active budgets are unaffected.</div>
                </div>
                <div id="closeDateWarning" class="alert alert-warning d-none">
                    <p class="mb-2 small" id="closeDateWarningText"></p>
                    <label class="form-label small">Budget end date</label>
                    <input type="date" class="form-control form-control-sm" id="cycleCloseEnd">
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-warning" id="closeConfirmBtn" disabled>Close Selected Budget</button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="cycleConfirmBtn" disabled>Activate Selected Budget</button>
                </div>
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
    let cycleData = { active: [], approved: [], current_fiscal_year: <?= (int)$currentFiscalYear ?> };

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
            showToast('Reference # and Approved Date are required. The Reference # should identify the business meeting minutes where this budget was approved.', 'warning');
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
        fetch(`pages/${page}.php`)
            .then(r => r.text())
            .then(h => applyMainContent(h))
            .catch(() => showToast('Failed to refresh page.', 'danger'));
    }
    function postAndApply(body) {
        return fetch(`pages/${page}.php`, { method: 'POST', body })
            .then(r => r.text())
            .then(h => applyMainContent(h))
            .catch(() => showToast('Request failed. Please try again.', 'danger'));
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

    function renderActiveBudgetList(activeList, currentFy) {
        if (!activeList.length) {
            return '<span class="text-muted">No active budgets</span>';
        }
        return activeList.map(b => {
            const fyBadge = Number(b.fiscal_year) === Number(currentFy)
                ? '<span class="badge bg-primary ms-1">Current FY</span>'
                : '';
            return `<div class="budget-active-item"><strong>${b.name}</strong> (FY ${b.fiscal_year})${fyBadge}<br><small class="text-muted">${b.start_date} – ${b.end_date}</small></div>`;
        }).join('');
    }
    function updateCycleDateWarning() {
        const sel = document.getElementById('cyclePromoteSelect');
        const promote = cycleData.approved.find(b => b.id == sel.value);
        const warn = document.getElementById('cycleDateWarning');
        const warnText = document.getElementById('cycleDateWarningText');
        const newStartGrp = document.getElementById('newStartGroup');
        if (!promote) { warn.classList.add('d-none'); return; }
        if (!withinOneWeek(promote.start_date)) {
            warnText.textContent = 'Today is not within one week of the selected budget\'s start date. You may override it below:';
            document.getElementById('cycleNewStart').value = promote.start_date;
            newStartGrp.classList.remove('d-none');
            warn.classList.remove('d-none');
        } else {
            warn.classList.add('d-none');
        }
    }
    function updateCloseDateWarning() {
        const closeId = document.getElementById('cycleCloseSelect').value;
        const closeBudget = (cycleData.active || []).find(b => b.id == closeId);
        const warn = document.getElementById('closeDateWarning');
        const warnText = document.getElementById('closeDateWarningText');
        if (!closeBudget) {
            warn.classList.add('d-none');
            return;
        }
        if (!withinOneWeek(closeBudget.end_date)) {
            warnText.textContent = `Today is not within one week of FY ${closeBudget.fiscal_year}'s scheduled end date. You may override it below:`;
            document.getElementById('cycleCloseEnd').value = closeBudget.end_date;
            warn.classList.remove('d-none');
        } else {
            warn.classList.add('d-none');
        }
    }
    function openCycleModal() {
        fetch(`pages/${page}.php?cycle_data=1`).then(r => r.json()).then(data => {
            cycleData = data;
            const activeEl = document.getElementById('cycleActiveInfo');
            activeEl.innerHTML = renderActiveBudgetList(data.active || [], data.current_fiscal_year);
            activeEl.classList.toggle('text-muted', !(data.active || []).length);

            const sel = document.getElementById('cyclePromoteSelect');
            sel.innerHTML = '<option value="">— Select Approved budget —</option>';
            (data.approved || []).forEach(b => {
                const currentTag = Number(b.fiscal_year) === Number(data.current_fiscal_year) ? ' · Current FY' : '';
                sel.innerHTML += `<option value="${b.id}" data-start="${b.start_date}">${b.name} (FY ${b.fiscal_year}${currentTag})</option>`;
            });

            const closeSel = document.getElementById('cycleCloseSelect');
            closeSel.innerHTML = '<option value="">— Select active budget to close —</option>';
            (data.active || []).forEach(b => {
                const currentTag = Number(b.fiscal_year) === Number(data.current_fiscal_year) ? ' · Current FY' : '';
                closeSel.innerHTML += `<option value="${b.id}">${b.name} (FY ${b.fiscal_year}${currentTag})</option>`;
            });

            document.getElementById('cycleConfirmBtn').disabled = !(data.approved || []).length;
            document.getElementById('closeConfirmBtn').disabled = !(data.active || []).length;
            document.getElementById('cycleDateWarning').classList.add('d-none');
            document.getElementById('closeDateWarning').classList.add('d-none');
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
        postAndApply(fd);
    });

    document.getElementById('cycleBtn').addEventListener('click', openCycleModal);
    document.getElementById('cyclePromoteSelect').addEventListener('change', () => {
        document.getElementById('cycleConfirmBtn').disabled = !document.getElementById('cyclePromoteSelect').value;
        updateCycleDateWarning();
    });
    document.getElementById('cycleCloseSelect').addEventListener('change', () => {
        document.getElementById('closeConfirmBtn').disabled = !document.getElementById('cycleCloseSelect').value;
        updateCloseDateWarning();
    });
    document.getElementById('cycleConfirmBtn').addEventListener('click', () => {
        const promoteId = document.getElementById('cyclePromoteSelect').value;
        if (!promoteId) return;
        const promote = cycleData.approved.find(b => b.id == promoteId);
        if (!promote.reference_number?.trim() || !promote.approved_date) {
            showToast('Cannot activate: Reference # and Approved Date are required. The Reference # should identify the business meeting minutes where this budget was approved.', 'warning');
            return;
        }
        const fd = new FormData();
        fd.append('action', 'cycle_budget');
        fd.append('promote_id', promoteId);
        const warn = document.getElementById('cycleDateWarning');
        if (!warn.classList.contains('d-none')) {
            fd.append('new_start_date', document.getElementById('cycleNewStart').value);
        }
        const msg = `Activate FY ${promote.fiscal_year} budget "${promote.name}"? Other active budgets will not be changed.`;
        if (!confirm(msg)) return;
        fetch(`pages/${page}.php`, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.error) {
                    showToast(res.error, 'danger');
                } else {
                    cycleModal.hide();
                    showToast(res.message || 'Budget activated successfully.', 'success');
                    reload();
                }
            })
            .catch(() => showToast('Budget activation failed. Please try again.', 'danger'));
    });
    document.getElementById('closeConfirmBtn').addEventListener('click', () => {
        const closeId = document.getElementById('cycleCloseSelect').value;
        if (!closeId) return;
        const closeBudget = (cycleData.active || []).find(b => b.id == closeId);
        if (!closeBudget) return;
        const fd = new FormData();
        fd.append('action', 'close_budget');
        fd.append('close_budget_id', closeId);
        const closeWarn = document.getElementById('closeDateWarning');
        if (!closeWarn.classList.contains('d-none')) {
            fd.append('old_end_date', document.getElementById('cycleCloseEnd').value);
        }
        if (!confirm(`Close FY ${closeBudget.fiscal_year} budget "${closeBudget.name}"? Other active budgets will not be changed.`)) return;
        fetch(`pages/${page}.php`, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.error) {
                    showToast(res.error, 'danger');
                } else {
                    cycleModal.hide();
                    showToast(res.message || 'Budget closed successfully.', 'success');
                    reload();
                }
            })
            .catch(() => showToast('Budget close failed. Please try again.', 'danger'));
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
        postAndApply(new FormData(formEl));
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-budget-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>
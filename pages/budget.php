<?php
    // Budget Page - Inner content only for AJAX loading

require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/budget_utils.php';
require_once __DIR__ . '/../includes/permissions.php';

    budgetEnsureSimplifiedSchema($db);

    $budgetActor = getCurrentUser();
    $canWriteBudget = $budgetActor && userHasPermission($db, (int)$budgetActor['id'], 'page.budget.write');

    if (isset($_GET['get_budget'])) {
        $id = (int)$_GET['get_budget'];
        header('Content-Type: application/json');
        if ($id <= 0) { echo json_encode(['error' => 'Invalid ID']); exit; }
        $budget = budgetFetchDetailWithLines($db, $id);
        if (!$budget) { echo json_encode(['error' => 'Budget not found']); exit; }
        echo json_encode($budget);
        exit;
    }

    if (isset($_GET['line_actuals'])) {
        header('Content-Type: application/json');
        $start = (string)($_GET['start_date'] ?? '');
        $end = (string)($_GET['end_date'] ?? '');
        $fundId = (int)($_GET['fund_id'] ?? 0);
        $ids = [];
        foreach (explode(',', (string)($_GET['account_ids'] ?? '')) as $part) {
            $aid = (int)trim($part);
            if ($aid > 0) {
                $ids[] = $aid;
            }
        }
        $map = budgetFetchAccountActuals($db, $start, $end, $ids, $fundId > 0 ? $fundId : null);
        $actuals = [];
        foreach ($map as $accountId => $actual) {
            $actuals[(string)$accountId] = $actual;
        }
        echo json_encode(['actuals' => $actuals]);
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

        if (!$canWriteBudget) {
            denyPermission('You do not have permission to modify budgets.');
        }

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
        } elseif ($action === 'duplicate') {
            header('Content-Type: application/json');
            $sourceId = (int)($_POST['source_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $fy = (int)($_POST['fiscal_year'] ?? 0);
            $start = (string)($_POST['start_date'] ?? '');
            $end = (string)($_POST['end_date'] ?? '');
            $result = budgetDuplicateToDraft($db, $sourceId, $name, $fy, $start, $end);
            if (empty($result['ok'])) {
                echo json_encode(['error' => $result['error'] ?? 'Unable to duplicate budget.']);
                exit;
            }
            $lineCount = (int)($result['line_count'] ?? 0);
            $lineLabel = $lineCount === 1 ? '1 line' : $lineCount . ' lines';
            echo json_encode([
                'success' => true,
                'id' => (int)$result['id'],
                'message' => 'Draft budget "' . $result['name'] . '" created from copy (' . $lineLabel . ').',
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

                // Validate lines: each must reference a non-archived account from the lookup
                $vlines = [];
                $lineError = null;
                $total = 0.0;
                foreach ($lines as $l) {
                    $aid = !empty($l['account_id']) ? (int)$l['account_id'] : 0;
                    $amt = (float)($l['budgeted_amount'] ?? 0);
                    $notes = trim($l['notes'] ?? '');
                    if ($aid <= 0 && $amt <= 0 && $notes === '') {
                        continue; // empty row
                    }
                    if ($aid <= 0) {
                        $lineError = 'Each budget line must select an account from the lookup.';
                        break;
                    }
                    if (!budgetIsValidAccountId($db, $aid)) {
                        $lineError = 'Budget lines may only use active accounts from Accounts setup.';
                        break;
                    }
                    if ($amt <= 0) {
                        $lineError = 'Each budget line must have an amount greater than zero.';
                        break;
                    }
                    $total += $amt;
                    $vlines[] = ['account_id' => $aid, 'budgeted_amount' => $amt, 'notes' => $notes];
                }

                if ($lineError) {
                    $pageFlash = ['message' => $lineError, 'type' => 'warning'];
                } else {
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
                if ($id > 0 && !$pageFlash) {
                    $ins = $db->prepare("INSERT INTO budget_lines (budget_id, account_id, budgeted_amount, notes) VALUES (?,?,?,?)");
                    foreach ($vlines as $v) {
                        $aid = $v['account_id'];
                        $amt = $v['budgeted_amount'];
                        $notes = $v['notes'];
                        $ins->bind_param('iids', $id, $aid, $amt, $notes);
                        $ins->execute();
                    }
                    $ins->close();
                    $pageFlash = [
                        'message' => $status === 'approved' ? 'Budget approved and saved successfully.' : 'Budget saved successfully.',
                        'type' => 'success',
                    ];
                }
                }
                }
            } else {
                $pageFlash = ['message' => 'This budget can no longer be edited.', 'type' => 'warning'];
            }
        }
    }

    $statusBadges = ['draft' => 'warning', 'approved' => 'info', 'active' => 'success', 'closed' => 'secondary'];
    $lookups = ['accounts' => budgetFetchAccountLookups($db)];
    $budgetSummary = budgetActiveSummary($db);
    $currentFiscalYear = $budgetSummary['current_fiscal_year'];
    $activeBudgets = $budgetSummary['active'];
    $budgets = $db->query("SELECT id, fiscal_year, name, start_date, end_date, approved_date, reference_number, status, total_budgeted FROM budgets ORDER BY fiscal_year DESC, name");
?>

<style>
    .budget-name-wrap { position: relative; }
    .budget-name-tip {
        position: absolute;
        left: 0;
        right: 0;
        top: calc(100% + 0.35rem);
        z-index: 20;
        padding: 0.5rem 0.65rem;
        font-size: 0.8rem;
        line-height: 1.35;
        color: var(--bs-body-color);
        background: var(--bs-body-bg);
        border: 1px solid var(--bs-border-color);
        border-radius: 0.375rem;
        box-shadow: 0 0.35rem 1rem rgba(0, 0, 0, 0.12);
        pointer-events: none;
    }
    .budget-lines-table-wrap { overflow-x: auto; }
    .budget-line-remaining.is-negative,
    #linesRemaining.is-negative,
    #mobileLinesRemaining.is-negative,
    #lineEditRemaining.is-negative {
        color: var(--bs-danger);
    }
    .budget-summary-card,
    .budget-line-cards,
    .budget-actions-flyout-head,
    .budget-actions-backdrop {
        display: none;
    }
    .budget-line-card {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
        width: 100%;
        margin: 0 0 0.55rem;
        padding: 0.7rem 0.8rem;
        border: 1px solid var(--bs-border-color);
        border-radius: 0.7rem;
        background: var(--bs-body-bg);
        box-shadow: 0 0.08rem 0.35rem rgba(0, 0, 0, 0.05);
        text-align: left;
        color: inherit;
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
    }
    .budget-line-card:last-child { margin-bottom: 0; }
    .budget-line-card:active {
        background-color: rgba(var(--bs-primary-rgb), 0.08);
        border-color: rgba(var(--bs-primary-rgb), 0.35);
    }
    .budget-line-card-main { min-width: 0; flex: 1 1 auto; }
    .budget-line-card-name {
        font-weight: 600;
        line-height: 1.25;
        word-break: break-word;
    }
    .budget-line-card-coa {
        font-size: 0.78rem;
        color: var(--bs-secondary-color);
        font-family: var(--bs-font-monospace);
    }
    .budget-line-card-figures {
        text-align: right;
        flex: 0 0 auto;
        font-variant-numeric: tabular-nums;
    }
    .budget-line-card-amount {
        font-weight: 700;
        font-family: var(--bs-font-monospace);
        line-height: 1.15;
    }
    .budget-line-card-remaining {
        font-size: 0.75rem;
        color: var(--bs-secondary-color);
        margin-top: 0.15rem;
    }
    .budget-line-card-remaining.is-negative { color: var(--bs-danger); }
    .budget-line-cards-empty {
        color: var(--bs-secondary-color);
        font-size: 0.875rem;
        padding: 0.85rem 0.25rem;
        text-align: center;
    }
    .budget-line-cards-foot {
        border-top: 1px solid var(--bs-border-color);
        margin-top: 0.35rem;
        padding-top: 0.65rem;
    }
    .budget-summary-dl { margin-bottom: 0; }
    .budget-summary-dl dt { color: var(--bs-secondary-color); font-weight: 600; }
    .budget-summary-dl dd { margin-bottom: 0.35rem; }
    @media (max-width: 767.98px) {
        .budget-page .budget-lines-table-wrap { display: none !important; }
        .budget-line-cards { display: block; }
        .budget-page.is-readonly-view .budget-field-core { display: none !important; }
        .budget-page.is-locked-view .budget-field-desc { display: none !important; }
        .budget-page.is-readonly-view .budget-summary-card { display: block; }
        .budget-page.is-readonly-view:not(.is-locked-view) .budget-summary-desc { display: none; }
        .budget-actions-backdrop {
            display: block;
            position: fixed;
            inset: 0;
            z-index: 1035;
            background: rgba(0, 0, 0, 0.35);
        }
        .budget-actions-backdrop[hidden] { display: none !important; }
        .budget-action-bar {
            position: fixed;
            top: 3.7rem;
            right: 0.5rem;
            left: auto;
            bottom: auto;
            width: min(18rem, calc(100vw - 1rem));
            max-height: calc(100dvh - 8.5rem - env(safe-area-inset-bottom, 0px));
            overflow-x: hidden;
            overflow-y: auto;
            display: flex !important;
            flex-direction: column;
            flex-wrap: nowrap;
            align-items: stretch;
            gap: 0.45rem;
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
        .budget-page.budget-actions-open .budget-action-bar {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
            transform: none;
        }
        .budget-actions-flyout-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            padding-bottom: 0.15rem;
            margin-bottom: 0.15rem;
            border-bottom: 1px solid var(--bs-border-color);
        }
        .budget-action-bar > .btn {
            flex: 0 0 auto;
            width: 100%;
            justify-content: flex-start;
        }
        /*
         * Stacked budget list cards: Bootstrap .table-primary uses a light-blue
         * cell fill and black table text. Caption labels (::before) keep
         * --bs-secondary-color, which in dark theme is light — unreadable on
         * that fill. Paint the card with a theme-aware primary tint instead.
         */
        .budget-page table.temper-stack-on-mobile tbody tr {
            --bs-table-color: var(--bs-body-color);
            --bs-table-bg: transparent;
            --bs-table-accent-bg: transparent;
            --bs-table-striped-bg: transparent;
            --bs-table-striped-color: var(--bs-body-color);
            --bs-table-active-bg: transparent;
            --bs-table-active-color: var(--bs-body-color);
            --bs-table-hover-bg: transparent;
            --bs-table-hover-color: var(--bs-body-color);
            color: var(--bs-body-color);
            background-color: var(--bs-body-bg);
        }
        .budget-page table.temper-stack-on-mobile tbody tr > * {
            background-color: transparent !important;
            color: inherit;
            box-shadow: none;
        }
        .budget-page table.temper-stack-on-mobile tbody tr.budget-row-current-fy {
            background-color: rgba(var(--bs-primary-rgb), 0.08);
        }
        .budget-page table.temper-stack-on-mobile tbody tr.table-primary {
            --bs-table-color: var(--bs-body-color);
            --bs-table-bg: transparent;
            color: var(--bs-body-color);
            background-color: rgba(var(--bs-primary-rgb), 0.2);
            border-color: rgba(var(--bs-primary-rgb), 0.55);
        }
        .budget-page table.temper-stack-on-mobile tbody tr.table-primary td {
            color: var(--bs-body-color);
        }
        .budget-page table.temper-stack-on-mobile tbody tr.table-primary td::before {
            color: var(--bs-secondary-color);
        }
    }
    #linesTable {
        table-layout: fixed;
        width: 100%;
        min-width: 860px;
    }
    #linesTable col.col-account { width: 22%; }
    #linesTable col.col-coa { width: 10%; }
    #linesTable col.col-cat { width: 12%; }
    #linesTable col.col-amount,
    #linesTable col.col-remaining { width: 100px; }
    #linesTable col.col-notes { width: 18%; }
    #linesTable col.col-actions { width: 42px; }
    #linesTable .line-cat-label {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: var(--bs-secondary-color);
        font-size: 0.875rem;
    }
    #linesTable .line-coa-label {
        font-family: var(--bs-font-monospace);
    }
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
<div class="container-fluid mt-2 mt-md-4 px-0 px-sm-2 budget-page" id="budgetPage">
    <div id="budgetMobileHeaderTools" hidden>
        <button type="button" id="budgetActionsFlyoutBtn" class="btn btn-outline-secondary btn-sm px-2"
                aria-expanded="false" aria-controls="budgetActionBar" title="Budget actions">
            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
            <span class="visually-hidden">Budget actions</span>
        </button>
    </div>
    <div id="budgetActionsBackdrop" class="budget-actions-backdrop d-md-none" hidden></div>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3 page-title-row">
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

    <div class="d-flex flex-wrap gap-2 mb-3 budget-action-bar justify-content-md-end" id="budgetActionBar" role="toolbar" aria-label="Budget actions">
        <div class="budget-actions-flyout-head d-md-none">
            <strong>Actions</strong>
            <button type="button" class="btn-close" id="budgetActionsFlyoutClose" aria-label="Close actions"></button>
        </div>
        <button type="button" id="cycleBtn" class="btn btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Activate / Close</button>
        <?php if ($canWriteBudget): ?>
        <button type="button" id="addBtn" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Budget</button>
        <button type="button" id="duplicateBtn" class="btn btn-outline-secondary" disabled><i class="bi bi-copy"></i> Duplicate</button>
        <?php endif; ?>
        <button type="button" id="exportBtn" class="btn btn-outline-secondary" disabled><i class="bi bi-download"></i> Export</button>
        <button type="button" id="deleteBtn" class="btn btn-danger" disabled><i class="bi bi-trash"></i> Delete</button>
    </div>

    <div class="table-responsive mb-4">
        <table class="table table-striped table-hover temper-stack-on-mobile">
            <thead class="table-dark">
                <tr>
                    <th>Year</th><th>Name</th><th>Period</th><th>Approved</th><th>Reference</th><th>Status</th><th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody id="budgetTableBody">
                <?php if ($budgets && $budgets->num_rows > 0): ?>
                    <?php while ($b = $budgets->fetch_assoc()): ?>
                        <?php $isCurrentFy = ((int)$b['fiscal_year'] === $currentFiscalYear); ?>
                        <tr data-id="<?= $b['id'] ?>"
                            data-status="<?= htmlspecialchars($b['status']) ?>"
                            data-fiscal-year="<?= (int)$b['fiscal_year'] ?>"
                            data-name="<?= htmlspecialchars($b['name']) ?>"
                            data-start-date="<?= htmlspecialchars($b['start_date']) ?>"
                            data-end-date="<?= htmlspecialchars($b['end_date']) ?>"
                            class="<?= $isCurrentFy ? 'budget-row-current-fy' : '' ?>">
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
            <form id="budgetFormContent" method="POST" data-dirty-track>
                <input type="hidden" name="action" id="formAction" value="save">
                <input type="hidden" name="budget_id" id="budgetId">
                <input type="hidden" name="lines_json" id="linesJson">

                <div id="budgetSummaryCard" class="budget-summary-card card border mb-3">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div class="min-w-0">
                                <div class="fw-semibold" id="budgetSummaryName"></div>
                                <div class="small text-muted" id="budgetSummaryPeriod"></div>
                            </div>
                            <span class="badge" id="budgetSummaryStatus"></span>
                        </div>
                        <dl class="row small budget-summary-dl">
                            <dt class="col-4">Year</dt><dd class="col-8" id="budgetSummaryYear"></dd>
                            <dt class="col-4">Reference</dt><dd class="col-8" id="budgetSummaryRef"></dd>
                            <dt class="col-4">Approved</dt><dd class="col-8" id="budgetSummaryApproved"></dd>
                            <dt class="col-4 budget-summary-desc">Description</dt><dd class="col-8 budget-summary-desc" id="budgetSummaryDesc"></dd>
                        </dl>
                    </div>
                </div>

                <div class="row g-2 g-md-3 mb-3" id="budgetDetailsFields">
                    <div class="col-6 col-md-2 budget-field-core">
                        <label class="form-label">Year</label>
                        <input type="number" class="form-control budget-field" name="fiscal_year" id="fiscalYear" required min="2000" max="2100">
                    </div>
                    <div class="col-12 col-md-4 budget-field-core">
                        <label class="form-label" for="budgetName">Name</label>
                        <div class="budget-name-wrap position-relative">
                            <input type="text" class="form-control budget-field" name="name" id="budgetName" required
                                   placeholder="e.g. FY26, CY26.Q2" autocomplete="off"
                                   aria-describedby="budgetNameTip">
                            <div id="budgetNameTip" class="budget-name-tip d-none" role="tooltip">
                                Use a clear short name for this budget (e.g. <strong>FY26Q1</strong>, <strong>FY26</strong>, <strong>CY26</strong>, <strong>CY26.Q2</strong>).
                                Accurate naming makes the transaction budget dropdown easier to use.
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2 budget-field-core">
                        <label class="form-label">Status</label>
                        <select class="form-select budget-field" name="status" id="budgetStatus">
                            <option value="draft">Draft</option>
                            <option value="approved">Approved</option>
                        </select>
                        <input type="text" class="form-control d-none" id="budgetStatusDisplay" readonly disabled>
                    </div>
                    <div class="col-12 col-md-4 budget-field-core">
                        <label class="form-label">Reference # <span class="text-danger">*</span></label>
                        <input type="text" class="form-control budget-field" name="reference_number" id="referenceNumber">
                        <div class="invalid-feedback">Required. Should identify the business meeting minutes where this budget was approved.</div>
                    </div>
                    <div class="col-6 col-md-3 budget-field-core">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control budget-field" name="start_date" id="startDate" required>
                    </div>
                    <div class="col-6 col-md-3 budget-field-core">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control budget-field" name="end_date" id="endDate" required>
                    </div>
                    <div class="col-6 col-md-3 budget-field-core">
                        <label class="form-label">Approved Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control budget-field" name="approved_date" id="approvedDate">
                        <div class="invalid-feedback">Required when approving a budget.</div>
                    </div>
                    <div class="col-12 col-md-3 budget-field-desc">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control budget-field" name="description" id="budgetDesc" placeholder="Optional longer description">
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center py-2">
                        <h6 class="mb-0">Budget Lines</h6>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="addLineBtn"><i class="bi bi-plus"></i> Add Line</button>
                    </div>
                    <div class="card-body p-0">
                        <div class="budget-lines-table-wrap d-none d-md-block">
                            <table class="table table-sm table-bordered mb-0" id="linesTable">
                                <colgroup>
                                    <col class="col-account">
                                    <col class="col-coa">
                                    <col class="col-cat"><col class="col-cat">
                                    <col class="col-amount"><col class="col-remaining">
                                    <col class="col-notes"><col class="col-actions">
                                </colgroup>
                                <thead class="table-light">
                                    <tr>
                                        <th class="line-cell-cat">Account</th>
                                        <th class="line-cell-cat">CoA #</th>
                                        <th class="line-cell-cat">Natural</th>
                                        <th class="line-cell-cat">Functional</th>
                                        <th class="text-end line-cell-amount">Amount</th>
                                        <th class="text-end line-cell-remaining">Remaining</th>
                                        <th class="line-cell-notes">Notes</th>
                                        <th class="line-actions"></th>
                                    </tr>
                                </thead>
                                <tbody id="linesBody"></tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="4" class="text-end fw-bold">Total</td>
                                        <td class="text-end fw-bold" id="linesTotal">$0.00</td>
                                        <td class="text-end fw-bold text-muted" id="linesRemaining">—</td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div id="budgetLineCards" class="budget-line-cards p-2 d-md-none">
                            <div id="budgetLineCardList"></div>
                            <div class="budget-line-cards-foot d-flex justify-content-between align-items-start px-1">
                                <span class="fw-semibold">Total</span>
                                <div class="text-end">
                                    <div id="mobileLinesTotal" class="fw-semibold">$0.00</div>
                                    <div class="small" id="mobileLinesRemaining">Remaining —</div>
                                </div>
                            </div>
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

<?php if ($canWriteBudget): ?>
<!-- Duplicate Budget Modal -->
<div class="modal fade" id="duplicateModal" tabindex="-1" aria-labelledby="duplicateModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="duplicateModalLabel">Duplicate Budget</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="duplicateForm">
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Creates a new <strong>Draft</strong> with the same budget lines (accounts, amounts, and notes).
                        Approval, activation, and transactions are not copied.
                    </p>
                    <div class="mb-3">
                        <label class="form-label">Copying from</label>
                        <div id="duplicateSourceLabel" class="form-control-plaintext small"></div>
                        <input type="hidden" id="duplicateSourceId">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="duplicateName">New budget name</label>
                        <input type="text" class="form-control" id="duplicateName" required maxlength="100" autocomplete="off">
                    </div>
                    <div class="row g-2">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="duplicateFiscalYear">Year</label>
                            <input type="number" class="form-control" id="duplicateFiscalYear" required min="2000" max="2100">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="duplicateStartDate">Start Date</label>
                            <input type="date" class="form-control" id="duplicateStartDate" required>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="duplicateEndDate">End Date</label>
                            <input type="date" class="form-control" id="duplicateEndDate" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="duplicateConfirmBtn">Create Draft Copy</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Export Budget Modal -->
<div class="modal fade" id="budgetExportModal" tabindex="-1" aria-labelledby="budgetExportModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="budgetExportModalLabel">Export Budget</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">Download the budget currently being viewed.</p>
                <div id="budgetExportSourceLabel" class="fw-semibold mb-3"></div>
                <fieldset>
                    <legend class="form-label fs-6">Format</legend>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="budgetExportFormat" id="budgetExportCsv" value="csv" checked>
                        <label class="form-check-label" for="budgetExportCsv">CSV (spreadsheet)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="budgetExportFormat" id="budgetExportPdf" value="pdf">
                        <label class="form-check-label" for="budgetExportPdf">PDF (printable)</label>
                    </div>
                </fieldset>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="budgetExportConfirm"><i class="bi bi-download"></i> Download</button>
            </div>
        </div>
    </div>
</div>

<!-- Budget Line editor (mobile sheet; also used to view a line) -->
<div class="modal fade" id="budgetLineModal" tabindex="-1" aria-labelledby="budgetLineModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="budgetLineModalTitle">Budget Line</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="lineEditUid">
                <div class="mb-3">
                    <label class="form-label" for="lineEditAccount">Account</label>
                    <select class="form-select" id="lineEditAccount"></select>
                    <div class="form-control-plaintext d-none fw-semibold" id="lineEditAccountDisplay"></div>
                </div>
                <div class="row g-2 mb-3 small">
                    <div class="col-4">
                        <div class="text-muted">CoA #</div>
                        <div class="font-monospace" id="lineEditCoa">—</div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted">Natural</div>
                        <div id="lineEditNatural">—</div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted">Functional</div>
                        <div id="lineEditFunctional">—</div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="lineEditAmount">Amount</label>
                    <input type="text" class="form-control text-end" id="lineEditAmount" inputmode="numeric">
                    <div class="form-control-plaintext d-none text-end fw-semibold" id="lineEditAmountDisplay"></div>
                </div>
                <div class="mb-3">
                    <div class="text-muted small">Remaining</div>
                    <div class="fs-5 fw-semibold" id="lineEditRemaining">—</div>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="lineEditNotes">Notes</label>
                    <input type="text" class="form-control" id="lineEditNotes">
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-danger" id="lineEditDelete">Remove</button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="lineEditApply">Apply</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Activate / Close Budget Modal -->
<div class="modal fade" id="cycleModal" tabindex="-1" aria-labelledby="cycleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
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
    const tableBody = document.getElementById('budgetTableBody');
    const form = document.getElementById('budgetForm');
    const formEl = document.getElementById('budgetFormContent');
    const linesBody = document.getElementById('linesBody');
    const linesTotal = document.getElementById('linesTotal');
    const addBtn = document.getElementById('addBtn');
    const duplicateBtn = document.getElementById('duplicateBtn');
    const exportBtn = document.getElementById('exportBtn');
    const deleteBtn = document.getElementById('deleteBtn');
    const addLineBtn = document.getElementById('addLineBtn');
    const saveBtn = document.getElementById('saveBtn');
    const modeBadge = document.getElementById('modeBadge');
    const refInput = document.getElementById('referenceNumber');
    const approvedDateInput = document.getElementById('approvedDate');
    const budgetNameInput = document.getElementById('budgetName');
    const budgetNameTip = document.getElementById('budgetNameTip');
    // Prefer body-mounted modal (SPA stacking); getOrCreate after mount so backdrop is under dialog
    let cycleModalEl = document.getElementById('cycleModal');
    if (cycleModalEl && typeof window.mountModalOnBody === 'function') {
        cycleModalEl = window.mountModalOnBody(cycleModalEl);
    }
    const cycleModal = cycleModalEl
        ? bootstrap.Modal.getOrCreateInstance(cycleModalEl)
        : null;
    let duplicateModalEl = document.getElementById('duplicateModal');
    if (duplicateModalEl && typeof window.mountModalOnBody === 'function') {
        duplicateModalEl = window.mountModalOnBody(duplicateModalEl);
    }
    const duplicateModal = duplicateModalEl
        ? bootstrap.Modal.getOrCreateInstance(duplicateModalEl)
        : null;
    let exportModalEl = document.getElementById('budgetExportModal');
    if (exportModalEl && typeof window.mountModalOnBody === 'function') {
        exportModalEl = window.mountModalOnBody(exportModalEl);
    }
    const exportModal = exportModalEl
        ? bootstrap.Modal.getOrCreateInstance(exportModalEl)
        : null;
    let lineModalEl = document.getElementById('budgetLineModal');
    if (lineModalEl && typeof window.mountModalOnBody === 'function') {
        lineModalEl = window.mountModalOnBody(lineModalEl);
    }
    const lineModal = lineModalEl
        ? bootstrap.Modal.getOrCreateInstance(lineModalEl)
        : null;
    const pageRoot = document.getElementById('budgetPage');
    const actionBar = document.getElementById('budgetActionBar');
    const actionsFlyoutBtn = document.getElementById('budgetActionsFlyoutBtn');
    const actionsFlyoutClose = document.getElementById('budgetActionsFlyoutClose');
    const actionsBackdrop = document.getElementById('budgetActionsBackdrop');
    const lineCardList = document.getElementById('budgetLineCardList');
    let selectedRow = null;
    let originalStatus = 'draft';
    let formMode = 'draft';
    let savedSnapshot = null;
    let cycleData = { active: [], approved: [], current_fiscal_year: <?= (int)$currentFiscalYear ?> };
    let lineUidSeq = 1;
    let actualsCache = {};
    let actualsRange = { start: '', end: '' };
    let lineEditUid = null;
    let lineEditIsNew = false;
    let lineEditAmountBound = false;

    function isBudgetMobileChrome() {
        return window.matchMedia('(max-width: 767.98px)').matches;
    }
    function fmt(n) {
        const v = Number(n);
        const num = Number.isFinite(v) ? v : 0;
        const formatted = Math.abs(num).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        return (num < 0 ? '-$' : '$') + formatted;
    }
    function remainingText(n) {
        if (n === null || n === undefined || n === '') return '—';
        const v = Number(n);
        if (!Number.isFinite(v)) return '—';
        return fmt(v);
    }
    function setRemainingEl(el, value, extraClass) {
        if (!el) return;
        const has = value !== null && value !== undefined && Number.isFinite(Number(value));
        el.textContent = has ? remainingText(value) : '—';
        el.classList.toggle('is-negative', has && Number(value) < 0);
        el.classList.toggle('text-muted', !has);
        if (extraClass) el.classList.toggle(extraClass, has && Number(value) < 0);
    }
    function currentPeriod() {
        return {
            start: document.getElementById('startDate')?.value || '',
            end: document.getElementById('endDate')?.value || ''
        };
    }
    function seedActual(accountId, actual) {
        if (!accountId) return;
        actualsCache[String(accountId)] = Number(actual) || 0;
    }
    function getCachedActual(accountId) {
        if (!accountId) return 0;
        const v = actualsCache[String(accountId)];
        return v === undefined ? 0 : Number(v) || 0;
    }
    function remainingForAmount(amount, accountId) {
        const period = currentPeriod();
        if (!accountId || !period.start || !period.end) return null;
        return Math.round(((Number(amount) || 0) - getCachedActual(accountId)) * 100) / 100;
    }
    function fetchActuals(accountIds) {
        const period = currentPeriod();
        if (period.start !== actualsRange.start || period.end !== actualsRange.end) {
            actualsCache = {};
            actualsRange = { start: period.start, end: period.end };
        }
        const ids = [...new Set((accountIds || []).map(id => String(id)).filter(id => id && id !== '0'))];
        if (!period.start || !period.end || !ids.length) return Promise.resolve();
        const missing = ids.filter(id => actualsCache[id] === undefined);
        if (!missing.length) return Promise.resolve();
        const q = new URLSearchParams({
            line_actuals: '1',
            start_date: period.start,
            end_date: period.end,
            account_ids: missing.join(',')
        });
        return fetch(`pages/${page}.php?${q.toString()}`)
            .then(r => r.json())
            .then(data => {
                Object.entries(data.actuals || {}).forEach(([k, v]) => {
                    actualsCache[String(k)] = Number(v) || 0;
                });
                missing.forEach(id => {
                    if (actualsCache[id] === undefined) actualsCache[id] = 0;
                });
            })
            .catch(() => {
                missing.forEach(id => { if (actualsCache[id] === undefined) actualsCache[id] = 0; });
            });
    }
    function rowAccountId(tr) {
        return tr.querySelector('.line-account')?.value || tr.dataset.accountId || '';
    }
    function rowAmount(tr) {
        const input = tr.querySelector('.line-amount');
        if (input) return parseFloat(input.dataset.amount || '0') || 0;
        return parseFloat(tr.dataset.amount || '0') || 0;
    }
    function applyRowRemaining(tr) {
        const aid = rowAccountId(tr);
        const rem = remainingForAmount(rowAmount(tr), aid);
        if (aid) tr.dataset.actual = String(getCachedActual(aid));
        else delete tr.dataset.actual;
        const cell = tr.querySelector('.line-cell-remaining');
        setRemainingEl(cell, rem);
    }
    function refreshAllRemainings() {
        const ids = [...linesBody.querySelectorAll('tr.line-row')].map(rowAccountId).filter(Boolean);
        return fetchActuals(ids).then(() => {
            linesBody.querySelectorAll('tr.line-row').forEach(applyRowRemaining);
            updateTotal();
        });
    }
    function escHtml(s) {
        return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function escAttr(s) {
        return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }
    function showBudgetNameTip() {
        if (!budgetNameTip || !budgetNameInput || budgetNameInput.disabled || budgetNameInput.readOnly) return;
        budgetNameTip.classList.remove('d-none');
    }
    function hideBudgetNameTip() {
        if (!budgetNameTip) return;
        budgetNameTip.classList.add('d-none');
    }
    if (budgetNameInput) {
        budgetNameInput.addEventListener('focus', showBudgetNameTip);
        budgetNameInput.addEventListener('input', showBudgetNameTip);
        budgetNameInput.addEventListener('blur', hideBudgetNameTip);
    }
    function accountById(id) {
        return (lookups.accounts || []).find(o => String(o.id) === String(id)) || null;
    }
    function sortLinesByCoa(lines) {
        return [...(lines || [])].sort((a, b) => {
            const acctA = accountById(a.account_id);
            const acctB = accountById(b.account_id);
            const ca = String(a.coa_number || acctA?.coa_number || '').trim();
            const cb = String(b.coa_number || acctB?.coa_number || '').trim();
            const aEmpty = ca === '' || ca === '—';
            const bEmpty = cb === '' || cb === '—';
            if (aEmpty !== bEmpty) return aEmpty ? 1 : -1;
            const cmp = ca.localeCompare(cb, 'en', { numeric: true, sensitivity: 'base' });
            if (cmp !== 0) return cmp;
            const na = String(a.account_name || acctA?.name || '');
            const nb = String(b.account_name || acctB?.name || '');
            return na.localeCompare(nb, 'en', { sensitivity: 'base' });
        });
    }
    function accountOpts(val) {
        return '<option value="">— Select account —</option>' + (lookups.accounts || []).map(o =>
            `<option value="${o.id}"${o.id == val ? ' selected' : ''} data-coa-number="${escAttr(o.coa_number || '')}" data-natural-name="${escAttr(o.natural_name)}" data-functional-name="${escAttr(o.functional_name)}">${escAttr(o.name)}</option>`
        ).join('');
    }
    function syncLineCategoryLabels(tr) {
        const sel = tr.querySelector('.line-account');
        const coaEl = tr.querySelector('.line-coa-label');
        const natEl = tr.querySelector('.line-natural-label');
        const funEl = tr.querySelector('.line-functional-label');
        if (!natEl || !funEl) return;
        const opt = sel && sel.selectedOptions ? sel.selectedOptions[0] : null;
        const coaRaw = (opt && opt.value) ? (opt.dataset.coaNumber || '') : '';
        const coa = coaRaw.trim() !== '' ? coaRaw.trim() : '—';
        const nat = (opt && opt.value) ? (opt.dataset.naturalName || '—') : '—';
        const fun = (opt && opt.value) ? (opt.dataset.functionalName || '—') : '—';
        if (coaEl) {
            coaEl.textContent = coa;
            coaEl.title = coa;
        }
        natEl.textContent = nat;
        natEl.title = nat;
        funEl.textContent = fun;
        funEl.title = fun;
    }
    function withinOneWeek(dateStr) {
        if (!dateStr) return false;
        const d = new Date(dateStr + 'T12:00:00');
        const t = new Date(); t.setHours(12, 0, 0, 0);
        return Math.abs(d - t) <= 7 * 86400000;
    }
    function updateTotal() {
        let t = 0;
        let rem = 0;
        let remCount = 0;
        linesBody.querySelectorAll('tr.line-row').forEach(tr => {
            const amt = rowAmount(tr);
            t += amt;
            const r = remainingForAmount(amt, rowAccountId(tr));
            if (r !== null) {
                rem += r;
                remCount++;
            }
        });
        if (linesTotal) linesTotal.textContent = fmt(t);
        const remEl = document.getElementById('linesRemaining');
        setRemainingEl(remEl, remCount ? rem : null);
        if (remEl) remEl.classList.toggle('fw-bold', true);
        const mobTotal = document.getElementById('mobileLinesTotal');
        const mobRem = document.getElementById('mobileLinesRemaining');
        if (mobTotal) mobTotal.textContent = fmt(t);
        if (mobRem) {
            if (!remCount) {
                mobRem.textContent = 'Remaining —';
                mobRem.classList.remove('is-negative');
            } else {
                mobRem.textContent = 'Remaining ' + remainingText(rem);
                mobRem.classList.toggle('is-negative', rem < 0);
            }
        }
        renderMobileLineCards();
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
            const tr = input.closest('tr');
            if (tr) {
                applyRowRemaining(tr);
                updateTotal();
            }
        });
        input.addEventListener('focus', () => input.select());
    }
    function collectLines() {
        return [...linesBody.querySelectorAll('tr')].map(tr => ({
            id: tr.dataset.lineId || '',
            account_id: tr.querySelector('.line-account')?.value || tr.dataset.accountId || '',
            budgeted_amount: tr.querySelector('.line-amount')?.dataset.amount || tr.dataset.amount || '0',
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
    function markBudgetClean() {
        if (formEl) formEl.removeAttribute('data-dirty');
    }
    function confirmDiscard() {
        if (!isDirty()) {
            markBudgetClean();
            return true;
        }
        const msg = (typeof window.TemperDirtyForms !== 'undefined' && window.TemperDirtyForms.MESSAGE)
            ? window.TemperDirtyForms.MESSAGE
            : 'You have unsaved changes. Leave anyway?';
        if (!confirm(msg)) return false;
        markBudgetClean();
        return true;
    }
    if (typeof window.TemperDirtyForms !== 'undefined') {
        window.TemperDirtyForms.registerChecker(isDirty);
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
        return confirm('Setting status to Approved will lock budget amounts and accounts. You may still edit notes. Continue?');
    }
    function addLine(data, mode) {
        data = data || {};
        const tr = document.createElement('tr');
        tr.classList.add('line-row');
        tr.dataset.uid = data.uid || ('n' + (lineUidSeq++));
        if (data.id) tr.dataset.lineId = data.id;
        if (data.account_id) tr.dataset.accountId = data.account_id;
        const amt = parseFloat(data.budgeted_amount) || 0;
        if (data.actual !== undefined && data.actual !== null && data.account_id) {
            seedActual(data.account_id, data.actual);
            tr.dataset.actual = String(Number(data.actual) || 0);
        }
        const acctInfo = accountById(data.account_id);
        const coaRaw = data.coa_number || (acctInfo && acctInfo.coa_number) || '';
        const coaLabel = String(coaRaw).trim() !== '' ? String(coaRaw).trim() : '—';
        const natLabel = data.natural_name || (acctInfo && acctInfo.natural_name) || '—';
        const funLabel = data.functional_name || (acctInfo && acctInfo.functional_name) || '—';
        const acctLabel = data.account_name || (acctInfo && acctInfo.name) || '—';
        if (mode === 'draft') {
            tr.innerHTML = `
                <td class="line-cell-cat" data-label="Account"><select class="form-select form-select-sm line-account" required>${accountOpts(data.account_id)}</select></td>
                <td class="line-cell-cat" data-label="CoA #"><span class="line-cat-label line-coa-label" title="">—</span></td>
                <td class="line-cell-cat" data-label="Natural"><span class="line-cat-label line-natural-label" title="">—</span></td>
                <td class="line-cell-cat" data-label="Functional"><span class="line-cat-label line-functional-label" title="">—</span></td>
                <td class="line-cell-amount" data-label="Amount"><input type="text" class="form-control form-control-sm text-end line-amount" inputmode="numeric"></td>
                <td class="text-end line-cell-remaining" data-label="Remaining">—</td>
                <td class="line-cell-notes" data-label="Notes"><input type="text" class="form-control form-control-sm line-notes" value="${escAttr(data.notes || '')}"></td>
                <td class="line-actions" data-label=""><button type="button" class="btn btn-sm btn-outline-danger rm-line"><i class="bi bi-x"></i></button></td>`;
            bindAmountInput(tr.querySelector('.line-amount'), data.budgeted_amount);
            const accSel = tr.querySelector('.line-account');
            accSel.addEventListener('change', () => {
                syncLineCategoryLabels(tr);
                const aid = accSel.value;
                if (aid) tr.dataset.accountId = aid;
                else delete tr.dataset.accountId;
                fetchActuals(aid ? [aid] : []).then(() => {
                    applyRowRemaining(tr);
                    updateTotal();
                });
            });
            syncLineCategoryLabels(tr);
            tr.querySelector('.rm-line').addEventListener('click', () => { tr.remove(); updateTotal(); });
        } else {
            const notesVal = data.notes || '';
            const notesCell = mode === 'approved'
                ? `<input type="text" class="form-control form-control-sm line-notes" value="${escAttr(notesVal)}">`
                : `<span class="line-cell-text line-notes-readonly" title="${escAttr(notesVal)}">${escAttr(notesVal)}</span>`;
            tr.innerHTML = `
                <td class="line-cell-cat" data-label="Account"><span class="line-cell-text" title="${escAttr(acctLabel)}">${escAttr(acctLabel)}</span></td>
                <td class="line-cell-cat" data-label="CoA #"><span class="line-cat-label line-coa-label" title="${escAttr(coaLabel)}">${escAttr(coaLabel)}</span></td>
                <td class="line-cell-cat" data-label="Natural"><span class="line-cat-label" title="${escAttr(natLabel)}">${escAttr(natLabel)}</span></td>
                <td class="line-cell-cat" data-label="Functional"><span class="line-cat-label" title="${escAttr(funLabel)}">${escAttr(funLabel)}</span></td>
                <td class="text-end line-cell-amount" data-label="Amount">${fmt(amt)}</td>
                <td class="text-end line-cell-remaining" data-label="Remaining">—</td>
                <td class="line-cell-notes" data-label="Notes">${notesCell}</td><td class="line-actions" data-label=""></td>`;
            tr.dataset.amount = amt;
        }
        linesBody.appendChild(tr);
        applyRowRemaining(tr);
        updateTotal();
        return tr;
    }
    function lineDisplayFromRow(tr) {
        const aid = rowAccountId(tr);
        const acct = accountById(aid);
        const coaEl = tr.querySelector('.line-coa-label');
        return {
            uid: tr.dataset.uid,
            account_id: aid,
            account_name: acct?.name || tr.querySelector('.line-cell-cat .line-cell-text')?.textContent || '',
            coa: (coaEl?.textContent || acct?.coa_number || '').trim() || '—',
            amount: rowAmount(tr),
            remaining: remainingForAmount(rowAmount(tr), aid),
            notes: tr.querySelector('.line-notes')?.value ?? tr.querySelector('.line-notes-readonly')?.textContent ?? ''
        };
    }
    function renderMobileLineCards() {
        if (!lineCardList) return;
        const rows = [...linesBody.querySelectorAll('tr.line-row')];
        if (!rows.length) {
            const empty = formMode === 'draft'
                ? 'No lines yet. Use Add Line to create one.'
                : 'No budget lines.';
            lineCardList.innerHTML = `<div class="budget-line-cards-empty">${empty}</div>`;
            return;
        }
        lineCardList.innerHTML = rows.map(tr => {
            const d = lineDisplayFromRow(tr);
            const name = d.account_name || 'Select account…';
            const remClass = (d.remaining !== null && d.remaining < 0) ? ' is-negative' : '';
            const remLabel = d.remaining === null ? 'Remaining —' : ('Remaining ' + remainingText(d.remaining));
            return `<article class="budget-line-card" data-uid="${escAttr(d.uid)}" role="button" tabindex="0">
                <div class="budget-line-card-main">
                    <div class="budget-line-card-name">${escHtml(name)}</div>
                    <div class="budget-line-card-coa">${escHtml(d.coa)}</div>
                </div>
                <div class="budget-line-card-figures">
                    <div class="budget-line-card-amount">${fmt(d.amount)}</div>
                    <div class="budget-line-card-remaining${remClass}">${escHtml(remLabel)}</div>
                </div>
            </article>`;
        }).join('');
    }
    function syncLineEditCategoryLabels() {
        const sel = document.getElementById('lineEditAccount');
        const opt = sel && sel.selectedOptions ? sel.selectedOptions[0] : null;
        const has = !!(opt && opt.value);
        document.getElementById('lineEditCoa').textContent = has ? ((opt.dataset.coaNumber || '').trim() || '—') : '—';
        document.getElementById('lineEditNatural').textContent = has ? (opt.dataset.naturalName || '—') : '—';
        document.getElementById('lineEditFunctional').textContent = has ? (opt.dataset.functionalName || '—') : '—';
        updateLineEditRemaining();
    }
    function lineEditAmountValue() {
        const input = document.getElementById('lineEditAmount');
        return parseFloat(input?.dataset.amount || '0') || 0;
    }
    function updateLineEditRemaining() {
        const aid = document.getElementById('lineEditAccount')?.value || '';
        const rem = remainingForAmount(lineEditAmountValue(), aid);
        setRemainingEl(document.getElementById('lineEditRemaining'), rem);
    }
    function setLineEditorEditable(canEditAccountAmount, canEditNotes) {
        const accSel = document.getElementById('lineEditAccount');
        const accDisp = document.getElementById('lineEditAccountDisplay');
        const amtInput = document.getElementById('lineEditAmount');
        const amtDisp = document.getElementById('lineEditAmountDisplay');
        const notes = document.getElementById('lineEditNotes');
        const applyBtn = document.getElementById('lineEditApply');
        const delBtn = document.getElementById('lineEditDelete');
        accSel.classList.toggle('d-none', !canEditAccountAmount);
        accDisp.classList.toggle('d-none', canEditAccountAmount);
        amtInput.classList.toggle('d-none', !canEditAccountAmount);
        amtDisp.classList.toggle('d-none', canEditAccountAmount);
        notes.readOnly = !canEditNotes;
        notes.disabled = !canEditNotes;
        applyBtn.classList.toggle('d-none', !canEditNotes && !canEditAccountAmount);
        delBtn.classList.toggle('d-none', !canEditAccountAmount || lineEditIsNew);
    }
    function openLineEditor(tr) {
        if (!lineModal || !lineModalEl) return;
        lineEditIsNew = !tr;
        lineEditUid = tr ? tr.dataset.uid : '';
        const accSel = document.getElementById('lineEditAccount');
        const accDisp = document.getElementById('lineEditAccountDisplay');
        const amtInput = document.getElementById('lineEditAmount');
        const amtDisp = document.getElementById('lineEditAmountDisplay');
        const notes = document.getElementById('lineEditNotes');
        const title = document.getElementById('budgetLineModalTitle');
        const aid = tr ? rowAccountId(tr) : '';
        const amt = tr ? rowAmount(tr) : 0;
        const acct = accountById(aid);
        accSel.innerHTML = accountOpts(aid);
        accDisp.textContent = acct?.name || (tr ? (tr.querySelector('.line-cell-cat .line-cell-text')?.textContent || '—') : '—');
        if (!lineEditAmountBound) {
            bindAmountInput(amtInput, amt);
            amtInput.addEventListener('input', updateLineEditRemaining);
            lineEditAmountBound = true;
        } else {
            amtInput.dataset.amount = amt;
            amtInput.value = fmt(amt);
        }
        amtDisp.textContent = fmt(amt);
        notes.value = tr
            ? (tr.querySelector('.line-notes')?.value ?? tr.querySelector('.line-notes-readonly')?.textContent ?? '')
            : '';
        title.textContent = lineEditIsNew ? 'Add Line' : (formMode === 'draft' ? 'Edit Line' : 'Budget Line');
        const canEditAll = formMode === 'draft';
        const canEditNotes = formMode === 'draft' || formMode === 'approved';
        setLineEditorEditable(canEditAll, canEditNotes);
        syncLineEditCategoryLabels();
        if (aid) {
            fetchActuals([aid]).then(updateLineEditRemaining);
        } else {
            updateLineEditRemaining();
        }
        if (typeof window.mountModalOnBody === 'function') {
            lineModalEl = window.mountModalOnBody(lineModalEl);
        }
        lineModal.show();
    }
    function applyLineEditor() {
        const aid = document.getElementById('lineEditAccount').value;
        const amt = lineEditAmountValue();
        const notes = document.getElementById('lineEditNotes').value || '';
        if (formMode === 'draft') {
            if (!aid) {
                showToast('Select an account for this line.', 'warning');
                return;
            }
            if (amt <= 0) {
                showToast('Amount must be greater than zero.', 'warning');
                return;
            }
        }
        if (lineEditIsNew) {
            const acct = accountById(aid);
            addLine({
                account_id: aid,
                budgeted_amount: amt,
                notes: notes,
                account_name: acct?.name || '',
                coa_number: acct?.coa_number || '',
                natural_name: acct?.natural_name || '',
                functional_name: acct?.functional_name || '',
                actual: getCachedActual(aid)
            }, 'draft');
        } else {
            const tr = linesBody.querySelector('tr[data-uid="' + lineEditUid + '"]');
            if (!tr) return;
            if (formMode === 'draft') {
                const sel = tr.querySelector('.line-account');
                if (sel) sel.value = aid;
                tr.dataset.accountId = aid;
                const amtInput = tr.querySelector('.line-amount');
                if (amtInput) {
                    amtInput.dataset.amount = amt;
                    amtInput.value = fmt(amt);
                }
                tr.dataset.amount = amt;
                syncLineCategoryLabels(tr);
                applyRowRemaining(tr);
            }
            const notesInput = tr.querySelector('.line-notes');
            if (notesInput) notesInput.value = notes;
            const notesRo = tr.querySelector('.line-notes-readonly');
            if (notesRo) {
                notesRo.textContent = notes;
                notesRo.title = notes;
            }
            updateTotal();
        }
        if (lineModal) lineModal.hide();
    }
    function setPageViewMode(mode) {
        if (!pageRoot) return;
        pageRoot.classList.toggle('is-draft-view', mode === 'draft');
        pageRoot.classList.toggle('is-readonly-view', mode === 'approved' || mode === 'locked');
        pageRoot.classList.toggle('is-locked-view', mode === 'locked');
    }
    const statusBadgeClass = { draft: 'warning', approved: 'info', active: 'success', closed: 'secondary' };
    function renderSummaryCard() {
        const name = document.getElementById('budgetName')?.value || 'Budget';
        const year = document.getElementById('fiscalYear')?.value || '';
        const start = document.getElementById('startDate')?.value || '';
        const end = document.getElementById('endDate')?.value || '';
        const ref = refInput?.value || '';
        const approved = approvedDateInput?.value || '';
        const desc = document.getElementById('budgetDesc')?.value || '';
        const statusRaw = formMode === 'locked'
            ? (document.getElementById('budgetStatusDisplay')?.value || originalStatus || '')
            : (document.getElementById('budgetStatus')?.value || originalStatus || '');
        const status = String(statusRaw).toLowerCase();
        const statusLabel = status ? status.charAt(0).toUpperCase() + status.slice(1) : '';
        const setTxt = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v || '—'; };
        setTxt('budgetSummaryName', name);
        setTxt('budgetSummaryPeriod', (start && end) ? (start + ' – ' + end) : '');
        setTxt('budgetSummaryYear', year);
        setTxt('budgetSummaryRef', ref);
        setTxt('budgetSummaryApproved', approved);
        setTxt('budgetSummaryDesc', desc);
        const badge = document.getElementById('budgetSummaryStatus');
        if (badge) {
            badge.textContent = statusLabel;
            badge.className = 'badge bg-' + (statusBadgeClass[status] || 'secondary');
        }
    }
    function setFormMode(mode) {
        formMode = mode;
        setPageViewMode(mode);
        renderSummaryCard();
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
    function viewedBudgetId() {
        const fromRow = selectedRow && selectedRow.dataset.id ? String(selectedRow.dataset.id) : '';
        const fromForm = (document.getElementById('budgetId')?.value || '').trim();
        const id = fromRow || fromForm;
        return /^\d+$/.test(id) && Number(id) > 0 ? id : '';
    }
    function downloadBudgetFile(budgetId, format) {
        const qs = new URLSearchParams({ budget_id: String(budgetId), format: format === 'pdf' ? 'pdf' : 'csv' });
        return fetch('pages/budget_export.php?' + qs.toString())
            .then(async r => {
                const ct = (r.headers.get('Content-Type') || '').toLowerCase();
                if (!r.ok || ct.includes('application/json') || ct.includes('text/html') || ct.includes('text/plain')) {
                    let msg = 'Export failed.';
                    if (ct.includes('application/json')) {
                        const data = await r.json();
                        msg = data.error || msg;
                    } else {
                        const t = await r.text();
                        const tmp = document.createElement('div');
                        tmp.innerHTML = t;
                        msg = (tmp.textContent || msg).trim() || msg;
                    }
                    throw new Error(msg);
                }
                const disp = r.headers.get('Content-Disposition') || '';
                let filename = 'budget.' + (format === 'pdf' ? 'pdf' : 'csv');
                const star = /filename\*=UTF-8''([^;]+)/i.exec(disp);
                const plain = /filename="?([^";]+)"?/i.exec(disp);
                if (star) filename = decodeURIComponent(star[1]);
                else if (plain) filename = plain[1];
                const blob = await r.blob();
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(() => URL.revokeObjectURL(url), 1000);
            });
    }
    function updateActionButtons() {
        deleteBtn.disabled = !selectedRow || selectedRow.dataset.status !== 'draft';
        if (duplicateBtn) duplicateBtn.disabled = !selectedRow;
        if (exportBtn) exportBtn.disabled = !viewedBudgetId();
    }
    function showForm(title) {
        document.getElementById('formTitle').textContent = title;
        form.classList.remove('d-none');
        updateActionButtons();
    }
    function hideForm() {
        form.classList.add('d-none');
        savedSnapshot = null;
        markBudgetClean();
        setPageViewMode('');
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
            .then(h => {
                markBudgetClean();
                applyMainContent(h);
            })
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
        actualsCache = {};
        actualsRange = { start: b.start_date || '', end: b.end_date || '' };
        linesBody.innerHTML = '';
        const hasLines = Array.isArray(b.lines) && b.lines.length;
        const lines = hasLines
            ? sortLinesByCoa(b.lines)
            : (mode === 'draft' && !isBudgetMobileChrome() ? [{}] : []);
        lines.forEach(l => addLine(l, mode));
        setFormMode(mode);
        renderMobileLineCards();
        const titles = { draft: b.id ? 'Edit Budget' : 'New Budget', approved: 'View Budget (Notes Editable)', locked: 'View Budget' };
        showForm(titles[mode]);
        savedSnapshot = getSnapshot();
        markBudgetClean();
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
            if (!cycleModal || !cycleModalEl) return;
            if (typeof window.mountModalOnBody === 'function') {
                cycleModalEl = window.mountModalOnBody(cycleModalEl);
            }
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

    if (addBtn) addBtn.addEventListener('click', () => {
        if (!confirmDiscard()) return;
        formEl.reset();
        originalStatus = 'draft';
        selectedRow = null;
        tableBody.querySelectorAll('tr.table-primary').forEach(r => r.classList.remove('table-primary'));
        const y = new Date().getFullYear();
        populateForm({ fiscal_year: y, start_date: `${y}-01-01`, end_date: `${y}-12-31`, status: 'draft', lines: [] });
        updateActionButtons();
    });

    function suggestedDuplicateName(sourceName) {
        const base = String(sourceName || '').trim() || 'Budget';
        const prefix = 'Copy of ';
        const max = 100;
        if ((prefix + base).length <= max) return prefix + base;
        return (prefix + base).slice(0, max);
    }
    function openDuplicateModal() {
        if (!selectedRow || !duplicateModal || !duplicateModalEl) return;
        document.getElementById('duplicateSourceId').value = selectedRow.dataset.id || '';
        const srcName = selectedRow.dataset.name || '';
        const fy = selectedRow.dataset.fiscalYear || '';
        const status = selectedRow.dataset.status || '';
        const period = [selectedRow.dataset.startDate, selectedRow.dataset.endDate].filter(Boolean).join(' – ');
        document.getElementById('duplicateSourceLabel').textContent =
            `${srcName} (FY ${fy}${status ? ', ' + status : ''}${period ? ' · ' + period : ''})`;
        document.getElementById('duplicateName').value = suggestedDuplicateName(srcName);
        document.getElementById('duplicateFiscalYear').value = fy;
        document.getElementById('duplicateStartDate').value = selectedRow.dataset.startDate || '';
        document.getElementById('duplicateEndDate').value = selectedRow.dataset.endDate || '';
        if (typeof window.mountModalOnBody === 'function') {
            duplicateModalEl = window.mountModalOnBody(duplicateModalEl);
        }
        duplicateModal.show();
        setTimeout(() => {
            const nameEl = document.getElementById('duplicateName');
            if (nameEl) { nameEl.focus(); nameEl.select(); }
        }, 150);
    }
    if (duplicateBtn) duplicateBtn.addEventListener('click', () => {
        if (!selectedRow) return;
        if (!confirmDiscard()) return;
        openDuplicateModal();
    });
    function openExportModal() {
        const id = viewedBudgetId();
        if (!id || !exportModal || !exportModalEl) {
            showToast('Select a budget to export.', 'warning');
            return;
        }
        const name = selectedRow?.dataset.name || document.getElementById('budgetName')?.value || 'Budget';
        const fy = selectedRow?.dataset.fiscalYear || document.getElementById('fiscalYear')?.value || '';
        const period = [selectedRow?.dataset.startDate, selectedRow?.dataset.endDate].filter(Boolean).join(' – ')
            || [document.getElementById('startDate')?.value, document.getElementById('endDate')?.value].filter(Boolean).join(' – ');
        const status = selectedRow?.dataset.status || '';
        const bits = [name];
        if (fy) bits.push('FY ' + fy);
        if (status) bits.push(status);
        if (period) bits.push(period);
        const label = document.getElementById('budgetExportSourceLabel');
        if (label) label.textContent = bits.join(' · ');
        const csv = document.getElementById('budgetExportCsv');
        if (csv) csv.checked = true;
        if (typeof window.mountModalOnBody === 'function') {
            exportModalEl = window.mountModalOnBody(exportModalEl);
        }
        exportModal.show();
    }
    if (exportBtn) exportBtn.addEventListener('click', () => {
        if (!viewedBudgetId()) return;
        openExportModal();
    });
    const exportConfirm = document.getElementById('budgetExportConfirm');
    if (exportConfirm) exportConfirm.addEventListener('click', () => {
        const id = viewedBudgetId();
        if (!id) {
            showToast('Select a budget to export.', 'warning');
            return;
        }
        const format = document.querySelector('input[name="budgetExportFormat"]:checked')?.value || 'csv';
        exportConfirm.disabled = true;
        downloadBudgetFile(id, format)
            .then(() => {
                if (exportModal) exportModal.hide();
            })
            .catch(err => showToast(err.message || 'Export failed.', 'danger'))
            .finally(() => { exportConfirm.disabled = false; });
    });
    const duplicateForm = document.getElementById('duplicateForm');
    if (duplicateForm) duplicateForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const sourceId = document.getElementById('duplicateSourceId').value;
        const name = (document.getElementById('duplicateName').value || '').trim();
        const fy = document.getElementById('duplicateFiscalYear').value;
        const start = document.getElementById('duplicateStartDate').value;
        const end = document.getElementById('duplicateEndDate').value;
        if (!sourceId || !name || !fy || !start || !end) {
            showToast('Name, year, start date, and end date are required.', 'warning');
            return;
        }
        if (start > end) {
            showToast('Start date must be on or before end date.', 'warning');
            return;
        }
        const fd = new FormData();
        fd.append('action', 'duplicate');
        fd.append('source_id', sourceId);
        fd.append('name', name);
        fd.append('fiscal_year', fy);
        fd.append('start_date', start);
        fd.append('end_date', end);
        const confirmBtn = document.getElementById('duplicateConfirmBtn');
        if (confirmBtn) confirmBtn.disabled = true;
        fetch(`pages/${page}.php`, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.error) {
                    showToast(res.error, 'danger');
                    return;
                }
                if (duplicateModal) duplicateModal.hide();
                if (res.id) sessionStorage.setItem('budgetOpenId', String(res.id));
                showToast(res.message || 'Draft budget created.', 'success');
                reload();
            })
            .catch(() => showToast('Budget duplicate failed. Please try again.', 'danger'))
            .finally(() => { if (confirmBtn) confirmBtn.disabled = false; });
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
                    if (cycleModal) cycleModal.hide();
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
                    if (cycleModal) cycleModal.hide();
                    showToast(res.message || 'Budget closed successfully.', 'success');
                    reload();
                }
            })
            .catch(() => showToast('Budget close failed. Please try again.', 'danger'));
    });

    addLineBtn.addEventListener('click', () => {
        if (isBudgetMobileChrome()) openLineEditor(null);
        else addLine({}, 'draft');
    });
    const lineEditAccount = document.getElementById('lineEditAccount');
    if (lineEditAccount) {
        lineEditAccount.addEventListener('change', () => {
            const aid = lineEditAccount.value;
            fetchActuals(aid ? [aid] : []).then(() => syncLineEditCategoryLabels());
        });
    }
    const lineEditApply = document.getElementById('lineEditApply');
    if (lineEditApply) lineEditApply.addEventListener('click', applyLineEditor);
    const lineEditDelete = document.getElementById('lineEditDelete');
    if (lineEditDelete) {
        lineEditDelete.addEventListener('click', () => {
            if (lineEditIsNew || !lineEditUid) { if (lineModal) lineModal.hide(); return; }
            const tr = linesBody.querySelector('tr[data-uid="' + lineEditUid + '"]');
            if (tr) tr.remove();
            updateTotal();
            if (lineModal) lineModal.hide();
        });
    }
    if (lineCardList) {
        lineCardList.addEventListener('click', e => {
            const card = e.target.closest('.budget-line-card');
            if (!card) return;
            const tr = linesBody.querySelector('tr[data-uid="' + card.dataset.uid + '"]');
            if (tr) openLineEditor(tr);
        });
        lineCardList.addEventListener('keydown', e => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            const card = e.target.closest('.budget-line-card');
            if (!card) return;
            e.preventDefault();
            const tr = linesBody.querySelector('tr[data-uid="' + card.dataset.uid + '"]');
            if (tr) openLineEditor(tr);
        });
    }
    ['startDate', 'endDate'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', () => {
            actualsCache = {};
            actualsRange = { start: '', end: '' };
            refreshAllRemainings();
            renderSummaryCard();
        });
    });
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

    function mountBudgetMobileHeaderTools() {
        const slot = document.getElementById('mobileTopbarEnd');
        const tools = document.getElementById('budgetMobileHeaderTools');
        if (!slot || !tools) return;
        while (tools.firstChild) slot.appendChild(tools.firstChild);
    }
    function unmountBudgetMobileHeaderTools() {
        const slot = document.getElementById('mobileTopbarEnd');
        const tools = document.getElementById('budgetMobileHeaderTools');
        if (!slot) return;
        if (tools) {
            while (slot.firstChild) tools.appendChild(slot.firstChild);
        } else {
            slot.replaceChildren();
        }
    }
    function closeBudgetActionsFlyout() {
        if (pageRoot) pageRoot.classList.remove('budget-actions-open');
        if (actionsFlyoutBtn) {
            actionsFlyoutBtn.setAttribute('aria-expanded', 'false');
            actionsFlyoutBtn.classList.remove('active');
        }
        if (actionBar) actionBar.setAttribute('aria-hidden', isBudgetMobileChrome() ? 'true' : 'false');
        if (actionsBackdrop) actionsBackdrop.hidden = true;
    }
    function openBudgetActionsFlyout() {
        if (!pageRoot) return;
        pageRoot.classList.add('budget-actions-open');
        if (actionsFlyoutBtn) {
            actionsFlyoutBtn.setAttribute('aria-expanded', 'true');
            actionsFlyoutBtn.classList.add('active');
        }
        if (actionBar) actionBar.setAttribute('aria-hidden', 'false');
        if (actionsBackdrop) actionsBackdrop.hidden = false;
    }
    function toggleBudgetActionsFlyout() {
        if (pageRoot && pageRoot.classList.contains('budget-actions-open')) closeBudgetActionsFlyout();
        else openBudgetActionsFlyout();
    }
    function onBudgetActionsKeydown(e) {
        if (e.key === 'Escape' && pageRoot && pageRoot.classList.contains('budget-actions-open')) {
            e.preventDefault();
            closeBudgetActionsFlyout();
        }
    }
    function onBudgetChromeResize() {
        if (!isBudgetMobileChrome()) {
            closeBudgetActionsFlyout();
            if (actionBar) actionBar.setAttribute('aria-hidden', 'false');
        }
    }
    function onBudgetSidebarShow() { closeBudgetActionsFlyout(); }
    function wireBudgetActionsFlyout() {
        mountBudgetMobileHeaderTools();
        if (isBudgetMobileChrome() && actionBar) actionBar.setAttribute('aria-hidden', 'true');
        if (actionsFlyoutBtn) {
            actionsFlyoutBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleBudgetActionsFlyout();
            });
        }
        if (actionsFlyoutClose) {
            actionsFlyoutClose.addEventListener('click', function(e) {
                e.preventDefault();
                closeBudgetActionsFlyout();
            });
        }
        if (actionsBackdrop) {
            actionsBackdrop.addEventListener('click', function() { closeBudgetActionsFlyout(); });
        }
        if (actionBar) {
            actionBar.addEventListener('click', function(e) {
                if (!isBudgetMobileChrome()) return;
                if (!pageRoot || !pageRoot.classList.contains('budget-actions-open')) return;
                if (e.target.closest('#budgetActionsFlyoutClose')) return;
                const actionEl = e.target.closest('button');
                if (!actionEl || actionEl.disabled || actionEl.classList.contains('disabled')) return;
                closeBudgetActionsFlyout();
            });
        }
        document.addEventListener('keydown', onBudgetActionsKeydown);
        window.addEventListener('resize', onBudgetChromeResize);
        const sidebar = document.getElementById('appSidebar');
        if (sidebar) sidebar.addEventListener('show.bs.offcanvas', onBudgetSidebarShow);
    }
    function disposeBudgetPage() {
        document.removeEventListener('keydown', onBudgetActionsKeydown);
        window.removeEventListener('resize', onBudgetChromeResize);
        const sidebar = document.getElementById('appSidebar');
        if (sidebar) sidebar.removeEventListener('show.bs.offcanvas', onBudgetSidebarShow);
        closeBudgetActionsFlyout();
        unmountBudgetMobileHeaderTools();
        if (window.TemperBudgetPage && window.TemperBudgetPage._bound === disposeBudgetPage) {
            window.TemperBudgetPage = { disposeActive: function() {} };
        }
    }

    wireBudgetActionsFlyout();
    window.TemperBudgetPage = {
        _bound: disposeBudgetPage,
        disposeActive: disposeBudgetPage
    };

    const pendingOpen = sessionStorage.getItem('budgetOpenId');
    if (pendingOpen && /^\d+$/.test(pendingOpen)) {
        sessionStorage.removeItem('budgetOpenId');
        const row = tableBody.querySelector('tr[data-id="' + pendingOpen + '"]');
        if (row) {
            if (selectedRow) selectedRow.classList.remove('table-primary');
            selectedRow = row;
            selectedRow.classList.add('table-primary');
            updateActionButtons();
            openBudget(pendingOpen);
        }
    }
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-budget-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>
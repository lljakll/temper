<?php
    // Ledger - Inner content only for AJAX loading

    if (!isset($db)) {
        require_once __DIR__ . '/../config.php';
        $db = getDbConnection();
    }

    $success = null;
    $error = null;

    // JSON data endpoint for edit (fetched by JS, does not render HTML)
    if (isset($_GET['get_transaction'])) {
        $id = (int)$_GET['get_transaction'];
        if ($id <= 0) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid ID']);
            exit;
        }
        $stmt = $db->prepare("SELECT id, transaction_date, pay_to, reference_number, check_number, memo, status, cleared_date FROM transaction_details WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $det = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$det) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Transaction not found']);
            exit;
        }
        // Split combined memo back into description | memo for the form if applicable
        $fullMemo = $det['memo'] ?? '';
        if (strpos($fullMemo, ' | ') !== false) {
            list($det['description'], $det['memo']) = explode(' | ', $fullMemo, 2);
        } else {
            $det['description'] = '';
            $det['memo'] = $fullMemo;
        }
        // Load lines
        $lst = $db->prepare("SELECT account_id, fund_id, amount, natural_category_id, functional_category_id FROM transaction_lines WHERE transaction_detail_id = ? ORDER BY id ASC");
        $lst->bind_param('i', $id);
        $lst->execute();
        $lines = [];
        $res = $lst->get_result();
        while ($l = $res->fetch_assoc()) {
            $lines[] = [
                'account_id' => (int)$l['account_id'],
                'fund_id' => $l['fund_id'] !== null ? (int)$l['fund_id'] : '',
                'amount' => $l['amount'],
                'natural_category_id' => $l['natural_category_id'] !== null ? (int)$l['natural_category_id'] : '',
                'functional_category_id' => $l['functional_category_id'] !== null ? (int)$l['functional_category_id'] : ''
            ];
        }
        $lst->close();
        $det['lines'] = $lines;
        header('Content-Type: application/json');
        echo json_encode($det);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'clear') {
            $ids = json_decode($_POST['selected_ids'] ?? '[]', true) ?: [];
            if (count($ids) > 0) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare("UPDATE transaction_details SET status='cleared', cleared_date=CURDATE() WHERE id IN ($in)");
                $types = str_repeat('i', count($ids));
                $stmt->bind_param($types, ...$ids);
                if ($stmt->execute()) {
                    $success = count($ids) . ' transaction(s) marked as cleared.';
                } else {
                    $error = 'Clear failed: ' . $db->error;
                }
                $stmt->close();
            }
        } elseif ($action === 'reconcile') {
            $ids = json_decode($_POST['selected_ids'] ?? '[]', true) ?: [];
            if (count($ids) > 0) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare("UPDATE transaction_details SET status='reconciled', date_reconciled=CURDATE() WHERE id IN ($in)");
                $types = str_repeat('i', count($ids));
                $stmt->bind_param($types, ...$ids);
                if ($stmt->execute()) {
                    $success = count($ids) . ' transaction(s) marked as reconciled.';
                } else {
                    $error = 'Reconcile failed: ' . $db->error;
                }
                $stmt->close();
            }
        } elseif ($action === 'save' || $action === '') {
            // Shared add / edit save handler
            $d = $_POST['transaction_date'] ?? '';
            $p = trim($_POST['pay_to'] ?? '');
            $ref = trim($_POST['reference_number'] ?? '');
            $c = trim($_POST['check_number'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $mem = trim($_POST['memo'] ?? '');
            $tx_id = (int)($_POST['tx_id'] ?? 0);
            $lines = json_decode($_POST['lines_json'] ?? '[]', true) ?: [];

            if (!$d) {
                $error = "Date is required.";
            } elseif (count($lines) < 2) {
                $error = "Every transaction must have at least 2 lines.";
            } else {
                $atypes = [];
                $ids = array_unique(array_map(fn($l) => (int)$l['account_id'], $lines));
                if ($ids) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $st = $db->prepare("SELECT id, normal_balance FROM accounts WHERE id IN ($in)");
                    $st->bind_param(str_repeat('i', count($ids)), ...$ids);
                    $st->execute();
                    $rs = $st->get_result();
                    while ($r = $rs->fetch_assoc()) {
                        $atypes[(int)$r['id']] = $r['normal_balance'];
                    }
                    $st->close();
                }
                $dt = $ct = 0.0;
                $vlines = [];
                foreach ($lines as $l) {
                    $aid = (int)($l['account_id'] ?? 0);
                    $am = (float)($l['amount'] ?? 0);
                    if ($aid <= 0 || $am <= 0) continue;
                    $t = $atypes[$aid] ?? 'debit';
                    if ($t === 'debit') $dt += $am; else $ct += $am;
                    $vlines[] = [
                        'aid' => $aid,
                        'fid' => !empty($l['fund_id']) ? (int)$l['fund_id'] : null,
                        'nid' => !empty($l['natural_category_id']) ? (int)$l['natural_category_id'] : null,
                        'fid2' => !empty($l['functional_category_id']) ? (int)$l['functional_category_id'] : null,
                        'am' => $am,
                        't' => $t
                    ];
                }
                if (count($vlines) < 2) {
                    $error = "At least two valid lines are required.";
                } elseif (abs($dt - $ct) > 0.005) {
                    $error = "Debits do not equal Credits.";
                } else {
                    $mm = $desc ? ($desc . ($mem ? ' | ' . $mem : '')) : $mem;

                    if ($tx_id > 0) {
                        // Edit / Update existing (prevent if cleared)
                        $chk = $db->prepare("SELECT status FROM transaction_details WHERE id = ?");
                        $chk->bind_param('i', $tx_id);
                        $chk->execute();
                        $crow = $chk->get_result()->fetch_assoc();
                        $chk->close();
                        if ($crow && $crow['status'] === 'cleared') {
                            $error = "Cannot edit a cleared transaction.";
                        } else {
                            $upd = $db->prepare("UPDATE transaction_details SET transaction_date=?, check_number=?, pay_to=?, reference_number=?, memo=? WHERE id=?");
                            $upd->bind_param("sssssi", $d, $c, $p, $ref, $mm, $tx_id);
                            if ($upd->execute()) {
                                $upd->close();
                                // Replace lines
                                $del = $db->prepare("DELETE FROM transaction_lines WHERE transaction_detail_id=?");
                                $del->bind_param('i', $tx_id);
                                $del->execute();
                                $del->close();

                                $ed = '';
                                $lins = $db->prepare("INSERT INTO transaction_lines(transaction_detail_id,account_id,fund_id,amount,type,natural_category_id,functional_category_id,description) VALUES(?,?,?,?,?,?,?,?)");
                                foreach ($vlines as $v) {
                                    $lins->bind_param("iiidsiis", $tx_id, $v['aid'], $v['fid'], $v['am'], $v['t'], $v['nid'], $v['fid2'], $ed);
                                    $lins->execute();
                                }
                                $lins->close();
                                $success = "Transaction #$tx_id updated. Debits $" . number_format($dt, 2) . " = Credits $" . number_format($ct, 2);
                            } else {
                                $error = "Update failed: " . $db->error;
                                $upd->close();
                            }
                        }
                    } else {
                        // New transaction
                        $ins = $db->prepare("INSERT INTO transaction_details(transaction_date,check_number,pay_to,reference_number,memo,status) VALUES(?,?,?,?,?,'pending')");
                        $ins->bind_param("sssss", $d, $c, $p, $ref, $mm);
                        if ($ins->execute()) {
                            $tid = $db->insert_id;
                            $ins->close();

                            $ed = '';
                            $lins = $db->prepare("INSERT INTO transaction_lines(transaction_detail_id,account_id,fund_id,amount,type,natural_category_id,functional_category_id,description) VALUES(?,?,?,?,?,?,?,?)");
                            foreach ($vlines as $v) {
                                $lins->bind_param("iiidsiis", $tid, $v['aid'], $v['fid'], $v['am'], $v['t'], $v['nid'], $v['fid2'], $ed);
                                $lins->execute();
                            }
                            $lins->close();
                            $success = "Transaction #$tid saved. Debits $" . number_format($dt, 2) . " = Credits $" . number_format($ct, 2);
                        } else {
                            $error = "Save failed: " . $db->error;
                            $ins->close();
                        }
                    }
                }
            }
        }
    }

    // Dropdown options (needed for Add/Edit form)
    $ar = $db->query("SELECT id,name,normal_balance FROM accounts WHERE archived=FALSE ORDER BY name");
    $fr = $db->query("SELECT id,name,code FROM funds WHERE is_active=TRUE AND archived=FALSE ORDER BY name");
    $nr = $db->query("SELECT id,name FROM natural_categories WHERE archived=FALSE ORDER BY name");
    $fur = $db->query("SELECT id,name FROM functional_categories WHERE archived=FALSE ORDER BY name");

    $aopt = '';
    if ($ar) {
        while ($a = $ar->fetch_assoc()) {
            $nb = htmlspecialchars($a['normal_balance']);
            $aopt .= '<option value="' . (int)$a['id'] . '" data-normal-balance="' . $nb . '">' . htmlspecialchars($a['name']) . ' (' . $nb . ')</option>';
        }
    }
    $fopt = '<option value="">—</option>';
    if ($fr) {
        while ($f = $fr->fetch_assoc()) {
            $fopt .= '<option value="' . (int)$f['id'] . '">' . htmlspecialchars($f['name'] . ($f['code'] ? ' (' . $f['code'] . ')' : '')) . '</option>';
        }
    }
    $nopt = '<option value="">—</option>';
    if ($nr) {
        while ($n = $nr->fetch_assoc()) {
            $nopt .= '<option value="' . (int)$n['id'] . '">' . htmlspecialchars($n['name']) . '</option>';
        }
    }
    $fuopt = '<option value="">—</option>';
    if ($fur) {
        while ($f = $fur->fetch_assoc()) {
            $fuopt .= '<option value="' . (int)$f['id'] . '">' . htmlspecialchars($f['name']) . '</option>';
        }
    }

    // Transaction list (with computed totals)
    $tx_result = $db->query("
        SELECT td.id, td.transaction_date, td.pay_to, td.reference_number, td.check_number, td.memo, td.status, td.cleared_date,
               COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id), 0) AS total_amount,
               COALESCE((SELECT COUNT(*) FROM transaction_lines WHERE transaction_detail_id=td.id), 0) AS num_lines
        FROM transaction_details td
        ORDER BY td.transaction_date DESC, td.id DESC
        LIMIT 100
    ");
?>
<div class="container-fluid mt-2">
    <h2 class="mb-3">Ledger</h2>

    <?php if ($success): ?>
        <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Top Action Buttons -->
    <div class="d-flex flex-wrap gap-2 mb-3">
        <button type="button" id="addTxBtn" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Add Transaction
        </button>
        <button type="button" id="editTxBtn" class="btn btn-outline-secondary" disabled>
            <i class="bi bi-pencil"></i> Edit
        </button>
        <button type="button" id="clearTxBtn" class="btn btn-outline-warning" disabled>
            <i class="bi bi-check2-circle"></i> Clear
        </button>
        <button type="button" id="reconcileTxBtn" class="btn btn-outline-info" disabled>
            <i class="bi bi-journal-check"></i> Reconcile
        </button>
    </div>

    <!-- Shared Add/Edit Form (hidden until action) -->
    <div id="txFormSection" class="card mb-4 d-none">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong id="formTitle">Add Transaction</strong>
            <button type="button" id="cancelFormBtn" class="btn btn-sm btn-outline-secondary">Cancel</button>
        </div>
        <div class="card-body">
            <form id="txForm" method="post">
                <input type="hidden" name="tx_id" id="tx_id">
                <input type="hidden" name="lines_json" id="lines_json">

                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Date *</label>
                        <input type="date" class="form-control" name="transaction_date" id="transaction_date" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Pay To</label>
                        <input type="text" class="form-control" name="pay_to" id="pay_to" placeholder="Vendor or person">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Reference #</label>
                        <input type="text" class="form-control" name="reference_number" id="reference_number" placeholder="Ref #">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Check Number</label>
                        <input type="text" class="form-control" name="check_number" id="check_number">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" name="description" id="description" placeholder="Short description">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Memo</label>
                        <input type="text" class="form-control" name="memo" id="memo" placeholder="Additional notes">
                    </div>
                </div>

                <div class="mt-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Lines <small class="text-muted">(min 2 required)</small></h6>
                        <button type="button" id="addLineBtn" class="btn btn-sm btn-outline-primary">+ Add Line</button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-2">
                            <thead class="table-light">
                                <tr>
                                    <th>Account *</th>
                                    <th>Fund</th>
                                    <th>Natural</th>
                                    <th>Functional</th>
                                    <th class="text-end" style="width:110px">Amount</th>
                                    <th style="width:70px">Type</th>
                                    <th style="width:30px"></th>
                                </tr>
                            </thead>
                            <tbody id="linesBody"></tbody>
                        </table>
                    </div>

                    <div class="d-flex gap-4 align-items-center small">
                        <div><strong>Debits:</strong> <span id="totalDebits" class="text-primary fw-bold">0.00</span></div>
                        <div><strong>Credits:</strong> <span id="totalCredits" class="text-success fw-bold">0.00</span></div>
                        <div><strong>Diff:</strong> <span id="diff" class="fw-bold">0.00</span></div>
                        <div id="balanceStatus" class="text-muted"></div>
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" id="saveBtn" class="btn btn-primary" disabled>Save Transaction</button>
                    <button type="button" id="resetLinesBtn" class="btn btn-outline-secondary ms-2">Reset to 2 Lines</button>
                    <button type="button" id="cancelFormBtn2" class="btn btn-outline-secondary ms-2">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Transactions List -->
    <div class="card">
        <div class="card-header py-2">
            <strong>Transactions</strong>
            <small class="text-muted ms-2">(select one or more with checkboxes)</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:28px"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                            <th>Date</th>
                            <th>Pay To</th>
                            <th>Ref #</th>
                            <th>Check #</th>
                            <th>Memo</th>
                            <th class="text-end">Amount</th>
                            <th>Status</th>
                            <th class="text-center">Lines</th>
                        </tr>
                    </thead>
                    <tbody id="txTableBody">
                        <?php if ($tx_result && $tx_result->num_rows > 0): ?>
                            <?php while ($r = $tx_result->fetch_assoc()): ?>
                                <?php
                                    $isCleared = ($r['status'] === 'cleared' || !empty($r['cleared_date']));
                                    $statusBadge = 'bg-secondary';
                                    $statusText = 'Pending';
                                    if ($r['status'] === 'cleared') { $statusBadge = 'bg-success'; $statusText = 'Cleared'; }
                                    elseif ($r['status'] === 'reconciled') { $statusBadge = 'bg-info'; $statusText = 'Reconciled'; }
                                ?>
                                <tr data-id="<?= (int)$r['id'] ?>" data-cleared="<?= $isCleared ? '1' : '0' ?>" data-status="<?= htmlspecialchars($r['status']) ?>">
                                    <td><input type="checkbox" class="form-check-input tx-cb" value="<?= (int)$r['id'] ?>"></td>
                                    <td><?= htmlspecialchars($r['transaction_date']) ?></td>
                                    <td><?= htmlspecialchars($r['pay_to'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['reference_number'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['check_number'] ?? '') ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars(substr($r['memo'] ?? '', 0, 70)) ?></td>
                                    <td class="text-end fw-semibold">$<?= number_format((float)$r['total_amount'], 2) ?></td>
                                    <td><span class="badge <?= $statusBadge ?>"><?= $statusText ?></span></td>
                                    <td class="text-center"><?= (int)$r['num_lines'] ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">No transactions yet. Use "Add Transaction" to create one.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script type="text/plain" id="init-ledger-script">
(function() {
    const form = document.getElementById('txForm');
    const linesBody = document.getElementById('linesBody');
    const addLineBtn = document.getElementById('addLineBtn');
    const resetLinesBtn = document.getElementById('resetLinesBtn');
    const saveBtn = document.getElementById('saveBtn');
    const linesJson = document.getElementById('lines_json');
    const txIdField = document.getElementById('tx_id');
    const formSection = document.getElementById('txFormSection');
    const formTitle = document.getElementById('formTitle');
    const cancelBtn = document.getElementById('cancelFormBtn');
    const cancelBtn2 = document.getElementById('cancelFormBtn2');

    const addTxBtn = document.getElementById('addTxBtn');
    const editTxBtn = document.getElementById('editTxBtn');
    const clearTxBtn = document.getElementById('clearTxBtn');
    const reconcileTxBtn = document.getElementById('reconcileTxBtn');

    const selectAll = document.getElementById('selectAll');
    const txTableBody = document.getElementById('txTableBody');

    const accountOpts = `<?= $aopt ?>`;
    const fundOpts = `<?= $fopt ?>`;
    const natOpts = `<?= $nopt ?>`;
    const funcOpts = `<?= $fuopt ?>`;

    function recalcTotals() {
        let deb = 0, cred = 0;
        linesBody.querySelectorAll('tr').forEach(row => {
            const amt = parseFloat(row.querySelector('.line-amount')?.value || 0);
            if (!amt) return;
            const type = row.querySelector('.line-type')?.dataset.type || '';
            if (type === 'debit') deb += amt;
            else if (type === 'credit') cred += amt;
        });
        const diff = deb - cred;

        document.getElementById('totalDebits').textContent = deb.toFixed(2);
        document.getElementById('totalCredits').textContent = cred.toFixed(2);
        const dEl = document.getElementById('diff');
        dEl.textContent = diff.toFixed(2);
        dEl.className = (Math.abs(diff) < 0.005) ? 'fw-bold text-success' : 'fw-bold text-danger';

        const lineCount = linesBody.querySelectorAll('tr').length;
        const balanced = Math.abs(diff) < 0.005 && lineCount >= 2;
        saveBtn.disabled = !balanced;

        const status = document.getElementById('balanceStatus');
        status.textContent = balanced ? '✓ Balanced' : (lineCount ? '⚠ Not balanced' : 'Add at least 2 lines');
        status.className = balanced ? 'text-success' : 'text-danger';
    }

    function attachLineListeners(row) {
        const accSel = row.querySelector('.line-account');
        const amtIn = row.querySelector('.line-amount');
        const typeBadge = row.querySelector('.line-type');
        const remBtn = row.querySelector('.remove-line');

        function updateType() {
            const opt = accSel.selectedOptions[0];
            const nb = opt ? opt.dataset.normalBalance : '';
            typeBadge.textContent = nb ? (nb.charAt(0).toUpperCase() + nb.slice(1)) : '—';
            typeBadge.dataset.type = nb || '';
            typeBadge.className = 'badge line-type ' + (nb === 'debit' ? 'bg-primary' : nb === 'credit' ? 'bg-success' : 'bg-secondary');
            recalcTotals();
        }

        accSel.addEventListener('change', updateType);
        if (amtIn) amtIn.addEventListener('input', recalcTotals);
        if (remBtn) remBtn.addEventListener('click', () => {
            row.remove();
            recalcTotals();
        });

        // initial
        updateType();
    }

    function createLineRow(prefill = null) {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><select class="form-select form-select-sm line-account" required>${accountOpts}</select></td>
            <td><select class="form-select form-select-sm line-fund">${fundOpts}</select></td>
            <td><select class="form-select form-select-sm line-nat">${natOpts}</select></td>
            <td><select class="form-select form-select-sm line-func">${funcOpts}</select></td>
            <td><input type="number" step="0.01" min="0.01" class="form-control form-control-sm line-amount text-end" required></td>
            <td><span class="badge line-type bg-secondary">—</span></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger remove-line">×</button></td>
        `;
        if (prefill) {
            const acc = row.querySelector('.line-account');
            if (prefill.account_id) acc.value = prefill.account_id;
            const fund = row.querySelector('.line-fund');
            if (prefill.fund_id !== undefined && prefill.fund_id !== '') fund.value = prefill.fund_id;
            const nat = row.querySelector('.line-nat');
            if (prefill.natural_category_id !== undefined && prefill.natural_category_id !== '') nat.value = prefill.natural_category_id;
            const func = row.querySelector('.line-func');
            if (prefill.functional_category_id !== undefined && prefill.functional_category_id !== '') func.value = prefill.functional_category_id;
            const amt = row.querySelector('.line-amount');
            if (prefill.amount !== undefined) amt.value = prefill.amount;
        }
        return row;
    }

    function addLine() {
        const row = createLineRow();
        linesBody.appendChild(row);
        attachLineListeners(row);
        recalcTotals();
    }

    function showFormForAdd() {
        formSection.classList.remove('d-none');
        formTitle.textContent = 'Add New Transaction';
        txIdField.value = '';
        form.reset();
        document.getElementById('transaction_date').value = new Date().toISOString().slice(0, 10);
        linesBody.innerHTML = '';
        addLine();
        addLine();
        recalcTotals();
    }

    function populateFormForEdit(data) {
        formSection.classList.remove('d-none');
        formTitle.textContent = 'Edit Transaction #' + data.id;
        txIdField.value = data.id;
        document.getElementById('transaction_date').value = data.transaction_date || '';
        document.getElementById('pay_to').value = data.pay_to || '';
        document.getElementById('reference_number').value = data.reference_number || '';
        document.getElementById('check_number').value = data.check_number || '';
        document.getElementById('description').value = data.description || '';
        document.getElementById('memo').value = data.memo || '';

        linesBody.innerHTML = '';
        const lines = data.lines || [];
        if (lines.length > 0) {
            lines.forEach(l => {
                const row = createLineRow(l);
                linesBody.appendChild(row);
                attachLineListeners(row);
            });
        } else {
            addLine();
            addLine();
        }
        recalcTotals();
    }

    function updateButtonStates() {
        const checked = txTableBody.querySelectorAll('.tx-cb:checked');
        const count = checked.length;
        let hasCleared = false;
        checked.forEach(cb => {
            const row = cb.closest('tr');
            if (row && row.dataset.cleared === '1') hasCleared = true;
        });
        const multi = count > 1;

        addTxBtn.disabled = multi;
        editTxBtn.disabled = (count !== 1 || hasCleared);
        clearTxBtn.disabled = (count === 0);
        reconcileTxBtn.disabled = (count === 0);
    }

    function getSelectedIds() {
        return Array.from(txTableBody.querySelectorAll('.tx-cb:checked')).map(cb => parseInt(cb.value, 10));
    }

    function hasUnsavedInputs() {
        if (formSection.classList.contains('d-none')) return false;
        const fields = ['pay_to', 'reference_number', 'check_number', 'description', 'memo'];
        for (const fid of fields) {
            const el = document.getElementById(fid);
            if (el && el.value.trim() !== '') return true;
        }
        // any lines with positive amount
        const hasLineData = Array.from(linesBody.querySelectorAll('.line-amount')).some(el => parseFloat(el.value || '0') > 0);
        if (hasLineData) return true;
        if (linesBody.querySelectorAll('tr').length > 2) return true;
        return false;
    }

    // Basic client-side sorting for specified columns
    let currentSortCol = -1;
    let currentSortDir = 1;

    function sortTable(colIdx) {
        const tbody = txTableBody;
        let rows = Array.from(tbody.querySelectorAll('tr'));
        if (rows.length < 2) return;

        const dir = (currentSortCol === colIdx) ? -currentSortDir : 1;
        currentSortCol = colIdx;
        currentSortDir = dir;

        rows.sort((ra, rb) => {
            const ta = ra.children[colIdx] ? ra.children[colIdx].textContent.trim() : '';
            const tb = rb.children[colIdx] ? rb.children[colIdx].textContent.trim() : '';

            if (colIdx === 1) { // Date
                const da = ta ? Date.parse(ta) : 0;
                const db = tb ? Date.parse(tb) : 0;
                return (da - db) * dir;
            } else if (colIdx === 3 || colIdx === 4 || colIdx === 6) { // Ref #, Check #, Amount - numeric
                const na = parseFloat(ta.replace(/[^0-9.-]/g, '')) || 0;
                const nb = parseFloat(tb.replace(/[^0-9.-]/g, '')) || 0;
                return (na - nb) * dir;
            } else {
                // string compare (case-insensitive)
                const sa = ta.toLowerCase();
                const sb = tb.toLowerCase();
                if (sa < sb) return -dir;
                if (sa > sb) return dir;
                return 0;
            }
        });

        rows.forEach(r => tbody.appendChild(r));

        // update header indicators (basic)
        const table = tbody.closest('table');
        const ths = table.querySelectorAll('thead th');
        ths.forEach((th, i) => {
            let txt = th.textContent.replace(/\s*[↑↓]$/, '');
            if (i === colIdx) {
                txt += (dir > 0 ? ' ↑' : ' ↓');
            }
            th.textContent = txt;
        });
    }

    function initSorting() {
        const table = txTableBody.closest('table');
        if (!table) return;
        const ths = table.querySelectorAll('thead th');
        // column indices after ref column added:
        // 1=Date, 2=Pay To, 3=Ref #, 4=Check #, 6=Amount, 7=Status
        const sortable = [1, 2, 3, 4, 6, 7];
        ths.forEach((th, idx) => {
            if (sortable.includes(idx)) {
                th.style.cursor = 'pointer';
                th.addEventListener('click', () => sortTable(idx));
            }
        });
    }

    // Wire action buttons
    addTxBtn.addEventListener('click', () => {
        const isEditMode = !formSection.classList.contains('d-none') && txIdField.value !== '';
        if (isEditMode && hasUnsavedInputs()) {
            if (!confirm('You have made changes to the current transaction. Discard changes and start a new transaction?')) {
                return;
            }
        }
        // clear the current selection when switching from edit
        if (isEditMode) {
            txTableBody.querySelectorAll('.tx-cb:checked').forEach(cb => cb.checked = false);
            if (selectAll) selectAll.checked = false;
            updateButtonStates();
        }
        showFormForAdd();
    });

    editTxBtn.addEventListener('click', () => {
        const ids = getSelectedIds();
        if (ids.length !== 1) return;
        fetch('pages/ledger.php?get_transaction=' + ids[0])
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                    return;
                }
                populateFormForEdit(data);
            })
            .catch(err => {
                console.error(err);
                alert('Failed to load transaction for edit.');
            });
    });

    clearTxBtn.addEventListener('click', () => {
        const ids = getSelectedIds();
        if (ids.length === 0) return;
        if (!confirm('Mark ' + ids.length + ' selected transaction(s) as cleared?')) return;

        const fd = new FormData();
        fd.append('action', 'clear');
        fd.append('selected_ids', JSON.stringify(ids));
        fetch('pages/ledger.php', { method: 'POST', body: fd })
            .then(r => r.text())
            .then(html => {
                document.getElementById('main-content').innerHTML = html;
            })
            .catch(err => {
                console.error(err);
                alert('Clear operation failed.');
            });
    });

    reconcileTxBtn.addEventListener('click', () => {
        const ids = getSelectedIds();
        if (ids.length === 0) return;
        if (!confirm('Mark ' + ids.length + ' selected transaction(s) as reconciled? (placeholder action)')) return;

        const fd = new FormData();
        fd.append('action', 'reconcile');
        fd.append('selected_ids', JSON.stringify(ids));
        fetch('pages/ledger.php', { method: 'POST', body: fd })
            .then(r => r.text())
            .then(html => {
                document.getElementById('main-content').innerHTML = html;
            })
            .catch(err => {
                console.error(err);
                alert('Reconcile operation failed.');
            });
    });

    // Cancel form
    function hideForm() {
        formSection.classList.add('d-none');
    }
    if (cancelBtn) cancelBtn.addEventListener('click', hideForm);
    if (cancelBtn2) cancelBtn2.addEventListener('click', hideForm);

    // Reset lines to exactly 2
    if (resetLinesBtn) {
        resetLinesBtn.addEventListener('click', () => {
            linesBody.innerHTML = '';
            addLine();
            addLine();
            recalcTotals();
        });
    }

    // Add line button (inside form)
    if (addLineBtn) {
        addLineBtn.addEventListener('click', addLine);
    }

    // Form submit (Add or Edit)
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const lines = [];
            linesBody.querySelectorAll('tr').forEach(row => {
                const acc = row.querySelector('.line-account')?.value;
                const amtEl = row.querySelector('.line-amount');
                const amt = amtEl ? parseFloat(amtEl.value) : 0;
                if (!acc || !amt) return;

                lines.push({
                    account_id: acc,
                    fund_id: row.querySelector('.line-fund')?.value || '',
                    natural_category_id: row.querySelector('.line-nat')?.value || '',
                    functional_category_id: row.querySelector('.line-func')?.value || '',
                    amount: amt
                });
            });

            if (lines.length < 2) {
                alert('Every transaction must have at least 2 lines.');
                return;
            }

            linesJson.value = JSON.stringify(lines);

            fetch('pages/ledger.php', {
                method: 'POST',
                body: new FormData(form)
            })
            .then(r => r.text())
            .then(html => {
                document.getElementById('main-content').innerHTML = html;
            })
            .catch(err => {
                console.error(err);
                alert('Save failed. See console.');
            });
        });
    }

    // Selection handling (multi-select via checkboxes)
    if (selectAll) {
        selectAll.addEventListener('change', () => {
            txTableBody.querySelectorAll('.tx-cb').forEach(cb => {
                cb.checked = selectAll.checked;
            });
            updateButtonStates();
        });
    }

    if (txTableBody) {
        // Checkbox changes
        txTableBody.addEventListener('change', function(e) {
            if (e.target.classList.contains('tx-cb')) {
                const allCbs = txTableBody.querySelectorAll('.tx-cb');
                const checkedCbs = txTableBody.querySelectorAll('.tx-cb:checked');
                if (selectAll) {
                    selectAll.checked = (checkedCbs.length === allCbs.length && allCbs.length > 0);
                }
                updateButtonStates();
            }
        });

        // Click row (except on checkbox or button) toggles selection
        txTableBody.addEventListener('click', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON') return;
            const row = e.target.closest('tr');
            if (!row) return;
            const cb = row.querySelector('.tx-cb');
            if (cb) {
                cb.checked = !cb.checked;
                cb.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    // Initial state
    updateButtonStates();

    // Enable sorting on the transactions table
    initSorting();

    // No lines pre-seeded until Add/Edit clicked (form hidden by default)
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-ledger-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>

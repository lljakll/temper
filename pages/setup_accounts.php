<?php

    // Security and DB connection already handled by index.php
    // Light fallback in case
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/budget_utils.php';

budgetEnsureSimplifiedSchema($db);

/** @var list<string> Classic accounting element types (accounts.account_type ENUM). */
const SETUP_ACCOUNTS_ACCOUNT_TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

/**
 * Expected normal balance for a classic account type.
 * asset/expense → debit; liability/equity/income → credit.
 */
function setupAccountsExpectedNormalBalance(string $accountType): string {
    return in_array($accountType, ['asset', 'expense'], true) ? 'debit' : 'credit';
}

function setupAccountsParseCategoryId($raw): ?int {
    if ($raw === null || $raw === '' || $raw === '0') {
        return null;
    }
    $id = (int)$raw;
    return $id > 0 ? $id : null;
}

function setupAccountsParseAccountType($raw): ?string {
    $type = strtolower(trim((string)($raw ?? '')));
    return in_array($type, SETUP_ACCOUNTS_ACCOUNT_TYPES, true) ? $type : null;
}

function setupAccountsParseNormalBalance($raw): ?string {
    $nb = strtolower(trim((string)($raw ?? '')));
    return in_array($nb, ['debit', 'credit'], true) ? $nb : null;
}

function setupAccountsAccountTypeLabel(string $type): string {
    $labels = [
        'asset' => 'Asset',
        'liability' => 'Liability',
        'equity' => 'Equity',
        'income' => 'Income',
        'expense' => 'Expense',
    ];
    return $labels[$type] ?? ucfirst($type);
}

// Category lookups for account form
$naturalOpts = [];
$functionalOpts = [];
$nr = $db->query("SELECT id, name FROM natural_categories WHERE archived = FALSE ORDER BY name");
if ($nr) {
    while ($row = $nr->fetch_assoc()) {
        $naturalOpts[] = $row;
    }
}
$fr = $db->query("SELECT id, name FROM functional_categories WHERE archived = FALSE ORDER BY name");
if ($fr) {
    while ($row = $fr->fetch_assoc()) {
        $functionalOpts[] = $row;
    }
}

// Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            $action = $_POST['action'];
            
            if ($action === 'add') {
                // Insert new account record
                $name = $_POST['name'] ?? '';
                $description = $_POST['description'] ?? '';
                $account_type = setupAccountsParseAccountType($_POST['account_type'] ?? null);
                $normal_balance = setupAccountsParseNormalBalance($_POST['normal_balance'] ?? null);
                $coa_number = trim((string)($_POST['coa_number'] ?? ''));
                if ($coa_number === '') {
                    $coa_number = null;
                } elseif (mb_strlen($coa_number) > 50) {
                    $coa_number = mb_substr($coa_number, 0, 50);
                }
                $natural_category_id = setupAccountsParseCategoryId($_POST['natural_category_id'] ?? null);
                $functional_category_id = setupAccountsParseCategoryId($_POST['functional_category_id'] ?? null);
                $archived = temperParsePostArchived();
                $mutable_fund = temperParsePostArchived($_POST, 'mutable_fund');
                
                // Validate required fields
                if (empty($name)) {
                    echo "Error: Account name is required\n";
                } elseif ($account_type === null) {
                    echo "Error: Account Type is required (asset, liability, equity, income, or expense)\n";
                } elseif ($normal_balance === null) {
                    echo "Error: Normal Balance must be debit or credit\n";
                } else {
                    $stmt = $db->prepare("INSERT INTO accounts (name, description, normal_balance, account_type, coa_number, natural_category_id, functional_category_id, archived, archived_at, mutable_fund) VALUES (?, ?, ?, ?, ?, ?, ?, ?, IF(? = 1, NOW(), NULL), ?)");
                    $stmt->bind_param("sssssiiiii", $name, $description, $normal_balance, $account_type, $coa_number, $natural_category_id, $functional_category_id, $archived, $archived, $mutable_fund);
                    if ($stmt->execute() === TRUE) {
                        echo "Account added successfully\n";
                    } else {
                        echo "Error adding account: " . $db->error . "\n";
                    }
                    $stmt->close();
                }
            }
            elseif ($action === 'edit') {
                // Update existing account record
                $id = (int)($_POST['id'] ?? 0);
                $name = $_POST['name'] ?? '';
                $description = $_POST['description'] ?? '';
                $account_type = setupAccountsParseAccountType($_POST['account_type'] ?? null);
                $normal_balance = setupAccountsParseNormalBalance($_POST['normal_balance'] ?? null);
                $coa_number = trim((string)($_POST['coa_number'] ?? ''));
                if ($coa_number === '') {
                    $coa_number = null;
                } elseif (mb_strlen($coa_number) > 50) {
                    $coa_number = mb_substr($coa_number, 0, 50);
                }
                $natural_category_id = setupAccountsParseCategoryId($_POST['natural_category_id'] ?? null);
                $functional_category_id = setupAccountsParseCategoryId($_POST['functional_category_id'] ?? null);
                $archived = temperParsePostArchived();
                $mutable_fund = temperParsePostArchived($_POST, 'mutable_fund');
                
                // Validate required fields and account exists
                if (empty($name) || $id <= 0) {
                    echo "Error: Invalid account data\n";
                } elseif ($account_type === null) {
                    echo "Error: Account Type is required (asset, liability, equity, income, or expense)\n";
                } elseif ($normal_balance === null) {
                    echo "Error: Normal Balance must be debit or credit\n";
                } else {
                    // Check if account exists before updating
                    $check_stmt = $db->prepare("SELECT id FROM accounts WHERE id = ?");
                    $check_stmt->bind_param("i", $id);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $stmt = $db->prepare("UPDATE accounts SET name=?, description=?, normal_balance=?, account_type=?, coa_number=?, natural_category_id=?, functional_category_id=?, archived=?, archived_at=IF(? = 1, COALESCE(archived_at, NOW()), NULL), mutable_fund=? WHERE id=?");
                        $stmt->bind_param("sssssiiiiii", $name, $description, $normal_balance, $account_type, $coa_number, $natural_category_id, $functional_category_id, $archived, $archived, $mutable_fund, $id);
                        if ($stmt->execute() === TRUE) {
                            echo "Account updated successfully\n";
                        } else {
                            echo "Error updating account: " . $db->error . "\n";
                        }
                        $stmt->close();
                    } else {
                        echo "Error: Account not found\n";
                    }
                    $check_stmt->close();
                }
            }
            elseif ($action === 'delete') {
                // Delete account record
                $id = (int)($_POST['id'] ?? 0);
                
                if ($id <= 0) {
                    echo "Error: Invalid account ID\n";
                } else {
                    $stmt = $db->prepare("DELETE FROM accounts WHERE id=?");
                    $stmt->bind_param("i", $id);
                    if ($stmt->execute() === TRUE) {
                        echo "Account deleted successfully\n";
                    } else {
                        echo "Error deleting account: " . $db->error . "\n";
                    }
                    $stmt->close();
                }
            }
            elseif ($action === 'archive') {
                // Archive/unarchive account record
                $id = (int)($_POST['id'] ?? 0);
                $archived = temperParsePostArchived();
                
                // Validate ID
                if ($id <= 0) {
                    echo "Error: Invalid account ID\n";
                } else {
                    // Check if account exists before updating
                    $check_stmt = $db->prepare("SELECT id FROM accounts WHERE id = ?");
                    $check_stmt->bind_param("i", $id);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $stmt = $db->prepare("UPDATE accounts SET archived=?, archived_at=IF(? = 1, COALESCE(archived_at, NOW()), NULL) WHERE id=?");
                        $stmt->bind_param("iii", $archived, $archived, $id);
                        if ($stmt->execute() === TRUE) {
                            echo $archived
                                ? "Account archived successfully\n"
                                : "Account unarchived successfully\n";
                        } else {
                            echo "Error updating account archived status: " . $db->error . "\n";
                        }
                        $stmt->close();
                    } else {
                        echo "Error: Account not found\n";
                    }
                    $check_stmt->close();
                }
            }
        } else {
            echo "Error: No action specified\n";
        }
    }

    // Check if 'show_archived' parameter is set
    $show_archived = isset($_GET['show_archived']) && $_GET['show_archived'] == '1';

    // Build query for accounts with category names
    if ($show_archived) {
        $accounts_query = "SELECT a.id, a.name, a.description, a.normal_balance, a.account_type, a.coa_number, a.archived, a.mutable_fund,
                                  a.natural_category_id, a.functional_category_id,
                                  COALESCE(nc.name, '') AS natural_name,
                                  COALESCE(fc.name, '') AS functional_name
                           FROM accounts a
                           LEFT JOIN natural_categories nc ON nc.id = a.natural_category_id
                           LEFT JOIN functional_categories fc ON fc.id = a.functional_category_id
                           ORDER BY (a.coa_number IS NULL OR a.coa_number = '') ASC, a.coa_number ASC, a.name ASC, a.id ASC";
    } else {
        $accounts_query = "SELECT a.id, a.name, a.description, a.normal_balance, a.account_type, a.coa_number, a.archived, a.mutable_fund,
                                  a.natural_category_id, a.functional_category_id,
                                  COALESCE(nc.name, '') AS natural_name,
                                  COALESCE(fc.name, '') AS functional_name
                           FROM accounts a
                           LEFT JOIN natural_categories nc ON nc.id = a.natural_category_id
                           LEFT JOIN functional_categories fc ON fc.id = a.functional_category_id
                           WHERE a.archived = FALSE
                           ORDER BY (a.coa_number IS NULL OR a.coa_number = '') ASC, a.coa_number ASC, a.name ASC, a.id ASC";
    }

    $accounts_result = $db->query($accounts_query);
?>

<div class="container-fluid mt-2 px-0 px-sm-2 temper-lookup-page" data-lookup-entity="accounts">
    <!-- Title + filter + font + actions on one compact row -->
    <div class="temper-lookup-toolbar d-flex flex-wrap align-items-center column-gap-2 row-gap-1 mb-2">
        <h2 class="h5 mb-0 temper-lookup-title text-nowrap">Accounts Setup</h2>

        <div class="input-group input-group-sm temper-lookup-filter-wrap">
            <span class="input-group-text" id="accountsFilterIcon"><i class="bi bi-search" aria-hidden="true"></i></span>
            <input type="search" class="form-control" id="accountsTableFilter"
                   placeholder="Filter…" aria-label="Filter accounts"
                   aria-describedby="accountsFilterIcon" autocomplete="off" data-dirty-ignore>
        </div>

        <div class="btn-group btn-group-sm" role="group" aria-label="Table text size">
            <button type="button" class="btn btn-outline-secondary" data-lookup-font-delta="-1"
                    title="Smaller table text (; then -)">A−</button>
            <button type="button" class="btn btn-outline-secondary" data-lookup-font-delta="1"
                    title="Larger table text (; then +)">A+</button>
        </div>

        <button type="button" class="btn btn-outline-secondary btn-sm" data-lookup-hotkey-help
                title="Keyboard shortcuts (; then ?)" aria-label="Keyboard shortcuts">
            <i class="bi bi-keyboard" aria-hidden="true"></i>
        </button>

        <div class="d-flex flex-wrap gap-1 ms-md-auto temper-lookup-actions">
            <button type="button" id="showArchivedBtn" class="btn btn-outline-secondary btn-sm" data-show-archived="<?= $show_archived ? '1' : '0' ?>">
                <?= $show_archived ? 'Hide' : 'Show' ?> Archived
            </button>
            <button type="button" id="addBtn" class="btn btn-primary btn-sm">Add</button>
            <button type="button" id="editBtn" class="btn btn-secondary btn-sm" disabled>Edit</button>
            <button type="button" id="deleteBtn" class="btn btn-danger btn-sm" disabled>Delete</button>
            <button type="button" id="archiveBtn" class="btn btn-warning btn-sm" disabled>Archive/Unarchive</button>
        </div>
    </div>

    <!-- Account Table -->
    <div class="table-responsive">
        <table class="table table-striped table-hover temper-lookup-table" id="accountsTable">
            <thead class="table-dark">
                <tr>
                    <th>CoA #</th>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Account Type</th>
                    <th>Normal Balance</th>
                    <th>Natural</th>
                    <th>Functional</th>
                    <th>Archived</th>
                    <th>Mut. Fund</th>
                </tr>
            </thead>
            <tbody id="accountsTableBody">
                <?php if ($accounts_result && $accounts_result->num_rows > 0): ?>
                    <?php while ($account = $accounts_result->fetch_assoc()): ?>
                        <?php
                            $coaDisplay = trim((string)($account['coa_number'] ?? ''));
                            $acctType = (string)($account['account_type'] ?? '');
                            $nbVal = (string)($account['normal_balance'] ?? '');
                            $expectedNb = $acctType !== '' ? setupAccountsExpectedNormalBalance($acctType) : '';
                            $nbDiverges = ($expectedNb !== '' && $nbVal !== '' && $nbVal !== $expectedNb);
                        ?>
                        <tr data-id="<?= (int)$account['id'] ?>"
                            data-coa-number="<?= htmlspecialchars($coaDisplay) ?>"
                            data-account-type="<?= htmlspecialchars($acctType) ?>"
                            data-normal-balance="<?= htmlspecialchars($nbVal) ?>"
                            data-natural-id="<?= $account['natural_category_id'] !== null ? (int)$account['natural_category_id'] : '' ?>"
                            data-functional-id="<?= $account['functional_category_id'] !== null ? (int)$account['functional_category_id'] : '' ?>"
                            data-archived="<?= !empty($account['archived']) ? '1' : '0' ?>"
                            data-mutable-fund="<?= !empty($account['mutable_fund']) ? '1' : '0' ?>"
                            class="<?= !empty($account['archived']) ? 'table-secondary' : '' ?>">
                            <td class="font-monospace"><?= htmlspecialchars($coaDisplay !== '' ? $coaDisplay : '—') ?></td>
                            <td><?= htmlspecialchars($account['name']) ?></td>
                            <td><?= htmlspecialchars($account['description'] ?? '') ?></td>
                            <td><?= htmlspecialchars(setupAccountsAccountTypeLabel($acctType)) ?></td>
                            <td>
                                <?= htmlspecialchars($nbVal) ?>
                                <?php if ($nbDiverges): ?>
                                    <span class="text-warning ms-1" title="Normal Balance differs from the usual <?= htmlspecialchars($expectedNb) ?> for <?= htmlspecialchars(setupAccountsAccountTypeLabel($acctType)) ?> accounts." aria-label="Caution: unusual normal balance">⚠</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($account['natural_name'] !== '' ? $account['natural_name'] : '—') ?></td>
                            <td><?= htmlspecialchars($account['functional_name'] !== '' ? $account['functional_name'] : '—') ?></td>
                            <td><?= !empty($account['archived']) ? 'Yes' : 'No' ?></td>
                            <td><?= !empty($account['mutable_fund']) ? 'Yes' : 'No' ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="text-center">No accounts found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Add / Edit modal -->
    <div class="modal fade" id="accountFormModal" tabindex="-1" aria-labelledby="formTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form id="accountFormContent" method="POST" data-dirty-track>
                    <div class="modal-header">
                        <h5 class="modal-title" id="formTitle">Add New Account</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="accountId" name="id">
                        <input type="hidden" name="action" id="formAction">

                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <label for="coa_number" class="form-label">CoA Number</label>
                                <input type="text" class="form-control font-monospace" id="coa_number" name="coa_number" maxlength="50" placeholder="e.g. 1000">
                                <div class="form-text">Internal Chart of Accounts reference only.</div>
                            </div>
                            <div class="col-md-8">
                                <label for="name" class="form-label">Name</label>
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label for="account_type" class="form-label">Account Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="account_type" name="account_type" required>
                                    <option value="">— Select —</option>
                                    <option value="asset">Asset</option>
                                    <option value="liability">Liability</option>
                                    <option value="equity">Equity</option>
                                    <option value="income">Income</option>
                                    <option value="expense">Expense</option>
                                </select>
                                <div class="form-text">Classic accounting element (required). Natural/Functional categories remain optional secondary labels.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="normal_balance" class="form-label">Normal Balance</label>
                                <select class="form-select" id="normal_balance" name="normal_balance" required>
                                    <option value="debit">Debit</option>
                                    <option value="credit">Credit</option>
                                </select>
                                <div id="normalBalanceCaution" class="form-text text-warning d-none" role="status">
                                    Caution: Normal Balance differs from the usual value for this Account Type. You can still save after confirming.
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label for="natural_category_id" class="form-label">Natural Category</label>
                                <select class="form-select" id="natural_category_id" name="natural_category_id">
                                    <option value="">— Optional —</option>
                                    <?php foreach ($naturalOpts as $opt): ?>
                                        <option value="<?= (int)$opt['id'] ?>"><?= htmlspecialchars($opt['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Shown as a read-only label on budget lines.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="functional_category_id" class="form-label">Functional Category</label>
                                <select class="form-select" id="functional_category_id" name="functional_category_id">
                                    <option value="">— Optional —</option>
                                    <?php foreach ($functionalOpts as $opt): ?>
                                        <option value="<?= (int)$opt['id'] ?>"><?= htmlspecialchars($opt['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="archived" name="archived" value="1">
                            <label class="form-check-label" for="archived">Archived</label>
                        </div>

                        <div class="mb-0 form-check">
                            <input type="checkbox" class="form-check-input" id="mutable_fund" name="mutable_fund" value="1">
                            <label class="form-check-label" for="mutable_fund">Mutable Fund</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script type="text/plain" id="init-accounts-script">
(function() {
    const currentPage = 'setup_accounts';
    const tableBody = document.getElementById('accountsTableBody');
    const addBtn = document.getElementById('addBtn');
    const editBtn = document.getElementById('editBtn');
    const deleteBtn = document.getElementById('deleteBtn');
    const archiveBtn = document.getElementById('archiveBtn');
    const showArchivedBtn = document.getElementById('showArchivedBtn');
    let showArchived = showArchivedBtn ? showArchivedBtn.dataset.showArchived === '1' : false;
    let accountFormModalEl = document.getElementById('accountFormModal');
    if (accountFormModalEl && typeof window.mountModalOnBody === 'function') {
        accountFormModalEl = window.mountModalOnBody(accountFormModalEl);
    }
    const accountFormModal = accountFormModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal
        ? bootstrap.Modal.getOrCreateInstance(accountFormModalEl)
        : null;
    const accountFormContent = document.getElementById('accountFormContent');
    const formAction = document.getElementById('formAction');
    const accountId = document.getElementById('accountId');
    const formTitle = document.getElementById('formTitle');
    const accountTypeEl = document.getElementById('account_type');
    const normalBalanceEl = document.getElementById('normal_balance');
    const normalBalanceCaution = document.getElementById('normalBalanceCaution');

    const expectedNormalByType = {
        asset: 'debit',
        expense: 'debit',
        liability: 'credit',
        equity: 'credit',
        income: 'credit'
    };
    const accountTypeLabels = {
        asset: 'Asset',
        liability: 'Liability',
        equity: 'Equity',
        income: 'Income',
        expense: 'Expense'
    };

    /** When true, next account_type change should auto-fill normal balance (add flow). */
    let autoPopulateNormalOnTypeChange = false;

    function openFormModal() {
        if (typeof window.showFragmentModal === 'function' && accountFormModalEl) {
            window.showFragmentModal(accountFormModalEl);
        } else if (accountFormModal) {
            accountFormModal.show();
        }
    }

    function expectedNormalBalance(accountType) {
        return expectedNormalByType[accountType] || null;
    }

    function updateNormalBalanceCaution() {
        if (!accountTypeEl || !normalBalanceEl || !normalBalanceCaution) return;
        const type = accountTypeEl.value;
        const nb = normalBalanceEl.value;
        const expected = expectedNormalBalance(type);
        const diverges = !!(type && expected && nb && nb !== expected);
        normalBalanceCaution.classList.toggle('d-none', !diverges);
        if (diverges) {
            const typeLabel = accountTypeLabels[type] || type;
            normalBalanceCaution.textContent =
                'Caution: Normal Balance is “' + nb + '” but ' + typeLabel +
                ' accounts usually use “' + expected + '”. You can still save after confirming.';
            normalBalanceEl.classList.add('border-warning');
            normalBalanceEl.title = normalBalanceCaution.textContent;
        } else {
            normalBalanceEl.classList.remove('border-warning');
            normalBalanceEl.removeAttribute('title');
        }
    }

    function applyExpectedNormalFromAccountType() {
        const type = accountTypeEl ? accountTypeEl.value : '';
        const expected = expectedNormalBalance(type);
        if (expected && normalBalanceEl) {
            normalBalanceEl.value = expected;
        }
        updateNormalBalanceCaution();
    }

    let selectedRow = null;

    function rowIsArchived(row) {
        if (!row) return false;
        if (row.dataset && (row.dataset.archived === '1' || row.dataset.archived === 'true')) return true;
        const cell = row.cells && row.cells[7] ? row.cells[7].textContent.trim() : '';
        return cell === 'Yes';
    }

    function syncArchiveButton() {
        if (!selectedRow) {
            archiveBtn.disabled = true;
            archiveBtn.textContent = 'Archive/Unarchive';
            return;
        }
        archiveBtn.disabled = false;
        archiveBtn.textContent = rowIsArchived(selectedRow) ? 'Unarchive' : 'Archive';
    }

    // Enable/disable buttons based on row selection
    tableBody.addEventListener('click', function(event) {
        const row = event.target.closest('tr');
        if (row && row.dataset.id) {
            if (selectedRow) {
                selectedRow.classList.remove('table-primary');
            }
            selectedRow = row;
            selectedRow.classList.add('table-primary');
            editBtn.disabled = false;
            deleteBtn.disabled = false;
            syncArchiveButton();
        }
    });

    if (accountTypeEl) {
        accountTypeEl.addEventListener('change', function() {
            if (autoPopulateNormalOnTypeChange) {
                applyExpectedNormalFromAccountType();
            } else {
                updateNormalBalanceCaution();
            }
        });
    }
    if (normalBalanceEl) {
        normalBalanceEl.addEventListener('change', updateNormalBalanceCaution);
    }

    // Reset auto-populate when modal closes (cancel / backdrop / Escape)
    if (accountFormModalEl) {
        accountFormModalEl.addEventListener('hidden.bs.modal', function() {
            autoPopulateNormalOnTypeChange = false;
            updateNormalBalanceCaution();
        });
    }

    // Add button — open modal with empty form
    addBtn.addEventListener('click', function() {
        accountFormContent.reset();
        formAction.value = 'add';
        formTitle.textContent = 'Add New Account';
        accountId.value = '';
        autoPopulateNormalOnTypeChange = true;
        // Default Account Type + matching Normal Balance
        if (accountTypeEl) accountTypeEl.value = 'asset';
        applyExpectedNormalFromAccountType();
        if (typeof window.TemperDirtyForms !== 'undefined') window.TemperDirtyForms.markClean(accountFormContent);
        openFormModal();
    });

    // Edit button — populate form from selected row and open modal
    editBtn.addEventListener('click', function() {
        if (!selectedRow) return;

        // Prefer data attributes (stable) over cell indices
        const id = selectedRow.getAttribute('data-id');
        const name = selectedRow.cells[1] ? selectedRow.cells[1].textContent : '';
        const description = selectedRow.cells[2] ? selectedRow.cells[2].textContent : '';
        const accountType = selectedRow.dataset.accountType || '';
        const normalBalance = selectedRow.dataset.normalBalance || '';
        const archived = rowIsArchived(selectedRow);
        const mutableFund = selectedRow.dataset.mutableFund === '1';

        // Fill form — do not auto-overwrite normal balance when loading edit
        autoPopulateNormalOnTypeChange = false;
        accountId.value = id;
        document.getElementById('coa_number').value = selectedRow.dataset.coaNumber || '';
        document.getElementById('name').value = name;
        document.getElementById('description').value = description;
        accountTypeEl.value = accountType;
        normalBalanceEl.value = normalBalance;
        document.getElementById('natural_category_id').value = selectedRow.dataset.naturalId || '';
        document.getElementById('functional_category_id').value = selectedRow.dataset.functionalId || '';
        document.getElementById('archived').checked = archived;
        document.getElementById('mutable_fund').checked = mutableFund;
        updateNormalBalanceCaution();

        formAction.value = 'edit';
        formTitle.textContent = 'Edit Account';
        if (typeof window.TemperDirtyForms !== 'undefined') window.TemperDirtyForms.markClean(accountFormContent);
        openFormModal();
    });

    // Delete button
    deleteBtn.addEventListener('click', function() {
        if (!selectedRow) return;

        const id = selectedRow.getAttribute('data-id');
        if (confirm('Are you sure you want to delete this account?')) {
            formAction.value = 'delete';
            accountId.value = id;
            accountFormContent.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
    });

    // Archive / Unarchive — explicit FormData so archived=0|1 is always sent correctly
    archiveBtn.addEventListener('click', function() {
        if (!selectedRow) {
            if (typeof showToast === 'function') showToast('Please select an account first.', 'warning');
            return;
        }
        if (typeof window.confirmLeaveIfDirty === 'function' && !window.confirmLeaveIfDirty()) return;

        const id = selectedRow.getAttribute('data-id');
        const isCurrentlyArchived = rowIsArchived(selectedRow);
        const newArchivedState = !isCurrentlyArchived;
        const verb = newArchivedState ? 'archive' : 'unarchive';

        if (!confirm('Are you sure you want to ' + verb + ' this account?')) return;

        const fd = new FormData();
        fd.append('action', 'archive');
        fd.append('id', id);
        fd.append('archived', newArchivedState ? '1' : '0');
        const qs = showArchived ? '?show_archived=1' : '';
        submitFormAndReload('pages/' + currentPage + '.php', fd, 'pages/' + currentPage + '.php' + qs);
    });

    // Toggle show-archived list via fetch (no full reload)
    showArchivedBtn.addEventListener('click', function() {
        if (typeof window.confirmLeaveIfDirty === 'function' && !window.confirmLeaveIfDirty()) return;
        showArchived = !showArchived;
        const newShow = showArchived ? '1' : '0';
        fetch(`pages/${currentPage}.php?show_archived=${newShow}`)
            .then(r => r.text())
            .then(html => {
                if (typeof applyMainContent === 'function') applyMainContent(html);
                else document.getElementById('main-content').innerHTML = html;
            })
            .catch(e => console.error('Toggle error:', e));
    });

    // Handle form submission via AJAX to stay in tab (list refresh closes modal)
    accountFormContent.addEventListener('submit', function(e) {
        e.preventDefault();

        const action = formAction.value;
        if (action === 'add' || action === 'edit') {
            const type = accountTypeEl ? accountTypeEl.value : '';
            const nb = normalBalanceEl ? normalBalanceEl.value : '';
            const expected = expectedNormalBalance(type);
            if (type && expected && nb && nb !== expected) {
                const typeLabel = accountTypeLabels[type] || type;
                const msg =
                    'Normal Balance (“' + nb + '”) differs from the usual “' + expected +
                    '” for ' + typeLabel + ' accounts.\n\n' +
                    'This is allowed but uncommon. Do you want to save anyway?';
                if (!confirm(msg)) {
                    return;
                }
            }
        }

        const qs = showArchived ? '?show_archived=1' : '';
        submitFormAndReload(`pages/${currentPage}.php`, new FormData(accountFormContent), `pages/${currentPage}.php${qs}`);
    });

    // Filter, sort, table font size, and leader-key hotkeys
    if (typeof window.TemperLookupPage !== 'undefined') {
        window.TemperLookupPage.init({
            root: document.querySelector('.temper-lookup-page'),
            table: document.getElementById('accountsTable') || tableBody.closest('table'),
            filterInput: document.getElementById('accountsTableFilter'),
            emptyMessage: 'No matching accounts.',
            actions: {
                add: addBtn,
                edit: editBtn,
                delete: deleteBtn,
                archive: archiveBtn,
                toggleArchived: showArchivedBtn
            }
        });
    } else if (typeof window.TemperLookupTable !== 'undefined') {
        window.TemperLookupTable.enhance({
            table: document.getElementById('accountsTable') || tableBody.closest('table'),
            filterInput: document.getElementById('accountsTableFilter'),
            emptyMessage: 'No matching accounts.'
        });
    }
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-accounts-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>

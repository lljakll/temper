<?php

    // Security and DB connection already handled by index.php
    // Light fallback in case
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/budget_utils.php';

budgetEnsureSimplifiedSchema($db);

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

function setupAccountsParseCategoryId($raw): ?int {
    if ($raw === null || $raw === '' || $raw === '0') {
        return null;
    }
    $id = (int)$raw;
    return $id > 0 ? $id : null;
}

// Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            $action = $_POST['action'];
            
            if ($action === 'add') {
                // Insert new account record
                $name = $_POST['name'] ?? '';
                $description = $_POST['description'] ?? '';
                $normal_balance = $_POST['normal_balance'] ?? 'debit';
                $coa_number = trim((string)($_POST['coa_number'] ?? ''));
                if ($coa_number === '') {
                    $coa_number = null;
                } elseif (mb_strlen($coa_number) > 50) {
                    $coa_number = mb_substr($coa_number, 0, 50);
                }
                $natural_category_id = setupAccountsParseCategoryId($_POST['natural_category_id'] ?? null);
                $functional_category_id = setupAccountsParseCategoryId($_POST['functional_category_id'] ?? null);
                $archived = isset($_POST['archived']) ? 1 : 0;
                $mutable_fund = isset($_POST['mutable_fund']) ? 1 : 0;
                
                // Validate required fields
                if (empty($name)) {
                    echo "Error: Account name is required\n";
                } else {
                    $stmt = $db->prepare("INSERT INTO accounts (name, description, normal_balance, coa_number, natural_category_id, functional_category_id, archived, mutable_fund) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssssiiii", $name, $description, $normal_balance, $coa_number, $natural_category_id, $functional_category_id, $archived, $mutable_fund);
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
                $id = $_POST['id'] ?? 0;
                $name = $_POST['name'] ?? '';
                $description = $_POST['description'] ?? '';
                $normal_balance = $_POST['normal_balance'] ?? 'debit';
                $coa_number = trim((string)($_POST['coa_number'] ?? ''));
                if ($coa_number === '') {
                    $coa_number = null;
                } elseif (mb_strlen($coa_number) > 50) {
                    $coa_number = mb_substr($coa_number, 0, 50);
                }
                $natural_category_id = setupAccountsParseCategoryId($_POST['natural_category_id'] ?? null);
                $functional_category_id = setupAccountsParseCategoryId($_POST['functional_category_id'] ?? null);
                $archived = isset($_POST['archived']) ? 1 : 0;
                $mutable_fund = isset($_POST['mutable_fund']) ? 1 : 0;
                
                // Validate required fields and account exists
                if (empty($name) || $id <= 0) {
                    echo "Error: Invalid account data\n";
                } else {
                    // Check if account exists before updating
                    $check_stmt = $db->prepare("SELECT id FROM accounts WHERE id = ?");
                    $check_stmt->bind_param("i", $id);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $stmt = $db->prepare("UPDATE accounts SET name=?, description=?, normal_balance=?, coa_number=?, natural_category_id=?, functional_category_id=?, archived=?, mutable_fund=? WHERE id=?");
                        $stmt->bind_param("ssssiiiii", $name, $description, $normal_balance, $coa_number, $natural_category_id, $functional_category_id, $archived, $mutable_fund, $id);
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
                $id = $_POST['id'] ?? 0;
                
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
                $id = $_POST['id'] ?? 0;
                $archived = $_POST['archived'] ?? 0;
                
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
                        $stmt = $db->prepare("UPDATE accounts SET archived=? WHERE id=?");
                        $stmt->bind_param("ii", $archived, $id);
                        if ($stmt->execute() === TRUE) {
                            echo "Account archived status updated successfully\n";
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
        $accounts_query = "SELECT a.id, a.name, a.description, a.normal_balance, a.coa_number, a.archived, a.mutable_fund,
                                  a.natural_category_id, a.functional_category_id,
                                  COALESCE(nc.name, '') AS natural_name,
                                  COALESCE(fc.name, '') AS functional_name
                           FROM accounts a
                           LEFT JOIN natural_categories nc ON nc.id = a.natural_category_id
                           LEFT JOIN functional_categories fc ON fc.id = a.functional_category_id
                           ORDER BY (a.coa_number IS NULL OR a.coa_number = '') ASC, a.coa_number ASC, a.name ASC, a.id ASC";
    } else {
        $accounts_query = "SELECT a.id, a.name, a.description, a.normal_balance, a.coa_number, a.archived, a.mutable_fund,
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

<div class="container-fluid mt-2 mt-md-4 px-0 px-sm-2">
    <h2 class="mb-3 mb-md-4 h3">Accounts Setup</h2>
    
    <!-- Controls -->
    <div class="row mb-3 g-2">
        <div class="col-12 col-md-4">
            <button id="showArchivedBtn" class="btn btn-outline-secondary" data-show-archived="<?= $show_archived ? '1' : '0' ?>">
                <?= $show_archived ? 'Hide' : 'Show' ?> Archived
            </button>
        </div>
        <div class="col-12 col-md-8 d-flex flex-wrap gap-2 justify-content-md-end">
            <button id="addBtn" class="btn btn-primary">Add</button>
            <button id="editBtn" class="btn btn-secondary" disabled>Edit</button>
            <button id="deleteBtn" class="btn btn-danger" disabled>Delete</button>
            <button id="archiveBtn" class="btn btn-warning" disabled>Archive/Unarchive</button>
        </div>
    </div>
    
    <!-- Account Table -->
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th>CoA #</th>
                    <th>Name</th>
                    <th>Description</th>
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
                        <?php $coaDisplay = trim((string)($account['coa_number'] ?? '')); ?>
                        <tr data-id="<?= $account['id'] ?>"
                            data-coa-number="<?= htmlspecialchars($coaDisplay) ?>"
                            data-natural-id="<?= $account['natural_category_id'] !== null ? (int)$account['natural_category_id'] : '' ?>"
                            data-functional-id="<?= $account['functional_category_id'] !== null ? (int)$account['functional_category_id'] : '' ?>">
                            <td class="font-monospace"><?= htmlspecialchars($coaDisplay !== '' ? $coaDisplay : '—') ?></td>
                            <td><?= htmlspecialchars($account['name']) ?></td>
                            <td><?= htmlspecialchars($account['description'] ?? '') ?></td>
                            <td><?= htmlspecialchars($account['normal_balance']) ?></td>
                            <td><?= htmlspecialchars($account['natural_name'] !== '' ? $account['natural_name'] : '—') ?></td>
                            <td><?= htmlspecialchars($account['functional_name'] !== '' ? $account['functional_name'] : '—') ?></td>
                            <td><?= $account['archived'] ? 'Yes' : 'No' ?></td>
                            <td><?= $account['mutable_fund'] ? 'Yes' : 'No' ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="text-center">No accounts found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Form for adding/editing -->
    <div id="accountForm" class="mt-4 d-none">
        <h4 id="formTitle">Add New Account</h4>
        <form id="accountFormContent" method="POST" data-dirty-track>
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
            
            <div class="mb-3">
                <label for="normal_balance" class="form-label">Normal Balance</label>
                <select class="form-select" id="normal_balance" name="normal_balance" required>
                    <option value="debit">Debit</option>
                    <option value="credit">Credit</option>
                </select>
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
                <input type="checkbox" class="form-check-input" id="archived" name="archived">
                <label class="form-check-label" for="archived">Archived</label>
            </div>
            
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="mutable_fund" name="mutable_fund">
                <label class="form-check-label" for="mutable_fund">Mutable Fund</label>
            </div>
            
            <button type="submit" class="btn btn-primary">Save</button>
            <button type="button" class="btn btn-secondary" id="cancelBtn">Cancel</button>
        </form>
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
    const accountForm = document.getElementById('accountForm');
    const accountFormContent = document.getElementById('accountFormContent');
    const formAction = document.getElementById('formAction');
    const accountId = document.getElementById('accountId');
    const formTitle = document.getElementById('formTitle');
    const cancelBtn = document.getElementById('cancelBtn');
    
    let selectedRow = null;
    
    // Enable/disable buttons based on row selection
    tableBody.addEventListener('click', function(event) {
        const row = event.target.closest('tr');
        if (row && row.dataset.id) {
            // Deselect previous row
            if (selectedRow) {
                selectedRow.classList.remove('table-primary');
            }
            
            // Select new row
            selectedRow = row;
            if (selectedRow) {
                selectedRow.classList.add('table-primary');
                // Enable action buttons
                editBtn.disabled = false;
                deleteBtn.disabled = false;
                archiveBtn.disabled = false;
            }
        }
    });
    
    // Add button
    addBtn.addEventListener('click', function() {
        if (typeof window.confirmLeaveIfDirty === 'function' && !window.confirmLeaveIfDirty()) return;
        // Reset form
        accountFormContent.reset();
        formAction.value = 'add';
        formTitle.textContent = 'Add New Account';
        accountId.value = '';
        accountForm.classList.remove('d-none');
        if (typeof window.TemperDirtyForms !== 'undefined') window.TemperDirtyForms.markClean(accountFormContent);
        
        // Disable action buttons during editing
        addBtn.disabled = true;
        editBtn.disabled = true;
        deleteBtn.disabled = true;
        archiveBtn.disabled = true;
    });
    
    // Edit button
    editBtn.addEventListener('click', function() {
        if (!selectedRow) return;
        if (typeof window.confirmLeaveIfDirty === 'function' && !window.confirmLeaveIfDirty()) return;
        
        // Get account data from the row
        // columns: 0 CoA, 1 Name, 2 Description, 3 Normal Balance, 4 Natural, 5 Functional, 6 Archived, 7 Mut. Fund
        const id = selectedRow.getAttribute('data-id');
        const name = selectedRow.cells[1].textContent;
        const description = selectedRow.cells[2].textContent;
        const normal_balance = selectedRow.cells[3].textContent;
        const archived = selectedRow.cells[6].textContent === 'Yes';
        const mutable_fund = selectedRow.cells[7].textContent === 'Yes';
        
        // Fill form
        accountId.value = id;
        document.getElementById('coa_number').value = selectedRow.dataset.coaNumber || '';
        document.getElementById('name').value = name;
        document.getElementById('description').value = description;
        document.getElementById('normal_balance').value = normal_balance;
        document.getElementById('natural_category_id').value = selectedRow.dataset.naturalId || '';
        document.getElementById('functional_category_id').value = selectedRow.dataset.functionalId || '';
        document.getElementById('archived').checked = archived;
        document.getElementById('mutable_fund').checked = mutable_fund;
        
        formAction.value = 'edit';
        formTitle.textContent = 'Edit Account';
        accountForm.classList.remove('d-none');
        if (typeof window.TemperDirtyForms !== 'undefined') window.TemperDirtyForms.markClean(accountFormContent);
        
        // Disable action buttons during editing
        addBtn.disabled = true;
        editBtn.disabled = true;
        deleteBtn.disabled = true;
        archiveBtn.disabled = true;
    });
    
    // Delete button
    deleteBtn.addEventListener('click', function() {
        if (!selectedRow) return;
        
        const id = selectedRow.getAttribute('data-id');
        if (confirm('Are you sure you want to delete this account?')) {
            // Set action to delete
            formAction.value = 'delete';
            accountId.value = id;
            
            // Submit form
            accountFormContent.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
    });
    
    // Archive/Unarchive button
    archiveBtn.addEventListener('click', function() {
        if (!selectedRow) {
            showToast('Please select an account first.', 'warning');
            return;
        }
        
        const id = selectedRow.getAttribute('data-id');
        const isCurrentlyArchived = selectedRow.cells[6].textContent.trim() === 'Yes';
        const newArchivedState = !isCurrentlyArchived;
        
        if (confirm(`Are you sure you want to ${newArchivedState ? 'archive' : 'unarchive'} this account?`)) {
            // Set form values for archive action
            formAction.value = 'archive';
            accountId.value = id;
            document.getElementById('archived').checked = newArchivedState;
            
            // Submit the form
            accountFormContent.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
    });
    
    // Cancel button
    cancelBtn.addEventListener('click', function() {
        if (typeof window.confirmLeaveIfDirty === 'function' && !window.confirmLeaveIfDirty()) return;
        if (typeof window.TemperDirtyForms !== 'undefined') window.TemperDirtyForms.markClean(accountFormContent);
        accountForm.classList.add('d-none');
        
        // Re-enable action buttons
        addBtn.disabled = false;
        editBtn.disabled = true;
        deleteBtn.disabled = true;
        archiveBtn.disabled = true;
    });
    
    // Toggle archived via fetch (no full reload)
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
    
    // Handle form submission via AJAX to stay in tab
    accountFormContent.addEventListener('submit', function(e) {
        e.preventDefault();
        const qs = showArchived ? '?show_archived=1' : '';
        submitFormAndReload(`pages/${currentPage}.php`, new FormData(accountFormContent), `pages/${currentPage}.php${qs}`);
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-accounts-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>

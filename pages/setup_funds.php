<?php
    // Setup Funds Page - Inner content only for AJAX loading

    // Security and DB connection already handled by index.php
    // Light fallback in case
require_once __DIR__ . '/../includes/page_bootstrap.php';
// Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'add') {
            $name = $_POST['name'] ?? '';
            $code = $_POST['code'] ?? '';
            $type = $_POST['type'] ?? '';
            $description = $_POST['description'] ?? '';
            $archived = temperParsePostArchived();

            if (empty($name)) {
                echo "Error: Fund name is required\n";
            } else {
                $stmt = $db->prepare("INSERT INTO funds (name, code, type, description, archived, archived_at) VALUES (?, ?, ?, ?, ?, IF(? = 1, NOW(), NULL))");
                $stmt->bind_param("ssssii", $name, $code, $type, $description, $archived, $archived);
                if ($stmt->execute() === TRUE) {
                    echo "Fund added successfully\n";
                } else {
                    echo "Error adding fund: " . $db->error . "\n";
                }
                $stmt->close();
            }
        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $name = $_POST['name'] ?? '';
            $code = $_POST['code'] ?? '';
            $type = $_POST['type'] ?? '';
            $description = $_POST['description'] ?? '';
            $archived = temperParsePostArchived();

            if (empty($name) || $id <= 0) {
                echo "Error: Invalid fund data\n";
            } else {
                $stmt = $db->prepare("UPDATE funds SET name=?, code=?, type=?, description=?, archived=?, archived_at=IF(? = 1, COALESCE(archived_at, NOW()), NULL) WHERE id=?");
                $stmt->bind_param("ssssiii", $name, $code, $type, $description, $archived, $archived, $id);
                if ($stmt->execute() === TRUE) {
                    echo "Fund updated successfully\n";
                } else {
                    echo "Error updating fund: " . $db->error . "\n";
                }
                $stmt->close();
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                echo "Error: Invalid fund ID\n";
            } else {
                $stmt = $db->prepare("DELETE FROM funds WHERE id=?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute() === TRUE) {
                    echo "Fund deleted successfully\n";
                } else {
                    echo "Error deleting fund: " . $db->error . "\n";
                }
                $stmt->close();
            }
        } elseif ($action === 'archive') {
            $id = (int)($_POST['id'] ?? 0);
            $archived = temperParsePostArchived();

            if ($id <= 0) {
                echo "Error: Invalid fund ID\n";
            } else {
                $stmt = $db->prepare("UPDATE funds SET archived=?, archived_at=IF(? = 1, COALESCE(archived_at, NOW()), NULL) WHERE id=?");
                $stmt->bind_param("iii", $archived, $archived, $id);
                if ($stmt->execute() === TRUE) {
                    echo $archived
                        ? "Fund archived successfully\n"
                        : "Fund unarchived successfully\n";
                } else {
                    echo "Error updating fund archived status: " . $db->error . "\n";
                }
                $stmt->close();
            }
        }
    }

    // Check if 'show_archived' parameter is set
    $show_archived = isset($_GET['show_archived']) && $_GET['show_archived'] == '1';

    // Build query for funds
    if ($show_archived) {
        $funds_query = "SELECT id, name, code, type, description, archived FROM funds ORDER BY name";
    } else {
        $funds_query = "SELECT id, name, code, type, description, archived FROM funds WHERE archived = FALSE ORDER BY name";
    }

    $funds_result = $db->query($funds_query);
?>

<div class="container-fluid mt-2 px-0 px-sm-2 temper-lookup-page" data-lookup-entity="funds">
    <!-- Title + filter + font + actions on one compact row -->
    <div class="temper-lookup-toolbar d-flex flex-wrap align-items-center column-gap-2 row-gap-1 mb-2">
        <h2 class="h5 mb-0 temper-lookup-title text-nowrap">Funds Setup</h2>

        <div class="input-group input-group-sm temper-lookup-filter-wrap">
            <span class="input-group-text" id="fundsFilterIcon"><i class="bi bi-search" aria-hidden="true"></i></span>
            <input type="search" class="form-control" id="fundsTableFilter"
                   placeholder="Filter…" aria-label="Filter funds"
                   aria-describedby="fundsFilterIcon" autocomplete="off" data-dirty-ignore>
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

    <!-- Fund Table -->
    <div class="table-responsive">
        <table class="table table-striped table-hover temper-lookup-table" id="fundsTable">
            <thead class="table-dark">
                <tr>
                    <th>Name</th>
                    <th>Code</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Archived</th>
                </tr>
            </thead>
            <tbody id="fundsTableBody">
                <?php if ($funds_result && $funds_result->num_rows > 0): ?>
                    <?php while ($fund = $funds_result->fetch_assoc()): ?>
                        <tr data-id="<?= (int)$fund['id'] ?>"
                            data-archived="<?= !empty($fund['archived']) ? '1' : '0' ?>"
                            class="<?= !empty($fund['archived']) ? 'table-secondary' : '' ?>">
                            <td><?= htmlspecialchars($fund['name']) ?></td>
                            <td><?= htmlspecialchars($fund['code'] ?? '') ?></td>
                            <td><?= htmlspecialchars($fund['type']) ?></td>
                            <td><?= htmlspecialchars($fund['description'] ?? '') ?></td>
                            <td><?= !empty($fund['archived']) ? 'Yes' : 'No' ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center">No funds found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Add / Edit modal -->
    <div class="modal fade" id="fundFormModal" tabindex="-1" aria-labelledby="formTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="fundFormContent" method="POST" data-dirty-track>
                    <div class="modal-header">
                        <h5 class="modal-title" id="formTitle">Add New Fund</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="fundId" name="id">
                        <input type="hidden" name="action" id="formAction">

                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>

                        <div class="mb-3">
                            <label for="code" class="form-label">Code</label>
                            <input type="text" class="form-control" id="code" name="code">
                        </div>

                        <div class="mb-3">
                            <label for="type" class="form-label">Type</label>
                            <select class="form-select" id="type" name="type" required>
                                <option value="WODR">WODR - Without Donor Restrictions</option>
                                <option value="WDR">WDR - With Donor Restrictions</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>

                        <div class="mb-0 form-check">
                            <input type="checkbox" class="form-check-input" id="archived" name="archived" value="1">
                            <label class="form-check-label" for="archived">Archived</label>
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

<script type="text/plain" id="init-funds-script">
(function() {
    const currentPage = 'setup_funds';
    const tableBody = document.getElementById('fundsTableBody');
    const addBtn = document.getElementById('addBtn');
    const editBtn = document.getElementById('editBtn');
    const deleteBtn = document.getElementById('deleteBtn');
    const archiveBtn = document.getElementById('archiveBtn');
    const showArchivedBtn = document.getElementById('showArchivedBtn');
    let showArchived = showArchivedBtn ? showArchivedBtn.dataset.showArchived === '1' : false;
    let fundFormModalEl = document.getElementById('fundFormModal');
    if (fundFormModalEl && typeof window.mountModalOnBody === 'function') {
        fundFormModalEl = window.mountModalOnBody(fundFormModalEl);
    }
    const fundFormModal = fundFormModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal
        ? bootstrap.Modal.getOrCreateInstance(fundFormModalEl)
        : null;
    const fundFormContent = document.getElementById('fundFormContent');
    const formAction = document.getElementById('formAction');
    const fundId = document.getElementById('fundId');
    const formTitle = document.getElementById('formTitle');

    let selectedRow = null;

    function openFormModal() {
        if (typeof window.showFragmentModal === 'function' && fundFormModalEl) {
            window.showFragmentModal(fundFormModalEl);
        } else if (fundFormModal) {
            fundFormModal.show();
        }
    }

    function rowIsArchived(row) {
        if (!row) return false;
        if (row.dataset && (row.dataset.archived === '1' || row.dataset.archived === 'true')) return true;
        const cell = row.cells && row.cells[4] ? row.cells[4].textContent.trim() : '';
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
        if (row && row.getAttribute('data-id')) {
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

    // Add button — open modal with empty form
    addBtn.addEventListener('click', function() {
        fundFormContent.reset();
        formAction.value = 'add';
        formTitle.textContent = 'Add New Fund';
        fundId.value = '';
        if (typeof window.TemperDirtyForms !== 'undefined') window.TemperDirtyForms.markClean(fundFormContent);
        openFormModal();
    });

    // Edit button — populate form from selected row and open modal
    editBtn.addEventListener('click', function() {
        if (!selectedRow) return;

        const id = selectedRow.getAttribute('data-id');
        const name = selectedRow.cells[0].textContent;
        const code = selectedRow.cells[1].textContent;
        const type = selectedRow.cells[2].textContent;
        const description = selectedRow.cells[3].textContent;
        const archived = rowIsArchived(selectedRow);

        fundId.value = id;
        document.getElementById('name').value = name;
        document.getElementById('code').value = code;
        document.getElementById('type').value = type;
        document.getElementById('description').value = description;
        document.getElementById('archived').checked = archived;

        formAction.value = 'edit';
        formTitle.textContent = 'Edit Fund';
        if (typeof window.TemperDirtyForms !== 'undefined') window.TemperDirtyForms.markClean(fundFormContent);
        openFormModal();
    });

    // Delete button
    deleteBtn.addEventListener('click', function() {
        if (!selectedRow) return;

        const id = selectedRow.getAttribute('data-id');
        if (confirm('Are you sure you want to delete this fund?')) {
            formAction.value = 'delete';
            fundId.value = id;
            fundFormContent.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
    });

    // Archive / Unarchive — explicit FormData (do not rely on the edit-form checkbox alone)
    archiveBtn.addEventListener('click', function() {
        if (!selectedRow) {
            if (typeof showToast === 'function') showToast('Please select a fund first.', 'warning');
            return;
        }
        if (typeof window.confirmLeaveIfDirty === 'function' && !window.confirmLeaveIfDirty()) return;

        const id = selectedRow.getAttribute('data-id');
        const isCurrentlyArchived = rowIsArchived(selectedRow);
        const newArchivedState = !isCurrentlyArchived;
        const verb = newArchivedState ? 'archive' : 'unarchive';

        if (!confirm('Are you sure you want to ' + verb + ' this fund?')) return;

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
    fundFormContent.addEventListener('submit', function(e) {
        e.preventDefault();
        const qs = showArchived ? '?show_archived=1' : '';
        submitFormAndReload(`pages/${currentPage}.php`, new FormData(fundFormContent), `pages/${currentPage}.php${qs}`);
    });

    // Filter, sort, table font size, and leader-key hotkeys
    if (typeof window.TemperLookupPage !== 'undefined') {
        window.TemperLookupPage.init({
            root: document.querySelector('.temper-lookup-page'),
            table: document.getElementById('fundsTable') || tableBody.closest('table'),
            filterInput: document.getElementById('fundsTableFilter'),
            emptyMessage: 'No matching funds.',
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
            table: document.getElementById('fundsTable') || tableBody.closest('table'),
            filterInput: document.getElementById('fundsTableFilter'),
            emptyMessage: 'No matching funds.'
        });
    }
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-funds-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>

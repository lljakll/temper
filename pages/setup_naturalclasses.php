<?php

    // Security and DB connection already handled by index.php
    // Light fallback in case
require_once __DIR__ . '/../includes/page_bootstrap.php';
// Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            $action = $_POST['action'];
            
            if ($action === 'add') {
                // Insert new natural category record
                $name = $_POST['name'] ?? '';
                $description = $_POST['description'] ?? '';
                $archived = temperParsePostArchived();
                
                // Validate required fields
                if (empty($name)) {
                    echo "Error: Name is required\n";
                } else {
                    $stmt = $db->prepare("INSERT INTO natural_categories (name, description, archived, archived_at) VALUES (?, ?, ?, IF(? = 1, NOW(), NULL))");
                    $stmt->bind_param("ssii", $name, $description, $archived, $archived);
                    if ($stmt->execute() === TRUE) {
                        echo "Natural category added successfully\n";
                    } else {
                        echo "Error adding natural category: " . $db->error . "\n";
                    }
                    $stmt->close();
                }
            }
            elseif ($action === 'edit') {
                // Update existing natural category record
                $id = (int)($_POST['id'] ?? 0);
                $name = $_POST['name'] ?? '';
                $description = $_POST['description'] ?? '';
                $archived = temperParsePostArchived();
                
                // Validate required fields and record exists
                if (empty($name) || $id <= 0) {
                    echo "Error: Invalid natural category data\n";
                } else {
                    // Check if record exists before updating
                    $check_stmt = $db->prepare("SELECT id FROM natural_categories WHERE id = ?");
                    $check_stmt->bind_param("i", $id);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $stmt = $db->prepare("UPDATE natural_categories SET name=?, description=?, archived=?, archived_at=IF(? = 1, COALESCE(archived_at, NOW()), NULL) WHERE id=?");
                        $stmt->bind_param("ssiii", $name, $description, $archived, $archived, $id);
                        if ($stmt->execute() === TRUE) {
                            echo "Natural category updated successfully\n";
                        } else {
                            echo "Error updating natural category: " . $db->error . "\n";
                        }
                        $stmt->close();
                    } else {
                        echo "Error: Natural category not found\n";
                    }
                    $check_stmt->close();
                }
            }
            elseif ($action === 'delete') {
                // Delete natural category record
                $id = (int)($_POST['id'] ?? 0);
                
                if ($id <= 0) {
                    echo "Error: Invalid natural category ID\n";
                } else {
                    $stmt = $db->prepare("DELETE FROM natural_categories WHERE id=?");
                    $stmt->bind_param("i", $id);
                    if ($stmt->execute() === TRUE) {
                        echo "Natural category deleted successfully\n";
                    } else {
                        echo "Error deleting natural category: " . $db->error . "\n";
                    }
                    $stmt->close();
                }
            }
            elseif ($action === 'archive') {
                // Archive/unarchive natural category record
                $id = (int)($_POST['id'] ?? 0);
                $archived = temperParsePostArchived();
                
                // Validate ID
                if ($id <= 0) {
                    echo "Error: Invalid natural category ID\n";
                } else {
                    // Check if record exists before updating
                    $check_stmt = $db->prepare("SELECT id FROM natural_categories WHERE id = ?");
                    $check_stmt->bind_param("i", $id);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $stmt = $db->prepare("UPDATE natural_categories SET archived=?, archived_at=IF(? = 1, COALESCE(archived_at, NOW()), NULL) WHERE id=?");
                        $stmt->bind_param("iii", $archived, $archived, $id);
                        if ($stmt->execute() === TRUE) {
                            echo $archived
                                ? "Natural category archived successfully\n"
                                : "Natural category unarchived successfully\n";
                        } else {
                            echo "Error updating natural category archived status: " . $db->error . "\n";
                        }
                        $stmt->close();
                    } else {
                        echo "Error: Natural category not found\n";
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

    // Build query for natural categories
    if ($show_archived) {
        $natural_query = "SELECT id, name, description, archived FROM natural_categories ORDER BY name";
    } else {
        $natural_query = "SELECT id, name, description, archived FROM natural_categories WHERE archived = FALSE ORDER BY name";
    }

    $natural_result = $db->query($natural_query);
?>

<div class="container-fluid mt-2 px-0 px-sm-2 temper-lookup-page" data-lookup-entity="natural">
    <!-- Title + filter + font + actions on one compact row -->
    <div class="temper-lookup-toolbar d-flex flex-wrap align-items-center column-gap-2 row-gap-1 mb-2">
        <h2 class="h5 mb-0 temper-lookup-title text-nowrap">Natural Classes Setup</h2>

        <div class="input-group input-group-sm temper-lookup-filter-wrap">
            <span class="input-group-text" id="naturalFilterIcon"><i class="bi bi-search" aria-hidden="true"></i></span>
            <input type="search" class="form-control" id="naturalTableFilter"
                   placeholder="Filter…" aria-label="Filter natural classes"
                   aria-describedby="naturalFilterIcon" autocomplete="off" data-dirty-ignore>
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

    <!-- Natural Categories Table -->
    <div class="table-responsive">
        <table class="table table-striped table-hover temper-lookup-table" id="naturalTable">
            <thead class="table-dark">
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Archived</th>
                </tr>
            </thead>
            <tbody id="naturalTableBody">
                <?php if ($natural_result && $natural_result->num_rows > 0): ?>
                    <?php while ($nat = $natural_result->fetch_assoc()): ?>
                        <tr data-id="<?= (int)$nat['id'] ?>"
                            data-archived="<?= !empty($nat['archived']) ? '1' : '0' ?>"
                            class="<?= !empty($nat['archived']) ? 'table-secondary' : '' ?>">
                            <td><?= htmlspecialchars($nat['name']) ?></td>
                            <td><?= htmlspecialchars($nat['description'] ?? '') ?></td>
                            <td><?= !empty($nat['archived']) ? 'Yes' : 'No' ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" class="text-center">No natural categories found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Add / Edit modal -->
    <div class="modal fade" id="naturalFormModal" tabindex="-1" aria-labelledby="formTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="naturalFormContent" method="POST" data-dirty-track>
                    <div class="modal-header">
                        <h5 class="modal-title" id="formTitle">Add New Natural Category</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="naturalId" name="id">
                        <input type="hidden" name="action" id="formAction">

                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
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

<script type="text/plain" id="init-natural-script">
(function() {
    const currentPage = 'setup_naturalclasses';
    const tableBody = document.getElementById('naturalTableBody');
    const addBtn = document.getElementById('addBtn');
    const editBtn = document.getElementById('editBtn');
    const deleteBtn = document.getElementById('deleteBtn');
    const archiveBtn = document.getElementById('archiveBtn');
    const showArchivedBtn = document.getElementById('showArchivedBtn');
    let showArchived = showArchivedBtn ? showArchivedBtn.dataset.showArchived === '1' : false;
    let naturalFormModalEl = document.getElementById('naturalFormModal');
    if (naturalFormModalEl && typeof window.mountModalOnBody === 'function') {
        naturalFormModalEl = window.mountModalOnBody(naturalFormModalEl);
    }
    const naturalFormModal = naturalFormModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal
        ? bootstrap.Modal.getOrCreateInstance(naturalFormModalEl)
        : null;
    const naturalFormContent = document.getElementById('naturalFormContent');
    const formAction = document.getElementById('formAction');
    const naturalId = document.getElementById('naturalId');
    const formTitle = document.getElementById('formTitle');

    let selectedRow = null;

    function openFormModal() {
        if (typeof window.showFragmentModal === 'function' && naturalFormModalEl) {
            window.showFragmentModal(naturalFormModalEl);
        } else if (naturalFormModal) {
            naturalFormModal.show();
        }
    }

    function rowIsArchived(row) {
        if (!row) return false;
        if (row.dataset && (row.dataset.archived === '1' || row.dataset.archived === 'true')) return true;
        const cell = row.cells && row.cells[2] ? row.cells[2].textContent.trim() : '';
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
        naturalFormContent.reset();
        formAction.value = 'add';
        formTitle.textContent = 'Add New Natural Category';
        naturalId.value = '';
        if (typeof window.TemperDirtyForms !== 'undefined') window.TemperDirtyForms.markClean(naturalFormContent);
        openFormModal();
    });

    // Edit button — populate form from selected row and open modal
    editBtn.addEventListener('click', function() {
        if (!selectedRow) return;

        const id = selectedRow.getAttribute('data-id');
        const name = selectedRow.cells[0].textContent;
        const description = selectedRow.cells[1].textContent;
        const archived = rowIsArchived(selectedRow);

        naturalId.value = id;
        document.getElementById('name').value = name;
        document.getElementById('description').value = description;
        document.getElementById('archived').checked = archived;

        formAction.value = 'edit';
        formTitle.textContent = 'Edit Natural Category';
        if (typeof window.TemperDirtyForms !== 'undefined') window.TemperDirtyForms.markClean(naturalFormContent);
        openFormModal();
    });

    // Delete button
    deleteBtn.addEventListener('click', function() {
        if (!selectedRow) return;

        const id = selectedRow.getAttribute('data-id');
        if (confirm('Are you sure you want to delete this natural category?')) {
            formAction.value = 'delete';
            naturalId.value = id;
            naturalFormContent.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
    });

    // Archive / Unarchive — explicit FormData so archived=0|1 is always sent correctly
    archiveBtn.addEventListener('click', function() {
        if (!selectedRow) {
            if (typeof showToast === 'function') showToast('Please select a natural category first.', 'warning');
            return;
        }
        if (typeof window.confirmLeaveIfDirty === 'function' && !window.confirmLeaveIfDirty()) return;

        const id = selectedRow.getAttribute('data-id');
        const isCurrentlyArchived = rowIsArchived(selectedRow);
        const newArchivedState = !isCurrentlyArchived;
        const verb = newArchivedState ? 'archive' : 'unarchive';

        if (!confirm('Are you sure you want to ' + verb + ' this natural category?')) return;

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
    naturalFormContent.addEventListener('submit', function(e) {
        e.preventDefault();
        const qs = showArchived ? '?show_archived=1' : '';
        submitFormAndReload(`pages/${currentPage}.php`, new FormData(naturalFormContent), `pages/${currentPage}.php${qs}`);
    });

    // Filter, sort, table font size, and leader-key hotkeys
    if (typeof window.TemperLookupPage !== 'undefined') {
        window.TemperLookupPage.init({
            root: document.querySelector('.temper-lookup-page'),
            table: document.getElementById('naturalTable') || tableBody.closest('table'),
            filterInput: document.getElementById('naturalTableFilter'),
            emptyMessage: 'No matching natural categories.',
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
            table: document.getElementById('naturalTable') || tableBody.closest('table'),
            filterInput: document.getElementById('naturalTableFilter'),
            emptyMessage: 'No matching natural categories.'
        });
    }
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-natural-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>

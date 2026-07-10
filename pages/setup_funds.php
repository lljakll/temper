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
            $archived = isset($_POST['archived']) ? 1 : 0;

            if (empty($name)) {
                echo "Error: Fund name is required\n";
            } else {
                $stmt = $db->prepare("INSERT INTO funds (name, code, type, description, archived) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $name, $code, $type, $description, $archived);
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
            $archived = isset($_POST['archived']) ? 1 : 0;

            if (empty($name) || $id <= 0) {
                echo "Error: Invalid fund data\n";
            } else {
                $stmt = $db->prepare("UPDATE funds SET name=?, code=?, type=?, description=?, archived=? WHERE id=?");
                $stmt->bind_param("sssssi", $name, $code, $type, $description, $archived, $id);
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
            $archived = (int)($_POST['archived'] ?? 0);

            if ($id <= 0) {
                echo "Error: Invalid fund ID\n";
            } else {
                $stmt = $db->prepare("UPDATE funds SET archived=? WHERE id=?");
                $stmt->bind_param("ii", $archived, $id);
                if ($stmt->execute() === TRUE) {
                    echo "Fund archived status updated successfully\n";
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

<div class="container mt-4">
    <h2 class="mb-4">Funds Setup</h2>
    
    <!-- Controls -->
    <div class="row mb-3">
        <div class="col-md-6">
            <button id="showArchivedBtn" class="btn btn-outline-secondary" data-show-archived="<?= $show_archived ? '1' : '0' ?>">
                <?= $show_archived ? 'Hide' : 'Show' ?> Archived
            </button>
        </div>
        <div class="col-md-6 text-end">
            <button id="addBtn" class="btn btn-primary me-2">Add</button>
            <button id="editBtn" class="btn btn-secondary me-2" disabled>Edit</button>
            <button id="deleteBtn" class="btn btn-danger me-2" disabled>Delete</button>
            <button id="archiveBtn" class="btn btn-warning me-2" disabled>Archive/Unarchive</button>
        </div>
    </div>
    
    <!-- Fund Table -->
    <div class="table-responsive">
        <table class="table table-striped table-hover">
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
                        <tr data-id="<?= $fund['id'] ?>">
                            <td><?= htmlspecialchars($fund['name']) ?></td>
                            <td><?= htmlspecialchars($fund['code'] ?? '') ?></td>
                            <td><?= htmlspecialchars($fund['type']) ?></td>
                            <td><?= htmlspecialchars($fund['description'] ?? '') ?></td>
                            <td><?= $fund['archived'] ? 'Yes' : 'No' ?></td>
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
    
    <!-- Form for adding/editing -->
    <div id="fundForm" class="mt-4 d-none">
        <h4 id="formTitle">Add New Fund</h4>
        <form id="fundFormContent" method="POST">
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
            
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="archived" name="archived">
                <label class="form-check-label" for="archived">Archived</label>
            </div>
            
            <button type="submit" class="btn btn-primary">Save</button>
            <button type="button" class="btn btn-secondary" id="cancelBtn">Cancel</button>
        </form>
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
    const fundForm = document.getElementById('fundForm');
    const fundFormContent = document.getElementById('fundFormContent');
    const formAction = document.getElementById('formAction');
    const fundId = document.getElementById('fundId');
    const formTitle = document.getElementById('formTitle');
    const cancelBtn = document.getElementById('cancelBtn');
    
    let selectedRow = null;
    
    // Enable/disable buttons based on row selection
    tableBody.addEventListener('click', function(event) {
        const row = event.target.closest('tr');
        if (row) {
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
        // Reset form
        fundFormContent.reset();
        formAction.value = 'add';
        formTitle.textContent = 'Add New Fund';
        fundId.value = '';
        fundForm.classList.remove('d-none');
        
        // Disable action buttons during editing
        addBtn.disabled = true;
        editBtn.disabled = true;
        deleteBtn.disabled = true;
        archiveBtn.disabled = true;
    });
    
    // Edit button
    editBtn.addEventListener('click', function() {
        if (!selectedRow) return;
        
        // Get fund data from the row
        const id = selectedRow.getAttribute('data-id');
        const name = selectedRow.cells[0].textContent;
        const code = selectedRow.cells[1].textContent;
        const type = selectedRow.cells[2].textContent;
        const description = selectedRow.cells[3].textContent;
        const archived = selectedRow.cells[4].textContent === 'Yes';
        
        // Fill form
        fundId.value = id;
        document.getElementById('name').value = name;
        document.getElementById('code').value = code;
        document.getElementById('type').value = type;
        document.getElementById('description').value = description;
        document.getElementById('archived').checked = archived;
        
        formAction.value = 'edit';
        formTitle.textContent = 'Edit Fund';
        fundForm.classList.remove('d-none');
        
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
        if (confirm('Are you sure you want to delete this fund?')) {
            // Set action to delete
            formAction.value = 'delete';
            fundId.value = id;
            
            // Submit form
            fundFormContent.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
    });
    
    // Cancel button
    cancelBtn.addEventListener('click', function() {
        fundForm.classList.add('d-none');
        
        // Re-enable action buttons
        addBtn.disabled = false;
        editBtn.disabled = true;
        deleteBtn.disabled = true;
        archiveBtn.disabled = true;
    });
    
    // Toggle archived via fetch (no full reload)
    showArchivedBtn.addEventListener('click', function() {
        showArchived = !showArchived;
        const newShow = showArchived ? '1' : '0';
        fetch(`pages/${currentPage}.php?show_archived=${newShow}`)
            .then(r => r.text())
            .then(html => { document.getElementById('main-content').innerHTML = html; })
            .catch(e => console.error('Toggle error:', e));
    });
    
    // Handle form submission via AJAX to stay in tab
    fundFormContent.addEventListener('submit', function(e) {
        e.preventDefault();
        const qs = showArchived ? '?show_archived=1' : '';
        submitFormAndReload(`pages/${currentPage}.php`, new FormData(fundFormContent), `pages/${currentPage}.php${qs}`);
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-funds-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>

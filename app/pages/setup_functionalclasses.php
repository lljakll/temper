<?php
    // Setup Functional Classes Page - Inner content only for AJAX loading

    // Security and DB connection already handled by index.php
    // Light fallback in case
    if (!isset($db)) {
        require_once __DIR__ . '/../config.php';
        $db = getDbConnection();
    }

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'add') {
            $name = $_POST['name'] ?? '';
            $description = $_POST['description'] ?? '';
            $archived = isset($_POST['archived']) ? 1 : 0;
            
            $stmt = $db->prepare("INSERT INTO functional_categories (name, description, archived) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $name, $description, $archived);
            $stmt->execute();
            $stmt->close();
        } elseif ($action === 'edit') {
            $id = $_POST['id'] ?? 0;
            $name = $_POST['name'] ?? '';
            $description = $_POST['description'] ?? '';
            $archived = isset($_POST['archived']) ? 1 : 0;
            
            $stmt = $db->prepare("UPDATE functional_categories SET name=?, description=?, archived=? WHERE id=?");
            $stmt->bind_param("ssii", $name, $description, $archived, $id);
            $stmt->execute();
            $stmt->close();
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? 0;
            $stmt = $db->prepare("DELETE FROM functional_categories WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        } elseif ($action === 'archive') {
            $id = $_POST['id'] ?? 0;
            $archived = isset($_POST['archived']) ? 1 : 0;
            
            $stmt = $db->prepare("UPDATE functional_categories SET archived=? WHERE id=?");
            $stmt->bind_param("ii", $archived, $id);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Check if 'show_archived' parameter is set
    $show_archived = isset($_GET['show_archived']) && $_GET['show_archived'] == '1';

    // Build query for functional categories
    if ($show_archived) {
        $functional_query = "SELECT id, name, description, archived FROM functional_categories ORDER BY name";
    } else {
        $functional_query = "SELECT id, name, description, archived FROM functional_categories WHERE archived = FALSE ORDER BY name";
    }

    $functional_result = $db->query($functional_query);
?>

<div class="container mt-4">
    <h2 class="mb-4">Functional Classes Setup</h2>
    
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
    
    <!-- Functional Classes Table -->
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Archived</th>
                </tr>
            </thead>
            <tbody id="functionalTableBody">
                <?php if ($functional_result && $functional_result->num_rows > 0): ?>
                    <?php while ($fc = $functional_result->fetch_assoc()): ?>
                        <tr data-id="<?= $fc['id'] ?>">
                            <td><?= htmlspecialchars($fc['name']) ?></td>
                            <td><?= htmlspecialchars($fc['description'] ?? '') ?></td>
                            <td><?= $fc['archived'] ? 'Yes' : 'No' ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" class="text-center">No functional classes found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Form for adding/editing -->
    <div id="functionalForm" class="mt-4 d-none">
        <h4 id="formTitle">Add New Functional Class</h4>
        <form id="functionalFormContent" method="POST">
            <input type="hidden" id="functionalId" name="id">
            <input type="hidden" name="action" id="formAction">
            
            <div class="mb-3">
                <label for="name" class="form-label">Name</label>
                <input type="text" class="form-control" id="name" name="name" required>
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

<script type="text/plain" id="init-functional-script">
(function() {
    const currentPage = 'setup_functionalclasses';
    const tableBody = document.getElementById('functionalTableBody');
    const addBtn = document.getElementById('addBtn');
    const editBtn = document.getElementById('editBtn');
    const deleteBtn = document.getElementById('deleteBtn');
    const archiveBtn = document.getElementById('archiveBtn');
    const showArchivedBtn = document.getElementById('showArchivedBtn');
    let showArchived = showArchivedBtn ? showArchivedBtn.dataset.showArchived === '1' : false;
    const functionalForm = document.getElementById('functionalForm');
    const functionalFormContent = document.getElementById('functionalFormContent');
    const formAction = document.getElementById('formAction');
    const functionalId = document.getElementById('functionalId');
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
        functionalFormContent.reset();
        formAction.value = 'add';
        formTitle.textContent = 'Add New Functional Class';
        functionalId.value = '';
        functionalForm.classList.remove('d-none');
        
        // Disable action buttons during editing
        addBtn.disabled = true;
        editBtn.disabled = true;
        deleteBtn.disabled = true;
        archiveBtn.disabled = true;
    });
    
    // Edit button
    editBtn.addEventListener('click', function() {
        if (!selectedRow) return;
        
        // Get data from the row
        const id = selectedRow.getAttribute('data-id');
        const name = selectedRow.cells[0].textContent;
        const description = selectedRow.cells[1].textContent;
        const archived = selectedRow.cells[2].textContent === 'Yes';
        
        // Fill form
        functionalId.value = id;
        document.getElementById('name').value = name;
        document.getElementById('description').value = description;
        document.getElementById('archived').checked = archived;
        
        formAction.value = 'edit';
        formTitle.textContent = 'Edit Functional Class';
        functionalForm.classList.remove('d-none');
        
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
        if (confirm('Are you sure you want to delete this functional class?')) {
            // Set action to delete
            formAction.value = 'delete';
            functionalId.value = id;
            
            // Submit form
            functionalFormContent.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
    });
    
    // Archive/Unarchive button
    archiveBtn.addEventListener('click', function() {
        if (!selectedRow) {
            showToast('Please select a functional class first.', 'warning');
            return;
        }
        
        const id = selectedRow.getAttribute('data-id');
        const isCurrentlyArchived = selectedRow.cells[2].textContent.trim() === 'Yes';
        const newArchivedState = !isCurrentlyArchived;
        
        if (confirm(`Are you sure you want to ${newArchivedState ? 'archive' : 'unarchive'} this functional class?`)) {
            // Set form values for archive action
            formAction.value = 'archive';
            functionalId.value = id;
            document.getElementById('archived').checked = newArchivedState;
            
            // Submit the form
            functionalFormContent.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
    });
    
    // Cancel button
    cancelBtn.addEventListener('click', function() {
        functionalForm.classList.add('d-none');
        
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
    functionalFormContent.addEventListener('submit', function(e) {
        e.preventDefault();
        const qs = showArchived ? '?show_archived=1' : '';
        submitFormAndReload(`pages/${currentPage}.php`, new FormData(functionalFormContent), `pages/${currentPage}.php${qs}`);
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-functional-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>
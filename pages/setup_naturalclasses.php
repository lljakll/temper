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
                $archived = isset($_POST['archived']) ? 1 : 0;
                
                // Validate required fields
                if (empty($name)) {
                    echo "Error: Name is required\n";
                } else {
                    $stmt = $db->prepare("INSERT INTO natural_categories (name, description, archived) VALUES (?, ?, ?)");
                    $stmt->bind_param("ssi", $name, $description, $archived);
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
                $id = $_POST['id'] ?? 0;
                $name = $_POST['name'] ?? '';
                $description = $_POST['description'] ?? '';
                $archived = isset($_POST['archived']) ? 1 : 0;
                
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
                        $stmt = $db->prepare("UPDATE natural_categories SET name=?, description=?, archived=? WHERE id=?");
                        $stmt->bind_param("ssii", $name, $description, $archived, $id);
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
                $id = $_POST['id'] ?? 0;
                
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
                $id = $_POST['id'] ?? 0;
                $archived = $_POST['archived'] ?? 0;
                
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
                        $stmt = $db->prepare("UPDATE natural_categories SET archived=? WHERE id=?");
                        $stmt->bind_param("ii", $archived, $id);
                        if ($stmt->execute() === TRUE) {
                            echo "Natural category archived status updated successfully\n";
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

<div class="container-fluid mt-2 mt-md-4 px-0 px-sm-2">
    <h2 class="mb-3 mb-md-4 h3">Natural Classes Setup</h2>
    
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
    
    <!-- Natural Categories Table -->
    <div class="table-responsive">
        <table class="table table-striped table-hover">
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
                        <tr data-id="<?= $nat['id'] ?>">
                            <td><?= htmlspecialchars($nat['name']) ?></td>
                            <td><?= htmlspecialchars($nat['description'] ?? '') ?></td>
                            <td><?= $nat['archived'] ? 'Yes' : 'No' ?></td>
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
    
    <!-- Form for adding/editing -->
    <div id="naturalForm" class="mt-4 d-none">
        <h4 id="formTitle">Add New Natural Category</h4>
        <form id="naturalFormContent" method="POST">
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
            
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="archived" name="archived">
                <label class="form-check-label" for="archived">Archived</label>
            </div>
            
            <button type="submit" class="btn btn-primary">Save</button>
            <button type="button" class="btn btn-secondary" id="cancelBtn">Cancel</button>
        </form>
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
    const naturalForm = document.getElementById('naturalForm');
    const naturalFormContent = document.getElementById('naturalFormContent');
    const formAction = document.getElementById('formAction');
    const naturalId = document.getElementById('naturalId');
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
        naturalFormContent.reset();
        formAction.value = 'add';
        formTitle.textContent = 'Add New Natural Category';
        naturalId.value = '';
        naturalForm.classList.remove('d-none');
        
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
        naturalId.value = id;
        document.getElementById('name').value = name;
        document.getElementById('description').value = description;
        document.getElementById('archived').checked = archived;
        
        formAction.value = 'edit';
        formTitle.textContent = 'Edit Natural Category';
        naturalForm.classList.remove('d-none');
        
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
        if (confirm('Are you sure you want to delete this natural category?')) {
            // Set action to delete
            formAction.value = 'delete';
            naturalId.value = id;
            
            // Submit form
            naturalFormContent.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
    });
    
    // Archive/Unarchive button
    archiveBtn.addEventListener('click', function() {
        if (!selectedRow) {
            showToast('Please select a natural category first.', 'warning');
            return;
        }
        
        const id = selectedRow.getAttribute('data-id');
        const isCurrentlyArchived = selectedRow.cells[2].textContent.trim() === 'Yes';
        const newArchivedState = !isCurrentlyArchived;
        
        if (confirm(`Are you sure you want to ${newArchivedState ? 'archive' : 'unarchive'} this natural category?`)) {
            // Set form values for archive action
            formAction.value = 'archive';
            naturalId.value = id;
            document.getElementById('archived').checked = newArchivedState;
            
            // Submit the form
            naturalFormContent.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
    });
    
    // Cancel button
    cancelBtn.addEventListener('click', function() {
        naturalForm.classList.add('d-none');
        
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
    naturalFormContent.addEventListener('submit', function(e) {
        e.preventDefault();
        const qs = showArchived ? '?show_archived=1' : '';
        submitFormAndReload(`pages/${currentPage}.php`, new FormData(naturalFormContent), `pages/${currentPage}.php${qs}`);
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-natural-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>

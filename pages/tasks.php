<?php
    // Tasks / Reminders - Inner content only for AJAX loading

require_once __DIR__ . '/../includes/page_bootstrap.php';
$db->query("CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        description TEXT,
        due_date DATE NULL,
        status ENUM('upcoming', 'due_soon', 'overdue', 'in_progress', 'done') NOT NULL DEFAULT 'upcoming',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $today = date('Y-m-d');
    $validStatuses = ['upcoming', 'due_soon', 'overdue', 'in_progress', 'done'];

    if (!function_exists('tasksComputeDueStatus')) {
        function tasksComputeDueStatus(?string $dueDate, string $today): string {
            if ($dueDate === null || $dueDate === '') {
                return 'upcoming';
            }
            if ($dueDate < $today) {
                return 'overdue';
            }
            $dueTs = strtotime($dueDate . ' 12:00:00');
            $todayTs = strtotime($today . ' 12:00:00');
            if ($dueTs === false || $todayTs === false) {
                return 'upcoming';
            }
            $days = (int)floor(($dueTs - $todayTs) / 86400);
            return $days <= 7 ? 'due_soon' : 'upcoming';
        }
    }

    if (!function_exists('tasksFormatRow')) {
        function tasksFormatRow(array $row): array {
            return [
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'description' => $row['description'] ?? '',
                'due_date' => $row['due_date'] ?? '',
                'status' => $row['status'],
                'created_at' => $row['created_at'] ?? '',
            ];
        }
    }

    if (!function_exists('tasksNormalizeDueDate')) {
        /**
         * Normalize posted due date to Y-m-d or null. Returns [normalized, errorMessage].
         * Avoids empty-string DATE inserts that throw under strict SQL mode.
         *
         * @return array{0:?string,1:?string}
         */
        function tasksNormalizeDueDate($raw): array {
            if ($raw === null) {
                return [null, null];
            }
            $raw = trim((string)$raw);
            if ($raw === '') {
                return [null, null];
            }
            // Prefer DateTime over regex (avoids PCRE JIT noise and validates calendar dates)
            $dt = DateTime::createFromFormat('Y-m-d', $raw);
            $errors = DateTime::getLastErrors();
            $hasErrors = is_array($errors)
                && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
            if (!$dt || $hasErrors || $dt->format('Y-m-d') !== $raw) {
                return [null, 'Enter a valid due date.'];
            }
            return [$dt->format('Y-m-d'), null];
        }
    }

    if (!function_exists('tasksJsonResponse')) {
        function tasksJsonResponse(array $payload, int $httpCode = 200): void {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            // Keep API responses clean even when display_errors is on
            $prevDisplay = ini_get('display_errors');
            ini_set('display_errors', '0');
            if (!headers_sent()) {
                http_response_code($httpCode);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($prevDisplay !== false) {
                ini_set('display_errors', (string)$prevDisplay);
            }
            exit;
        }
    }

    if (isset($_GET['api']) && $_GET['api'] === 'list') {
        $tasks = [];
        try {
            $res = $db->query("SELECT id, title, description, due_date, status, created_at FROM tasks ORDER BY due_date IS NULL, due_date ASC, id DESC");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $tasks[] = tasksFormatRow($row);
                }
                $res->close();
            }
        } catch (Throwable $e) {
            error_log('tasks list failed: ' . $e->getMessage());
            tasksJsonResponse([
                'error' => 'Could not load tasks due to a system error. Please refresh and try again.',
                'system' => true,
            ], 500);
        }
        tasksJsonResponse(['tasks' => $tasks]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = (string)$_POST['action'];

        if ($action === 'create') {
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $statusRaw = trim((string)($_POST['status'] ?? ''));
            [$dueDate, $dueError] = tasksNormalizeDueDate($_POST['due_date'] ?? null);

            $fieldErrors = [];
            if ($title === '') {
                $fieldErrors['title'] = 'Task name is required.';
            } elseif (mb_strlen($title) > 200) {
                $fieldErrors['title'] = 'Task name must be 200 characters or fewer.';
            }
            if ($dueError !== null) {
                $fieldErrors['due_date'] = $dueError;
            }
            if ($statusRaw !== '' && !in_array($statusRaw, $validStatuses, true)) {
                $fieldErrors['status'] = 'Choose a valid status, or leave Auto selected.';
            }

            if ($fieldErrors !== []) {
                tasksJsonResponse([
                    'error' => 'Please fix the highlighted fields.',
                    'fields' => $fieldErrors,
                ], 422);
            }

            $status = $statusRaw;
            if ($status === '' || !in_array($status, $validStatuses, true)) {
                $status = tasksComputeDueStatus($dueDate, $today);
            } elseif (in_array($status, ['upcoming', 'due_soon', 'overdue'], true) && $dueDate !== null) {
                // Keep due-based statuses in sync with the chosen date
                $status = tasksComputeDueStatus($dueDate, $today);
            }

            try {
                // Bind NULL explicitly when no due date — empty string fails under strict SQL mode
                if ($dueDate === null) {
                    $stmt = $db->prepare(
                        'INSERT INTO tasks (title, description, due_date, status) VALUES (?, ?, NULL, ?)'
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Prepare failed: ' . $db->error);
                    }
                    $stmt->bind_param('sss', $title, $description, $status);
                } else {
                    $stmt = $db->prepare(
                        'INSERT INTO tasks (title, description, due_date, status) VALUES (?, ?, ?, ?)'
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Prepare failed: ' . $db->error);
                    }
                    $stmt->bind_param('ssss', $title, $description, $dueDate, $status);
                }

                if (!$stmt->execute()) {
                    $dbErr = $stmt->error ?: 'unknown database error';
                    $stmt->close();
                    throw new RuntimeException('Execute failed: ' . $dbErr);
                }
                $id = (int)$stmt->insert_id;
                $stmt->close();

                $get = $db->prepare(
                    'SELECT id, title, description, due_date, status, created_at FROM tasks WHERE id = ?'
                );
                if (!$get) {
                    throw new RuntimeException('Could not reload saved task: ' . $db->error);
                }
                $get->bind_param('i', $id);
                $get->execute();
                $row = $get->get_result()->fetch_assoc();
                $get->close();
                if (!$row) {
                    throw new RuntimeException('Task was saved but could not be reloaded.');
                }
                tasksJsonResponse(['success' => true, 'task' => tasksFormatRow($row)]);
            } catch (Throwable $e) {
                error_log('tasks create failed: ' . $e->getMessage());
                tasksJsonResponse([
                    'error' => 'Could not save the task due to a system error. Please try again. If it keeps failing, contact an administrator.',
                    'system' => true,
                ], 500);
            }
        }

        if ($action === 'update_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            if ($id <= 0 || !in_array($status, $validStatuses, true)) {
                tasksJsonResponse([
                    'error' => 'Could not update status: invalid task or status value.',
                    'system' => true,
                ], 400);
            }
            try {
                $stmt = $db->prepare('UPDATE tasks SET status = ? WHERE id = ?');
                if (!$stmt) {
                    throw new RuntimeException('Prepare failed: ' . $db->error);
                }
                $stmt->bind_param('si', $status, $id);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($affected < 1) {
                    // unchanged status still counts as success for drag/drop UX
                    $check = $db->prepare('SELECT id FROM tasks WHERE id = ?');
                    if ($check) {
                        $check->bind_param('i', $id);
                        $check->execute();
                        $exists = (bool)$check->get_result()->fetch_assoc();
                        $check->close();
                        if ($exists) {
                            tasksJsonResponse(['success' => true, 'unchanged' => true]);
                        }
                    }
                    tasksJsonResponse([
                        'error' => 'Task not found. It may have been deleted.',
                        'system' => true,
                    ], 404);
                }
                tasksJsonResponse(['success' => true]);
            } catch (Throwable $e) {
                error_log('tasks update_status failed: ' . $e->getMessage());
                tasksJsonResponse([
                    'error' => 'Could not update task status due to a system error. Please try again.',
                    'system' => true,
                ], 500);
            }
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                tasksJsonResponse([
                    'error' => 'Could not delete: invalid task.',
                    'system' => true,
                ], 400);
            }
            try {
                $stmt = $db->prepare('DELETE FROM tasks WHERE id = ?');
                if (!$stmt) {
                    throw new RuntimeException('Prepare failed: ' . $db->error);
                }
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $deleted = $stmt->affected_rows > 0;
                $stmt->close();
                if (!$deleted) {
                    tasksJsonResponse([
                        'error' => 'Task not found. It may have already been deleted.',
                        'system' => true,
                    ], 404);
                }
                tasksJsonResponse(['success' => true]);
            } catch (Throwable $e) {
                error_log('tasks delete failed: ' . $e->getMessage());
                tasksJsonResponse([
                    'error' => 'Could not delete the task due to a system error. Please try again.',
                    'system' => true,
                ], 500);
            }
        }

        tasksJsonResponse([
            'error' => 'Unknown action. Please refresh the page and try again.',
            'system' => true,
        ], 400);
    }

    $tasksByStatus = array_fill_keys($validStatuses, []);
    $res = $db->query("SELECT id, title, description, due_date, status, created_at FROM tasks ORDER BY due_date IS NULL, due_date ASC, id DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $status = $row['status'];
            if (!isset($tasksByStatus[$status])) {
                $status = 'upcoming';
            }
            $tasksByStatus[$status][] = $row;
        }
        $res->close();
    }

    $statusMeta = [
        'upcoming' => ['label' => 'Upcoming', 'badge' => 'secondary', 'header' => 'bg-secondary'],
        'due_soon' => ['label' => 'Due Soon', 'badge' => 'warning', 'header' => 'bg-warning text-dark'],
        'overdue' => ['label' => 'Overdue', 'badge' => 'danger', 'header' => 'bg-danger'],
        'in_progress' => ['label' => 'In Progress', 'badge' => 'info', 'header' => 'bg-info text-dark'],
        'done' => ['label' => 'Done', 'badge' => 'success', 'header' => 'bg-success'],
    ];

    $allTasks = [];
    foreach ($validStatuses as $s) {
        foreach ($tasksByStatus[$s] as $t) {
            $allTasks[] = tasksFormatRow($t);
        }
    }
?>

<style>
    .kanban-board {
        display: flex;
        gap: 0.75rem;
        overflow-x: auto;
        padding-bottom: 0.5rem;
        min-height: 420px;
    }
    .kanban-column {
        flex: 1 1 200px;
        min-width: 200px;
        max-width: 280px;
    }
    .kanban-column-body {
        min-height: 360px;
        background: var(--bs-tertiary-bg);
        border: 1px dashed var(--bs-border-color);
        border-radius: 0.375rem;
        padding: 0.5rem;
        transition: background-color 0.15s, border-color 0.15s;
    }
    .kanban-column-body.drag-over {
        background: rgba(var(--bs-primary-rgb), 0.12);
        border-color: var(--bs-primary);
    }
    .task-card {
        cursor: grab;
        user-select: none;
    }
    .task-card:active {
        cursor: grabbing;
    }
    .task-card.dragging {
        opacity: 0.45;
    }
    .task-card .card-text {
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .kanban-empty {
        color: var(--bs-secondary-color);
        font-size: 0.85rem;
        text-align: center;
        padding: 1.5rem 0.5rem;
        pointer-events: none;
    }
    .calendar-table th {
        text-align: center;
        font-size: 0.8rem;
        text-transform: uppercase;
        color: var(--bs-secondary-color);
        background: var(--bs-tertiary-bg);
    }
    .calendar-day {
        vertical-align: top;
        height: 110px;
        width: 14.28%;
        cursor: pointer;
        transition: background-color 0.15s;
        color: var(--bs-body-color);
    }
    .calendar-day:hover {
        background-color: var(--bs-tertiary-bg);
    }
    .calendar-day.other-month {
        background-color: var(--bs-secondary-bg);
        color: var(--bs-secondary-color);
    }
    .calendar-day.today .calendar-day-num {
        background: var(--bs-primary);
        color: var(--bs-white, #fff);
    }
    .calendar-day.selected {
        background-color: rgba(var(--bs-primary-rgb), 0.12);
        box-shadow: inset 0 0 0 2px var(--bs-primary);
    }
    .calendar-day-num {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.75rem;
        height: 1.75rem;
        border-radius: 50%;
        font-weight: 600;
        font-size: 0.85rem;
        margin-bottom: 0.25rem;
    }
    .calendar-task-pill {
        display: block;
        font-size: 0.7rem;
        line-height: 1.2;
        padding: 0.1rem 0.35rem;
        margin-bottom: 0.15rem;
        border-radius: 0.25rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        background: var(--bs-secondary-bg);
        color: var(--bs-body-color);
    }
    .calendar-task-pill.status-overdue { background: var(--bs-danger-bg-subtle); color: var(--bs-danger-text-emphasis); }
    .calendar-task-pill.status-due_soon { background: var(--bs-warning-bg-subtle); color: var(--bs-warning-text-emphasis); }
    .calendar-task-pill.status-in_progress { background: var(--bs-info-bg-subtle); color: var(--bs-info-text-emphasis); }
    .calendar-task-pill.status-done { background: var(--bs-success-bg-subtle); color: var(--bs-success-text-emphasis); }
    .calendar-more {
        font-size: 0.7rem;
        color: var(--bs-secondary-color);
    }
</style>

<div class="row mb-3 align-items-center g-2">
    <div class="col-12 col-md-8">
        <h2 class="mb-0">Tasks / Reminders</h2>
        <p class="text-muted mb-0">Manual tasks and recurring-action reminders</p>
    </div>
    <div class="col-12 col-md-4 d-flex flex-wrap justify-content-md-end align-items-center gap-2">
        <div class="btn-group flex-grow-1 flex-md-grow-0" role="group" aria-label="View toggle">
            <button type="button" class="btn btn-outline-primary active" id="viewKanbanBtn">
                <i class="bi bi-kanban"></i> <span class="d-none d-sm-inline">Kanban</span>
            </button>
            <button type="button" class="btn btn-outline-primary" id="viewListBtn">
                <i class="bi bi-list-ul"></i> <span class="d-none d-sm-inline">List</span>
            </button>
            <button type="button" class="btn btn-outline-primary" id="viewCalendarBtn">
                <i class="bi bi-calendar3"></i> <span class="d-none d-sm-inline">Calendar</span>
            </button>
        </div>
        <button type="button" class="btn btn-primary" id="addTaskBtn">
            <i class="bi bi-plus-lg"></i> Add Task
        </button>
    </div>
</div>

<div id="kanbanView">
    <div class="kanban-board">
        <?php foreach ($validStatuses as $status): ?>
            <?php $meta = $statusMeta[$status]; $items = $tasksByStatus[$status]; ?>
            <div class="kanban-column" data-status="<?= htmlspecialchars($status) ?>">
                <div class="card h-100 shadow-sm">
                    <div class="card-header <?= $meta['header'] ?> d-flex justify-content-between align-items-center py-2">
                        <span class="fw-semibold"><?= htmlspecialchars($meta['label']) ?></span>
                        <span class="badge rounded-pill bg-body-secondary text-body" id="count-<?= $status ?>"><?= count($items) ?></span>
                    </div>
                    <div class="card-body p-2">
                        <div class="kanban-column-body" data-drop-zone="<?= htmlspecialchars($status) ?>">
                            <?php if (count($items) === 0): ?>
                                <div class="kanban-empty">Drop tasks here</div>
                            <?php endif; ?>
                            <?php foreach ($items as $task): ?>
                                <div class="card task-card mb-2 shadow-sm"
                                     draggable="true"
                                     data-id="<?= (int)$task['id'] ?>"
                                     data-status="<?= htmlspecialchars($task['status']) ?>">
                                    <div class="card-body p-2">
                                        <div class="d-flex justify-content-between align-items-start gap-1">
                                            <h6 class="card-title mb-1 small fw-semibold"><?= htmlspecialchars($task['title']) ?></h6>
                                            <button type="button" class="btn btn-link btn-sm text-danger p-0 task-delete" title="Delete">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                        <?php if (!empty($task['description'])): ?>
                                            <p class="card-text text-muted small mb-1"><?= htmlspecialchars($task['description']) ?></p>
                                        <?php endif; ?>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted">
                                                <i class="bi bi-calendar3"></i>
                                                <?= $task['due_date'] ? htmlspecialchars($task['due_date']) : 'No due date' ?>
                                            </small>
                                            <span class="badge bg-<?= $statusMeta[$task['status']]['badge'] ?? 'secondary' ?>">
                                                <?= htmlspecialchars($statusMeta[$task['status']]['label'] ?? $task['status']) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="calendarView" class="d-none">
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center py-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="calPrevBtn" title="Previous month">
                <i class="bi bi-chevron-left"></i>
            </button>
            <h5 class="mb-0" id="calMonthLabel"></h5>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="calNextBtn" title="Next month">
                <i class="bi bi-chevron-right"></i>
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered calendar-table mb-0">
                    <thead>
                        <tr>
                            <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th>
                            <th>Thu</th><th>Fri</th><th>Sat</th>
                        </tr>
                    </thead>
                    <tbody id="calGridBody"></tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="card shadow-sm mt-3">
        <div class="card-header py-2">
            <i class="bi bi-calendar-event"></i>
            Tasks for <span id="calDayLabel" class="fw-semibold">—</span>
        </div>
        <div class="card-body" id="calDayTasks">
            <p class="text-muted mb-0">Click a date on the calendar to view tasks due that day.</p>
        </div>
    </div>
</div>

<div id="listView" class="d-none">
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Title</th>
                            <th>Description</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tasksListBody">
                        <?php if (count($allTasks) === 0): ?>
                            <tr id="tasksListEmpty">
                                <td colspan="5" class="text-center text-muted py-4">No tasks yet. Add one to get started.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allTasks as $task): ?>
                                <tr data-id="<?= (int)$task['id'] ?>">
                                    <td class="fw-semibold"><?= htmlspecialchars($task['title']) ?></td>
                                    <td class="text-muted small"><?= htmlspecialchars($task['description']) ?></td>
                                    <td><?= $task['due_date'] ? htmlspecialchars($task['due_date']) : '—' ?></td>
                                    <td>
                                        <span class="badge bg-<?= $statusMeta[$task['status']]['badge'] ?? 'secondary' ?>">
                                            <?= htmlspecialchars($statusMeta[$task['status']]['label'] ?? $task['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <select class="form-select form-select-sm d-inline-block w-auto list-status-select" data-id="<?= (int)$task['id'] ?>">
                                            <?php foreach ($validStatuses as $s): ?>
                                                <option value="<?= $s ?>"<?= $task['status'] === $s ? ' selected' : '' ?>>
                                                    <?= htmlspecialchars($statusMeta[$s]['label']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-sm btn-outline-danger ms-1 task-delete-list" data-id="<?= (int)$task['id'] ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addTaskModal" tabindex="-1" aria-labelledby="addTaskModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="addTaskForm" data-dirty-track>
                <div class="modal-header">
                    <h5 class="modal-title" id="addTaskModalLabel">Add Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="addTaskFormAlert" class="alert alert-danger d-none py-2 small" role="alert"></div>
                    <div class="mb-3">
                        <label for="taskTitle" class="form-label">Task name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="taskTitle" name="title" maxlength="200" required autocomplete="off">
                        <div class="invalid-feedback" id="taskTitleError">Task name is required.</div>
                    </div>
                    <div class="mb-3">
                        <label for="taskDescription" class="form-label">Description</label>
                        <textarea class="form-control" id="taskDescription" name="description" rows="3"></textarea>
                        <div class="invalid-feedback" id="taskDescriptionError"></div>
                    </div>
                    <div class="mb-3">
                        <label for="taskDueDate" class="form-label">Due date</label>
                        <input type="date" class="form-control" id="taskDueDate" name="due_date">
                        <div class="invalid-feedback" id="taskDueDateError">Enter a valid due date.</div>
                    </div>
                    <div class="mb-0">
                        <label for="taskStatus" class="form-label">Status</label>
                        <select class="form-select" id="taskStatus" name="status">
                            <option value="">Auto (from due date)</option>
                            <?php foreach ($validStatuses as $s): ?>
                                <option value="<?= $s ?>"><?= htmlspecialchars($statusMeta[$s]['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback" id="taskStatusError">Choose a valid status.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Task</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script type="application/json" id="tasks-status-meta"><?= json_encode($statusMeta) ?></script>
<script type="application/json" id="tasks-data"><?= json_encode($allTasks) ?></script>
<script type="text/plain" id="init-tasks-script">
(function() {
    const page = 'tasks';
    const statusMeta = JSON.parse(document.getElementById('tasks-status-meta').textContent);
    const tasksData = JSON.parse(document.getElementById('tasks-data').textContent);
    const validStatuses = Object.keys(statusMeta);
    const kanbanView = document.getElementById('kanbanView');
    const listView = document.getElementById('listView');
    const calendarView = document.getElementById('calendarView');
    const viewKanbanBtn = document.getElementById('viewKanbanBtn');
    const viewListBtn = document.getElementById('viewListBtn');
    const viewCalendarBtn = document.getElementById('viewCalendarBtn');
    const calGridBody = document.getElementById('calGridBody');
    const calMonthLabel = document.getElementById('calMonthLabel');
    const calDayLabel = document.getElementById('calDayLabel');
    const calDayTasks = document.getElementById('calDayTasks');
    const addTaskModalEl = document.getElementById('addTaskModal');
    const addTaskModal = new bootstrap.Modal(addTaskModalEl);
    const addTaskForm = document.getElementById('addTaskForm');
    const todayStr = new Date().toISOString().slice(0, 10);
    const todayParts = todayStr.split('-').map(Number);
    let calYear = todayParts[0];
    let calMonth = todayParts[1] - 1;
    let selectedDate = null;
    let draggedCard = null;

    const tasksByDate = {};
    tasksData.forEach(task => {
        if (!task.due_date) return;
        if (!tasksByDate[task.due_date]) tasksByDate[task.due_date] = [];
        tasksByDate[task.due_date].push(task);
    });

    function pad2(n) { return String(n).padStart(2, '0'); }
    function dateKey(y, m, d) { return `${y}-${pad2(m + 1)}-${pad2(d)}`; }
    function formatDisplayDate(dateStr) {
        const d = new Date(dateStr + 'T12:00:00');
        return d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setActiveView(view) {
        kanbanView.classList.toggle('d-none', view !== 'kanban');
        listView.classList.toggle('d-none', view !== 'list');
        calendarView.classList.toggle('d-none', view !== 'calendar');
        viewKanbanBtn.classList.toggle('active', view === 'kanban');
        viewListBtn.classList.toggle('active', view === 'list');
        viewCalendarBtn.classList.toggle('active', view === 'calendar');
        if (view === 'calendar') renderCalendar();
    }

    function renderDayTasks(dateStr) {
        selectedDate = dateStr;
        calDayLabel.textContent = formatDisplayDate(dateStr);
        const tasks = tasksByDate[dateStr] || [];
        if (tasks.length === 0) {
            calDayTasks.innerHTML = '<p class="text-muted mb-0">No tasks due on this date.</p>';
            return;
        }
        calDayTasks.innerHTML = tasks.map(task => {
            const meta = statusMeta[task.status] || { label: task.status, badge: 'secondary' };
            const desc = task.description
                ? `<p class="text-muted small mb-2">${escapeHtml(task.description)}</p>`
                : '';
            return `
                <div class="border rounded p-2 mb-2 cal-day-task" data-id="${task.id}">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold">${escapeHtml(task.title)}</div>
                            ${desc}
                            <span class="badge bg-${meta.badge}">${escapeHtml(meta.label)}</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger task-delete-cal" data-id="${task.id}" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>`;
        }).join('');
        calDayTasks.querySelectorAll('.task-delete-cal').forEach(btn => {
            btn.addEventListener('click', () => {
                if (!confirm('Delete this task?')) return;
                postAction({ action: 'delete', id: btn.dataset.id })
                    .then(res => {
                        if (res.error) {
                            showToast(res.error, 'danger');
                            return;
                        }
                        reload('Task deleted.');
                    });
            });
        });
    }

    function renderCalendar() {
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'];
        calMonthLabel.textContent = `${monthNames[calMonth]} ${calYear}`;

        const firstDow = new Date(calYear, calMonth, 1).getDay();
        const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
        const daysInPrev = new Date(calYear, calMonth, 0).getDate();

        let html = '<tr>';
        let dayCount = 0;

        for (let i = 0; i < firstDow; i++) {
            const day = daysInPrev - firstDow + i + 1;
            const prevMonth = calMonth === 0 ? 11 : calMonth - 1;
            const prevYear = calMonth === 0 ? calYear - 1 : calYear;
            const key = dateKey(prevYear, prevMonth, day);
            html += buildDayCell(day, key, true);
            dayCount++;
        }

        for (let day = 1; day <= daysInMonth; day++) {
            const key = dateKey(calYear, calMonth, day);
            html += buildDayCell(day, key, false);
            dayCount++;
            if (dayCount % 7 === 0 && day < daysInMonth) html += '</tr><tr>';
        }

        if (dayCount % 7 !== 0) {
            const nextMonth = calMonth === 11 ? 0 : calMonth + 1;
            const nextYear = calMonth === 11 ? calYear + 1 : calYear;
            let nextDay = 1;
            while (dayCount % 7 !== 0) {
                const key = dateKey(nextYear, nextMonth, nextDay);
                html += buildDayCell(nextDay, key, true);
                nextDay++;
                dayCount++;
            }
        }
        html += '</tr>';
        calGridBody.innerHTML = html;

        calGridBody.querySelectorAll('.calendar-day').forEach(cell => {
            cell.addEventListener('click', () => {
                calGridBody.querySelectorAll('.calendar-day.selected').forEach(c => c.classList.remove('selected'));
                cell.classList.add('selected');
                renderDayTasks(cell.dataset.date);
            });
        });

        if (selectedDate) {
            const selectedCell = calGridBody.querySelector(`.calendar-day[data-date="${selectedDate}"]`);
            if (selectedCell) selectedCell.classList.add('selected');
        }
    }

    function buildDayCell(day, dateStr, otherMonth) {
        const tasks = tasksByDate[dateStr] || [];
        const isToday = dateStr === todayStr;
        const isSelected = dateStr === selectedDate;
        const classes = ['calendar-day'];
        if (otherMonth) classes.push('other-month');
        if (isToday) classes.push('today');
        if (isSelected) classes.push('selected');

        const maxShow = 3;
        const pills = tasks.slice(0, maxShow).map(task => {
            const statusClass = `status-${task.status}`;
            return `<span class="calendar-task-pill ${statusClass}" title="${escapeHtml(task.title)}">${escapeHtml(task.title)}</span>`;
        }).join('');
        const more = tasks.length > maxShow
            ? `<div class="calendar-more">+${tasks.length - maxShow} more</div>`
            : '';

        return `<td class="${classes.join(' ')}" data-date="${dateStr}">
            <div class="calendar-day-num">${day}</div>
            ${pills}${more}
        </td>`;
    }

    function reload(successMessage) {
        fetch(`pages/${page}.php`)
            .then(r => r.text())
            .then(h => {
                if (successMessage) showToast(successMessage, 'success');
                applyMainContent(h);
            })
            .catch(() => showToast('Failed to refresh tasks.', 'danger'));
    }

    function updateColumnEmptyState(zone) {
        const cards = zone.querySelectorAll('.task-card');
        let empty = zone.querySelector('.kanban-empty');
        if (cards.length === 0) {
            if (!empty) {
                empty = document.createElement('div');
                empty.className = 'kanban-empty';
                empty.textContent = 'Drop tasks here';
                zone.appendChild(empty);
            }
        } else if (empty) {
            empty.remove();
        }
    }

    function updateCounts() {
        validStatuses.forEach(status => {
            const countEl = document.getElementById(`count-${status}`);
            if (!countEl) return;
            const zone = document.querySelector(`[data-drop-zone="${status}"]`);
            countEl.textContent = zone ? zone.querySelectorAll('.task-card').length : 0;
        });
    }

    const fieldMap = {
        title: document.getElementById('taskTitle'),
        description: document.getElementById('taskDescription'),
        due_date: document.getElementById('taskDueDate'),
        status: document.getElementById('taskStatus')
    };
    const fieldErrorMap = {
        title: document.getElementById('taskTitleError'),
        description: document.getElementById('taskDescriptionError'),
        due_date: document.getElementById('taskDueDateError'),
        status: document.getElementById('taskStatusError')
    };
    const formAlert = document.getElementById('addTaskFormAlert');
    const saveTaskBtn = addTaskForm.querySelector('button[type="submit"]');

    function clearFieldErrors() {
        Object.keys(fieldMap).forEach(key => {
            const el = fieldMap[key];
            if (el) el.classList.remove('is-invalid');
            const err = fieldErrorMap[key];
            if (err && err.dataset.defaultText === undefined) {
                err.dataset.defaultText = err.textContent || '';
            }
        });
        if (formAlert) {
            formAlert.classList.add('d-none');
            formAlert.textContent = '';
        }
    }

    function setFieldErrors(fields) {
        clearFieldErrors();
        if (!fields || typeof fields !== 'object') return;
        let firstInvalid = null;
        Object.keys(fields).forEach(key => {
            const el = fieldMap[key];
            const err = fieldErrorMap[key];
            const message = fields[key];
            if (el) {
                el.classList.add('is-invalid');
                if (!firstInvalid) firstInvalid = el;
            }
            if (err && message) {
                err.textContent = message;
            }
        });
        if (firstInvalid) firstInvalid.focus();
    }

    function clientValidateTaskForm() {
        const fields = {};
        const title = (fieldMap.title?.value || '').trim();
        const due = (fieldMap.due_date?.value || '').trim();
        if (!title) {
            fields.title = 'Task name is required.';
        } else if (title.length > 200) {
            fields.title = 'Task name must be 200 characters or fewer.';
        }
        if (due) {
            // HTML date inputs usually enforce format; still guard empty-invalid edge cases
            const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(due);
            if (!m) {
                fields.due_date = 'Enter a valid due date.';
            } else {
                const y = Number(m[1]), mo = Number(m[2]), d = Number(m[3]);
                const dt = new Date(y, mo - 1, d);
                if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) {
                    fields.due_date = 'Enter a valid due date.';
                }
            }
        }
        if (Object.keys(fields).length) {
            setFieldErrors(fields);
            return false;
        }
        clearFieldErrors();
        return true;
    }

    function postAction(data) {
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => {
            // Never send null/undefined as the strings "null"/"undefined"
            if (v === null || v === undefined) {
                fd.append(k, '');
            } else {
                fd.append(k, v);
            }
        });
        return fetch(`pages/${page}.php`, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(async (r) => {
            const text = await r.text();
            let res;
            try {
                res = text ? JSON.parse(text) : {};
            } catch (parseErr) {
                const err = new Error(
                    r.status >= 500
                        ? 'The server returned an unexpected response while saving. Please try again.'
                        : 'Could not read the server response. Please refresh and try again.'
                );
                err.system = true;
                err.status = r.status;
                throw err;
            }
            if (!r.ok && !res.error) {
                res.error = r.status >= 500
                    ? 'A server error occurred while saving the task. Please try again.'
                    : 'The request could not be completed. Please try again.';
                res.system = true;
            }
            res._httpStatus = r.status;
            return res;
        });
    }

    viewKanbanBtn.addEventListener('click', () => setActiveView('kanban'));
    viewListBtn.addEventListener('click', () => setActiveView('list'));
    viewCalendarBtn.addEventListener('click', () => setActiveView('calendar'));

    document.getElementById('calPrevBtn').addEventListener('click', () => {
        calMonth--;
        if (calMonth < 0) { calMonth = 11; calYear--; }
        renderCalendar();
    });
    document.getElementById('calNextBtn').addEventListener('click', () => {
        calMonth++;
        if (calMonth > 11) { calMonth = 0; calYear++; }
        renderCalendar();
    });

    document.getElementById('addTaskBtn').addEventListener('click', () => {
        addTaskForm.reset();
        clearFieldErrors();
        if (saveTaskBtn) saveTaskBtn.disabled = false;
        if (typeof window.TemperDirtyForms !== 'undefined') {
            window.TemperDirtyForms.markClean(addTaskForm);
        }
        addTaskModal.show();
        setTimeout(() => fieldMap.title && fieldMap.title.focus(), 200);
    });

    Object.keys(fieldMap).forEach(key => {
        const el = fieldMap[key];
        if (!el) return;
        const clear = () => {
            el.classList.remove('is-invalid');
            if (formAlert) {
                formAlert.classList.add('d-none');
                formAlert.textContent = '';
            }
        };
        el.addEventListener('input', clear);
        el.addEventListener('change', clear);
    });

    addTaskForm.addEventListener('submit', e => {
        e.preventDefault();
        if (!clientValidateTaskForm()) {
            return;
        }
        if (saveTaskBtn) saveTaskBtn.disabled = true;

        // Build payload explicitly so empty due_date is a clean empty string
        const payload = {
            action: 'create',
            title: (fieldMap.title?.value || '').trim(),
            description: (fieldMap.description?.value || '').trim(),
            due_date: (fieldMap.due_date?.value || '').trim(),
            status: fieldMap.status?.value || ''
        };

        postAction(payload)
            .then(res => {
                if (res.fields) {
                    setFieldErrors(res.fields);
                    if (formAlert && res.error) {
                        formAlert.textContent = res.error;
                        formAlert.classList.remove('d-none');
                    }
                    return;
                }
                if (res.error) {
                    if (res.system || res._httpStatus >= 500) {
                        showToast(res.error, 'danger', 7000);
                    } else {
                        // Non-field validation message — keep visible in the form
                        if (formAlert) {
                            formAlert.textContent = res.error;
                            formAlert.classList.remove('d-none');
                        }
                        showToast(res.error, 'warning', 5000);
                    }
                    return;
                }
                if (!res.success) {
                    showToast('Could not save the task. The server did not confirm success. Please try again.', 'danger', 7000);
                    return;
                }
                if (typeof window.TemperDirtyForms !== 'undefined') {
                    window.TemperDirtyForms.markClean(addTaskForm);
                }
                addTaskModal.hide();
                reload('Task created successfully.');
            })
            .catch(err => {
                const msg = (err && err.message)
                    ? err.message
                    : 'Could not save the task due to a network or system error. Check your connection and try again.';
                showToast(msg, 'danger', 7000);
            })
            .finally(() => {
                if (saveTaskBtn) saveTaskBtn.disabled = false;
            });
    });

    document.querySelectorAll('.task-delete').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const card = btn.closest('.task-card');
            if (!card || !confirm('Delete this task?')) return;
            postAction({ action: 'delete', id: card.dataset.id })
                .then(res => {
                    if (res.error) {
                        showToast(res.error, 'danger');
                        return;
                    }
                    reload('Task deleted.');
                });
        });
    });

    document.querySelectorAll('.task-delete-list').forEach(btn => {
        btn.addEventListener('click', () => {
            if (!confirm('Delete this task?')) return;
            postAction({ action: 'delete', id: btn.dataset.id })
                .then(res => {
                    if (res.error) {
                        showToast(res.error, 'danger');
                        return;
                    }
                    reload('Task deleted.');
                });
        });
    });

    document.querySelectorAll('.list-status-select').forEach(sel => {
        sel.addEventListener('change', () => {
            const id = sel.dataset.id;
            const status = sel.value;
            postAction({ action: 'update_status', id, status })
                .then(res => {
                    if (res.error) {
                        showToast(res.error, 'danger');
                        reload();
                        return;
                    }
                    reload('Task status updated.');
                });
        });
    });

    document.querySelectorAll('.task-card').forEach(card => {
        card.addEventListener('dragstart', e => {
            draggedCard = card;
            card.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.dataset.id);
        });
        card.addEventListener('dragend', () => {
            card.classList.remove('dragging');
            draggedCard = null;
            document.querySelectorAll('.kanban-column-body.drag-over').forEach(z => z.classList.remove('drag-over'));
        });
    });

    document.querySelectorAll('.kanban-column-body').forEach(zone => {
        zone.addEventListener('dragover', e => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            zone.classList.add('drag-over');
        });
        zone.addEventListener('dragleave', e => {
            if (!zone.contains(e.relatedTarget)) {
                zone.classList.remove('drag-over');
            }
        });
        zone.addEventListener('drop', e => {
            e.preventDefault();
            zone.classList.remove('drag-over');
            if (!draggedCard) return;

            const newStatus = zone.dataset.dropZone;
            const taskId = draggedCard.dataset.id;
            const oldZone = draggedCard.closest('.kanban-column-body');

            if (draggedCard.dataset.status === newStatus) {
                return;
            }

            zone.appendChild(draggedCard);
            draggedCard.dataset.status = newStatus;
            updateColumnEmptyState(zone);
            if (oldZone) updateColumnEmptyState(oldZone);
            updateCounts();

            const badge = draggedCard.querySelector('.badge');
            if (badge && statusMeta[newStatus]) {
                badge.className = `badge bg-${statusMeta[newStatus].badge}`;
                badge.textContent = statusMeta[newStatus].label;
            }

            postAction({ action: 'update_status', id: taskId, status: newStatus })
                .then(res => {
                    if (res.error) {
                        showToast(res.error, 'danger');
                        reload();
                        return;
                    }
                    showToast('Task status updated.', 'success');
                })
                .catch(() => {
                    showToast('Could not update task status.', 'danger');
                    reload();
                });
        });
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-tasks-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>
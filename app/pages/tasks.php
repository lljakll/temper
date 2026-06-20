<?php
    // Tasks / Reminders - Inner content only for AJAX loading

    if (!isset($db)) {
        require_once __DIR__ . '/../config.php';
        $db = getDbConnection();
    }

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

    function tasksComputeDueStatus(?string $dueDate, string $today): string {
        if (!$dueDate) {
            return 'upcoming';
        }
        if ($dueDate < $today) {
            return 'overdue';
        }
        $days = (int)floor((strtotime($dueDate) - strtotime($today)) / 86400);
        return $days <= 7 ? 'due_soon' : 'upcoming';
    }

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

    if (isset($_GET['api']) && $_GET['api'] === 'list') {
        header('Content-Type: application/json');
        $tasks = [];
        $res = $db->query("SELECT id, title, description, due_date, status, created_at FROM tasks ORDER BY due_date IS NULL, due_date ASC, id DESC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $tasks[] = tasksFormatRow($row);
            }
            $res->close();
        }
        echo json_encode(['tasks' => $tasks]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json');
        $action = $_POST['action'];

        if ($action === 'create') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $dueDate = trim($_POST['due_date'] ?? '') ?: null;
            $status = $_POST['status'] ?? '';

            if ($title === '') {
                echo json_encode(['error' => 'Title is required.']);
                exit;
            }
            if ($dueDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                echo json_encode(['error' => 'Invalid due date.']);
                exit;
            }
            if (!in_array($status, $validStatuses, true)) {
                $status = tasksComputeDueStatus($dueDate, $today);
            }
            if (in_array($status, ['upcoming', 'due_soon', 'overdue'], true) && $dueDate) {
                $status = tasksComputeDueStatus($dueDate, $today);
            }

            $stmt = $db->prepare("INSERT INTO tasks (title, description, due_date, status) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $title, $description, $dueDate, $status);
            if (!$stmt->execute()) {
                echo json_encode(['error' => 'Could not create task.']);
                $stmt->close();
                exit;
            }
            $id = (int)$stmt->insert_id;
            $stmt->close();

            $get = $db->prepare("SELECT id, title, description, due_date, status, created_at FROM tasks WHERE id = ?");
            $get->bind_param('i', $id);
            $get->execute();
            $task = tasksFormatRow($get->get_result()->fetch_assoc());
            $get->close();
            echo json_encode(['success' => true, 'task' => $task]);
            exit;
        }

        if ($action === 'update_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if ($id <= 0 || !in_array($status, $validStatuses, true)) {
                echo json_encode(['error' => 'Invalid task or status.']);
                exit;
            }
            $stmt = $db->prepare("UPDATE tasks SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $status, $id);
            if (!$stmt->execute() || $stmt->affected_rows < 1) {
                echo json_encode(['error' => 'Task not found or status unchanged.']);
                $stmt->close();
                exit;
            }
            $stmt->close();
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['error' => 'Invalid task.']);
                exit;
            }
            $stmt = $db->prepare("DELETE FROM tasks WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $deleted = $stmt->affected_rows > 0;
            $stmt->close();
            echo json_encode($deleted ? ['success' => true] : ['error' => 'Task not found.']);
            exit;
        }

        echo json_encode(['error' => 'Unknown action.']);
        exit;
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
        background: #f8f9fa;
        border: 1px dashed #dee2e6;
        border-radius: 0.375rem;
        padding: 0.5rem;
        transition: background-color 0.15s, border-color 0.15s;
    }
    .kanban-column-body.drag-over {
        background: #e7f1ff;
        border-color: #0d6efd;
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
        color: #adb5bd;
        font-size: 0.85rem;
        text-align: center;
        padding: 1.5rem 0.5rem;
        pointer-events: none;
    }
    .calendar-table th {
        text-align: center;
        font-size: 0.8rem;
        text-transform: uppercase;
        color: #6c757d;
        background: #f8f9fa;
    }
    .calendar-day {
        vertical-align: top;
        height: 110px;
        width: 14.28%;
        cursor: pointer;
        transition: background-color 0.15s;
    }
    .calendar-day:hover {
        background-color: #f8f9fa;
    }
    .calendar-day.other-month {
        background-color: #fafafa;
        color: #adb5bd;
    }
    .calendar-day.today .calendar-day-num {
        background: #0d6efd;
        color: #fff;
    }
    .calendar-day.selected {
        background-color: #e7f1ff;
        box-shadow: inset 0 0 0 2px #0d6efd;
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
        background: #e9ecef;
        color: #212529;
    }
    .calendar-task-pill.status-overdue { background: #f8d7da; color: #842029; }
    .calendar-task-pill.status-due_soon { background: #fff3cd; color: #664d03; }
    .calendar-task-pill.status-in_progress { background: #cff4fc; color: #055160; }
    .calendar-task-pill.status-done { background: #d1e7dd; color: #0f5132; }
    .calendar-more {
        font-size: 0.7rem;
        color: #6c757d;
    }
</style>

<div class="row mb-3 align-items-center">
    <div class="col-md-8">
        <h2 class="mb-0">Tasks / Reminders</h2>
        <p class="text-muted mb-0">Manual tasks and recurring-action reminders</p>
    </div>
    <div class="col-md-4 d-flex justify-content-md-end align-items-center gap-2 mt-2 mt-md-0">
        <div class="btn-group" role="group" aria-label="View toggle">
            <button type="button" class="btn btn-outline-primary active" id="viewKanbanBtn">
                <i class="bi bi-kanban"></i> Kanban
            </button>
            <button type="button" class="btn btn-outline-primary" id="viewListBtn">
                <i class="bi bi-list-ul"></i> List
            </button>
            <button type="button" class="btn btn-outline-primary" id="viewCalendarBtn">
                <i class="bi bi-calendar3"></i> Calendar
            </button>
        </div>
        <button type="button" class="btn btn-primary" id="addTaskBtn">
            <i class="bi bi-plus-lg"></i> Add Task
        </button>
    </div>
</div>

<div id="tasksAlert" class="alert d-none" role="alert"></div>

<div id="kanbanView">
    <div class="kanban-board">
        <?php foreach ($validStatuses as $status): ?>
            <?php $meta = $statusMeta[$status]; $items = $tasksByStatus[$status]; ?>
            <div class="kanban-column" data-status="<?= htmlspecialchars($status) ?>">
                <div class="card h-100 shadow-sm">
                    <div class="card-header <?= $meta['header'] ?> d-flex justify-content-between align-items-center py-2">
                        <span class="fw-semibold"><?= htmlspecialchars($meta['label']) ?></span>
                        <span class="badge rounded-pill bg-light text-dark" id="count-<?= $status ?>"><?= count($items) ?></span>
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
            <form id="addTaskForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="addTaskModalLabel">Add Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="addTaskError" class="alert alert-danger d-none"></div>
                    <div class="mb-3">
                        <label for="taskTitle" class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="taskTitle" name="title" maxlength="200" required>
                    </div>
                    <div class="mb-3">
                        <label for="taskDescription" class="form-label">Description</label>
                        <textarea class="form-control" id="taskDescription" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="taskDueDate" class="form-label">Due Date</label>
                        <input type="date" class="form-control" id="taskDueDate" name="due_date">
                    </div>
                    <div class="mb-0">
                        <label for="taskStatus" class="form-label">Status</label>
                        <select class="form-select" id="taskStatus" name="status">
                            <option value="">Auto (from due date)</option>
                            <?php foreach ($validStatuses as $s): ?>
                                <option value="<?= $s ?>"><?= htmlspecialchars($statusMeta[$s]['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
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
    const tasksAlert = document.getElementById('tasksAlert');
    const addTaskModalEl = document.getElementById('addTaskModal');
    const addTaskModal = new bootstrap.Modal(addTaskModalEl);
    const addTaskForm = document.getElementById('addTaskForm');
    const addTaskError = document.getElementById('addTaskError');
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
                            showAlert(res.error, 'danger');
                            return;
                        }
                        reload();
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

    function reload() {
        fetch(`pages/${page}.php`).then(r => r.text()).then(h => {
            document.getElementById('main-content').innerHTML = h;
        });
    }

    function showAlert(message, type) {
        tasksAlert.textContent = message;
        tasksAlert.className = `alert alert-${type}`;
        tasksAlert.classList.remove('d-none');
        setTimeout(() => tasksAlert.classList.add('d-none'), 4000);
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

    function postAction(data) {
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        return fetch(`pages/${page}.php`, { method: 'POST', body: fd }).then(r => r.json());
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
        addTaskError.classList.add('d-none');
        addTaskModal.show();
    });

    addTaskForm.addEventListener('submit', e => {
        e.preventDefault();
        addTaskError.classList.add('d-none');
        const fd = new FormData(addTaskForm);
        fd.append('action', 'create');
        postAction(Object.fromEntries(fd.entries()))
            .then(res => {
                if (res.error) {
                    addTaskError.textContent = res.error;
                    addTaskError.classList.remove('d-none');
                    return;
                }
                addTaskModal.hide();
                reload();
            })
            .catch(() => {
                addTaskError.textContent = 'Could not save task. Please try again.';
                addTaskError.classList.remove('d-none');
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
                        showAlert(res.error, 'danger');
                        return;
                    }
                    reload();
                });
        });
    });

    document.querySelectorAll('.task-delete-list').forEach(btn => {
        btn.addEventListener('click', () => {
            if (!confirm('Delete this task?')) return;
            postAction({ action: 'delete', id: btn.dataset.id })
                .then(res => {
                    if (res.error) {
                        showAlert(res.error, 'danger');
                        return;
                    }
                    reload();
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
                        showAlert(res.error, 'danger');
                        reload();
                        return;
                    }
                    reload();
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
                        showAlert(res.error, 'danger');
                        reload();
                    }
                })
                .catch(() => {
                    showAlert('Could not update task status.', 'danger');
                    reload();
                });
        });
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-tasks-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>
<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/storage_paths.php';

function workflowEnsureTables(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS workflow_instances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workflow_type VARCHAR(50) NOT NULL,
        title VARCHAR(200) NOT NULL,
        status VARCHAR(80) NOT NULL,
        current_step VARCHAR(80) NOT NULL,
        created_by_user_id INT NOT NULL,
        payload JSON NOT NULL,
        transaction_detail_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_workflow_type (workflow_type),
        INDEX idx_workflow_status (status),
        INDEX idx_workflow_created_by (created_by_user_id)
    )");

    $db->query("CREATE TABLE IF NOT EXISTS workflow_steps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workflow_instance_id INT NOT NULL,
        step_key VARCHAR(80) NOT NULL,
        step_order INT NOT NULL DEFAULT 0,
        status ENUM('pending','completed','rejected') NOT NULL DEFAULT 'pending',
        required_role VARCHAR(50) NULL,
        completed_by_user_id INT NULL,
        completed_at DATETIME NULL,
        signature_username VARCHAR(50) NULL,
        notes TEXT NULL,
        payload JSON NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (workflow_instance_id) REFERENCES workflow_instances(id) ON DELETE CASCADE,
        INDEX idx_workflow_steps_instance (workflow_instance_id),
        INDEX idx_workflow_steps_key (step_key)
    )");

    $db->query("CREATE TABLE IF NOT EXISTS workflow_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workflow_instance_id INT NOT NULL,
        workflow_step_id INT NULL,
        stored_filename VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        mime_type VARCHAR(120) NULL,
        file_size INT NOT NULL DEFAULT 0,
        uploaded_by_user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (workflow_instance_id) REFERENCES workflow_instances(id) ON DELETE CASCADE,
        INDEX idx_workflow_documents_instance (workflow_instance_id)
    )");

    $db->query("CREATE TABLE IF NOT EXISTS workflow_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workflow_instance_id INT NOT NULL,
        workflow_step_id INT NULL,
        event_type VARCHAR(80) NOT NULL,
        user_id INT NULL,
        username VARCHAR(50) NOT NULL,
        summary VARCHAR(255) NOT NULL,
        details JSON NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (workflow_instance_id) REFERENCES workflow_instances(id) ON DELETE CASCADE,
        INDEX idx_workflow_events_instance (workflow_instance_id),
        INDEX idx_workflow_events_type (event_type)
    )");
}

function workflowLogEvent(
    mysqli $db,
    int $instanceId,
    ?int $stepId,
    string $eventType,
    ?int $userId,
    string $username,
    string $summary,
    array $details = []
): void {
    workflowEnsureTables($db);
    $json = $details ? json_encode($details) : null;
    $stmt = $db->prepare(
        'INSERT INTO workflow_events (workflow_instance_id, workflow_step_id, event_type, user_id, username, summary, details)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iisisss', $instanceId, $stepId, $eventType, $userId, $username, $summary, $json);
    $stmt->execute();
    $stmt->close();

    logAuditAction(
        $db,
        $userId,
        $username,
        'workflow.' . $eventType,
        'instance=' . $instanceId . ' ' . $summary . ($details ? ' ' . json_encode($details) : '')
    );
}

function workflowCreateInstance(
    mysqli $db,
    string $type,
    string $title,
    string $status,
    string $currentStep,
    int $createdByUserId,
    array $payload,
    array $stepDefs,
    array $actor
): int {
    workflowEnsureTables($db);
    $payloadJson = json_encode($payload);
    $stmt = $db->prepare(
        'INSERT INTO workflow_instances (workflow_type, title, status, current_step, created_by_user_id, payload)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssssis', $type, $title, $status, $currentStep, $createdByUserId, $payloadJson);
    $stmt->execute();
    $instanceId = (int)$stmt->insert_id;
    $stmt->close();

    $ins = $db->prepare(
        'INSERT INTO workflow_steps (workflow_instance_id, step_key, step_order, status, required_role)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($stepDefs as $def) {
        $key = $def['key'];
        $order = (int)$def['order'];
        $stepStatus = $def['status'] ?? 'pending';
        $role = $def['role'] ?? null;
        $ins->bind_param('isiss', $instanceId, $key, $order, $stepStatus, $role);
        $ins->execute();
    }
    $ins->close();

    workflowLogEvent(
        $db,
        $instanceId,
        null,
        'created',
        (int)$actor['id'],
        $actor['username'] ?? 'system',
        'Workflow created: ' . $title,
        ['workflow_type' => $type, 'status' => $status]
    );

    return $instanceId;
}

function workflowFetchInstance(mysqli $db, int $instanceId): ?array {
    workflowEnsureTables($db);
    $stmt = $db->prepare('SELECT * FROM workflow_instances WHERE id = ?');
    $stmt->bind_param('i', $instanceId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $row['payload'] = json_decode($row['payload'] ?? '{}', true) ?: [];
    $row['steps'] = workflowFetchSteps($db, $instanceId);
    $row['documents'] = workflowFetchDocuments($db, $instanceId);
    $row['events'] = workflowFetchEvents($db, $instanceId, 20);
    return $row;
}

function workflowFetchSteps(mysqli $db, int $instanceId): array {
    $steps = [];
    $stmt = $db->prepare(
        'SELECT * FROM workflow_steps WHERE workflow_instance_id = ? ORDER BY step_order ASC, id ASC'
    );
    $stmt->bind_param('i', $instanceId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['payload'] = $row['payload'] ? (json_decode($row['payload'], true) ?: []) : [];
        $steps[] = $row;
    }
    $stmt->close();
    return $steps;
}

function workflowFetchDocuments(mysqli $db, int $instanceId): array {
    $docs = [];
    $stmt = $db->prepare(
        'SELECT id, workflow_step_id, stored_filename, original_filename, mime_type, file_size, uploaded_by_user_id, created_at
         FROM workflow_documents WHERE workflow_instance_id = ? ORDER BY created_at DESC'
    );
    $stmt->bind_param('i', $instanceId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $docs[] = $row;
    }
    $stmt->close();
    return $docs;
}

function workflowFetchEvents(mysqli $db, int $instanceId, int $limit = 50): array {
    $events = [];
    $stmt = $db->prepare(
        'SELECT id, workflow_step_id, event_type, user_id, username, summary, details, created_at
         FROM workflow_events WHERE workflow_instance_id = ? ORDER BY id DESC LIMIT ?'
    );
    $stmt->bind_param('ii', $instanceId, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['details'] = $row['details'] ? (json_decode($row['details'], true) ?: []) : [];
        $events[] = $row;
    }
    $stmt->close();
    return $events;
}

function workflowUpdateInstance(
    mysqli $db,
    int $instanceId,
    string $status,
    string $currentStep,
    array $payload,
    ?int $transactionDetailId = null
): void {
    $payloadJson = json_encode($payload);
    if ($transactionDetailId !== null) {
        $stmt = $db->prepare(
            'UPDATE workflow_instances SET status = ?, current_step = ?, payload = ?, transaction_detail_id = ? WHERE id = ?'
        );
        $stmt->bind_param('sssii', $status, $currentStep, $payloadJson, $transactionDetailId, $instanceId);
    } else {
        $stmt = $db->prepare(
            'UPDATE workflow_instances SET status = ?, current_step = ?, payload = ? WHERE id = ?'
        );
        $stmt->bind_param('sssi', $status, $currentStep, $payloadJson, $instanceId);
    }
    $stmt->execute();
    $stmt->close();
}

function workflowCompleteStep(
    mysqli $db,
    int $instanceId,
    string $stepKey,
    int $userId,
    string $username,
    array $stepPayload = [],
    ?string $notes = null
): void {
    $payloadJson = $stepPayload ? json_encode($stepPayload) : null;
    $stmt = $db->prepare(
        "UPDATE workflow_steps
         SET status = 'completed', completed_by_user_id = ?, completed_at = NOW(),
             signature_username = ?, notes = ?, payload = ?
         WHERE workflow_instance_id = ? AND step_key = ?"
    );
    $stmt->bind_param('isssis', $userId, $username, $notes, $payloadJson, $instanceId, $stepKey);
    $stmt->execute();
    $stmt->close();
}

function workflowGetStepId(mysqli $db, int $instanceId, string $stepKey): ?int {
    $stmt = $db->prepare('SELECT id FROM workflow_steps WHERE workflow_instance_id = ? AND step_key = ? LIMIT 1');
    $stmt->bind_param('is', $instanceId, $stepKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

function workflowStoreDocument(
    mysqli $db,
    int $instanceId,
    ?int $stepId,
    int $userId,
    string $originalName,
    string $tmpPath,
    string $mimeType
): array {
    $dir = getWorkflowDocumentsDir() . '/' . $instanceId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['success' => false, 'error' => 'Could not create workflow document directory.'];
    }

    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    $stored = 'doc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($safeExt ? '.' . $safeExt : '');
    $dest = $dir . '/' . $stored;

    if (!@move_uploaded_file($tmpPath, $dest) && !@rename($tmpPath, $dest)) {
        return ['success' => false, 'error' => 'Failed to save uploaded document.'];
    }

    $size = (int)filesize($dest);
    $stmt = $db->prepare(
        'INSERT INTO workflow_documents (workflow_instance_id, workflow_step_id, stored_filename, original_filename, mime_type, file_size, uploaded_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iisssii', $instanceId, $stepId, $stored, $originalName, $mimeType, $size, $userId);
    $stmt->execute();
    $docId = (int)$stmt->insert_id;
    $stmt->close();

    return ['success' => true, 'id' => $docId, 'stored_filename' => $stored];
}

function workflowListInstances(mysqli $db, ?string $type = null, int $limit = 100): array {
    workflowEnsureTables($db);
    $rows = [];
    if ($type) {
        $stmt = $db->prepare(
            'SELECT id, workflow_type, title, status, current_step, created_by_user_id, transaction_detail_id, created_at, updated_at
             FROM workflow_instances WHERE workflow_type = ? ORDER BY updated_at DESC LIMIT ?'
        );
        $stmt->bind_param('si', $type, $limit);
    } else {
        $stmt = $db->prepare(
            'SELECT id, workflow_type, title, status, current_step, created_by_user_id, transaction_detail_id, created_at, updated_at
             FROM workflow_instances ORDER BY updated_at DESC LIMIT ?'
        );
        $stmt->bind_param('i', $limit);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function workflowEligibleUsers(mysqli $db, string $capability): array {
    $users = [];
    $res = $db->query(
        'SELECT u.id, u.username, u.first_name, u.last_name, r.name AS role_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.is_active = TRUE
         ORDER BY u.first_name, u.last_name, u.username'
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (userHasWorkflowCapability($db, (int)$row['id'], $capability)) {
                $row['display_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: $row['username'];
                $users[] = $row;
            }
        }
        $res->close();
    }
    return $users;
}
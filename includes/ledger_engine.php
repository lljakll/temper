<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/storage_paths.php';

/**
 * Verify ledger tables exist. Does not create or modify schema —
 * run setup_db.php to initialize the database.
 */
function ledgerRequireTables(mysqli $db): void {
    static $verified = false;
    if ($verified) {
        return;
    }

    $required = [
        'transaction_details',
        'transaction_lines',
        'transaction_documents',
        'transaction_events',
    ];

    $missing = [];
    foreach ($required as $table) {
        $escaped = $db->real_escape_string($table);
        $result = $db->query("SHOW TABLES LIKE '{$escaped}'");
        if ($result === false || $result->num_rows === 0) {
            $missing[] = $table;
        }
    }

    if ($missing !== []) {
        throw new RuntimeException(
            'Ledger tables are not initialized (missing: ' . implode(', ', $missing) . '). '
            . 'Run: php setup_db.php or php setup_db.php --check'
        );
    }

    $verified = true;
}

function ledgerLogEvent(
    mysqli $db,
    int $transactionId,
    string $eventType,
    ?int $userId,
    string $username,
    string $summary,
    array $details = []
): void {
    ledgerRequireTables($db);
    $json = $details ? json_encode($details) : null;
    $stmt = $db->prepare(
        'INSERT INTO transaction_events (transaction_detail_id, event_type, user_id, username, summary, details)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isisss', $transactionId, $eventType, $userId, $username, $summary, $json);
    $stmt->execute();
    $stmt->close();

    logAuditAction(
        $db,
        $userId,
        $username,
        'ledger.' . $eventType,
        'transaction=' . $transactionId . ' ' . $summary . ($details ? ' ' . json_encode($details) : '')
    );
}

function ledgerCreateHeader(
    mysqli $db,
    string $transactionDate,
    string $payTo,
    string $referenceNumber,
    string $memo,
    string $source = 'manual',
    string $entryStatus = 'finalized',
    ?int $createdByUserId = null,
    ?array $transactionData = null,
    ?int $workflowInstanceId = null
): int {
    ledgerRequireTables($db);
    $dataJson = $transactionData ? json_encode($transactionData) : null;
    $stmt = $db->prepare(
        "INSERT INTO transaction_details (
            transaction_date, pay_to, reference_number, memo, status,
            source, entry_status, workflow_instance_id, created_by_user_id, transaction_data
         ) VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'ssssssiis',
        $transactionDate,
        $payTo,
        $referenceNumber,
        $memo,
        $source,
        $entryStatus,
        $workflowInstanceId,
        $createdByUserId,
        $dataJson
    );
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

function ledgerUpdateHeader(
    mysqli $db,
    int $transactionId,
    ?string $transactionDate = null,
    ?string $payTo = null,
    ?string $referenceNumber = null,
    ?string $memo = null,
    ?array $transactionData = null,
    ?int $workflowInstanceId = null
): void {
    $sets = [];
    $types = '';
    $params = [];

    if ($transactionDate !== null) {
        $sets[] = 'transaction_date = ?';
        $types .= 's';
        $params[] = $transactionDate;
    }
    if ($payTo !== null) {
        $sets[] = 'pay_to = ?';
        $types .= 's';
        $params[] = $payTo;
    }
    if ($referenceNumber !== null) {
        $sets[] = 'reference_number = ?';
        $types .= 's';
        $params[] = $referenceNumber;
    }
    if ($memo !== null) {
        $sets[] = 'memo = ?';
        $types .= 's';
        $params[] = $memo;
    }
    if ($transactionData !== null) {
        $sets[] = 'transaction_data = ?';
        $types .= 's';
        $params[] = json_encode($transactionData);
    }
    if ($workflowInstanceId !== null) {
        $sets[] = 'workflow_instance_id = ?';
        $types .= 'i';
        $params[] = $workflowInstanceId;
    }

    if ($sets === []) {
        return;
    }

    $sql = 'UPDATE transaction_details SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $types .= 'i';
    $params[] = $transactionId;

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
}

function ledgerSetValidated(
    mysqli $db,
    int $transactionId,
    int $validatedByUserId,
    ?int $actorUserId,
    string $actorUsername
): void {
    $stmt = $db->prepare(
        'UPDATE transaction_details SET validated_by_user_id = ?, validated_at = NOW(), entry_status = ? WHERE id = ?'
    );
    $finalized = 'finalized';
    $stmt->bind_param('isi', $validatedByUserId, $finalized, $transactionId);
    $stmt->execute();
    $stmt->close();

    ledgerLogEvent(
        $db,
        $transactionId,
        'validated',
        $actorUserId,
        $actorUsername,
        'Transaction validated and finalized.',
        ['validated_by_user_id' => $validatedByUserId]
    );
}

function ledgerReplaceLines(mysqli $db, int $transactionId, array $lines): void {
    ledgerRequireTables($db);
    $del = $db->prepare('DELETE FROM transaction_lines WHERE transaction_detail_id = ?');
    $del->bind_param('i', $transactionId);
    $del->execute();
    $del->close();

    if ($lines === []) {
        return;
    }

    $ins = $db->prepare(
        'INSERT INTO transaction_lines (
            transaction_detail_id, account_id, fund_id, amount, type,
            natural_category_id, functional_category_id, description
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($lines as $line) {
        $fundId = $line['fund_id'] ?? null;
        $naturalId = $line['natural_category_id'] ?? null;
        $functionalId = $line['functional_category_id'] ?? null;
        $description = $line['description'] ?? '';
        $ins->bind_param(
            'iiidsiis',
            $transactionId,
            $line['account_id'],
            $fundId,
            $line['amount'],
            $line['type'],
            $naturalId,
            $functionalId,
            $description
        );
        $ins->execute();
    }
    $ins->close();
}

function ledgerFetchTransaction(mysqli $db, int $transactionId, int $eventLimit = 50): ?array {
    ledgerRequireTables($db);
    $stmt = $db->prepare('SELECT * FROM transaction_details WHERE id = ?');
    $stmt->bind_param('i', $transactionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $row['transaction_data'] = $row['transaction_data']
        ? (json_decode($row['transaction_data'], true) ?: [])
        : [];
    $row['lines'] = ledgerFetchLines($db, $transactionId);
    $row['documents'] = ledgerFetchDocuments($db, $transactionId);
    $row['events'] = ledgerFetchEvents($db, $transactionId, $eventLimit);
    $row['validated_by'] = ledgerFetchUserDisplay($db, $row['validated_by_user_id'] ?? null);
    $row['created_by'] = ledgerFetchUserDisplay($db, $row['created_by_user_id'] ?? null);
    $row['is_editable'] = ledgerIsEditable($row);

    return $row;
}

function ledgerIsEditable(array $transaction): bool {
    if (($transaction['status'] ?? '') === 'cleared' || ($transaction['status'] ?? '') === 'reconciled') {
        return false;
    }
    if (($transaction['source'] ?? 'manual') === 'workflow' && ($transaction['entry_status'] ?? '') === 'finalized') {
        return false;
    }
    return true;
}

function ledgerFetchLines(mysqli $db, int $transactionId): array {
    $lines = [];
    $stmt = $db->prepare(
        'SELECT id, account_id, fund_id, amount, type, natural_category_id, functional_category_id, description
         FROM transaction_lines WHERE transaction_detail_id = ? ORDER BY id ASC'
    );
    $stmt->bind_param('i', $transactionId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $lines[] = $row;
    }
    $stmt->close();
    return $lines;
}

function ledgerFetchDocuments(mysqli $db, int $transactionId): array {
    $docs = [];
    $stmt = $db->prepare(
        'SELECT id, stored_filename, original_filename, mime_type, file_size,
                uploaded_by_user_id, workflow_step_key, created_at
         FROM transaction_documents WHERE transaction_detail_id = ? ORDER BY created_at DESC'
    );
    $stmt->bind_param('i', $transactionId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $docs[] = $row;
    }
    $stmt->close();
    return $docs;
}

function ledgerFetchEvents(mysqli $db, int $transactionId, int $limit = 50): array {
    $events = [];
    $stmt = $db->prepare(
        'SELECT id, event_type, user_id, username, summary, details, created_at
         FROM transaction_events WHERE transaction_detail_id = ? ORDER BY id DESC LIMIT ?'
    );
    $stmt->bind_param('ii', $transactionId, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['details'] = $row['details'] ? (json_decode($row['details'], true) ?: []) : [];
        $events[] = $row;
    }
    $stmt->close();
    return $events;
}

function ledgerFetchUserDisplay(mysqli $db, ?int $userId): ?array {
    if (!$userId) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT id, username, first_name, last_name FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $row['display_name'] = $name !== '' ? $name : $row['username'];
    return $row;
}

function ledgerStoreDocument(
    mysqli $db,
    int $transactionId,
    int $userId,
    string $originalName,
    string $tmpPath,
    string $mimeType,
    ?string $workflowStepKey = null
): array {
    $dir = getTransactionDocumentsDir() . '/' . $transactionId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['success' => false, 'error' => 'Could not create transaction document directory.'];
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
        'INSERT INTO transaction_documents (
            transaction_detail_id, stored_filename, original_filename, mime_type,
            file_size, uploaded_by_user_id, workflow_step_key
         ) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssiis', $transactionId, $stored, $originalName, $mimeType, $size, $userId, $workflowStepKey);
    $stmt->execute();
    $docId = (int)$stmt->insert_id;
    $stmt->close();

    return ['success' => true, 'id' => $docId, 'stored_filename' => $stored];
}

function ledgerResolveDocumentPath(int $transactionId, string $storedFilename): ?string {
    $safe = basename($storedFilename);
    $path = getTransactionDocumentsDir() . '/' . $transactionId . '/' . $safe;
    return is_file($path) ? $path : null;
}

function ledgerFetchDocument(mysqli $db, int $documentId): ?array {
    $stmt = $db->prepare(
        'SELECT id, transaction_detail_id, stored_filename, original_filename, mime_type, file_size
         FROM transaction_documents WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
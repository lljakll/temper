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
    $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;
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
        'transaction=' . $transactionId . ' ' . $summary . ($details ? ' ' . json_encode($details, JSON_UNESCAPED_UNICODE) : '')
    );
}

/**
 * Format money for audit messages.
 */
function ledgerFormatMoney($amount): string {
    return '$' . number_format((float)$amount, 2);
}

/**
 * Resolve account / fund display names (cached per request).
 */
function ledgerNameMaps(mysqli $db): array {
    static $maps = null;
    if ($maps !== null) {
        return $maps;
    }
    $accounts = [];
    $funds = [];
    if ($r = $db->query('SELECT id, name FROM accounts')) {
        while ($row = $r->fetch_assoc()) {
            $accounts[(int)$row['id']] = $row['name'];
        }
    }
    if ($r = $db->query('SELECT id, name FROM funds')) {
        while ($row = $r->fetch_assoc()) {
            $funds[(int)$row['id']] = $row['name'];
        }
    }
    $maps = ['accounts' => $accounts, 'funds' => $funds];
    return $maps;
}

/**
 * Build human-readable change lines when a manual transaction is updated.
 *
 * @param array $existing Row from ledgerFetchTransaction (includes lines)
 * @param array $newHeader Keys: transaction_date, pay_to, reference_number, check_number, memo
 * @param array $newLines  List of [aid, fid, am, t, nid, fid2]
 * @return array{summary:string,changes:array<int,string>,debits:float,credits:float}
 */
function ledgerDescribeTransactionUpdate(
    mysqli $db,
    array $existing,
    array $newHeader,
    array $newLines
): array {
    $names = ledgerNameMaps($db);
    $changes = [];

    $oldDate = (string)($existing['transaction_date'] ?? '');
    $newDate = (string)($newHeader['transaction_date'] ?? '');
    if ($oldDate !== $newDate) {
        $changes[] = 'Date changed from ' . ($oldDate !== '' ? $oldDate : '—') . ' to ' . ($newDate !== '' ? $newDate : '—') . '.';
    }

    $oldPay = trim((string)($existing['pay_to'] ?? ''));
    $newPay = trim((string)($newHeader['pay_to'] ?? ''));
    if ($oldPay !== $newPay) {
        $changes[] = 'Pay to changed from "' . ($oldPay !== '' ? $oldPay : '—') . '" to "' . ($newPay !== '' ? $newPay : '—') . '".';
    }

    $oldRef = trim((string)($existing['reference_number'] ?? ''));
    $newRef = trim((string)($newHeader['reference_number'] ?? ''));
    if ($oldRef !== $newRef) {
        $changes[] = 'Reference # changed from "' . ($oldRef !== '' ? $oldRef : '—') . '" to "' . ($newRef !== '' ? $newRef : '—') . '".';
    }

    $oldCheck = trim((string)($existing['check_number'] ?? ''));
    $newCheck = trim((string)($newHeader['check_number'] ?? ''));
    if ($oldCheck !== $newCheck) {
        $changes[] = 'Check # changed from "' . ($oldCheck !== '' ? $oldCheck : '—') . '" to "' . ($newCheck !== '' ? $newCheck : '—') . '".';
    }

    $oldMemo = trim((string)($existing['memo'] ?? ''));
    $newMemo = trim((string)($newHeader['memo'] ?? ''));
    if ($oldMemo !== $newMemo) {
        $changes[] = 'Memo / description updated.';
    }

    // Normalize lines for comparison: key by account|fund|type
    $lineKey = static function (int $aid, $fid, string $type): string {
        return $aid . '|' . ($fid !== null && $fid !== '' ? (int)$fid : 0) . '|' . $type;
    };
    $lineLabel = static function (int $aid, $fid, array $names): string {
        $acct = $names['accounts'][$aid] ?? ('Account #' . $aid);
        $fidInt = ($fid !== null && $fid !== '' && (int)$fid > 0) ? (int)$fid : 0;
        if ($fidInt > 0) {
            $fund = $names['funds'][$fidInt] ?? ('Fund #' . $fidInt);
            return 'Fund "' . $fund . '" (' . $acct . ')';
        }
        return 'Account "' . $acct . '"';
    };

    $oldMap = [];
    foreach ($existing['lines'] ?? [] as $ol) {
        $aid = (int)($ol['account_id'] ?? 0);
        $fid = $ol['fund_id'] ?? null;
        $type = (string)($ol['type'] ?? 'debit');
        $key = $lineKey($aid, $fid, $type);
        $amt = (float)($ol['amount'] ?? 0);
        if (!isset($oldMap[$key])) {
            $oldMap[$key] = ['aid' => $aid, 'fid' => $fid, 'type' => $type, 'amount' => 0.0];
        }
        $oldMap[$key]['amount'] += $amt;
    }

    $newMap = [];
    $debits = 0.0;
    $credits = 0.0;
    foreach ($newLines as $nl) {
        $aid = (int)($nl['aid'] ?? $nl['account_id'] ?? 0);
        $fid = $nl['fid'] ?? $nl['fund_id'] ?? null;
        $type = (string)($nl['t'] ?? $nl['type'] ?? 'debit');
        $amt = (float)($nl['am'] ?? $nl['amount'] ?? 0);
        if ($aid <= 0 || $amt <= 0) {
            continue;
        }
        $key = $lineKey($aid, $fid, $type);
        if (!isset($newMap[$key])) {
            $newMap[$key] = ['aid' => $aid, 'fid' => $fid, 'type' => $type, 'amount' => 0.0];
        }
        $newMap[$key]['amount'] += $amt;
        if ($type === 'credit') {
            $credits += $amt;
        } else {
            $debits += $amt;
        }
    }

    foreach ($newMap as $key => $nl) {
        $label = $lineLabel($nl['aid'], $nl['fid'], $names);
        $newAmt = $nl['amount'];
        if (!isset($oldMap[$key])) {
            $changes[] = 'Added ' . $label . ' ' . $nl['type'] . ' ' . ledgerFormatMoney($newAmt) . '.';
            continue;
        }
        $oldAmt = (float)$oldMap[$key]['amount'];
        if (abs($oldAmt - $newAmt) > 0.005) {
            // Prefer fund-focused wording when a fund is present (matches product examples)
            $fidInt = ($nl['fid'] !== null && $nl['fid'] !== '' && (int)$nl['fid'] > 0) ? (int)$nl['fid'] : 0;
            if ($fidInt > 0) {
                $fund = $names['funds'][$fidInt] ?? ('Fund #' . $fidInt);
                $changes[] = 'Fund "' . $fund . '" amount adjusted from '
                    . ledgerFormatMoney($oldAmt) . ' to ' . ledgerFormatMoney($newAmt) . '.';
            } else {
                $acct = $names['accounts'][$nl['aid']] ?? ('Account #' . $nl['aid']);
                $changes[] = 'Account "' . $acct . '" amount adjusted from '
                    . ledgerFormatMoney($oldAmt) . ' to ' . ledgerFormatMoney($newAmt) . '.';
            }
        }
        unset($oldMap[$key]);
    }
    foreach ($oldMap as $ol) {
        $label = $lineLabel($ol['aid'], $ol['fid'], $names);
        $changes[] = 'Removed ' . $label . ' ' . $ol['type'] . ' ' . ledgerFormatMoney($ol['amount']) . '.';
    }

    if ($changes === []) {
        $summary = 'Transaction saved with no field changes (re-saved).';
    } elseif (count($changes) === 1) {
        $summary = $changes[0];
    } else {
        $summary = 'Transaction updated (' . count($changes) . ' changes).';
    }

    return [
        'summary' => $summary,
        'changes' => $changes,
        'debits' => $debits,
        'credits' => $credits,
    ];
}

/**
 * Build a concise create summary for a new manual transaction.
 */
function ledgerDescribeTransactionCreate(
    mysqli $db,
    string $payTo,
    array $newLines
): array {
    $names = ledgerNameMaps($db);
    $debits = 0.0;
    $credits = 0.0;
    $lineCount = 0;
    $fundBits = [];
    foreach ($newLines as $nl) {
        $amt = (float)($nl['am'] ?? $nl['amount'] ?? 0);
        $type = (string)($nl['t'] ?? $nl['type'] ?? 'debit');
        if ($amt <= 0) {
            continue;
        }
        $lineCount++;
        if ($type === 'credit') {
            $credits += $amt;
        } else {
            $debits += $amt;
        }
        $fid = $nl['fid'] ?? $nl['fund_id'] ?? null;
        if ($fid !== null && $fid !== '' && (int)$fid > 0) {
            $fname = $names['funds'][(int)$fid] ?? ('Fund #' . (int)$fid);
            $fundBits[$fname] = ($fundBits[$fname] ?? 0) + $amt;
        }
    }
    $pay = trim($payTo) !== '' ? trim($payTo) : '—';
    $summary = 'Transaction created for "' . $pay . '" totaling '
        . ledgerFormatMoney(max($debits, $credits)) . ' (' . $lineCount . ' lines).';
    $changes = [$summary];
    foreach ($fundBits as $fname => $amt) {
        $changes[] = 'Fund "' . $fname . '" ' . ledgerFormatMoney($amt) . '.';
    }
    return [
        'summary' => $summary,
        'changes' => $changes,
        'debits' => $debits,
        'credits' => $credits,
    ];
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

    $validator = ledgerFetchUserDisplay($db, $validatedByUserId);
    $validatorName = $validator['display_name'] ?? ($actorUsername !== '' ? $actorUsername : 'user #' . $validatedByUserId);

    ledgerLogEvent(
        $db,
        $transactionId,
        'validated',
        $actorUserId,
        $actorUsername,
        'Transaction validated by ' . $validatorName . '.',
        [
            'validated_by_user_id' => $validatedByUserId,
            'validated_by_name' => $validatorName,
        ]
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

/** Allowed attachment extensions and MIME types (extension => list of acceptable MIME types). */
function ledgerAllowedDocumentTypes(): array {
    return [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'doc'  => ['application/msword'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip', // some browsers/OS report docx as zip
        ],
    ];
}

/** Max attachment size in bytes (2 MiB — aligns with typical upload_max_filesize). */
function ledgerMaxDocumentBytes(): int {
    return 2 * 1024 * 1024;
}

/**
 * Validate an uploaded document before storage.
 * $file is a $_FILES['…'] entry (name, type, tmp_name, error, size).
 */
function ledgerValidateUploadedDocument(array $file): array {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
        return ['success' => false, 'error' => 'Please select a file to upload.'];
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return ['success' => false, 'error' => 'File exceeds the maximum upload size of 2 MB.'];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed (error code ' . $error . ').'];
    }

    $originalName = (string)($file['name'] ?? '');
    $tmpPath = (string)$file['tmp_name'];
    if ($originalName === '' || $tmpPath === '') {
        return ['success' => false, 'error' => 'Invalid upload.'];
    }
    // HTTP uploads must pass is_uploaded_file; CLI/tests may use a plain temp file.
    $isHttpUpload = is_uploaded_file($tmpPath);
    if (!$isHttpUpload && PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
        return ['success' => false, 'error' => 'Invalid upload.'];
    }
    if (!$isHttpUpload && !is_file($tmpPath)) {
        return ['success' => false, 'error' => 'Invalid upload.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 && is_file($tmpPath)) {
        $size = (int)filesize($tmpPath);
    }
    if ($size <= 0) {
        return ['success' => false, 'error' => 'Uploaded file is empty.'];
    }
    if ($size > ledgerMaxDocumentBytes()) {
        return ['success' => false, 'error' => 'File exceeds the maximum upload size of 2 MB.'];
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ledgerAllowedDocumentTypes();
    if ($ext === '' || !isset($allowed[$ext])) {
        return [
            'success' => false,
            'error' => 'Invalid file type. Allowed: PDF, JPG, PNG, DOC, DOCX.',
        ];
    }

    $detectedMime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string)$finfo->file($tmpPath);
    } elseif (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedMime = (string)finfo_file($finfo, $tmpPath);
            if (PHP_VERSION_ID < 80500) {
                finfo_close($finfo);
            }
        }
    }
    $clientMime = strtolower(trim((string)($file['type'] ?? '')));
    $mime = $detectedMime !== '' ? strtolower($detectedMime) : $clientMime;
    $accepted = $allowed[$ext];

    // Block clearly dangerous content types even if extension was spoofed
    $dangerous = [
        'text/x-php', 'application/x-php', 'application/x-httpd-php',
        'text/html', 'application/javascript', 'text/javascript',
        'application/x-msdownload', 'application/x-executable',
        'application/x-sharedlib', 'application/x-shellscript',
    ];
    if ($mime !== '' && in_array($mime, $dangerous, true)) {
        return [
            'success' => false,
            'error' => 'File content type is not allowed.',
        ];
    }

    // Prefer a listed MIME when client/finfo matches; otherwise use first accepted for the extension
    $resolvedMime = $accepted[0];
    if ($mime !== '' && (in_array($mime, $accepted, true) || $mime === 'application/octet-stream'
        || $mime === 'image/jpg')) {
        $resolvedMime = ($mime === 'image/jpg') ? 'image/jpeg' : ($mime === 'application/octet-stream' ? $accepted[0] : $mime);
    } elseif ($clientMime !== '' && in_array($clientMime, $accepted, true)) {
        $resolvedMime = $clientMime;
    }

    return [
        'success' => true,
        'original_name' => $originalName,
        'tmp_path' => $tmpPath,
        'mime_type' => $resolvedMime,
        'extension' => $ext,
        'size' => $size,
    ];
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
    ledgerRequireTables($db);

    // Callers should prefer ledgerStoreDocumentFromUpload() for $_FILES entries.
    // This function still validates extension/size for direct/CLI use.
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ledgerAllowedDocumentTypes();
    if ($ext === '' || !isset($allowed[$ext])) {
        return [
            'success' => false,
            'error' => 'Invalid file type. Allowed: PDF, JPG, PNG, DOC, DOCX.',
        ];
    }
    $size = is_file($tmpPath) ? (int)filesize($tmpPath) : 0;
    if ($size <= 0) {
        return ['success' => false, 'error' => 'Uploaded file is empty.'];
    }
    if ($size > ledgerMaxDocumentBytes()) {
        return ['success' => false, 'error' => 'File exceeds the maximum upload size of 2 MB.'];
    }
    if (!is_file($tmpPath) || !is_readable($tmpPath)) {
        return ['success' => false, 'error' => 'Upload temporary file is missing or unreadable.'];
    }

    $dir = getTransactionDocumentsDir() . '/' . $transactionId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['success' => false, 'error' => 'Could not create transaction document directory.'];
    }

    $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    $stored = 'doc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($safeExt ? '.' . $safeExt : '');
    $dest = $dir . '/' . $stored;

    if (is_uploaded_file($tmpPath)) {
        if (!@move_uploaded_file($tmpPath, $dest)) {
            return ['success' => false, 'error' => 'Failed to save uploaded document.'];
        }
    } elseif (!@rename($tmpPath, $dest) && !@copy($tmpPath, $dest)) {
        return ['success' => false, 'error' => 'Failed to save uploaded document.'];
    }

    $size = (int)filesize($dest);
    $safeOriginal = mb_substr(basename($originalName), 0, 255);
    $safeMime = mb_substr($mimeType !== '' ? $mimeType : 'application/octet-stream', 0, 120);
    // Empty string for bind_param stability; NULLIF stores SQL NULL when empty.
    $stepKey = ($workflowStepKey !== null && $workflowStepKey !== '')
        ? mb_substr($workflowStepKey, 0, 80)
        : '';

    $stmt = $db->prepare(
        'INSERT INTO transaction_documents (
            transaction_detail_id, stored_filename, original_filename, mime_type,
            file_size, uploaded_by_user_id, workflow_step_key
         ) VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, \'\'))'
    );
    if (!$stmt) {
        @unlink($dest);
        return ['success' => false, 'error' => 'Database error preparing document record.'];
    }
    $stmt->bind_param(
        'isssiis',
        $transactionId,
        $stored,
        $safeOriginal,
        $safeMime,
        $size,
        $userId,
        $stepKey
    );
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        @unlink($dest);
        return ['success' => false, 'error' => 'Failed to link document to transaction: ' . $err];
    }
    $docId = (int)$stmt->insert_id;
    $stmt->close();

    if ($docId <= 0) {
        @unlink($dest);
        return ['success' => false, 'error' => 'Failed to link document to transaction.'];
    }

    return ['success' => true, 'id' => $docId, 'stored_filename' => $stored];
}

/**
 * Store a document from a $_FILES entry after full validation.
 */
function ledgerStoreDocumentFromUpload(
    mysqli $db,
    int $transactionId,
    int $userId,
    array $file,
    ?string $workflowStepKey = null
): array {
    $check = ledgerValidateUploadedDocument($file);
    if (empty($check['success'])) {
        return ['success' => false, 'error' => $check['error'] ?? 'Invalid upload.'];
    }
    return ledgerStoreDocument(
        $db,
        $transactionId,
        $userId,
        $check['original_name'],
        $check['tmp_path'],
        $check['mime_type'],
        $workflowStepKey
    );
}

function ledgerResolveDocumentPath(int $transactionId, string $storedFilename): ?string {
    $safe = basename($storedFilename);
    // Preferred path: storage/attachments/{txId}/
    $path = getTransactionDocumentsDir() . '/' . $transactionId . '/' . $safe;
    if (is_file($path)) {
        return $path;
    }
    // Legacy fallback: storage/transaction_documents/{txId}/
    $legacyRoot = resolveStorageRoot()['path'] . '/transaction_documents';
    $legacy = $legacyRoot . '/' . $transactionId . '/' . $safe;
    return is_file($legacy) ? $legacy : null;
}

function ledgerFetchDocument(mysqli $db, int $documentId): ?array {
    $stmt = $db->prepare(
        'SELECT id, transaction_detail_id, stored_filename, original_filename, mime_type, file_size,
                uploaded_by_user_id, workflow_step_key, created_at
         FROM transaction_documents WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Delete a document row and its file. Caller must enforce editability / auth.
 */
function ledgerDeleteDocument(mysqli $db, int $documentId): array {
    ledgerRequireTables($db);
    $doc = ledgerFetchDocument($db, $documentId);
    if (!$doc) {
        return ['success' => false, 'error' => 'Document not found.'];
    }

    $txId = (int)$doc['transaction_detail_id'];
    $path = ledgerResolveDocumentPath($txId, $doc['stored_filename']);

    $stmt = $db->prepare('DELETE FROM transaction_documents WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error preparing delete.'];
    }
    $stmt->bind_param('i', $documentId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to delete document record: ' . $err];
    }
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected < 1) {
        return ['success' => false, 'error' => 'Document not found.'];
    }

    if ($path && is_file($path)) {
        @unlink($path);
    }

    return [
        'success' => true,
        'id' => $documentId,
        'transaction_detail_id' => $txId,
        'original_filename' => $doc['original_filename'] ?? '',
    ];
}

/**
 * Classify a document for UI preview: image | pdf | text | other
 */
function ledgerDocumentPreviewKind(?string $mimeType, string $originalFilename): string {
    $mime = strtolower(trim((string)$mimeType));
    $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

    if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return 'image';
    }
    if ($mime === 'application/pdf' || $ext === 'pdf') {
        return 'pdf';
    }
    if (str_starts_with($mime, 'text/') || in_array($ext, ['txt', 'csv', 'log', 'md'], true)) {
        return 'text';
    }
    return 'other';
}
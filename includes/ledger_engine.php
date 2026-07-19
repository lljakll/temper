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

    // Reference # (YY####) lives in reference_number; migrate off sequence_number if present
    ledgerEnsureReferenceNumberSchema($db);

    $verified = true;
}

/**
 * Ensure transaction Reference # (YY####) is stored in reference_number.
 * Migrates legacy sequence_number column into reference_number, then drops it.
 * Index is non-unique so confirmed reuse is allowed.
 */
function ledgerEnsureReferenceNumberSchema(mysqli $db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $seqCol = $db->query("SHOW COLUMNS FROM transaction_details LIKE 'sequence_number'");
    if ($seqCol && $seqCol->num_rows > 0) {
        // Prefer explicit YY#### sequence values when present
        $db->query(
            "UPDATE transaction_details
             SET reference_number = sequence_number
             WHERE sequence_number IS NOT NULL
               AND sequence_number <> ''
               AND sequence_number REGEXP '^[0-9]{6}$'"
        );
        $oldIdx = $db->query("SHOW INDEX FROM transaction_details WHERE Key_name = 'idx_transaction_details_sequence'");
        if ($oldIdx && $oldIdx->num_rows > 0) {
            $db->query('DROP INDEX idx_transaction_details_sequence ON transaction_details');
        }
        if (!$db->query('ALTER TABLE transaction_details DROP COLUMN sequence_number')) {
            error_log('ledgerEnsureReferenceNumberSchema: drop sequence_number failed: ' . $db->error);
        }
    }

    $idxRes = $db->query("SHOW INDEX FROM transaction_details WHERE Key_name = 'idx_transaction_details_reference'");
    if (!$idxRes || $idxRes->num_rows === 0) {
        if (!$db->query('CREATE INDEX idx_transaction_details_reference ON transaction_details(reference_number)')) {
            error_log('ledgerEnsureReferenceNumberSchema: index create failed: ' . $db->error);
        }
    }
}

/**
 * Normalize a Reference # value (trim only).
 */
function ledgerNormalizeReferenceNumber(?string $value): string {
    return trim((string)$value);
}

/** @deprecated Use ledgerNormalizeReferenceNumber */
function ledgerNormalizeSequenceNumber(?string $value): string {
    return ledgerNormalizeReferenceNumber($value);
}

/**
 * Validate manual Reference # as YY#### (exactly 6 digits: 2-digit year + 4-digit serial).
 *
 * @return array{ok:bool,value:?string,error?:string}
 */
function ledgerValidateReferenceNumber(?string $value, bool $required = true): array {
    $ref = ledgerNormalizeReferenceNumber($value);
    if ($ref === '') {
        if ($required) {
            return [
                'ok' => false,
                'value' => null,
                'error' => 'Reference # is required (format YY####, e.g. 260001).',
            ];
        }
        return ['ok' => true, 'value' => null];
    }
    if (!preg_match('/^\d{6}$/', $ref)) {
        return [
            'ok' => false,
            'value' => null,
            'error' => 'Reference # must be YY#### (exactly 6 digits, e.g. 260001).',
        ];
    }
    return ['ok' => true, 'value' => $ref];
}

/** @deprecated Use ledgerValidateReferenceNumber */
function ledgerValidateSequenceNumber(?string $value, bool $required = true): array {
    return ledgerValidateReferenceNumber($value, $required);
}

/**
 * True if another transaction already uses this Reference # (YY####).
 */
function ledgerReferenceNumberTaken(mysqli $db, string $referenceNumber, ?int $excludeTransactionId = null): bool {
    return ledgerReferenceUsage($db, $referenceNumber, $excludeTransactionId) !== null;
}

/** @deprecated Use ledgerReferenceNumberTaken */
function ledgerSequenceNumberTaken(mysqli $db, string $sequenceNumber, ?int $excludeTransactionId = null): bool {
    return ledgerReferenceNumberTaken($db, $sequenceNumber, $excludeTransactionId);
}

/**
 * First transaction using this Reference # (excluding optional id), or null.
 *
 * @return array{id:int,reference_number:string,transaction_date:?string,pay_to:?string,memo:?string}|null
 */
function ledgerReferenceUsage(mysqli $db, string $referenceNumber, ?int $excludeTransactionId = null): ?array {
    ledgerRequireTables($db);
    $ref = ledgerNormalizeReferenceNumber($referenceNumber);
    if ($ref === '') {
        return null;
    }
    if ($excludeTransactionId !== null && $excludeTransactionId > 0) {
        $stmt = $db->prepare(
            'SELECT id, reference_number, transaction_date, pay_to, memo
             FROM transaction_details
             WHERE reference_number = ? AND id <> ?
             ORDER BY id ASC LIMIT 1'
        );
        $stmt->bind_param('si', $ref, $excludeTransactionId);
    } else {
        $stmt = $db->prepare(
            'SELECT id, reference_number, transaction_date, pay_to, memo
             FROM transaction_details
             WHERE reference_number = ?
             ORDER BY id ASC LIMIT 1'
        );
        $stmt->bind_param('s', $ref);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    return $row;
}

/** @deprecated Use ledgerReferenceUsage */
function ledgerSequenceUsage(mysqli $db, string $sequenceNumber, ?int $excludeTransactionId = null): ?array {
    $row = ledgerReferenceUsage($db, $sequenceNumber, $excludeTransactionId);
    if ($row) {
        $row['sequence_number'] = $row['reference_number'] ?? null;
    }
    return $row;
}

/**
 * Recommended Reference # serial ranges (suffix after YY):
 * - contribution: 0001–0099
 * - other:        0100–9999 (payments, reimbursements, transfers, manual ledger, etc.)
 *
 * @return array{kind:string,min:int,max:int,label:string,hint:string}
 */
function ledgerReferenceRangeForKind(string $kind = 'other'): array {
    $kind = strtolower(trim($kind));
    if ($kind === 'contribution' || $kind === 'contrib' || $kind === 'contributions') {
        return [
            'kind' => 'contribution',
            'min' => 1,
            'max' => 99,
            'label' => 'contributions',
            'hint' => 'YY0001–YY0099 reserved for contributions',
        ];
    }
    return [
        'kind' => 'other',
        'min' => 100,
        'max' => 9999,
        'label' => 'other transactions',
        'hint' => 'YY0100+ for payments, reimbursements, transfers, and other non-contribution entries',
    ];
}

/**
 * Soft advisory when a Reference # is outside the recommended range for a kind.
 * Does not block save — manual override is allowed.
 *
 * @return string|null Warning message, or null if in range / invalid format
 */
function ledgerReferenceRangeAdvisory(?string $value, string $kind = 'other'): ?string {
    $check = ledgerValidateReferenceNumber($value, false);
    if (empty($check['ok']) || empty($check['value'])) {
        return null;
    }
    $ref = $check['value'];
    if (!preg_match('/^\d{2}(\d{4})$/', $ref, $m)) {
        return null;
    }
    $suffix = (int)$m[1];
    $range = ledgerReferenceRangeForKind($kind);
    if ($suffix >= $range['min'] && $suffix <= $range['max']) {
        return null;
    }
    if ($range['kind'] === 'contribution') {
        return 'Reference # ' . $ref . ' is outside the contribution range (YY0001–YY0099). '
            . 'You may still use it if intentional.';
    }
    return 'Reference # ' . $ref . ' is in the contribution range (YY0001–YY0099). '
        . 'Non-contribution entries usually start at YY0100. You may still use it if intentional.';
}

/**
 * Suggest next free-ish Reference # for a year within the kind's range.
 * Does not auto-assign — UI only (placeholder / double-click fill).
 *
 * Ranges:
 * - contribution → YY0001–YY0099
 * - other        → YY0100–YY9999
 *
 * @param string|null $asOfDate Y-m-d (defaults to today) to pick YY
 * @param string      $kind     contribution|other
 */
function ledgerSuggestNextReferenceNumber(mysqli $db, ?string $asOfDate = null, string $kind = 'other'): string {
    ledgerRequireTables($db);
    $range = ledgerReferenceRangeForKind($kind);
    $ts = time();
    if ($asOfDate !== null && $asOfDate !== '') {
        $parsed = strtotime($asOfDate);
        if ($parsed !== false) {
            $ts = $parsed;
        }
    }
    $yy = date('y', $ts);
    $prefix = $yy;
    $like = $yy . '%';

    $stmt = $db->prepare(
        'SELECT reference_number FROM transaction_details
         WHERE reference_number LIKE ? AND CHAR_LENGTH(reference_number) = 6'
    );
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $maxInRange = $range['min'] - 1;
    while ($row = $res->fetch_assoc()) {
        $sn = ledgerNormalizeReferenceNumber($row['reference_number'] ?? '');
        if (!preg_match('/^(\d{2})(\d{4})$/', $sn, $m) || $m[1] !== $prefix) {
            continue;
        }
        $suffix = (int)$m[2];
        if ($suffix < $range['min'] || $suffix > $range['max']) {
            continue;
        }
        if ($suffix > $maxInRange) {
            $maxInRange = $suffix;
        }
    }
    $stmt->close();

    $next = $maxInRange + 1;
    if ($next < $range['min']) {
        $next = $range['min'];
    }
    if ($next > $range['max']) {
        // Range exhausted — still surface the max (user can override manually)
        $next = $range['max'];
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/** @deprecated Use ledgerSuggestNextReferenceNumber */
function ledgerSuggestNextSequenceNumber(mysqli $db, ?string $asOfDate = null, string $kind = 'other'): string {
    return ledgerSuggestNextReferenceNumber($db, $asOfDate, $kind);
}

/**
 * All used YY#### Reference # values for the lookup modal (highest first).
 *
 * @return list<array{id:int,reference_number:string,transaction_date:?string,pay_to:?string,memo:?string,description:string}>
 */
function ledgerListUsedReferenceNumbers(mysqli $db, int $limit = 1000): array {
    ledgerRequireTables($db);
    $limit = max(1, min(5000, $limit));
    $sql = "SELECT id, reference_number, transaction_date, pay_to, memo
            FROM transaction_details
            WHERE reference_number IS NOT NULL
              AND reference_number <> ''
              AND reference_number REGEXP '^[0-9]{6}$'
            ORDER BY reference_number DESC, transaction_date DESC, id DESC
            LIMIT " . (int)$limit;
    $rows = [];
    $res = $db->query($sql);
    if (!$res) {
        return [];
    }
    while ($row = $res->fetch_assoc()) {
        $memo = trim((string)($row['memo'] ?? ''));
        $pay = trim((string)($row['pay_to'] ?? ''));
        $desc = $pay !== '' ? $pay : ($memo !== '' ? $memo : '—');
        if (mb_strlen($desc) > 80) {
            $desc = mb_substr($desc, 0, 77) . '…';
        }
        $rows[] = [
            'id' => (int)$row['id'],
            'reference_number' => (string)$row['reference_number'],
            // Keep sequence_number key for older UI JSON consumers during rename
            'sequence_number' => (string)$row['reference_number'],
            'transaction_date' => $row['transaction_date'] ?? null,
            'pay_to' => $row['pay_to'] ?? null,
            'memo' => $row['memo'] ?? null,
            'description' => $desc,
        ];
    }
    return $rows;
}

/** @deprecated Use ledgerListUsedReferenceNumbers */
function ledgerListUsedSequenceNumbers(mysqli $db, int $limit = 1000): array {
    return ledgerListUsedReferenceNumbers($db, $limit);
}

/**
 * Resolve YY#### Reference # for a transaction (null if missing / not YY####).
 */
function ledgerGetReferenceNumber(mysqli $db, int $transactionId): ?string {
    ledgerRequireTables($db);
    $stmt = $db->prepare('SELECT reference_number FROM transaction_details WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $transactionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $ref = ledgerNormalizeReferenceNumber($row['reference_number'] ?? null);
    if ($ref === '' || !preg_match('/^\d{6}$/', $ref)) {
        return null;
    }
    return $ref;
}

/** @deprecated Use ledgerGetReferenceNumber */
function ledgerGetSequenceNumber(mysqli $db, int $transactionId): ?string {
    return ledgerGetReferenceNumber($db, $transactionId);
}

/**
 * Folder key for attachments: prefer Reference # (YY####), else numeric id (legacy).
 */
function ledgerAttachmentFolderKey(?string $referenceNumber, int $transactionId): string {
    $ref = ledgerNormalizeReferenceNumber($referenceNumber);
    if ($ref !== '' && preg_match('/^\d{6}$/', $ref)) {
        return $ref;
    }
    return (string)$transactionId;
}

/**
 * Absolute directory for a transaction's attachments under the preferred key.
 */
function ledgerAttachmentDir(string $folderKey): string {
    $safe = basename(str_replace(['\\', "\0"], '', $folderKey));
    if ($safe === '' || $safe === '.' || $safe === '..') {
        $safe = '_invalid';
    }
    return getTransactionDocumentsDir() . '/' . $safe;
}

/**
 * Move attachment files when Reference # changes (or from legacy id folder → YY####).
 */
function ledgerRelocateAttachmentFolder(string $fromKey, string $toKey): void {
    if ($fromKey === '' || $toKey === '' || $fromKey === $toKey) {
        return;
    }
    $from = ledgerAttachmentDir($fromKey);
    $to = ledgerAttachmentDir($toKey);
    if (!is_dir($from)) {
        return;
    }
    if (!is_dir($to) && !@mkdir($to, 0775, true)) {
        return;
    }
    $items = @scandir($from);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $src = $from . '/' . $name;
        $dst = $to . '/' . $name;
        if (is_file($src)) {
            if (!is_file($dst)) {
                @rename($src, $dst) || @copy($src, $dst);
            }
            if (is_file($dst) && is_file($src) && realpath($src) !== realpath($dst)) {
                @unlink($src);
            }
        }
    }
    // Remove empty source directory
    $left = @scandir($from);
    if (is_array($left) && count(array_diff($left, ['.', '..'])) === 0) {
        @rmdir($from);
    }
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

    $oldRef = ledgerNormalizeReferenceNumber($existing['reference_number'] ?? null);
    $newRef = ledgerNormalizeReferenceNumber($newHeader['reference_number'] ?? null);
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

/**
 * Create a transaction header. $referenceNumber is the manual YY#### Reference #.
 */
function ledgerCreateHeader(
    mysqli $db,
    string $transactionDate,
    string $payTo,
    string $referenceNumber,
    string $memo,
    ?int $createdByUserId = null,
    ?array $transactionData = null
): int {
    ledgerRequireTables($db);
    $dataJson = $transactionData ? json_encode($transactionData) : null;
    $ref = ledgerNormalizeReferenceNumber($referenceNumber);
    $refParam = $ref !== '' ? $ref : null;
    $stmt = $db->prepare(
        "INSERT INTO transaction_details (
            transaction_date, pay_to, reference_number, memo, status,
            created_by_user_id, transaction_data
         ) VALUES (?, ?, ?, ?, 'pending', ?, ?)"
    );
    $stmt->bind_param(
        'ssssis',
        $transactionDate,
        $payTo,
        $refParam,
        $memo,
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
    ?array $transactionData = null
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
        $ref = ledgerNormalizeReferenceNumber($referenceNumber);
        $sets[] = 'reference_number = ?';
        $types .= 's';
        $params[] = $ref !== '' ? $ref : null;
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
        'UPDATE transaction_details SET validated_by_user_id = ?, validated_at = NOW() WHERE id = ?'
    );
    $stmt->bind_param('ii', $validatedByUserId, $transactionId);
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
                uploaded_by_user_id, created_at
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
    string $mimeType
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

    // Attachments live under storage/attachments/{YY####}/ (Reference #). Require a valid ref.
    $reference = ledgerGetReferenceNumber($db, $transactionId);
    if ($reference === null) {
        return [
            'success' => false,
            'error' => 'Set a Reference # (YY####) on the transaction before uploading attachments.',
        ];
    }
    // Migrate any legacy id-folder files into the reference folder on first new upload
    ledgerRelocateAttachmentFolder((string)$transactionId, $reference);

    $folderKey = ledgerAttachmentFolderKey($reference, $transactionId);
    $dir = ledgerAttachmentDir($folderKey);
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

    $stmt = $db->prepare(
        'INSERT INTO transaction_documents (
            transaction_detail_id, stored_filename, original_filename, mime_type,
            file_size, uploaded_by_user_id
         ) VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        @unlink($dest);
        return ['success' => false, 'error' => 'Database error preparing document record.'];
    }
    $stmt->bind_param(
        'isssii',
        $transactionId,
        $stored,
        $safeOriginal,
        $safeMime,
        $size,
        $userId
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

    return [
        'success' => true,
        'id' => $docId,
        'stored_filename' => $stored,
        'reference_number' => $reference,
        'folder' => $folderKey,
    ];
}

/**
 * Store a document from a $_FILES entry after full validation.
 */
function ledgerStoreDocumentFromUpload(
    mysqli $db,
    int $transactionId,
    int $userId,
    array $file
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
        $check['mime_type']
    );
}

/**
 * Resolve on-disk path for a stored document filename.
 * Checks (in order): Reference # (YY####) folder, numeric id folder, legacy transaction_documents/{id}/.
 *
 * @param int         $transactionId    DB id (always known)
 * @param string      $storedFilename   Basename in storage
 * @param string|null $referenceNumber  Optional YY#### Reference #
 */
function ledgerResolveDocumentPath(
    int $transactionId,
    string $storedFilename,
    ?string $referenceNumber = null
): ?string {
    $safe = basename($storedFilename);
    if ($safe === '' || $safe === '.' || $safe === '..') {
        return null;
    }

    $candidates = [];
    $ref = ledgerNormalizeReferenceNumber($referenceNumber);
    if ($ref !== '' && preg_match('/^\d{6}$/', $ref)) {
        $candidates[] = ledgerAttachmentDir($ref) . '/' . $safe;
    }
    // Current / legacy id-based folder under attachments/
    $candidates[] = ledgerAttachmentDir((string)$transactionId) . '/' . $safe;
    // Older path: storage/transaction_documents/{txId}/
    $legacyRoot = resolveStorageRoot()['path'] . '/transaction_documents';
    $candidates[] = $legacyRoot . '/' . $transactionId . '/' . $safe;

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Resolve path using DB to load Reference # when not provided.
 */
function ledgerResolveDocumentPathForTransaction(
    mysqli $db,
    int $transactionId,
    string $storedFilename
): ?string {
    $ref = ledgerGetReferenceNumber($db, $transactionId);
    return ledgerResolveDocumentPath($transactionId, $storedFilename, $ref);
}

function ledgerFetchDocument(mysqli $db, int $documentId): ?array {
    ledgerRequireTables($db);
    $stmt = $db->prepare(
        'SELECT d.id, d.transaction_detail_id, d.stored_filename, d.original_filename, d.mime_type,
                d.file_size, d.uploaded_by_user_id, d.created_at,
                t.reference_number
         FROM transaction_documents d
         LEFT JOIN transaction_details t ON t.id = d.transaction_detail_id
         WHERE d.id = ? LIMIT 1'
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
    $ref = ledgerNormalizeReferenceNumber($doc['reference_number'] ?? null);
    $path = ledgerResolveDocumentPath($txId, $doc['stored_filename'], preg_match('/^\d{6}$/', $ref) ? $ref : null);

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
        'reference_number' => preg_match('/^\d{6}$/', $ref) ? $ref : null,
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
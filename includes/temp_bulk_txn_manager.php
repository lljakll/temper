<?php
/**
 * TEMPORARY Ledger bulk-apply helper for similar pending transactions.
 *
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 *
 * Speeds historical / current-year cleanup after temporary importers.
 * Self-contained so it can be deleted with those importers:
 *   - this file (includes/temp_bulk_txn_manager.php)
 *   - pages/ledger.php sections marked TEMP_BULK_TXN_MANAGER:
 *       require, $canBulkApply, POST action bulk_apply, toolbar button,
 *       modal render call, JS constants, updateButtonStates hook, modal JS
 *   - updates/20260826_0942_ledger_temp_bulk_apply.sql is a process-only
 *     history row (leave in place; do not roll back version history)
 *
 * Does not implement an importer, fuzzy matching, templates, or per-row values.
 * Does not change fund-balance rules or double-entry math.
 * Writes go through existing ledger update helpers (header, replace lines, audit).
 *
 * Access: Administrator or Treasurer (same roles as pending-transaction delete).
 * Does not grant bulk-apply to other roles that have ledger write (e.g. Finance Manager).
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

// TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
const TEMP_BULK_TXN_MANAGER_MAX_IDS = 500;
const TEMP_BULK_TXN_MANAGER_EVENT_PREFIX = 'Bulk apply: ';

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 *
 * @return list<string>
 */
function tempBulkTxnManagerAllowedRoleNames(): array
{
    return ['Administrator', 'Treasurer'];
}

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 */
function tempBulkTxnManagerUserCanAccess(mysqli $db, int $userId): bool
{
    if ($userId <= 0 || !function_exists('loadUserAcl')) {
        return false;
    }
    $acl = loadUserAcl($db, $userId);
    if (!$acl) {
        return false;
    }
    $role = trim((string)($acl['active_role_name'] ?? $acl['role_name'] ?? ''));
    return in_array($role, tempBulkTxnManagerAllowedRoleNames(), true);
}

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 *
 * @return array<string,mixed>|null
 */
function tempBulkTxnManagerLoadAccountMeta(mysqli $db, int $accountId, array &$cache): ?array
{
    if ($accountId <= 0) {
        return null;
    }
    if (array_key_exists($accountId, $cache)) {
        return $cache[$accountId];
    }
    $st = $db->prepare(
        'SELECT id, name, account_type, natural_category_id, functional_category_id, archived
         FROM accounts WHERE id = ? LIMIT 1'
    );
    if (!$st) {
        $cache[$accountId] = null;
        return null;
    }
    $st->bind_param('i', $accountId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        $cache[$accountId] = null;
        return null;
    }
    $nid = $row['natural_category_id'] !== null ? (int)$row['natural_category_id'] : null;
    $fid2 = $row['functional_category_id'] !== null ? (int)$row['functional_category_id'] : null;
    $meta = [
        'id' => (int)$row['id'],
        'name' => (string)($row['name'] ?? ''),
        'account_type' => strtolower(trim((string)($row['account_type'] ?? ''))),
        'natural_category_id' => ($nid !== null && $nid > 0) ? $nid : null,
        'functional_category_id' => ($fid2 !== null && $fid2 > 0) ? $fid2 : null,
        'archived' => !empty($row['archived']),
    ];
    $cache[$accountId] = $meta;
    return $meta;
}

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 *
 * Bank / cash lines (asset). Counterpart is any other line.
 */
function tempBulkTxnManagerIsBankLikeAccountType(string $accountType): bool
{
    return $accountType === 'asset';
}

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 *
 * Same rule as the ledger line editor: only income, expense, and equity
 * (net assets) lines affect fund balances.
 */
function tempBulkTxnManagerIsFundAppropriateAccountType(string $accountType): bool
{
    return in_array($accountType, ['income', 'expense', 'equity'], true);
}

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 */
function tempBulkTxnManagerNormalizeFundId(mixed $fundId): ?int
{
    if ($fundId === null || $fundId === '' || $fundId === false) {
        return null;
    }
    $n = (int)$fundId;
    return $n > 0 ? $n : null;
}

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 */
function tempBulkTxnManagerFundExists(mysqli $db, int $fundId): bool
{
    $st = $db->prepare(
        'SELECT id FROM funds WHERE id = ? AND is_active = TRUE AND archived = FALSE LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('i', $fundId);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_assoc();
    $st->close();
    return $ok;
}

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 *
 * @param array<string,mixed> $line
 * @return array<string,mixed>
 */
function tempBulkTxnManagerLineToReplace(array $line): array
{
    $aid = (int)($line['account_id'] ?? 0);
    $type = strtolower(trim((string)($line['type'] ?? 'debit')));
    if ($type !== 'debit' && $type !== 'credit') {
        $type = 'debit';
    }
    $nid = $line['natural_category_id'] ?? null;
    $fid2 = $line['functional_category_id'] ?? null;
    $nid = ($nid !== null && $nid !== '' && (int)$nid > 0) ? (int)$nid : null;
    $fid2 = ($fid2 !== null && $fid2 !== '' && (int)$fid2 > 0) ? (int)$fid2 : null;
    return [
        'account_id' => $aid,
        'fund_id' => tempBulkTxnManagerNormalizeFundId($line['fund_id'] ?? null),
        'amount' => (float)($line['amount'] ?? 0),
        'type' => $type,
        'natural_category_id' => $nid,
        'functional_category_id' => $fid2,
        'description' => function_exists('ledgerLineNoteFromRow')
            ? ledgerLineNoteFromRow($line)
            : trim((string)($line['description'] ?? '')),
    ];
}

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 *
 * @param array<string,mixed> $line
 * @return array<string,mixed>
 */
function tempBulkTxnManagerLineToDescribe(array $line): array
{
    $row = tempBulkTxnManagerLineToReplace($line);
    return [
        'aid' => $row['account_id'],
        'fid' => $row['fund_id'],
        'am' => $row['amount'],
        't' => $row['type'],
        'nid' => $row['natural_category_id'],
        'fid2' => $row['functional_category_id'],
        'description' => $row['description'],
    ];
}

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 *
 * @param list<array<string,mixed>> $lines
 */
function tempBulkTxnManagerLinesSnapshot(array $lines): string
{
    $parts = [];
    foreach ($lines as $line) {
        $row = tempBulkTxnManagerLineToReplace($line);
        $parts[] = implode("\t", [
            (string)$row['account_id'],
            (string)($row['fund_id'] ?? 0),
            number_format((float)$row['amount'], 2, '.', ''),
            $row['type'],
            (string)($row['natural_category_id'] ?? 0),
            (string)($row['functional_category_id'] ?? 0),
            $row['description'],
        ]);
    }
    return implode("\n", $parts);
}

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 *
 * @return array{description:?string,counterpart_account_id:?int,fund_id:?int,line_note:?string,line_note_all:bool}
 */
function tempBulkTxnManagerParsePatch(array $src): array
{
    $descRaw = $src['description'] ?? null;
    $description = null;
    if ($descRaw !== null && trim((string)$descRaw) !== '') {
        $description = trim((string)$descRaw);
        if (function_exists('mb_substr')) {
            $description = mb_substr($description, 0, 8000);
        } else {
            $description = substr($description, 0, 8000);
        }
    }

    $acctRaw = $src['counterpart_account_id'] ?? $src['account_id'] ?? 0;
    $acctId = (int)$acctRaw;
    $counterpartAccountId = $acctId > 0 ? $acctId : null;

    $fundId = tempBulkTxnManagerNormalizeFundId($src['fund_id'] ?? null);

    $noteRaw = $src['line_note'] ?? $src['note'] ?? null;
    $lineNote = null;
    if ($noteRaw !== null && trim((string)$noteRaw) !== '') {
        $lineNote = function_exists('ledgerNormalizeLineNote')
            ? ledgerNormalizeLineNote($noteRaw)
            : trim((string)$noteRaw);
    }

    $scope = strtolower(trim((string)($src['line_note_scope'] ?? 'counterpart')));
    $lineNoteAll = in_array($scope, ['all', 'all_lines', '1', 'true'], true)
        || !empty($src['line_note_all']);

    return [
        'description' => $description,
        'counterpart_account_id' => $counterpartAccountId,
        'fund_id' => $fundId,
        'line_note' => $lineNote,
        'line_note_all' => $lineNoteAll,
    ];
}

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 *
 * @param list<int|string> $rawIds
 * @return list<int>
 */
function tempBulkTxnManagerNormalizeIds(array $rawIds): array
{
    $ids = [];
    $seen = [];
    foreach ($rawIds as $raw) {
        $id = (int)$raw;
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $ids[] = $id;
    }
    return $ids;
}

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 *
 * @param array<string,mixed> $actor
 * @param array<string,mixed> $patch
 * @return array{ok:bool,status:string,warning:?string,error:?string}
 */
function tempBulkTxnManagerApplyOne(
    mysqli $db,
    int $txId,
    array $patch,
    array $actor,
    array &$accountCache
): array {
    $existing = ledgerFetchTransaction($db, $txId, 0);
    if (!$existing) {
        return ['ok' => false, 'status' => 'not_found', 'warning' => null, 'error' => 'Transaction not found.'];
    }
    $status = strtolower(trim((string)($existing['status'] ?? '')));
    if ($status !== 'pending' || !ledgerIsEditable($existing)) {
        return [
            'ok' => false,
            'status' => 'skipped_not_pending',
            'warning' => null,
            'error' => 'Cleared or reconciled transactions cannot be bulk-edited.',
        ];
    }

    $oldLines = $existing['lines'] ?? [];
    if (count($oldLines) < 2) {
        return [
            'ok' => false,
            'status' => 'error',
            'warning' => null,
            'error' => 'Transaction does not have at least two lines.',
        ];
    }

    $newLines = [];
    $counterpartIndexes = [];
    $warnings = [];
    foreach ($oldLines as $i => $old) {
        $row = tempBulkTxnManagerLineToReplace($old);
        $meta = tempBulkTxnManagerLoadAccountMeta($db, $row['account_id'], $accountCache);
        $acctType = $meta['account_type'] ?? '';
        if (!tempBulkTxnManagerIsBankLikeAccountType($acctType)) {
            $counterpartIndexes[] = $i;
        }
        $newLines[] = $row;
    }

    if ($patch['counterpart_account_id'] !== null) {
        if ($counterpartIndexes === []) {
            $warnings[] = 'No non-asset (counterpart) line; account was not changed.';
        } else {
            $newMeta = tempBulkTxnManagerLoadAccountMeta($db, $patch['counterpart_account_id'], $accountCache);
            if (!$newMeta) {
                return [
                    'ok' => false,
                    'status' => 'error',
                    'warning' => null,
                    'error' => 'Counterpart account was not found.',
                ];
            }
            foreach ($counterpartIndexes as $i) {
                $newLines[$i]['account_id'] = $newMeta['id'];
                $newLines[$i]['natural_category_id'] = $newMeta['natural_category_id'];
                $newLines[$i]['functional_category_id'] = $newMeta['functional_category_id'];
            }
        }
    }

    if ($patch['fund_id'] !== null) {
        $fundApplied = 0;
        foreach ($newLines as $i => $row) {
            $meta = tempBulkTxnManagerLoadAccountMeta($db, (int)$row['account_id'], $accountCache);
            $acctType = $meta['account_type'] ?? '';
            if (tempBulkTxnManagerIsFundAppropriateAccountType($acctType)) {
                $newLines[$i]['fund_id'] = $patch['fund_id'];
                $fundApplied++;
            }
        }
        if ($fundApplied === 0) {
            $warnings[] = 'No income/expense/equity line; fund was not applied.';
        }
    }

    if ($patch['line_note'] !== null) {
        $noteApplied = 0;
        foreach ($newLines as $i => $row) {
            $apply = $patch['line_note_all'];
            if (!$apply) {
                $meta = tempBulkTxnManagerLoadAccountMeta($db, (int)$row['account_id'], $accountCache);
                $acctType = $meta['account_type'] ?? '';
                $apply = !tempBulkTxnManagerIsBankLikeAccountType($acctType);
            }
            if ($apply) {
                $newLines[$i]['description'] = $patch['line_note'];
                $noteApplied++;
            }
        }
        if ($noteApplied === 0) {
            $warnings[] = 'No counterpart line; line note was not applied.';
        }
    }

    $newDescription = $patch['description'] !== null
        ? $patch['description']
        : (string)($existing['description'] ?? '');
    $oldDescription = (string)($existing['description'] ?? '');
    $headerChanged = $patch['description'] !== null && $newDescription !== $oldDescription;
    $linesChanged = tempBulkTxnManagerLinesSnapshot($oldLines) !== tempBulkTxnManagerLinesSnapshot($newLines);

    if (!$headerChanged && !$linesChanged) {
        return [
            'ok' => true,
            'status' => 'no_change',
            'warning' => $warnings !== [] ? implode(' ', $warnings) : null,
            'error' => null,
        ];
    }

    $dt = 0.0;
    $ct = 0.0;
    foreach ($newLines as $row) {
        if ($row['type'] === 'credit') {
            $ct += (float)$row['amount'];
        } else {
            $dt += (float)$row['amount'];
        }
    }
    if (abs($dt - $ct) > 0.005) {
        return [
            'ok' => false,
            'status' => 'error',
            'warning' => null,
            'error' => 'Debits do not equal credits; bulk apply will not rewrite unbalanced transactions.',
        ];
    }

    $actorId = isset($actor['id']) ? (int)$actor['id'] : null;
    $actorName = (string)($actor['username'] ?? 'system');
    $vlines = array_map('tempBulkTxnManagerLineToDescribe', $newLines);

    $db->begin_transaction();
    try {
        if ($headerChanged) {
            ledgerUpdateHeader($db, $txId, null, null, null, $newDescription);
        }
        if ($linesChanged) {
            ledgerReplaceLines($db, $txId, $newLines);
        }
        $describe = ledgerDescribeTransactionUpdate(
            $db,
            $existing,
            [
                'transaction_date' => (string)($existing['transaction_date'] ?? ''),
                'pay_to' => (string)($existing['pay_to'] ?? ''),
                'reference_number' => (string)($existing['reference_number'] ?? ''),
                'check_number' => (string)($existing['check_number'] ?? ''),
                'description' => $newDescription,
                'budget_id' => !empty($existing['budget_id']) ? (int)$existing['budget_id'] : 0,
            ],
            $vlines
        );
        $summary = $describe['summary'] !== '' ? $describe['summary'] : 'Transaction updated.';
        if (strpos($summary, TEMP_BULK_TXN_MANAGER_EVENT_PREFIX) !== 0) {
            $summary = TEMP_BULK_TXN_MANAGER_EVENT_PREFIX . $summary;
        }
        ledgerLogEvent(
            $db,
            $txId,
            'updated',
            $actorId,
            $actorName,
            $summary,
            [
                'bulk_apply' => true,
                'debits' => $dt,
                'credits' => $ct,
                'changes' => $describe['changes'],
                'warnings' => $warnings,
            ]
        );
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return [
            'ok' => false,
            'status' => 'error',
            'warning' => null,
            'error' => 'Update failed: ' . $e->getMessage(),
        ];
    }

    return [
        'ok' => true,
        'status' => 'updated',
        'warning' => $warnings !== [] ? implode(' ', $warnings) : null,
        'error' => null,
    ];
}

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 *
 * @param array<string,mixed>|null $actor
 * @return array<string,mixed>
 */
function tempBulkTxnManagerHandlePost(mysqli $db, ?array $actor, bool $canWriteLedger): array
{
    if (!$actor || !$canWriteLedger || !tempBulkTxnManagerUserCanAccess($db, (int)$actor['id'])) {
        return [
            'success' => false,
            'error' => 'Only an Administrator or Treasurer can bulk-apply pending transactions.',
        ];
    }

    $confirm = (string)($_POST['confirm'] ?? '');
    if (!in_array($confirm, ['1', 'true', 'yes'], true)) {
        return [
            'success' => false,
            'error' => 'Confirmation is required before bulk apply.',
        ];
    }

    $rawIds = $_POST['tx_ids'] ?? $_POST['tx_id'] ?? [];
    if (is_string($rawIds)) {
        $decoded = json_decode($rawIds, true);
        if (is_array($decoded)) {
            $rawIds = $decoded;
        } else {
            $rawIds = preg_split('/[,\s]+/', $rawIds) ?: [];
        }
    }
    if (!is_array($rawIds)) {
        $rawIds = [$rawIds];
    }
    $ids = tempBulkTxnManagerNormalizeIds($rawIds);
    if ($ids === []) {
        return ['success' => false, 'error' => 'Select at least one transaction.'];
    }
    if (count($ids) > TEMP_BULK_TXN_MANAGER_MAX_IDS) {
        return [
            'success' => false,
            'error' => 'Bulk apply is limited to ' . TEMP_BULK_TXN_MANAGER_MAX_IDS . ' transactions at a time.',
        ];
    }

    $patch = tempBulkTxnManagerParsePatch($_POST);
    if (
        $patch['description'] === null
        && $patch['counterpart_account_id'] === null
        && $patch['fund_id'] === null
        && $patch['line_note'] === null
    ) {
        return [
            'success' => false,
            'error' => 'Set at least one value to apply (account, fund, description, or line note).',
        ];
    }

    $accountCache = [];
    if ($patch['counterpart_account_id'] !== null) {
        $meta = tempBulkTxnManagerLoadAccountMeta($db, $patch['counterpart_account_id'], $accountCache);
        if (!$meta) {
            return ['success' => false, 'error' => 'Counterpart account was not found.'];
        }
        if (!empty($meta['archived'])) {
            return ['success' => false, 'error' => 'Counterpart account is archived.'];
        }
    }
    if ($patch['fund_id'] !== null && !tempBulkTxnManagerFundExists($db, $patch['fund_id'])) {
        return ['success' => false, 'error' => 'Fund was not found or is inactive.'];
    }

    $updated = 0;
    $noChange = 0;
    $skippedNotPending = 0;
    $skippedNotFound = 0;
    $errors = [];
    $warnings = [];

    foreach ($ids as $txId) {
        $one = tempBulkTxnManagerApplyOne($db, $txId, $patch, $actor, $accountCache);
        if (!empty($one['warning'])) {
            $warnings[] = '#' . $txId . ': ' . $one['warning'];
        }
        if ($one['status'] === 'updated') {
            $updated++;
            continue;
        }
        if ($one['status'] === 'no_change') {
            $noChange++;
            continue;
        }
        if ($one['status'] === 'skipped_not_pending') {
            $skippedNotPending++;
            continue;
        }
        if ($one['status'] === 'not_found') {
            $skippedNotFound++;
            continue;
        }
        $errors[] = [
            'id' => $txId,
            'error' => $one['error'] ?: 'Update failed.',
        ];
    }

    $parts = [];
    if ($updated > 0) {
        $parts[] = $updated . ' pending transaction' . ($updated === 1 ? '' : 's') . ' updated';
    }
    if ($noChange > 0) {
        $parts[] = $noChange . ' already matched (no change)';
    }
    if ($skippedNotPending > 0) {
        $parts[] = $skippedNotPending . ' cleared/reconciled skipped';
    }
    if ($skippedNotFound > 0) {
        $parts[] = $skippedNotFound . ' not found';
    }
    if ($errors !== []) {
        $parts[] = count($errors) . ' failed';
    }
    $message = $parts !== [] ? (implode('. ', $parts) . '.') : 'No transactions were updated.';
    $success = $updated > 0 || $noChange > 0
        || ($errors === [] && ($skippedNotPending > 0 || $skippedNotFound > 0));
    $payload = [
        'success' => $success,
        'message' => $message,
        'updated' => $updated,
        'no_change' => $noChange,
        'skipped_not_pending' => $skippedNotPending,
        'skipped_not_found' => $skippedNotFound,
        'failed' => count($errors),
        'errors' => $errors,
        'warnings' => $warnings,
        'ids' => $ids,
    ];
    if (!$success) {
        $payload['error'] = ($errors[0]['error'] ?? null) ?: $message;
    }
    return $payload;
}

/**
 * TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired
 */
function tempBulkTxnManagerRenderModal(string $accountOptionsHtml, string $fundOptionsHtml): void
{
    $fundInner = $fundOptionsHtml;
    $emptyFund = '<option value="">—</option>';
    if (strncmp($fundInner, $emptyFund, strlen($emptyFund)) === 0) {
        $fundInner = substr($fundInner, strlen($emptyFund));
    }
    ?>
<!-- TEMP_BULK_TXN_MANAGER — remove when historical load tools are retired -->
<div class="modal fade" id="bulkApplyModal" tabindex="-1" aria-labelledby="bulkApplyTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="bulkApplyTitle">Bulk apply to pending</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">
                    Temporary cleanup helper. Blank fields are left unchanged.
                    Bank/cash (asset) lines are not recoded. Account, fund, and line note apply to the
                    counterpart (non-asset) line — the same line that should carry the fund tag.
                </p>
                <div class="alert alert-warning py-2 small mb-3" id="bulkApplySkipAlert" hidden>
                    <span id="bulkApplySkipText"></span>
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1" for="bulkApplyAccount">Counterpart account</label>
                    <select class="form-select form-select-sm" id="bulkApplyAccount" data-dirty-ignore>
                        <option value="">— leave unchanged —</option>
                        <?= $accountOptionsHtml ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1" for="bulkApplyFund">Fund (income / expense / equity line)</label>
                    <select class="form-select form-select-sm" id="bulkApplyFund" data-dirty-ignore>
                        <option value="">— leave unchanged —</option>
                        <?= $fundInner ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1" for="bulkApplyDescription">Description</label>
                    <input type="text" class="form-control form-control-sm" id="bulkApplyDescription"
                           placeholder="Leave blank to keep existing" data-dirty-ignore autocomplete="off">
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1" for="bulkApplyLineNote">Line note</label>
                    <input type="text" class="form-control form-control-sm" id="bulkApplyLineNote"
                           maxlength="255" placeholder="Leave blank to keep existing" data-dirty-ignore autocomplete="off">
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" id="bulkApplyNoteAll" data-dirty-ignore>
                        <label class="form-check-label small" for="bulkApplyNoteAll">Apply this note to all lines</label>
                    </div>
                </div>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" id="bulkApplyConfirm" data-dirty-ignore>
                    <label class="form-check-label small" for="bulkApplyConfirm" id="bulkApplyConfirmLabel">
                        I confirm applying these values to <span id="bulkApplyPendingCount">0</span> pending transaction(s).
                    </label>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="bulkApplySubmit" disabled>Apply</button>
            </div>
        </div>
    </div>
</div>
    <?php
}

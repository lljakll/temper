<?php
/**
 * TEMPORARY Bank Export mass importer (FMB Checking CSV).
 *
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * Self-contained so it can be deleted after historical bank CSV data is loaded:
 *   - this file
 *   - pages/ledger_bank_export.php
 *   - Ledger → Bank Export nav entries in includes/nav.php
 *
 * Does not alter Beancount Mass Import or the single-transaction Import-from-Text.
 * Does not assign funds or Reference numbers. No duplicate detection. No attachments.
 *
 * Access: Administrator, Treasurer, Finance Manager, Archivist
 * (reuses existing page.ledger.mass_import; no extra permission grant).
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

// TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
const BANK_EXPORT_IMPORT_PERMISSION = 'page.ledger.mass_import';
const BANK_EXPORT_CHECKING_ACCOUNT_NAME = 'FMB: Checking Account';
const BANK_EXPORT_IMBALANCE_ACCOUNT_NAME = 'Imbalance';
const BANK_EXPORT_IMPORT_SOURCE = 'bank_export_import';
const BANK_EXPORT_IMPORT_MAX_ROWS = 10000;

/**
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * @return list<string>
 */
function bankExportImportAllowedRoleNames(): array
{
    return ['Administrator', 'Treasurer', 'Finance Manager', 'Archivist'];
}

/** TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete */
function bankExportImportUserCanAccess(?array $acl): bool
{
    if (!$acl) {
        return false;
    }
    $perms = $acl['permissions'] ?? [];
    if (function_exists('permissionSetAllows')) {
        if (permissionSetAllows($perms, BANK_EXPORT_IMPORT_PERMISSION)) {
            return true;
        }
        if (permissionSetAllows($perms, '*')) {
            return true;
        }
    }
    $active = (string)($acl['active_role_name'] ?? $acl['role_name'] ?? '');
    return in_array($active, bankExportImportAllowedRoleNames(), true);
}

/**
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * @return array ACL row
 */
function bankExportImportRequireAccess(mysqli $db): array
{
    requireLogin($db);
    $user = getCurrentUser();
    if (!$user) {
        denyUnauthenticatedAccess();
    }
    $acl = loadUserAcl($db, (int)$user['id']);
    if (!$acl || !bankExportImportUserCanAccess($acl)) {
        denyPermission('You do not have permission to use Bank Export import.');
    }
    return $acl;
}

/** TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete */
function bankExportImportNormalizeHeader(string $raw): string
{
    $s = strtolower(trim($raw));
    $s = preg_replace('/^[\xEF\xBB\xBF]+/', '', $s) ?? $s;
    $s = trim($s, " \t\n\r\0\x0B\"'");
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return $s;
}

/**
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * @return array<string, string> normalized header → field key
 */
function bankExportImportExpectedHeaders(): array
{
    return [
        'account name' => 'account_name',
        'processed date' => 'processed_date',
        'description' => 'description',
        'check number' => 'check_number',
        'credit or debit' => 'credit_or_debit',
        'amount' => 'amount',
    ];
}

/** TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete */
function bankExportImportParseDate(string $raw): ?string
{
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1])
            ? sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3])
            : null;
    }
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $s, $m)) {
        $month = (int)$m[1];
        $day = (int)$m[2];
        $year = (int)$m[3];
        return checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2})$/', $s, $m)) {
        $month = (int)$m[1];
        $day = (int)$m[2];
        $yy = (int)$m[3];
        $year = $yy >= 70 ? 1900 + $yy : 2000 + $yy;
        return checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }
    $ts = strtotime($s);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

/** TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete */
function bankExportImportParseAmount(string $raw): ?float
{
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    if (str_starts_with($s, '(') && str_ends_with($s, ')')) {
        $s = substr($s, 1, -1);
    }
    $s = str_replace(["\xC2\xA0", '$', ',', ' '], '', $s);
    if ($s === '' || !is_numeric($s)) {
        return null;
    }
    $n = round(abs((float)$s), 2);
    if ($n <= 0) {
        return null;
    }
    return $n;
}

/**
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * Bank "Credit" = money in; bank "Debit" = money out.
 */
function bankExportImportParseDirection(string $raw): ?string
{
    $s = strtolower(trim($raw));
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    if (in_array($s, ['credit', 'cr', 'c'], true)) {
        return 'credit';
    }
    if (in_array($s, ['debit', 'dr', 'd'], true)) {
        return 'debit';
    }
    return null;
}

/** TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete */
function bankExportImportNormalizeCheckNumber(string $raw): array
{
    $s = trim($raw);
    if ($s === '' || $s === '-' || strcasecmp($s, 'n/a') === 0) {
        return ['ok' => true, 'value' => ''];
    }
    if (strlen($s) > 20) {
        return ['ok' => false, 'value' => '', 'error' => 'Check Number is longer than 20 characters.'];
    }
    return ['ok' => true, 'value' => $s];
}

/**
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * @return list<list<string>>
 */
function bankExportImportReadCsvRows(string $csv): array
{
    $csv = str_replace("\r\n", "\n", $csv);
    $csv = str_replace("\r", "\n", $csv);
    if (str_starts_with($csv, "\xEF\xBB\xBF")) {
        $csv = substr($csv, 3);
    }
    $fp = fopen('php://temp', 'r+');
    if ($fp === false) {
        return [];
    }
    fwrite($fp, $csv);
    rewind($fp);
    $rows = [];
    // PHP 8.4+: $escape must be passed explicitly or fgetcsv() emits a deprecation
    // that poisons the JSON Preview response when display_errors is on.
    while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }
        $rows[] = array_map(static fn($c) => is_string($c) ? $c : (string)$c, $row);
    }
    fclose($fp);
    return $rows;
}

/**
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * @param list<list<string>> $rows
 * @return array{ok:bool,map?:array<string,int>,error?:string,header_index?:int}
 */
function bankExportImportFindHeaderMap(array $rows): array
{
    $expected = bankExportImportExpectedHeaders();
    $limit = min(count($rows), 12);
    for ($i = 0; $i < $limit; $i++) {
        $map = [];
        foreach ($rows[$i] as $col => $cell) {
            $key = bankExportImportNormalizeHeader((string)$cell);
            if ($key !== '' && isset($expected[$key]) && !isset($map[$expected[$key]])) {
                $map[$expected[$key]] = (int)$col;
            }
        }
        $missing = [];
        foreach (['processed_date', 'description', 'check_number', 'credit_or_debit', 'amount'] as $need) {
            if (!isset($map[$need])) {
                $missing[] = array_search($need, $expected, true) ?: $need;
            }
        }
        if ($missing === []) {
            return ['ok' => true, 'map' => $map, 'header_index' => $i];
        }
        if ($i === 0) {
            $firstMissing = $missing;
        }
    }
    $hint = isset($firstMissing) ? (' Missing: ' . implode(', ', $firstMissing) . '.') : '';
    return [
        'ok' => false,
        'error' => 'CSV headers must include "Processed Date","Description","Check Number","Credit or Debit","Amount".'
            . $hint,
    ];
}

/**
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * @param list<string> $row
 * @param array<string,int> $map
 */
function bankExportImportCell(array $row, array $map, string $key): string
{
    $idx = $map[$key] ?? null;
    if ($idx === null || !isset($row[$idx])) {
        return '';
    }
    return trim((string)$row[$idx]);
}

/**
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * @return array{ok:bool,checking:?array,imbalance:?array,error?:string}
 */
function bankExportImportResolveAccounts(mysqli $db): array
{
    $load = static function (mysqli $db, string $name): ?array {
        $stmt = $db->prepare(
            'SELECT id, name, natural_category_id, functional_category_id
             FROM accounts
             WHERE archived = FALSE AND name = ?
             LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        $nid = $row['natural_category_id'] !== null ? (int)$row['natural_category_id'] : null;
        $fid2 = $row['functional_category_id'] !== null ? (int)$row['functional_category_id'] : null;
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'natural_category_id' => ($nid !== null && $nid > 0) ? $nid : null,
            'functional_category_id' => ($fid2 !== null && $fid2 > 0) ? $fid2 : null,
        ];
    };

    $checking = $load($db, BANK_EXPORT_CHECKING_ACCOUNT_NAME);
    $imbalance = $load($db, BANK_EXPORT_IMBALANCE_ACCOUNT_NAME);
    if (!$checking || !$imbalance) {
        $missing = [];
        if (!$checking) {
            $missing[] = '"' . BANK_EXPORT_CHECKING_ACCOUNT_NAME . '"';
        }
        if (!$imbalance) {
            $missing[] = '"' . BANK_EXPORT_IMBALANCE_ACCOUNT_NAME . '"';
        }
        return [
            'ok' => false,
            'checking' => $checking,
            'imbalance' => $imbalance,
            'error' => 'Required account(s) not found (must already exist, not archived): ' . implode(' and ', $missing) . '.',
        ];
    }
    return ['ok' => true, 'checking' => $checking, 'imbalance' => $imbalance];
}

/**
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * Credit (money in): Debit FMB Checking, Credit Imbalance.
 * Debit (money out): Credit FMB Checking, Debit Imbalance.
 *
 * @return array{checking_type:string,imbalance_type:string}
 */
function bankExportImportLegTypes(string $direction): array
{
    if ($direction === 'credit') {
        return ['checking_type' => 'debit', 'imbalance_type' => 'credit'];
    }
    return ['checking_type' => 'credit', 'imbalance_type' => 'debit'];
}

/**
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * @param list<string> $row
 * @param array<string,int> $map
 * @return array{ok:bool,item?:array,error?:string,blank?:bool}
 */
function bankExportImportParseDataRow(array $row, array $map, int $lineNumber): array
{
    $dateRaw = bankExportImportCell($row, $map, 'processed_date');
    $desc = bankExportImportCell($row, $map, 'description');
    $checkRaw = bankExportImportCell($row, $map, 'check_number');
    $dirRaw = bankExportImportCell($row, $map, 'credit_or_debit');
    $amtRaw = bankExportImportCell($row, $map, 'amount');

    // Ignore Account Name and skip rows with no date / direction / amount
    // (trailing blanks or leftover account-name-only lines).
    if ($dateRaw === '' && $dirRaw === '' && $amtRaw === '') {
        return ['ok' => true, 'blank' => true];
    }

    $errors = [];
    $date = bankExportImportParseDate($dateRaw);
    if ($date === null) {
        $errors[] = 'Invalid Processed Date.';
    }
    $direction = bankExportImportParseDirection($dirRaw);
    if ($direction === null) {
        $errors[] = 'Credit or Debit must be Credit or Debit.';
    }
    $amount = bankExportImportParseAmount($amtRaw);
    if ($amount === null) {
        $errors[] = 'Invalid Amount.';
    }
    $check = bankExportImportNormalizeCheckNumber($checkRaw);
    if (empty($check['ok'])) {
        $errors[] = $check['error'] ?? 'Invalid Check Number.';
    }
    if (strlen($desc) > 255) {
        $desc = substr($desc, 0, 255);
    }

    if ($errors !== []) {
        return [
            'ok' => false,
            'error' => 'Row ' . $lineNumber . ': ' . implode(' ', $errors),
        ];
    }

    $legs = bankExportImportLegTypes((string)$direction);
    return [
        'ok' => true,
        'item' => [
            'row_number' => $lineNumber,
            'transaction_date' => $date,
            'pay_to' => $desc,
            'description' => $desc,
            'check_number' => (string)($check['value'] ?? ''),
            'bank_direction' => $direction,
            'amount' => $amount,
            'checking_type' => $legs['checking_type'],
            'imbalance_type' => $legs['imbalance_type'],
            'account_name_ignored' => bankExportImportCell($row, $map, 'account_name'),
        ],
    ];
}

/**
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * @return array{ok:bool,items:list<array>,errors:list<string>,row_count:int}
 */
function bankExportImportParseCsv(string $csv): array
{
    $csv = trim($csv);
    if ($csv === '') {
        return [
            'ok' => false,
            'items' => [],
            'errors' => ['Paste or upload a bank-export CSV first.'],
            'row_count' => 0,
        ];
    }

    $rows = bankExportImportReadCsvRows($csv);
    if ($rows === []) {
        return [
            'ok' => false,
            'items' => [],
            'errors' => ['CSV is empty.'],
            'row_count' => 0,
        ];
    }

    $header = bankExportImportFindHeaderMap($rows);
    if (empty($header['ok'])) {
        return [
            'ok' => false,
            'items' => [],
            'errors' => [$header['error'] ?? 'Could not find CSV headers.'],
            'row_count' => 0,
        ];
    }

    $map = $header['map'];
    $start = (int)$header['header_index'] + 1;
    $items = [];
    $errors = [];
    $dataRows = 0;
    for ($i = $start; $i < count($rows); $i++) {
        $lineNumber = $i + 1;
        $parsed = bankExportImportParseDataRow($rows[$i], $map, $lineNumber);
        if (!empty($parsed['blank'])) {
            continue;
        }
        $dataRows++;
        if ($dataRows > BANK_EXPORT_IMPORT_MAX_ROWS) {
            $errors[] = 'CSV has more than ' . BANK_EXPORT_IMPORT_MAX_ROWS . ' transactions.';
            break;
        }
        if (empty($parsed['ok'])) {
            $errors[] = $parsed['error'] ?? ('Row ' . $lineNumber . ': could not parse.');
            continue;
        }
        $items[] = $parsed['item'];
    }

    if ($dataRows === 0 && $errors === []) {
        return [
            'ok' => false,
            'items' => [],
            'errors' => ['No transaction rows found under the header.'],
            'row_count' => 0,
        ];
    }

    return [
        'ok' => $errors === [] && $items !== [],
        'items' => $items,
        'errors' => $errors,
        'row_count' => $dataRows,
    ];
}

/**
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * Validate a preview/import row payload (do not trust client account ids).
 *
 * @return array{ok:bool,item?:array,error?:string}
 */
function bankExportImportValidateItem(array $raw, int $index): array
{
    $lineNumber = (int)($raw['row_number'] ?? ($index + 2));
    $date = bankExportImportParseDate((string)($raw['transaction_date'] ?? ''));
    $direction = bankExportImportParseDirection((string)($raw['bank_direction'] ?? ''));
    $amount = bankExportImportParseAmount((string)($raw['amount'] ?? ''));
    $desc = trim((string)($raw['pay_to'] ?? $raw['description'] ?? ''));
    if (strlen($desc) > 255) {
        $desc = substr($desc, 0, 255);
    }
    $check = bankExportImportNormalizeCheckNumber((string)($raw['check_number'] ?? ''));

    $errors = [];
    if ($date === null) {
        $errors[] = 'Invalid date.';
    }
    if ($direction === null) {
        $errors[] = 'Credit or Debit must be Credit or Debit.';
    }
    if ($amount === null) {
        $errors[] = 'Invalid amount.';
    }
    if (empty($check['ok'])) {
        $errors[] = $check['error'] ?? 'Invalid Check Number.';
    }
    if ($errors !== []) {
        return ['ok' => false, 'error' => 'Row ' . $lineNumber . ': ' . implode(' ', $errors)];
    }

    $legs = bankExportImportLegTypes((string)$direction);
    return [
        'ok' => true,
        'item' => [
            'row_number' => $lineNumber,
            'transaction_date' => $date,
            'pay_to' => $desc,
            'description' => $desc,
            'check_number' => (string)($check['value'] ?? ''),
            'bank_direction' => $direction,
            'amount' => $amount,
            'checking_type' => $legs['checking_type'],
            'imbalance_type' => $legs['imbalance_type'],
        ],
    ];
}

/**
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * @return array{ok:bool,id?:int,error?:string}
 */
function bankExportImportWriteOne(mysqli $db, array $item, array $actor, array $checking, array $imbalance): array
{
    $date = (string)$item['transaction_date'];
    $payTo = (string)($item['pay_to'] ?? '');
    $desc = (string)($item['description'] ?? $payTo);
    $checkNo = (string)($item['check_number'] ?? '');
    $amount = (float)$item['amount'];
    $checkingType = (string)$item['checking_type'];
    $imbalanceType = (string)$item['imbalance_type'];
    if ($amount <= 0 || ($checkingType !== 'debit' && $checkingType !== 'credit')
        || ($imbalanceType !== 'debit' && $imbalanceType !== 'credit')) {
        return ['ok' => false, 'error' => 'Invalid transaction payload.'];
    }

    $createdBy = isset($actor['id']) ? (int)$actor['id'] : null;
    $username = (string)($actor['username'] ?? 'system');

    $tid = ledgerCreateHeader(
        $db,
        $date,
        $payTo,
        '',
        $desc,
        $createdBy,
        ['source' => BANK_EXPORT_IMPORT_SOURCE],
        null
    );
    if ($tid <= 0) {
        return ['ok' => false, 'error' => 'Failed to create transaction header.'];
    }

    $chk = $db->prepare('UPDATE transaction_details SET check_number = ? WHERE id = ?');
    $checkParam = $checkNo !== '' ? $checkNo : null;
    $chk->bind_param('si', $checkParam, $tid);
    $chk->execute();
    $chk->close();

    $lineRows = [
        [
            'account_id' => (int)$checking['id'],
            'fund_id' => null,
            'amount' => $amount,
            'type' => $checkingType,
            'natural_category_id' => $checking['natural_category_id'],
            'functional_category_id' => $checking['functional_category_id'],
            'description' => '',
        ],
        [
            'account_id' => (int)$imbalance['id'],
            'fund_id' => null,
            'amount' => $amount,
            'type' => $imbalanceType,
            'natural_category_id' => $imbalance['natural_category_id'],
            'functional_category_id' => $imbalance['functional_category_id'],
            'description' => '',
        ],
    ];
    ledgerReplaceLines($db, $tid, $lineRows);

    $vlines = [
        ['am' => $amount, 't' => $checkingType, 'fid' => null],
        ['am' => $amount, 't' => $imbalanceType, 'fid' => null],
    ];
    $describe = ledgerDescribeTransactionCreate($db, $payTo, $vlines);
    ledgerLogEvent(
        $db,
        $tid,
        'created',
        $createdBy,
        $username,
        'Bank export import: ' . $describe['summary'],
        [
            'source' => BANK_EXPORT_IMPORT_SOURCE,
            'bank_direction' => (string)($item['bank_direction'] ?? ''),
            'debits' => $amount,
            'credits' => $amount,
            'changes' => $describe['changes'],
        ]
    );

    return ['ok' => true, 'id' => $tid];
}

/**
 * TEMP_BANK_EXPORT_IMPORTER — remove when historical bank data load is complete
 *
 * @param list<array<string,mixed>> $items
 * @return array{ok:bool,imported:list<array>,rejected:list<array>,error?:string}
 */
function bankExportImportWriteBatch(mysqli $db, array $items, array $actor): array
{
    $accounts = bankExportImportResolveAccounts($db);
    if (empty($accounts['ok'])) {
        return [
            'ok' => false,
            'imported' => [],
            'rejected' => [],
            'error' => $accounts['error'] ?? 'Required accounts were not found.',
        ];
    }
    $checking = $accounts['checking'];
    $imbalance = $accounts['imbalance'];

    $toWrite = [];
    $rejected = [];
    foreach ($items as $i => $raw) {
        if (!is_array($raw)) {
            $rejected[] = ['error' => 'Item ' . ($i + 1) . ' is invalid.'];
            continue;
        }
        $valid = bankExportImportValidateItem($raw, (int)$i);
        if (empty($valid['ok'])) {
            $rejected[] = ['error' => $valid['error'] ?? 'Invalid row.'];
            continue;
        }
        $toWrite[] = $valid['item'];
    }
    if ($rejected !== []) {
        return [
            'ok' => false,
            'imported' => [],
            'rejected' => $rejected,
            'error' => $rejected[0]['error'] ?? 'Some rows could not be imported.',
        ];
    }
    if ($toWrite === []) {
        return [
            'ok' => false,
            'imported' => [],
            'rejected' => [],
            'error' => 'Nothing to import.',
        ];
    }

    $imported = [];
    $db->begin_transaction();
    try {
        foreach ($toWrite as $it) {
            $res = bankExportImportWriteOne($db, $it, $actor, $checking, $imbalance);
            if (empty($res['ok'])) {
                throw new RuntimeException($res['error'] ?? 'Write failed.');
            }
            $imported[] = [
                'id' => (int)$res['id'],
                'row_number' => (int)($it['row_number'] ?? 0),
                'transaction_date' => (string)$it['transaction_date'],
                'pay_to' => (string)$it['pay_to'],
                'amount' => $it['amount'],
            ];
        }
        if (function_exists('logAuditAction')) {
            $uid = isset($actor['id']) ? (int)$actor['id'] : null;
            $uname = (string)($actor['username'] ?? 'system');
            logAuditAction(
                $db,
                $uid,
                $uname,
                'ledger.bank_export_import',
                count($imported) . ' FMB Checking transaction(s) imported from bank-export CSV (no funds, no Ref #).'
            );
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return [
            'ok' => false,
            'imported' => [],
            'rejected' => [],
            'error' => 'Import failed: ' . $e->getMessage(),
        ];
    }

    return [
        'ok' => true,
        'imported' => $imported,
        'rejected' => [],
    ];
}

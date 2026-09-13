<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

/**
 * Fund tagging & balance rules.
 *
 * Fund tags on Asset accounts (Checking, cash/bank, and any account_type = asset)
 * are stored if the UI allows it, but they are ignored when computing fund balances.
 * Liability tags are also ignored. Only income, expense, and equity (Net Assets
 * WODR / WDR) lines change a fund:
 *
 *   credit on income or Net Assets – WDR  → increases the tagged fund
 *   debit  on expense                    → decreases the tagged fund
 *   debit  on Net Assets – WDR (release) → decreases the tagged fund
 *
 * Unified signed amount on those account types: credit +, debit −.
 */

/** Account types whose fund tags affect fund balances. */
const FUND_BALANCE_ACCOUNT_TYPES = ['income', 'expense', 'equity'];

/**
 * Whether a classic account_type participates in fund-balance math.
 */
function fundBalanceAffectsAccountType(?string $accountType): bool
{
    $t = strtolower(trim((string)$accountType));
    return in_array($t, FUND_BALANCE_ACCOUNT_TYPES, true);
}

/**
 * SQL IN-list of account types that affect fund balances.
 */
function fundBalanceAccountTypesSql(): string
{
    return "'income','expense','equity'";
}

/**
 * Predicate: the joined accounts row is a type that affects fund balances.
 * Requires the given alias to refer to `accounts`.
 */
function fundBalanceAccountTypePredicate(string $accountsAlias = 'a'): string
{
    return $accountsAlias . '.account_type IN (' . fundBalanceAccountTypesSql() . ')';
}

/**
 * Signed contribution of a tagged line to its fund (credit +, debit −).
 * Requires the given alias to refer to `transaction_lines`.
 */
function fundBalanceSignedAmountSql(string $linesAlias = 'tl'): string
{
    return "CASE WHEN {$linesAlias}.type = 'credit' THEN {$linesAlias}.amount ELSE -{$linesAlias}.amount END";
}

/**
 * SELECT a single fund's balance. Bind fund_id as the first parameter.
 * $dateClause is appended as-is (e.g. "AND td.transaction_date <= ?").
 *
 * Aliases: tl = transaction_lines, a = accounts, td = transaction_details.
 */
function fundBalanceSelectSql(string $dateClause = ''): string
{
    $signed = fundBalanceSignedAmountSql('tl');
    $types = fundBalanceAccountTypePredicate('a');
    $extra = trim($dateClause);
    $extra = $extra !== '' ? "\n          {$extra}" : '';

    return "SELECT COALESCE(SUM({$signed}), 0) AS balance
        FROM transaction_lines tl
        JOIN accounts a ON a.id = tl.account_id
        JOIN transaction_details td ON td.id = tl.transaction_detail_id
        WHERE tl.fund_id = ?
          AND {$types}{$extra}";
}

/**
 * Period inflows: credits on income / expense / equity lines tagged to the fund.
 * Bind: fund_id, date_from, date_to.
 */
function fundPeriodInflowsSelectSql(): string
{
    $types = fundBalanceAccountTypePredicate('a');

    return "SELECT COALESCE(SUM(tl.amount), 0) AS total
        FROM transaction_lines tl
        JOIN transaction_details td ON td.id = tl.transaction_detail_id
        JOIN accounts a ON a.id = tl.account_id
        WHERE tl.fund_id = ?
          AND {$types}
          AND tl.type = 'credit'
          AND td.transaction_date >= ? AND td.transaction_date <= ?";
}

/**
 * Period outflows: debits on income / expense / equity lines tagged to the fund.
 * Bind: fund_id, date_from, date_to.
 */
function fundPeriodOutflowsSelectSql(): string
{
    $types = fundBalanceAccountTypePredicate('a');

    return "SELECT COALESCE(SUM(tl.amount), 0) AS total
        FROM transaction_lines tl
        JOIN transaction_details td ON td.id = tl.transaction_detail_id
        JOIN accounts a ON a.id = tl.account_id
        WHERE tl.fund_id = ?
          AND {$types}
          AND tl.type = 'debit'
          AND td.transaction_date >= ? AND td.transaction_date <= ?";
}

/**
 * Compute one fund's balance as of a date (inclusive), or all dates when $asOf is null.
 * When $beforeDate is true, uses transaction_date < $asOf (beginning-of-period).
 */
function fundComputeBalance(mysqli $db, int $fundId, ?string $asOfDate = null, bool $beforeDate = false): float
{
    $dateClause = '';
    if ($asOfDate !== null && $asOfDate !== '') {
        $dateClause = $beforeDate
            ? 'AND td.transaction_date < ?'
            : 'AND td.transaction_date <= ?';
    }

    $sql = fundBalanceSelectSql($dateClause);
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare fund balance query: ' . $db->error);
    }

    if ($dateClause !== '') {
        $stmt->bind_param('is', $fundId, $asOfDate);
    } else {
        $stmt->bind_param('i', $fundId);
    }
    $stmt->execute();
    $balance = (float)($stmt->get_result()->fetch_assoc()['balance'] ?? 0);
    $stmt->close();

    return $balance;
}

/**
 * Human label for fund restriction type (WODR / WDR).
 */
function fundTypeLabel(?string $type): string
{
    $t = strtoupper(trim((string)$type));
    return match ($t) {
        'WDR' => 'WDR — With donor restrictions',
        'WODR' => 'WODR — Without donor restrictions',
        default => $t !== '' ? $t : '—',
    };
}

/**
 * Compact period activity line: n TXNs | +in | −out | Bal current
 */
function fundFormatActivityLine(int $txnCount, float $inflows, float $outflows, float $balance): string
{
    $txn = $txnCount === 1 ? '1 TXN' : $txnCount . ' TXNs';
    return $txn
        . ' | +' . number_format($inflows, 2)
        . ' | −' . number_format($outflows, 2)
        . ' | Bal ' . number_format($balance, 2);
}

/**
 * Active (or all) fund records for the Funds screen.
 *
 * @return list<array<string,mixed>>
 */
function fundFetchRecords(mysqli $db, bool $includeArchived = false): array
{
    $sql = 'SELECT id, name, code, type, description, purpose, donor_reference, is_active, archived
            FROM funds';
    if (!$includeArchived) {
        $sql .= ' WHERE archived = FALSE';
    }
    $sql .= " ORDER BY FIELD(type, 'WDR', 'WODR'), name, id";

    $rows = [];
    $res = $db->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->close();
    }
    return $rows;
}

/**
 * Fund balances keyed by fund_id as of a date (inclusive), or strictly before when $beforeDate.
 *
 * @return array<int,float>
 */
function fundBalancesMap(mysqli $db, ?string $asOfDate = null, bool $beforeDate = false): array
{
    $dateClause = '';
    if ($asOfDate !== null && $asOfDate !== '') {
        $dateClause = $beforeDate
            ? 'AND td.transaction_date < ?'
            : 'AND td.transaction_date <= ?';
    }

    $signed = fundBalanceSignedAmountSql('tl');
    $types = fundBalanceAccountTypePredicate('a');
    $sql = "SELECT tl.fund_id, COALESCE(SUM({$signed}), 0) AS balance
            FROM transaction_lines tl
            JOIN accounts a ON a.id = tl.account_id
            JOIN transaction_details td ON td.id = tl.transaction_detail_id
            WHERE tl.fund_id IS NOT NULL
              AND {$types}
              {$dateClause}
            GROUP BY tl.fund_id";

    $map = [];
    if ($dateClause !== '') {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare fund balances map: ' . $db->error);
        }
        $stmt->bind_param('s', $asOfDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $map[(int)$row['fund_id']] = (float)$row['balance'];
        }
        $stmt->close();
        return $map;
    }

    $res = $db->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $map[(int)$row['fund_id']] = (float)$row['balance'];
        }
        $res->close();
    }
    return $map;
}

/**
 * Period inflows / outflows / distinct transaction counts keyed by fund_id.
 * Only income, expense, and equity lines with fund tags count.
 *
 * @return array<int,array{inflows:float,outflows:float,txn_count:int}>
 */
function fundPeriodActivityMap(mysqli $db, string $dateFrom, string $dateTo): array
{
    $types = fundBalanceAccountTypePredicate('a');
    $sql = "SELECT tl.fund_id,
                   COALESCE(SUM(CASE WHEN tl.type = 'credit' THEN tl.amount ELSE 0 END), 0) AS inflows,
                   COALESCE(SUM(CASE WHEN tl.type = 'debit' THEN tl.amount ELSE 0 END), 0) AS outflows,
                   COUNT(DISTINCT td.id) AS txn_count
            FROM transaction_lines tl
            JOIN accounts a ON a.id = tl.account_id
            JOIN transaction_details td ON td.id = tl.transaction_detail_id
            WHERE tl.fund_id IS NOT NULL
              AND {$types}
              AND td.transaction_date >= ?
              AND td.transaction_date <= ?
            GROUP BY tl.fund_id";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare fund period activity: ' . $db->error);
    }
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $res = $stmt->get_result();
    $map = [];
    while ($row = $res->fetch_assoc()) {
        $map[(int)$row['fund_id']] = [
            'inflows' => (float)$row['inflows'],
            'outflows' => (float)$row['outflows'],
            'txn_count' => (int)$row['txn_count'],
        ];
    }
    $stmt->close();
    return $map;
}

/**
 * Period summaries for the Funds screen (starting, in, out, current, txn count).
 *
 * @return list<array<string,mixed>>
 */
function fundBuildPeriodSummaries(mysqli $db, string $dateFrom, string $dateTo, bool $includeArchived = false): array
{
    $funds = fundFetchRecords($db, $includeArchived);
    $starting = fundBalancesMap($db, $dateFrom, true);
    $activity = fundPeriodActivityMap($db, $dateFrom, $dateTo);
    $out = [];

    foreach ($funds as $f) {
        $id = (int)$f['id'];
        $startBal = (float)($starting[$id] ?? 0.0);
        $in = (float)($activity[$id]['inflows'] ?? 0.0);
        $outflow = (float)($activity[$id]['outflows'] ?? 0.0);
        $txn = (int)($activity[$id]['txn_count'] ?? 0);
        $bal = $startBal + $in - $outflow;
        $description = trim((string)($f['description'] ?? ''));
        $purpose = trim((string)($f['purpose'] ?? ''));
        $notes = $description !== '' ? $description : $purpose;

        $out[] = [
            'id' => $id,
            'name' => (string)$f['name'],
            'code' => (string)($f['code'] ?? ''),
            'type' => (string)$f['type'],
            'type_label' => fundTypeLabel((string)$f['type']),
            'description' => $description,
            'purpose' => $purpose,
            'notes' => $notes,
            'donor_reference' => (string)($f['donor_reference'] ?? ''),
            'archived' => !empty($f['archived']),
            'starting_balance' => $startBal,
            'inflows' => $in,
            'outflows' => $outflow,
            'txn_count' => $txn,
            'current_balance' => $bal,
            'activity_line' => fundFormatActivityLine($txn, $in, $outflow, $bal),
        ];
    }

    return $out;
}

/**
 * Transactions assigned to a fund in a date range (fund-affecting lines only).
 *
 * @return list<array<string,mixed>>
 */
function fundPeriodTransactionRows(mysqli $db, int $fundId, string $dateFrom, string $dateTo): array
{
    if ($fundId <= 0) {
        return [];
    }

    $types = fundBalanceAccountTypePredicate('a');
    $signed = fundBalanceSignedAmountSql('tl');
    $sql = "SELECT td.id,
                   td.transaction_date,
                   td.reference_number,
                   td.check_number,
                   td.pay_to,
                   td.description,
                   td.status,
                   COALESCE(SUM(CASE WHEN tl.type = 'credit' THEN tl.amount ELSE 0 END), 0) AS inflow,
                   COALESCE(SUM(CASE WHEN tl.type = 'debit' THEN tl.amount ELSE 0 END), 0) AS outflow,
                   COALESCE(SUM({$signed}), 0) AS net
            FROM transaction_lines tl
            JOIN accounts a ON a.id = tl.account_id
            JOIN transaction_details td ON td.id = tl.transaction_detail_id
            WHERE tl.fund_id = ?
              AND {$types}
              AND td.transaction_date >= ?
              AND td.transaction_date <= ?
            GROUP BY td.id, td.transaction_date, td.reference_number, td.check_number,
                     td.pay_to, td.description, td.status
            ORDER BY td.transaction_date DESC, td.id DESC";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare fund period transactions: ' . $db->error);
    }
    $stmt->bind_param('iss', $fundId, $dateFrom, $dateTo);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $inflow = (float)$row['inflow'];
        $outflow = (float)$row['outflow'];
        $net = (float)$row['net'];
        $direction = 'in';
        if ($inflow > 0.004999 && $outflow > 0.004999) {
            $direction = 'both';
        } elseif ($outflow > 0.004999 && $inflow <= 0.004999) {
            $direction = 'out';
        } elseif ($net < 0) {
            $direction = 'out';
        }

        $ref = trim((string)($row['reference_number'] ?? ''));
        if ($ref === '') {
            $check = trim((string)($row['check_number'] ?? ''));
            $ref = $check !== '' ? $check : '—';
        }
        $payTo = trim((string)($row['pay_to'] ?? ''));
        $desc = trim((string)($row['description'] ?? ''));
        $payee = $payTo !== '' ? $payTo : ($desc !== '' ? $desc : '—');

        $rows[] = [
            'id' => (int)$row['id'],
            'date' => (string)$row['transaction_date'],
            'ref' => $ref,
            'pay_to' => $payee,
            'description' => $desc,
            'status' => (string)($row['status'] ?? ''),
            'inflow' => $inflow,
            'outflow' => $outflow,
            'net' => $net,
            'amount' => abs($net),
            'direction' => $direction,
        ];
    }
    $stmt->close();
    return $rows;
}

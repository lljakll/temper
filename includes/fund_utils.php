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

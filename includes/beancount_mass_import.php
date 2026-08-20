<?php
/**
 * TEMPORARY Beancount Mass Import module.
 *
 * Self-contained so it can be deleted after historical data is loaded:
 *   - this file
 *   - pages/ledger_import.php
 *   - Ledger → Import nav entries
 *   - permission page.ledger.mass_import
 *
 * Does not alter the single-transaction Import-from-Text in the Add modal.
 * Write/duplicate helpers operate on a generic transaction DTO so a future
 * definition-file importer can reuse them without Beancount parsing.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

const BEANCOUNT_MASS_IMPORT_PERMISSION = 'page.ledger.mass_import';

const BEANCOUNT_MASS_IMPORT_MATCH_AUTO = 0.86;
const BEANCOUNT_MASS_IMPORT_MATCH_AUTO_GAP = 0.10;
const BEANCOUNT_MASS_IMPORT_MATCH_SUGGEST = 0.42;
const BEANCOUNT_MASS_IMPORT_MATCH_MAX_SUGGEST = 8;
const BEANCOUNT_MASS_IMPORT_DUPLICATE_THRESHOLD = 70.0;
/** Smaller/larger ratio below this (and ≥ $1 apart) is a substantial amount mismatch. */
const BEANCOUNT_MASS_IMPORT_AMOUNT_VETO_RATIO = 0.50;
const BEANCOUNT_MASS_IMPORT_AMOUNT_VETO_MIN_DIFF = 1.00;

/**
 * @return list<string>
 */
function beancountMassImportAllowedRoleNames(): array
{
    return ['Administrator', 'Treasurer', 'Finance Manager', 'Archivist'];
}

function beancountMassImportUserCanAccess(?array $acl): bool
{
    if (!$acl) {
        return false;
    }
    $perms = $acl['permissions'] ?? [];
    if (function_exists('permissionSetAllows')) {
        if (permissionSetAllows($perms, BEANCOUNT_MASS_IMPORT_PERMISSION)) {
            return true;
        }
        if (permissionSetAllows($perms, '*')) {
            return true;
        }
    }
    $active = (string)($acl['active_role_name'] ?? $acl['role_name'] ?? '');
    return in_array($active, beancountMassImportAllowedRoleNames(), true);
}

/**
 * @return array ACL row
 */
function beancountMassImportRequireAccess(mysqli $db): array
{
    requireLogin($db);
    $user = getCurrentUser();
    if (!$user) {
        denyUnauthenticatedAccess();
    }
    $acl = loadUserAcl($db, (int)$user['id']);
    if (!$acl || !beancountMassImportUserCanAccess($acl)) {
        denyPermission('You do not have permission to use Mass Import.');
    }
    return $acl;
}

/**
 * Token expansions used by fuzzy account/fund matching (same set as Import-from-Text).
 *
 * @return array<string, string>
 */
function beancountMassImportTokenExpandMap(): array
{
    return [
        'acct' => 'account',
        'accts' => 'accounts',
        'chkg' => 'checking',
        'chk' => 'checking',
        'svgs' => 'savings',
        'exp' => 'expense',
        'rec' => 'receivable',
        'liab' => 'liability',
        'ap' => 'accounts payable',
        'ar' => 'accounts receivable',
    ];
}

function beancountMassImportCollapseKey(string $s): string
{
    $s = strtolower($s);
    $s = str_replace('&', ' and ', $s);
    $s = preg_replace("/[''`´]/u", '', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

/**
 * @return list<string>
 */
function beancountMassImportStripPath(string $accountName): array
{
    $raw = trim($accountName);
    $variants = [];
    $push = static function (string $v) use (&$variants): void {
        $t = trim($v);
        if ($t !== '' && !in_array($t, $variants, true)) {
            $variants[] = $t;
        }
    };
    $push($raw);
    if (str_contains($raw, ':')) {
        $parts = array_values(array_filter(array_map('trim', explode(':', $raw)), static fn($p) => $p !== ''));
        if ($parts !== []) {
            $push($parts[count($parts) - 1]);
        }
    }
    return $variants !== [] ? $variants : [$raw];
}

/**
 * @return list<string>
 */
function beancountMassImportExpandQuery(string $collapsed): array
{
    $tokens = array_values(array_filter(explode(' ', $collapsed), static fn($t) => $t !== ''));
    if ($tokens === []) {
        return [];
    }
    $map = beancountMassImportTokenExpandMap();
    $expanded = implode(' ', array_map(static fn($t) => $map[$t] ?? $t, $tokens));
    $variants = [$collapsed];
    if ($expanded !== $collapsed) {
        $variants[] = $expanded;
    }
    return $variants;
}

function beancountMassImportScoreStringPair(string $query, string $target): float
{
    if ($query === '' || $target === '') {
        return 0.0;
    }
    if ($query === $target) {
        return 1.0;
    }
    $qLen = strlen($query);
    $tLen = strlen($target);
    if ($qLen < 2) {
        return 0.0;
    }

    if (str_contains($target, $query) || str_contains($query, $target)) {
        $ratio = min($qLen, $tLen) / max($qLen, $tLen);
        if (str_starts_with($target, $query) || str_starts_with($query, $target)) {
            return 0.78 + 0.20 * $ratio;
        }
        return 0.70 + 0.22 * $ratio;
    }

    $qTok = array_values(array_filter(explode(' ', $query), static fn($t) => $t !== ''));
    $tTok = array_values(array_filter(explode(' ', $target), static fn($t) => $t !== ''));
    if ($qTok !== [] && $tTok !== []) {
        $matched = 0;
        $tokenScoreSum = 0.0;
        $used = [];
        foreach ($qTok as $qt) {
            $best = 0.0;
            $bestI = -1;
            foreach ($tTok as $i => $tt) {
                if (isset($used[$i])) {
                    continue;
                }
                $s = 0.0;
                if ($qt === $tt) {
                    $s = 1.0;
                } elseif (str_starts_with($tt, $qt) || str_starts_with($qt, $tt)) {
                    $s = 0.9 * min(strlen($qt), strlen($tt)) / max(strlen($qt), strlen($tt));
                } else {
                    $maxTok = max(strlen($qt), strlen($tt));
                    $sim = $maxTok > 0 ? 1 - (levenshtein($qt, $tt) / $maxTok) : 0.0;
                    if ($sim >= 0.75) {
                        $s = $sim;
                    }
                }
                if ($s > $best) {
                    $best = $s;
                    $bestI = $i;
                }
            }
            if ($best >= 0.75 && $bestI >= 0) {
                $used[$bestI] = true;
                $matched++;
                $tokenScoreSum += $best;
            }
        }
        if ($matched === count($qTok)) {
            $qJoin = implode('', $qTok);
            $tJoin = implode('', $tTok);
            $coverage = strlen($qJoin) / max(strlen($tJoin), 1);
            $avg = $tokenScoreSum / max(count($qTok), 1);
            return min(0.98, 0.72 + 0.18 * $avg + 0.08 * min(1.0, $coverage));
        }
        if ($matched >= (int)ceil(count($qTok) * 0.6) && $matched >= 1) {
            $avg = $tokenScoreSum / max(count($qTok), 1);
            return 0.45 + 0.30 * $avg * ($matched / count($qTok));
        }
    }

    $maxLen = max($qLen, $tLen);
    if ($maxLen === 0) {
        return 0.0;
    }
    $sim = 1 - (levenshtein($query, $target) / $maxLen);
    if ($sim >= 0.72) {
        return $sim * 0.92;
    }
    return 0.0;
}

/**
 * @param array{name?:string,coa_number?:string,code?:string} $item
 */
function beancountMassImportScoreLookupItem(string $rawName, array $item, bool $alsoCode = false): float
{
    $rawVariants = beancountMassImportStripPath($rawName);
    $queries = [];
    foreach ($rawVariants as $v) {
        foreach (beancountMassImportExpandQuery(beancountMassImportCollapseKey($v)) as $q) {
            if ($q !== '' && !in_array($q, $queries, true)) {
                $queries[] = $q;
            }
        }
    }
    $targets = [];
    $addTarget = static function (string $v) use (&$targets): void {
        $k = beancountMassImportCollapseKey($v);
        if ($k !== '' && !in_array($k, $targets, true)) {
            $targets[] = $k;
        }
    };
    $addTarget((string)($item['name'] ?? ''));
    $name = (string)($item['name'] ?? '');
    if ($name !== '' && str_contains($name, ':')) {
        $parts = array_values(array_filter(array_map('trim', explode(':', $name)), static fn($p) => $p !== ''));
        if (count($parts) > 1) {
            $addTarget($parts[count($parts) - 1]);
            $addTarget($parts[0]);
        }
    }
    if (!empty($item['coa_number'])) {
        $addTarget((string)$item['coa_number']);
        if ($name !== '') {
            $addTarget((string)$item['coa_number'] . ' ' . $name);
        }
    }
    if ($alsoCode && !empty($item['code'])) {
        $addTarget((string)$item['code']);
    }

    $best = 0.0;
    foreach ($queries as $q) {
        foreach ($targets as $t) {
            $s = beancountMassImportScoreStringPair($q, $t);
            if ($s > $best) {
                $best = $s;
            }
        }
    }
    return $best;
}

/**
 * @param list<array> $list
 * @return array{status:string,ok:bool,item:?array,score:float,fuzzy:bool,candidates:list<array>,error?:string}
 */
function beancountMassImportMatchLookupName(array $list, string $rawName, bool $alsoCode = false, string $label = 'account'): array
{
    $scored = [];
    foreach ($list as $item) {
        $score = beancountMassImportScoreLookupItem($rawName, $item, $alsoCode);
        if ($score > 0) {
            $scored[] = ['item' => $item, 'score' => $score];
        }
    }
    usort($scored, static function ($a, $b) {
        if ($b['score'] !== $a['score']) {
            return $a['score'] < $b['score'] ? 1 : -1;
        }
        return strcmp((string)($a['item']['name'] ?? ''), (string)($b['item']['name'] ?? ''));
    });
    $best = $scored[0] ?? null;
    $displayName = trim($rawName);
    $qKey = beancountMassImportCollapseKey(beancountMassImportStripPath($rawName)[0] ?? $displayName);
    $tooShort = strlen($qKey) < 3;
    $competitor = null;
    foreach ($scored as $i => $s) {
        if ($i > 0 && $s['score'] >= BEANCOUNT_MASS_IMPORT_MATCH_SUGGEST) {
            $competitor = $s;
            break;
        }
    }
    $candidates = [];
    foreach ($scored as $s) {
        if ($s['score'] >= BEANCOUNT_MASS_IMPORT_MATCH_SUGGEST) {
            $candidates[] = $s;
            if (count($candidates) >= BEANCOUNT_MASS_IMPORT_MATCH_MAX_SUGGEST) {
                break;
            }
        }
    }

    $auto = false;
    if ($best) {
        $gap = $competitor ? ($best['score'] - $competitor['score']) : 1.0;
        $uniqueExact = $best['score'] >= 0.999 && (!$competitor || $competitor['score'] < 0.999);
        $confident = !$tooShort && $best['score'] >= BEANCOUNT_MASS_IMPORT_MATCH_AUTO && $gap >= BEANCOUNT_MASS_IMPORT_MATCH_AUTO_GAP;
        $uniqueStrong = !$tooShort && $best['score'] >= BEANCOUNT_MASS_IMPORT_MATCH_AUTO && !$competitor;
        $auto = $uniqueExact || $confident || $uniqueStrong;
    }

    if ($auto) {
        return [
            'status' => 'matched',
            'ok' => true,
            'item' => $best['item'],
            'score' => $best['score'],
            'fuzzy' => $best['score'] < 0.999,
            'candidates' => $candidates,
        ];
    }

    $ambiguous = (bool)($best && $competitor
        && ($best['score'] - $competitor['score']) < BEANCOUNT_MASS_IMPORT_MATCH_AUTO_GAP
        && $best['score'] >= BEANCOUNT_MASS_IMPORT_MATCH_SUGGEST);

    $error = '';
    if (!$best || $best['score'] < BEANCOUNT_MASS_IMPORT_MATCH_SUGGEST) {
        $error = 'Unknown ' . $label . ' "' . $displayName . '". Names must match existing records in the system.';
    } elseif ($ambiguous) {
        $names = [];
        foreach ($scored as $s) {
            if ($s['score'] >= BEANCOUNT_MASS_IMPORT_MATCH_SUGGEST
                && $s['score'] >= $best['score'] - BEANCOUNT_MASS_IMPORT_MATCH_AUTO_GAP) {
                $names[] = (string)($s['item']['name'] ?? '');
            }
        }
        $error = 'Ambiguous ' . $label . ' "' . $displayName . '" — matches: ' . implode(', ', $names)
            . '. Use the exact name from the chart.';
    } else {
        $error = 'Uncertain ' . $label . ' "' . $displayName . '" (closest: '
            . ($best ? (string)($best['item']['name'] ?? '') : '') . ').';
    }

    return [
        'status' => $ambiguous ? 'ambiguous' : 'unresolved',
        'ok' => false,
        'item' => null,
        'score' => $best ? (float)$best['score'] : 0.0,
        'fuzzy' => true,
        'candidates' => $candidates,
        'error' => $error,
    ];
}

/**
 * Chart / fund / budget lookups for parse + review UI.
 *
 * @return array{accounts:list<array>,funds:list<array>,budgets:list<array>}
 */
function beancountMassImportLoadLookups(mysqli $db): array
{
    $accounts = [];
    $ar = $db->query(
        "SELECT a.id, a.name, a.normal_balance, a.coa_number, a.account_type,
                a.natural_category_id, a.functional_category_id,
                COALESCE(nc.name, '') AS natural_name,
                COALESCE(fc.name, '') AS functional_name
         FROM accounts a
         LEFT JOIN natural_categories nc ON nc.id = a.natural_category_id
         LEFT JOIN functional_categories fc ON fc.id = a.functional_category_id
         WHERE a.archived = FALSE
         ORDER BY (a.coa_number IS NULL OR a.coa_number = '') ASC, a.coa_number ASC, a.name ASC, a.id ASC"
    );
    if ($ar) {
        while ($a = $ar->fetch_assoc()) {
            $natId = isset($a['natural_category_id']) ? (int)$a['natural_category_id'] : 0;
            $funId = isset($a['functional_category_id']) ? (int)$a['functional_category_id'] : 0;
            $accounts[] = [
                'id' => (int)$a['id'],
                'name' => (string)$a['name'],
                'normal_balance' => (string)$a['normal_balance'],
                'account_type' => strtolower(trim((string)($a['account_type'] ?? ''))),
                'coa_number' => trim((string)($a['coa_number'] ?? '')),
                'natural_category_id' => $natId > 0 ? $natId : '',
                'functional_category_id' => $funId > 0 ? $funId : '',
                'natural_name' => ($a['natural_name'] ?? '') !== '' ? (string)$a['natural_name'] : '—',
                'functional_name' => ($a['functional_name'] ?? '') !== '' ? (string)$a['functional_name'] : '—',
            ];
        }
        $ar->close();
    }

    $funds = [];
    $fr = $db->query('SELECT id, name, code FROM funds WHERE is_active = TRUE AND archived = FALSE ORDER BY name');
    if ($fr) {
        while ($f = $fr->fetch_assoc()) {
            $funds[] = [
                'id' => (int)$f['id'],
                'name' => (string)$f['name'],
                'code' => (string)($f['code'] ?? ''),
            ];
        }
        $fr->close();
    }

    $budgets = [];
    if (function_exists('budgetFetchTransactionOptions')) {
        $budgets = budgetFetchTransactionOptions($db);
    }

    return [
        'accounts' => $accounts,
        'funds' => $funds,
        'budgets' => $budgets,
    ];
}

/**
 * @return array{quotes:list<string>,unquoted:string}
 */
function beancountMassImportParseQuotedTokens(string $rest): array
{
    $quotes = [];
    $unquoted = '';
    $i = 0;
    $len = strlen($rest);
    while ($i < $len) {
        $ch = $rest[$i];
        if ($ch === '"' || $ch === "'") {
            $q = $ch;
            $i++;
            $buf = '';
            while ($i < $len && $rest[$i] !== $q) {
                if ($rest[$i] === '\\' && $i + 1 < $len) {
                    $buf .= $rest[$i + 1];
                    $i += 2;
                    continue;
                }
                $buf .= $rest[$i];
                $i++;
            }
            if ($i < $len && $rest[$i] === $q) {
                $i++;
            }
            $quotes[] = $buf;
        } else {
            $unquoted .= $ch;
            $i++;
        }
    }
    return ['quotes' => $quotes, 'unquoted' => trim($unquoted)];
}

function beancountMassImportParseAmountToken(string $raw): ?float
{
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    $s = preg_replace('/^(USD|usd|\$)\s*/', '', $s) ?? $s;
    $s = preg_replace('/\s*(USD|usd|\$)$/', '', $s) ?? $s;
    $s = str_replace(',', '', trim($s));
    if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) {
        return null;
    }
    $n = (float)$s;
    if (!is_finite($n) || abs($n) < 0.0000001) {
        return null;
    }
    return $n;
}

function beancountMassImportStripMetaQuotes(string $val): string
{
    $v = trim($val);
    if (preg_match('/^["\'](.*)["\']$/s', $v, $m)) {
        $v = $m[1];
    }
    return trim($v);
}

/**
 * @return array{fundHint:string,descText:string}
 */
function beancountMassImportSplitFundAndDesc(string $comment): array
{
    $descText = trim($comment);
    if ($descText === '') {
        return ['fundHint' => '', 'descText' => ''];
    }
    if (preg_match('/fund\s*:\\s*(.+)$/i', $descText, $m)) {
        $fundHint = trim($m[1]);
        $fundHint = preg_replace('/^["\']|["\']$/', '', $fundHint) ?? $fundHint;
        $descText = trim(preg_replace('/;?\\s*fund\\s*:\\s*.+$/i', '', $descText) ?? $descText);
        return ['fundHint' => trim($fundHint), 'descText' => $descText];
    }
    return ['fundHint' => '', 'descText' => $descText];
}

function beancountMassImportIsDirectiveLine(string $trimmed): bool
{
    if (preg_match('/^(option|plugin|include|pushtag|poptag|pushmeta|popmeta)\\b/i', $trimmed)) {
        return true;
    }
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}\\s+(open|close|balance|pad|price|event|note|document|query|custom|commodity)\\b/i', $trimmed)) {
        return true;
    }
    return false;
}

function beancountMassImportIsTxnHeader(string $trimmed): bool
{
    if ($trimmed === '' || str_starts_with($trimmed, ';') || beancountMassImportIsDirectiveLine($trimmed)) {
        return false;
    }
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}\\s+(\\*|\\!|txn)(?:\\s|$)/i', $trimmed)) {
        return true;
    }
    // Date + quoted payee without flag (loose paste)
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}\\s+"/', $trimmed)) {
        return true;
    }
    return false;
}

/**
 * Split pasted text into per-transaction line arrays.
 *
 * @return list<list<string>>
 */
function beancountMassImportSplitEntries(string $text): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = explode("\n", $raw);
    $chunks = [];
    $current = [];
    $started = false;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (beancountMassImportIsDirectiveLine($trimmed)) {
            continue;
        }
        if (beancountMassImportIsTxnHeader($trimmed)) {
            if ($started && $current !== []) {
                $chunks[] = $current;
                $current = [];
            }
            $started = true;
            $current[] = $line;
            continue;
        }
        if ($started) {
            $current[] = $line;
        }
    }
    if ($started && $current !== []) {
        $chunks[] = $current;
    }
    return $chunks;
}

function beancountMassImportValidDate(string $dateStr): bool
{
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dateStr)) {
        return false;
    }
    [$yy, $mm, $dd] = array_map('intval', explode('-', $dateStr));
    return checkdate($mm, $dd, $yy);
}

/**
 * Parse one Beancount-style transaction (already split from the block).
 *
 * @param list<string> $lines
 * @param array{accounts:list<array>,funds:list<array>} $lookups
 * @return array<string, mixed>
 */
function beancountMassImportParseOne(array $lines, array $lookups, string $queueId): array
{
    $errors = [];
    $warnings = [];
    $headerIdx = -1;
    $headerMatch = null;
    $txnHeaderRe = '/^(\\d{4}-\\d{2}-\\d{2})\\s+(\\*|\\!|txn)(?:\\s+(.*))?$/i';

    foreach ($lines as $i => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, ';') || beancountMassImportIsDirectiveLine($trimmed)) {
            continue;
        }
        if (preg_match($txnHeaderRe, $trimmed, $m)) {
            $headerIdx = $i;
            $headerMatch = $m;
            break;
        }
    }

    if ($headerMatch === null) {
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, ';') || beancountMassImportIsDirectiveLine($trimmed)) {
                continue;
            }
            if (preg_match('/^(\\d{4}-\\d{2}-\\d{2})(?:\\s+(.*))?$/', $trimmed, $m2)) {
                $headerIdx = $i;
                $headerMatch = [$m2[0], $m2[1], '*', $m2[2] ?? ''];
                $warnings[] = 'Missing flag (* or !); treated as complete (*).';
                break;
            }
            $errors[] = 'Expected transaction header starting with YYYY-MM-DD (e.g. 2026-03-15 * "Payee" "Description").';
            break;
        }
    }

    $empty = [
        'queue_id' => $queueId,
        'source_text' => implode("\n", $lines),
        'transaction_date' => '',
        'reference_number' => '',
        'check_number' => '',
        'pay_to' => '',
        'description' => '',
        'budget_id' => '',
        'lines' => [],
        'warnings' => $warnings,
        'errors' => $errors !== [] ? $errors : ['No transaction header found.'],
        'balanced' => false,
        'debit_total' => 0.0,
        'credit_total' => 0.0,
        'parse_ok' => false,
        'ref_auto' => false,
    ];

    if ($headerMatch === null) {
        return $empty;
    }

    $dateStr = $headerMatch[1];
    if (!beancountMassImportValidDate($dateStr)) {
        $errors[] = 'Invalid calendar date "' . $dateStr . '". Use YYYY-MM-DD.';
    }

    $parsed = beancountMassImportParseQuotedTokens((string)($headerMatch[3] ?? ''));
    $payTo = count($parsed['quotes']) >= 1 ? $parsed['quotes'][0] : '';
    $description = count($parsed['quotes']) >= 2 ? $parsed['quotes'][1] : '';
    if ($payTo === '' && $description === '' && $parsed['unquoted'] !== '') {
        $description = $parsed['unquoted'];
    }

    $descParts = [];
    $headerRaw = $lines[$headerIdx] ?? '';
    $headerSemi = strpos($headerRaw, ';');
    if ($headerSemi !== false) {
        $hc = trim(substr($headerRaw, $headerSemi + 1));
        if ($hc !== '') {
            $descParts[] = $hc;
        }
    }

    $referenceVal = '';
    $checkVal = '';
    $postings = [];
    $amountRe = '/(?:USD|\\$)?\\s*(-?\\d{1,3}(?:,\\d{3})+(?:\\.\\d+)?|-?\\d+(?:\\.\\d+)?)\\s*(?:USD|\\$)?/i';

    $count = count($lines);
    for ($i = $headerIdx + 1; $i < $count; $i++) {
        $lineNo = $i + 1;
        $line = $lines[$i];
        if (trim($line) === '') {
            continue;
        }
        if (beancountMassImportIsTxnHeader(trim($line))) {
            break;
        }

        if (str_starts_with(trim($line), ';')) {
            $c = trim(substr(trim($line), 1));
            if ($c !== '') {
                $split = beancountMassImportSplitFundAndDesc($c);
                if ($split['descText'] !== '') {
                    $descParts[] = $split['descText'];
                }
            }
            continue;
        }

        $comment = '';
        $semi = strpos($line, ';');
        if ($semi !== false) {
            $comment = trim(substr($line, $semi + 1));
            $line = substr($line, 0, $semi);
        }

        $trimmed = trim($line);
        if ($trimmed === '') {
            if ($comment !== '') {
                $split = beancountMassImportSplitFundAndDesc($comment);
                if ($split['descText'] !== '') {
                    $descParts[] = $split['descText'];
                }
            }
            continue;
        }

        if (preg_match('/^([A-Za-z_][A-Za-z0-9_-]*)\\s*:\\s*(.*)$/', $trimmed, $metaLineM)) {
            $key = strtolower($metaLineM[1]);
            $rhs = $metaLineM[2];
            $rhsTrim = trim((string)$rhs);
            $rhsQuoted = preg_match('/^["\']/', $rhsTrim) === 1;
            $rhsHasAccountAmount = !$rhsQuoted && (
                preg_match('/\\S.+\\s+(?:USD|\\$)?\\s*-?\\d/', $rhs) === 1
                || preg_match('/\\s+(?:USD|\\$)?\\s*-?\\d.+\\s+(?:USD|\\$)?\\s*-?\\d/', $trimmed) === 1
            );
            $rhsIsSimpleValue = $rhsTrim === ''
                || $rhsQuoted
                || preg_match('/^-?\\d[\\d,]*(?:\\.\\d+)?\\s*(USD|\\$)?$/i', $rhsTrim) === 1
                || preg_match('/\\s+-?\\d/', $rhsTrim) !== 1;
            if (!$rhsHasAccountAmount && $rhsIsSimpleValue) {
                if (in_array($key, ['reference', 'ref', 'sequence'], true)) {
                    $referenceVal = beancountMassImportStripMetaQuotes($rhsTrim);
                } elseif ($key === 'check') {
                    $checkVal = beancountMassImportStripMetaQuotes($rhsTrim);
                }
                if ($comment !== '') {
                    $split = beancountMassImportSplitFundAndDesc($comment);
                    if ($split['descText'] !== '') {
                        $descParts[] = $split['descText'];
                    }
                }
                continue;
            }
        }

        if (!preg_match_all($amountRe, $trimmed, $amtMatches, PREG_OFFSET_CAPTURE)) {
            $errors[] = 'Line ' . $lineNo . ': no amount found. Expected e.g. "Bank Account  -87.43".';
            continue;
        }
        $lastAmt = $amtMatches[0][count($amtMatches[0]) - 1];
        $amountRaw = $lastAmt[0];
        $amountIdx = $lastAmt[1];
        $amount = beancountMassImportParseAmountToken($amountRaw);
        if ($amount === null) {
            $errors[] = 'Line ' . $lineNo . ': invalid or zero amount "' . trim($amountRaw) . '".';
            continue;
        }

        $accountName = trim(substr($trimmed, 0, $amountIdx));
        $accountName = trim(preg_replace('/\\s*\\{[^}]*\\}\\s*$/', '', $accountName) ?? $accountName);
        if ($accountName === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*\\s*:$/', $accountName)) {
            if ($accountName === '') {
                $errors[] = 'Line ' . $lineNo . ': missing account name before amount.';
            }
            continue;
        }

        $split = beancountMassImportSplitFundAndDesc($comment);
        if ($split['descText'] !== '') {
            $descParts[] = $split['descText'];
        }

        $postings[] = [
            'lineNo' => $lineNo,
            'accountName' => $accountName,
            'amount' => $amount,
            'fundHint' => $split['fundHint'],
        ];
    }

    if (count($postings) < 2) {
        $errors[] = count($postings) === 0
            ? 'No posting lines found. Add at least two lines with account and amount under the header.'
            : 'Only ' . count($postings) . ' posting found. Double-entry requires at least 2 lines.';
    }

    $outLines = [];
    $sum = 0.0;
    $debitTotal = 0.0;
    $creditTotal = 0.0;
    foreach ($postings as $p) {
        $accRes = beancountMassImportMatchLookupName($lookups['accounts'] ?? [], $p['accountName'], false, 'account');
        $fundId = '';
        $fundName = '';
        if ($p['fundHint'] !== '') {
            $fRes = beancountMassImportMatchLookupName($lookups['funds'] ?? [], $p['fundHint'], true, 'fund');
            if (!empty($fRes['ok']) && is_array($fRes['item'])) {
                $fundId = (int)$fRes['item']['id'];
                $fundName = (string)($fRes['item']['name'] ?? '');
                if (!empty($fRes['fuzzy'])) {
                    $warnings[] = 'Fund "' . $p['fundHint'] . '" matched as "' . $fundName . '" (fuzzy).';
                }
            } else {
                $warnings[] = 'Line ' . $p['lineNo'] . ': ' . ($fRes['error'] ?? 'Unknown fund') . ' Pick a fund in the review panel.';
            }
        }

        $sum += $p['amount'];
        $type = $p['amount'] > 0 ? 'debit' : 'credit';
        $abs = round(abs($p['amount']), 2);
        if ($type === 'debit') {
            $debitTotal += $abs;
        } else {
            $creditTotal += $abs;
        }

        $accountId = '';
        $accountMatchedName = '';
        $natId = '';
        $funId = '';
        $natName = '—';
        $funName = '—';
        if (!empty($accRes['ok']) && is_array($accRes['item'])) {
            $accountId = (int)$accRes['item']['id'];
            $accountMatchedName = (string)($accRes['item']['name'] ?? '');
            $natId = $accRes['item']['natural_category_id'] ?? '';
            $funId = $accRes['item']['functional_category_id'] ?? '';
            $natName = (string)($accRes['item']['natural_name'] ?? '—');
            $funName = (string)($accRes['item']['functional_name'] ?? '—');
            if (!empty($accRes['fuzzy'])) {
                $warnings[] = 'Account "' . $p['accountName'] . '" matched as "' . $accountMatchedName . '" (fuzzy).';
            }
        } else {
            $errors[] = 'Line ' . $p['lineNo'] . ': ' . ($accRes['error'] ?? 'Unknown account');
        }

        $cand = [];
        foreach ($accRes['candidates'] ?? [] as $c) {
            $cand[] = [
                'id' => (int)($c['item']['id'] ?? 0),
                'name' => (string)($c['item']['name'] ?? ''),
                'coa_number' => (string)($c['item']['coa_number'] ?? ''),
                'score' => (float)($c['score'] ?? 0),
            ];
        }

        $outLines[] = [
            'account_id' => $accountId,
            'account_name_raw' => $p['accountName'],
            'account_name' => $accountMatchedName,
            'fund_id' => $fundId,
            'fund_hint' => $p['fundHint'],
            'fund_name' => $fundName,
            'amount' => $abs,
            'type' => $type,
            'natural_category_id' => $natId,
            'functional_category_id' => $funId,
            'natural_name' => $natName,
            'functional_name' => $funName,
            'match_status' => $accRes['status'],
            'fuzzy' => !empty($accRes['fuzzy']),
            'candidates' => $cand,
        ];
    }

    $commentDesc = trim(preg_replace('/\\s+/', ' ', implode(' ', array_filter(array_map('trim', $descParts)))) ?? '');
    $finalDescription = trim($description);
    if ($commentDesc !== '') {
        $finalDescription = $finalDescription !== '' ? ($finalDescription . ' ' . $commentDesc) : $commentDesc;
    }

    $ref = trim($referenceVal);
    $check = trim($checkVal);
    if ($ref !== '' && !preg_match('/^\\d{6}$/', $ref)) {
        $warnings[] = 'Reference "' . $ref . '" is not YY#### (6 digits). Correct it before import.';
    }

    $balanced = abs($sum) < 0.005;
    if (!$balanced && $outLines !== []) {
        $warnings[] = 'Postings do not balance (signed sum = ' . number_format($sum, 2, '.', '') . '). Fix amounts before import.';
    }

    $parseOk = $errors === [] && $balanced && count($outLines) >= 2;
    // Unresolved accounts stay as errors so parse_ok is false until the user picks them.

    return [
        'queue_id' => $queueId,
        'source_text' => implode("\n", $lines),
        'transaction_date' => $dateStr,
        'reference_number' => $ref,
        'check_number' => $check,
        'pay_to' => $payTo,
        'description' => $finalDescription,
        'budget_id' => '',
        'lines' => $outLines,
        'warnings' => $warnings,
        'errors' => $errors,
        'balanced' => $balanced,
        'debit_total' => round($debitTotal, 2),
        'credit_total' => round($creditTotal, 2),
        'parse_ok' => $parseOk,
        'ref_auto' => false,
    ];
}

/**
 * Suggest unused YY#### refs for items that lack a valid Reference #.
 *
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function beancountMassImportAssignMissingRefs(mysqli $db, array $items): array
{
    $used = [];
    $stmt = $db->prepare(
        'SELECT reference_number FROM transaction_details
         WHERE reference_number IS NOT NULL AND CHAR_LENGTH(reference_number) = 6'
    );
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $used[(string)$row['reference_number']] = true;
        }
        $stmt->close();
    }
    foreach ($items as $it) {
        $r = trim((string)($it['reference_number'] ?? ''));
        if (preg_match('/^\\d{6}$/', $r)) {
            $used[$r] = true;
        }
    }

    foreach ($items as &$it) {
        $r = trim((string)($it['reference_number'] ?? ''));
        if (preg_match('/^\\d{6}$/', $r)) {
            continue;
        }
        $date = (string)($it['transaction_date'] ?? '');
        $ts = strtotime($date) ?: time();
        $yy = date('y', $ts);
        $n = 100;
        $assigned = null;
        while ($n <= 9999) {
            $cand = $yy . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
            if (!isset($used[$cand])) {
                $assigned = $cand;
                $used[$cand] = true;
                break;
            }
            $n++;
        }
        if ($assigned !== null) {
            $it['reference_number'] = $assigned;
            $it['ref_auto'] = true;
            $it['warnings'][] = 'Assigned suggested Reference # ' . $assigned . ' (none in paste). Confirm or change it before import.';
        } else {
            $it['errors'][] = 'Could not assign a free Reference # for year 20' . $yy . '. Enter one manually.';
        }
    }
    unset($it);
    return $items;
}

/**
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function beancountMassImportAssignBudgets(mysqli $db, array $items): array
{
    if (!function_exists('budgetDefaultIdForDate')) {
        return $items;
    }
    foreach ($items as &$it) {
        $date = (string)($it['transaction_date'] ?? '');
        if ($date === '' || ($it['budget_id'] ?? '') !== '') {
            continue;
        }
        $bid = budgetDefaultIdForDate($db, $date);
        if ($bid !== null && $bid > 0) {
            $it['budget_id'] = $bid;
        } else {
            $it['warnings'][] = 'No budget covers ' . $date . '. Assign one in the review panel if needed.';
        }
    }
    unset($it);
    return $items;
}

/**
 * Parse a pasted Beancount block into a dated review queue.
 *
 * @return array{ok:bool,items:list<array>,errors:list<string>,lookups:array}
 */
function beancountMassImportParseBlock(mysqli $db, string $text): array
{
    $lookups = beancountMassImportLoadLookups($db);
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    if ($text === '') {
        return [
            'ok' => false,
            'items' => [],
            'errors' => ['Paste is empty. Paste one or more Beancount-style transactions and try again.'],
            'lookups' => $lookups,
        ];
    }

    $chunks = beancountMassImportSplitEntries($text);
    if ($chunks === []) {
        return [
            'ok' => false,
            'items' => [],
            'errors' => [
                'No transaction headers found. Each entry should start with a date, e.g.',
                '2026-03-15 * "Payee" "Description"',
            ],
            'lookups' => $lookups,
        ];
    }

    $items = [];
    $n = 0;
    foreach ($chunks as $chunk) {
        $n++;
        $items[] = beancountMassImportParseOne($chunk, $lookups, 'q' . $n);
    }

    usort($items, static function ($a, $b) {
        $da = (string)($a['transaction_date'] ?? '');
        $db_ = (string)($b['transaction_date'] ?? '');
        if ($da !== $db_) {
            return strcmp($da, $db_);
        }
        return strcmp((string)$a['queue_id'], (string)$b['queue_id']);
    });

    $items = beancountMassImportAssignMissingRefs($db, $items);
    $items = beancountMassImportAssignBudgets($db, $items);

    // Recompute parse_ok after ref assignment
    foreach ($items as &$it) {
        $it['parse_ok'] = beancountMassImportItemIsReady($it) && ($it['errors'] ?? []) === [];
    }
    unset($it);

    return [
        'ok' => true,
        'items' => $items,
        'errors' => [],
        'lookups' => $lookups,
    ];
}

function beancountMassImportItemDebitTotal(array $item): float
{
    $t = 0.0;
    foreach ($item['lines'] ?? [] as $ln) {
        if (($ln['type'] ?? '') === 'debit') {
            $t += (float)($ln['amount'] ?? 0);
        }
    }
    return round($t, 2);
}

function beancountMassImportItemCreditTotal(array $item): float
{
    $t = 0.0;
    foreach ($item['lines'] ?? [] as $ln) {
        if (($ln['type'] ?? '') === 'credit') {
            $t += (float)($ln['amount'] ?? 0);
        }
    }
    return round($t, 2);
}

/**
 * Blocking issues that prevent writing this queue item.
 *
 * @return list<string>
 */
function beancountMassImportItemBlockingErrors(array $item): array
{
    $errors = [];
    $date = trim((string)($item['transaction_date'] ?? ''));
    if (!beancountMassImportValidDate($date)) {
        $errors[] = 'Date is required (YYYY-MM-DD).';
    }
    $refCheck = function_exists('ledgerValidateReferenceNumber')
        ? ledgerValidateReferenceNumber((string)($item['reference_number'] ?? ''), true)
        : ['ok' => preg_match('/^\\d{6}$/', trim((string)($item['reference_number'] ?? ''))) === 1];
    if (empty($refCheck['ok'])) {
        $errors[] = $refCheck['error'] ?? 'Reference # is required (YY####).';
    }
    $lines = [];
    foreach ($item['lines'] ?? [] as $ln) {
        $aid = (int)($ln['account_id'] ?? 0);
        $am = (float)($ln['amount'] ?? 0);
        $t = strtolower(trim((string)($ln['type'] ?? '')));
        if ($aid > 0 && $am > 0 && ($t === 'debit' || $t === 'credit')) {
            $lines[] = $ln;
        }
    }
    if (count($lines) < 2) {
        $errors[] = 'At least two valid lines with accounts and amounts are required.';
    }
    $dt = 0.0;
    $ct = 0.0;
    foreach ($lines as $ln) {
        if (($ln['type'] ?? '') === 'credit') {
            $ct += (float)$ln['amount'];
        } else {
            $dt += (float)$ln['amount'];
        }
    }
    if (count($lines) >= 2 && abs($dt - $ct) > 0.005) {
        $errors[] = 'Debits do not equal credits.';
    }
    return $errors;
}

function beancountMassImportItemIsReady(array $item): bool
{
    return beancountMassImportItemBlockingErrors($item) === [];
}

/**
 * @param array{name?:string,coa_number?:string} $acc
 */
function beancountMassImportAccountLabel(array $acc): string
{
    $coa = trim((string)($acc['coa_number'] ?? ''));
    $name = (string)($acc['name'] ?? '');
    return $coa !== '' ? ($coa . ' · ' . $name) : $name;
}

/**
 * Render a queue/ledger DTO as Beancount text for copy boxes.
 *
 * @param array<string,mixed> $item
 * @param array{accounts?:list<array>,funds?:list<array>} $lookups
 */
function beancountMassImportFormatBeancount(array $item, array $lookups = []): string
{
    $acctById = [];
    foreach ($lookups['accounts'] ?? [] as $a) {
        $acctById[(int)$a['id']] = $a;
    }
    $fundById = [];
    foreach ($lookups['funds'] ?? [] as $f) {
        $fundById[(int)$f['id']] = $f;
    }

    $date = trim((string)($item['transaction_date'] ?? '')) ?: '0000-00-00';
    $pay = (string)($item['pay_to'] ?? '');
    $desc = (string)($item['description'] ?? '');
    $header = $date . ' *';
    if ($pay !== '') {
        $header .= ' "' . str_replace('"', '\\"', $pay) . '"';
    }
    if ($desc !== '') {
        $header .= ' "' . str_replace('"', '\\"', $desc) . '"';
    }
    $out = [$header];
    $ref = trim((string)($item['reference_number'] ?? ''));
    if ($ref !== '') {
        $out[] = '  reference: "' . $ref . '"';
    }
    $check = trim((string)($item['check_number'] ?? ''));
    if ($check !== '') {
        $out[] = '  check: "' . $check . '"';
    }
    foreach ($item['lines'] ?? [] as $ln) {
        $aid = (int)($ln['account_id'] ?? 0);
        $name = (string)($ln['account_name'] ?? $ln['account_name_raw'] ?? '');
        if ($aid > 0 && isset($acctById[$aid])) {
            $name = (string)$acctById[$aid]['name'];
        }
        if ($name === '') {
            $name = 'Unknown';
        }
        $amt = (float)($ln['amount'] ?? 0);
        $signed = (($ln['type'] ?? 'debit') === 'credit') ? -$amt : $amt;
        $amtStr = number_format($signed, 2, '.', '');
        $line = '  ' . $name . '  ' . $amtStr . ' USD';
        $fid = (int)($ln['fund_id'] ?? 0);
        if ($fid > 0 && isset($fundById[$fid])) {
            $line .= ' ; fund: ' . $fundById[$fid]['name'];
        } elseif (!empty($ln['fund_hint'])) {
            $line .= ' ; fund: ' . $ln['fund_hint'];
        }
        $out[] = $line;
    }
    return implode("\n", $out);
}

/**
 * @return array{account_ids:list<int>,debit_total:float,credit_total:float}
 */
function beancountMassImportItemSignature(array $item): array
{
    $ids = [];
    $dt = 0.0;
    $ct = 0.0;
    foreach ($item['lines'] ?? [] as $ln) {
        $aid = (int)($ln['account_id'] ?? 0);
        if ($aid > 0) {
            $ids[] = $aid;
        }
        $am = (float)($ln['amount'] ?? 0);
        if (($ln['type'] ?? '') === 'credit') {
            $ct += $am;
        } else {
            $dt += $am;
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return [
        'account_ids' => $ids,
        'debit_total' => round($dt, 2),
        'credit_total' => round($ct, 2),
    ];
}

function beancountMassImportItemAmount(array $item): float
{
    $sig = beancountMassImportItemSignature($item);
    return max((float)$sig['debit_total'], (float)$sig['credit_total']);
}

/**
 * True when both sides have amounts and they differ enough that they should
 * not be treated as the same transaction (e.g. $700 vs $69).
 */
function beancountMassImportAmountsSubstantiallyDifferent(float $a, float $b): bool
{
    if ($a <= 0.004 || $b <= 0.004) {
        return false;
    }
    $lo = min($a, $b);
    $hi = max($a, $b);
    if (($hi - $lo) < BEANCOUNT_MASS_IMPORT_AMOUNT_VETO_MIN_DIFF) {
        return false;
    }
    return ($lo / $hi) < BEANCOUNT_MASS_IMPORT_AMOUNT_VETO_RATIO;
}

/**
 * Duplicate score. Primary: date, ref #, check #.
 * Amount is a strong confirming/disconfirming factor; supporting: pay to, description, accounts.
 *
 * @return array{score:float,reasons:list<string>,primary:bool,amount_mismatch:bool}
 */
function beancountMassImportDuplicateScore(array $a, array $b): array
{
    $reasons = [];
    $score = 0.0;
    $primary = false;

    $amtA = beancountMassImportItemAmount($a);
    $amtB = beancountMassImportItemAmount($b);
    $amountMismatch = beancountMassImportAmountsSubstantiallyDifferent($amtA, $amtB);
    if ($amountMismatch) {
        return [
            'score' => 0.0,
            'reasons' => ['substantially different amounts'],
            'primary' => false,
            'amount_mismatch' => true,
        ];
    }

    $da = trim((string)($a['transaction_date'] ?? ''));
    $dbd = trim((string)($b['transaction_date'] ?? ''));
    $sameDate = ($da !== '' && $da === $dbd);
    if ($sameDate) {
        $score += 50.0;
        $reasons[] = 'same date';
    } elseif ($da !== '' && $dbd !== '') {
        $ta = strtotime($da);
        $tb = strtotime($dbd);
        if ($ta !== false && $tb !== false && abs($ta - $tb) <= 86400) {
            $score += 12.0;
            $reasons[] = 'dates within 1 day';
        }
    }

    $ra = trim((string)($a['reference_number'] ?? ''));
    $rb = trim((string)($b['reference_number'] ?? ''));
    $sameRef = ($ra !== '' && $rb !== '' && $ra === $rb);
    if ($sameRef) {
        $score += 55.0;
        $reasons[] = 'same Ref #';
        $primary = true;
    }

    $ca = trim((string)($a['check_number'] ?? ''));
    $cb = trim((string)($b['check_number'] ?? ''));
    $sameCheck = ($ca !== '' && $cb !== '' && strcasecmp($ca, $cb) === 0);
    if ($sameCheck) {
        $score += 40.0;
        $reasons[] = 'same Check #';
        $primary = true;
    }

    if ($sameDate && ($sameRef || $sameCheck)) {
        $primary = true;
    }

    if ($amtA > 0.004 && $amtB > 0.004) {
        $diff = abs($amtA - $amtB);
        $hi = max($amtA, $amtB);
        $rel = $hi > 0 ? ($diff / $hi) : 1.0;
        if ($diff < 0.005) {
            $score += 30.0;
            $reasons[] = 'same amount';
        } elseif ($rel <= 0.02 || $diff <= 0.50) {
            $score += 20.0;
            $reasons[] = 'similar amount';
        } elseif ($rel <= 0.05) {
            $score += 12.0;
            $reasons[] = 'similar amount';
        }
    }

    $pa = beancountMassImportCollapseKey((string)($a['pay_to'] ?? ''));
    $pb = beancountMassImportCollapseKey((string)($b['pay_to'] ?? ''));
    if ($pa !== '' && $pb !== '') {
        $sim = beancountMassImportScoreStringPair($pa, $pb);
        if ($sim >= 0.72) {
            $score += 12.0 * $sim;
            $reasons[] = 'similar Pay To';
        }
    }

    $ma = beancountMassImportCollapseKey((string)($a['description'] ?? ''));
    $mb = beancountMassImportCollapseKey((string)($b['description'] ?? ''));
    if ($ma !== '' && $mb !== '') {
        $sim = beancountMassImportScoreStringPair($ma, $mb);
        if ($sim >= 0.72) {
            $score += 8.0 * $sim;
            $reasons[] = 'similar description';
        }
    }

    $sa = beancountMassImportItemSignature($a);
    $sb = beancountMassImportItemSignature($b);
    if ($sa['account_ids'] !== [] && $sa['account_ids'] === $sb['account_ids']) {
        $score += 10.0;
        $reasons[] = 'same accounts';
    } elseif ($sa['account_ids'] !== [] && $sb['account_ids'] !== []) {
        $overlap = array_intersect($sa['account_ids'], $sb['account_ids']);
        $union = array_unique(array_merge($sa['account_ids'], $sb['account_ids']));
        if ($overlap !== [] && $union !== []) {
            $ratio = count($overlap) / count($union);
            if ($ratio >= 0.5) {
                $score += 6.0 * $ratio;
                $reasons[] = 'overlapping accounts';
            }
        }
    }

    return [
        'score' => round($score, 2),
        'reasons' => $reasons,
        'primary' => $primary,
        'amount_mismatch' => false,
    ];
}

function beancountMassImportIsDuplicateHit(array $scoreRow): bool
{
    if (!empty($scoreRow['amount_mismatch'])) {
        return false;
    }
    if (!empty($scoreRow['primary'])) {
        return true;
    }
    return (float)($scoreRow['score'] ?? 0) >= BEANCOUNT_MASS_IMPORT_DUPLICATE_THRESHOLD;
}

function beancountMassImportIsAllowedSameBatch(array $item): bool
{
    $v = $item['allow_same_batch'] ?? false;
    return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
}

function beancountMassImportMatchSummary(array $item, string $where): string
{
    $pay = trim((string)($item['pay_to'] ?? ''));
    $date = trim((string)($item['transaction_date'] ?? ''));
    $amt = beancountMassImportItemAmount($item);
    $bits = [];
    if ($pay !== '') {
        $bits[] = '"' . $pay . '"';
    }
    if ($date !== '') {
        $bits[] = 'on ' . $date;
    }
    if ($amt > 0) {
        $bits[] = '$' . number_format($amt, 2, '.', '');
    }
    $core = $bits !== [] ? implode(' ', $bits) : 'another transaction';
    return 'Matches ' . $core . ' ' . $where;
}

/**
 * Load existing ledger transactions that could match this queue (date window + refs + checks).
 *
 * @param list<array<string,mixed>> $queue
 * @return list<array<string,mixed>>
 */
function beancountMassImportFetchLedgerCandidates(mysqli $db, array $queue): array
{
    if ($queue === []) {
        return [];
    }
    $dates = [];
    $refs = [];
    $checks = [];
    foreach ($queue as $it) {
        $d = trim((string)($it['transaction_date'] ?? ''));
        if (beancountMassImportValidDate($d)) {
            $dates[] = $d;
        }
        $r = trim((string)($it['reference_number'] ?? ''));
        if ($r !== '') {
            $refs[] = $r;
        }
        $c = trim((string)($it['check_number'] ?? ''));
        if ($c !== '') {
            $checks[] = $c;
        }
    }
    $dates = array_values(array_unique($dates));
    $refs = array_values(array_unique($refs));
    $checks = array_values(array_unique($checks));

    $where = [];
    $types = '';
    $params = [];
    if ($dates !== []) {
        $min = min($dates);
        $max = max($dates);
        $where[] = 'td.transaction_date BETWEEN DATE_SUB(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 1 DAY)';
        $types .= 'ss';
        $params[] = $min;
        $params[] = $max;
    }
    if ($refs !== []) {
        $ph = implode(',', array_fill(0, count($refs), '?'));
        $where[] = "td.reference_number IN ($ph)";
        $types .= str_repeat('s', count($refs));
        foreach ($refs as $r) {
            $params[] = $r;
        }
    }
    if ($checks !== []) {
        $ph = implode(',', array_fill(0, count($checks), '?'));
        $where[] = "td.check_number IN ($ph)";
        $types .= str_repeat('s', count($checks));
        foreach ($checks as $c) {
            $params[] = $c;
        }
    }
    if ($where === []) {
        return [];
    }

    $sql = 'SELECT td.id, td.transaction_date, td.reference_number, td.check_number, td.pay_to,
                   td.description, td.budget_id, td.status
            FROM transaction_details td
            WHERE ' . implode(' OR ', $where) . '
            ORDER BY td.transaction_date ASC, td.id ASC
            LIMIT 4000';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $id = (int)$row['id'];
        $ids[] = $id;
        $rows[$id] = [
            'ledger_id' => $id,
            'source' => 'ledger',
            'queue_id' => null,
            'transaction_date' => (string)$row['transaction_date'],
            'reference_number' => (string)($row['reference_number'] ?? ''),
            'check_number' => (string)($row['check_number'] ?? ''),
            'pay_to' => (string)($row['pay_to'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'budget_id' => !empty($row['budget_id']) ? (int)$row['budget_id'] : '',
            'status' => (string)($row['status'] ?? ''),
            'lines' => [],
        ];
    }
    $stmt->close();
    if ($ids === []) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $ls = $db->prepare(
        "SELECT tl.transaction_detail_id, tl.account_id, tl.fund_id, tl.amount, tl.type,
                a.name AS account_name, f.name AS fund_name
         FROM transaction_lines tl
         LEFT JOIN accounts a ON a.id = tl.account_id
         LEFT JOIN funds f ON f.id = tl.fund_id
         WHERE tl.transaction_detail_id IN ($ph)
         ORDER BY tl.id ASC"
    );
    if ($ls) {
        $ls->bind_param($types, ...$ids);
        $ls->execute();
        $lr = $ls->get_result();
        while ($ln = $lr->fetch_assoc()) {
            $tid = (int)$ln['transaction_detail_id'];
            if (!isset($rows[$tid])) {
                continue;
            }
            $rows[$tid]['lines'][] = [
                'account_id' => (int)$ln['account_id'],
                'account_name' => (string)($ln['account_name'] ?? ''),
                'fund_id' => $ln['fund_id'] !== null ? (int)$ln['fund_id'] : '',
                'fund_name' => (string)($ln['fund_name'] ?? ''),
                'amount' => round((float)$ln['amount'], 2),
                'type' => strtolower((string)$ln['type']) === 'credit' ? 'credit' : 'debit',
            ];
        }
        $ls->close();
    }

    foreach ($rows as &$r) {
        $sig = beancountMassImportItemSignature($r);
        $r['debit_total'] = $sig['debit_total'];
        $r['credit_total'] = $sig['credit_total'];
    }
    unset($r);

    return array_values($rows);
}

/**
 * Classify each queue item as a ledger duplicate, a same-batch duplicate, or neither.
 * Ledger matches take priority (side-by-side modal). Same-batch matches are flagged only.
 *
 * @param list<array<string,mixed>> $queue
 * @return array{items:list<array<string,mixed>>,ledger_sets:list<array<string,mixed>>}
 */
function beancountMassImportClassifyDuplicates(mysqli $db, array $queue, array $lookups = []): array
{
    $ledger = beancountMassImportFetchLedgerCandidates($db, $queue);
    $byQid = [];
    foreach ($queue as $it) {
        $byQid[(string)($it['queue_id'] ?? '')] = $it;
    }
    $qids = array_values(array_filter(array_keys($byQid), static fn($id) => $id !== ''));

    $ledgerBest = [];
    foreach ($qids as $qid) {
        $it = $byQid[$qid];
        $best = null;
        $bestHit = null;
        foreach ($ledger as $cand) {
            $hit = beancountMassImportDuplicateScore($it, $cand);
            if (!beancountMassImportIsDuplicateHit($hit)) {
                continue;
            }
            if ($bestHit === null || $hit['score'] > $bestHit['score']) {
                $best = $cand;
                $bestHit = $hit;
            }
        }
        if ($best && $bestHit) {
            $ledgerBest[$qid] = ['match' => $best, 'hit' => $bestHit];
        }
    }

    $queueBest = [];
    $n = count($qids);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $byQid[$qids[$i]];
            $b = $byQid[$qids[$j]];
            $hit = beancountMassImportDuplicateScore($a, $b);
            if (!beancountMassImportIsDuplicateHit($hit)) {
                continue;
            }
            $qa = $qids[$i];
            $qb = $qids[$j];
            if (!isset($queueBest[$qa]) || $hit['score'] > $queueBest[$qa]['hit']['score']) {
                $queueBest[$qa] = ['match' => $b, 'hit' => $hit];
            }
            if (!isset($queueBest[$qb]) || $hit['score'] > $queueBest[$qb]['hit']['score']) {
                $queueBest[$qb] = ['match' => $a, 'hit' => $hit];
            }
        }
    }

    $items = [];
    foreach ($queue as $it) {
        $qid = (string)($it['queue_id'] ?? '');
        $kind = null;
        $reasons = [];
        $score = 0.0;
        $primary = false;
        $matchLabel = '';
        $matchQid = null;
        if (isset($ledgerBest[$qid])) {
            $kind = 'ledger';
            $hit = $ledgerBest[$qid]['hit'];
            $reasons = $hit['reasons'];
            $score = (float)$hit['score'];
            $primary = !empty($hit['primary']);
            $matchLabel = beancountMassImportMatchSummary($ledgerBest[$qid]['match'], 'in the ledger');
        } elseif (isset($queueBest[$qid])) {
            $kind = 'queue';
            $hit = $queueBest[$qid]['hit'];
            $reasons = $hit['reasons'];
            $score = (float)$hit['score'];
            $primary = !empty($hit['primary']);
            $other = $queueBest[$qid]['match'];
            $matchQid = (string)($other['queue_id'] ?? '');
            $matchLabel = beancountMassImportMatchSummary($other, 'in this import');
        }
        $it['duplicate_preview'] = $kind !== null;
        $it['duplicate_kind'] = $kind;
        $it['duplicate_reasons'] = $reasons;
        $it['duplicate_score'] = $score;
        $it['duplicate_primary'] = $primary;
        $it['duplicate_match_label'] = $matchLabel;
        $it['duplicate_match_queue_id'] = $matchQid;
        $it['allow_same_batch'] = beancountMassImportIsAllowedSameBatch($it);
        $items[] = $it;
    }

    $sets = [];
    $si = 0;
    foreach ($items as $left) {
        $qid = (string)($left['queue_id'] ?? '');
        if (($left['duplicate_kind'] ?? null) !== 'ledger' || !isset($ledgerBest[$qid])) {
            continue;
        }
        $si++;
        $right = $ledgerBest[$qid]['match'];
        $hit = $ledgerBest[$qid]['hit'];
        $left['beancount_text'] = beancountMassImportFormatBeancount($left, $lookups);
        $right['beancount_text'] = beancountMassImportFormatBeancount($right, $lookups);
        $sets[] = [
            'set_id' => 'dup' . $si,
            'score' => $hit['score'],
            'reasons' => $hit['reasons'],
            'primary' => !empty($hit['primary']),
            'left' => $left,
            'left_label' => 'Importing',
            'right' => $right,
            'right_label' => 'Existing in Ledger',
            'right_source' => 'ledger',
            'right_editable' => false,
        ];
    }

    return [
        'items' => $items,
        'ledger_sets' => $sets,
    ];
}

/**
 * Ledger-only duplicate sets (side-by-side modal). Same-batch matches are not included.
 *
 * @param list<array<string,mixed>> $queue
 * @return list<array<string,mixed>>
 */
function beancountMassImportDetectDuplicates(mysqli $db, array $queue, array $lookups = []): array
{
    return beancountMassImportClassifyDuplicates($db, $queue, $lookups)['ledger_sets'];
}

/**
 * @return array{ok:bool,id?:int,error?:string}
 */
function beancountMassImportWriteOne(mysqli $db, array $item, array $actor, bool $allowRefReuse = true): array
{
    $blocking = beancountMassImportItemBlockingErrors($item);
    if ($blocking !== []) {
        return ['ok' => false, 'error' => $blocking[0]];
    }

    $d = (string)$item['transaction_date'];
    $p = trim((string)($item['pay_to'] ?? ''));
    $c = trim((string)($item['check_number'] ?? ''));
    $desc = trim((string)($item['description'] ?? ''));
    $refCheck = ledgerValidateReferenceNumber((string)($item['reference_number'] ?? ''), true);
    $ref = (string)($refCheck['value'] ?? '');
    $budgetIdRaw = (int)($item['budget_id'] ?? 0);
    $budgetId = $budgetIdRaw > 0 ? $budgetIdRaw : null;

    if ($budgetId !== null && function_exists('budgetIsAssignableId') && !budgetIsAssignableId($db, $budgetId)) {
        return ['ok' => false, 'error' => 'Selected budget is not available for transactions.'];
    }

    if (!$allowRefReuse && ledgerReferenceNumberTaken($db, $ref, null)) {
        return ['ok' => false, 'error' => 'Reference # ' . $ref . ' is already used.'];
    }

    $accountCatCache = [];
    $vlines = [];
    $dt = 0.0;
    $ct = 0.0;
    foreach ($item['lines'] ?? [] as $l) {
        $aid = (int)($l['account_id'] ?? 0);
        $am = (float)($l['amount'] ?? 0);
        $t = strtolower(trim((string)($l['type'] ?? '')));
        if ($aid <= 0 || $am <= 0 || ($t !== 'debit' && $t !== 'credit')) {
            continue;
        }
        if ($t === 'debit') {
            $dt += $am;
        } else {
            $ct += $am;
        }
        if (!isset($accountCatCache[$aid])) {
            $nid = null;
            $fid2 = null;
            $st = $db->prepare(
                'SELECT natural_category_id, functional_category_id FROM accounts WHERE id = ? AND archived = FALSE LIMIT 1'
            );
            if ($st) {
                $st->bind_param('i', $aid);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if (!$row) {
                    return ['ok' => false, 'error' => 'Account #' . $aid . ' is missing or archived.'];
                }
                $nid = $row['natural_category_id'] !== null ? (int)$row['natural_category_id'] : null;
                $fid2 = $row['functional_category_id'] !== null ? (int)$row['functional_category_id'] : null;
                if ($nid !== null && $nid <= 0) {
                    $nid = null;
                }
                if ($fid2 !== null && $fid2 <= 0) {
                    $fid2 = null;
                }
            }
            $accountCatCache[$aid] = ['nid' => $nid, 'fid2' => $fid2];
        }
        $fid = !empty($l['fund_id']) ? (int)$l['fund_id'] : null;
        if ($fid !== null && $fid <= 0) {
            $fid = null;
        }
        $vlines[] = [
            'account_id' => $aid,
            'fund_id' => $fid,
            'amount' => $am,
            'type' => $t,
            'natural_category_id' => $accountCatCache[$aid]['nid'],
            'functional_category_id' => $accountCatCache[$aid]['fid2'],
            'description' => '',
            'am' => $am,
            't' => $t,
            'aid' => $aid,
            'fid' => $fid,
            'nid' => $accountCatCache[$aid]['nid'],
            'fid2' => $accountCatCache[$aid]['fid2'],
        ];
    }
    if (count($vlines) < 2) {
        return ['ok' => false, 'error' => 'At least two valid lines are required.'];
    }
    if (abs($dt - $ct) > 0.005) {
        return ['ok' => false, 'error' => 'Debits do not equal credits.'];
    }

    $createdBy = isset($actor['id']) ? (int)$actor['id'] : null;
    $username = (string)($actor['username'] ?? 'system');

    $tid = ledgerCreateHeader(
        $db,
        $d,
        $p,
        $ref,
        $desc,
        $createdBy,
        ['source' => 'beancount_mass_import'],
        $budgetId
    );
    if ($tid <= 0) {
        return ['ok' => false, 'error' => 'Failed to create transaction header.'];
    }

    $chk = $db->prepare('UPDATE transaction_details SET check_number = ? WHERE id = ?');
    $chk->bind_param('si', $c, $tid);
    $chk->execute();
    $chk->close();

    $lineRows = [];
    foreach ($vlines as $v) {
        $lineRows[] = [
            'account_id' => $v['account_id'],
            'fund_id' => $v['fund_id'],
            'amount' => $v['amount'],
            'type' => $v['type'],
            'natural_category_id' => $v['natural_category_id'],
            'functional_category_id' => $v['functional_category_id'],
            'description' => '',
        ];
    }
    ledgerReplaceLines($db, $tid, $lineRows);
    $describe = ledgerDescribeTransactionCreate($db, $p, $vlines);
    ledgerLogEvent(
        $db,
        $tid,
        'created',
        $createdBy,
        $username,
        'Mass import: ' . $describe['summary'],
        [
            'source' => 'beancount_mass_import',
            'debits' => $dt,
            'credits' => $ct,
            'changes' => $describe['changes'],
        ]
    );

    return ['ok' => true, 'id' => $tid];
}

/**
 * Write ready non-ledger-duplicate items in one DB transaction.
 * Same-batch duplicates are written only when marked Legitimate / Allow.
 * Ledger matches are held out for the side-by-side modal.
 *
 * @param list<array<string,mixed>> $queue
 * @return array{ok:bool,imported:list<array>,duplicate_sets:list<array>,rejected:list<array>,skipped_same_batch:list<array>,error?:string}
 */
function beancountMassImportWriteBatch(mysqli $db, array $queue, array $actor, array $lookups = []): array
{
    $classified = beancountMassImportClassifyDuplicates($db, $queue, $lookups);
    $sets = $classified['ledger_sets'];
    $byKind = [];
    foreach ($classified['items'] as $ann) {
        $byKind[(string)($ann['queue_id'] ?? '')] = $ann;
    }

    $toWrite = [];
    $rejected = [];
    $skipped = [];
    foreach ($queue as $it) {
        $qid = (string)($it['queue_id'] ?? '');
        $ann = $byKind[$qid] ?? $it;
        $kind = $ann['duplicate_kind'] ?? null;
        if ($kind === 'ledger') {
            continue;
        }
        if ($kind === 'queue' && !beancountMassImportIsAllowedSameBatch($it)) {
            $skipped[] = [
                'queue_id' => $qid,
                'pay_to' => (string)($it['pay_to'] ?? ''),
                'reference_number' => (string)($it['reference_number'] ?? ''),
                'reason' => $ann['duplicate_match_label'] ?? 'Matches another item in this import',
            ];
            continue;
        }
        $block = beancountMassImportItemBlockingErrors($it);
        if ($block !== []) {
            $rejected[] = [
                'queue_id' => $qid,
                'error' => $block[0],
                'errors' => $block,
            ];
            continue;
        }
        $toWrite[] = $it;
    }

    $imported = [];
    $db->begin_transaction();
    try {
        foreach ($toWrite as $it) {
            $res = beancountMassImportWriteOne($db, $it, $actor, true);
            if (empty($res['ok'])) {
                throw new RuntimeException($res['error'] ?? 'Write failed.');
            }
            $imported[] = [
                'queue_id' => (string)$it['queue_id'],
                'id' => (int)$res['id'],
                'reference_number' => (string)($it['reference_number'] ?? ''),
                'transaction_date' => (string)($it['transaction_date'] ?? ''),
                'pay_to' => (string)($it['pay_to'] ?? ''),
            ];
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return [
            'ok' => false,
            'imported' => [],
            'duplicate_sets' => $sets,
            'rejected' => $rejected,
            'skipped_same_batch' => $skipped,
            'error' => 'Batch import failed: ' . $e->getMessage(),
        ];
    }

    return [
        'ok' => true,
        'imported' => $imported,
        'duplicate_sets' => $sets,
        'rejected' => $rejected,
        'skipped_same_batch' => $skipped,
    ];
}

<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

/**
 * Read-only check: accounts hold natural/functional category FKs and budget_lines
 * only references accounts (categories come from the linked account).
 * Does not ALTER tables or migrate data. Schema is owned by setup_db / updates/*.sql.
 *
 * @return list<string>
 */
function budgetCheckSimplifiedSchema(mysqli $db): array {
    $issues = [];

    foreach (['accounts', 'budget_lines', 'budgets'] as $table) {
        $escaped = $db->real_escape_string($table);
        $res = $db->query("SHOW TABLES LIKE '{$escaped}'");
        if (!$res || $res->num_rows === 0) {
            $issues[] = "table {$table} is missing";
        }
        if ($res) {
            $res->close();
        }
    }

    if ($issues !== []) {
        return $issues;
    }

    foreach (['natural_category_id', 'functional_category_id', 'coa_number'] as $col) {
        $c = $db->query("SHOW COLUMNS FROM accounts LIKE '" . $db->real_escape_string($col) . "'");
        if (!$c || $c->num_rows === 0) {
            $issues[] = "column accounts.{$col} is missing";
        }
        if ($c) {
            $c->close();
        }
    }

    // Legacy layout: categories on budget_lines (must be migrated via updates/*.sql)
    foreach (['natural_category_id', 'functional_category_id'] as $col) {
        $c = $db->query("SHOW COLUMNS FROM budget_lines LIKE '" . $db->real_escape_string($col) . "'");
        if ($c && $c->num_rows > 0) {
            $issues[] = "legacy column budget_lines.{$col} still present (apply schema patches)";
        }
        if ($c) {
            $c->close();
        }
    }

    $aidCol = $db->query("SHOW COLUMNS FROM budget_lines LIKE 'account_id'");
    if (!$aidCol || $aidCol->num_rows === 0) {
        $issues[] = 'column budget_lines.account_id is missing';
    } elseif ($row = $aidCol->fetch_assoc()) {
        if (strtoupper((string)($row['Null'] ?? '')) === 'YES') {
            $issues[] = 'column budget_lines.account_id must be NOT NULL';
        }
    }
    if ($aidCol) {
        $aidCol->close();
    }

    return $issues;
}

/**
 * Ensure simplified budget schema is present (read-only). Logs and throws if outdated.
 * Does not run live DDL or data backfills.
 */
function budgetEnsureSimplifiedSchema(mysqli $db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $issues = budgetCheckSimplifiedSchema($db);
    if ($issues !== []) {
        temperSchemaOutOfDate('budget', $issues);
    }
}

function budgetCurrentFiscalYear(): int {
    return (int)date('Y');
}

/**
 * Active (non-archived) accounts for budget line pickers, with category labels.
 *
 * @return list<array{id:int,name:string,coa_number:string,natural_category_id:?int,functional_category_id:?int,natural_name:string,functional_name:string}>
 */
function budgetFetchAccountLookups(mysqli $db): array {
    budgetEnsureSimplifiedSchema($db);
    $rows = [];
    $sql = "SELECT a.id, a.name, a.coa_number,
                   a.natural_category_id, a.functional_category_id,
                   COALESCE(nc.name, '') AS natural_name,
                   COALESCE(fc.name, '') AS functional_name
            FROM accounts a
            LEFT JOIN natural_categories nc ON nc.id = a.natural_category_id
            LEFT JOIN functional_categories fc ON fc.id = a.functional_category_id
            WHERE a.archived = FALSE
            ORDER BY (a.coa_number IS NULL OR a.coa_number = '') ASC, a.coa_number ASC, a.name ASC, a.id ASC";
    $r = $db->query($sql);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $coa = trim((string)($row['coa_number'] ?? ''));
            $rows[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'coa_number' => $coa,
                'natural_category_id' => $row['natural_category_id'] !== null ? (int)$row['natural_category_id'] : null,
                'functional_category_id' => $row['functional_category_id'] !== null ? (int)$row['functional_category_id'] : null,
                'natural_name' => $row['natural_name'] !== '' ? $row['natural_name'] : '—',
                'functional_name' => $row['functional_name'] !== '' ? $row['functional_name'] : '—',
            ];
        }
        $r->close();
    }
    return $rows;
}

/**
 * Validate that account_id is a non-archived account from the lookup system.
 */
function budgetIsValidAccountId(mysqli $db, int $accountId): bool {
    if ($accountId <= 0) {
        return false;
    }
    $stmt = $db->prepare('SELECT id FROM accounts WHERE id = ? AND archived = FALSE LIMIT 1');
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $ok;
}

/**
 * Budgets available for transaction header selection (non-draft).
 * Dropdown label is the budget name; period + description feed the standard Bootstrap tooltip.
 *
 * @return list<array{id:int,fiscal_year:int,name:string,description:string,start_date:string,end_date:string,status:string,label:string,period_label:string,tooltip:string}>
 */
function budgetFetchTransactionOptions(mysqli $db): array {
    $rows = [];
    $r = $db->query(
        "SELECT id, fiscal_year, name, description, start_date, end_date, status
         FROM budgets
         WHERE status IN ('active', 'approved', 'closed')
         ORDER BY FIELD(status, 'active', 'approved', 'closed'), fiscal_year DESC, name, id DESC"
    );
    if (!$r) {
        return $rows;
    }
    while ($row = $r->fetch_assoc()) {
        $fy = (int)$row['fiscal_year'];
        $start = (string)$row['start_date'];
        $end = (string)$row['end_date'];
        $name = (string)$row['name'];
        $desc = trim((string)($row['description'] ?? ''));
        $period = budgetFormatPeriodLabel($start, $end);
        $tooltip = $period;
        if ($desc !== '') {
            $tooltip .= "\n" . $desc;
        }
        $rows[] = [
            'id' => (int)$row['id'],
            'fiscal_year' => $fy,
            'name' => $name,
            'description' => $desc,
            'start_date' => $start,
            'end_date' => $end,
            'status' => (string)$row['status'],
            // Dropdown shows name only (not description, not year prefix)
            'label' => $name,
            'period_label' => $period,
            'tooltip' => $tooltip,
        ];
    }
    $r->close();
    return $rows;
}

/** Format YYYY-MM-DD range as MM/DD/YYYY - MM/DD/YYYY for tooltips. */
function budgetFormatPeriodLabel(string $startDate, string $endDate): string {
    $fmt = static function (string $iso): string {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return $iso;
        }
        return $m[2] . '/' . $m[3] . '/' . $m[1];
    };
    return $fmt($startDate) . ' - ' . $fmt($endDate);
}

/**
 * Prefer an active budget whose period covers $transactionDate.
 * Fallback: any non-draft covering the date. Null if none.
 */
function budgetDefaultIdForDate(mysqli $db, string $transactionDate): ?int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionDate)) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT id FROM budgets
         WHERE status = 'active'
           AND start_date <= ? AND end_date >= ?
         ORDER BY fiscal_year DESC, id DESC
         LIMIT 1"
    );
    $stmt->bind_param('ss', $transactionDate, $transactionDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return (int)$row['id'];
    }

    $stmt = $db->prepare(
        "SELECT id FROM budgets
         WHERE status IN ('approved', 'closed')
           AND start_date <= ? AND end_date >= ?
         ORDER BY FIELD(status, 'approved', 'closed'), fiscal_year DESC, id DESC
         LIMIT 1"
    );
    $stmt->bind_param('ss', $transactionDate, $transactionDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

/**
 * @return array{id:int,fiscal_year:int,name:string,start_date:string,end_date:string,status:string}|null
 */
function budgetFetchById(mysqli $db, int $budgetId): ?array {
    if ($budgetId <= 0) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT id, fiscal_year, name, start_date, end_date, status FROM budgets WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $budgetId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'fiscal_year' => (int)$row['fiscal_year'],
        'name' => (string)$row['name'],
        'start_date' => (string)$row['start_date'],
        'end_date' => (string)$row['end_date'],
        'status' => (string)$row['status'],
    ];
}

/** True when transaction date falls within the budget period (inclusive). */
function budgetDateInPeriod(string $transactionDate, string $startDate, string $endDate): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionDate)) {
        return false;
    }
    return $transactionDate >= $startDate && $transactionDate <= $endDate;
}

/**
 * Whether budget_id may be assigned on a transaction (active/approved/closed).
 */
function budgetIsAssignableId(mysqli $db, int $budgetId): bool {
    if ($budgetId <= 0) {
        return false;
    }
    $stmt = $db->prepare(
        "SELECT id FROM budgets WHERE id = ? AND status IN ('active', 'approved', 'closed') LIMIT 1"
    );
    $stmt->bind_param('i', $budgetId);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $ok;
}

function budgetFetchActiveList(mysqli $db): array {
    $rows = [];
    $r = $db->query(
        "SELECT id, name, fiscal_year, start_date, end_date
         FROM budgets
         WHERE status = 'active'
         ORDER BY fiscal_year DESC, name, id DESC"
    );
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $rows[] = $row;
        }
        $r->close();
    }
    return $rows;
}

function budgetFetchListForYear(mysqli $db, int $fiscalYear): array {
    $rows = [];
    $stmt = $db->prepare(
        "SELECT id, name, start_date, end_date, status, fiscal_year
         FROM budgets
         WHERE fiscal_year = ?
         ORDER BY FIELD(status, 'active', 'approved', 'closed', 'draft'), name, id DESC"
    );
    $stmt->bind_param('i', $fiscalYear);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function budgetResolveForYear(mysqli $db, int $fiscalYear, ?int $budgetId = null): ?array {
    if ($budgetId !== null && $budgetId > 0) {
        $stmt = $db->prepare(
            "SELECT id, name, start_date, end_date, status, fiscal_year
             FROM budgets
             WHERE id = ? AND fiscal_year = ?"
        );
        $stmt->bind_param('ii', $budgetId, $fiscalYear);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    $stmt = $db->prepare(
        "SELECT id, name, start_date, end_date, status, fiscal_year
         FROM budgets
         WHERE fiscal_year = ?
         ORDER BY FIELD(status, 'active', 'approved', 'closed', 'draft'), id DESC
         LIMIT 1"
    );
    $stmt->bind_param('i', $fiscalYear);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function budgetActiveSummary(mysqli $db): array {
    $currentFy = budgetCurrentFiscalYear();
    $active = budgetFetchActiveList($db);
    return [
        'current_fiscal_year' => $currentFy,
        'active' => $active,
        'active_fiscal_years' => array_map(fn($b) => (int)$b['fiscal_year'], $active),
    ];
}

function budgetListGroupedByYear(mysqli $db): array {
    $grouped = [];
    $r = $db->query(
        "SELECT id, fiscal_year, name, status
         FROM budgets
         ORDER BY fiscal_year DESC, FIELD(status, 'active', 'approved', 'closed', 'draft'), name, id DESC"
    );
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $fy = (int)$row['fiscal_year'];
            if (!isset($grouped[$fy])) {
                $grouped[$fy] = [];
            }
            $grouped[$fy][] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'status' => $row['status'],
            ];
        }
        $r->close();
    }
    return $grouped;
}
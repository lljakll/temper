<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

/**
 * Ensure accounts hold natural/functional category FKs and budget_lines
 * only references accounts (categories come from the linked account).
 * Safe to call repeatedly.
 */
function budgetEnsureSimplifiedSchema(mysqli $db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // --- accounts: natural_category_id / functional_category_id ---
    $hasNat = $db->query("SHOW COLUMNS FROM accounts LIKE 'natural_category_id'");
    if ($hasNat && $hasNat->num_rows === 0) {
        $db->query(
            'ALTER TABLE accounts
             ADD COLUMN natural_category_id INT NULL AFTER normal_balance,
             ADD COLUMN functional_category_id INT NULL AFTER natural_category_id'
        );
        // Best-effort indexes (ignore if already present)
        @$db->query('CREATE INDEX idx_accounts_natural_category_id ON accounts(natural_category_id)');
        @$db->query('CREATE INDEX idx_accounts_functional_category_id ON accounts(functional_category_id)');
        // FKs if categories tables exist
        $nc = $db->query("SHOW TABLES LIKE 'natural_categories'");
        $fc = $db->query("SHOW TABLES LIKE 'functional_categories'");
        if ($nc && $nc->num_rows > 0) {
            @$db->query(
                'ALTER TABLE accounts
                 ADD CONSTRAINT fk_accounts_natural_category
                 FOREIGN KEY (natural_category_id) REFERENCES natural_categories(id) ON DELETE SET NULL'
            );
        }
        if ($fc && $fc->num_rows > 0) {
            @$db->query(
                'ALTER TABLE accounts
                 ADD CONSTRAINT fk_accounts_functional_category
                 FOREIGN KEY (functional_category_id) REFERENCES functional_categories(id) ON DELETE SET NULL'
            );
        }
    }
    if ($hasNat) {
        $hasNat->close();
    }

    // Backfill account categories from historical budget_lines when still NULL
    $blHasNat = $db->query("SHOW COLUMNS FROM budget_lines LIKE 'natural_category_id'");
    if ($blHasNat && $blHasNat->num_rows > 0) {
        $db->query(
            'UPDATE accounts a
             INNER JOIN (
                 SELECT account_id,
                        MAX(natural_category_id) AS natural_category_id,
                        MAX(functional_category_id) AS functional_category_id
                 FROM budget_lines
                 WHERE account_id IS NOT NULL
                 GROUP BY account_id
             ) bl ON bl.account_id = a.id
             SET a.natural_category_id = COALESCE(a.natural_category_id, bl.natural_category_id),
                 a.functional_category_id = COALESCE(a.functional_category_id, bl.functional_category_id)
             WHERE a.natural_category_id IS NULL OR a.functional_category_id IS NULL'
        );

        // Drop FKs and columns on budget_lines
        $fkRes = $db->query(
            "SELECT CONSTRAINT_NAME, COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'budget_lines'
               AND COLUMN_NAME IN ('natural_category_id', 'functional_category_id')
               AND REFERENCED_TABLE_NAME IS NOT NULL"
        );
        if ($fkRes) {
            while ($fk = $fkRes->fetch_assoc()) {
                $name = $fk['CONSTRAINT_NAME'];
                @$db->query('ALTER TABLE budget_lines DROP FOREIGN KEY `' . $db->real_escape_string($name) . '`');
            }
            $fkRes->close();
        }
        @$db->query('ALTER TABLE budget_lines DROP INDEX idx_budget_lines_natural_category_id');
        @$db->query('ALTER TABLE budget_lines DROP INDEX idx_budget_lines_functional_category_id');
        @$db->query('ALTER TABLE budget_lines DROP COLUMN natural_category_id');
        @$db->query('ALTER TABLE budget_lines DROP COLUMN functional_category_id');
    }
    if ($blHasNat) {
        $blHasNat->close();
    }

    // Ensure account_id is NOT NULL and FK does not SET NULL on delete
    $aidCol = $db->query("SHOW COLUMNS FROM budget_lines LIKE 'account_id'");
    if ($aidCol && ($row = $aidCol->fetch_assoc())) {
        if (strtoupper((string)($row['Null'] ?? '')) === 'YES') {
            $db->query('DELETE FROM budget_lines WHERE account_id IS NULL OR account_id = 0');
            // Drop existing account FK (often ON DELETE SET NULL) before NOT NULL
            $fkRes = $db->query(
                "SELECT CONSTRAINT_NAME
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'budget_lines'
                   AND COLUMN_NAME = 'account_id'
                   AND REFERENCED_TABLE_NAME IS NOT NULL"
            );
            if ($fkRes) {
                while ($fk = $fkRes->fetch_assoc()) {
                    @$db->query('ALTER TABLE budget_lines DROP FOREIGN KEY `' . $db->real_escape_string($fk['CONSTRAINT_NAME']) . '`');
                }
                $fkRes->close();
            }
            @$db->query('ALTER TABLE budget_lines MODIFY account_id INT NOT NULL');
            @$db->query(
                'ALTER TABLE budget_lines
                 ADD CONSTRAINT fk_budget_lines_account
                 FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT'
            );
        }
        $aidCol->close();
    }
}

function budgetCurrentFiscalYear(): int {
    return (int)date('Y');
}

/**
 * Active (non-archived) accounts for budget line pickers, with category labels.
 *
 * @return list<array{id:int,name:string,natural_category_id:?int,functional_category_id:?int,natural_name:string,functional_name:string}>
 */
function budgetFetchAccountLookups(mysqli $db): array {
    budgetEnsureSimplifiedSchema($db);
    $rows = [];
    $sql = "SELECT a.id, a.name,
                   a.natural_category_id, a.functional_category_id,
                   COALESCE(nc.name, '') AS natural_name,
                   COALESCE(fc.name, '') AS functional_name
            FROM accounts a
            LEFT JOIN natural_categories nc ON nc.id = a.natural_category_id
            LEFT JOIN functional_categories fc ON fc.id = a.functional_category_id
            WHERE a.archived = FALSE
            ORDER BY a.name";
    $r = $db->query($sql);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $rows[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
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
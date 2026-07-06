<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

function budgetCurrentFiscalYear(): int {
    return (int)date('Y');
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
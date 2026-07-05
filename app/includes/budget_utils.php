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
         ORDER BY fiscal_year DESC, name"
    );
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $rows[] = $row;
        }
        $r->close();
    }
    return $rows;
}

function budgetFetchActiveForYear(mysqli $db, int $fiscalYear): ?array {
    $stmt = $db->prepare(
        "SELECT id, name, fiscal_year, start_date, end_date
         FROM budgets
         WHERE status = 'active' AND fiscal_year = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $fiscalYear);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function budgetResolveForYear(mysqli $db, int $fiscalYear): ?array {
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
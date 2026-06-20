<?php
    // Reports - Inner content only for AJAX loading

    if (!isset($db)) {
        require_once __DIR__ . '/../config.php';
        $db = getDbConnection();
    }

    $today = date('Y-m-d');
    $yearStart = date('Y') . '-01-01';

    // ── JSON API: run a report ──────────────────────────────────────────────
    if (isset($_GET['run_report'])) {
        header('Content-Type: application/json');
        $report = $_GET['report'] ?? '';
        $response = ['error' => 'Unknown report'];

        // Shared helpers
        $fundBalanceSql = "
            SELECT COALESCE(SUM(
                CASE WHEN tl.type = 'debit' THEN tl.amount ELSE -tl.amount END
            ), 0) AS balance
            FROM transaction_lines tl
            JOIN accounts a ON a.id = tl.account_id AND a.normal_balance = 'debit'
            JOIN transaction_details td ON td.id = tl.transaction_detail_id
            WHERE tl.fund_id = ?
        ";

        try {
            if ($report === 'fund-balances') {
                $asOf      = $_GET['as_of'] ?? $today;
                $fundType  = $_GET['fund_type'] ?? '';
                $incArch   = ($_GET['include_archived'] ?? '') === '1';

                $conds  = [];
                $params = [];
                $types  = '';
                if (!$incArch) {
                    $conds[] = 'f.archived = FALSE';
                }
                if ($fundType !== '') {
                    $conds[] = 'f.type = ?';
                    $params[] = $fundType;
                    $types .= 's';
                }
                $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

                $sql = "SELECT f.id, f.name, f.code, f.type, f.is_active, f.archived
                        FROM funds f $where
                        ORDER BY f.type, f.name";
                $stmt = $types ? $db->prepare($sql) : $db->query($sql);
                if ($types) {
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $res = $stmt->get_result();
                } else {
                    $res = $stmt;
                }

                $rows = [];
                $wodr = $wdr = $grand = 0.0;
                $balStmt = $db->prepare($fundBalanceSql . " AND td.transaction_date <= ?");
                while ($f = $res->fetch_assoc()) {
                    $fid = (int)$f['id'];
                    $balStmt->bind_param('is', $fid, $asOf);
                    $balStmt->execute();
                    $balance = (float)($balStmt->get_result()->fetch_assoc()['balance'] ?? 0);
                    $rows[] = [
                        'name'    => $f['name'],
                        'code'    => $f['code'] ?? '',
                        'type'    => $f['type'],
                        'balance' => $balance,
                        'active'  => (bool)$f['is_active'],
                        'archived'=> (bool)$f['archived'],
                    ];
                    if ($f['type'] === 'WODR') {
                        $wodr += $balance;
                    } else {
                        $wdr += $balance;
                    }
                    $grand += $balance;
                }
                if ($types) $stmt->close();
                $balStmt->close();

                $response = [
                    'generated' => date('Y-m-d H:i:s'),
                    'as_of'     => $asOf,
                    'rows'      => $rows,
                    'totals'    => ['wodr' => $wodr, 'wdr' => $wdr, 'grand' => $grand],
                ];

            } elseif ($report === 'transaction-listing') {
                $dateFrom  = $_GET['date_from'] ?? $yearStart;
                $dateTo    = $_GET['date_to'] ?? $today;
                $status    = strtolower(trim($_GET['status'] ?? ''));
                $search    = trim($_GET['search'] ?? '');
                $fundId    = (int)($_GET['fund_id'] ?? 0);
                $accountId = (int)($_GET['account_id'] ?? 0);
                $categoryId= (int)($_GET['category_id'] ?? 0);

                $conds  = [];
                $params = [];
                $types  = '';
                if ($dateFrom) {
                    $conds[] = 'td.transaction_date >= ?';
                    $params[] = $dateFrom;
                    $types .= 's';
                }
                if ($dateTo) {
                    $conds[] = 'td.transaction_date <= ?';
                    $params[] = $dateTo;
                    $types .= 's';
                }
                if ($status !== '') {
                    $conds[] = 'td.status = ?';
                    $params[] = $status;
                    $types .= 's';
                }
                if ($search !== '') {
                    $like = '%' . $search . '%';
                    $conds[] = "(td.pay_to LIKE ? OR td.reference_number LIKE ? OR td.check_number LIKE ? OR td.memo LIKE ?)";
                    $params = array_merge($params, [$like, $like, $like, $like]);
                    $types .= 'ssss';
                }
                if ($fundId > 0) {
                    $conds[] = "EXISTS (SELECT 1 FROM transaction_lines tl WHERE tl.transaction_detail_id = td.id AND tl.fund_id = ?)";
                    $params[] = $fundId;
                    $types .= 'i';
                }
                if ($accountId > 0) {
                    $conds[] = "EXISTS (SELECT 1 FROM transaction_lines tl WHERE tl.transaction_detail_id = td.id AND tl.account_id = ?)";
                    $params[] = $accountId;
                    $types .= 'i';
                }
                if ($categoryId > 0) {
                    $conds[] = "EXISTS (SELECT 1 FROM transaction_lines tl WHERE tl.transaction_detail_id = td.id AND tl.natural_category_id = ?)";
                    $params[] = $categoryId;
                    $types .= 'i';
                }
                $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

                $sql = "SELECT td.id, td.transaction_date, td.pay_to, td.reference_number,
                               td.check_number, td.status,
                               COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id = td.id), 0) AS total_amount,
                               (SELECT GROUP_CONCAT(DISTINCT f.name ORDER BY f.name SEPARATOR ', ')
                                FROM transaction_lines tl2
                                JOIN funds f ON f.id = tl2.fund_id
                                WHERE tl2.transaction_detail_id = td.id) AS fund_names,
                               (SELECT GROUP_CONCAT(DISTINCT nc.name ORDER BY nc.name SEPARATOR ', ')
                                FROM transaction_lines tl3
                                JOIN natural_categories nc ON nc.id = tl3.natural_category_id
                                WHERE tl3.transaction_detail_id = td.id) AS category_names
                        FROM transaction_details td
                        $where
                        ORDER BY td.transaction_date DESC, td.id DESC
                        LIMIT 500";
                $stmt = $db->prepare($sql);
                if ($types) $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();

                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'date'       => $r['transaction_date'],
                        'ref'        => $r['reference_number'] ?: ($r['check_number'] ?: '—'),
                        'pay_to'     => $r['pay_to'] ?? '',
                        'fund'       => $r['fund_names'] ?? '—',
                        'category'   => $r['category_names'] ?? '—',
                        'amount'     => (float)$r['total_amount'],
                        'status'     => $r['status'],
                    ];
                }
                $stmt->close();

                $response = [
                    'generated' => date('Y-m-d H:i:s'),
                    'count'     => count($rows),
                    'rows'      => $rows,
                ];

            } elseif ($report === 'budget-vs-actual') {
                $fiscalYear = (int)($_GET['fiscal_year'] ?? 0);
                $period     = $_GET['period'] ?? 'ytd';
                $groupBy    = $_GET['group_by'] ?? 'natural_category';
                $dateFrom   = $_GET['date_from'] ?? '';
                $dateTo     = $_GET['date_to'] ?? '';

                // Resolve budget for fiscal year (prefer active, then approved, then closed)
                $bStmt = $db->prepare("SELECT id, name, start_date, end_date, status
                                       FROM budgets
                                       WHERE fiscal_year = ?
                                       ORDER BY FIELD(status,'active','approved','closed','draft'), id DESC
                                       LIMIT 1");
                $bStmt->bind_param('i', $fiscalYear);
                $bStmt->execute();
                $budget = $bStmt->get_result()->fetch_assoc();
                $bStmt->close();

                if (!$budget) {
                    echo json_encode(['error' => "No budget found for fiscal year $fiscalYear"]);
                    exit;
                }

                $bStart = $budget['start_date'];
                $bEnd   = $budget['end_date'];
                if ($period === 'full') {
                    $pStart = $bStart;
                    $pEnd   = $bEnd;
                } elseif ($period === 'custom' && $dateFrom && $dateTo) {
                    $pStart = $dateFrom;
                    $pEnd   = $dateTo;
                } else {
                    // YTD: budget start through today (capped at budget end)
                    $pStart = $bStart;
                    $pEnd   = min($today, $bEnd);
                }

                // Budget lines grouped
                $groupCol = match ($groupBy) {
                    'account'            => 'bl.account_id',
                    'functional_category'=> 'bl.functional_category_id',
                    default              => 'bl.natural_category_id',
                };
                $labelJoin = match ($groupBy) {
                    'account'            => 'LEFT JOIN accounts lbl ON lbl.id = bl.account_id',
                    'functional_category'=> 'LEFT JOIN functional_categories lbl ON lbl.id = bl.functional_category_id',
                    default              => 'LEFT JOIN natural_categories lbl ON lbl.id = bl.natural_category_id',
                };
                $labelField = match ($groupBy) {
                    'account'            => "COALESCE(lbl.name, 'Unassigned Account')",
                    'functional_category'=> "COALESCE(lbl.name, 'Unassigned Class')",
                    default              => "COALESCE(lbl.name, 'Uncategorized')",
                };

                $bSql = "SELECT $groupCol AS gid, $labelField AS label,
                                SUM(bl.budgeted_amount) AS budgeted
                         FROM budget_lines bl
                         $labelJoin
                         WHERE bl.budget_id = ?
                         GROUP BY gid, label
                         ORDER BY label";
                $bQ = $db->prepare($bSql);
                $bid = (int)$budget['id'];
                $bQ->bind_param('i', $bid);
                $bQ->execute();
                $bRes = $bQ->get_result();
                $budgetMap = [];
                while ($b = $bRes->fetch_assoc()) {
                    $key = $b['gid'] ?? 'null';
                    $budgetMap[$key] = [
                        'label'    => $b['label'],
                        'budgeted' => (float)$b['budgeted'],
                        'actual'   => 0.0,
                    ];
                }
                $bQ->close();

                // Actuals from transactions in period
                $actGroupCol = match ($groupBy) {
                    'account'            => 'tl.account_id',
                    'functional_category'=> 'tl.functional_category_id',
                    default              => 'tl.natural_category_id',
                };
                $actLabelJoin = match ($groupBy) {
                    'account'            => 'LEFT JOIN accounts albl ON albl.id = tl.account_id',
                    'functional_category'=> 'LEFT JOIN functional_categories albl ON albl.id = tl.functional_category_id',
                    default              => 'LEFT JOIN natural_categories albl ON albl.id = tl.natural_category_id',
                };
                $actLabelField = match ($groupBy) {
                    'account'            => "COALESCE(albl.name, 'Unassigned Account')",
                    'functional_category'=> "COALESCE(albl.name, 'Unassigned Class')",
                    default              => "COALESCE(albl.name, 'Uncategorized')",
                };

                $aSql = "SELECT $actGroupCol AS gid, $actLabelField AS label,
                                SUM(CASE
                                    WHEN a.normal_balance = 'debit' AND tl.type = 'debit' THEN tl.amount
                                    WHEN a.normal_balance = 'debit' AND tl.type = 'credit' THEN -tl.amount
                                    WHEN a.normal_balance = 'credit' AND tl.type = 'credit' THEN tl.amount
                                    WHEN a.normal_balance = 'credit' AND tl.type = 'debit' THEN -tl.amount
                                    ELSE 0
                                END) AS actual
                         FROM transaction_lines tl
                         JOIN transaction_details td ON td.id = tl.transaction_detail_id
                         JOIN accounts a ON a.id = tl.account_id
                         $actLabelJoin
                         WHERE td.transaction_date >= ? AND td.transaction_date <= ?
                         GROUP BY gid, label";
                $aQ = $db->prepare($aSql);
                $aQ->bind_param('ss', $pStart, $pEnd);
                $aQ->execute();
                $aRes = $aQ->get_result();
                while ($a = $aRes->fetch_assoc()) {
                    $key = $a['gid'] ?? 'null';
                    $actual = abs((float)$a['actual']);
                    if (isset($budgetMap[$key])) {
                        $budgetMap[$key]['actual'] = $actual;
                    } else {
                        $budgetMap[$key] = [
                            'label'    => $a['label'],
                            'budgeted' => 0.0,
                            'actual'   => $actual,
                        ];
                    }
                }
                $aQ->close();

                $rows = [];
                $totBudget = $totActual = 0.0;
                foreach ($budgetMap as $item) {
                    $budgeted = $item['budgeted'];
                    $actual   = $item['actual'];
                    $variance = $budgeted - $actual;
                    $pct      = $budgeted > 0 ? round(($actual / $budgeted) * 100, 1) : ($actual > 0 ? 100 : 0);
                    $rows[] = [
                        'label'    => $item['label'],
                        'budgeted' => $budgeted,
                        'actual'   => $actual,
                        'variance' => $variance,
                        'pct_used' => $pct,
                    ];
                    $totBudget += $budgeted;
                    $totActual += $actual;
                }
                usort($rows, fn($a, $b) => strcmp($a['label'], $b['label']));

                $response = [
                    'generated'   => date('Y-m-d H:i:s'),
                    'budget_name' => $budget['name'],
                    'budget_status'=> $budget['status'],
                    'period_start'=> $pStart,
                    'period_end'  => $pEnd,
                    'group_by'    => $groupBy,
                    'rows'        => $rows,
                    'totals'      => [
                        'budget'   => $totBudget,
                        'actual'   => $totActual,
                        'variance' => $totBudget - $totActual,
                    ],
                ];

            } elseif ($report === 'restricted-funds') {
                $asOf      = $_GET['as_of'] ?? $today;
                $fundId    = (int)($_GET['fund_id'] ?? 0);
                $activeOnly= ($_GET['active_only'] ?? '1') === '1';
                $periodStart = $_GET['period_start'] ?? $yearStart;

                $conds  = ["f.type = 'WDR'"];
                $params = [];
                $types  = '';
                if ($activeOnly) {
                    $conds[] = 'f.is_active = TRUE';
                    $conds[] = 'f.archived = FALSE';
                }
                if ($fundId > 0) {
                    $conds[] = 'f.id = ?';
                    $params[] = $fundId;
                    $types .= 'i';
                }
                $where = 'WHERE ' . implode(' AND ', $conds);

                $sql = "SELECT f.id, f.name, f.code FROM funds f $where ORDER BY f.name";
                $stmt = $types ? $db->prepare($sql) : $db->query($sql);
                if ($types) {
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $res = $stmt->get_result();
                } else {
                    $res = $stmt;
                }

                $balBefore = $db->prepare($fundBalanceSql . " AND td.transaction_date < ?");
                $balAsOf   = $db->prepare($fundBalanceSql . " AND td.transaction_date <= ?");
                // Cash-only movements so beginning + inflows - outflows = ending balance
                $inflowQ   = $db->prepare("
                    SELECT COALESCE(SUM(tl.amount), 0) AS total
                    FROM transaction_lines tl
                    JOIN transaction_details td ON td.id = tl.transaction_detail_id
                    JOIN accounts a ON a.id = tl.account_id
                    WHERE tl.fund_id = ?
                      AND a.normal_balance = 'debit' AND tl.type = 'debit'
                      AND td.transaction_date >= ? AND td.transaction_date <= ?
                ");
                $outflowQ  = $db->prepare("
                    SELECT COALESCE(SUM(tl.amount), 0) AS total
                    FROM transaction_lines tl
                    JOIN transaction_details td ON td.id = tl.transaction_detail_id
                    JOIN accounts a ON a.id = tl.account_id
                    WHERE tl.fund_id = ?
                      AND a.normal_balance = 'debit' AND tl.type = 'credit'
                      AND td.transaction_date >= ? AND td.transaction_date <= ?
                ");

                $rows = [];
                while ($f = $res->fetch_assoc()) {
                    $fid = (int)$f['id'];

                    $balBefore->bind_param('is', $fid, $periodStart);
                    $balBefore->execute();
                    $beginning = (float)($balBefore->get_result()->fetch_assoc()['balance'] ?? 0);

                    $inflowQ->bind_param('iss', $fid, $periodStart, $asOf);
                    $inflowQ->execute();
                    $inflows = (float)($inflowQ->get_result()->fetch_assoc()['total'] ?? 0);

                    $outflowQ->bind_param('iss', $fid, $periodStart, $asOf);
                    $outflowQ->execute();
                    $outflows = (float)($outflowQ->get_result()->fetch_assoc()['total'] ?? 0);

                    $balAsOf->bind_param('is', $fid, $asOf);
                    $balAsOf->execute();
                    $ending = (float)($balAsOf->get_result()->fetch_assoc()['balance'] ?? 0);

                    $rows[] = [
                        'name'      => $f['name'],
                        'code'      => $f['code'] ?? '',
                        'beginning' => $beginning,
                        'inflows'   => $inflows,
                        'outflows'  => $outflows,
                        'ending'    => $ending,
                    ];
                }
                if ($types) $stmt->close();
                $balBefore->close();
                $balAsOf->close();
                $inflowQ->close();
                $outflowQ->close();

                $response = [
                    'generated'    => date('Y-m-d H:i:s'),
                    'as_of'        => $asOf,
                    'period_start' => $periodStart,
                    'rows'         => $rows,
                ];
            }
        } catch (Exception $e) {
            $response = ['error' => $e->getMessage()];
        }

        echo json_encode($response);
        exit;
    }

    // ── Load filter dropdown data ───────────────────────────────────────────
    $funds_list = [];
    $r = $db->query("SELECT id, name, code, type FROM funds WHERE is_active = TRUE AND archived = FALSE ORDER BY type, name");
    while ($row = $r->fetch_assoc()) $funds_list[] = $row;

    $wdr_funds = array_values(array_filter($funds_list, fn($f) => $f['type'] === 'WDR'));

    $accounts_list = [];
    $r = $db->query("SELECT id, name FROM accounts WHERE archived = FALSE ORDER BY name");
    while ($row = $r->fetch_assoc()) $accounts_list[] = $row;

    $categories_list = [];
    $r = $db->query("SELECT id, name FROM natural_categories WHERE archived = FALSE ORDER BY name");
    while ($row = $r->fetch_assoc()) $categories_list[] = $row;

    $budget_years = [];
    $r = $db->query("SELECT DISTINCT fiscal_year FROM budgets ORDER BY fiscal_year DESC");
    while ($row = $r->fetch_assoc()) $budget_years[] = (int)$row['fiscal_year'];
    if (!$budget_years) $budget_years = [(int)date('Y')];

    $filterData = json_encode([
        'today'      => $today,
        'yearStart'  => $yearStart,
        'funds'      => $funds_list,
        'wdrFunds'   => $wdr_funds,
        'accounts'   => $accounts_list,
        'categories' => $categories_list,
        'budgetYears'=> $budget_years,
    ]);
?>

<div class="container-fluid mt-2">
    <div class="mb-3">
        <h2 class="mb-1">Reports</h2>
        <p class="text-muted mb-0 small">Select a report to view details or export</p>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <div class="input-group input-group-sm" style="max-width: 320px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" id="reportSearch" class="form-control" placeholder="Search reports..." oninput="filterReports()">
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearReportSearch()">Clear</button>
        <span class="small text-muted ms-auto" id="reportCount">4 reports available</span>
    </div>

    <div class="row g-3" id="reportsGrid">

        <div class="col-md-6 col-xl-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-wallet2 fs-3 text-primary me-3 mt-1"></i>
                        <div class="flex-grow-1">
                            <h6 class="card-title mb-1">Fund Balances Report</h6>
                            <p class="card-text small text-muted mb-0">Current balances for all funds with subtotals by restriction type (WODR / WDR).</p>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0 pt-0 pb-3 px-3">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary btn-sm flex-grow-1" onclick="viewReport('fund-balances')">View</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportReport('fund-balances')" title="Export">
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-journal-text fs-3 text-primary me-3 mt-1"></i>
                        <div class="flex-grow-1">
                            <h6 class="card-title mb-1">Transaction Listing Report</h6>
                            <p class="card-text small text-muted mb-0">Searchable list of transactions for a selected date range with full details.</p>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0 pt-0 pb-3 px-3">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary btn-sm flex-grow-1" onclick="viewReport('transaction-listing')">View</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportReport('transaction-listing')" title="Export">
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-graph-up fs-3 text-primary me-3 mt-1"></i>
                        <div class="flex-grow-1">
                            <h6 class="card-title mb-1">Budget vs Actual Report</h6>
                            <p class="card-text small text-muted mb-0">Compare budgeted amounts to actual activity by category, account, or period.</p>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0 pt-0 pb-3 px-3">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary btn-sm flex-grow-1" onclick="viewReport('budget-vs-actual')">View</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportReport('budget-vs-actual')" title="Export">
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-lock fs-3 text-success me-3 mt-1"></i>
                        <div class="flex-grow-1">
                            <h6 class="card-title mb-1">Restricted Funds Status</h6>
                            <p class="card-text small text-muted mb-0">Activity, inflows, outflows, and remaining balances for donor-restricted funds.</p>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0 pt-0 pb-3 px-3">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary btn-sm flex-grow-1" onclick="viewReport('restricted-funds')">View</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportReport('restricted-funds')" title="Export">
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div id="reportViewer" class="mt-4 d-none">
        <div class="card border-primary shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center py-2 bg-light">
                <div id="viewerHeader" class="fw-semibold"></div>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closeViewer()">Close</button>
            </div>
            <div class="card-body">
                <div id="viewerFilters" class="mb-3"></div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-primary btn-sm" onclick="runCurrentReport()">
                        <i class="bi bi-play-fill"></i> Run Report
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportCurrentReport()">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>
                <div id="viewerResults"></div>
            </div>
        </div>
    </div>
</div>

<script type="text/plain" id="init-reports-script">
(function() {
    const FD = <?= $filterData ?>;
    let currentReportKey = null;

    const reportTitles = {
        'fund-balances': 'Fund Balances Report',
        'transaction-listing': 'Transaction Listing Report',
        'budget-vs-actual': 'Budget vs Actual Report',
        'restricted-funds': 'Restricted Funds Status Report'
    };

    const reportIcons = {
        'fund-balances': '<i class="bi bi-wallet2 text-primary me-2"></i>',
        'transaction-listing': '<i class="bi bi-journal-text text-primary me-2"></i>',
        'budget-vs-actual': '<i class="bi bi-graph-up text-primary me-2"></i>',
        'restricted-funds': '<i class="bi bi-lock text-success me-2"></i>'
    };

    function fmtMoney(n) {
        const v = parseFloat(n) || 0;
        const neg = v < 0;
        return (neg ? '-' : '') + '$' + Math.abs(v).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function statusBadge(status) {
        const map = {pending: 'secondary', cleared: 'success', reconciled: 'info'};
        const cls = map[status] || 'secondary';
        const label = status ? status.charAt(0).toUpperCase() + status.slice(1) : '—';
        return '<span class="badge bg-' + cls + '">' + label + '</span>';
    }

    function optsFromList(list, allLabel) {
        let h = '<option value="">' + allLabel + '</option>';
        list.forEach(i => { h += '<option value="' + i.id + '">' + i.name + '</option>'; });
        return h;
    }

    function filterReports() {
        const term = (document.getElementById('reportSearch')?.value || '').toLowerCase().trim();
        const cards = document.querySelectorAll('#reportsGrid > div');
        let visible = 0;
        cards.forEach(cardCol => {
            const show = !term || cardCol.textContent.toLowerCase().includes(term);
            cardCol.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        const countEl = document.getElementById('reportCount');
        if (countEl) countEl.textContent = visible + ' report' + (visible === 1 ? '' : 's') + ' available';
    }

    function clearReportSearch() {
        const input = document.getElementById('reportSearch');
        if (input) input.value = '';
        filterReports();
    }

    function getFiltersHTML(key) {
        if (key === 'fund-balances') {
            return `
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small mb-1">As of Date</label>
                        <input type="date" id="fb-date" class="form-control form-control-sm" value="${FD.today}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Fund Type</label>
                        <select id="fb-type" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            <option value="WODR">WODR - Without Donor Restrictions</option>
                            <option value="WDR">WDR - With Donor Restrictions</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check mt-3 mt-md-2">
                            <input class="form-check-input" type="checkbox" id="fb-include-archived">
                            <label class="form-check-label small" for="fb-include-archived">Include archived funds</label>
                        </div>
                    </div>
                </div>`;
        }
        if (key === 'transaction-listing') {
            return `
                <div class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label small mb-1">Date From</label>
                        <input type="date" id="tl-from" class="form-control form-control-sm" value="${FD.yearStart}">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Date To</label>
                        <input type="date" id="tl-to" class="form-control form-control-sm" value="${FD.today}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Fund</label>
                        <select id="tl-fund" class="form-select form-select-sm">${optsFromList(FD.funds, 'All Funds')}</select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Account</label>
                        <select id="tl-account" class="form-select form-select-sm">${optsFromList(FD.accounts, 'All Accounts')}</select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Category</label>
                        <select id="tl-category" class="form-select form-select-sm">${optsFromList(FD.categories, 'All Categories')}</select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Status</label>
                        <select id="tl-status" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="pending">Pending</option>
                            <option value="cleared">Cleared</option>
                            <option value="reconciled">Reconciled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Search</label>
                        <input type="text" id="tl-search" class="form-control form-control-sm" placeholder="Pay to, ref, memo...">
                    </div>
                </div>`;
        }
        if (key === 'budget-vs-actual') {
            const yearOpts = FD.budgetYears.map(y => '<option value="' + y + '">' + y + '</option>').join('');
            return `
                <div class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Fiscal Year</label>
                        <select id="ba-year" class="form-select form-select-sm">${yearOpts}</select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Period</label>
                        <select id="ba-period" class="form-select form-select-sm">
                            <option value="ytd">Year to Date</option>
                            <option value="full">Full Budget Year</option>
                            <option value="custom">Custom Range</option>
                        </select>
                    </div>
                    <div class="col-auto" id="ba-custom-dates" style="display:none">
                        <label class="form-label small mb-1">From / To</label>
                        <div class="d-flex gap-1">
                            <input type="date" id="ba-from" class="form-control form-control-sm" value="${FD.yearStart}">
                            <input type="date" id="ba-to" class="form-control form-control-sm" value="${FD.today}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Group By</label>
                        <select id="ba-group" class="form-select form-select-sm">
                            <option value="natural_category">Natural Category</option>
                            <option value="account">Account</option>
                            <option value="functional_category">Functional Class</option>
                        </select>
                    </div>
                </div>`;
        }
        if (key === 'restricted-funds') {
            return `
                <div class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Period Start</label>
                        <input type="date" id="rf-period-start" class="form-control form-control-sm" value="${FD.yearStart}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">As of Date</label>
                        <input type="date" id="rf-date" class="form-control form-control-sm" value="${FD.today}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Fund</label>
                        <select id="rf-fund" class="form-select form-select-sm">${optsFromList(FD.wdrFunds, 'All Restricted Funds')}</select>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check mt-3 mt-md-2">
                            <input class="form-check-input" type="checkbox" id="rf-active-only" checked>
                            <label class="form-check-label small" for="rf-active-only">Active funds only</label>
                        </div>
                    </div>
                </div>`;
        }
        return '<p class="text-muted small">No filters defined.</p>';
    }

    function collectParams(key) {
        const p = { report: key };
        if (key === 'fund-balances') {
            p.as_of = document.getElementById('fb-date')?.value || FD.today;
            p.fund_type = document.getElementById('fb-type')?.value || '';
            p.include_archived = document.getElementById('fb-include-archived')?.checked ? '1' : '0';
        } else if (key === 'transaction-listing') {
            p.date_from = document.getElementById('tl-from')?.value || '';
            p.date_to = document.getElementById('tl-to')?.value || '';
            p.fund_id = document.getElementById('tl-fund')?.value || '';
            p.account_id = document.getElementById('tl-account')?.value || '';
            p.category_id = document.getElementById('tl-category')?.value || '';
            p.status = document.getElementById('tl-status')?.value || '';
            p.search = document.getElementById('tl-search')?.value || '';
        } else if (key === 'budget-vs-actual') {
            p.fiscal_year = document.getElementById('ba-year')?.value || '';
            p.period = document.getElementById('ba-period')?.value || 'ytd';
            p.group_by = document.getElementById('ba-group')?.value || 'natural_category';
            if (p.period === 'custom') {
                p.date_from = document.getElementById('ba-from')?.value || '';
                p.date_to = document.getElementById('ba-to')?.value || '';
            }
        } else if (key === 'restricted-funds') {
            p.as_of = document.getElementById('rf-date')?.value || FD.today;
            p.period_start = document.getElementById('rf-period-start')?.value || FD.yearStart;
            p.fund_id = document.getElementById('rf-fund')?.value || '';
            p.active_only = document.getElementById('rf-active-only')?.checked ? '1' : '0';
        }
        return p;
    }

    function renderResults(key, data) {
        if (data.error) {
            return '<div class="alert alert-danger py-2 small">' + data.error + '</div>';
        }

        if (key === 'fund-balances') {
            let rows = '';
            data.rows.forEach(r => {
                const cls = r.type === 'WODR' ? 'text-primary' : 'text-success';
                const arch = r.archived ? ' <span class="badge bg-secondary">Archived</span>' : '';
                rows += '<tr><td>' + r.name + arch + '</td><td>' + (r.code || '—') + '</td><td>' + r.type + '</td><td class="text-end ' + cls + '">' + fmtMoney(r.balance) + '</td></tr>';
            });
            if (!data.rows.length) rows = '<tr><td colspan="4" class="text-center text-muted py-3">No funds match the selected filters.</td></tr>';
            return `
                <div class="small text-muted mb-2">Generated ${data.generated} &bull; As of ${data.as_of}</div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead class="table-dark"><tr><th>Fund</th><th>Code</th><th>Type</th><th class="text-end">Balance</th></tr></thead>
                        <tbody>${rows}</tbody>
                        <tfoot class="table-light">
                            <tr><th colspan="3">Total WODR</th><th class="text-end text-primary">${fmtMoney(data.totals.wodr)}</th></tr>
                            <tr><th colspan="3">Total WDR</th><th class="text-end text-success">${fmtMoney(data.totals.wdr)}</th></tr>
                            <tr class="fw-bold"><th colspan="3">Grand Total</th><th class="text-end">${fmtMoney(data.totals.grand)}</th></tr>
                        </tfoot>
                    </table>
                </div>`;
        }

        if (key === 'transaction-listing') {
            let rows = '';
            data.rows.forEach(r => {
                rows += '<tr><td>' + r.date + '</td><td>' + r.ref + '</td><td>' + (r.pay_to || '—') + '</td><td>' + r.fund + '</td><td class="small">' + r.category + '</td><td class="text-end">' + fmtMoney(r.amount) + '</td><td>' + statusBadge(r.status) + '</td></tr>';
            });
            if (!data.rows.length) rows = '<tr><td colspan="7" class="text-center text-muted py-3">No transactions match the selected filters.</td></tr>';
            return `
                <div class="small text-muted mb-2">Generated ${data.generated} &bull; ${data.count} transaction${data.count === 1 ? '' : 's'}</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-dark"><tr><th>Date</th><th>Ref #</th><th>Pay To</th><th>Fund</th><th>Category</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>`;
        }

        if (key === 'budget-vs-actual') {
            let rows = '';
            data.rows.forEach(r => {
                const varCls = r.variance >= 0 ? 'text-success' : 'text-danger';
                const sign = r.variance >= 0 ? '+' : '';
                rows += '<tr><td>' + r.label + '</td><td class="text-end">' + fmtMoney(r.budgeted) + '</td><td class="text-end">' + fmtMoney(r.actual) + '</td><td class="text-end ' + varCls + '">' + sign + fmtMoney(r.variance) + '</td><td class="text-end">' + r.pct_used + '%</td></tr>';
            });
            if (!data.rows.length) rows = '<tr><td colspan="5" class="text-center text-muted py-3">No budget lines found for this period.</td></tr>';
            const netCls = data.totals.variance >= 0 ? 'text-success' : 'text-danger';
            const netSign = data.totals.variance >= 0 ? '+' : '';
            return `
                <div class="small text-muted mb-2">Generated ${data.generated} &bull; ${data.budget_name} (${data.budget_status}) &bull; ${data.period_start} to ${data.period_end}</div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead class="table-dark"><tr><th>Category</th><th class="text-end">Budget</th><th class="text-end">Actual</th><th class="text-end">Variance</th><th class="text-end">% Used</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
                <div class="small mt-2"><strong>Net variance:</strong> <span class="${netCls}">${netSign}${fmtMoney(data.totals.variance)}</span></div>`;
        }

        if (key === 'restricted-funds') {
            let rows = '';
            data.rows.forEach(r => {
                rows += '<tr><td>' + r.name + (r.code ? ' <span class="text-muted small">(' + r.code + ')</span>' : '') + '</td><td class="text-end">' + fmtMoney(r.beginning) + '</td><td class="text-end text-success">+' + fmtMoney(r.inflows).replace('$','') + '</td><td class="text-end text-danger">' + fmtMoney(r.outflows) + '</td><td class="text-end fw-bold">' + fmtMoney(r.ending) + '</td></tr>';
            });
            if (!data.rows.length) rows = '<tr><td colspan="5" class="text-center text-muted py-3">No restricted funds match the selected filters.</td></tr>';
            return `
                <div class="small text-muted mb-2">Generated ${data.generated} &bull; Period ${data.period_start} to ${data.as_of}</div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead class="table-dark"><tr><th>Fund</th><th class="text-end">Beginning</th><th class="text-end">Inflows</th><th class="text-end">Outflows</th><th class="text-end">Ending Balance</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>`;
        }

        return '<div class="text-muted small">No results.</div>';
    }

    function viewReport(key) {
        currentReportKey = key;
        const viewer = document.getElementById('reportViewer');
        const header = document.getElementById('viewerHeader');
        const filters = document.getElementById('viewerFilters');
        const results = document.getElementById('viewerResults');
        if (!viewer || !header || !filters || !results) return;

        header.innerHTML = (reportIcons[key] || '') + (reportTitles[key] || key);
        filters.innerHTML = getFiltersHTML(key);
        results.innerHTML = '<div class="text-muted small fst-italic">Configure filters above and click "Run Report".</div>';
        viewer.classList.remove('d-none');
        viewer.scrollIntoView({ behavior: 'smooth', block: 'start' });

        const baPeriod = document.getElementById('ba-period');
        if (baPeriod) {
            baPeriod.addEventListener('change', () => {
                const el = document.getElementById('ba-custom-dates');
                if (el) el.style.display = baPeriod.value === 'custom' ? '' : 'none';
            });
        }
    }

    function closeViewer() {
        const viewer = document.getElementById('reportViewer');
        if (viewer) viewer.classList.add('d-none');
        currentReportKey = null;
    }

    function runCurrentReport() {
        if (!currentReportKey) return;
        const results = document.getElementById('viewerResults');
        if (!results) return;

        results.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> Running report...</div>';

        const params = collectParams(currentReportKey);
        const qs = new URLSearchParams({ run_report: '1', ...params }).toString();

        fetch('pages/reports.php?' + qs)
            .then(r => r.json())
            .then(data => { results.innerHTML = renderResults(currentReportKey, data); })
            .catch(err => {
                results.innerHTML = '<div class="alert alert-danger py-2 small">Failed to load report: ' + err.message + '</div>';
            });
    }

    function exportCurrentReport() {
        if (!currentReportKey) { alert('Please open a report first.'); return; }
        runCurrentReport();
    }

    function exportReport(key) {
        viewReport(key);
        setTimeout(runCurrentReport, 300);
    }

    const searchInput = document.getElementById('reportSearch');
    if (searchInput) searchInput.addEventListener('input', filterReports);

    window.viewReport = viewReport;
    window.exportReport = exportReport;
    window.clearReportSearch = clearReportSearch;
    window.runCurrentReport = runCurrentReport;
    window.closeViewer = closeViewer;
    window.exportCurrentReport = exportCurrentReport;
    window.filterReports = filterReports;

    filterReports();
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-reports-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>
<?php
    // Ledger - Inner content only for AJAX loading

require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/ledger_engine.php';
require_once __DIR__ . '/../includes/budget_utils.php';
require_once __DIR__ . '/../includes/permissions.php';

    $success = null;
    $error = null;
    $ledgerActor = getCurrentUser();
    $canWriteLedger = $ledgerActor && userHasPermission($db, (int)$ledgerActor['id'], 'page.ledger.write');
    // Ensure budget_id column exists before any ledger queries
    ledgerRequireTables($db);

    if (isset($_GET['download_document']) || isset($_GET['preview_document'])) {
        $isPreview = isset($_GET['preview_document']);
        $docId = (int)($isPreview ? $_GET['preview_document'] : $_GET['download_document']);
        $doc = ledgerFetchDocument($db, $docId);
        if (!$doc) {
            http_response_code(404);
            echo 'Document not found.';
            exit;
        }
        $ref = ledgerNormalizeReferenceNumber($doc['reference_number'] ?? null);
        $path = ledgerResolveDocumentPath(
            (int)$doc['transaction_detail_id'],
            $doc['stored_filename'],
            preg_match('/^\d{6}$/', $ref) ? $ref : null
        );
        if (!$path) {
            http_response_code(404);
            echo 'File not found.';
            exit;
        }
        $mime = $doc['mime_type'] ?: 'application/octet-stream';
        $filename = basename($doc['original_filename']);
        $safeName = str_replace(['"', "\r", "\n"], '', $filename);
        header('Content-Type: ' . $mime);
        if ($isPreview) {
            header('Content-Disposition: inline; filename="' . $safeName . '"');
            header('X-Content-Type-Options: nosniff');
        } else {
            header('Content-Disposition: attachment; filename="' . $safeName . '"');
        }
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    if (isset($_GET['document_meta'])) {
        header('Content-Type: application/json');
        $docId = (int)$_GET['document_meta'];
        $doc = ledgerFetchDocument($db, $docId);
        if (!$doc) {
            echo json_encode(['success' => false, 'error' => 'Document not found.']);
            exit;
        }
        $kind = ledgerDocumentPreviewKind($doc['mime_type'] ?? null, $doc['original_filename'] ?? '');
        echo json_encode([
            'success' => true,
            'id' => (int)$doc['id'],
            'original_filename' => $doc['original_filename'],
            'mime_type' => $doc['mime_type'],
            'file_size' => (int)$doc['file_size'],
            'preview_kind' => $kind,
            'preview_url' => 'pages/ledger.php?preview_document=' . (int)$doc['id'],
            'download_url' => 'pages/ledger.php?download_document=' . (int)$doc['id'],
        ]);
        exit;
    }

    // Lightweight attachment list for the ledger portfolio viewer
    if (isset($_GET['transaction_documents'])) {
        header('Content-Type: application/json; charset=utf-8');
        $txId = (int)$_GET['transaction_documents'];
        if ($txId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid transaction.']);
            exit;
        }
        $tx = ledgerFetchTransaction($db, $txId);
        if (!$tx) {
            echo json_encode(['success' => false, 'error' => 'Transaction not found.']);
            exit;
        }
        $docs = [];
        foreach (($tx['documents'] ?? []) as $doc) {
            $docs[] = ledgerDocumentClientPayload($doc);
        }
        echo json_encode([
            'success' => true,
            'transaction_id' => $txId,
            'reference_number' => $tx['reference_number'] ?? '',
            'pay_to' => $tx['pay_to'] ?? '',
            'transaction_date' => $tx['transaction_date'] ?? '',
            'description' => $tx['description'] ?? '',
            'documents' => $docs,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Reference # (YY####) helpers: shadow default, reuse check, used-list modal
    // Accept reference_api (preferred) or sequence_api (legacy alias)
    if (isset($_GET['reference_api']) || isset($_GET['sequence_api'])) {
        header('Content-Type: application/json; charset=utf-8');
        $api = (string)($_GET['reference_api'] ?? $_GET['sequence_api'] ?? '');
        if ($api === 'suggest') {
            $date = trim((string)($_GET['date'] ?? ''));
            $kind = trim((string)($_GET['kind'] ?? 'other'));
            $range = ledgerReferenceRangeForKind($kind);
            $suggested = ledgerSuggestNextReferenceNumber($db, $date !== '' ? $date : null, $range['kind']);
            echo json_encode([
                'success' => true,
                'suggested' => $suggested,
                'date' => $date ?: date('Y-m-d'),
                'kind' => $range['kind'],
                'range' => [
                    'min' => $range['min'],
                    'max' => $range['max'],
                    'hint' => $range['hint'],
                    'label' => $range['label'],
                ],
            ]);
            exit;
        }
        if ($api === 'check') {
            $raw = $_GET['ref'] ?? $_GET['seq'] ?? '';
            $kind = trim((string)($_GET['kind'] ?? 'other'));
            $refCheck = ledgerValidateReferenceNumber($raw, true);
            if (empty($refCheck['ok'])) {
                echo json_encode([
                    'success' => false,
                    'taken' => false,
                    'error' => $refCheck['error'] ?? 'Invalid Reference #.',
                ]);
                exit;
            }
            $excludeId = (int)($_GET['exclude_id'] ?? 0);
            $usage = ledgerReferenceUsage($db, (string)$refCheck['value'], $excludeId > 0 ? $excludeId : null);
            $advisory = ledgerReferenceRangeAdvisory((string)$refCheck['value'], $kind);
            echo json_encode([
                'success' => true,
                'taken' => $usage !== null,
                'reference_number' => $refCheck['value'],
                'usage' => $usage,
                'range_advisory' => $advisory,
                'kind' => ledgerReferenceRangeForKind($kind)['kind'],
            ]);
            exit;
        }
        if ($api === 'list') {
            $items = ledgerListUsedReferenceNumbers($db, 2000);
            echo json_encode(['success' => true, 'items' => $items, 'count' => count($items)]);
            exit;
        }
        echo json_encode(['success' => false, 'error' => 'Unknown reference_api']);
        exit;
    }

    // JSON data endpoint for edit (fetched by JS, does not render HTML)
    if (isset($_GET['get_transaction'])) {
        $id = (int)$_GET['get_transaction'];
        if ($id <= 0) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid ID']);
            exit;
        }
        $det = ledgerFetchTransaction($db, $id);
        if (!$det) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Transaction not found']);
            exit;
        }
        // Single description column (no memo / no " | " split)
        $det['description'] = $det['description'] ?? '';
        $det['budget_id'] = !empty($det['budget_id']) ? (int)$det['budget_id'] : '';
        $lines = [];
        foreach ($det['lines'] as $l) {
            $lineType = strtolower(trim((string)($l['type'] ?? 'debit')));
            if ($lineType !== 'debit' && $lineType !== 'credit') {
                $lineType = 'debit';
            }
            $lines[] = [
                'account_id' => (int)$l['account_id'],
                'fund_id' => $l['fund_id'] !== null ? (int)$l['fund_id'] : '',
                'amount' => $l['amount'],
                'type' => $lineType,
                'natural_category_id' => $l['natural_category_id'] !== null ? (int)$l['natural_category_id'] : '',
                'functional_category_id' => $l['functional_category_id'] !== null ? (int)$l['functional_category_id'] : ''
            ];
        }
        $det['lines'] = $lines;
        header('Content-Type: application/json');
        echo json_encode($det);
        exit;
    }

    /**
     * Normalize multi-value request params into a string list.
     * Accepts: key as array, key as comma/|| delimited string, or singular scalar.
     * Empty strings are preserved (Excel "(Blanks)").
     * Sentinel "__NONE__" means apply a no-match filter.
     *
     * @return list<string>
     */
    $ledgerParseMultiStrings = static function (array $src, string $key, ?string $altKey = null): array {
        $raw = $src[$key] ?? null;
        if ($raw === null && $altKey !== null) {
            $raw = $src[$altKey] ?? null;
        }
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $v) {
                $out[] = trim((string)$v);
            }
            return array_values(array_unique($out));
        }
        $s = trim((string)$raw);
        if ($s === '') {
            return [];
        }
        // Delimited multi: a||b or a,b when clearly multi
        if (str_contains($s, '||')) {
            return array_values(array_unique(array_map('trim', explode('||', $s))));
        }
        return [$s];
    };

    /**
     * Normalize multi-value integer id lists (account_id / fund_id).
     *
     * @return list<int>
     */
    $ledgerParseMultiIds = static function (array $src, string $key, ?string $altKey = null): array {
        $raw = $src[$key] ?? null;
        if ($raw === null && $altKey !== null) {
            $raw = $src[$altKey] ?? null;
        }
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (!is_array($raw)) {
            $raw = [$raw];
        }
        $out = [];
        foreach ($raw as $v) {
            $i = (int)$v;
            if ($i > 0) {
                $out[] = $i;
            }
        }
        return array_values(array_unique($out));
    };

    /**
     * Append an equality/IN filter for a string column, supporting blanks and __NONE__.
     *
     * @param list<string> $values
     * @param-out list<string> $conditions
     * @param-out list<mixed> $bind_params
     * @param-out string $bind_types
     */
    $ledgerAddStringMultiFilter = static function (
        string $columnExpr,
        array $values,
        array &$conditions,
        array &$bind_params,
        string &$bind_types
    ): void {
        if ($values === []) {
            return;
        }
        if (count($values) === 1 && $values[0] === '__NONE__') {
            $conditions[] = '1=0';
            return;
        }
        $hasBlank = false;
        $nonBlank = [];
        foreach ($values as $v) {
            if ($v === '' || $v === '__BLANK__') {
                $hasBlank = true;
            } elseif ($v !== '__NONE__') {
                $nonBlank[] = $v;
            }
        }
        $parts = [];
        if ($nonBlank !== []) {
            $ph = implode(',', array_fill(0, count($nonBlank), '?'));
            $parts[] = "$columnExpr IN ($ph)";
            foreach ($nonBlank as $v) {
                $bind_params[] = $v;
                $bind_types .= 's';
            }
        }
        if ($hasBlank) {
            $parts[] = "($columnExpr IS NULL OR $columnExpr = '')";
        }
        if ($parts === []) {
            $conditions[] = '1=0';
            return;
        }
        $conditions[] = '(' . implode(' OR ', $parts) . ')';
    };

    /**
     * Build ledger list WHERE clause + bind params from request filters.
     * Multi-select Excel-style filters (dates[], pay_to[], status[], account_id[], …).
     * Used by HTML first paint, JSON infinite-scroll, and filter-values endpoint.
     *
     * @param list<string>|null $excludeColumns Column keys to skip (when loading that column's unique values)
     * @return array{conditions: string[], bind_params: array, bind_types: string, filter_account_id: int, filter_fund_id: int, view_normal: string, filters: array}
     */
    $ledgerBuildListFilters = static function (mysqli $db, array $src, ?array $excludeColumns = null) use (
        $ledgerParseMultiStrings,
        $ledgerParseMultiIds,
        $ledgerAddStringMultiFilter
    ): array {
        $exclude = [];
        if (is_array($excludeColumns)) {
            foreach ($excludeColumns as $c) {
                $exclude[strtolower((string)$c)] = true;
            }
        }
        $skip = static function (string $col) use ($exclude): bool {
            return isset($exclude[$col]);
        };

        // ── Multi-select lists ──────────────────────────────────────────────
        $dates = $skip('date') ? [] : $ledgerParseMultiStrings($src, 'date', 'dates');
        // Legacy date range still honored when multi dates not set
        $date_from = $skip('date') ? '' : trim((string)($src['date_from'] ?? ''));
        $date_to = $skip('date') ? '' : trim((string)($src['date_to'] ?? ''));

        $references = $skip('reference') ? [] : $ledgerParseMultiStrings($src, 'reference', 'references');
        if ($references === [] && !$skip('reference')) {
            $legacyRef = trim((string)($src['reference_number'] ?? ''));
            if ($legacyRef !== '') {
                $references = [$legacyRef];
            }
        }
        $descriptions = $skip('description') ? [] : $ledgerParseMultiStrings($src, 'description', 'descriptions');
        $pay_tos = $skip('pay_to') ? [] : $ledgerParseMultiStrings($src, 'pay_to', 'pay_tos');
        $statuses = $skip('status') ? [] : $ledgerParseMultiStrings($src, 'status', 'statuses');
        $amounts = $skip('amount') ? [] : $ledgerParseMultiStrings($src, 'amount', 'amounts');

        $account_ids = $skip('account') ? [] : $ledgerParseMultiIds($src, 'account_id', 'account_ids');
        $fund_ids = $skip('fund') ? [] : $ledgerParseMultiIds($src, 'fund_id', 'fund_ids');

        $check_number = trim((string)($src['check_number'] ?? ''));
        $search = trim((string)($src['search'] ?? ''));
        $amount_min = $skip('amount') ? '' : trim((string)($src['amount_min'] ?? ''));
        $amount_max = $skip('amount') ? '' : trim((string)($src['amount_max'] ?? ''));

        $allowedStatus = ['pending', 'cleared', 'reconciled'];
        $statuses = array_values(array_filter(
            $statuses,
            static fn($s) => $s === '__NONE__' || $s === '' || $s === '__BLANK__' || in_array(strtolower($s), $allowedStatus, true)
        ));
        $statuses = array_map(static function ($s) {
            if ($s === '' || $s === '__BLANK__' || $s === '__NONE__') {
                return $s;
            }
            return strtolower($s);
        }, $statuses);

        // Validate ISO dates
        $dates = array_values(array_filter($dates, static function ($d) {
            if ($d === '__NONE__') {
                return true;
            }
            return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        }));

        $conditions = [];
        $bind_params = [];
        $bind_types = '';

        // Dates: multi-select exact match, or legacy from/to range
        if ($dates !== []) {
            if (count($dates) === 1 && $dates[0] === '__NONE__') {
                $conditions[] = '1=0';
            } else {
                $realDates = array_values(array_filter($dates, static fn($d) => $d !== '__NONE__'));
                if ($realDates === []) {
                    $conditions[] = '1=0';
                } else {
                    $ph = implode(',', array_fill(0, count($realDates), '?'));
                    $conditions[] = "td.transaction_date IN ($ph)";
                    foreach ($realDates as $d) {
                        $bind_params[] = $d;
                        $bind_types .= 's';
                    }
                }
            }
        } else {
            if ($date_from !== '') {
                $conditions[] = 'td.transaction_date >= ?';
                $bind_params[] = $date_from;
                $bind_types .= 's';
            }
            if ($date_to !== '') {
                $conditions[] = 'td.transaction_date <= ?';
                $bind_params[] = $date_to;
                $bind_types .= 's';
            }
        }

        $ledgerAddStringMultiFilter('td.reference_number', $references, $conditions, $bind_params, $bind_types);
        $ledgerAddStringMultiFilter('td.description', $descriptions, $conditions, $bind_params, $bind_types);
        $ledgerAddStringMultiFilter('td.pay_to', $pay_tos, $conditions, $bind_params, $bind_types);
        $ledgerAddStringMultiFilter('td.status', $statuses, $conditions, $bind_params, $bind_types);

        if ($check_number !== '') {
            $like = '%' . $check_number . '%';
            $conditions[] = 'td.check_number LIKE ?';
            $bind_params[] = $like;
            $bind_types .= 's';
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $conditions[] = '(td.pay_to LIKE ? OR td.reference_number LIKE ? OR td.check_number LIKE ? OR td.description LIKE ? OR CAST(COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id), 0) AS CHAR) LIKE ?)';
            $bind_params = array_merge($bind_params, [$like, $like, $like, $like, $like]);
            $bind_types .= str_repeat('s', 5);
        }

        if ($account_ids !== []) {
            if (count($account_ids) === 1) {
                $conditions[] = 'EXISTS (SELECT 1 FROM transaction_lines tl WHERE tl.transaction_detail_id = td.id AND tl.account_id = ?)';
                $bind_params[] = $account_ids[0];
                $bind_types .= 'i';
            } else {
                $ph = implode(',', array_fill(0, count($account_ids), '?'));
                $conditions[] = "EXISTS (SELECT 1 FROM transaction_lines tl WHERE tl.transaction_detail_id = td.id AND tl.account_id IN ($ph))";
                foreach ($account_ids as $aid) {
                    $bind_params[] = $aid;
                    $bind_types .= 'i';
                }
            }
        }
        if ($fund_ids !== []) {
            if (count($fund_ids) === 1) {
                $conditions[] = 'EXISTS (SELECT 1 FROM transaction_lines tl WHERE tl.transaction_detail_id = td.id AND tl.fund_id = ?)';
                $bind_params[] = $fund_ids[0];
                $bind_types .= 'i';
            } else {
                $ph = implode(',', array_fill(0, count($fund_ids), '?'));
                $conditions[] = "EXISTS (SELECT 1 FROM transaction_lines tl WHERE tl.transaction_detail_id = td.id AND tl.fund_id IN ($ph))";
                foreach ($fund_ids as $fid) {
                    $bind_params[] = $fid;
                    $bind_types .= 'i';
                }
            }
        }

        $totalExpr = 'COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id), 0)';
        $debitExpr = "COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id AND type='debit'), 0)";
        $creditExpr = "COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id AND type='credit'), 0)";

        // Amount multi-select: match when rounded debit total OR credit total equals any selected amount
        if ($amounts !== []) {
            if (count($amounts) === 1 && $amounts[0] === '__NONE__') {
                $conditions[] = '1=0';
            } else {
                $nums = [];
                foreach ($amounts as $a) {
                    if ($a === '__NONE__' || $a === '' || $a === '__BLANK__') {
                        continue;
                    }
                    if (is_numeric($a)) {
                        $nums[] = round((float)$a, 2);
                    }
                }
                $nums = array_values(array_unique($nums));
                if ($nums === []) {
                    $conditions[] = '1=0';
                } else {
                    $ph = implode(',', array_fill(0, count($nums), '?'));
                    $conditions[] = "(ROUND($debitExpr, 2) IN ($ph) OR ROUND($creditExpr, 2) IN ($ph))";
                    // bind twice (debit IN + credit IN)
                    foreach ($nums as $n) {
                        $bind_params[] = $n;
                        $bind_types .= 'd';
                    }
                    foreach ($nums as $n) {
                        $bind_params[] = $n;
                        $bind_types .= 'd';
                    }
                }
            }
        }
        if ($amount_min !== '' && is_numeric($amount_min)) {
            $conditions[] = "$debitExpr >= ?";
            $bind_params[] = (float)$amount_min;
            $bind_types .= 'd';
        }
        if ($amount_max !== '' && is_numeric($amount_max)) {
            $conditions[] = "$debitExpr <= ?";
            $bind_params[] = (float)$amount_max;
            $bind_types .= 'd';
        }

        // Single-account view mode only when exactly one account is filtered
        $filter_account_id = count($account_ids) === 1 ? $account_ids[0] : 0;
        $filter_fund_id = count($fund_ids) === 1 ? $fund_ids[0] : 0;

        $view_normal = '';
        if ($filter_account_id > 0) {
            $vn = $db->prepare('SELECT normal_balance FROM accounts WHERE id = ? LIMIT 1');
            $vn->bind_param('i', $filter_account_id);
            $vn->execute();
            if ($vnr = $vn->get_result()->fetch_assoc()) {
                $view_normal = (string)$vnr['normal_balance'];
            }
            $vn->close();
        }

        return [
            'conditions' => $conditions,
            'bind_params' => $bind_params,
            'bind_types' => $bind_types,
            'filter_account_id' => $filter_account_id,
            'filter_fund_id' => $filter_fund_id,
            'view_normal' => $view_normal,
            'filters' => [
                'dates' => $dates,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'references' => $references,
                'descriptions' => $descriptions,
                'pay_tos' => $pay_tos,
                'statuses' => $statuses,
                'amounts' => $amounts,
                'account_ids' => $account_ids,
                'fund_ids' => $fund_ids,
                'check_number' => $check_number,
                'search' => $search,
                'amount_min' => $amount_min,
                'amount_max' => $amount_max,
                // Back-compat single fields for older UI bits
                'account_id' => $filter_account_id,
                'fund_id' => $filter_fund_id,
            ],
        ];
    };

    /**
     * Fetch a page of ledger transactions with server-side filters + sort.
     *
     * @return array{rows: array, total: int, offset: int, limit: int, has_more: bool, sort: string, sort_dir: string, filter_account_id: int, filters: array}
     */
    $ledgerFetchTransactionPage = static function (mysqli $db, array $src, callable $buildFilters) use ($ledgerBuildListFilters): array {
        $built = $buildFilters($db, $src);
        $conditions = $built['conditions'];
        $bind_params = $built['bind_params'];
        $bind_types = $built['bind_types'];
        $filter_account_id = $built['filter_account_id'];
        $where_clause = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';

        $limit = isset($src['limit']) ? (int)$src['limit'] : 50;
        if ($limit < 1) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }
        $offset = isset($src['offset']) ? max(0, (int)$src['offset']) : 0;
        // Legacy page param support
        if ($offset === 0 && isset($src['page']) && (int)$src['page'] > 1) {
            $offset = ((int)$src['page'] - 1) * $limit;
        }

        $sortKey = strtolower(trim((string)($src['sort'] ?? 'date')));
        $sortDir = strtolower(trim((string)($src['sort_dir'] ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $sortMap = [
            'date' => 'td.transaction_date',
            'reference' => 'td.reference_number',
            'ref' => 'td.reference_number',
            'pay_to' => 'td.pay_to',
            'check' => 'td.check_number',
            'check_number' => 'td.check_number',
            'description' => 'td.description',
            'status' => 'td.status',
            'lines' => 'num_lines',
            'debit' => 'total_debits',
            'credit' => 'total_credits',
            'amount' => 'total_debits',
            'id' => 'td.id',
        ];
        if (!isset($sortMap[$sortKey])) {
            $sortKey = 'date';
        }
        $orderCol = $sortMap[$sortKey];
        // Secondary sort always newest id for stable paging
        $orderSql = $orderCol . ' ' . $sortDir . ', td.id ' . $sortDir;

        $count_stmt = $db->prepare('SELECT COUNT(*) AS total FROM transaction_details td' . $where_clause);
        if ($bind_types !== '') {
            $count_stmt->bind_param($bind_types, ...$bind_params);
        }
        $count_stmt->execute();
        $total = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $count_stmt->close();

        $list_params = $bind_params;
        $list_types = $bind_types;
        $list_params[] = $limit;
        $list_params[] = $offset;
        $list_types .= 'ii';

        $sql = "
            SELECT td.id, td.transaction_date, td.pay_to, td.reference_number, td.check_number, td.description, td.status, td.cleared_date,
                   td.validated_by_user_id, td.validated_at,
                   COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id AND type='debit'), 0) AS total_debits,
                   COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id AND type='credit'), 0) AS total_credits,
                   COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id), 0) AS total_amount,
                   COALESCE((SELECT COUNT(*) FROM transaction_lines WHERE transaction_detail_id=td.id), 0) AS num_lines,
                   COALESCE((SELECT COUNT(*) FROM transaction_documents WHERE transaction_detail_id=td.id), 0) AS doc_count,
                   (SELECT GROUP_CONCAT(DISTINCT a.name ORDER BY a.name SEPARATOR ', ')
                      FROM transaction_lines tl2
                      INNER JOIN accounts a ON a.id = tl2.account_id
                     WHERE tl2.transaction_detail_id = td.id) AS account_names,
                   (SELECT GROUP_CONCAT(DISTINCT f.name ORDER BY f.name SEPARATOR ', ')
                      FROM transaction_lines tl3
                      LEFT JOIN funds f ON f.id = tl3.fund_id
                     WHERE tl3.transaction_detail_id = td.id AND tl3.fund_id IS NOT NULL) AS fund_names
            FROM transaction_details td
            $where_clause
            ORDER BY $orderSql
            LIMIT ? OFFSET ?
        ";
        $tx_stmt = $db->prepare($sql);
        if ($list_types !== '') {
            $tx_stmt->bind_param($list_types, ...$list_params);
        }
        $tx_stmt->execute();
        $tx_result = $tx_stmt->get_result();
        $tx_rows = [];
        if ($tx_result) {
            while ($r = $tx_result->fetch_assoc()) {
                $tx_rows[] = $r;
            }
            $tx_result->close();
        }
        $tx_stmt->close();

        // When an account is selected, show Debit/Credit totals for that account only
        $acct_debits = [];
        $acct_credits = [];
        if ($filter_account_id > 0 && count($tx_rows) > 0) {
            $ids = [];
            foreach ($tx_rows as $r) {
                $ids[] = (int)$r['id'];
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $dq = $db->prepare("
                SELECT transaction_detail_id,
                       COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) AS debits,
                       COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) AS credits
                FROM transaction_lines
                WHERE transaction_detail_id IN ($in) AND account_id = ?
                GROUP BY transaction_detail_id
            ");
            $dtypes = str_repeat('i', count($ids)) . 'i';
            $dparams = array_merge($ids, [$filter_account_id]);
            $dq->bind_param($dtypes, ...$dparams);
            $dq->execute();
            $dres = $dq->get_result();
            while ($dm = $dres->fetch_assoc()) {
                $tid = (int)$dm['transaction_detail_id'];
                $acct_debits[$tid] = (float)$dm['debits'];
                $acct_credits[$tid] = (float)$dm['credits'];
            }
            $dq->close();
        }

        $out_rows = [];
        foreach ($tx_rows as $r) {
            $tid = (int)$r['id'];
            if ($filter_account_id > 0) {
                $debAmt = $acct_debits[$tid] ?? 0.0;
                $credAmt = $acct_credits[$tid] ?? 0.0;
            } else {
                $debAmt = (float)($r['total_debits'] ?? 0);
                $credAmt = (float)($r['total_credits'] ?? 0);
            }
            $isCleared = ($r['status'] === 'cleared' || !empty($r['cleared_date']));
            $out_rows[] = [
                'id' => $tid,
                'transaction_date' => $r['transaction_date'],
                'pay_to' => $r['pay_to'] ?? '',
                'reference_number' => $r['reference_number'] ?? '',
                'check_number' => $r['check_number'] ?? '',
                'description' => $r['description'] ?? '',
                'status' => $r['status'] ?? 'pending',
                'cleared_date' => $r['cleared_date'] ?? null,
                'total_debits' => (float)($r['total_debits'] ?? 0),
                'total_credits' => (float)($r['total_credits'] ?? 0),
                'total_amount' => (float)($r['total_amount'] ?? 0),
                'num_lines' => (int)($r['num_lines'] ?? 0),
                'doc_count' => (int)($r['doc_count'] ?? 0),
                'account_names' => $r['account_names'] ?? '',
                'fund_names' => $r['fund_names'] ?? '',
                'debits' => $debAmt,
                'credits' => $credAmt,
                'is_cleared' => $isCleared ? 1 : 0,
            ];
        }

        return [
            'rows' => $out_rows,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => ($offset + count($out_rows)) < $total,
            'sort' => $sortKey,
            'sort_dir' => strtolower($sortDir) === 'asc' ? 'asc' : 'desc',
            'filter_account_id' => $filter_account_id,
            'filters' => $built['filters'],
        ];
    };

    // JSON list endpoint for infinite scroll / Excel-style server-side filters
    if (isset($_GET['list_transactions'])) {
        header('Content-Type: application/json; charset=utf-8');
        $page = $ledgerFetchTransactionPage($db, $_GET, $ledgerBuildListFilters);
        echo json_encode([
            'success' => true,
            'total' => $page['total'],
            'offset' => $page['offset'],
            'limit' => $page['limit'],
            'has_more' => $page['has_more'],
            'sort' => $page['sort'],
            'sort_dir' => $page['sort_dir'],
            'filters' => $page['filters'],
            'rows' => $page['rows'],
        ]);
        exit;
    }

    // Unique values for Excel-style multi-select auto-filter dropdowns.
    // Other-column filters apply; the opened column's own filter is excluded (Excel behavior).
    if (isset($_GET['filter_values'])) {
        header('Content-Type: application/json; charset=utf-8');
        $column = strtolower(trim((string)($_GET['column'] ?? '')));
        $allowedCols = ['date', 'reference', 'pay_to', 'description', 'account', 'fund', 'amount', 'status'];
        if (!in_array($column, $allowedCols, true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid filter column.']);
            exit;
        }

        $built = $ledgerBuildListFilters($db, $_GET, [$column]);
        $conditions = $built['conditions'];
        $bind_params = $built['bind_params'];
        $bind_types = $built['bind_types'];
        $where_clause = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';

        $values = [];
        $tree = null;

        if ($column === 'date') {
            $sql = "SELECT td.transaction_date AS d, COUNT(*) AS cnt
                    FROM transaction_details td
                    $where_clause
                    GROUP BY td.transaction_date
                    ORDER BY td.transaction_date DESC
                    LIMIT 2000";
            $stmt = $db->prepare($sql);
            if ($bind_types !== '') {
                $stmt->bind_param($bind_types, ...$bind_params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $byYear = [];
            while ($row = $res->fetch_assoc()) {
                $d = (string)$row['d'];
                $cnt = (int)$row['cnt'];
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
                    continue;
                }
                $y = $m[1];
                $mo = $m[2];
                $day = $m[3];
                if (!isset($byYear[$y])) {
                    $byYear[$y] = ['year' => $y, 'count' => 0, 'months' => []];
                }
                if (!isset($byYear[$y]['months'][$mo])) {
                    $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                        7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
                    $byYear[$y]['months'][$mo] = [
                        'month' => $mo,
                        'label' => $monthNames[(int)$mo] ?? $mo,
                        'count' => 0,
                        'days' => [],
                    ];
                }
                $byYear[$y]['count'] += $cnt;
                $byYear[$y]['months'][$mo]['count'] += $cnt;
                $byYear[$y]['months'][$mo]['days'][] = [
                    'value' => $d,
                    'label' => (string)((int)$day),
                    'count' => $cnt,
                ];
                $values[] = ['value' => $d, 'label' => $d, 'count' => $cnt];
            }
            $stmt->close();
            // Normalize months to list sorted desc
            $tree = [];
            foreach ($byYear as $yNode) {
                $months = array_values($yNode['months']);
                usort($months, static fn($a, $b) => strcmp($b['month'], $a['month']));
                $yNode['months'] = $months;
                $tree[] = $yNode;
            }
            usort($tree, static fn($a, $b) => strcmp($b['year'], $a['year']));
        } elseif ($column === 'account') {
            $sql = "SELECT a.id AS value, a.name AS label, a.coa_number, COUNT(DISTINCT td.id) AS cnt
                    FROM transaction_details td
                    INNER JOIN transaction_lines tl ON tl.transaction_detail_id = td.id
                    INNER JOIN accounts a ON a.id = tl.account_id
                    $where_clause
                    GROUP BY a.id, a.name, a.coa_number
                    ORDER BY (a.coa_number IS NULL OR a.coa_number = '') ASC, a.coa_number ASC, a.name ASC
                    LIMIT 2000";
            $stmt = $db->prepare($sql);
            if ($bind_types !== '') {
                $stmt->bind_param($bind_types, ...$bind_params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                // Display account name only (no CoA number) in the filter list.
                $label = trim((string)$row['label']);
                if ($label === '') {
                    $label = '(Unnamed)';
                }
                $values[] = [
                    'value' => (string)(int)$row['value'],
                    'label' => $label,
                    'count' => (int)$row['cnt'],
                ];
            }
            $stmt->close();
        } elseif ($column === 'fund') {
            // Display fund name only (no fund code) in the filter list.
            $sql = "SELECT f.id AS value, f.name AS label, COUNT(DISTINCT td.id) AS cnt
                    FROM transaction_details td
                    INNER JOIN transaction_lines tl ON tl.transaction_detail_id = td.id
                    INNER JOIN funds f ON f.id = tl.fund_id
                    $where_clause
                    GROUP BY f.id, f.name
                    ORDER BY f.name ASC
                    LIMIT 2000";
            $stmt = $db->prepare($sql);
            if ($bind_types !== '') {
                $stmt->bind_param($bind_types, ...$bind_params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $label = trim((string)$row['label']);
                if ($label === '') {
                    $label = '(Unnamed)';
                }
                $values[] = [
                    'value' => (string)(int)$row['value'],
                    'label' => $label,
                    'count' => (int)$row['cnt'],
                ];
            }
            $stmt->close();
        } elseif ($column === 'amount') {
            $debitExpr = "COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id AND type='debit'), 0)";
            $creditExpr = "COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id AND type='credit'), 0)";
            $sql = "SELECT amt, SUM(cnt) AS cnt FROM (
                        SELECT ROUND($debitExpr, 2) AS amt, COUNT(*) AS cnt
                        FROM transaction_details td
                        $where_clause
                        GROUP BY ROUND($debitExpr, 2)
                        UNION ALL
                        SELECT ROUND($creditExpr, 2) AS amt, COUNT(*) AS cnt
                        FROM transaction_details td
                        $where_clause
                        GROUP BY ROUND($creditExpr, 2)
                    ) u
                    WHERE amt IS NOT NULL AND amt > 0
                    GROUP BY amt
                    ORDER BY amt DESC
                    LIMIT 2000";
            // Bind params twice (two subqueries)
            $stmt = $db->prepare($sql);
            if ($bind_types !== '') {
                $allTypes = $bind_types . $bind_types;
                $allParams = array_merge($bind_params, $bind_params);
                $stmt->bind_param($allTypes, ...$allParams);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $amt = round((float)$row['amt'], 2);
                $label = '$' . number_format($amt, 2);
                $values[] = [
                    'value' => number_format($amt, 2, '.', ''),
                    'label' => $label,
                    'count' => (int)$row['cnt'],
                ];
            }
            $stmt->close();
        } else {
            // Text categorical: pay_to, description, reference, status
            $colMap = [
                'pay_to' => 'td.pay_to',
                'description' => 'td.description',
                'reference' => 'td.reference_number',
                'status' => 'td.status',
            ];
            $expr = $colMap[$column];
            $sql = "SELECT COALESCE($expr, '') AS value, COUNT(*) AS cnt
                    FROM transaction_details td
                    $where_clause
                    GROUP BY COALESCE($expr, '')
                    ORDER BY (COALESCE($expr, '') = '') ASC, value ASC
                    LIMIT 2000";
            $stmt = $db->prepare($sql);
            if ($bind_types !== '') {
                $stmt->bind_param($bind_types, ...$bind_params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $statusLabels = ['pending' => 'Pending', 'cleared' => 'Cleared', 'reconciled' => 'Reconciled'];
            while ($row = $res->fetch_assoc()) {
                $val = (string)$row['value'];
                if ($val === '') {
                    $label = '(Blanks)';
                } elseif ($column === 'status') {
                    $label = $statusLabels[strtolower($val)] ?? $val;
                } else {
                    $label = $val;
                }
                $values[] = [
                    'value' => $val,
                    'label' => $label,
                    'count' => (int)$row['cnt'],
                ];
            }
            $stmt->close();
        }

        echo json_encode([
            'success' => true,
            'column' => $column,
            'values' => $values,
            'tree' => $tree,
            'filters' => $built['filters'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        require_once __DIR__ . '/../auth.php';
        $actor = getCurrentUserWithRole($db);

        if (!$canWriteLedger) {
            denyPermission('You do not have permission to modify ledger transactions.');
        }

        if ($action === 'upload_document') {
            header('Content-Type: application/json; charset=utf-8');
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $txId = (int)($_POST['tx_id'] ?? 0);
            if ($txId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Transaction ID is required.']);
                exit;
            }
            // Prefer tx_document (current field name); accept legacy "document" for older clients.
            $uploadFile = null;
            foreach (['tx_document', 'document'] as $fileKey) {
                if (!isset($_FILES[$fileKey]) || !is_array($_FILES[$fileKey])) {
                    continue;
                }
                $candidate = $_FILES[$fileKey];
                // Multi-file shape is not used; skip if nested arrays.
                if (is_array($candidate['error'] ?? null)) {
                    continue;
                }
                $err = (int)($candidate['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $uploadFile = $candidate;
                break;
            }
            if ($uploadFile === null) {
                echo json_encode(['success' => false, 'error' => 'Please select a file to upload.']);
                exit;
            }
            $tx = ledgerFetchTransaction($db, $txId);
            if (!$tx) {
                echo json_encode(['success' => false, 'error' => 'Transaction not found.']);
                exit;
            }
            if (empty($tx['is_editable'])) {
                echo json_encode(['success' => false, 'error' => 'This transaction is read-only; documents cannot be uploaded.']);
                exit;
            }
            if (!$actor) {
                echo json_encode(['success' => false, 'error' => 'You must be signed in to upload documents.']);
                exit;
            }
            $userId = (int)$actor['id'];
            $result = ledgerStoreDocumentFromUpload(
                $db,
                $txId,
                $userId,
                $uploadFile
            );
            if (!empty($result['success'])) {
                $origName = basename((string)($uploadFile['name'] ?? 'file'));
                try {
                    ledgerLogEvent(
                        $db,
                        $txId,
                        'document_uploaded',
                        $userId,
                        $actor['username'] ?? 'system',
                        'Attachment "' . $origName . '" added.',
                        ['doc_id' => $result['id'], 'original_filename' => $origName]
                    );
                } catch (Throwable $e) {
                    // File is stored; do not fail the client response for audit logging issues.
                    error_log('ledger upload audit failed: ' . $e->getMessage());
                }
                $result['success'] = true;
                $result['message'] = 'Upload Successful';
                $docs = ledgerFetchDocuments($db, $txId);
                $result['documents'] = $docs;
            } else {
                $result['success'] = false;
                if (empty($result['error'])) {
                    $result['error'] = 'Upload failed.';
                }
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'delete_document' || $action === 'delete_documents') {
            header('Content-Type: application/json; charset=utf-8');
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!$actor) {
                echo json_encode(['success' => false, 'error' => 'You must be signed in to delete documents.']);
                exit;
            }

            $docIds = [];
            if ($action === 'delete_documents') {
                $raw = json_decode($_POST['doc_ids'] ?? '[]', true);
                if (is_array($raw)) {
                    foreach ($raw as $v) {
                        $id = (int)$v;
                        if ($id > 0) {
                            $docIds[] = $id;
                        }
                    }
                }
            } else {
                $one = (int)($_POST['doc_id'] ?? 0);
                if ($one > 0) {
                    $docIds[] = $one;
                }
            }
            $docIds = array_values(array_unique($docIds));
            if ($docIds === []) {
                echo json_encode(['success' => false, 'error' => 'Document ID is required.']);
                exit;
            }

            $txId = 0;
            $deleted = [];
            $errors = [];
            foreach ($docIds as $docId) {
                $doc = ledgerFetchDocument($db, $docId);
                if (!$doc) {
                    $errors[] = "Document #$docId not found.";
                    continue;
                }
                $docTxId = (int)$doc['transaction_detail_id'];
                if ($txId === 0) {
                    $txId = $docTxId;
                    $tx = ledgerFetchTransaction($db, $txId);
                    if (!$tx) {
                        echo json_encode(['success' => false, 'error' => 'Transaction not found.']);
                        exit;
                    }
                    if (empty($tx['is_editable'])) {
                        echo json_encode(['success' => false, 'error' => 'This transaction is read-only; documents cannot be deleted.']);
                        exit;
                    }
                } elseif ($docTxId !== $txId) {
                    $errors[] = "Document #$docId belongs to a different transaction.";
                    continue;
                }

                $del = ledgerDeleteDocument($db, $docId);
                if (empty($del['success'])) {
                    $errors[] = $del['error'] ?? ("Failed to delete document #$docId.");
                    continue;
                }
                $deleted[] = [
                    'id' => $docId,
                    'original_filename' => $doc['original_filename'] ?? '',
                ];
                $delName = basename((string)($doc['original_filename'] ?? 'file'));
                try {
                    ledgerLogEvent(
                        $db,
                        $txId,
                        'document_deleted',
                        (int)$actor['id'],
                        $actor['username'] ?? 'system',
                        'Attachment "' . $delName . '" removed.',
                        ['doc_id' => $docId, 'original_filename' => $delName]
                    );
                } catch (Throwable $e) {
                    error_log('ledger delete audit failed: ' . $e->getMessage());
                }
            }

            $count = count($deleted);
            if ($count === 0) {
                echo json_encode([
                    'success' => false,
                    'error' => $errors[0] ?? 'Delete failed.',
                    'errors' => $errors,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $names = array_map(static fn($d) => $d['original_filename'] ?: ('#' . $d['id']), $deleted);
            $msg = $count === 1
                ? ('Deleted attachment: ' . $names[0])
                : ('Deleted ' . $count . ' attachments: ' . implode(', ', $names));

            echo json_encode([
                'success' => true,
                'message' => $msg,
                'deleted' => $deleted,
                'deleted_count' => $count,
                'errors' => $errors,
                'documents' => $txId > 0 ? ledgerFetchDocuments($db, $txId) : [],
                'transaction_detail_id' => $txId,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'clear') {
            $ids = json_decode($_POST['selected_ids'] ?? '[]', true) ?: [];
            if (count($ids) > 0) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $types = str_repeat('i', count($ids));
                $chk = $db->prepare("SELECT COUNT(*) FROM transaction_details WHERE id IN ($in) AND status <> 'pending'");
                $chk->bind_param($types, ...$ids);
                $chk->execute();
                $bad = (int)($chk->get_result()->fetch_row()[0] ?? 0);
                $chk->close();
                if ($bad > 0) {
                    $error = 'Only pending transactions can be cleared.';
                } else {
                    $stmt = $db->prepare("UPDATE transaction_details SET status='cleared', cleared_date=CURDATE() WHERE id IN ($in)");
                    $stmt->bind_param($types, ...$ids);
                    if ($stmt->execute()) {
                        $success = count($ids) . ' transaction(s) marked as cleared.';
                    } else {
                        $error = 'Clear failed: ' . $db->error;
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'reconcile') {
            $ids = json_decode($_POST['selected_ids'] ?? '[]', true) ?: [];
            if (count($ids) > 0) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $types = str_repeat('i', count($ids));
                $chk = $db->prepare("SELECT COUNT(*) FROM transaction_details WHERE id IN ($in) AND status <> 'pending'");
                $chk->bind_param($types, ...$ids);
                $chk->execute();
                $bad = (int)($chk->get_result()->fetch_row()[0] ?? 0);
                $chk->close();
                if ($bad > 0) {
                    $error = 'Only pending transactions can be reconciled.';
                } else {
                    $stmt = $db->prepare("UPDATE transaction_details SET status='reconciled', date_reconciled=CURDATE() WHERE id IN ($in)");
                    $stmt->bind_param($types, ...$ids);
                    if ($stmt->execute()) {
                        $success = count($ids) . ' transaction(s) marked as reconciled.';
                    } else {
                        $error = 'Reconcile failed: ' . $db->error;
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'save' || $action === '') {
            // Shared add / edit save handler
            $d = $_POST['transaction_date'] ?? '';
            $p = trim($_POST['pay_to'] ?? '');
            // Reference # is the manual YY#### field (replaces free-text ref + sequence)
            $refRaw = $_POST['reference_number'] ?? '';
            $c = trim($_POST['check_number'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $budgetIdRaw = (int)($_POST['budget_id'] ?? 0);
            $budgetId = $budgetIdRaw > 0 ? $budgetIdRaw : null;
            $allowBudgetOutOfPeriod = in_array(
                (string)($_POST['allow_budget_out_of_period'] ?? '0'),
                ['1', 'true'],
                true
            );
            $tx_id = (int)($_POST['tx_id'] ?? 0);
            $lines = json_decode($_POST['lines_json'] ?? '[]', true) ?: [];

            $refCheck = ledgerValidateReferenceNumber($refRaw, true);
            $ref = $refCheck['value'] ?? null;
            $allowRefReuse = in_array(
                (string)($_POST['allow_reference_reuse'] ?? '0'),
                ['1', 'true'],
                true
            );

            $budgetRow = null;
            $budgetPeriodError = null;
            if ($budgetId !== null) {
                if (!budgetIsAssignableId($db, $budgetId)) {
                    $budgetPeriodError = 'Selected budget is not available for transactions.';
                } else {
                    $budgetRow = budgetFetchById($db, $budgetId);
                    if ($budgetRow && $d && !budgetDateInPeriod($d, $budgetRow['start_date'], $budgetRow['end_date'])) {
                        if (!$allowBudgetOutOfPeriod) {
                            $budgetPeriodError = 'Transaction date is outside the selected budget period ('
                                . budgetFormatPeriodLabel($budgetRow['start_date'], $budgetRow['end_date'])
                                . '). Confirm in the form if this is intentional.';
                        }
                    }
                }
            }

            // Cleared/reconciled: allow budget-only update without re-validating locked header/lines
            $handledBudgetOnly = false;
            if ($tx_id > 0 && empty($error)) {
                $lockedTx = ledgerFetchTransaction($db, $tx_id);
                if ($lockedTx && !ledgerIsEditable($lockedTx)) {
                    $handledBudgetOnly = true;
                    $oldBudgetId = !empty($lockedTx['budget_id']) ? (int)$lockedTx['budget_id'] : 0;
                    $newBudgetId = $budgetId !== null ? (int)$budgetId : 0;
                    if ($oldBudgetId === $newBudgetId) {
                        $error = 'This transaction is read-only (cleared or reconciled). Only the budget may be changed.';
                    } else {
                        // Period check uses the locked transaction date, not a tampered POST date
                        $lockedDate = (string)($lockedTx['transaction_date'] ?? '');
                        if ($budgetId !== null && $lockedDate !== '') {
                            if (!budgetIsAssignableId($db, $budgetId)) {
                                $error = 'Selected budget is not available for transactions.';
                            } else {
                                $bRow = budgetFetchById($db, $budgetId);
                                if (
                                    $bRow
                                    && !budgetDateInPeriod($lockedDate, $bRow['start_date'], $bRow['end_date'])
                                    && !$allowBudgetOutOfPeriod
                                ) {
                                    $error = 'Transaction date is outside the selected budget period ('
                                        . budgetFormatPeriodLabel($bRow['start_date'], $bRow['end_date'])
                                        . '). Confirm in the form if this is intentional.';
                                }
                            }
                        }
                        if (empty($error)) {
                            try {
                                ledgerUpdateHeader(
                                    $db,
                                    $tx_id,
                                    null,
                                    null,
                                    null,
                                    null,
                                    null,
                                    $budgetId,
                                    true
                                );
                                $describe = ledgerDescribeTransactionUpdate(
                                    $db,
                                    $lockedTx,
                                    [
                                        'transaction_date' => (string)($lockedTx['transaction_date'] ?? ''),
                                        'pay_to' => (string)($lockedTx['pay_to'] ?? ''),
                                        'reference_number' => (string)($lockedTx['reference_number'] ?? ''),
                                        'check_number' => (string)($lockedTx['check_number'] ?? ''),
                                        'description' => (string)($lockedTx['description'] ?? ''),
                                        'budget_id' => $newBudgetId,
                                    ],
                                    array_map(static function ($ol) {
                                        return [
                                            'aid' => (int)($ol['account_id'] ?? 0),
                                            'fid' => $ol['fund_id'] ?? null,
                                            'am' => (float)($ol['amount'] ?? 0),
                                            't' => (string)($ol['type'] ?? 'debit'),
                                            'nid' => $ol['natural_category_id'] ?? null,
                                            'fid2' => $ol['functional_category_id'] ?? null,
                                        ];
                                    }, $lockedTx['lines'] ?? [])
                                );
                                ledgerLogEvent(
                                    $db,
                                    $tx_id,
                                    'updated',
                                    $actor ? (int)$actor['id'] : null,
                                    $actor['username'] ?? 'system',
                                    $describe['summary'] !== ''
                                        ? $describe['summary']
                                        : 'Budget updated on cleared/reconciled transaction.',
                                    [
                                        'budget_only' => true,
                                        'status' => (string)($lockedTx['status'] ?? ''),
                                        'changes' => $describe['changes'],
                                    ]
                                );
                                $success = "Transaction #$tx_id budget updated.";
                            } catch (Throwable $e) {
                                $error = 'Budget update failed: ' . $e->getMessage();
                            }
                        }
                    }
                }
            }

            if ($handledBudgetOnly) {
                // Budget-only save finished (success or error already set)
            } elseif (!$d) {
                $error = "Date is required.";
            } elseif (empty($refCheck['ok'])) {
                $error = $refCheck['error'] ?? 'Invalid Reference #.';
            } elseif (
                $ref !== null
                && !$allowRefReuse
                && ledgerReferenceNumberTaken($db, $ref, $tx_id > 0 ? $tx_id : null)
            ) {
                $usage = ledgerReferenceUsage($db, $ref, $tx_id > 0 ? $tx_id : null);
                $hint = $usage
                    ? (' (used by #' . (int)$usage['id']
                        . (!empty($usage['transaction_date']) ? ' on ' . $usage['transaction_date'] : '')
                        . (!empty($usage['pay_to']) ? ' — ' . $usage['pay_to'] : '')
                        . ')')
                    : '';
                $error = 'Reference # ' . $ref . ' is already used' . $hint
                    . '. Confirm reuse in the form if this is intentional.';
            } elseif ($budgetPeriodError) {
                $error = $budgetPeriodError;
            } elseif (count($lines) < 2) {
                $error = "Every transaction must have at least 2 lines.";
            } else {
                $dt = $ct = 0.0;
                $vlines = [];
                $typeError = false;
                // Natural/Functional always come from the selected account (not client overrides).
                $accountCatCache = [];
                $resolveAccountCategories = static function (mysqli $db, int $aid, array &$cache): array {
                    if (isset($cache[$aid])) {
                        return $cache[$aid];
                    }
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
                        if ($row) {
                            $nid = $row['natural_category_id'] !== null ? (int)$row['natural_category_id'] : null;
                            $fid2 = $row['functional_category_id'] !== null ? (int)$row['functional_category_id'] : null;
                            if ($nid !== null && $nid <= 0) {
                                $nid = null;
                            }
                            if ($fid2 !== null && $fid2 <= 0) {
                                $fid2 = null;
                            }
                        }
                    }
                    $cache[$aid] = ['nid' => $nid, 'fid2' => $fid2];
                    return $cache[$aid];
                };
                foreach ($lines as $l) {
                    $aid = (int)($l['account_id'] ?? 0);
                    $am = (float)($l['amount'] ?? 0);
                    if ($aid <= 0 || $am <= 0) continue;
                    // Line type is chosen by the user (debit or credit column), not locked to account normal_balance
                    $t = strtolower(trim((string)($l['type'] ?? '')));
                    if ($t !== 'debit' && $t !== 'credit') {
                        $typeError = true;
                        break;
                    }
                    if ($t === 'debit') $dt += $am; else $ct += $am;
                    $cats = $resolveAccountCategories($db, $aid, $accountCatCache);
                    $vlines[] = [
                        'aid' => $aid,
                        'fid' => !empty($l['fund_id']) ? (int)$l['fund_id'] : null,
                        'nid' => $cats['nid'],
                        'fid2' => $cats['fid2'],
                        'am' => $am,
                        't' => $t
                    ];
                }
                if ($typeError) {
                    $error = "Each line must be either Debit or Credit.";
                } elseif (count($vlines) < 2) {
                    $error = "At least two valid lines are required.";
                } elseif (abs($dt - $ct) > 0.005) {
                    $error = "Debits do not equal Credits.";
                } else {
                    // Single Description field → transaction_details.description (no memo / no " | " join)
                    $description = $desc;

                    if ($tx_id > 0) {
                        $existing = ledgerFetchTransaction($db, $tx_id);
                        if (!$existing) {
                            $error = "Transaction not found.";
                        } elseif (!ledgerIsEditable($existing)) {
                            $error = "This transaction is read-only (cleared or reconciled).";
                        } else {
                            $oldRef = ledgerNormalizeReferenceNumber($existing['reference_number'] ?? null);
                            $budgetBind = $budgetId !== null ? (string)$budgetId : null;
                            $upd = $db->prepare("UPDATE transaction_details SET transaction_date=?, check_number=?, pay_to=?, reference_number=?, description=?, budget_id=? WHERE id=?");
                            $upd->bind_param("ssssssi", $d, $c, $p, $ref, $description, $budgetBind, $tx_id);
                            if ($upd->execute()) {
                                $upd->close();

                                // Keep attachment files with the Reference # folder when it changes
                                if ($oldRef !== '' && preg_match('/^\d{6}$/', $oldRef) && $ref !== null && $oldRef !== $ref) {
                                    ledgerRelocateAttachmentFolder($oldRef, $ref);
                                } elseif (($oldRef === '' || !preg_match('/^\d{6}$/', $oldRef)) && $ref !== null) {
                                    ledgerRelocateAttachmentFolder((string)$tx_id, $ref);
                                }

                                $describe = ledgerDescribeTransactionUpdate(
                                    $db,
                                    $existing,
                                    [
                                        'transaction_date' => $d,
                                        'pay_to' => $p,
                                        'reference_number' => $ref,
                                        'check_number' => $c,
                                        'description' => $description,
                                        'budget_id' => $budgetId ?? 0,
                                    ],
                                    $vlines
                                );

                                // Replace lines
                                $del = $db->prepare("DELETE FROM transaction_lines WHERE transaction_detail_id=?");
                                $del->bind_param('i', $tx_id);
                                $del->execute();
                                $del->close();

                                $ed = '';
                                $lins = $db->prepare("INSERT INTO transaction_lines(transaction_detail_id,account_id,fund_id,amount,type,natural_category_id,functional_category_id,description) VALUES(?,?,?,?,?,?,?,?)");
                                foreach ($vlines as $v) {
                                    $lins->bind_param("iiidsiis", $tx_id, $v['aid'], $v['fid'], $v['am'], $v['t'], $v['nid'], $v['fid2'], $ed);
                                    $lins->execute();
                                }
                                $lins->close();
                                ledgerLogEvent(
                                    $db,
                                    $tx_id,
                                    'updated',
                                    $actor ? (int)$actor['id'] : null,
                                    $actor['username'] ?? 'system',
                                    $describe['summary'],
                                    [
                                        'debits' => $dt,
                                        'credits' => $ct,
                                        'changes' => $describe['changes'],
                                    ]
                                );
                                $success = "Transaction #$tx_id updated. Debits $" . number_format($dt, 2) . " = Credits $" . number_format($ct, 2);
                            } else {
                                $error = "Update failed: " . $db->error;
                                $upd->close();
                            }
                        }
                    } else {
                        $createdBy = $actor ? (int)$actor['id'] : null;
                        $tid = ledgerCreateHeader(
                            $db,
                            $d,
                            $p,
                            (string)$ref,
                            $description,
                            $createdBy,
                            null,
                            $budgetId
                        );
                        $chk = $db->prepare('UPDATE transaction_details SET check_number = ? WHERE id = ?');
                        $chk->bind_param('si', $c, $tid);
                        $chk->execute();
                        $chk->close();

                        $lineRows = [];
                        foreach ($vlines as $v) {
                            $lineRows[] = [
                                'account_id' => $v['aid'],
                                'fund_id' => $v['fid'],
                                'amount' => $v['am'],
                                'type' => $v['t'],
                                'natural_category_id' => $v['nid'],
                                'functional_category_id' => $v['fid2'],
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
                            $actor['username'] ?? 'system',
                            $describe['summary'],
                            [
                                'debits' => $dt,
                                'credits' => $ct,
                                'changes' => $describe['changes'],
                            ]
                        );
                        $success = "Transaction #$tid saved. Debits $" . number_format($dt, 2) . " = Credits $" . number_format($ct, 2);
                    }
                }
            }
        }
    }

    // Dropdown options (needed for Add/Edit form). Accounts ordered by CoA number (null/empty last).
    // Natural/Functional classes come from the account (same pattern as budget lines).
    $ar = $db->query(
        "SELECT a.id, a.name, a.normal_balance, a.coa_number,
                a.natural_category_id, a.functional_category_id,
                COALESCE(nc.name, '') AS natural_name,
                COALESCE(fc.name, '') AS functional_name
         FROM accounts a
         LEFT JOIN natural_categories nc ON nc.id = a.natural_category_id
         LEFT JOIN functional_categories fc ON fc.id = a.functional_category_id
         WHERE a.archived = FALSE
         ORDER BY (a.coa_number IS NULL OR a.coa_number = '') ASC, a.coa_number ASC, a.name ASC, a.id ASC"
    );
    $fr = $db->query("SELECT id,name,code FROM funds WHERE is_active=TRUE AND archived=FALSE ORDER BY name");
    $nr = $db->query("SELECT id,name FROM natural_categories WHERE archived=FALSE ORDER BY name");
    $fur = $db->query("SELECT id,name FROM functional_categories WHERE archived=FALSE ORDER BY name");
    $budgetOptions = budgetFetchTransactionOptions($db);
    $defaultBudgetIdToday = budgetDefaultIdForDate($db, date('Y-m-d'));

    // Lookups for text-paste import (client-side account/fund name resolution)
    $accountsLookup = [];
    $fundsLookup = [];
    $naturalLookup = [];
    $functionalLookup = [];

    $aopt = '';
    if ($ar) {
        while ($a = $ar->fetch_assoc()) {
            $coa = trim((string)($a['coa_number'] ?? ''));
            $natName = ($a['natural_name'] ?? '') !== '' ? $a['natural_name'] : '—';
            $funName = ($a['functional_name'] ?? '') !== '' ? $a['functional_name'] : '—';
            $natId = $a['natural_category_id'] !== null && $a['natural_category_id'] !== ''
                ? (int)$a['natural_category_id'] : 0;
            $funId = $a['functional_category_id'] !== null && $a['functional_category_id'] !== ''
                ? (int)$a['functional_category_id'] : 0;
            $accountsLookup[] = [
                'id' => (int)$a['id'],
                'name' => $a['name'],
                'normal_balance' => $a['normal_balance'],
                'coa_number' => $coa,
                'natural_category_id' => $natId > 0 ? $natId : '',
                'functional_category_id' => $funId > 0 ? $funId : '',
                'natural_name' => $natName,
                'functional_name' => $funName,
            ];
            $nb = htmlspecialchars($a['normal_balance']);
            $aopt .= '<option value="' . (int)$a['id'] . '"'
                . ' data-normal-balance="' . $nb . '"'
                . ' data-coa-number="' . htmlspecialchars($coa) . '"'
                . ' data-natural-id="' . ($natId > 0 ? $natId : '') . '"'
                . ' data-functional-id="' . ($funId > 0 ? $funId : '') . '"'
                . ' data-natural-name="' . htmlspecialchars($natName) . '"'
                . ' data-functional-name="' . htmlspecialchars($funName) . '"'
                . '>' . htmlspecialchars($a['name']) . ' (' . $nb . ')</option>';
        }
    }
    $fopt = '<option value="">—</option>';
    if ($fr) {
        while ($f = $fr->fetch_assoc()) {
            $fundsLookup[] = [
                'id' => (int)$f['id'],
                'name' => $f['name'],
                'code' => $f['code'] ?? '',
            ];
            $fopt .= '<option value="' . (int)$f['id'] . '">' . htmlspecialchars($f['name'] . ($f['code'] ? ' (' . $f['code'] . ')' : '')) . '</option>';
        }
    }
    if ($nr) {
        while ($n = $nr->fetch_assoc()) {
            $naturalLookup[] = [
                'id' => (int)$n['id'],
                'name' => $n['name'],
            ];
        }
    }
    if ($fur) {
        while ($f = $fur->fetch_assoc()) {
            $functionalLookup[] = [
                'id' => (int)$f['id'],
                'name' => $f['name'],
            ];
        }
    }

    // Filters + initial page for continuous list (infinite scroll loads more via JSON)
    $list_page = $ledgerFetchTransactionPage($db, $_GET, $ledgerBuildListFilters);
    $tx_list_rows = $list_page['rows'];
    $total = $list_page['total'];
    $list_offset = $list_page['offset'];
    $list_limit = $list_page['limit'];
    $list_has_more = $list_page['has_more'];
    $list_sort = $list_page['sort'];
    $list_sort_dir = $list_page['sort_dir'];
    $active_filters = $list_page['filters'];
    /**
     * Format a ledger money cell: blank when zero, otherwise positive $x.xx (right-aligned by CSS).
     */
    $fmtLedgerAmt = static function ($amount): string {
        $a = (float)$amount;
        if (abs($a) < 0.005) {
            return '';
        }
        return '$' . number_format(abs($a), 2);
    };
    $fmtAttachCell = static function (int $txId, int $docCount): string {
        if ($docCount < 1) {
            return '<td class="text-center ledger-attach-cell"></td>';
        }
        $label = $docCount === 1 ? 'View 1 attachment' : ('View ' . $docCount . ' attachments');
        $badge = $docCount > 1
            ? '<span class="badge rounded-pill text-bg-secondary ledger-attach-count">' . $docCount . '</span>'
            : '';
        return '<td class="text-center ledger-attach-cell">'
            . '<button type="button" class="btn btn-link btn-sm p-0 ledger-attach-btn"'
            . ' data-tx-id="' . $txId . '"'
            . ' title="' . htmlspecialchars($label) . '"'
            . ' aria-label="' . htmlspecialchars($label) . '">'
            . '<i class="bi bi-paperclip" aria-hidden="true"></i>'
            . $badge
            . '</button></td>';
    };

    $hasActiveFilters = false;
    foreach ($active_filters as $fk => $fv) {
        if (in_array($fk, ['dates', 'references', 'descriptions', 'pay_tos', 'statuses', 'amounts', 'account_ids', 'fund_ids'], true)) {
            if (is_array($fv) && count($fv) > 0) {
                $hasActiveFilters = true;
                break;
            }
        } elseif ($fk === 'account_id' || $fk === 'fund_id') {
            if ((int)$fv > 0) {
                $hasActiveFilters = true;
                break;
            }
        } elseif ($fv !== '' && $fv !== null && !(is_array($fv) && $fv === [])) {
            $hasActiveFilters = true;
            break;
        }
    }

?>
<div class="container-fluid ledger-page">
<?php if ($success || $error): ?>
<script type="application/json" id="ledger-flash"><?= json_encode(['message' => $success ?: $error, 'type' => $success ? 'success' : 'danger']) ?></script>
<?php endif; ?>
<script type="application/json" id="ledger-list-state"><?= json_encode([
    'total' => (int)$total,
    'offset' => (int)$list_offset,
    'limit' => (int)$list_limit,
    'has_more' => (bool)$list_has_more,
    'sort' => $list_sort,
    'sort_dir' => $list_sort_dir,
    'filters' => $active_filters,
    'loaded' => count($tx_list_rows),
], JSON_UNESCAPED_UNICODE) ?></script>

    <!-- Top Action Buttons -->
    <div class="d-flex flex-wrap gap-2 mb-2 ledger-action-bar align-items-center">
        <?php if ($canWriteLedger): ?>
        <button type="button" id="addTxBtn" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> <span class="d-none d-sm-inline">Add Transaction</span><span class="d-sm-none">Add</span>
        </button>
        <?php endif; ?>
        <button type="button" id="viewTxBtn" class="btn btn-outline-secondary" disabled>
            <i class="bi bi-eye"></i> View
        </button>
        <?php if ($canWriteLedger): ?>
        <button type="button" id="editTxBtn" class="btn btn-outline-secondary" disabled>
            <i class="bi bi-pencil"></i> Edit
        </button>
        <button type="button" id="clearTxBtn" class="btn btn-outline-warning" disabled>
            <i class="bi bi-check2-circle"></i> Clear
        </button>
        <button type="button" id="reconcileTxBtn" class="btn btn-outline-info" disabled>
            <i class="bi bi-journal-check"></i> <span class="d-none d-sm-inline">Reconcile</span><span class="d-sm-none">Rec.</span>
        </button>
        <?php else: ?>
        <span class="text-muted small align-self-center"><i class="bi bi-eye"></i> Read-only access</span>
        <?php endif; ?>
        <button type="button" id="clearAllFiltersBtn" class="btn btn-outline-secondary btn-sm ms-md-2" title="Clear all column filters"<?= $hasActiveFilters ? '' : ' disabled' ?>>
            <i class="bi bi-funnel"></i> Clear all filters
        </button>
        <span class="text-muted small ms-auto align-self-center" id="ledgerTotalLabel"><?= (int)$total ?> total</span>
    </div>

    <div class="d-flex flex-column ledger-workspace">
        <div class="card flex-grow-1 d-flex flex-column ledger-tx-list mb-0" style="min-height:0;">
            <div class="card-header py-2 d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
                <strong>Transactions</strong>
                <small class="text-muted d-none d-md-inline">(double-click or View to open; checkbox / Ctrl / Shift for multi-select)</small>
                <small class="text-muted d-md-none">(double-tap to view)</small>
            </div>
            <div class="card-body p-0 d-flex flex-column" style="flex:1 1 auto; min-height:0;">
                <div class="table-responsive ledger-table-scroll" id="ledgerTableScroll" style="flex:1 1 auto; overflow:auto; min-height:0;">
                    <table class="table table-sm table-hover mb-0 align-middle ledger-tx-table" id="ledgerTxTable" style="min-width: 1020px;">
                        <thead class="table-dark ledger-sticky-head">
                            <tr class="ledger-col-titles">
                                <th style="width:28px" class="ledger-th-check">
                                    <input type="checkbox" id="selectAll" class="form-check-input" title="Select all loaded">
                                </th>
<?php
$colDefs = [
    ['key' => 'date', 'label' => 'Date', 'filter' => 'multi', 'class' => 'text-nowrap'],
    ['key' => 'reference', 'label' => 'Ref #', 'filter' => 'multi', 'class' => 'text-nowrap'],
    ['key' => 'pay_to', 'label' => 'Pay To', 'filter' => 'multi', 'class' => ''],
    ['key' => 'description', 'label' => 'Description', 'filter' => 'multi', 'class' => ''],
    ['key' => 'account', 'label' => 'Account', 'filter' => 'multi', 'class' => ''],
    ['key' => 'fund', 'label' => 'Fund', 'filter' => 'multi', 'class' => ''],
    ['key' => 'amount', 'label' => 'Amount', 'filter' => 'multi', 'class' => 'text-end text-nowrap', 'title' => 'Debit / Credit amounts'],
    ['key' => 'status', 'label' => 'Status', 'filter' => 'multi', 'class' => ''],
];
$ledgerFilterKeyMap = [
    'date' => 'dates',
    'reference' => 'references',
    'pay_to' => 'pay_tos',
    'description' => 'descriptions',
    'account' => 'account_ids',
    'fund' => 'fund_ids',
    'amount' => 'amounts',
    'status' => 'statuses',
];
foreach ($colDefs as $col):
    $ck = $col['key'];
    $isSorted = ($list_sort === $ck || ($ck === 'amount' && in_array($list_sort, ['amount', 'debit', 'credit'], true)) || ($ck === 'reference' && in_array($list_sort, ['reference', 'ref'], true)));
    $sortIcon = '';
    if ($isSorted) {
        $sortIcon = $list_sort_dir === 'asc' ? ' ↑' : ' ↓';
    }
    $fk = $ledgerFilterKeyMap[$ck] ?? $ck;
    $sel = $active_filters[$fk] ?? [];
    $filterActive = is_array($sel) && count($sel) > 0;
    if (!$filterActive && $ck === 'date') {
        $filterActive = ($active_filters['date_from'] ?? '') !== '' || ($active_filters['date_to'] ?? '') !== '';
    }
    if (!$filterActive && $ck === 'amount') {
        $filterActive = ($active_filters['amount_min'] ?? '') !== '' || ($active_filters['amount_max'] ?? '') !== '';
    }
?>
                                <th class="ledger-th-filter <?= htmlspecialchars($col['class']) ?><?= $filterActive ? ' ledger-filter-active' : '' ?>"
                                    data-col="<?= htmlspecialchars($ck) ?>"
                                    data-filter-type="multi"
                                    data-filter-key="<?= htmlspecialchars($fk) ?>"
                                    <?= !empty($col['title']) ? ' title="' . htmlspecialchars($col['title']) . '"' : '' ?>>
                                    <div class="d-flex align-items-center gap-1 <?= str_contains($col['class'], 'text-end') ? 'justify-content-end' : '' ?>">
                                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none ledger-sort-btn <?= str_contains($col['class'], 'text-end') ? '' : 'text-start' ?> text-white"
                                                data-sort="<?= htmlspecialchars($ck) ?>" title="Sort by <?= htmlspecialchars($col['label']) ?>">
                                            <span class="ledger-col-label"><?= htmlspecialchars($col['label']) ?></span><span class="ledger-sort-indicator"><?= $sortIcon ?></span>
                                        </button>
                                        <div class="dropdown">
                                            <button type="button"
                                                    class="btn btn-sm p-0 border-0 ledger-filter-toggle <?= $filterActive ? 'text-warning' : 'text-white-50' ?>"
                                                    data-bs-toggle="dropdown" data-bs-auto-close="outside"
                                                    aria-expanded="false" title="Filter <?= htmlspecialchars($col['label']) ?>">
                                                <i class="bi <?= $filterActive ? 'bi-funnel-fill' : 'bi-funnel' ?>"></i>
                                            </button>
                                            <div class="dropdown-menu dropdown-menu-end p-2 shadow ledger-filter-menu">
                                                <div class="small text-muted mb-1 fw-semibold flex-shrink-0"><?= htmlspecialchars($col['label']) ?> filter</div>
                                                <input type="search" class="form-control form-control-sm mb-2 ledger-f-search flex-shrink-0" placeholder="Search…" data-dirty-ignore autocomplete="off">
                                                <div class="ledger-f-select-all-wrap flex-shrink-0">
                                                    <input class="form-check-input ledger-f-select-all" type="checkbox" id="ledgerFAll_<?= htmlspecialchars($ck) ?>" data-dirty-ignore checked>
                                                    <label class="form-check-label small" for="ledgerFAll_<?= htmlspecialchars($ck) ?>">(Select All)</label>
                                                </div>
                                                <div class="ledger-f-values border rounded mb-2 bg-body" data-loaded="0">
                                                    <div class="text-muted small p-2 ledger-f-placeholder">Open to load values…</div>
                                                </div>
                                                <div class="d-flex gap-1 flex-shrink-0">
                                                    <button type="button" class="btn btn-sm btn-primary flex-grow-1 ledger-f-apply">Apply</button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary ledger-f-clear">Clear</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </th>
<?php endforeach; ?>
                                <th class="text-center" style="width:3rem" title="Line count">#</th>
                                <th class="text-center ledger-th-attach" style="width:2.6rem" title="Attachments">
                                    <i class="bi bi-paperclip" aria-hidden="true"></i>
                                    <span class="visually-hidden">Attachments</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody id="txTableBody">
                            <?php if (count($tx_list_rows) > 0): ?>
                                <?php foreach ($tx_list_rows as $r): ?>
                                    <?php
                                        $isCleared = !empty($r['is_cleared']) || ($r['status'] === 'cleared' || !empty($r['cleared_date']));
                                        $statusBadge = 'bg-secondary';
                                        $statusText = 'Pending';
                                        if ($r['status'] === 'cleared') { $statusBadge = 'bg-success'; $statusText = 'Cleared'; }
                                        elseif ($r['status'] === 'reconciled') { $statusBadge = 'bg-info'; $statusText = 'Reconciled'; }
                                        $tid = (int)$r['id'];
                                        $debAmt = (float)($r['debits'] ?? $r['total_debits'] ?? 0);
                                        $credAmt = (float)($r['credits'] ?? $r['total_credits'] ?? 0);
                                        $debDisplay = $fmtLedgerAmt($debAmt);
                                        $credDisplay = $fmtLedgerAmt($credAmt);
                                        $acctNames = (string)($r['account_names'] ?? '');
                                        $fundNames = (string)($r['fund_names'] ?? '');
                                        $descFull = (string)($r['description'] ?? '');
                                    ?>
                                    <tr data-id="<?= $tid ?>" data-cleared="<?= $isCleared ? '1' : '0' ?>" data-status="<?= htmlspecialchars($r['status']) ?>" data-debits="<?= htmlspecialchars((string)$debAmt) ?>" data-credits="<?= htmlspecialchars((string)$credAmt) ?>" data-doc-count="<?= (int)($r['doc_count'] ?? 0) ?>">
                                        <td><input type="checkbox" class="form-check-input tx-cb" value="<?= $tid ?>"></td>
                                        <td class="text-nowrap"><?= htmlspecialchars($r['transaction_date']) ?></td>
                                        <td class="font-monospace"><?= htmlspecialchars($r['reference_number'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($r['pay_to'] ?? '') ?></td>
                                        <td class="small text-muted" title="<?= htmlspecialchars($descFull) ?>"><?= htmlspecialchars(mb_strlen($descFull) > 70 ? mb_substr($descFull, 0, 70) . '…' : $descFull) ?></td>
                                        <td class="small" title="<?= htmlspecialchars($acctNames) ?>"><?= htmlspecialchars(mb_strlen($acctNames) > 40 ? mb_substr($acctNames, 0, 40) . '…' : $acctNames) ?></td>
                                        <td class="small" title="<?= htmlspecialchars($fundNames) ?>"><?= htmlspecialchars(mb_strlen($fundNames) > 30 ? mb_substr($fundNames, 0, 30) . '…' : $fundNames) ?></td>
                                        <td class="text-end font-monospace small">
                                            <?php if ($debDisplay !== ''): ?><div class="text-primary fw-semibold ledger-debit-col"><?= htmlspecialchars($debDisplay) ?></div><?php endif; ?>
                                            <?php if ($credDisplay !== ''): ?><div class="text-success fw-semibold ledger-credit-col"><?= htmlspecialchars($credDisplay) ?></div><?php endif; ?>
                                            <?php if ($debDisplay === '' && $credDisplay === ''): ?><span class="text-muted">&nbsp;</span><?php endif; ?>
                                        </td>
                                        <td><span class="badge <?= $statusBadge ?>"><?= $statusText ?></span></td>
                                        <td class="text-center"><?= (int)$r['num_lines'] ?></td>
                                        <?= $fmtAttachCell($tid, (int)($r['doc_count'] ?? 0)) ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr class="ledger-empty-row">
                                    <td colspan="11" class="text-center text-muted py-4">No transactions match the current filters.<?= $canWriteLedger ? ' Use “Add Transaction” to create one.' : '' ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="ledgerLoadMoreBar" class="d-flex justify-content-center align-items-center px-2 py-2 small bg-body-tertiary border-top flex-shrink-0 gap-2<?= $list_has_more ? '' : ' d-none' ?>">
                    <div id="ledgerLoadingIndicator" class="d-none text-muted">
                        <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                        Loading more…
                    </div>
                    <span id="ledgerEndOfList" class="text-muted d-none">End of list</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Transaction Add / Edit / View modal -->
<div class="modal fade" id="txFormModal" tabindex="-1" aria-labelledby="formTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form id="txForm" method="post" data-dirty-track>
                <div class="modal-header py-2">
                    <div class="d-flex align-items-center flex-wrap gap-2 me-2 min-w-0">
                        <h5 class="modal-title text-truncate mb-0" id="formTitle">Transaction Details</h5>
                        <span id="formModeBadge" class="badge bg-body-secondary text-body"></span>
                    </div>
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <button type="button" id="importTextBtn" class="btn btn-sm btn-outline-primary d-none"
                                title="Paste Beancount-style text to fill this form (does not save)">
                            <i class="bi bi-clipboard-data"></i> <span class="d-none d-sm-inline">Import from Text</span>
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="tx_id" id="tx_id">
                    <input type="hidden" name="lines_json" id="lines_json">

                    <div class="row g-2">
                        <div class="col-6 col-sm-4 col-md-2 col-xl-1">
                            <label class="form-label small mb-1">Date *</label>
                            <input type="date" class="form-control form-control-sm" name="transaction_date" id="transaction_date" required>
                        </div>
                        <div class="col-6 col-sm-4 col-md-2 col-xl-1">
                            <label class="form-label small mb-1" for="reference_number">
                                Ref # *
                                <button type="button" class="btn btn-link btn-sm p-0 align-baseline"
                                        id="referenceHelpBtn" title="List used Reference # values"
                                        aria-label="Show used Reference numbers">(?)</button>
                            </label>
                            <input type="text" class="form-control form-control-sm font-monospace ref-number-input" name="reference_number" id="reference_number"
                                   placeholder="" maxlength="6" pattern="\d{6}"
                                   data-suggested="" data-ref-kind="other"
                                   title="Double-click for next suggested number" required
                                   autocomplete="off">
                            <div class="form-text small text-muted lh-1" id="referenceSuggestHint" style="font-size:0.65rem;">
                                Double-click for next suggested number
                            </div>
                            <div class="form-text small text-warning d-none" id="referenceReuseWarn" style="font-size:0.7rem;"></div>
                            <input type="hidden" name="allow_reference_reuse" id="allow_reference_reuse" value="0">
                        </div>
                        <div class="col-6 col-sm-8 col-md-3 col-xl-2">
                            <label class="form-label small mb-1">Pay To</label>
                            <input type="text" class="form-control form-control-sm" name="pay_to" id="pay_to" placeholder="Vendor or person">
                        </div>
                        <div class="col-6 col-sm-4 col-md-2 col-xl-1">
                            <label class="form-label small mb-1">Check #</label>
                            <input type="text" class="form-control form-control-sm" name="check_number" id="check_number">
                        </div>
                        <div class="col-12 col-sm-8 col-md-4 col-xl-2">
                            <label class="form-label small mb-1" for="budget_id">Budget</label>
                            <select class="form-select form-select-sm" name="budget_id" id="budget_id"
                                    data-bs-toggle="tooltip" data-bs-placement="top"
                                    data-bs-html="true" title="">
                                <option value="">— None —</option>
                            </select>
                            <div class="form-text small text-warning d-none lh-sm" id="budgetPeriodWarn" style="font-size:0.7rem;"></div>
                            <div class="form-text small text-warning d-none lh-sm" id="budgetStatusWarn" style="font-size:0.7rem;"></div>
                            <input type="hidden" name="allow_budget_out_of_period" id="allow_budget_out_of_period" value="0">
                        </div>
                        <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                            <label class="form-label small mb-1">Description</label>
                            <input type="text" class="form-control form-control-sm" name="description" id="description" placeholder="Transaction description">
                        </div>
                    </div>

                    <div class="mt-2">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <h6 class="mb-0 small">Lines <small class="text-muted">(min 2 required)</small></h6>
                            <button type="button" id="addLineBtn" class="btn btn-sm btn-outline-primary">+ Add Line</button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-1" id="txLinesTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Account *</th>
                                        <th>Fund</th>
                                        <th>Natural</th>
                                        <th>Functional</th>
                                        <th class="text-end text-primary" style="width:100px">Debit</th>
                                        <th class="text-end text-success" style="width:100px">Credit</th>
                                        <th style="width:30px"></th>
                                    </tr>
                                </thead>
                                <tbody id="linesBody"></tbody>
                            </table>
                        </div>
                        <style>
                            #txLinesTable .line-cat-label {
                                display: block;
                                overflow: hidden;
                                text-overflow: ellipsis;
                                white-space: nowrap;
                                color: var(--bs-secondary-color);
                                font-size: 0.875rem;
                                max-width: 9rem;
                            }
                        </style>

                        <div class="d-flex flex-wrap gap-2 gap-md-3 align-items-center small">
                            <div><strong>Debits:</strong> <span id="totalDebits" class="text-primary fw-bold">0.00</span></div>
                            <div><strong>Credits:</strong> <span id="totalCredits" class="text-success fw-bold">0.00</span></div>
                            <div><strong>Diff:</strong> <span id="diff" class="fw-bold">0.00</span></div>
                            <div id="balanceStatus" class="text-muted"></div>
                        </div>
                    </div>

                    <div id="txMetaSection" class="mt-3 d-none">
                        <div class="row g-2 small mb-2" id="txMetaBadges"></div>
                        <div id="txContributionData" class="d-none mb-2"></div>
                        <h6 class="small mb-1">Documents</h6>
                        <ul id="txDocumentsList" class="list-unstyled small mb-2"></ul>
                        <!--
                          Not a nested <form> (invalid inside #txForm); button-driven upload via fetch.
                          name=tx_document avoids clashing with form.property "document".
                          data-dirty-ignore: selecting a file is not a transaction field edit.
                        -->
                        <div id="txDocUploadForm" class="d-flex flex-column flex-sm-row gap-2 align-items-stretch align-items-sm-center mb-3 d-none">
                            <input type="file" id="txDocFile" class="form-control form-control-sm"
                                   name="tx_document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                   data-dirty-ignore>
                            <button type="button" id="txDocUploadBtn" class="btn btn-outline-secondary btn-sm text-nowrap" disabled data-dirty-ignore>Upload</button>
                        </div>
                        <div class="accordion accordion-flush border rounded" id="txAuditAccordion">
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="txAuditHeading">
                                    <button class="accordion-button collapsed py-2 px-3 small fw-semibold" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#txAuditCollapse"
                                            aria-expanded="false" aria-controls="txAuditCollapse" id="txAuditToggle">
                                        Audit Trail
                                    </button>
                                </h2>
                                <div id="txAuditCollapse" class="accordion-collapse collapse"
                                     aria-labelledby="txAuditHeading" data-bs-parent="#txAuditAccordion">
                                    <div class="accordion-body p-2">
                                        <ul id="txEventsList" class="small mb-0 list-unstyled"></ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2 flex-wrap gap-2">
                    <button type="submit" id="saveBtn" class="btn btn-sm btn-primary" disabled>Save Transaction</button>
                    <button type="button" id="resetLinesBtn" class="btn btn-sm btn-outline-secondary">Reset to 2 Lines</button>
                    <button type="button" id="cancelFormBtn2" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Document preview modal (Bootstrap) -->
<div class="modal fade" id="txDocPreviewModal" tabindex="-1" aria-labelledby="txDocPreviewTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title text-truncate" id="txDocPreviewTitle">Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="txDocPreviewBody" style="min-height: 50vh;">
                <div class="text-center text-muted py-5">Loading…</div>
            </div>
            <div class="modal-footer py-2">
                <a href="#" id="txDocPreviewDownload" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">Download</a>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Ledger list attachment portfolio viewer -->
<div class="modal fade" id="txAttachPortfolioModal" tabindex="-1" aria-labelledby="txAttachPortfolioTitle" aria-hidden="true" data-no-autofocus>
    <div class="modal-dialog ledger-portfolio-dialog">
        <div class="modal-content ledger-portfolio-modal">
            <div class="modal-header py-2">
                <h5 class="modal-title text-truncate" id="txAttachPortfolioTitle">Attachments</h5>
                <div class="d-flex align-items-center gap-2 ms-auto">
                    <a href="#" id="txAttachDownload" class="btn btn-sm btn-outline-primary d-none" target="_blank" rel="noopener">
                        <i class="bi bi-download" aria-hidden="true"></i> Download
                    </a>
                    <button type="button" class="btn btn-outline-secondary ledger-portfolio-close" data-bs-dismiss="modal" aria-label="Close" title="Close">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="modal-body p-0">
                <div class="ledger-portfolio">
                    <aside class="ledger-portfolio-sidebar" aria-label="Attached documents">
                        <div class="ledger-portfolio-sidebar-head">Documents</div>
                        <ul id="txAttachPortfolioList" class="list-unstyled mb-0"></ul>
                    </aside>
                    <aside class="ledger-portfolio-pages d-none" id="txAttachPagePanel" aria-label="PDF pages">
                        <div class="ledger-portfolio-sidebar-head">Pages</div>
                        <div class="ledger-portfolio-page-nav" id="txAttachPdfNav">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="txAttachPdfPrev" title="Previous page" aria-label="Previous page">
                                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                            </button>
                            <span id="txAttachPdfPageLabel" class="ledger-portfolio-page-label">1 / 1</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="txAttachPdfNext" title="Next page" aria-label="Next page">
                                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                            </button>
                        </div>
                        <ul id="txAttachPageList" class="list-unstyled mb-0"></ul>
                    </aside>
                    <div class="ledger-portfolio-main">
                        <div class="ledger-portfolio-toolbar">
                            <div id="txAttachZoomBar" class="ledger-portfolio-zoom d-none" aria-label="Zoom">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="txAttachZoomOut" title="Zoom out">
                                    <i class="bi bi-zoom-out" aria-hidden="true"></i>
                                </button>
                                <span id="txAttachZoomLabel" class="ledger-portfolio-zoom-label">Fit</span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="txAttachZoomIn" title="Zoom in">
                                    <i class="bi bi-zoom-in" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="txAttachZoomFit" title="Fit page height">Fit</button>
                            </div>
                        </div>
                        <div id="txAttachPortfolioPane" class="ledger-portfolio-pane">
                            <div class="text-center text-muted py-5">Select a document</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Queue delete confirmation modal -->
<div class="modal fade" id="txQueueDeleteModal" tabindex="-1" aria-labelledby="txQueueDeleteTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="txQueueDeleteTitle">Queue file for deletion?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2" id="txQueueDeleteMessage">
                    This file will be queued for deletion. It will be removed only when you save the transaction.
                </p>
                <p class="small text-muted mb-0">File: <strong id="txQueueDeleteFileName"></strong></p>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal" id="txQueueDeleteCancel">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger" id="txQueueDeleteConfirm">Queue Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Import from Text (Beancount-style) — parse only, no DB writes -->
<div class="modal fade" id="importTextModal" tabindex="-1" aria-labelledby="importTextTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="importTextTitle">Import from Text</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3" role="note">
                    <strong>Read-only import.</strong> This tool only parses your text and fills the Add Transaction form.
                    Nothing is written to the database until you click <strong>Save Transaction</strong> on the form
                    (all usual balancing and validation still apply).
                </div>
                <p class="small text-muted mb-2">
                    Paste a single Beancount-style ledger entry. Date on the first line, then one posting per line
                    with an account name and signed amount (positive = debit, negative = credit). Amounts must sum to zero for a balanced entry.
                </p>
                <p class="small mb-1"><strong>Example format:</strong></p>
                <pre class="small bg-body-secondary border rounded p-2 mb-3" id="importTextExample" style="white-space: pre-wrap;">2026-03-15 * "Office Depot" "Printer paper and toner"
  reference: "260150"
  check: "4521"
  ; Ordered supplies for office
  Bank Account           -87.43  ; fund: GOF
  Accounts Payable        87.43</pre>
                <p class="small text-muted mb-2">
                    Header: first quoted string = <strong>Pay To</strong>, second = <strong>Description</strong>.
                    Metadata lines recognized: <code>reference:</code>, <code>ref:</code>, <code>sequence:</code>, and <code>check:</code> (others ignored).
                    <code>;</code> comments (full-line or trailing) are appended to Description when present.
                    Per-line fund: <code>; fund: GOF</code> or fund name. Account names must match the chart (case-insensitive).
                </p>
                <label class="form-label small mb-1" for="importTextArea">Ledger text</label>
                <textarea class="form-control font-monospace" id="importTextArea" rows="12"
                          placeholder="Paste one transaction here…"
                          spellcheck="false" autocomplete="off"></textarea>
                <div id="importTextErrors" class="alert alert-danger small mt-3 d-none mb-0" role="alert">
                    <div class="fw-semibold mb-1">Could not parse / populate</div>
                    <ul class="mb-0 ps-3" id="importTextErrorList"></ul>
                </div>
                <div id="importTextWarnings" class="alert alert-warning small mt-3 d-none mb-0" role="status">
                    <div class="fw-semibold mb-1">Warnings</div>
                    <ul class="mb-0 ps-3" id="importTextWarningList"></ul>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="importTextParseBtn">Parse &amp; Populate</button>
            </div>
        </div>
    </div>
</div>

<!-- Used Reference # values (scrollable) -->
<div class="modal fade" id="referenceListModal" tabindex="-1" aria-labelledby="referenceListTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="referenceListTitle">Used Reference # values</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <p class="small text-muted px-3 pt-2 mb-2">
                    Manual <code>YY####</code> Reference # values already on the ledger (highest first).
                    <strong>YY0001–YY0099</strong> are reserved for contributions;
                    <strong>YY0100+</strong> for payments, reimbursements, transfers, and other entries.
                    Double-click the Ref # field to fill the suggested next number for this form type.
                </p>
                <div class="table-responsive" style="max-height: 60vh;">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-3">Ref #</th>
                                <th>Date</th>
                                <th>Description</th>
                                <th class="pe-3 text-end">Tx #</th>
                            </tr>
                        </thead>
                        <tbody id="referenceListBody">
                            <tr><td colspan="4" class="text-center text-muted py-4">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script type="text/plain" id="init-ledger-script">
(function() {
    const form = document.getElementById('txForm');
    const linesBody = document.getElementById('linesBody');
    const addLineBtn = document.getElementById('addLineBtn');
    const resetLinesBtn = document.getElementById('resetLinesBtn');
    const saveBtn = document.getElementById('saveBtn');
    const linesJson = document.getElementById('lines_json');
    const refInput = document.getElementById('reference_number');
    const refReuseFlag = document.getElementById('allow_reference_reuse');
    const refReuseWarn = document.getElementById('referenceReuseWarn');
    const refSuggestHint = document.getElementById('referenceSuggestHint');
    const refHelpBtn = document.getElementById('referenceHelpBtn');
    let refReuseConfirmedFor = ''; // last Reference # user confirmed for reuse
    const txIdField = document.getElementById('tx_id');
    const formTitle = document.getElementById('formTitle');
    const cancelBtn2 = document.getElementById('cancelFormBtn2');

    const addTxBtn = document.getElementById('addTxBtn');
    const viewTxBtn = document.getElementById('viewTxBtn');
    const editTxBtn = document.getElementById('editTxBtn');
    const clearTxBtn = document.getElementById('clearTxBtn');
    const reconcileTxBtn = document.getElementById('reconcileTxBtn');
    const clearAllFiltersBtn = document.getElementById('clearAllFiltersBtn');

    const selectAll = document.getElementById('selectAll');
    const txTableBody = document.getElementById('txTableBody');
    const ledgerTableScroll = document.getElementById('ledgerTableScroll');
    const ledgerTotalLabel = document.getElementById('ledgerTotalLabel');
    const ledgerLoadMoreBar = document.getElementById('ledgerLoadMoreBar');
    const ledgerLoadingIndicator = document.getElementById('ledgerLoadingIndicator');
    const ledgerEndOfList = document.getElementById('ledgerEndOfList');

    // Transaction form modal (Add / Edit / View)
    let txFormModalEl = document.getElementById('txFormModal');
    if (txFormModalEl && typeof window.mountModalOnBody === 'function') {
        txFormModalEl = window.mountModalOnBody(txFormModalEl);
    }
    function openTxFormModal() {
        if (!txFormModalEl) return;
        // Already visible — keep content swap without re-show flicker
        if (txFormModalEl.classList.contains('show')) return;
        showLedgerModal(txFormModalEl);
    }
    function closeTxFormModal() {
        if (!txFormModalEl || typeof bootstrap === 'undefined') return;
        const inst = bootstrap.Modal.getInstance(txFormModalEl);
        if (inst) inst.hide();
    }
    function isTxFormModalOpen() {
        return !!(txFormModalEl && txFormModalEl.classList.contains('show'));
    }

    // List state for infinite scroll + server-side filters
    let listState = { total: 0, offset: 0, limit: 50, has_more: false, sort: 'date', sort_dir: 'desc', filters: {}, loading: false };
    (function initListStateFromDom() {
        const el = document.getElementById('ledger-list-state');
        if (!el) return;
        try {
            const raw = JSON.parse(el.textContent || '{}');
            listState.total = raw.total || 0;
            listState.offset = (raw.offset || 0) + (raw.loaded || 0);
            listState.limit = raw.limit || 50;
            listState.has_more = !!raw.has_more;
            listState.sort = raw.sort || 'date';
            listState.sort_dir = raw.sort_dir || 'desc';
            listState.filters = normalizeFilters(raw.filters || {});
        } catch (e) { /* ignore */ }
    })();

    const accountOpts = `<?= $aopt ?>`;
    const fundOpts = `<?= $fopt ?>`;
    const budgetOptions = <?= json_encode($budgetOptions, JSON_UNESCAPED_UNICODE) ?> || [];
    const defaultBudgetIdToday = <?= $defaultBudgetIdToday !== null ? (int)$defaultBudgetIdToday : 'null' ?>;
    /** Name→id lookups for text paste import (read-only populate). */
    const ledgerImportLookups = {
        accounts: <?= json_encode($accountsLookup, JSON_UNESCAPED_UNICODE) ?> || [],
        funds: <?= json_encode($fundsLookup, JSON_UNESCAPED_UNICODE) ?> || [],
        natural: <?= json_encode($naturalLookup, JSON_UNESCAPED_UNICODE) ?> || [],
        functional: <?= json_encode($functionalLookup, JSON_UNESCAPED_UNICODE) ?> || []
    };
    const importTextBtn = document.getElementById('importTextBtn');
    let importTextModalEl = document.getElementById('importTextModal');
    const importTextArea = document.getElementById('importTextArea');
    const importTextParseBtn = document.getElementById('importTextParseBtn');
    const importTextErrors = document.getElementById('importTextErrors');
    const importTextErrorList = document.getElementById('importTextErrorList');
    const importTextWarnings = document.getElementById('importTextWarnings');
    const importTextWarningList = document.getElementById('importTextWarningList');

    /**
     * Use shell helpers (footer mountModalOnBody / showFragmentModal) so ledger modals
     * stack above the body backdrop. Local wrappers keep call sites readable.
     */
    function mountModalOnBody(modalEl) {
        if (typeof window.mountModalOnBody === 'function') {
            return window.mountModalOnBody(modalEl);
        }
        if (!modalEl || !modalEl.classList || !modalEl.classList.contains('modal')) return modalEl;
        if (modalEl.parentElement !== document.body) {
            document.body.appendChild(modalEl);
        }
        return modalEl;
    }

    function showLedgerModal(modalEl, options) {
        if (typeof window.showFragmentModal === 'function') {
            return window.showFragmentModal(modalEl, options);
        }
        if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
        mountModalOnBody(modalEl);
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl, options || {});
        modal.show();
        return modal;
    }
    const budgetSelect = document.getElementById('budget_id');
    const budgetPeriodWarn = document.getElementById('budgetPeriodWarn');
    const budgetStatusWarn = document.getElementById('budgetStatusWarn');
    const budgetOutOfPeriodFlag = document.getElementById('allow_budget_out_of_period');
    /** When true, date changes auto-pick the covering active budget. Cleared after manual budget change. */
    let budgetAutoMode = true;
    /** Cleared/reconciled edit: only budget may change; other header/line fields stay locked. */
    let budgetOnlyEditMode = false;
    const BUDGET_STATUS_WARN_MSG = 'This transaction is already cleared/reconciled. Changing budget will not affect the audit trail but may impact reporting.';

    function budgetById(id) {
        if (!id) return null;
        return budgetOptions.find(b => String(b.id) === String(id)) || null;
    }
    /** Standard Bootstrap tooltip title: period, then description (HTML for line break). */
    function budgetTooltipTitle(b) {
        if (!b) return '';
        const period = b.period_label || formatPeriodMdY(b.start_date || '', b.end_date || '');
        if (!period) return '';
        const desc = (b.description || '').trim();
        return desc ? (escHtml(period) + '<br>' + escHtml(desc)) : escHtml(period);
    }
    /** Refresh Bootstrap tooltip on #budget_id for the current selection. */
    function syncBudgetSelectTooltip() {
        if (!budgetSelect || typeof bootstrap === 'undefined' || !bootstrap.Tooltip) return;
        const existing = bootstrap.Tooltip.getInstance(budgetSelect);
        if (existing) existing.dispose();
        const title = budgetTooltipTitle(budgetById(budgetSelect.value));
        if (!title) {
            budgetSelect.removeAttribute('title');
            budgetSelect.removeAttribute('data-bs-original-title');
            return;
        }
        budgetSelect.setAttribute('title', title);
        new bootstrap.Tooltip(budgetSelect);
    }
    function fillBudgetSelect(selectedId) {
        if (!budgetSelect) return;
        const keep = selectedId != null && selectedId !== '' ? String(selectedId) : '';
        budgetSelect.innerHTML = '<option value="">— None —</option>';
        budgetOptions.forEach(b => {
            const opt = document.createElement('option');
            opt.value = String(b.id);
            // Dropdown shows Name only
            opt.textContent = b.name || b.label || ('Budget #' + b.id);
            opt.dataset.start = b.start_date || '';
            opt.dataset.end = b.end_date || '';
            opt.dataset.period = b.period_label || '';
            opt.dataset.status = b.status || '';
            if (keep && String(b.id) === keep) opt.selected = true;
            budgetSelect.appendChild(opt);
        });
        // If saved budget is draft/missing from list, still show it as selected
        if (keep && !budgetById(keep)) {
            const opt = document.createElement('option');
            opt.value = keep;
            opt.textContent = 'Budget #' + keep + ' (unavailable)';
            opt.selected = true;
            budgetSelect.appendChild(opt);
        }
        if (keep) budgetSelect.value = keep;
        syncBudgetSelectTooltip();
    }
    function formatPeriodMdY(start, end) {
        const fmt = (iso) => {
            if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return iso || '';
            const [y, m, d] = iso.split('-');
            return m + '/' + d + '/' + y;
        };
        if (!start || !end) return '';
        return fmt(start) + ' - ' + fmt(end);
    }
    function isDateInBudgetPeriod(dateStr, budget) {
        if (!dateStr || !budget) return true;
        const start = budget.start_date || budget.dataset?.start || '';
        const end = budget.end_date || budget.dataset?.end || '';
        if (!start || !end) return true;
        return dateStr >= start && dateStr <= end;
    }
    function defaultBudgetIdForDate(dateStr) {
        if (!dateStr || !/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) return null;
        // Prefer active covering the date
        const active = budgetOptions.filter(b => b.status === 'active' && dateStr >= b.start_date && dateStr <= b.end_date);
        if (active.length) return active[0].id;
        const other = budgetOptions.filter(b => dateStr >= b.start_date && dateStr <= b.end_date);
        return other.length ? other[0].id : null;
    }
    function clearBudgetOutOfPeriodFlag() {
        if (budgetOutOfPeriodFlag) budgetOutOfPeriodFlag.value = '0';
        if (budgetPeriodWarn) {
            budgetPeriodWarn.classList.add('d-none');
            budgetPeriodWarn.textContent = '';
        }
    }
    function updateBudgetPeriodWarning() {
        if (!budgetSelect || !budgetPeriodWarn) return;
        const dateStr = document.getElementById('transaction_date')?.value || '';
        const bid = budgetSelect.value;
        if (!bid || !dateStr) {
            clearBudgetOutOfPeriodFlag();
            return;
        }
        const b = budgetById(bid);
        const start = b ? b.start_date : '';
        const end = b ? b.end_date : '';
        if (!start || !end || (dateStr >= start && dateStr <= end)) {
            clearBudgetOutOfPeriodFlag();
            return;
        }
        const period = b ? b.period_label : formatPeriodMdY(start, end);
        budgetPeriodWarn.textContent = 'Date is outside budget period (' + period + '). Save will ask you to confirm.';
        budgetPeriodWarn.classList.remove('d-none');
        if (budgetOutOfPeriodFlag) budgetOutOfPeriodFlag.value = '0';
    }
    function applyBudgetForDate(dateStr, { force = false } = {}) {
        if (!budgetSelect) return;
        if (!force && !budgetAutoMode) {
            updateBudgetPeriodWarning();
            return;
        }
        const defId = defaultBudgetIdForDate(dateStr);
        if (defId != null) {
            fillBudgetSelect(defId);
        } else if (force || !budgetSelect.value) {
            fillBudgetSelect('');
        }
        clearBudgetOutOfPeriodFlag();
        updateBudgetPeriodWarning();
    }
    function setBudgetSelection(budgetId, { auto = false } = {}) {
        budgetAutoMode = !!auto;
        fillBudgetSelect(budgetId || '');
        updateBudgetPeriodWarning();
    }

    /** Column key → multi-select filter array key in listState.filters */
    const FILTER_KEY_MAP = {
        date: 'dates',
        reference: 'references',
        pay_to: 'pay_tos',
        description: 'descriptions',
        account: 'account_ids',
        fund: 'fund_ids',
        amount: 'amounts',
        status: 'statuses'
    };
    const FILTER_PARAM_MAP = {
        dates: 'date',
        references: 'reference',
        pay_tos: 'pay_to',
        descriptions: 'description',
        account_ids: 'account_id',
        fund_ids: 'fund_id',
        amounts: 'amount',
        statuses: 'status'
    };

    function emptyMultiFilters() {
        return {
            dates: [],
            references: [],
            descriptions: [],
            pay_tos: [],
            statuses: [],
            amounts: [],
            account_ids: [],
            fund_ids: [],
            date_from: '',
            date_to: '',
            check_number: '',
            search: '',
            amount_min: '',
            amount_max: '',
            account_id: 0,
            fund_id: 0
        };
    }

    function normalizeFilters(raw) {
        const base = emptyMultiFilters();
        if (!raw || typeof raw !== 'object') return base;
        const asArr = (v) => {
            if (Array.isArray(v)) return v.map(x => String(x));
            if (v === undefined || v === null || v === '') return [];
            return [String(v)];
        };
        const asIdArr = (v) => asArr(v).map(x => parseInt(x, 10)).filter(n => n > 0).map(String);
        base.dates = asArr(raw.dates || raw.date);
        base.references = asArr(raw.references != null ? raw.references : raw.reference);
        base.descriptions = asArr(raw.descriptions != null ? raw.descriptions : raw.description);
        base.pay_tos = asArr(raw.pay_tos != null ? raw.pay_tos : raw.pay_to);
        base.statuses = asArr(raw.statuses != null ? raw.statuses : raw.status);
        base.amounts = asArr(raw.amounts != null ? raw.amounts : raw.amount);
        base.account_ids = asIdArr(raw.account_ids != null ? raw.account_ids : raw.account_id);
        base.fund_ids = asIdArr(raw.fund_ids != null ? raw.fund_ids : raw.fund_id);
        base.date_from = raw.date_from ? String(raw.date_from) : '';
        base.date_to = raw.date_to ? String(raw.date_to) : '';
        base.check_number = raw.check_number ? String(raw.check_number) : '';
        base.search = raw.search ? String(raw.search) : '';
        base.amount_min = raw.amount_min != null && raw.amount_min !== '' ? String(raw.amount_min) : '';
        base.amount_max = raw.amount_max != null && raw.amount_max !== '' ? String(raw.amount_max) : '';
        base.account_id = base.account_ids.length === 1 ? parseInt(base.account_ids[0], 10) : 0;
        base.fund_id = base.fund_ids.length === 1 ? parseInt(base.fund_ids[0], 10) : 0;
        return base;
    }

    function getActiveFilters() {
        return normalizeFilters(listState.filters || {});
    }

    function buildFilterParams(includeSort = true, offset = null, limit = null) {
        const p = new URLSearchParams();
        const f = getActiveFilters();
        const appendMulti = (paramName, arr) => {
            if (!arr || !arr.length) return;
            arr.forEach(v => p.append(paramName + '[]', String(v)));
        };
        appendMulti('date', f.dates);
        appendMulti('reference', f.references);
        appendMulti('description', f.descriptions);
        appendMulti('pay_to', f.pay_tos);
        appendMulti('status', f.statuses);
        appendMulti('amount', f.amounts);
        appendMulti('account_id', f.account_ids);
        appendMulti('fund_id', f.fund_ids);
        if (f.date_from) p.set('date_from', f.date_from);
        if (f.date_to) p.set('date_to', f.date_to);
        if (f.check_number) p.set('check_number', f.check_number);
        if (f.search) p.set('search', f.search);
        if (f.amount_min) p.set('amount_min', f.amount_min);
        if (f.amount_max) p.set('amount_max', f.amount_max);
        if (includeSort) {
            if (listState.sort) p.set('sort', listState.sort);
            if (listState.sort_dir) p.set('sort_dir', listState.sort_dir);
        }
        if (offset !== null && offset !== undefined) p.set('offset', String(offset));
        if (limit !== null && limit !== undefined) p.set('limit', String(limit));
        return p;
    }

    function buildQueryString(_preservePage = true) {
        const p = buildFilterParams(true, null, null);
        const s = p.toString();
        return s ? '?' + s : '';
    }

    function hasAnyActiveFilter() {
        const f = getActiveFilters();
        const multiKeys = ['dates', 'references', 'descriptions', 'pay_tos', 'statuses', 'amounts', 'account_ids', 'fund_ids'];
        for (const k of multiKeys) {
            if (f[k] && f[k].length) return true;
        }
        if (f.date_from || f.date_to || f.check_number || f.search || f.amount_min || f.amount_max) return true;
        return false;
    }

    function updateClearAllFiltersBtn() {
        if (!clearAllFiltersBtn) return;
        clearAllFiltersBtn.disabled = !hasAnyActiveFilter();
    }

    function isColumnFilterActive(col, f) {
        f = f || getActiveFilters();
        const key = FILTER_KEY_MAP[col];
        if (key && f[key] && f[key].length) return true;
        if (col === 'date' && (f.date_from || f.date_to)) return true;
        if (col === 'amount' && (f.amount_min || f.amount_max)) return true;
        return false;
    }

    function selectedValuesForColumn(col) {
        const f = getActiveFilters();
        const key = FILTER_KEY_MAP[col];
        return key ? (f[key] || []).map(String) : [];
    }

    function setColumnFilterValues(col, values) {
        const f = getActiveFilters();
        const key = FILTER_KEY_MAP[col];
        if (!key) return;
        if (col === 'account' || col === 'fund') {
            f[key] = (values || []).map(v => String(parseInt(v, 10))).filter(v => v !== '0' && v !== 'NaN');
        } else {
            f[key] = (values || []).map(String);
        }
        // Clear legacy range when multi dates set
        if (col === 'date') {
            f.date_from = '';
            f.date_to = '';
        }
        if (col === 'amount') {
            f.amount_min = '';
            f.amount_max = '';
        }
        f.account_id = f.account_ids.length === 1 ? parseInt(f.account_ids[0], 10) : 0;
        f.fund_id = f.fund_ids.length === 1 ? parseInt(f.fund_ids[0], 10) : 0;
        listState.filters = f;
    }

    function escAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function renderMultiValueList(container, values, selected, { isDateTree, tree } = {}) {
        if (!container) return;
        const selectedSet = new Set((selected || []).map(String));
        // When no filter active, Excel shows all checked
        const allChecked = selectedSet.size === 0;

        if (isDateTree && tree && tree.length) {
            let html = '<div class="ledger-date-tree">';
            tree.forEach((yNode, yi) => {
                const yearDays = [];
                (yNode.months || []).forEach(m => (m.days || []).forEach(d => yearDays.push(String(d.value))));
                const yearAll = yearDays.every(d => allChecked || selectedSet.has(d));
                const yearSome = yearDays.some(d => allChecked || selectedSet.has(d));
                html += '<details class="mb-1" open>'
                    + '<summary class="ledger-date-year ledger-f-item">'
                    + '<input type="checkbox" class="form-check-input ledger-f-cb ledger-f-year-cb" data-year="' + escAttr(yNode.year) + '"'
                    + (yearAll ? ' checked' : '') + (yearSome && !yearAll ? ' data-indeterminate="1"' : '')
                    + ' data-dirty-ignore>'
                    + '<span class="small ledger-f-label">' + escHtml(yNode.year) + '</span>'
                    + '<span class="ledger-f-count">(' + (yNode.count || 0) + ')</span>'
                    + '</summary><div class="ledger-date-month-wrap">';
                (yNode.months || []).forEach((mNode) => {
                    const monthDays = (mNode.days || []).map(d => String(d.value));
                    const mAll = monthDays.every(d => allChecked || selectedSet.has(d));
                    const mSome = monthDays.some(d => allChecked || selectedSet.has(d));
                    html += '<details class="mb-1" open>'
                        + '<summary class="ledger-date-month ledger-f-item">'
                        + '<input type="checkbox" class="form-check-input ledger-f-cb ledger-f-month-cb" data-year="' + escAttr(yNode.year) + '" data-month="' + escAttr(mNode.month) + '"'
                        + (mAll ? ' checked' : '') + (mSome && !mAll ? ' data-indeterminate="1"' : '')
                        + ' data-dirty-ignore>'
                        + '<span class="small ledger-f-label">' + escHtml(mNode.label || mNode.month) + '</span>'
                        + '<span class="ledger-f-count">(' + (mNode.count || 0) + ')</span>'
                        + '</summary><div class="ledger-date-day-wrap">';
                    (mNode.days || []).forEach(d => {
                        const val = String(d.value);
                        const checked = allChecked || selectedSet.has(val);
                        // Day label is day-of-month only (meaningful display); full ISO stays in value/data-label for search/filter.
                        const dayLabel = String(d.label || val);
                        html += '<div class="ledger-f-item ledger-date-day" data-search="' + escAttr((val + ' ' + dayLabel).toLowerCase()) + '">'
                            + '<input class="form-check-input ledger-f-cb ledger-f-day-cb" type="checkbox" value="' + escAttr(val) + '"'
                            + ' data-label="' + escAttr(val) + '"'
                            + (checked ? ' checked' : '') + ' data-dirty-ignore>'
                            + '<label class="ledger-f-label small">' + escHtml(dayLabel)
                            + ' <span class="ledger-f-count">(' + (d.count || 0) + ')</span></label>'
                            + '</div>';
                    });
                    html += '</div></details>';
                });
                html += '</div></details>';
            });
            html += '</div>';
            container.innerHTML = html;
            container.querySelectorAll('input[data-indeterminate="1"]').forEach(el => {
                el.indeterminate = true;
            });
            return;
        }

        if (!values || !values.length) {
            container.innerHTML = '<div class="text-muted small p-2">No values</div>';
            return;
        }
        let html = '';
        values.forEach((item, idx) => {
            const val = String(item.value);
            const label = item.label != null ? String(item.label) : val;
            const checked = allChecked || selectedSet.has(val);
            const id = 'lfv_' + Math.random().toString(36).slice(2, 9) + '_' + idx;
            // Flex row (no Bootstrap form-check float) so the checkbox stays fully visible on the left.
            // data-search uses display label only (not raw ids/codes) so search matches what the user sees.
            html += '<div class="ledger-f-item" data-search="' + escAttr(label.toLowerCase()) + '">'
                + '<input class="form-check-input ledger-f-cb" type="checkbox" value="' + escAttr(val) + '" id="' + id + '"'
                + ' data-label="' + escAttr(label) + '"'
                + (checked ? ' checked' : '') + ' data-dirty-ignore>'
                + '<label class="ledger-f-label small" for="' + id + '">' + escHtml(label)
                + (item.count != null ? ' <span class="ledger-f-count">(' + item.count + ')</span>' : '')
                + '</label></div>';
        });
        container.innerHTML = html;
    }

    function getLeafCheckboxes(th, { visibleOnly = false, checkedOnly = false } = {}) {
        const menu = th && th.querySelector('.ledger-filter-menu');
        if (!menu) return [];
        const isDate = !!menu.querySelector('.ledger-date-tree');
        const selector = isDate
            ? '.ledger-f-day-cb'
            : '.ledger-f-values .ledger-f-cb:not(.ledger-f-year-cb):not(.ledger-f-month-cb)';
        return Array.from(menu.querySelectorAll(selector)).filter(cb => {
            if (checkedOnly && !cb.checked) return false;
            if (visibleOnly) {
                const item = cb.closest('.ledger-f-item');
                if (item && item.style.display === 'none') return false;
                const det = cb.closest('details');
                if (det && det.style.display === 'none') return false;
            }
            return true;
        });
    }

    function syncSelectAllCheckbox(th) {
        const menu = th && th.querySelector('.ledger-filter-menu');
        if (!menu) return;
        const selectAll = menu.querySelector('.ledger-f-select-all');
        if (!selectAll) return;
        const leaves = getLeafCheckboxes(th, { visibleOnly: true });
        if (!leaves.length) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
            return;
        }
        const nChecked = leaves.filter(c => c.checked).length;
        selectAll.checked = nChecked === leaves.length;
        selectAll.indeterminate = nChecked > 0 && nChecked < leaves.length;
    }

    function getCheckedLeafValues(th) {
        return getLeafCheckboxes(th, { checkedOnly: true }).map(cb => cb.value);
    }

    function getAllLeafValues(th) {
        return getLeafCheckboxes(th).map(cb => cb.value);
    }

    function applySearchFilterToMenu(th, q) {
        const menu = th.querySelector('.ledger-filter-menu');
        if (!menu) return;
        q = String(q || '').trim().toLowerCase();
        const isDate = !!menu.querySelector('.ledger-date-tree');
        if (isDate) {
            menu.querySelectorAll('.ledger-f-day-cb').forEach(cb => {
                const label = (cb.dataset.label || cb.value || '').toLowerCase();
                const show = !q || label.includes(q);
                const wrap = cb.closest('.ledger-f-item');
                if (wrap) wrap.style.display = show ? '' : 'none';
            });
            // Hide empty months/years
            menu.querySelectorAll('details').forEach(det => {
                const days = det.querySelectorAll('.ledger-f-day-cb');
                if (!days.length) return;
                let any = false;
                days.forEach(d => {
                    const wrap = d.closest('.ledger-f-item');
                    if (wrap && wrap.style.display !== 'none') any = true;
                });
                det.style.display = any || !q ? '' : 'none';
            });
        } else {
            menu.querySelectorAll('.ledger-f-values .ledger-f-item').forEach(item => {
                const hay = (item.dataset.search || item.textContent || '').toLowerCase();
                item.style.display = !q || hay.includes(q) ? '' : 'none';
            });
        }
        syncSelectAllCheckbox(th);
    }

    function setAllVisibleChecked(th, checked) {
        const menu = th.querySelector('.ledger-filter-menu');
        if (!menu) return;
        const isDate = !!menu.querySelector('.ledger-date-tree');
        const leaves = isDate
            ? menu.querySelectorAll('.ledger-f-day-cb')
            : menu.querySelectorAll('.ledger-f-values .ledger-f-cb:not(.ledger-f-year-cb):not(.ledger-f-month-cb)');
        leaves.forEach(cb => {
            const item = cb.closest('.ledger-f-item');
            if (item && item.style.display === 'none') return;
            cb.checked = checked;
        });
        if (isDate) {
            menu.querySelectorAll('.ledger-f-year-cb, .ledger-f-month-cb').forEach(cb => {
                const det = cb.closest('details');
                if (det && det.style.display === 'none') return;
                cb.checked = checked;
                cb.indeterminate = false;
            });
        }
        syncSelectAllCheckbox(th);
    }

    function syncDateParentCheckboxes(th) {
        const menu = th.querySelector('.ledger-filter-menu');
        if (!menu || !menu.querySelector('.ledger-date-tree')) return;
        menu.querySelectorAll('details').forEach(det => {
            const parentCb = det.querySelector(':scope > summary > .ledger-f-cb');
            if (!parentCb) return;
            const days = det.querySelectorAll('.ledger-f-day-cb');
            if (!days.length) return;
            const n = days.length;
            const c = Array.from(days).filter(d => d.checked).length;
            parentCb.checked = c === n;
            parentCb.indeterminate = c > 0 && c < n;
        });
        syncSelectAllCheckbox(th);
    }

    function loadFilterValuesForMenu(th) {
        if (!th) return Promise.resolve();
        const col = th.dataset.col;
        const menu = th.querySelector('.ledger-filter-menu');
        const box = th.querySelector('.ledger-f-values');
        if (!menu || !box) return Promise.resolve();
        box.innerHTML = '<div class="text-muted small p-2"><span class="spinner-border spinner-border-sm me-1"></span>Loading…</div>';
        const params = buildFilterParams(false, null, null);
        params.set('filter_values', '1');
        params.set('column', col);
        return fetch('pages/ledger.php?' + params.toString())
            .then(r => r.json())
            .then(data => {
                if (!data || data.success === false) {
                    box.innerHTML = '<div class="text-danger small p-2">' + escHtml((data && data.error) || 'Failed to load values') + '</div>';
                    return;
                }
                const selected = selectedValuesForColumn(col);
                const isDate = col === 'date';
                renderMultiValueList(box, data.values || [], selected, {
                    isDateTree: isDate,
                    tree: data.tree
                });
                box.dataset.loaded = '1';
                syncSelectAllCheckbox(th);
            })
            .catch(err => {
                console.error(err);
                box.innerHTML = '<div class="text-danger small p-2">Failed to load values</div>';
            });
    }

    function fmtLedgerAmtJs(amount) {
        const a = parseFloat(amount) || 0;
        if (Math.abs(a) < 0.005) return '';
        return '$' + Math.abs(a).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function statusBadgeHtml(status) {
        const st = String(status || 'pending');
        let badge = 'bg-secondary';
        let text = 'Pending';
        if (st === 'cleared') { badge = 'bg-success'; text = 'Cleared'; }
        else if (st === 'reconciled') { badge = 'bg-info'; text = 'Reconciled'; }
        return '<span class="badge ' + badge + '">' + text + '</span>';
    }

    function truncText(s, n) {
        s = String(s || '');
        if (s.length <= n) return s;
        return s.slice(0, n) + '…';
    }

    function renderTxRow(r) {
        const tid = parseInt(r.id, 10) || 0;
        const isCleared = r.is_cleared || r.status === 'cleared' || !!r.cleared_date;
        const debAmt = r.debits != null ? r.debits : (r.total_debits || 0);
        const credAmt = r.credits != null ? r.credits : (r.total_credits || 0);
        const debDisplay = fmtLedgerAmtJs(debAmt);
        const credDisplay = fmtLedgerAmtJs(credAmt);
        const desc = r.description || '';
        const accts = r.account_names || '';
        const funds = r.fund_names || '';
        let amtHtml = '';
        if (debDisplay) amtHtml += '<div class="text-primary fw-semibold ledger-debit-col">' + escHtml(debDisplay) + '</div>';
        if (credDisplay) amtHtml += '<div class="text-success fw-semibold ledger-credit-col">' + escHtml(credDisplay) + '</div>';
        if (!amtHtml) amtHtml = '<span class="text-muted">&nbsp;</span>';
        return '<tr data-id="' + tid + '" data-cleared="' + (isCleared ? '1' : '0') + '" data-status="' + escHtml(r.status || 'pending') + '" data-debits="' + escHtml(String(debAmt)) + '" data-credits="' + escHtml(String(credAmt)) + '" data-doc-count="' + (parseInt(r.doc_count, 10) || 0) + '">'
            + '<td><input type="checkbox" class="form-check-input tx-cb" value="' + tid + '"></td>'
            + '<td class="text-nowrap">' + escHtml(r.transaction_date || '') + '</td>'
            + '<td class="font-monospace">' + escHtml(r.reference_number || '') + '</td>'
            + '<td>' + escHtml(r.pay_to || '') + '</td>'
            + '<td class="small text-muted" title="' + escHtml(desc) + '">' + escHtml(truncText(desc, 70)) + '</td>'
            + '<td class="small" title="' + escHtml(accts) + '">' + escHtml(truncText(accts, 40)) + '</td>'
            + '<td class="small" title="' + escHtml(funds) + '">' + escHtml(truncText(funds, 30)) + '</td>'
            + '<td class="text-end font-monospace small">' + amtHtml + '</td>'
            + '<td>' + statusBadgeHtml(r.status) + '</td>'
            + '<td class="text-center">' + (parseInt(r.num_lines, 10) || 0) + '</td>'
            + attachCellHtml(tid, r.doc_count)
            + '</tr>';
    }

    function attachCellHtml(txId, docCount) {
        const n = parseInt(docCount, 10) || 0;
        if (n < 1) {
            return '<td class="text-center ledger-attach-cell"></td>';
        }
        const label = n === 1 ? 'View 1 attachment' : ('View ' + n + ' attachments');
        const badge = n > 1
            ? '<span class="badge rounded-pill text-bg-secondary ledger-attach-count">' + n + '</span>'
            : '';
        return '<td class="text-center ledger-attach-cell">'
            + '<button type="button" class="btn btn-link btn-sm p-0 ledger-attach-btn" data-tx-id="'
            + (parseInt(txId, 10) || 0)
            + '" title="' + escHtml(label) + '" aria-label="' + escHtml(label) + '">'
            + '<i class="bi bi-paperclip" aria-hidden="true"></i>'
            + badge
            + '</button></td>';
    }

    function updateRowAttachmentIndicator(txId, docCount) {
        const id = parseInt(txId, 10) || 0;
        if (!id || !txTableBody) return;
        const row = txTableBody.querySelector('tr[data-id="' + id + '"]');
        if (!row) return;
        const n = parseInt(docCount, 10) || 0;
        row.dataset.docCount = String(n);
        const cell = row.querySelector('.ledger-attach-cell');
        if (cell) {
            const tmp = document.createElement('tbody');
            tmp.innerHTML = '<tr>' + attachCellHtml(id, n) + '</tr>';
            const next = tmp.querySelector('td');
            if (next) cell.replaceWith(next);
        }
    }

    function setListLoading(on) {
        listState.loading = !!on;
        if (ledgerLoadingIndicator) ledgerLoadingIndicator.classList.toggle('d-none', !on);
        if (ledgerLoadMoreBar && (on || listState.has_more)) {
            ledgerLoadMoreBar.classList.remove('d-none');
        }
    }

    function updateListFooter() {
        if (ledgerTotalLabel) {
            ledgerTotalLabel.textContent = (listState.total || 0) + ' total';
        }
        if (ledgerLoadMoreBar) {
            if (listState.has_more || listState.loading) {
                ledgerLoadMoreBar.classList.remove('d-none');
            } else if ((listState.total || 0) > 0 && listState.offset >= listState.total) {
                ledgerLoadMoreBar.classList.remove('d-none');
            } else {
                ledgerLoadMoreBar.classList.add('d-none');
            }
        }
        if (ledgerEndOfList) {
            const showEnd = !listState.has_more && !listState.loading && (listState.total || 0) > 0 && listState.offset > 0;
            ledgerEndOfList.classList.toggle('d-none', !showEnd);
        }
        updateClearAllFiltersBtn();
        updateFilterHeaderIndicators();
    }

    function updateFilterHeaderIndicators() {
        const f = getActiveFilters();
        document.querySelectorAll('#ledgerTxTable thead th.ledger-th-filter').forEach(th => {
            const col = th.dataset.col;
            const active = isColumnFilterActive(col, f);
            th.classList.toggle('ledger-filter-active', active);
            const btn = th.querySelector('.ledger-filter-toggle');
            const icon = th.querySelector('.ledger-filter-toggle i');
            if (btn) {
                btn.classList.toggle('text-warning', active);
                btn.classList.toggle('text-white-50', !active);
            }
            if (icon) {
                icon.className = active ? 'bi bi-funnel-fill' : 'bi bi-funnel';
            }
        });
        // Sort indicators
        document.querySelectorAll('#ledgerTxTable .ledger-sort-btn').forEach(btn => {
            const col = btn.dataset.sort;
            const ind = btn.querySelector('.ledger-sort-indicator');
            if (!ind) return;
            let sorted = listState.sort === col;
            if (col === 'amount' && ['amount', 'debit', 'credit'].includes(listState.sort)) sorted = true;
            if (col === 'reference' && ['reference', 'ref'].includes(listState.sort)) sorted = true;
            ind.textContent = sorted ? (listState.sort_dir === 'asc' ? ' ↑' : ' ↓') : '';
        });
    }

    function fetchTransactionList({ reset = false } = {}) {
        if (listState.loading) return Promise.resolve();
        if (!reset && !listState.has_more) return Promise.resolve();
        const offset = reset ? 0 : (listState.offset || 0);
        const limit = listState.limit || 50;
        const params = buildFilterParams(true, offset, limit);
        params.set('list_transactions', '1');
        setListLoading(true);
        return fetch('pages/ledger.php?' + params.toString())
            .then(r => r.json())
            .then(data => {
                if (!data || data.success === false) {
                    showToast((data && data.error) || 'Failed to load transactions.', 'danger');
                    return;
                }
                const rows = data.rows || [];
                if (reset) {
                    if (!txTableBody) return;
                    if (rows.length === 0) {
                        txTableBody.innerHTML = '<tr class="ledger-empty-row"><td colspan="11" class="text-center text-muted py-4">No transactions match the current filters.</td></tr>';
                    } else {
                        txTableBody.innerHTML = rows.map(renderTxRow).join('');
                    }
                    if (selectAll) selectAll.checked = false;
                    lastAnchorRow = null;
                    updateButtonStates();
                } else if (rows.length && txTableBody) {
                    // Remove empty placeholder if present
                    const empty = txTableBody.querySelector('.ledger-empty-row');
                    if (empty) empty.remove();
                    txTableBody.insertAdjacentHTML('beforeend', rows.map(renderTxRow).join(''));
                }
                listState.total = data.total || 0;
                listState.offset = (data.offset || 0) + rows.length;
                listState.limit = data.limit || limit;
                listState.has_more = !!data.has_more;
                if (data.sort) listState.sort = data.sort;
                if (data.sort_dir) listState.sort_dir = data.sort_dir;
                if (data.filters) listState.filters = normalizeFilters(data.filters);
                updateListFooter();
            })
            .catch(err => {
                console.error(err);
                showToast('Failed to load transactions.', 'danger');
            })
            .finally(() => {
                setListLoading(false);
                updateListFooter();
            });
    }

    function reloadTransactionList() {
        listState.offset = 0;
        listState.has_more = true;
        // Force filter value panels to reload next open (other filters may change available values)
        document.querySelectorAll('#ledgerTxTable .ledger-f-values').forEach(el => {
            el.dataset.loaded = '0';
        });
        return fetchTransactionList({ reset: true });
    }

    function closeFilterDropdown(th) {
        const toggle = th && th.querySelector('.ledger-filter-toggle');
        if (toggle && typeof bootstrap !== 'undefined') {
            const dd = bootstrap.Dropdown.getInstance(toggle);
            if (dd) dd.hide();
        }
    }

    function applyColumnFilterFromMenu(th) {
        if (!th) return;
        const col = th.dataset.col;
        const allVals = getAllLeafValues(th);
        const checked = getCheckedLeafValues(th);
        // Excel: Select All (all checked) = no column filter; none checked = no matches
        if (allVals.length && checked.length === allVals.length) {
            setColumnFilterValues(col, []);
        } else if (checked.length === 0) {
            setColumnFilterValues(col, ['__NONE__']);
        } else {
            setColumnFilterValues(col, checked);
        }
        reloadTransactionList();
        closeFilterDropdown(th);
    }

    function clearColumnFilterFromMenu(th) {
        if (!th) return;
        const col = th.dataset.col;
        setColumnFilterValues(col, []);
        // Reset UI: re-check all visible, clear search
        const search = th.querySelector('.ledger-f-search');
        if (search) search.value = '';
        const box = th.querySelector('.ledger-f-values');
        if (box) box.dataset.loaded = '0';
        setAllVisibleChecked(th, true);
        const selectAll = th.querySelector('.ledger-f-select-all');
        if (selectAll) {
            selectAll.checked = true;
            selectAll.indeterminate = false;
        }
        reloadTransactionList();
        closeFilterDropdown(th);
    }

    function clearAllFilters() {
        listState.filters = emptyMultiFilters();
        document.querySelectorAll('#ledgerTxTable .ledger-f-search').forEach(el => { el.value = ''; });
        document.querySelectorAll('#ledgerTxTable .ledger-f-values').forEach(el => {
            el.dataset.loaded = '0';
            el.innerHTML = '<div class="text-muted small p-2 ledger-f-placeholder">Open to load values…</div>';
        });
        document.querySelectorAll('#ledgerTxTable .ledger-f-select-all').forEach(el => {
            el.checked = true;
            el.indeterminate = false;
        });
        reloadTransactionList();
    }

    function wireLedgerFiltersAndSort() {
        const table = document.getElementById('ledgerTxTable');
        if (!table || table.dataset.filtersWired === '1') return;
        table.dataset.filtersWired = '1';

        table.addEventListener('click', function(e) {
            const applyBtn = e.target.closest('.ledger-f-apply');
            if (applyBtn) {
                e.preventDefault();
                e.stopPropagation();
                applyColumnFilterFromMenu(applyBtn.closest('th'));
                return;
            }
            const clearBtn = e.target.closest('.ledger-f-clear');
            if (clearBtn) {
                e.preventDefault();
                e.stopPropagation();
                clearColumnFilterFromMenu(clearBtn.closest('th'));
                return;
            }
            // Keep checkbox clicks from toggling <details> open/close
            if (e.target.classList && e.target.classList.contains('ledger-f-cb')) {
                e.stopPropagation();
            }
            const sortBtn = e.target.closest('.ledger-sort-btn');
            if (sortBtn) {
                e.preventDefault();
                const col = sortBtn.dataset.sort;
                if (!col) return;
                if (listState.sort === col || (col === 'amount' && ['amount','debit','credit'].includes(listState.sort)) || (col === 'reference' && ['reference','ref'].includes(listState.sort))) {
                    listState.sort_dir = listState.sort_dir === 'asc' ? 'desc' : 'asc';
                } else {
                    listState.sort = col;
                    listState.sort_dir = (col === 'date' || col === 'amount') ? 'desc' : 'asc';
                }
                reloadTransactionList();
            }
        });

        table.addEventListener('change', function(e) {
            if (e.target.classList && e.target.classList.contains('ledger-f-select-all')) {
                const th = e.target.closest('th');
                setAllVisibleChecked(th, e.target.checked);
                e.target.indeterminate = false;
                return;
            }
            if (e.target.classList && e.target.classList.contains('ledger-f-day-cb')) {
                syncDateParentCheckboxes(e.target.closest('th'));
                return;
            }
            if (e.target.classList && e.target.classList.contains('ledger-f-cb')) {
                const th = e.target.closest('th');
                if (e.target.classList.contains('ledger-f-year-cb') || e.target.classList.contains('ledger-f-month-cb')) {
                    const det = e.target.closest('details');
                    if (det) {
                        det.querySelectorAll('.ledger-f-day-cb').forEach(cb => { cb.checked = e.target.checked; });
                        det.querySelectorAll('.ledger-f-month-cb, .ledger-f-year-cb').forEach(cb => {
                            if (cb !== e.target && det.contains(cb)) {
                                cb.checked = e.target.checked;
                                cb.indeterminate = false;
                            }
                        });
                    }
                    e.target.indeterminate = false;
                    syncDateParentCheckboxes(th);
                } else {
                    syncSelectAllCheckbox(th);
                }
            }
        });

        table.addEventListener('input', function(e) {
            if (e.target.classList && e.target.classList.contains('ledger-f-search')) {
                applySearchFilterToMenu(e.target.closest('th'), e.target.value);
            }
        });

        // Enter in search applies
        table.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter') return;
            const th = e.target.closest('th.ledger-th-filter');
            if (!th) return;
            if (e.target.matches('input.ledger-f-search')) {
                e.preventDefault();
                applyColumnFilterFromMenu(th);
            }
        });

        // Load unique values when a filter dropdown opens (respects other active filters)
        table.querySelectorAll('.ledger-filter-toggle').forEach(toggle => {
            toggle.addEventListener('show.bs.dropdown', function() {
                const th = toggle.closest('th');
                if (!th) return;
                loadFilterValuesForMenu(th);
            });
        });

        if (clearAllFiltersBtn) {
            clearAllFiltersBtn.addEventListener('click', () => clearAllFilters());
        }
        // Infinite scroll
        if (ledgerTableScroll) {
            ledgerTableScroll.addEventListener('scroll', function() {
                if (listState.loading || !listState.has_more) return;
                const el = ledgerTableScroll;
                if (el.scrollTop + el.clientHeight >= el.scrollHeight - 80) {
                    fetchTransactionList({ reset: false });
                }
            });
        }
        updateListFooter();
    }

    function getLineAmountAndType(row) {
        const debIn = row.querySelector('.line-debit-amt');
        const credIn = row.querySelector('.line-credit-amt');
        const deb = parseFloat(debIn?.value || '0') || 0;
        const cred = parseFloat(credIn?.value || '0') || 0;
        // Debit/Credit is chosen by which column has the amount (not locked to account type)
        if (cred > 0 && deb <= 0) return { amount: cred, type: 'credit' };
        if (deb > 0 && cred <= 0) return { amount: deb, type: 'debit' };
        if (deb > 0 && cred > 0) {
            // Both filled: prefer the last-edited column if known, else debit
            const prefer = row.dataset.lineType || 'debit';
            if (prefer === 'credit') return { amount: cred, type: 'credit' };
            return { amount: deb, type: 'debit' };
        }
        return { amount: 0, type: '' };
    }

    /** Original budget id for the loaded transaction (string, or '' for none). */
    function originalBudgetId() {
        if (!currentViewData || currentViewData.budget_id == null || currentViewData.budget_id === '') {
            return '';
        }
        return String(currentViewData.budget_id);
    }

    function currentBudgetId() {
        return budgetSelect && budgetSelect.value ? String(budgetSelect.value) : '';
    }

    /** True when budget differs from the loaded transaction (budget-only save candidate). */
    function isBudgetSelectionChanged() {
        return originalBudgetId() !== currentBudgetId();
    }

    /**
     * Enable/disable Save.
     * Normal edit: requires balanced lines (unchanged).
     * Budget-only (cleared/reconciled): enable when budget selection changed.
     */
    function updateSaveButtonState(balanced) {
        if (!saveBtn) return;
        if (budgetOnlyEditMode) {
            saveBtn.disabled = !isBudgetSelectionChanged();
            return;
        }
        if (typeof balanced === 'boolean') {
            saveBtn.disabled = !balanced;
        }
    }

    function recalcTotals() {
        let deb = 0, cred = 0;
        linesBody.querySelectorAll('tr').forEach(row => {
            const { amount, type } = getLineAmountAndType(row);
            if (!amount) return;
            if (type === 'debit') deb += amount;
            else if (type === 'credit') cred += amount;
        });
        const diff = deb - cred;

        document.getElementById('totalDebits').textContent = deb.toFixed(2);
        document.getElementById('totalCredits').textContent = cred.toFixed(2);
        const dEl = document.getElementById('diff');
        dEl.textContent = diff.toFixed(2);
        dEl.className = (Math.abs(diff) < 0.005) ? 'fw-bold text-success' : 'fw-bold text-danger';

        const lineCount = linesBody.querySelectorAll('tr').length;
        const balanced = Math.abs(diff) < 0.005 && lineCount >= 2;
        updateSaveButtonState(balanced);

        const status = document.getElementById('balanceStatus');
        status.textContent = balanced ? '✓ Balanced' : (lineCount ? '⚠ Not balanced' : 'Add at least 2 lines');
        status.className = balanced ? 'text-success' : 'text-danger';
    }

    /**
     * Pull Natural / Functional class labels from the selected account option
     * (same pattern as budget page — read-only, not user-editable).
     */
    function syncLineCategoryLabels(row) {
        const sel = row.querySelector('.line-account');
        const natEl = row.querySelector('.line-natural-label');
        const funEl = row.querySelector('.line-functional-label');
        if (!natEl || !funEl) return;
        const opt = sel && sel.selectedOptions ? sel.selectedOptions[0] : null;
        const hasAcct = !!(opt && opt.value);
        const nat = hasAcct ? (opt.dataset.naturalName || '—') : '—';
        const fun = hasAcct ? (opt.dataset.functionalName || '—') : '—';
        natEl.textContent = nat;
        natEl.title = nat;
        funEl.textContent = fun;
        funEl.title = fun;
        row.dataset.naturalId = hasAcct ? (opt.dataset.naturalId || '') : '';
        row.dataset.functionalId = hasAcct ? (opt.dataset.functionalId || '') : '';
    }

    function attachLineListeners(row) {
        if (row.dataset.attached === '1') return;
        row.dataset.attached = '1';
        const accSel = row.querySelector('.line-account');
        const debIn = row.querySelector('.line-debit-amt');
        const credIn = row.querySelector('.line-credit-amt');
        const remBtn = row.querySelector('.remove-line');

        // Account selection drives Natural/Functional labels; user may debit or credit any account
        if (accSel) {
            accSel.addEventListener('change', () => {
                syncLineCategoryLabels(row);
                recalcTotals();
            });
        }
        if (debIn) debIn.addEventListener('input', () => {
            if (credIn && parseFloat(debIn.value || '0') > 0) credIn.value = '';
            row.dataset.lineType = parseFloat(debIn.value || '0') > 0 ? 'debit' : (row.dataset.lineType || '');
            recalcTotals();
        });
        if (credIn) credIn.addEventListener('input', () => {
            if (debIn && parseFloat(credIn.value || '0') > 0) debIn.value = '';
            row.dataset.lineType = parseFloat(credIn.value || '0') > 0 ? 'credit' : (row.dataset.lineType || '');
            recalcTotals();
        });
        if (remBtn) remBtn.addEventListener('click', () => {
            row.remove();
            recalcTotals();
        });

        syncLineCategoryLabels(row);
        recalcTotals();
    }

    function createLineRow(prefill = null, readonly = false) {
        const ro = readonly ? ' disabled' : '';
        const remStyle = readonly ? ' style="display:none"' : '';
        const row = document.createElement('tr');
        // Debit / Credit columns: user enters amount in either column for any account.
        // Natural / Functional are read-only labels pulled from the account (budget-page pattern).
        row.innerHTML = `
            <td><select class="form-select form-select-sm line-account" required${ro}>${accountOpts}</select></td>
            <td><select class="form-select form-select-sm line-fund"${ro}>${fundOpts}</select></td>
            <td><span class="line-cat-label line-natural-label" title="">—</span></td>
            <td><span class="line-cat-label line-functional-label" title="">—</span></td>
            <td>
                <input type="number" step="0.01" min="0.01" class="form-control form-control-sm line-amount line-debit-amt text-end font-monospace" placeholder=""${ro}>
            </td>
            <td>
                <input type="number" step="0.01" min="0.01" class="form-control form-control-sm line-amount line-credit-amt text-end font-monospace" placeholder=""${ro}>
            </td>
            <td><button type="button" class="btn btn-sm btn-outline-danger remove-line"${remStyle}>×</button></td>
        `;
        row.dataset.lineType = '';
        row.dataset.naturalId = '';
        row.dataset.functionalId = '';
        if (prefill) {
            const acc = row.querySelector('.line-account');
            if (prefill.account_id) acc.value = prefill.account_id;
            const fund = row.querySelector('.line-fund');
            if (prefill.fund_id !== undefined && prefill.fund_id !== '') fund.value = prefill.fund_id;
            // Place amount in debit or credit column from saved line type (not account normal_balance)
            const debIn = row.querySelector('.line-debit-amt');
            const credIn = row.querySelector('.line-credit-amt');
            if (prefill.amount !== undefined && prefill.amount !== '') {
                const t = String(prefill.type || '').toLowerCase();
                if (t === 'credit') {
                    credIn.value = prefill.amount;
                    row.dataset.lineType = 'credit';
                } else {
                    debIn.value = prefill.amount;
                    row.dataset.lineType = 'debit';
                }
            }
        }
        syncLineCategoryLabels(row);
        if (readonly) {
            row.querySelectorAll('select, input').forEach(el => el.classList.add('bg-body-secondary'));
        }
        return row;
    }

    function addLine() {
        const row = createLineRow();
        linesBody.appendChild(row);
        attachLineListeners(row);
        recalcTotals();
    }

    function isTxClearedOrReconciled(data) {
        if (!data) return false;
        if (data.is_editable === false || data.is_editable === 0 || data.is_editable === '0') return true;
        const st = String(data.status || '').toLowerCase();
        return st === 'cleared' || st === 'reconciled';
    }

    function setBudgetStatusWarning(show) {
        if (!budgetStatusWarn) return;
        if (show) {
            budgetStatusWarn.textContent = BUDGET_STATUS_WARN_MSG;
            budgetStatusWarn.classList.remove('d-none');
        } else {
            budgetStatusWarn.textContent = '';
            budgetStatusWarn.classList.add('d-none');
        }
    }

    /**
     * Lock/unlock main header fields.
     * @param {boolean} readonly
     * @param {{budgetEnabled?: boolean}} [opts] When readonly and budgetEnabled, keep budget selectable (cleared/reconciled budget-only edit).
     */
    function setMainFieldsReadOnly(readonly, opts = {}) {
        const budgetEnabled = !!opts.budgetEnabled;
        ['transaction_date', 'reference_number', 'pay_to', 'check_number', 'description'].forEach(fid => {
            const el = document.getElementById(fid);
            if (!el) return;
            el.readOnly = readonly;
            if (readonly) el.classList.add('bg-body-secondary');
            else el.classList.remove('bg-body-secondary');
        });
        if (budgetSelect) {
            const lockBudget = readonly && !budgetEnabled;
            budgetSelect.disabled = lockBudget;
            if (lockBudget) budgetSelect.classList.add('bg-body-secondary');
            else budgetSelect.classList.remove('bg-body-secondary');
            // Refresh tooltip so disabled state doesn't leave a stuck popover
            syncBudgetSelectTooltip();
        }
        if (!readonly || !budgetEnabled) {
            setBudgetStatusWarning(false);
        }
    }

    /** Enter budget-only edit UI for a cleared/reconciled transaction (lines stay locked). */
    function applyBudgetOnlyEditMode(data) {
        budgetOnlyEditMode = true;
        budgetAutoMode = false;
        setMainFieldsReadOnly(true, { budgetEnabled: true });
        setBudgetStatusWarning(true);
        formTitle.textContent = data && data.reference_number
            ? ('Edit budget — ' + data.reference_number + ' (#' + data.id + ')')
            : ('Edit budget — Transaction #' + (data && data.id ? data.id : (txIdField.value || '')));
        updateModeBadge('edit');
        if (addLineBtn) addLineBtn.style.display = 'none';
        if (resetLinesBtn) resetLinesBtn.style.display = 'none';
        if (saveBtn) saveBtn.style.display = '';
        if (cancelBtn2) cancelBtn2.style.display = '';
        // Lines remain disabled (view-style)
        linesBody.querySelectorAll('tr').forEach(row => {
            row.querySelectorAll('select, input').forEach(el => {
                el.disabled = true;
                el.classList.add('bg-body-secondary');
            });
            const rem = row.querySelector('.remove-line');
            if (rem) rem.style.display = 'none';
        });
        setDocUploadVisible(false);
        renderDocumentsList((data && data.documents) || (currentViewData && currentViewData.documents) || [], false);
        updateBudgetPeriodWarning();
        syncBudgetSelectTooltip();
        // Save stays off until the user actually changes budget
        updateSaveButtonState();
        recalcTotals();
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }

    function fmtMoney(n) {
        return '$' + (parseFloat(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderContributionSummary(data) {
        if (!data || data.type !== 'contribution') return '';
        const totals = data.totals || {};
        let checks = '';
        if ((data.checks || []).length) {
            checks = '<ul class="mb-1">' + data.checks.map(c =>
                `<li>${escHtml(c.payor || '')} #${escHtml(c.check_number || '')} — ${fmtMoney(c.amount)}</li>`
            ).join('') + '</ul>';
        } else {
            checks = '<span class="text-muted">No checks</span>';
        }
        return `<div class="card card-body py-2 bg-body-tertiary border-0">
            <h6 class="small text-muted mb-1">Contribution Details</h6>
            <div class="row g-2">
                <div class="col-md-3"><span class="text-muted">Service date:</span> ${escHtml(data.service_date || '')}</div>
                <div class="col-md-3"><span class="text-muted">Cash:</span> ${fmtMoney(totals.cash)}</div>
                <div class="col-md-3"><span class="text-muted">Checks:</span> ${fmtMoney(totals.checks)}</div>
                <div class="col-md-3"><span class="text-muted">Grand:</span> <strong>${fmtMoney(totals.grand)}</strong></div>
            </div>
            <div class="mt-1 small">${checks}</div>
        </div>`;
    }

    function renderMetaSection(data) {
        const section = document.getElementById('txMetaSection');
        if (!section) return;
        if (!data || !data.id) {
            section.classList.add('d-none');
            return;
        }
        section.classList.remove('d-none');

        const badges = document.getElementById('txMetaBadges');
        let validated = '';
        if (data.validated_by) {
            validated = `<div class="col-auto"><span class="badge bg-success">Validated by ${escHtml(data.validated_by.display_name)}</span></div>`;
        }
        if (data.created_by) {
            validated += `<div class="col-auto"><span class="badge bg-body-secondary text-body border">Created by ${escHtml(data.created_by.display_name)}</span></div>`;
        }
        const refBadge = data.reference_number
            ? `<div class="col-auto"><span class="badge bg-dark font-monospace" title="Reference #">Ref ${escHtml(data.reference_number)}</span></div>`
            : '';
        const statusLabel = data.status || 'pending';
        badges.innerHTML = `
            ${refBadge}
            <div class="col-auto"><span class="badge bg-secondary">${escHtml(statusLabel)}</span></div>
            ${validated}`;

        const contribEl = document.getElementById('txContributionData');
        const contribHtml = renderContributionSummary(data.transaction_data);
        if (contribHtml) {
            contribEl.innerHTML = contribHtml;
            contribEl.classList.remove('d-none');
        } else {
            contribEl.classList.add('d-none');
            contribEl.innerHTML = '';
        }

        const inEdit = isTxEditMode();
        const canEditDocs = !!(data.is_editable && inEdit);
        renderDocumentsList(data.documents || [], canEditDocs);

        // Do not toggle upload visibility here — callers control edit-mode UI.

        renderAuditTrail(data.events || []);
    }

    function formatAuditEventHtml(e) {
        const when = escHtml(e.created_at || '');
        const who = escHtml(e.username || 'system');
        const summary = escHtml(humanizeAuditSummary(e));
        const details = e.details && typeof e.details === 'object' ? e.details : {};
        const changes = Array.isArray(details.changes) ? details.changes : [];
        let extra = '';
        if (changes.length > 1 || (changes.length === 1 && changes[0] !== e.summary)) {
            extra = '<ul class="mb-0 mt-1 ps-3 text-muted">'
                + changes.map(c => '<li>' + escHtml(c) + '</li>').join('')
                + '</ul>';
        }
        return `<li class="mb-2 border-bottom pb-1">
            <div><span class="text-muted">${when}</span> — <strong>${who}</strong></div>
            <div>${summary}</div>
            ${extra}
        </li>`;
    }

    function humanizeAuditSummary(e) {
        const type = e.event_type || '';
        const details = e.details && typeof e.details === 'object' ? e.details : {};
        const summary = (e.summary || '').trim();

        // Prefer already-improved summaries; rewrite known generic ones
        if (type === 'document_uploaded') {
            const name = details.original_filename || (summary.match(/:\s*(.+)$/) || [])[1];
            if (name) return 'Attachment "' + name + '" added.';
        }
        if (type === 'document_deleted') {
            const name = details.original_filename || (summary.match(/:\s*(.+)$/) || [])[1];
            if (name) return 'Attachment "' + name + '" removed.';
        }
        if (type === 'validated') {
            if (details.validated_by_name) {
                return 'Transaction validated by ' + details.validated_by_name + '.';
            }
            if (summary && summary !== 'Transaction validated and finalized.') return summary;
        }
        if (type === 'updated' && (summary === 'Manual transaction updated.' || summary === '')) {
            if (Array.isArray(details.changes) && details.changes.length) {
                return details.changes.length === 1
                    ? details.changes[0]
                    : ('Transaction updated (' + details.changes.length + ' changes).');
            }
            if (details.debits != null && details.credits != null) {
                return 'Transaction updated (balanced at $'
                    + Number(details.debits).toFixed(2) + ').';
            }
        }
        if (type === 'created' && (summary === 'Manual transaction created.' || summary === '')) {
            if (details.debits != null) {
                return 'Transaction created totaling $' + Number(details.debits).toFixed(2) + '.';
            }
        }
        return summary || type || 'Event recorded.';
    }

    function renderAuditTrail(events) {
        const eventsEl = document.getElementById('txEventsList');
        const toggle = document.getElementById('txAuditToggle');
        if (!eventsEl) return;
        const list = events || [];
        if (toggle) {
            toggle.textContent = list.length
                ? ('Audit Trail (' + list.length + ' event' + (list.length === 1 ? '' : 's') + ')')
                : 'Audit Trail';
        }
        eventsEl.innerHTML = list.length
            ? list.map(formatAuditEventHtml).join('')
            : '<li class="text-muted">No audit events recorded.</li>';
    }

    function isTxEditMode() {
        if (txIdField.value === '') return false;
        // Full edit (date unlocked) or budget-only edit on cleared/reconciled
        return !document.getElementById('transaction_date').readOnly || budgetOnlyEditMode;
    }

    function parseJsonResponse(r) {
        return r.text().then(text => {
            const trimmed = (text || '').trim();
            if (!trimmed) throw new Error('Empty response');
            try {
                return JSON.parse(trimmed);
            } catch (e) {
                // Tolerate accidental PHP notices before JSON
                const start = trimmed.indexOf('{');
                const end = trimmed.lastIndexOf('}');
                if (start >= 0 && end > start) {
                    return JSON.parse(trimmed.slice(start, end + 1));
                }
                throw e;
            }
        });
    }

    function isApiSuccess(res) {
        if (!res || typeof res !== 'object') return false;
        if (res.error) return false;
        if (res.success === false || res.success === 0 || res.success === '0') return false;
        if (res.success === true || res.success === 1 || res.success === '1') return true;
        // Fallback: presence of new document id after upload
        return !!(res.id && !res.error);
    }

    function docPreviewKind(doc) {
        const mime = String(doc.mime_type || '').toLowerCase();
        const name = String(doc.original_filename || '');
        const ext = name.includes('.') ? name.split('.').pop().toLowerCase() : '';
        if (mime.startsWith('image/') || ['jpg','jpeg','png','gif','webp'].includes(ext)) return 'image';
        if (mime === 'application/pdf' || ext === 'pdf') return 'pdf';
        if (mime.startsWith('text/') || ['txt','csv','log','md'].includes(ext)) return 'text';
        return 'other';
    }

    /** @type {Object.<string,{id:number,name:string}>} */
    let pendingDocDeletes = {};

    const PENDING_DOC_STORAGE_KEY = 'temperLedgerPendingDocDeletes';

    function clearPendingDocDeletes(clearStorage = true) {
        pendingDocDeletes = {};
        if (clearStorage) {
            try { sessionStorage.removeItem(PENDING_DOC_STORAGE_KEY); } catch (e) { /* ignore */ }
        }
    }

    function getPendingDocDeleteList() {
        return Object.keys(pendingDocDeletes).map(k => pendingDocDeletes[k]).filter(Boolean);
    }

    function isDocQueuedForDelete(docId) {
        return !!pendingDocDeletes[String(docId)];
    }

    function queueDocForDelete(docId, name) {
        pendingDocDeletes[String(docId)] = { id: parseInt(docId, 10), name: name || ('#' + docId) };
        persistPendingDocDeletes();
    }

    function unqueueDocForDelete(docId) {
        delete pendingDocDeletes[String(docId)];
        persistPendingDocDeletes();
    }

    function persistPendingDocDeletes() {
        try {
            const txId = txIdField ? txIdField.value : '';
            const items = getPendingDocDeleteList();
            if (!txId || !items.length) {
                sessionStorage.removeItem(PENDING_DOC_STORAGE_KEY);
                return;
            }
            sessionStorage.setItem(PENDING_DOC_STORAGE_KEY, JSON.stringify({ txId: String(txId), items }));
        } catch (e) { /* ignore */ }
    }

    function restorePendingDocDeletes(txId) {
        try {
            const raw = sessionStorage.getItem(PENDING_DOC_STORAGE_KEY);
            if (!raw) return;
            const data = JSON.parse(raw);
            if (!data || String(data.txId) !== String(txId) || !Array.isArray(data.items)) return;
            pendingDocDeletes = {};
            data.items.forEach(item => {
                if (item && item.id) {
                    pendingDocDeletes[String(item.id)] = {
                        id: parseInt(item.id, 10),
                        name: item.name || ('#' + item.id)
                    };
                }
            });
        } catch (e) { /* ignore */ }
    }

    function prunePendingDocDeletes(docs) {
        const alive = new Set((docs || []).map(d => String(d.id)));
        Object.keys(pendingDocDeletes).forEach(k => {
            if (!alive.has(k)) delete pendingDocDeletes[k];
        });
        persistPendingDocDeletes();
    }

    function renderDocumentsList(docs, showDelete) {
        const docsEl = document.getElementById('txDocumentsList');
        if (!docsEl) return;
        prunePendingDocDeletes(docs);
        if (!docs || !docs.length) {
            docsEl.innerHTML = '<li class="text-muted">No documents attached.</li>';
            return;
        }
        docsEl.innerHTML = docs.map(d => {
            const name = escHtml(d.original_filename || 'document');
            const when = escHtml(d.created_at || '');
            const id = parseInt(d.id, 10) || 0;
            const queued = isDocQueuedForDelete(id);
            const delBtn = showDelete
                ? `<button type="button"
                        class="btn btn-sm ${queued ? 'btn-secondary' : 'btn-danger'} fw-bold px-2 py-0 tx-doc-delete"
                        style="font-size:1.15rem;line-height:1.2;min-width:2rem"
                        data-doc-id="${id}"
                        data-doc-name="${name}"
                        title="${queued ? 'Undo delete queue' : 'Queue for deletion'}"
                        aria-label="${queued ? 'Undo delete queue' : 'Queue for deletion'}">&times;</button>`
                : '';
            const nameClass = queued
                ? 'tx-doc-preview text-decoration-line-through text-muted'
                : 'tx-doc-preview text-decoration-none';
            const badge = queued
                ? '<span class="badge bg-warning text-dark">queued for deletion</span>'
                : '';
            return `<li class="d-flex align-items-center flex-wrap gap-2 mb-1 ${queued ? 'opacity-75' : ''}" data-doc-id="${id}">
                ${delBtn}
                <a href="#" class="${nameClass}" data-doc-id="${id}" data-doc-name="${name}">${name}</a>
                <span class="text-muted">(${when})</span>
                ${badge}
            </li>`;
        }).join('');
    }

    function refreshDocumentsFromServer(txId, keepEditUi) {
        const id = parseInt(txId, 10);
        if (!id) return Promise.resolve();
        return fetch('pages/ledger.php?get_transaction=' + id)
            .then(parseJsonResponse)
            .then(data => {
                if (data.error) {
                    showToast(data.error, 'danger');
                    return data;
                }
                // Preserve edit-mode field values; only refresh meta/docs/events
                if (currentViewData) {
                    currentViewData.documents = data.documents || [];
                    currentViewData.events = data.events || [];
                    currentViewData.is_editable = data.is_editable;
                } else {
                    currentViewData = data;
                }
                const canEditDocs = !!(data.is_editable && (keepEditUi || isTxEditMode()));
                renderDocumentsList(data.documents || [], canEditDocs);
                updateRowAttachmentIndicator(id, (data.documents || []).length);
                const eventsEl = document.getElementById('txEventsList');
                if (eventsEl) {
                    renderAuditTrail(data.events || []);
                }
                if (keepEditUi && data.is_editable) {
                    setDocUploadVisible(true);
                }
                return data;
            });
    }

    function openDocumentPreview(docId, fallbackName) {
        const modalEl = document.getElementById('txDocPreviewModal');
        const titleEl = document.getElementById('txDocPreviewTitle');
        const bodyEl = document.getElementById('txDocPreviewBody');
        const dlEl = document.getElementById('txDocPreviewDownload');
        if (!modalEl || !bodyEl) return;

        titleEl.textContent = fallbackName || 'Document';
        bodyEl.innerHTML = '<div class="text-center text-muted py-5">Loading…</div>';
        dlEl.href = 'pages/ledger.php?download_document=' + docId;
        dlEl.classList.remove('d-none');

        showLedgerModal(modalEl);

        fetch('pages/ledger.php?document_meta=' + docId)
            .then(parseJsonResponse)
            .then(meta => {
                if (!isApiSuccess(meta)) {
                    bodyEl.innerHTML = '<div class="alert alert-danger m-3">' + escHtml(meta.error || 'Unable to load document.') + '</div>';
                    return;
                }
                titleEl.textContent = meta.original_filename || fallbackName || 'Document';
                dlEl.href = meta.download_url || ('pages/ledger.php?download_document=' + docId);
                const kind = meta.preview_kind || 'other';
                const url = meta.preview_url || ('pages/ledger.php?preview_document=' + docId);

                if (kind === 'image') {
                    bodyEl.innerHTML = `<div class="text-center p-2"><img src="${url}" alt="${escHtml(meta.original_filename || '')}" class="img-fluid" style="max-height:70vh"></div>`;
                } else if (kind === 'pdf') {
                    bodyEl.innerHTML = `<iframe src="${url}" title="PDF preview" class="w-100 border-0" style="height:70vh"></iframe>`;
                } else if (kind === 'text') {
                    fetch(url).then(r => r.text()).then(txt => {
                        bodyEl.innerHTML = `<pre class="small bg-body-tertiary border rounded p-3 m-0" style="max-height:70vh;overflow:auto;white-space:pre-wrap">${escHtml(txt)}</pre>`;
                    }).catch(() => {
                        bodyEl.innerHTML = '<div class="alert alert-warning m-3">Could not load text preview. Use Download instead.</div>';
                    });
                } else {
                    bodyEl.innerHTML = `<div class="p-4 text-center">
                        <p class="mb-2">Preview is not available for this file type (<code>${escHtml(meta.mime_type || 'unknown')}</code>).</p>
                        <a class="btn btn-sm btn-primary" href="${dlEl.href}" target="_blank" rel="noopener">Download file</a>
                    </div>`;
                }
            })
            .catch(() => {
                bodyEl.innerHTML = '<div class="alert alert-danger m-3">Failed to load document preview.</div>';
            });
    }

    // ── Ledger list attachment portfolio ─────────────────────────────────
    const PDFJS_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174';
    let attachPortfolio = {
        txId: 0,
        docs: [],
        selectedId: 0,
        pdfDoc: null,
        pdfPage: 1,
        pdfPages: 1,
        pdfUrl: '',
        zoom: 1,
        paneW: 0,
        paneH: 0,
        renderToken: 0
    };
    let attachPortfolioModalEl = document.getElementById('txAttachPortfolioModal');
    if (attachPortfolioModalEl && typeof window.mountModalOnBody === 'function') {
        attachPortfolioModalEl = window.mountModalOnBody(attachPortfolioModalEl);
    }

    function loadPdfJsLib() {
        if (window.pdfjsLib) {
            if (window.pdfjsLib.GlobalWorkerOptions && !window.pdfjsLib.GlobalWorkerOptions.workerSrc) {
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_CDN + '/pdf.worker.min.js';
            }
            return Promise.resolve(window.pdfjsLib);
        }
        if (window.__temperPdfJsLoading) {
            return window.__temperPdfJsLoading;
        }
        window.__temperPdfJsLoading = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = PDFJS_CDN + '/pdf.min.js';
            script.async = true;
            script.onload = function() {
                if (!window.pdfjsLib) {
                    reject(new Error('PDF.js failed to initialize'));
                    return;
                }
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_CDN + '/pdf.worker.min.js';
                resolve(window.pdfjsLib);
            };
            script.onerror = function() {
                reject(new Error('Failed to load PDF.js'));
            };
            document.head.appendChild(script);
        });
        return window.__temperPdfJsLoading;
    }

    function attachFileTypeMeta(doc) {
        const name = String((doc && doc.original_filename) || '');
        const ext = name.includes('.') ? name.split('.').pop().toLowerCase() : '';
        const kind = (doc && doc.preview_kind) || 'other';
        if (kind === 'pdf' || ext === 'pdf') {
            return { label: 'PDF', icon: 'bi-filetype-pdf', cls: 'is-pdf' };
        }
        if (ext === 'png') return { label: 'PNG', icon: 'bi-filetype-png', cls: 'is-img' };
        if (ext === 'jpg' || ext === 'jpeg') return { label: 'JPG', icon: 'bi-filetype-jpg', cls: 'is-img' };
        if (ext === 'gif') return { label: 'GIF', icon: 'bi-filetype-gif', cls: 'is-img' };
        if (ext === 'webp') return { label: 'WEBP', icon: 'bi-file-earmark-image', cls: 'is-img' };
        if (kind === 'image') {
            return { label: (ext || 'IMG').toUpperCase(), icon: 'bi-file-earmark-image', cls: 'is-img' };
        }
        if (ext === 'txt' || ext === 'csv' || ext === 'log' || ext === 'md' || kind === 'text') {
            return { label: 'TXT', icon: 'bi-filetype-txt', cls: 'is-txt' };
        }
        if (ext === 'doc') return { label: 'DOC', icon: 'bi-filetype-doc', cls: 'is-doc' };
        if (ext === 'docx') return { label: 'DOCX', icon: 'bi-filetype-docx', cls: 'is-doc' };
        return { label: (ext || 'FILE').toUpperCase(), icon: 'bi-file-earmark', cls: 'is-other' };
    }

    function formatAttachFileSize(bytes) {
        const n = parseInt(bytes, 10) || 0;
        if (n <= 0) return '0 bytes';
        if (n < 1024) return n + ' bytes';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function setAttachPagePanelVisible(on) {
        const panel = document.getElementById('txAttachPagePanel');
        if (panel) panel.classList.toggle('d-none', !on);
    }

    function setAttachZoomBarVisible(on) {
        const bar = document.getElementById('txAttachZoomBar');
        if (bar) bar.classList.toggle('d-none', !on);
    }

    function updateAttachZoomUi() {
        const label = document.getElementById('txAttachZoomLabel');
        const out = document.getElementById('txAttachZoomOut');
        const z = attachPortfolio.zoom || 1;
        if (label) label.textContent = z <= 1.001 ? 'Fit' : (Math.round(z * 100) + '%');
        if (out) out.disabled = z <= 1.001;
        const pane = document.getElementById('txAttachPortfolioPane');
        if (pane) pane.classList.toggle('is-zoomed', z > 1.001);
    }

    function destroyAttachPdf() {
        attachPortfolio.renderToken = (attachPortfolio.renderToken || 0) + 1;
        if (attachPortfolio.pdfDoc && typeof attachPortfolio.pdfDoc.destroy === 'function') {
            try { attachPortfolio.pdfDoc.destroy(); } catch (e) { /* ignore */ }
        }
        attachPortfolio.pdfDoc = null;
        attachPortfolio.pdfPage = 1;
        attachPortfolio.pdfPages = 1;
        attachPortfolio.pdfUrl = '';
        const pageList = document.getElementById('txAttachPageList');
        if (pageList) pageList.innerHTML = '';
        setAttachPagePanelVisible(false);
    }

    function updateAttachPdfNav() {
        const label = document.getElementById('txAttachPdfPageLabel');
        const prev = document.getElementById('txAttachPdfPrev');
        const next = document.getElementById('txAttachPdfNext');
        const page = attachPortfolio.pdfPage || 1;
        const total = attachPortfolio.pdfPages || 1;
        if (label) label.textContent = page + ' / ' + total;
        if (prev) prev.disabled = page <= 1;
        if (next) next.disabled = page >= total;
        document.querySelectorAll('#txAttachPageList .ledger-portfolio-page').forEach(function(btn) {
            const active = parseInt(btn.dataset.page, 10) === page;
            btn.classList.toggle('active', active);
            if (active && typeof btn.scrollIntoView === 'function') {
                btn.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    function attachPaneSize() {
        const pane = document.getElementById('txAttachPortfolioPane');
        if (!pane) return { w: 0, h: 0 };
        return { w: pane.clientWidth || 0, h: pane.clientHeight || 0 };
    }

    function renderAttachPdfPage(pageNum) {
        const pane = document.getElementById('txAttachPortfolioPane');
        const pdf = attachPortfolio.pdfDoc;
        if (!pdf || !pane) return Promise.resolve();
        const total = pdf.numPages || 1;
        const page = Math.max(1, Math.min(total, parseInt(pageNum, 10) || 1));
        attachPortfolio.pdfPage = page;
        attachPortfolio.pdfPages = total;
        updateAttachPdfNav();
        updateAttachZoomUi();
        const token = attachPortfolio.renderToken;
        return pdf.getPage(page).then(function(pg) {
            if (token !== attachPortfolio.renderToken || attachPortfolio.pdfDoc !== pdf) return;
            let wrap = pane.querySelector('.ledger-portfolio-pdf-wrap');
            if (!wrap) {
                pane.innerHTML = '<div class="ledger-portfolio-pdf-wrap"><canvas class="ledger-portfolio-pdf-canvas"></canvas></div>';
                wrap = pane.querySelector('.ledger-portfolio-pdf-wrap');
            }
            let canvas = wrap.querySelector('canvas.ledger-portfolio-pdf-canvas');
            if (!canvas) {
                wrap.innerHTML = '<canvas class="ledger-portfolio-pdf-canvas"></canvas>';
                canvas = wrap.querySelector('canvas.ledger-portfolio-pdf-canvas');
            }
            const size = attachPaneSize();
            attachPortfolio.paneW = size.w;
            attachPortfolio.paneH = size.h;
            const availW = Math.max(40, size.w);
            const availH = Math.max(40, size.h);
            const unscaled = pg.getViewport({ scale: 1 });
            const fitH = availH / unscaled.height;
            const fitW = availW / unscaled.width;
            // Default (zoom=1): fit page height without overflowing the pane.
            // Also cap by width so no scrollbar appears until the user zooms in.
            const fitScale = Math.min(fitH, fitW);
            const zoom = attachPortfolio.zoom || 1;
            const scale = fitScale * zoom;
            const viewport = pg.getViewport({ scale: scale });
            const ctx = canvas.getContext('2d');
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            canvas.style.width = viewport.width + 'px';
            canvas.style.height = viewport.height + 'px';
            return pg.render({ canvasContext: ctx, viewport: viewport }).promise;
        }).catch(function(err) {
            console.error(err);
            if (token !== attachPortfolio.renderToken) return;
            pane.innerHTML = '<div class="alert alert-warning m-3">Could not render this PDF page. Use Download instead.</div>';
        });
    }

    function renderAttachPageThumbs() {
        const pdf = attachPortfolio.pdfDoc;
        const list = document.getElementById('txAttachPageList');
        if (!pdf || !list) return;
        const total = pdf.numPages || 1;
        const items = [];
        for (let p = 1; p <= total; p++) {
            items.push(
                '<li><button type="button" class="ledger-portfolio-page' + (p === attachPortfolio.pdfPage ? ' active' : '') + '" data-page="' + p + '">'
                + '<canvas data-page="' + p + '"></canvas>'
                + '<span class="ledger-portfolio-page-num">' + p + '</span>'
                + '</button></li>'
            );
        }
        list.innerHTML = items.join('');
        const canvases = Array.from(list.querySelectorAll('canvas[data-page]'));
        let i = 0;
        const token = attachPortfolio.renderToken;
        function nextThumb() {
            if (token !== attachPortfolio.renderToken || attachPortfolio.pdfDoc !== pdf) return;
            if (i >= canvases.length) return;
            const canvas = canvases[i++];
            const pageNum = parseInt(canvas.dataset.page, 10);
            pdf.getPage(pageNum).then(function(pg) {
                if (token !== attachPortfolio.renderToken || attachPortfolio.pdfDoc !== pdf) return;
                const thumbW = 112;
                const unscaled = pg.getViewport({ scale: 1 });
                const scale = thumbW / unscaled.width;
                const vp = pg.getViewport({ scale: scale });
                canvas.width = vp.width;
                canvas.height = vp.height;
                return pg.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
            }).then(nextThumb).catch(nextThumb);
        }
        nextThumb();
    }

    function showAttachPagePanelForPdf() {
        const pages = attachPortfolio.pdfPages || 1;
        if (pages > 1) {
            setAttachPagePanelVisible(true);
            renderAttachPageThumbs();
            updateAttachPdfNav();
        } else {
            setAttachPagePanelVisible(false);
        }
    }

    function applyAttachImageFit() {
        const pane = document.getElementById('txAttachPortfolioPane');
        const img = pane && pane.querySelector('.ledger-portfolio-image img');
        if (!img) return;
        const size = attachPaneSize();
        attachPortfolio.paneW = size.w;
        attachPortfolio.paneH = size.h;
        const zoom = attachPortfolio.zoom || 1;
        updateAttachZoomUi();
        if (zoom <= 1.001) {
            img.style.maxHeight = '100%';
            img.style.maxWidth = '100%';
            img.style.height = 'auto';
            img.style.width = 'auto';
            return;
        }
        img.style.maxHeight = 'none';
        img.style.maxWidth = 'none';
        img.style.height = Math.round(size.h * zoom) + 'px';
        img.style.width = 'auto';
    }

    function applyAttachZoom() {
        updateAttachZoomUi();
        if (attachPortfolio.pdfDoc) {
            return renderAttachPdfPage(attachPortfolio.pdfPage);
        }
        applyAttachImageFit();
        return Promise.resolve();
    }

    function renderAttachPortfolioList(docs, selectedId) {
        const list = document.getElementById('txAttachPortfolioList');
        if (!list) return;
        if (!docs || !docs.length) {
            list.innerHTML = '<li class="text-muted small px-3 py-2">No documents attached.</li>';
            return;
        }
        list.innerHTML = docs.map(function(d) {
            const id = parseInt(d.id, 10) || 0;
            const name = escHtml(d.original_filename || 'document');
            const meta = attachFileTypeMeta(d);
            const active = id === selectedId ? ' active' : '';
            return '<li>'
                + '<button type="button" class="ledger-portfolio-item' + active + '" data-doc-id="' + id + '" title="' + name + '">'
                + '<span class="ledger-portfolio-type-icon ' + meta.cls + '" title="' + escHtml(meta.label) + '">'
                + '<i class="bi ' + meta.icon + '" aria-hidden="true"></i>'
                + '</span>'
                + '<span class="ledger-portfolio-item-name">' + name + '</span>'
                + '</button></li>';
        }).join('');
    }

    function showAttachOtherInfo(doc) {
        const pane = document.getElementById('txAttachPortfolioPane');
        if (!pane) return;
        setAttachZoomBarVisible(false);
        const name = escHtml(doc.original_filename || 'document');
        const mime = escHtml(doc.mime_type || 'unknown');
        const size = escHtml(doc.file_size_label || formatAttachFileSize(doc.file_size));
        const when = escHtml(doc.created_at || '');
        const href = escHtml(doc.download_url || ('pages/ledger.php?download_document=' + (doc.id || '')));
        const meta = attachFileTypeMeta(doc);
        pane.innerHTML = '<div class="ledger-portfolio-other p-4 text-center">'
            + '<div class="mb-3 d-inline-flex"><span class="ledger-portfolio-type-icon ' + meta.cls + '" style="width:64px;height:68px">'
            + '<i class="bi ' + meta.icon + '" aria-hidden="true" style="font-size:2.4rem"></i></span></div>'
            + '<h6 class="mb-3">' + name + '</h6>'
            + '<dl class="row justify-content-center small mb-4 text-start mx-auto" style="max-width:22rem">'
            + '<dt class="col-5">Type</dt><dd class="col-7"><code>' + mime + '</code></dd>'
            + '<dt class="col-5">Size</dt><dd class="col-7">' + size + '</dd>'
            + (when ? ('<dt class="col-5">Uploaded</dt><dd class="col-7">' + when + '</dd>') : '')
            + '</dl>'
            + '<p class="text-muted small mb-3">Preview is not available for this file type.</p>'
            + '<a class="btn btn-primary" href="' + href + '" target="_blank" rel="noopener">'
            + '<i class="bi bi-download" aria-hidden="true"></i> Download</a>'
            + '</div>';
    }

    function selectAttachDocument(docId) {
        const id = parseInt(docId, 10) || 0;
        const doc = (attachPortfolio.docs || []).find(function(d) { return parseInt(d.id, 10) === id; });
        const pane = document.getElementById('txAttachPortfolioPane');
        const dl = document.getElementById('txAttachDownload');
        if (!pane) return;
        destroyAttachPdf();
        attachPortfolio.zoom = 1;
        updateAttachZoomUi();
        setAttachZoomBarVisible(false);
        if (!doc) {
            if (dl) dl.classList.add('d-none');
            pane.innerHTML = '<div class="text-center text-muted py-5">Select a document</div>';
            return;
        }
        attachPortfolio.selectedId = id;
        renderAttachPortfolioList(attachPortfolio.docs, id);
        const href = doc.download_url || ('pages/ledger.php?download_document=' + id);
        if (dl) {
            dl.href = href;
            dl.classList.remove('d-none');
        }
        const kind = doc.preview_kind || 'other';
        const url = doc.preview_url || ('pages/ledger.php?preview_document=' + id);
        if (kind === 'image') {
            setAttachZoomBarVisible(true);
            pane.innerHTML = '<div class="ledger-portfolio-image">'
                + '<img src="' + url + '" alt="' + escHtml(doc.original_filename || '') + '">'
                + '</div>';
            const img = pane.querySelector('img');
            if (img) {
                if (img.complete) applyAttachImageFit();
                else img.addEventListener('load', applyAttachImageFit, { once: true });
            }
            return;
        }
        if (kind === 'pdf') {
            setAttachZoomBarVisible(true);
            pane.innerHTML = '<div class="ledger-portfolio-pdf-wrap"><div class="text-center text-muted py-5">Loading PDF…</div></div>';
            loadPdfJsLib().then(function(pdfjsLib) {
                return pdfjsLib.getDocument({ url: url, withCredentials: true }).promise;
            }).then(function(pdf) {
                if (attachPortfolio.selectedId !== id) {
                    pdf.destroy();
                    return;
                }
                attachPortfolio.pdfDoc = pdf;
                attachPortfolio.pdfUrl = url;
                attachPortfolio.pdfPages = pdf.numPages || 1;
                attachPortfolio.pdfPage = 1;
                pane.innerHTML = '<div class="ledger-portfolio-pdf-wrap"><canvas class="ledger-portfolio-pdf-canvas"></canvas></div>';
                showAttachPagePanelForPdf();
                return renderAttachPdfPage(1);
            }).catch(function(err) {
                console.error(err);
                setAttachPagePanelVisible(false);
                setAttachZoomBarVisible(false);
                pane.innerHTML = '<div class="p-4 text-center">'
                    + '<p class="mb-2">Could not render this PDF in the viewer.</p>'
                    + '<a class="btn btn-sm btn-primary" href="' + escHtml(href) + '" target="_blank" rel="noopener">Download file</a>'
                    + '</div>';
            });
            return;
        }
        if (kind === 'text') {
            pane.innerHTML = '<div class="text-center text-muted py-5">Loading…</div>';
            fetch(url).then(function(r) { return r.text(); }).then(function(txt) {
                pane.innerHTML = '<pre class="small bg-body-tertiary border rounded p-3 ledger-portfolio-text">'
                    + escHtml(txt) + '</pre>';
            }).catch(function() {
                pane.innerHTML = '<div class="alert alert-warning m-3">Could not load text preview. Use Download instead.</div>';
            });
            return;
        }
        showAttachOtherInfo(doc);
    }

    function openAttachmentPortfolio(txId) {
        const id = parseInt(txId, 10) || 0;
        if (!id || !attachPortfolioModalEl) return;
        const titleEl = document.getElementById('txAttachPortfolioTitle');
        const pane = document.getElementById('txAttachPortfolioPane');
        const list = document.getElementById('txAttachPortfolioList');
        const dl = document.getElementById('txAttachDownload');
        destroyAttachPdf();
        attachPortfolio.txId = id;
        attachPortfolio.docs = [];
        attachPortfolio.selectedId = 0;
        attachPortfolio.zoom = 1;
        if (titleEl) titleEl.textContent = 'Attachments';
        if (dl) dl.classList.add('d-none');
        setAttachZoomBarVisible(false);
        if (list) list.innerHTML = '<li class="text-muted small px-3 py-2">Loading…</li>';
        if (pane) pane.innerHTML = '<div class="text-center text-muted py-5">Loading…</div>';
        showLedgerModal(attachPortfolioModalEl);
        fetch('pages/ledger.php?transaction_documents=' + id)
            .then(parseJsonResponse)
            .then(function(data) {
                if (!isApiSuccess(data)) {
                    if (list) list.innerHTML = '';
                    if (pane) {
                        pane.innerHTML = '<div class="alert alert-danger m-3">'
                            + escHtml((data && data.error) || 'Unable to load attachments.') + '</div>';
                    }
                    return;
                }
                const docs = data.documents || [];
                attachPortfolio.docs = docs;
                updateRowAttachmentIndicator(id, docs.length);
                const bits = ['Attachments'];
                if (data.reference_number) bits.push('Ref #' + data.reference_number);
                else if (data.pay_to) bits.push(data.pay_to);
                if (titleEl) titleEl.textContent = bits.join(' — ');
                if (!docs.length) {
                    renderAttachPortfolioList([], 0);
                    if (pane) pane.innerHTML = '<div class="text-center text-muted py-5">No documents attached.</div>';
                    return;
                }
                selectAttachDocument(docs[0].id);
            })
            .catch(function(err) {
                console.error(err);
                if (pane) {
                    pane.innerHTML = '<div class="alert alert-danger m-3">Failed to load attachments.</div>';
                }
            });
    }

    function bindAttachmentPortfolio() {
        if (!attachPortfolioModalEl || attachPortfolioModalEl.dataset.bound === '1') return;
        attachPortfolioModalEl.dataset.bound = '1';
        const list = document.getElementById('txAttachPortfolioList');
        if (list) {
            list.addEventListener('click', function(e) {
                const item = e.target.closest('.ledger-portfolio-item');
                if (!item) return;
                e.preventDefault();
                const id = parseInt(item.dataset.docId, 10);
                if (id) selectAttachDocument(id);
            });
        }
        const pageList = document.getElementById('txAttachPageList');
        if (pageList) {
            pageList.addEventListener('click', function(e) {
                const btn = e.target.closest('.ledger-portfolio-page');
                if (!btn) return;
                e.preventDefault();
                const p = parseInt(btn.dataset.page, 10);
                if (p) renderAttachPdfPage(p);
            });
        }
        const prev = document.getElementById('txAttachPdfPrev');
        const next = document.getElementById('txAttachPdfNext');
        if (prev) {
            prev.addEventListener('click', function() {
                if (attachPortfolio.pdfPage > 1) renderAttachPdfPage(attachPortfolio.pdfPage - 1);
            });
        }
        if (next) {
            next.addEventListener('click', function() {
                if (attachPortfolio.pdfPage < attachPortfolio.pdfPages) {
                    renderAttachPdfPage(attachPortfolio.pdfPage + 1);
                }
            });
        }
        const zoomIn = document.getElementById('txAttachZoomIn');
        const zoomOut = document.getElementById('txAttachZoomOut');
        const zoomFit = document.getElementById('txAttachZoomFit');
        if (zoomIn) {
            zoomIn.addEventListener('click', function() {
                attachPortfolio.zoom = Math.min(4, Math.round(((attachPortfolio.zoom || 1) + 0.25) * 100) / 100);
                applyAttachZoom();
            });
        }
        if (zoomOut) {
            zoomOut.addEventListener('click', function() {
                attachPortfolio.zoom = Math.max(1, Math.round(((attachPortfolio.zoom || 1) - 0.25) * 100) / 100);
                applyAttachZoom();
            });
        }
        if (zoomFit) {
            zoomFit.addEventListener('click', function() {
                attachPortfolio.zoom = 1;
                applyAttachZoom();
            });
        }
        const pane = document.getElementById('txAttachPortfolioPane');
        if (pane && typeof ResizeObserver !== 'undefined') {
            const ro = new ResizeObserver(function() {
                const size = attachPaneSize();
                if (Math.abs(size.w - attachPortfolio.paneW) < 2 && Math.abs(size.h - attachPortfolio.paneH) < 2) {
                    return;
                }
                if (!size.w || !size.h) return;
                applyAttachZoom();
            });
            ro.observe(pane);
        }
        if (pane) {
            let lastWheelPageAt = 0;
            pane.addEventListener('wheel', function(e) {
                const pages = attachPortfolio.pdfPages || 1;
                if (!attachPortfolio.pdfDoc || pages <= 1) return;
                e.preventDefault();
                e.stopPropagation();
                const now = Date.now();
                if (now - lastWheelPageAt < 280) return;
                const dy = e.deltaY;
                if (!dy || Math.abs(dy) < 4) return;
                if (dy > 0 && attachPortfolio.pdfPage < pages) {
                    lastWheelPageAt = now;
                    renderAttachPdfPage(attachPortfolio.pdfPage + 1);
                } else if (dy < 0 && attachPortfolio.pdfPage > 1) {
                    lastWheelPageAt = now;
                    renderAttachPdfPage(attachPortfolio.pdfPage - 1);
                }
            }, { passive: false });
        }
        attachPortfolioModalEl.addEventListener('shown.bs.modal', function() {
            applyAttachZoom();
        });
        attachPortfolioModalEl.addEventListener('hidden.bs.modal', function() {
            destroyAttachPdf();
            attachPortfolio.docs = [];
            attachPortfolio.selectedId = 0;
            attachPortfolio.zoom = 1;
            setAttachZoomBarVisible(false);
            const paneEl = document.getElementById('txAttachPortfolioPane');
            if (paneEl) {
                paneEl.classList.remove('is-zoomed');
                paneEl.innerHTML = '<div class="text-center text-muted py-5">Select a document</div>';
            }
        });
    }
    bindAttachmentPortfolio();

    /**
     * Bootstrap modal confirm for queueing a file delete.
     * Resolves true on "Queue Delete", false on Cancel/dismiss.
     */
    function confirmQueueDelete(fileName) {
        return new Promise(resolve => {
            const modalEl = document.getElementById('txQueueDeleteModal');
            const nameEl = document.getElementById('txQueueDeleteFileName');
            const confirmBtn = document.getElementById('txQueueDeleteConfirm');
            if (!modalEl || !confirmBtn || typeof bootstrap === 'undefined') {
                // Fallback if modal/bootstrap unavailable
                resolve(window.confirm(
                    'This file will be queued for deletion. It will be removed only when you save the transaction.\n\nFile: '
                    + fileName
                ));
                return;
            }
            if (nameEl) nameEl.textContent = fileName || 'selected file';
            mountModalOnBody(modalEl);
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            let settled = false;
            const finish = (value) => {
                if (settled) return;
                settled = true;
                confirmBtn.removeEventListener('click', onConfirm);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                resolve(value);
            };
            const onConfirm = () => {
                finish(true);
                modal.hide();
            };
            const onHidden = () => finish(false);
            confirmBtn.addEventListener('click', onConfirm);
            modalEl.addEventListener('hidden.bs.modal', onHidden);
            modal.show();
        });
    }

    function bindDocumentListActions() {
        const docsEl = document.getElementById('txDocumentsList');
        if (!docsEl || docsEl.dataset.bound === '1') return;
        docsEl.dataset.bound = '1';
        docsEl.addEventListener('click', function(e) {
            const preview = e.target.closest('.tx-doc-preview');
            if (preview) {
                e.preventDefault();
                const id = parseInt(preview.dataset.docId, 10);
                if (id) openDocumentPreview(id, preview.dataset.docName || preview.textContent);
                return;
            }
            const del = e.target.closest('.tx-doc-delete');
            if (del) {
                e.preventDefault();
                if (!isTxEditMode()) {
                    showToast('Enter edit mode to queue document deletions.', 'warning');
                    return;
                }
                const id = parseInt(del.dataset.docId, 10);
                const name = del.dataset.docName || 'this file';
                if (!id) return;

                if (isDocQueuedForDelete(id)) {
                    unqueueDocForDelete(id);
                    showToast('Removed “' + name + '” from deletion queue.', 'info');
                    const docs = (currentViewData && currentViewData.documents) || [];
                    renderDocumentsList(docs, true);
                    return;
                }

                confirmQueueDelete(name).then(ok => {
                    if (!ok) {
                        // Cancel — leave queue and edit mode untouched
                        return;
                    }
                    queueDocForDelete(id, name);
                    showToast('Queued “' + name + '” for deletion on save.', 'warning');
                    const docs = (currentViewData && currentViewData.documents) || [];
                    renderDocumentsList(docs, true);
                });
            }
        });
    }

    function confirmQueuedDocumentDeletion() {
        const pending = getPendingDocDeleteList();
        if (!pending.length) {
            return { confirmed: true, pending: [] };
        }
        const lines = pending.map(p => '• ' + p.name).join('\n');
        const msg = 'The following file(s) are queued for deletion and will be permanently removed:\n\n'
            + lines
            + '\n\nDelete these file(s) and continue saving?';
        if (!confirm(msg)) {
            return { confirmed: false, pending };
        }
        return { confirmed: true, pending };
    }

    function executeQueuedDocumentDeletion(pending) {
        if (!pending || !pending.length) {
            return Promise.resolve({ skipped: true, deleted_count: 0, message: '' });
        }
        const fd = new FormData();
        fd.append('action', 'delete_documents');
        fd.append('doc_ids', JSON.stringify(pending.map(p => p.id)));
        return fetch('pages/ledger.php', { method: 'POST', body: fd })
            .then(parseJsonResponse)
            .then(res => {
                if (!isApiSuccess(res)) {
                    throw new Error(res.error || 'Failed to delete queued attachments.');
                }
                clearPendingDocDeletes();
                if (currentViewData && Array.isArray(res.documents)) {
                    currentViewData.documents = res.documents;
                    updateRowAttachmentIndicator(currentViewData.id, res.documents.length);
                }
                return res;
            });
    }

    function updateModeBadge(mode) {
        const b = document.getElementById('formModeBadge');
        if (!b) return;
        b.className = 'badge ms-1';
        if (mode === 'view') {
            b.textContent = 'View';
            b.classList.add('bg-secondary');
        } else if (mode === 'edit') {
            b.textContent = 'Edit';
            b.classList.add('bg-warning', 'text-dark');
        } else if (mode === 'add') {
            b.textContent = 'New';
            b.classList.add('bg-primary');
        } else {
            b.textContent = '—';
            b.classList.add('bg-body-secondary', 'text-muted');
        }
        setImportTextBtnVisible(mode === 'add');
    }

    /** Import from Text is only available when adding a new transaction. */
    function setImportTextBtnVisible(show) {
        if (!importTextBtn) return;
        if (show) importTextBtn.classList.remove('d-none');
        else importTextBtn.classList.add('d-none');
    }

    function getTxDocFileInput() {
        return document.getElementById('txDocFile');
    }

    function getTxDocUploadBtn() {
        return document.getElementById('txDocUploadBtn');
    }

    /** Clear selected file and disable Upload (does not hide the control row). */
    function clearDocFileSelection() {
        const fileInput = getTxDocFileInput();
        if (fileInput) {
            // Reset so the same file can be re-chosen after a failed/successful upload
            fileInput.value = '';
        }
        syncDocUploadBtn();
    }

    function setDocUploadVisible(show) {
        const docForm = document.getElementById('txDocUploadForm');
        if (!docForm) return;
        if (show) docForm.classList.remove('d-none');
        else docForm.classList.add('d-none');
        // Always reset selection when showing/hiding so a prior pick does not linger across txs
        clearDocFileSelection();
    }

    function showBlankForm() {
        txIdField.value = '';
        currentViewData = null;
        budgetOnlyEditMode = false;
        // Keep sessionStorage queue so a failed save can re-open the same tx and restore it
        clearPendingDocDeletes(false);
        if (form) form.reset();
        if (linesBody) linesBody.innerHTML = '';
        renderMetaSection(null);
        setDocUploadVisible(false);
        setMainFieldsReadOnly(true);
        setBudgetStatusWarning(false);
        if (formTitle) formTitle.textContent = 'Transaction Details';
        updateModeBadge('blank');
        if (addLineBtn) addLineBtn.style.display = 'none';
        if (resetLinesBtn) resetLinesBtn.style.display = 'none';
        if (saveBtn) saveBtn.style.display = 'none';
        if (cancelBtn2) cancelBtn2.style.display = '';
        markTxFormClean();
    }

    function populateView(data) {
        // Discard queue when viewing a different transaction; keep stored queue for same tx
        try {
            const raw = sessionStorage.getItem(PENDING_DOC_STORAGE_KEY);
            const stored = raw ? JSON.parse(raw) : null;
            if (!stored || String(stored.txId) !== String(data.id)) {
                clearPendingDocDeletes(true);
            } else {
                clearPendingDocDeletes(false);
            }
        } catch (e) {
            clearPendingDocDeletes(true);
        }
        txIdField.value = data.id || '';
        currentViewData = data;
        document.getElementById('transaction_date').value = data.transaction_date || '';
        document.getElementById('reference_number').value = data.reference_number || '';
        document.getElementById('pay_to').value = data.pay_to || '';
        document.getElementById('check_number').value = data.check_number || '';
        document.getElementById('description').value = data.description || '';
        setBudgetSelection(data.budget_id || '', { auto: false });
        clearReferenceReuseState();
        refreshReferenceSuggestion().then(updateReferenceHintVisibility);
        updateReferenceHintVisibility();

        linesBody.innerHTML = '';
        const lines = data.lines || [];
        lines.forEach(l => {
            const row = createLineRow(l, true);
            linesBody.appendChild(row);
            // no listeners for view
        });
        budgetOnlyEditMode = false;
        setMainFieldsReadOnly(true);
        setBudgetStatusWarning(false);
        formTitle.textContent = data.reference_number
            ? ('Transaction ' + data.reference_number + ' (#' + data.id + ')')
            : ('Transaction #' + data.id);
        updateModeBadge('view');
        if (addLineBtn) addLineBtn.style.display = 'none';
        if (resetLinesBtn) resetLinesBtn.style.display = 'none';
        if (saveBtn) saveBtn.style.display = 'none';
        if (cancelBtn2) cancelBtn2.style.display = 'none';
        renderMetaSection(data);
        setDocUploadVisible(false);
        // Re-render docs without delete buttons in view mode
        renderDocumentsList((data && data.documents) || [], false);
        markTxFormClean();
        openTxFormModal();
    }

    function populateEditable(data) {
        txIdField.value = data.id;
        currentViewData = data;
        // Restore any queue persisted across a failed save reload
        restorePendingDocDeletes(data.id);
        document.getElementById('transaction_date').value = data.transaction_date || '';
        document.getElementById('reference_number').value = data.reference_number || '';
        document.getElementById('pay_to').value = data.pay_to || '';
        document.getElementById('check_number').value = data.check_number || '';
        document.getElementById('description').value = data.description || '';
        // Keep saved budget; do not auto-override unless user changes the date later
        setBudgetSelection(data.budget_id || '', { auto: false });
        clearReferenceReuseState();
        refreshReferenceSuggestion().then(updateReferenceHintVisibility);
        updateReferenceHintVisibility();

        linesBody.innerHTML = '';
        const lines = data.lines || [];
        const locked = isTxClearedOrReconciled(data);

        if (locked) {
            // Cleared/reconciled: show lines read-only; only budget is editable
            lines.forEach(l => {
                const row = createLineRow(l, true);
                linesBody.appendChild(row);
            });
            renderMetaSection(data);
            applyBudgetOnlyEditMode(data);
            recalcTotals();
            markTxFormClean();
            openTxFormModal();
            return;
        }

        if (lines.length > 0) {
            lines.forEach(l => {
                const row = createLineRow(l);
                linesBody.appendChild(row);
                attachLineListeners(row);
            });
        } else {
            addLine();
            addLine();
        }
        budgetOnlyEditMode = false;
        setMainFieldsReadOnly(false);
        setBudgetStatusWarning(false);
        formTitle.textContent = data.reference_number
            ? ('Edit ' + data.reference_number + ' (#' + data.id + ')')
            : ('Edit Transaction #' + data.id);
        updateModeBadge('edit');
        if (addLineBtn) addLineBtn.style.display = '';
        if (resetLinesBtn) resetLinesBtn.style.display = '';
        if (saveBtn) saveBtn.style.display = '';
        if (cancelBtn2) cancelBtn2.style.display = '';
        renderMetaSection(data);
        setDocUploadVisible(!!data.is_editable);
        renderDocumentsList(data.documents || [], !!data.is_editable);
        recalcTotals();
        markTxFormClean();
        openTxFormModal();
    }

    function enableEditFromView() {
        const id = txIdField.value;
        if (!id) return;
        restorePendingDocDeletes(id);

        // Cleared/reconciled: unlock budget only
        if (isTxClearedOrReconciled(currentViewData)) {
            applyBudgetOnlyEditMode(currentViewData || { id: id });
            markTxFormClean();
            openTxFormModal();
            return;
        }

        budgetOnlyEditMode = false;
        setMainFieldsReadOnly(false);
        setBudgetStatusWarning(false);
        formTitle.textContent = 'Edit Transaction #' + id;
        updateModeBadge('edit');
        if (addLineBtn) addLineBtn.style.display = '';
        if (resetLinesBtn) resetLinesBtn.style.display = '';
        if (saveBtn) saveBtn.style.display = '';
        if (cancelBtn2) cancelBtn2.style.display = '';
        linesBody.querySelectorAll('tr').forEach(row => {
            row.querySelectorAll('select, input').forEach(el => {
                el.disabled = false;
                el.classList.remove('bg-body-secondary');
            });
            const rem = row.querySelector('.remove-line');
            if (rem) rem.style.display = '';
            attachLineListeners(row);
        });
        const canEdit = !!(currentViewData && currentViewData.is_editable);
        setDocUploadVisible(canEdit);
        renderDocumentsList((currentViewData && currentViewData.documents) || [], canEdit);
        recalcTotals();
        markTxFormClean();
        openTxFormModal();
    }

    function showFormForAdd() {
        txIdField.value = '';
        currentViewData = null;
        budgetOnlyEditMode = false;
        form.reset();
        const today = new Date().toISOString().slice(0, 10);
        document.getElementById('transaction_date').value = today;
        if (refInput) refInput.value = '';
        clearReferenceReuseState();
        budgetAutoMode = true;
        applyBudgetForDate(today, { force: true });
        linesBody.innerHTML = '';
        addLine();
        addLine();
        setMainFieldsReadOnly(false);
        setBudgetStatusWarning(false);
        formTitle.textContent = 'Add New Transaction';
        updateModeBadge('add');
        if (addLineBtn) addLineBtn.style.display = '';
        if (resetLinesBtn) resetLinesBtn.style.display = '';
        if (saveBtn) saveBtn.style.display = '';
        if (cancelBtn2) cancelBtn2.style.display = '';
        renderMetaSection(null);
        setDocUploadVisible(false);
        recalcTotals();
        refreshReferenceSuggestion().then(updateReferenceHintVisibility);
        updateReferenceHintVisibility();
        markTxFormClean();
        openTxFormModal();
    }

    function updateButtonStates() {
        if (!txTableBody) return;
        const checked = txTableBody.querySelectorAll('.tx-cb:checked');
        const count = checked.length;
        const multi = count > 1;

        if (addTxBtn) addTxBtn.disabled = multi;
        if (viewTxBtn) viewTxBtn.disabled = (count !== 1);
        // Edit allowed for single selection including cleared/reconciled (budget-only there)
        if (editTxBtn) editTxBtn.disabled = (count !== 1);
        if (clearTxBtn) clearTxBtn.disabled = (count === 0);
        if (reconcileTxBtn) reconcileTxBtn.disabled = (count === 0);
    }

    function getSelectedIds() {
        return Array.from(txTableBody.querySelectorAll('.tx-cb:checked')).map(cb => parseInt(cb.value, 10));
    }

    function anySelectedNonPending() {
        return Array.from(txTableBody.querySelectorAll('.tx-cb:checked')).some(cb => {
            const row = cb.closest('tr');
            return row && row.dataset.status && row.dataset.status !== 'pending';
        });
    }

    function hasUnsavedInputs() {
        if (budgetOnlyEditMode) {
            return isBudgetSelectionChanged();
        }
        // form always visible; consider unsaved if has tx id in edit or any data entered
        const fields = ['reference_number', 'pay_to', 'check_number', 'description'];
        for (const fid of fields) {
            const el = document.getElementById(fid);
            if (el && el.value.trim() !== '') return true;
        }
        // any lines with positive amount
        const hasLineData = Array.from(linesBody.querySelectorAll('.line-amount')).some(el => parseFloat(el.value || '0') > 0);
        if (hasLineData) return true;
        if (linesBody.querySelectorAll('tr').length > 2) return true;
        return false;
    }

    function markTxFormClean() {
        if (form) form.removeAttribute('data-dirty');
    }

    /**
     * Navigation / discard dirty: rely on data-dirty (user edits after populate).
     * Do not use hasUnsavedInputs() alone — view mode fills fields and would false-positive.
     */
    function isLedgerFormDirty() {
        return !!(form && form.getAttribute('data-dirty') === '1');
    }

    if (typeof window.TemperDirtyForms !== 'undefined') {
        window.TemperDirtyForms.registerChecker(isLedgerFormDirty);
    }

    function confirmDiscardTx(message) {
        if (!isLedgerFormDirty()) {
            markTxFormClean();
            return true;
        }
        const msg = message
            || (typeof window.TemperDirtyForms !== 'undefined' && window.TemperDirtyForms.MESSAGE)
            || 'You have unsaved changes. Leave anyway?';
        if (!confirm(msg)) return false;
        markTxFormClean();
        return true;
    }

    let lastAnchorRow = null;
    let currentViewData = null;

    // Wire action buttons
    if (addTxBtn) addTxBtn.addEventListener('click', () => {
        // Prompt only when the user has edited the form since it was loaded
        if ((isTxEditMode() || budgetOnlyEditMode) && isLedgerFormDirty()) {
            if (!confirmDiscardTx()) return;
        }
        // clear the current selection when starting add
        if (txTableBody) txTableBody.querySelectorAll('.tx-cb:checked').forEach(cb => cb.checked = false);
        if (selectAll) selectAll.checked = false;
        updateButtonStates();
        showFormForAdd();
        markTxFormClean();
    });

    function openViewForId(id) {
        if (!id) return;
        // If already showing this tx in view mode in an open modal, no-op
        if (isTxFormModalOpen() && String(txIdField.value) === String(id)
            && document.getElementById('transaction_date')?.readOnly && !budgetOnlyEditMode
            && saveBtn && saveBtn.style.display === 'none') {
            return;
        }
        if (isLedgerFormDirty() && !confirmDiscardTx()) return;
        fetch('pages/ledger.php?get_transaction=' + id)
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    showToast(data.error, 'danger');
                    return;
                }
                populateView(data);
            })
            .catch(err => {
                console.error(err);
                showToast('Failed to load transaction.', 'danger');
            });
    }

    function openEditForId(id) {
        if (!id) return;
        const curId = txIdField.value;
        const alreadyFullEdit = isTxFormModalOpen() && curId == id && !document.getElementById('transaction_date').readOnly;
        const alreadyBudgetOnly = isTxFormModalOpen() && curId == id && budgetOnlyEditMode;
        if (alreadyFullEdit || alreadyBudgetOnly) return;
        if (isLedgerFormDirty() && String(curId) !== String(id) && !confirmDiscardTx()) return;
        if (curId == id && currentViewData && isTxFormModalOpen()) {
            enableEditFromView();
            return;
        }
        fetch('pages/ledger.php?get_transaction=' + id)
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    showToast(data.error, 'danger');
                    return;
                }
                populateEditable(data);
            })
            .catch(err => {
                console.error(err);
                showToast('Failed to load transaction for edit.', 'danger');
            });
    }

    if (viewTxBtn) viewTxBtn.addEventListener('click', () => {
        const ids = getSelectedIds();
        if (ids.length !== 1) return;
        openViewForId(ids[0]);
    });

    if (editTxBtn) editTxBtn.addEventListener('click', () => {
        const ids = getSelectedIds();
        if (ids.length !== 1) return;
        openEditForId(ids[0]);
    });

    if (clearTxBtn) clearTxBtn.addEventListener('click', () => {
        const ids = getSelectedIds();
        if (ids.length === 0) return;
        if (anySelectedNonPending()) {
            showToast('Only pending transactions can be cleared.', 'warning');
            return;
        }
        if (!confirm('Mark ' + ids.length + ' selected transaction(s) as cleared?')) return;

        const fd = new FormData();
        fd.append('action', 'clear');
        fd.append('selected_ids', JSON.stringify(ids));
        fetch('pages/ledger.php' + buildQueryString(true), { method: 'POST', body: fd })
            .then(r => r.text())
            .then(html => {
                applyMainContent(html);
            })
            .catch(err => {
                console.error(err);
                showToast('Clear operation failed.', 'danger');
            });
    });

    if (reconcileTxBtn) reconcileTxBtn.addEventListener('click', () => {
        const ids = getSelectedIds();
        if (ids.length === 0) return;
        if (anySelectedNonPending()) {
            showToast('Only pending transactions can be reconciled.', 'warning');
            return;
        }
        if (!confirm('Mark ' + ids.length + ' selected transaction(s) as reconciled? (placeholder action)')) return;

        const fd = new FormData();
        fd.append('action', 'reconcile');
        fd.append('selected_ids', JSON.stringify(ids));
        fetch('pages/ledger.php' + buildQueryString(true), { method: 'POST', body: fd })
            .then(r => r.text())
            .then(html => {
                applyMainContent(html);
            })
            .catch(err => {
                console.error(err);
                showToast('Reconcile operation failed.', 'danger');
            });
    });

    // Cancel / close: data-bs-dismiss on Cancel; dirty protection via hide.bs.modal.
    // On successful dismiss, clear pending doc queue and reset form state.
    function onTxFormModalHidden() {
        clearPendingDocDeletes(true);
        showBlankForm();
        markTxFormClean();
    }
    if (txFormModalEl) {
        txFormModalEl.addEventListener('hidden.bs.modal', onTxFormModalHidden);
    }
    // Programmatic cancel still available if needed
    function cancelFormAction() {
        if (!confirmDiscardTx()) return;
        markTxFormClean();
        closeTxFormModal();
    }

    // Reset lines to exactly 2
    if (resetLinesBtn) {
        resetLinesBtn.addEventListener('click', () => {
            linesBody.innerHTML = '';
            addLine();
            addLine();
            recalcTotals();
        });
    }

    // Add line button (inside form)
    if (addLineBtn) {
        addLineBtn.addEventListener('click', addLine);
    }

    // ── Reference #: shadow default, double-click fill, reuse warn, list modal ──
    // Ledger manual entry is non-contribution → suggest YY0100+
    const REF_KIND = 'other';

    /** Show tip only when Ref # is empty (ghost placeholder is visible). */
    function updateReferenceHintVisibility() {
        if (!refSuggestHint) return;
        const hasValue = !!(refInput && (refInput.value || '').trim() !== '');
        refSuggestHint.classList.toggle('d-none', hasValue);
        if (!hasValue) {
            refSuggestHint.textContent = 'Double-click for next suggested number';
        }
    }

    function refreshReferenceSuggestion() {
        if (!refInput) return Promise.resolve(null);
        const dateEl = document.getElementById('transaction_date');
        const date = (dateEl && dateEl.value) ? dateEl.value : '';
        let url = 'pages/ledger.php?reference_api=suggest&kind=' + encodeURIComponent(REF_KIND);
        if (date) url += '&date=' + encodeURIComponent(date);
        return fetch(url)
            .then(r => r.json())
            .then(d => {
                if (!d || !d.suggested) return null;
                // Ghosted suggestion in the empty field (placeholder)
                refInput.dataset.suggested = d.suggested;
                refInput.placeholder = d.suggested;
                updateReferenceHintVisibility();
                return d.suggested;
            })
            .catch(() => null);
    }

    function clearReferenceReuseState() {
        refReuseConfirmedFor = '';
        if (refReuseFlag) refReuseFlag.value = '0';
        if (refReuseWarn) {
            refReuseWarn.classList.add('d-none');
            refReuseWarn.textContent = '';
        }
    }

    function checkReferenceReuseLive() {
        if (!refInput) return;
        const seq = (refInput.value || '').trim();
        const excludeId = parseInt(txIdField.value || '0', 10) || 0;
        if (!/^\d{6}$/.test(seq)) {
            if (refReuseConfirmedFor !== seq) {
                if (refReuseFlag) refReuseFlag.value = '0';
            }
            if (refReuseWarn) {
                refReuseWarn.classList.add('d-none');
                refReuseWarn.textContent = '';
            }
            return;
        }
        if (refReuseConfirmedFor === seq) {
            if (refReuseFlag) refReuseFlag.value = '1';
            if (refReuseWarn) {
                refReuseWarn.classList.remove('d-none');
                refReuseWarn.textContent = 'Already Used';
            }
            return;
        }
        fetch('pages/ledger.php?reference_api=check&ref=' + encodeURIComponent(seq)
            + '&exclude_id=' + excludeId
            + '&kind=' + encodeURIComponent(REF_KIND))
            .then(r => r.json())
            .then(d => {
                if (!refReuseWarn) return;
                if (d && d.taken) {
                    if (refReuseFlag) refReuseFlag.value = '0';
                    refReuseWarn.classList.remove('d-none');
                    refReuseWarn.textContent = 'Already Used';
                } else {
                    if (refReuseFlag) refReuseFlag.value = '0';
                    refReuseWarn.classList.add('d-none');
                    refReuseWarn.textContent = '';
                }
            })
            .catch(() => { /* ignore live-check failures */ });
    }

    /**
     * If Reference # is taken by another tx, confirm reuse. Resolves true to proceed.
     */
    function confirmReferenceReuseIfNeeded(seqVal) {
        const excludeId = parseInt(txIdField.value || '0', 10) || 0;
        if (refReuseConfirmedFor === seqVal && refReuseFlag && refReuseFlag.value === '1') {
            return Promise.resolve(true);
        }
        return fetch('pages/ledger.php?reference_api=check&ref=' + encodeURIComponent(seqVal)
            + '&exclude_id=' + excludeId
            + '&kind=' + encodeURIComponent(REF_KIND))
            .then(r => r.json())
            .then(d => {
                if (!d || !d.taken) {
                    clearReferenceReuseState();
                    return true;
                }
                const u = d.usage || {};
                const lines = [
                    'Reference # ' + seqVal + ' is already used.',
                    u.id ? ('Existing transaction #' + u.id
                        + (u.transaction_date ? (' dated ' + u.transaction_date) : '')
                        + (u.pay_to ? (' — ' + u.pay_to) : '')
                        + '.') : '',
                    '',
                    'Reuse this Reference # anyway?',
                    '(Attachments share the same folder when Reference # values match.)',
                ].filter(Boolean);
                if (!window.confirm(lines.join('\n'))) {
                    return false;
                }
                refReuseConfirmedFor = seqVal;
                if (refReuseFlag) refReuseFlag.value = '1';
                if (refReuseWarn) {
                    refReuseWarn.classList.remove('d-none');
                    refReuseWarn.textContent = 'Already Used';
                }
                return true;
            })
            .catch(() => {
                // If check fails, let server validate
                return true;
            });
    }

    function openReferenceListModal() {
        const modalEl = document.getElementById('referenceListModal');
        const body = document.getElementById('referenceListBody');
        if (!modalEl || !body || typeof bootstrap === 'undefined') return;
        body.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">Loading…</td></tr>';
        showLedgerModal(modalEl);
        fetch('pages/ledger.php?reference_api=list')
            .then(r => r.json())
            .then(d => {
                const items = (d && d.items) || [];
                if (!items.length) {
                    body.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">No Reference #s assigned yet.</td></tr>';
                    return;
                }
                body.innerHTML = items.map(it => {
                    return '<tr>'
                        + '<td class="ps-3 font-monospace fw-semibold">' + escHtml(it.reference_number || it.sequence_number || '') + '</td>'
                        + '<td class="text-nowrap">' + escHtml(it.transaction_date || '—') + '</td>'
                        + '<td class="small">' + escHtml(it.label || it.pay_to || it.description || '—') + '</td>'
                        + '<td class="pe-3 text-end text-muted">#' + escHtml(String(it.id || '')) + '</td>'
                        + '</tr>';
                }).join('');
            })
            .catch(() => {
                body.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-4">Failed to load list.</td></tr>';
            });
    }

    if (refInput) {
        refInput.addEventListener('dblclick', function() {
            const suggested = (refInput.dataset.suggested || refInput.placeholder || '').trim();
            if (/^\d{6}$/.test(suggested)) {
                refInput.value = suggested;
                clearReferenceReuseState();
                checkReferenceReuseLive();
                updateReferenceHintVisibility();
                showToast('Filled suggested Reference # ' + suggested + '.', 'info', 2500);
            } else {
                refreshReferenceSuggestion().then(s => {
                    if (s && /^\d{6}$/.test(s)) {
                        refInput.value = s;
                        clearReferenceReuseState();
                        checkReferenceReuseLive();
                        updateReferenceHintVisibility();
                        showToast('Filled suggested Reference # ' + s + '.', 'info', 2500);
                    }
                });
            }
        });
        refInput.addEventListener('input', function() {
            if (refReuseConfirmedFor && refReuseConfirmedFor !== (refInput.value || '').trim()) {
                clearReferenceReuseState();
            }
            updateReferenceHintVisibility();
            checkReferenceReuseLive();
        });
        refInput.addEventListener('blur', checkReferenceReuseLive);
        updateReferenceHintVisibility();
    }
    const dateElForSeq = document.getElementById('transaction_date');
    if (dateElForSeq) {
        dateElForSeq.addEventListener('change', function() {
            refreshReferenceSuggestion();
            // Auto-select covering active budget when still in auto mode
            applyBudgetForDate(dateElForSeq.value || '');
            updateBudgetPeriodWarning();
        });
    }
    if (budgetSelect) {
        fillBudgetSelect(defaultBudgetIdToday || '');
        budgetSelect.addEventListener('change', function() {
            // Manual budget choice disables auto-reselect until Add form is opened again
            budgetAutoMode = false;
            if (budgetOutOfPeriodFlag) budgetOutOfPeriodFlag.value = '0';
            updateBudgetPeriodWarning();
            syncBudgetSelectTooltip();
            // Keep cleared/reconciled reporting warning visible while in budget-only edit
            if (budgetOnlyEditMode) {
                setBudgetStatusWarning(true);
                // Unlock Save only when budget actually differs from the loaded value
                updateSaveButtonState();
            }
        });
        // Default empty form on first load (if add mode is shown)
        if (!txIdField.value) {
            budgetAutoMode = true;
            const d0 = document.getElementById('transaction_date')?.value || new Date().toISOString().slice(0, 10);
            applyBudgetForDate(d0, { force: true });
        }
    }
    if (refHelpBtn) {
        refHelpBtn.addEventListener('click', function(e) {
            e.preventDefault();
            openReferenceListModal();
        });
    }
    // Initial shadow default for empty form
    refreshReferenceSuggestion();

    // Form submit (Add or Edit) — process queued attachment deletions after confirm
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const seqVal = (document.getElementById('reference_number')?.value || '').trim();
            if (!budgetOnlyEditMode && !/^\d{6}$/.test(seqVal)) {
                showToast('Reference # must be YY#### (exactly 6 digits, e.g. 260001).', 'warning');
                document.getElementById('reference_number')?.focus();
                return;
            }

            // Budget period check — warn and require confirmation when date is outside period
            const txDateVal = document.getElementById('transaction_date')?.value || '';
            const bidVal = budgetSelect?.value || '';
            if (bidVal && txDateVal) {
                const b = budgetById(bidVal);
                const start = b?.start_date || '';
                const end = b?.end_date || '';
                if (start && end && (txDateVal < start || txDateVal > end)) {
                    const period = b?.period_label || formatPeriodMdY(start, end);
                    const ok = confirm(
                        'Transaction date ' + txDateVal + ' is outside the selected budget period (' + period + ').\n\n'
                        + 'Save anyway?'
                    );
                    if (!ok) {
                        showToast('Save cancelled — budget period not confirmed.', 'info');
                        return;
                    }
                    if (budgetOutOfPeriodFlag) budgetOutOfPeriodFlag.value = '1';
                } else if (budgetOutOfPeriodFlag) {
                    budgetOutOfPeriodFlag.value = '0';
                }
            } else if (budgetOutOfPeriodFlag) {
                budgetOutOfPeriodFlag.value = '0';
            }

            // Cleared/reconciled: confirm budget change before save
            if (budgetOnlyEditMode) {
                if (!isBudgetSelectionChanged()) {
                    showToast('No budget change to save.', 'info');
                    return;
                }
                const okStatus = confirm(
                    BUDGET_STATUS_WARN_MSG + '\n\nSave the new budget assignment?'
                );
                if (!okStatus) {
                    showToast('Save cancelled.', 'info');
                    return;
                }
            }

            const lines = [];
            linesBody.querySelectorAll('tr').forEach(row => {
                const accSel = row.querySelector('.line-account');
                const acc = accSel?.value;
                const { amount: amt, type: lineType } = getLineAmountAndType(row);
                if (!acc || !amt || (lineType !== 'debit' && lineType !== 'credit')) return;

                // Prefer dataset filled by syncLineCategoryLabels; fall back to selected option.
                const opt = accSel && accSel.selectedOptions ? accSel.selectedOptions[0] : null;
                const natId = row.dataset.naturalId
                    || (opt && opt.dataset.naturalId)
                    || '';
                const funId = row.dataset.functionalId
                    || (opt && opt.dataset.functionalId)
                    || '';

                lines.push({
                    account_id: acc,
                    fund_id: row.querySelector('.line-fund')?.value || '',
                    natural_category_id: natId,
                    functional_category_id: funId,
                    amount: amt,
                    type: lineType
                });
            });

            if (!budgetOnlyEditMode && lines.length < 2) {
                showToast('Every transaction must have at least 2 lines.', 'warning');
                return;
            }

            linesJson.value = JSON.stringify(lines);

            const confirmDel = confirmQueuedDocumentDeletion();
            if (!confirmDel.confirmed) {
                showToast('Save cancelled. Queued files were not deleted.', 'info');
                return;
            }
            const pendingDeletes = confirmDel.pending.slice();

            confirmReferenceReuseIfNeeded(seqVal).then(function(ok) {
                if (!ok) {
                    showToast('Save cancelled — Reference # not confirmed for reuse.', 'info');
                    return;
                }

                // Save transaction first; only then permanently delete queued attachments
                fetch('pages/ledger.php' + buildQueryString(true), {
                    method: 'POST',
                    body: new FormData(form)
                })
                    .then(r => r.text())
                    .then(html => {
                        const flashMatch = html.match(/id=["']ledger-flash["'][^>]*>([\s\S]*?)<\/script>/i);
                        let flash = null;
                        if (flashMatch) {
                            try { flash = JSON.parse(flashMatch[1].trim()); } catch (err) { flash = null; }
                        }
                        const saveOk = !!(flash && flash.type === 'success');
                        if (saveOk) markTxFormClean();

                        if (!pendingDeletes.length) {
                            applyMainContent(html);
                            return null;
                        }
                        if (!saveOk) {
                            // Keep deletion queue so user can fix validation and save again
                            applyMainContent(html);
                            showToast('Transaction was not saved; queued attachments were not deleted.', 'warning', 6000);
                            return null;
                        }
                        return executeQueuedDocumentDeletion(pendingDeletes)
                            .then(delRes => {
                                applyMainContent(html);
                                const delMsg = delRes.message || ((delRes.deleted_count || pendingDeletes.length) + ' attachment(s) deleted.');
                                showToast('Transaction saved. ' + delMsg, 'success', 6000);
                            })
                            .catch(delErr => {
                                applyMainContent(html);
                                showToast(
                                    'Transaction saved, but attachment deletion failed: '
                                        + ((delErr && delErr.message) || 'unknown error'),
                                    'warning',
                                    7000
                                );
                            });
                    })
                    .catch(err => {
                        console.error(err);
                        showToast('Save failed. See console.', 'danger');
                    });
            });
        });
    }

    // Selection: support checkboxes + Ctrl/Shift + click; plain click selects single and opens view (read-only)
    function syncSelectAllState() {
        const allCbs = txTableBody.querySelectorAll('.tx-cb');
        const checked = txTableBody.querySelectorAll('.tx-cb:checked');
        if (selectAll) {
            selectAll.checked = (allCbs.length > 0 && checked.length === allCbs.length);
        }
    }

    function afterSelectionChanged() {
        updateButtonStates();
        // Selection alone does not open the form; use View / Edit / double-click.
    }

    function loadView(id) {
        openViewForId(id);
    }

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            txTableBody.querySelectorAll('.tx-cb').forEach(cb => cb.checked = selectAll.checked);
            syncSelectAllState();
            afterSelectionChanged();
        });
    }

    if (txTableBody) {
        txTableBody.addEventListener('change', function(e) {
            if (e.target.classList.contains('tx-cb')) {
                syncSelectAllState();
                afterSelectionChanged();
            }
        });

        txTableBody.addEventListener('click', function(e) {
            const attachBtn = e.target.closest('.ledger-attach-btn');
            if (attachBtn) {
                e.preventDefault();
                e.stopPropagation();
                const attachId = parseInt(attachBtn.dataset.txId, 10);
                if (attachId) openAttachmentPortfolio(attachId);
                return;
            }
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON') return;
            const row = e.target.closest('tr');
            if (!row || !row.dataset.id) return;
            const cb = row.querySelector('.tx-cb');
            if (!cb) return;

            const allRows = Array.from(txTableBody.querySelectorAll('tr[data-id]'));
            const rowIdx = allRows.indexOf(row);

            if (e.shiftKey && lastAnchorRow) {
                const anchorIdx = allRows.indexOf(lastAnchorRow);
                if (anchorIdx !== -1) {
                    const start = Math.min(anchorIdx, rowIdx);
                    const end = Math.max(anchorIdx, rowIdx);
                    allRows.forEach((r, i) => {
                        const c = r.querySelector('.tx-cb');
                        if (c) c.checked = (i >= start && i <= end);
                    });
                }
            } else if (e.ctrlKey || e.metaKey) {
                cb.checked = !cb.checked;
            } else {
                allRows.forEach(r => {
                    const c = r.querySelector('.tx-cb');
                    if (c) c.checked = (r === row);
                });
                lastAnchorRow = row;
            }
            if (!e.shiftKey) {
                lastAnchorRow = row;
            }
            syncSelectAllState();
            afterSelectionChanged();
        });

        // Double-click row → read-only View modal (not Edit)
        txTableBody.addEventListener('dblclick', function(e) {
            if (e.target.closest('.ledger-attach-btn')) {
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON') return;
            const row = e.target.closest('tr[data-id]');
            if (!row) return;
            const id = parseInt(row.dataset.id, 10);
            if (!id) return;
            // Select this row only
            txTableBody.querySelectorAll('.tx-cb').forEach(cb => {
                cb.checked = (parseInt(cb.value, 10) === id);
            });
            lastAnchorRow = row;
            syncSelectAllState();
            afterSelectionChanged();
            openViewForId(id);
        });
    }

    function syncDocUploadBtn() {
        const docUploadBtn = getTxDocUploadBtn();
        const docFileInput = getTxDocFileInput();
        if (!docUploadBtn || !docFileInput) return;
        const inEdit = isTxEditMode();
        const hasFile = !!(docFileInput.files && docFileInput.files.length > 0);
        docUploadBtn.disabled = !(inEdit && hasFile);
    }

    /**
     * Read the currently selected File from the live file input (never a stale closure).
     * @returns {File|null}
     */
    function getSelectedDocFile() {
        const input = getTxDocFileInput();
        if (!input || !input.files || input.files.length === 0) return null;
        const f = input.files[0];
        // Guard: some browsers report a placeholder entry with empty name when nothing is chosen
        if (!f || (typeof f.size === 'number' && f.size === 0 && !f.name)) return null;
        if (!f.name) return null;
        return f;
    }

    function runDocUpload() {
        const id = txIdField && txIdField.value ? String(txIdField.value).trim() : '';
        const docUploadBtn = getTxDocUploadBtn();
        if (!isTxEditMode()) {
            showToast('Enter edit mode to upload documents.', 'warning');
            return;
        }
        if (!id) {
            showToast('Save the transaction before uploading documents.', 'warning');
            return;
        }
        const file = getSelectedDocFile();
        if (!file) {
            showToast('Please select a file to upload.', 'warning');
            syncDocUploadBtn();
            return;
        }
        const fd = new FormData();
        fd.append('action', 'upload_document');
        fd.append('tx_id', id);
        // Field name matches server; third arg ensures filename is present in multipart headers
        fd.append('tx_document', file, file.name);
        if (docUploadBtn) docUploadBtn.disabled = true;
        fetch('pages/ledger.php', { method: 'POST', body: fd })
            .then(parseJsonResponse)
            .then(res => {
                if (!isApiSuccess(res)) {
                    showToast(res.error || 'Upload failed.', 'danger');
                    syncDocUploadBtn();
                    return;
                }
                showToast(res.message || 'Upload Successful', 'success');
                clearDocFileSelection();
                // Prefer server-returned documents list for instant refresh (preserve delete queue)
                if (Array.isArray(res.documents)) {
                    if (currentViewData) currentViewData.documents = res.documents;
                    renderDocumentsList(res.documents, true);
                    updateRowAttachmentIndicator(id, res.documents.length);
                    const docForm = document.getElementById('txDocUploadForm');
                    if (docForm) docForm.classList.remove('d-none');
                    syncDocUploadBtn();
                } else {
                    refreshDocumentsFromServer(id, true)
                        .catch(() => showToast('Uploaded, but failed to refresh document list.', 'warning'))
                        .finally(() => syncDocUploadBtn());
                }
            })
            .catch(err => {
                console.error(err);
                showToast('Upload failed.', 'danger');
                syncDocUploadBtn();
            });
    }

    // Live binding on the modal (survives reparent to body; no stale element refs)
    if (txFormModalEl) {
        txFormModalEl.addEventListener('change', function(e) {
            if (e.target && e.target.id === 'txDocFile') {
                syncDocUploadBtn();
            }
        });
        txFormModalEl.addEventListener('click', function(e) {
            const btn = e.target && e.target.closest ? e.target.closest('#txDocUploadBtn') : null;
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            runDocUpload();
        });
    } else {
        // Fallback if modal node missing at init
        const docFileInput = getTxDocFileInput();
        const docUploadBtn = getTxDocUploadBtn();
        if (docFileInput) docFileInput.addEventListener('change', syncDocUploadBtn);
        if (docUploadBtn) docUploadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            runDocUpload();
        });
    }

    bindDocumentListActions();

    // ── Import from Text (Beancount-style paste → form only; no DB writes) ──
    function normLookupKey(s) {
        return String(s || '')
            .trim()
            .toLowerCase()
            .replace(/\s+/g, ' ');
    }

    function stripBeancountPath(accountName) {
        // Allow Assets:Bank Account or Bank Account — prefer full string, then last segment
        const raw = String(accountName || '').trim();
        if (!raw.includes(':')) return [raw];
        const parts = raw.split(':').map(p => p.trim()).filter(Boolean);
        const last = parts.length ? parts[parts.length - 1] : raw;
        return last && last !== raw ? [raw, last] : [raw];
    }

    function resolveByName(list, rawName, { alsoCode = false, label = 'account' } = {}) {
        const candidates = stripBeancountPath(rawName);
        for (const cand of candidates) {
            const key = normLookupKey(cand);
            if (!key) continue;
            const exact = [];
            const codeHits = [];
            for (const item of list) {
                if (normLookupKey(item.name) === key) exact.push(item);
                if (alsoCode && item.code && normLookupKey(item.code) === key) codeHits.push(item);
            }
            if (exact.length === 1) return { ok: true, item: exact[0] };
            if (exact.length > 1) {
                return {
                    ok: false,
                    error: 'Ambiguous ' + label + ' "' + cand + '" matches: ' + exact.map(i => i.name).join(', ')
                };
            }
            if (alsoCode && codeHits.length === 1) return { ok: true, item: codeHits[0] };
            if (alsoCode && codeHits.length > 1) {
                return {
                    ok: false,
                    error: 'Ambiguous ' + label + ' code "' + cand + '" matches: ' + codeHits.map(i => i.name).join(', ')
                };
            }
        }
        // Fuzzy: name contains or is contained (only if unique)
        const primary = normLookupKey(candidates[candidates.length - 1] || rawName);
        if (primary && primary.length >= 3) {
            const fuzzy = list.filter(item => {
                const n = normLookupKey(item.name);
                return n.includes(primary) || primary.includes(n);
            });
            if (fuzzy.length === 1) return { ok: true, item: fuzzy[0], fuzzy: true };
            if (fuzzy.length > 1) {
                return {
                    ok: false,
                    error: 'Ambiguous ' + label + ' "' + rawName + '" — matches: ' + fuzzy.map(i => i.name).join(', ')
                        + '. Use the exact name from the chart.'
                };
            }
        }
        return {
            ok: false,
            error: 'Unknown ' + label + ' "' + String(rawName || '').trim()
                + '". Names must match existing records in the system.'
        };
    }

    function parseQuotedTokens(rest) {
        // Extract "..." strings and leftover unquoted text from txn header remainder
        const quotes = [];
        let i = 0;
        let unquoted = '';
        const s = String(rest || '');
        while (i < s.length) {
            const ch = s[i];
            if (ch === '"' || ch === "'") {
                const q = ch;
                i++;
                let buf = '';
                while (i < s.length && s[i] !== q) {
                    if (s[i] === '\\' && i + 1 < s.length) {
                        buf += s[i + 1];
                        i += 2;
                        continue;
                    }
                    buf += s[i];
                    i++;
                }
                if (i < s.length && s[i] === q) i++;
                quotes.push(buf);
            } else {
                unquoted += ch;
                i++;
            }
        }
        return { quotes, unquoted: unquoted.trim() };
    }

    function parseAmountToken(raw) {
        // Accept: -87.43, 87.43, $87.43, 87.43 USD, USD -87.43, 1,234.56
        let s = String(raw || '').trim();
        if (!s) return null;
        s = s.replace(/^(USD|usd|\$)\s*/, '').replace(/\s*(USD|usd|\$)$/, '').trim();
        s = s.replace(/,/g, '');
        if (!/^-?\d+(\.\d+)?$/.test(s)) return null;
        const n = parseFloat(s);
        if (!isFinite(n) || n === 0) return null;
        return n;
    }

    /**
     * Parse one Beancount-style transaction from pasted text.
     * Positive amount → debit; negative → credit (sums to zero when balanced).
     * Read-only: never writes to the database.
     */
    function parseBeancountImportText(text) {
        const errors = [];
        const warnings = [];
        const raw = String(text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        if (!raw.trim()) {
            return { ok: false, errors: ['Paste is empty. Paste a Beancount-style transaction and try again.'] };
        }

        const lines = raw.split('\n');
        let headerIdx = -1;
        let headerMatch = null;
        // Note: do not use \b after * or ! — those are non-word chars so \b fails before a space.
        const txnHeaderRe = /^(\d{4}-\d{2}-\d{2})\s+(\*|\!|txn)(?:\s+(.*))?$/i;

        for (let i = 0; i < lines.length; i++) {
            const trimmed = lines[i].trim();
            if (!trimmed || trimmed.startsWith(';')) continue;
            const m = trimmed.match(txnHeaderRe);
            if (m) {
                headerIdx = i;
                headerMatch = m;
                break;
            }
        }

        // Fallback: date-only first non-empty line (without flag)
        if (!headerMatch) {
            for (let i = 0; i < lines.length; i++) {
                const trimmed = lines[i].trim();
                if (!trimmed || trimmed.startsWith(';')) continue;
                const m2 = trimmed.match(/^(\d{4}-\d{2}-\d{2})(?:\s+(.*))?$/);
                if (m2) {
                    headerIdx = i;
                    headerMatch = [m2[0], m2[1], '*', m2[2] || ''];
                    warnings.push('Line ' + (i + 1) + ': missing flag (* or !); treated as complete (*).');
                    break;
                }
                errors.push('Line ' + (i + 1) + ': expected transaction header starting with YYYY-MM-DD (e.g. 2026-03-15 * "Payee" "Description").');
                return { ok: false, errors };
            }
        }

        if (!headerMatch) {
            return {
                ok: false,
                errors: [
                    'No transaction header found. First line should look like:',
                    '2026-03-15 * "Payee" "Description"'
                ]
            };
        }

        const dateStr = headerMatch[1];
        if (!/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
            errors.push('Invalid date "' + dateStr + '". Use YYYY-MM-DD.');
        } else {
            const [yy, mm, dd] = dateStr.split('-').map(Number);
            const dt = new Date(Date.UTC(yy, mm - 1, dd));
            if (dt.getUTCFullYear() !== yy || dt.getUTCMonth() !== mm - 1 || dt.getUTCDate() !== dd) {
                errors.push('Invalid calendar date "' + dateStr + '".');
            }
        }

        // Pay To = first quoted string after date/flag; Description = second quoted string
        const { quotes, unquoted } = parseQuotedTokens(headerMatch[3] || '');
        let payTo = quotes.length >= 1 ? quotes[0] : '';
        let description = quotes.length >= 2 ? quotes[1] : '';
        // Unquoted remainder only if no quoted strings at all (loose pastes)
        if (!payTo && !description && unquoted) {
            description = unquoted;
        }

        // Harvest ; comments on the header line into description extras
        const descParts = [];
        const headerRaw = lines[headerIdx] || '';
        const headerSemi = headerRaw.indexOf(';');
        if (headerSemi >= 0) {
            const hc = headerRaw.slice(headerSemi + 1).trim();
            if (hc) descParts.push(hc);
        }

        // Scan for a second transaction header (only first is imported)
        for (let i = headerIdx + 1; i < lines.length; i++) {
            const t = lines[i].trim();
            if (txnHeaderRe.test(t) || /^\d{4}-\d{2}-\d{2}\s+(\*|\!|txn)(?:\s|$)/i.test(t)) {
                warnings.push('Additional transaction header at line ' + (i + 1) + ' ignored. Import one transaction at a time.');
                lines.length = i; // stop processing at second header
                break;
            }
        }

        // Only these metadata keys populate the form; all others are ignored
        let referenceVal = '';
        let checkVal = '';
        const postings = [];
        // Amount: optional currency around a number (possibly with thousands commas)
        const amountRe = /(?:USD|\$)?\s*(-?\d{1,3}(?:,\d{3})+(?:\.\d+)?|-?\d+(?:\.\d+)?)\s*(?:USD|\$)?/gi;

        /**
         * Pull fund: hint out of a ; comment; return remaining text for description.
         * @returns {{ fundHint: string, descText: string }}
         */
        function splitFundAndDesc(comment) {
            let descText = String(comment || '').trim();
            if (!descText) return { fundHint: '', descText: '' };
            const m = descText.match(/fund\s*:\s*(.+)$/i);
            if (m) {
                const fundHint = m[1].trim().replace(/^["']|["']$/g, '');
                descText = descText.replace(/;?\s*fund\s*:\s*.+$/i, '').trim();
                return { fundHint, descText };
            }
            return { fundHint: '', descText };
        }

        function stripMetaQuotes(val) {
            let v = String(val || '').trim();
            const qv = v.match(/^["'](.*)["']$/);
            if (qv) v = qv[1];
            return v.trim();
        }

        for (let i = headerIdx + 1; i < lines.length; i++) {
            const lineNo = i + 1;
            let line = lines[i];
            if (line.trim() === '') continue;

            // Full-line comment → description extras (do not skip)
            if (line.trim().startsWith(';')) {
                const c = line.trim().slice(1).trim();
                if (c) {
                    const { fundHint: _fh, descText } = splitFundAndDesc(c);
                    if (descText) descParts.push(descText);
                    // bare "; fund: X" full-line is not a posting fund (no account); ignore fund
                }
                continue;
            }

            // Split inline comment
            let comment = '';
            const semi = line.indexOf(';');
            if (semi >= 0) {
                comment = line.slice(semi + 1).trim();
                line = line.slice(0, semi);
            }

            const trimmed = line.trim();
            if (!trimmed) {
                // comment-only after stripping empty code part
                if (comment) {
                    const { descText } = splitFundAndDesc(comment);
                    if (descText) descParts.push(descText);
                }
                continue;
            }

            // Metadata BEFORE amount matching — values like reference: "260150" contain
            // digits that would otherwise be misread as posting amounts.
            // reference: / ref: / sequence: and check: populate the form; other key: value lines are ignored.
            // Shape: single-token key + colon (not "Assets:Bank Account  10" postings).
            const metaLineM = trimmed.match(/^([A-Za-z_][A-Za-z0-9_-]*)\s*:\s*(.*)$/);
            if (metaLineM) {
                const key = metaLineM[1].toLowerCase();
                const rhs = metaLineM[2];
                // Postings with Beancount paths look like "Assets:Bank Account  10.00" —
                // those have spaces + amount after the first segment; detect via trailing amount
                // after additional account text. Pure metadata RHS has no "Name  amount" tail.
                const rhsHasAccountAmount = /\S.+\s+(?:USD|\$)?\s*-?\d/.test(rhs)
                    || /\s+(?:USD|\$)?\s*-?\d.+\s+(?:USD|\$)?\s*-?\d/.test(trimmed);
                // If RHS is only a value (quoted, number, words without amount-as-posting), treat as meta
                const rhsTrim = String(rhs || '').trim();
                const rhsIsSimpleValue = rhsTrim === ''
                    || /^["']/.test(rhsTrim)
                    || /^-?\d[\d,]*(\.\d+)?\s*(USD|\$)?$/i.test(rhsTrim)
                    || !/\s+-?\d/.test(rhsTrim);
                if (!rhsHasAccountAmount && rhsIsSimpleValue) {
                    if (key === 'reference' || key === 'ref' || key === 'sequence') {
                        referenceVal = stripMetaQuotes(rhsTrim);
                    } else if (key === 'check') {
                        checkVal = stripMetaQuotes(rhsTrim);
                    }
                    // else: ignore unknown metadata keys (memo, natural, etc.)
                    if (comment) {
                        const { descText } = splitFundAndDesc(comment);
                        if (descText) descParts.push(descText);
                    }
                    continue;
                }
                // else fall through — e.g. Assets:Cash  10.00 parsed as posting below
            }

            // Posting: account … amount [currency]
            amountRe.lastIndex = 0;
            let lastAmt = null;
            let m;
            while ((m = amountRe.exec(trimmed)) !== null) {
                lastAmt = m;
            }

            if (!lastAmt) {
                errors.push('Line ' + lineNo + ': no amount found. Expected e.g. "Bank Account  -87.43" or "Accounts Payable  87.43 USD".');
                continue;
            }

            const amountRaw = lastAmt[0];
            const amount = parseAmountToken(amountRaw);
            if (amount === null) {
                errors.push('Line ' + lineNo + ': invalid or zero amount "' + amountRaw.trim() + '".');
                continue;
            }

            let accountName = trimmed.slice(0, lastAmt.index).trim();
            // Drop trailing cost/price braces if any slipped in before amount
            accountName = accountName.replace(/\s*\{[^}]*\}\s*$/, '').trim();
            // Guard: bare "invoice: 123" style that slipped through → ignore as non-posting
            if (/^[A-Za-z_][A-Za-z0-9_-]*\s*:$/.test(accountName) || accountName === '') {
                if (!accountName) {
                    errors.push('Line ' + lineNo + ': missing account name before amount.');
                }
                // key: 123 alone — ignore (unknown meta with numeric value already handled above)
                continue;
            }

            const { fundHint, descText } = splitFundAndDesc(comment);
            if (descText) descParts.push(descText);

            postings.push({
                lineNo,
                accountName,
                amount,
                fundHint
            });
        }

        if (postings.length < 2) {
            errors.push(
                postings.length === 0
                    ? 'No posting lines found. Add at least two lines with account and amount under the header.'
                    : 'Only ' + postings.length + ' posting found. Double-entry requires at least 2 lines.'
            );
        }

        // Resolve accounts / funds
        const resolvedLines = [];
        let sum = 0;
        for (const p of postings) {
            const accRes = resolveByName(ledgerImportLookups.accounts, p.accountName);
            if (!accRes.ok) {
                errors.push('Line ' + p.lineNo + ': ' + accRes.error);
                continue;
            }
            if (accRes.fuzzy) {
                warnings.push('Line ' + p.lineNo + ': account "' + p.accountName + '" matched as "' + accRes.item.name + '" (fuzzy).');
            }

            let fundId = '';
            if (p.fundHint) {
                const fRes = resolveByName(ledgerImportLookups.funds, p.fundHint, {
                    alsoCode: true,
                    label: 'fund'
                });
                if (!fRes.ok) {
                    errors.push('Line ' + p.lineNo + ': ' + fRes.error + ' Use fund name or code (e.g. GOF).');
                } else {
                    fundId = fRes.item.id;
                }
            }

            const type = p.amount > 0 ? 'debit' : 'credit';
            const absAmt = Math.abs(p.amount);
            sum += p.amount;

            resolvedLines.push({
                account_id: accRes.item.id,
                fund_id: fundId,
                // Categories follow the account (labels sync on row create); server re-resolves on save
                natural_category_id: accRes.item.natural_category_id || '',
                functional_category_id: accRes.item.functional_category_id || '',
                amount: absAmt.toFixed(2),
                type
            });
        }

        const ref = String(referenceVal || '').trim();
        const check = String(checkVal || '').trim();
        // Append ; comments (fund: hints excluded) to Description with spaces (no " | ")
        const commentDesc = descParts
            .map(p => String(p || '').trim())
            .filter(Boolean)
            .join(' ')
            .replace(/\s+/g, ' ')
            .trim();
        let finalDescription = String(description || '').trim();
        if (commentDesc) {
            finalDescription = finalDescription
                ? (finalDescription + ' ' + commentDesc)
                : commentDesc;
        }

        if (ref && !/^\d{6}$/.test(ref)) {
            warnings.push('Reference "' + ref + '" is not YY#### (6 digits). It was filled anyway — correct it before save if needed.');
        }

        if (errors.length) {
            return { ok: false, errors, warnings };
        }

        // Balance check only after all postings resolved cleanly
        if (Math.abs(sum) >= 0.005) {
            warnings.push(
                'Postings do not balance (signed sum = ' + sum.toFixed(2) + '). '
                + 'The form will show the imbalance; fix amounts before saving.'
            );
        }

        if (resolvedLines.length < 2) {
            return {
                ok: false,
                errors: ['Fewer than 2 valid postings after account resolution. Double-entry requires at least 2 lines.'],
                warnings
            };
        }

        return {
            ok: true,
            warnings,
            data: {
                transaction_date: dateStr,
                pay_to: payTo,
                description: finalDescription,
                check_number: check,
                reference_number: ref,
                lines: resolvedLines
            }
        };
    }

    function clearImportTextFeedback() {
        if (importTextErrors) importTextErrors.classList.add('d-none');
        if (importTextErrorList) importTextErrorList.innerHTML = '';
        if (importTextWarnings) importTextWarnings.classList.add('d-none');
        if (importTextWarningList) importTextWarningList.innerHTML = '';
    }

    function clearImportTextArea() {
        const area = document.getElementById('importTextArea') || importTextArea;
        if (area) area.value = '';
    }

    function showImportTextErrors(msgs) {
        if (!importTextErrors || !importTextErrorList) return;
        importTextErrorList.innerHTML = (msgs || []).map(m => '<li>' + escHtml(String(m)) + '</li>').join('');
        importTextErrors.classList.toggle('d-none', !(msgs && msgs.length));
    }

    function showImportTextWarnings(msgs) {
        if (!importTextWarnings || !importTextWarningList) return;
        importTextWarningList.innerHTML = (msgs || []).map(m => '<li>' + escHtml(String(m)) + '</li>').join('');
        importTextWarnings.classList.toggle('d-none', !(msgs && msgs.length));
    }

    function applyImportToAddForm(data, warnings) {
        // Form must already be in Add mode; populate fields + lines only
        document.getElementById('transaction_date').value = data.transaction_date || '';
        if (data.reference_number) {
            if (refInput) refInput.value = data.reference_number;
        }
        document.getElementById('pay_to').value = data.pay_to || '';
        document.getElementById('check_number').value = data.check_number || '';
        document.getElementById('description').value = data.description || '';

        // Budget follows transaction date (auto mode on Add)
        budgetAutoMode = true;
        applyBudgetForDate(data.transaction_date || '', { force: true });
        clearReferenceReuseState();
        refreshReferenceSuggestion().then(updateReferenceHintVisibility);
        updateReferenceHintVisibility();
        checkReferenceReuseLive();

        linesBody.innerHTML = '';
        const lines = data.lines || [];
        if (lines.length > 0) {
            lines.forEach(l => {
                const row = createLineRow(l);
                linesBody.appendChild(row);
                attachLineListeners(row);
            });
        } else {
            addLine();
            addLine();
        }
        recalcTotals();
        if (form) form.setAttribute('data-dirty', '1');

        // Clear paste box after successful populate, then close modal
        clearImportTextArea();
        clearImportTextFeedback();

        if (importTextModalEl && typeof bootstrap !== 'undefined') {
            const modal = bootstrap.Modal.getInstance(importTextModalEl);
            if (modal) modal.hide();
        }

        const n = lines.length;
        let msg = 'Form populated from text (' + n + ' line' + (n === 1 ? '' : 's') + '). Review and Save when ready — nothing was written to the database yet.';
        if (warnings && warnings.length) {
            msg += ' (' + warnings.length + ' warning' + (warnings.length === 1 ? '' : 's') + ')';
            showToast(msg, 'warning');
        } else {
            showToast(msg, 'success');
        }
    }

    function openImportTextModal() {
        // Re-resolve in case a prior page left a body-mounted node
        importTextModalEl = document.getElementById('importTextModal') || importTextModalEl;
        if (!importTextModalEl || typeof bootstrap === 'undefined') return;

        clearImportTextFeedback();
        mountModalOnBody(importTextModalEl);

        // Focus textarea only after the modal is shown (avoids focusing while aria-hidden)
        const onShown = function() {
            importTextModalEl.removeEventListener('shown.bs.modal', onShown);
            const area = document.getElementById('importTextArea') || importTextArea;
            if (area) {
                try {
                    area.focus();
                    // Place caret at end for convenience when re-opening with prior text
                    const len = area.value ? area.value.length : 0;
                    if (typeof area.setSelectionRange === 'function') {
                        area.setSelectionRange(len, len);
                    }
                } catch (e) { /* ignore */ }
            }
        };
        importTextModalEl.addEventListener('shown.bs.modal', onShown);
        showLedgerModal(importTextModalEl, { backdrop: true, keyboard: true, focus: true });
    }

    if (importTextBtn) {
        importTextBtn.addEventListener('click', () => {
            // Only on Add; button is hidden otherwise
            openImportTextModal();
        });
    }

    function runImportTextParse() {
        clearImportTextFeedback();
        // Always read live DOM (modal may have been reparented to body)
        const area = document.getElementById('importTextArea') || importTextArea;
        const text = area ? area.value : '';
        const result = parseBeancountImportText(text);
        if (!result.ok) {
            showImportTextErrors(result.errors || ['Unknown parse error.']);
            if (result.warnings && result.warnings.length) showImportTextWarnings(result.warnings);
            return;
        }
        // Successful parse clears the textarea inside applyImportToAddForm
        applyImportToAddForm(result.data, result.warnings || []);
    }

    if (importTextParseBtn) {
        importTextParseBtn.addEventListener('click', runImportTextParse);
    }
    // Keydown on modal (delegation) so it works after reparent to body
    if (importTextModalEl) {
        importTextModalEl.addEventListener('keydown', (ev) => {
            const t = ev.target;
            if (!t || t.id !== 'importTextArea') return;
            if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter') {
                ev.preventDefault();
                runImportTextParse();
            }
        });
        importTextModalEl.addEventListener('hidden.bs.modal', () => {
            // Cancel / close / backdrop: clear paste + errors so next open is clean
            clearImportTextArea();
            clearImportTextFeedback();
        });
    }

    // Initial state
    updateButtonStates();
    showBlankForm();
    wireLedgerFiltersAndSort();

})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-ledger-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>

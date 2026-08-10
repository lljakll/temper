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
     * Build ledger list WHERE clause + bind params from request filters.
     * Used by HTML first paint and JSON infinite-scroll endpoint.
     *
     * @return array{conditions: string[], bind_params: array, bind_types: string, filter_account_id: int, view_normal: string, filters: array}
     */
    $ledgerBuildListFilters = static function (mysqli $db, array $src): array {
        $date_from = trim((string)($src['date_from'] ?? ''));
        $date_to = trim((string)($src['date_to'] ?? ''));
        $reference = trim((string)($src['reference'] ?? $src['reference_number'] ?? ''));
        $description = trim((string)($src['description'] ?? ''));
        $pay_to = trim((string)($src['pay_to'] ?? ''));
        $check_number = trim((string)($src['check_number'] ?? ''));
        $search = trim((string)($src['search'] ?? ''));
        $status = strtolower(trim((string)($src['status'] ?? '')));
        $amount = trim((string)($src['amount'] ?? ''));
        $amount_min = trim((string)($src['amount_min'] ?? ''));
        $amount_max = trim((string)($src['amount_max'] ?? ''));

        $filter_account_id = isset($src['account_id']) ? (int)$src['account_id'] : 0;
        if ($filter_account_id < 0) {
            $filter_account_id = 0;
        }
        $filter_fund_id = isset($src['fund_id']) ? (int)$src['fund_id'] : 0;
        if ($filter_fund_id < 0) {
            $filter_fund_id = 0;
        }

        $allowedStatus = ['pending', 'cleared', 'reconciled'];
        if ($status !== '' && !in_array($status, $allowedStatus, true)) {
            $status = '';
        }

        $conditions = [];
        $bind_params = [];
        $bind_types = '';

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
        if ($reference !== '') {
            $like = '%' . $reference . '%';
            $conditions[] = 'td.reference_number LIKE ?';
            $bind_params[] = $like;
            $bind_types .= 's';
        }
        if ($description !== '') {
            $like = '%' . $description . '%';
            $conditions[] = 'td.description LIKE ?';
            $bind_params[] = $like;
            $bind_types .= 's';
        }
        if ($pay_to !== '') {
            $like = '%' . $pay_to . '%';
            $conditions[] = 'td.pay_to LIKE ?';
            $bind_params[] = $like;
            $bind_types .= 's';
        }
        if ($check_number !== '') {
            $like = '%' . $check_number . '%';
            $conditions[] = 'td.check_number LIKE ?';
            $bind_params[] = $like;
            $bind_types .= 's';
        }
        if ($status !== '') {
            $conditions[] = 'td.status = ?';
            $bind_params[] = $status;
            $bind_types .= 's';
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $conditions[] = '(td.pay_to LIKE ? OR td.reference_number LIKE ? OR td.check_number LIKE ? OR td.description LIKE ? OR CAST(COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id), 0) AS CHAR) LIKE ?)';
            $bind_params = array_merge($bind_params, [$like, $like, $like, $like, $like]);
            $bind_types .= str_repeat('s', 5);
        }
        if ($filter_account_id > 0) {
            $conditions[] = 'EXISTS (SELECT 1 FROM transaction_lines tl WHERE tl.transaction_detail_id = td.id AND tl.account_id = ?)';
            $bind_params[] = $filter_account_id;
            $bind_types .= 'i';
        }
        if ($filter_fund_id > 0) {
            $conditions[] = 'EXISTS (SELECT 1 FROM transaction_lines tl WHERE tl.transaction_detail_id = td.id AND tl.fund_id = ?)';
            $bind_params[] = $filter_fund_id;
            $bind_types .= 'i';
        }

        // Amount filters against total line amounts (absolute sum of lines) and debit/credit totals
        $totalExpr = 'COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id), 0)';
        $debitExpr = "COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id AND type='debit'), 0)";
        $creditExpr = "COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id AND type='credit'), 0)";

        if ($amount !== '') {
            $like = '%' . $amount . '%';
            $conditions[] = "(CAST($totalExpr AS CHAR) LIKE ? OR CAST($debitExpr AS CHAR) LIKE ? OR CAST($creditExpr AS CHAR) LIKE ?)";
            $bind_params = array_merge($bind_params, [$like, $like, $like]);
            $bind_types .= 'sss';
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
                'date_from' => $date_from,
                'date_to' => $date_to,
                'reference' => $reference,
                'description' => $description,
                'pay_to' => $pay_to,
                'check_number' => $check_number,
                'search' => $search,
                'status' => $status,
                'amount' => $amount,
                'amount_min' => $amount_min,
                'amount_max' => $amount_max,
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
            if (empty($_FILES['document']) || (int)($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
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
                $_FILES['document']
            );
            if (!empty($result['success'])) {
                $origName = basename((string)($_FILES['document']['name'] ?? 'file'));
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
    $filter_account_id = (int)$list_page['filter_account_id'];
    $filter_fund_id = (int)($active_filters['fund_id'] ?? 0);
    $dropdown_selected = $filter_account_id;

    // Curated accounts for Account filter dropdown: exclude revenue accounts like Contributions.
    // Ordered by CoA number ascending (null/empty CoA at end), then name, then id.
    $view_accts = [];
    $vaq = $db->query("SELECT id, name, normal_balance, coa_number FROM accounts WHERE archived=FALSE ORDER BY (coa_number IS NULL OR coa_number = '') ASC, coa_number ASC, name ASC, id ASC");
    if ($vaq) {
        while ($va = $vaq->fetch_assoc()) {
            if (stripos($va['name'], 'contribution') !== false) continue;
            $view_accts[] = $va;
        }
    }

    // Funds for fund filter dropdown
    $view_funds = [];
    $vfq = $db->query("SELECT id, name, code FROM funds WHERE is_active=TRUE AND archived=FALSE ORDER BY name ASC, id ASC");
    if ($vfq) {
        while ($vf = $vfq->fetch_assoc()) {
            $view_funds[] = $vf;
        }
    }

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

    $hasActiveFilters = false;
    foreach ($active_filters as $fk => $fv) {
        if ($fk === 'account_id' || $fk === 'fund_id') {
            if ((int)$fv > 0) { $hasActiveFilters = true; break; }
        } elseif ($fv !== '' && $fv !== null) {
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
        <button type="button" id="clearAllFiltersBtn" class="btn btn-outline-secondary btn-sm ms-md-2<?= $hasActiveFilters ? '' : ' d-none' ?>" title="Clear all column filters">
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
                    <table class="table table-sm table-hover mb-0 align-middle ledger-tx-table" id="ledgerTxTable" style="min-width: 980px;">
                        <thead class="table-dark ledger-sticky-head">
                            <tr class="ledger-col-titles">
                                <th style="width:28px" class="ledger-th-check">
                                    <input type="checkbox" id="selectAll" class="form-check-input" title="Select all loaded">
                                </th>
<?php
$colDefs = [
    ['key' => 'date', 'label' => 'Date', 'filter' => 'date', 'class' => 'text-nowrap'],
    ['key' => 'reference', 'label' => 'Ref #', 'filter' => 'text', 'class' => 'text-nowrap'],
    ['key' => 'pay_to', 'label' => 'Pay To', 'filter' => 'text', 'class' => ''],
    ['key' => 'description', 'label' => 'Description', 'filter' => 'text', 'class' => ''],
    ['key' => 'account', 'label' => 'Account', 'filter' => 'account', 'class' => ''],
    ['key' => 'fund', 'label' => 'Fund', 'filter' => 'fund', 'class' => ''],
    ['key' => 'amount', 'label' => 'Amount', 'filter' => 'amount', 'class' => 'text-end text-nowrap', 'title' => 'Debit / Credit amounts'],
    ['key' => 'status', 'label' => 'Status', 'filter' => 'status', 'class' => ''],
];
foreach ($colDefs as $col):
    $ck = $col['key'];
    $isSorted = ($list_sort === $ck || ($ck === 'amount' && in_array($list_sort, ['amount', 'debit', 'credit'], true)) || ($ck === 'reference' && in_array($list_sort, ['reference', 'ref'], true)));
    $sortIcon = '';
    if ($isSorted) {
        $sortIcon = $list_sort_dir === 'asc' ? ' ↑' : ' ↓';
    }
    $filterActive = false;
    if ($ck === 'date') {
        $filterActive = ($active_filters['date_from'] ?? '') !== '' || ($active_filters['date_to'] ?? '') !== '';
    } elseif ($ck === 'reference') {
        $filterActive = ($active_filters['reference'] ?? '') !== '';
    } elseif ($ck === 'pay_to') {
        $filterActive = ($active_filters['pay_to'] ?? '') !== '';
    } elseif ($ck === 'description') {
        $filterActive = ($active_filters['description'] ?? '') !== '';
    } elseif ($ck === 'account') {
        $filterActive = (int)($active_filters['account_id'] ?? 0) > 0;
    } elseif ($ck === 'fund') {
        $filterActive = (int)($active_filters['fund_id'] ?? 0) > 0;
    } elseif ($ck === 'amount') {
        $filterActive = ($active_filters['amount'] ?? '') !== '' || ($active_filters['amount_min'] ?? '') !== '' || ($active_filters['amount_max'] ?? '') !== '';
    } elseif ($ck === 'status') {
        $filterActive = ($active_filters['status'] ?? '') !== '';
    }
?>
                                <th class="ledger-th-filter <?= htmlspecialchars($col['class']) ?><?= $filterActive ? ' ledger-filter-active' : '' ?>"
                                    data-col="<?= htmlspecialchars($ck) ?>"
                                    data-filter-type="<?= htmlspecialchars($col['filter']) ?>"
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
                                            <div class="dropdown-menu dropdown-menu-end p-2 shadow ledger-filter-menu" style="min-width:14rem;">
                                                <div class="small text-muted mb-1 fw-semibold"><?= htmlspecialchars($col['label']) ?> filter</div>
<?php if ($col['filter'] === 'date'): ?>
                                                <label class="form-label small mb-0">From</label>
                                                <input type="date" class="form-control form-control-sm mb-1 ledger-f-date-from" value="<?= htmlspecialchars($active_filters['date_from'] ?? '') ?>" data-dirty-ignore>
                                                <label class="form-label small mb-0">To</label>
                                                <input type="date" class="form-control form-control-sm mb-2 ledger-f-date-to" value="<?= htmlspecialchars($active_filters['date_to'] ?? '') ?>" data-dirty-ignore>
<?php elseif ($col['filter'] === 'text'): ?>
                                                <input type="search" class="form-control form-control-sm mb-2 ledger-f-text" placeholder="Contains…"
                                                       value="<?= htmlspecialchars(
                                                           $ck === 'reference' ? ($active_filters['reference'] ?? '')
                                                           : ($ck === 'pay_to' ? ($active_filters['pay_to'] ?? '')
                                                           : ($ck === 'description' ? ($active_filters['description'] ?? '') : ''))
                                                       ) ?>" data-dirty-ignore autocomplete="off">
<?php elseif ($col['filter'] === 'account'): ?>
                                                <select class="form-select form-select-sm mb-2 ledger-f-account" data-dirty-ignore>
                                                    <option value="0">All accounts</option>
<?php foreach ($view_accts as $va): $vid = (int)$va['id']; ?>
                                                    <option value="<?= $vid ?>" <?= $vid === $filter_account_id ? 'selected' : '' ?>><?= htmlspecialchars($va['name']) ?></option>
<?php endforeach; ?>
                                                </select>
<?php elseif ($col['filter'] === 'fund'): ?>
                                                <select class="form-select form-select-sm mb-2 ledger-f-fund" data-dirty-ignore>
                                                    <option value="0">All funds</option>
<?php foreach ($view_funds as $vf): $fid = (int)$vf['id']; ?>
                                                    <option value="<?= $fid ?>" <?= $fid === $filter_fund_id ? 'selected' : '' ?>><?= htmlspecialchars($vf['name'] . (!empty($vf['code']) ? ' (' . $vf['code'] . ')' : '')) ?></option>
<?php endforeach; ?>
                                                </select>
<?php elseif ($col['filter'] === 'amount'): ?>
                                                <label class="form-label small mb-0">Contains</label>
                                                <input type="search" class="form-control form-control-sm mb-1 ledger-f-amount" placeholder="e.g. 125.00"
                                                       value="<?= htmlspecialchars($active_filters['amount'] ?? '') ?>" data-dirty-ignore autocomplete="off">
                                                <div class="row g-1 mb-2">
                                                    <div class="col-6">
                                                        <label class="form-label small mb-0">Min debit</label>
                                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm ledger-f-amount-min"
                                                               value="<?= htmlspecialchars($active_filters['amount_min'] ?? '') ?>" data-dirty-ignore>
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label small mb-0">Max debit</label>
                                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm ledger-f-amount-max"
                                                               value="<?= htmlspecialchars($active_filters['amount_max'] ?? '') ?>" data-dirty-ignore>
                                                    </div>
                                                </div>
<?php elseif ($col['filter'] === 'status'): ?>
                                                <select class="form-select form-select-sm mb-2 ledger-f-status" data-dirty-ignore>
                                                    <option value="">All statuses</option>
                                                    <option value="pending" <?= ($active_filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="cleared" <?= ($active_filters['status'] ?? '') === 'cleared' ? 'selected' : '' ?>>Cleared</option>
                                                    <option value="reconciled" <?= ($active_filters['status'] ?? '') === 'reconciled' ? 'selected' : '' ?>>Reconciled</option>
                                                </select>
<?php endif; ?>
                                                <div class="d-flex gap-1">
                                                    <button type="button" class="btn btn-sm btn-primary flex-grow-1 ledger-f-apply">Apply</button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary ledger-f-clear">Clear</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </th>
<?php endforeach; ?>
                                <th class="text-center" style="width:3rem" title="Line count">#</th>
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
                                    <tr data-id="<?= $tid ?>" data-cleared="<?= $isCleared ? '1' : '0' ?>" data-status="<?= htmlspecialchars($r['status']) ?>" data-debits="<?= htmlspecialchars((string)$debAmt) ?>" data-credits="<?= htmlspecialchars((string)$credAmt) ?>">
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
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr class="ledger-empty-row">
                                    <td colspan="10" class="text-center text-muted py-4">No transactions match the current filters.<?= $canWriteLedger ? ' Use “Add Transaction” to create one.' : '' ?></td>
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
                        <!-- Not a nested <form> (invalid inside #txForm); button-driven upload -->
                        <div id="txDocUploadForm" class="d-flex flex-column flex-sm-row gap-2 align-items-stretch align-items-sm-center mb-3 d-none">
                            <input type="file" id="txDocFile" class="form-control form-control-sm" name="document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            <button type="button" id="txDocUploadBtn" class="btn btn-outline-secondary btn-sm text-nowrap" disabled>Upload</button>
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
                    Metadata lines recognized: <code>reference:</code> and <code>check:</code> only (others ignored).
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
            listState.filters = raw.filters || {};
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

    function getActiveFilters() {
        // Prefer live state; fall back to listState.filters
        return Object.assign({}, listState.filters || {});
    }

    function buildFilterParams(includeSort = true, offset = null, limit = null) {
        const p = new URLSearchParams();
        const f = getActiveFilters();
        const setIf = (k, v) => {
            if (v === undefined || v === null || v === '') return;
            if ((k === 'account_id' || k === 'fund_id') && (String(v) === '0' || v === 0)) return;
            p.set(k, String(v));
        };
        setIf('date_from', f.date_from);
        setIf('date_to', f.date_to);
        setIf('reference', f.reference);
        setIf('description', f.description);
        setIf('pay_to', f.pay_to);
        setIf('check_number', f.check_number);
        setIf('search', f.search);
        setIf('status', f.status);
        setIf('amount', f.amount);
        setIf('amount_min', f.amount_min);
        setIf('amount_max', f.amount_max);
        setIf('account_id', f.account_id);
        setIf('fund_id', f.fund_id);
        if (includeSort) {
            if (listState.sort && listState.sort !== 'date') p.set('sort', listState.sort);
            if (listState.sort_dir && listState.sort_dir !== 'desc') p.set('sort_dir', listState.sort_dir);
            // Always send sort for determinism when non-default
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
        for (const k of Object.keys(f)) {
            const v = f[k];
            if (k === 'account_id' || k === 'fund_id') {
                if (parseInt(v, 10) > 0) return true;
            } else if (v !== undefined && v !== null && String(v).trim() !== '') {
                return true;
            }
        }
        return false;
    }

    function updateClearAllFiltersBtn() {
        if (!clearAllFiltersBtn) return;
        clearAllFiltersBtn.classList.toggle('d-none', !hasAnyActiveFilter());
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
        return '<tr data-id="' + tid + '" data-cleared="' + (isCleared ? '1' : '0') + '" data-status="' + escHtml(r.status || 'pending') + '" data-debits="' + escHtml(String(debAmt)) + '" data-credits="' + escHtml(String(credAmt)) + '">'
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
            + '</tr>';
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
            let active = false;
            if (col === 'date') active = !!(f.date_from || f.date_to);
            else if (col === 'reference') active = !!f.reference;
            else if (col === 'pay_to') active = !!f.pay_to;
            else if (col === 'description') active = !!f.description;
            else if (col === 'account') active = parseInt(f.account_id || 0, 10) > 0;
            else if (col === 'fund') active = parseInt(f.fund_id || 0, 10) > 0;
            else if (col === 'amount') active = !!(f.amount || f.amount_min || f.amount_max);
            else if (col === 'status') active = !!f.status;
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
                        txTableBody.innerHTML = '<tr class="ledger-empty-row"><td colspan="10" class="text-center text-muted py-4">No transactions match the current filters.</td></tr>';
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
                if (data.filters) listState.filters = data.filters;
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
        return fetchTransactionList({ reset: true });
    }

    function applyColumnFilterFromMenu(th) {
        if (!th) return;
        const col = th.dataset.col;
        const f = Object.assign({}, listState.filters || {});
        const type = th.dataset.filterType;
        if (type === 'date') {
            f.date_from = th.querySelector('.ledger-f-date-from')?.value || '';
            f.date_to = th.querySelector('.ledger-f-date-to')?.value || '';
        } else if (type === 'text') {
            const val = (th.querySelector('.ledger-f-text')?.value || '').trim();
            if (col === 'reference') f.reference = val;
            else if (col === 'pay_to') f.pay_to = val;
            else if (col === 'description') f.description = val;
        } else if (type === 'account') {
            f.account_id = parseInt(th.querySelector('.ledger-f-account')?.value || '0', 10) || 0;
        } else if (type === 'fund') {
            f.fund_id = parseInt(th.querySelector('.ledger-f-fund')?.value || '0', 10) || 0;
        } else if (type === 'amount') {
            f.amount = (th.querySelector('.ledger-f-amount')?.value || '').trim();
            f.amount_min = (th.querySelector('.ledger-f-amount-min')?.value || '').trim();
            f.amount_max = (th.querySelector('.ledger-f-amount-max')?.value || '').trim();
        } else if (type === 'status') {
            f.status = th.querySelector('.ledger-f-status')?.value || '';
        }
        listState.filters = f;
        reloadTransactionList();
        // Close dropdown
        const toggle = th.querySelector('.ledger-filter-toggle');
        if (toggle && typeof bootstrap !== 'undefined') {
            const dd = bootstrap.Dropdown.getInstance(toggle);
            if (dd) dd.hide();
        }
    }

    function clearColumnFilterFromMenu(th) {
        if (!th) return;
        const col = th.dataset.col;
        const f = Object.assign({}, listState.filters || {});
        const type = th.dataset.filterType;
        if (type === 'date') {
            f.date_from = ''; f.date_to = '';
            const a = th.querySelector('.ledger-f-date-from'); if (a) a.value = '';
            const b = th.querySelector('.ledger-f-date-to'); if (b) b.value = '';
        } else if (type === 'text') {
            if (col === 'reference') f.reference = '';
            else if (col === 'pay_to') f.pay_to = '';
            else if (col === 'description') f.description = '';
            const t = th.querySelector('.ledger-f-text'); if (t) t.value = '';
        } else if (type === 'account') {
            f.account_id = 0;
            const s = th.querySelector('.ledger-f-account'); if (s) s.value = '0';
        } else if (type === 'fund') {
            f.fund_id = 0;
            const s = th.querySelector('.ledger-f-fund'); if (s) s.value = '0';
        } else if (type === 'amount') {
            f.amount = ''; f.amount_min = ''; f.amount_max = '';
            const a = th.querySelector('.ledger-f-amount'); if (a) a.value = '';
            const b = th.querySelector('.ledger-f-amount-min'); if (b) b.value = '';
            const c = th.querySelector('.ledger-f-amount-max'); if (c) c.value = '';
        } else if (type === 'status') {
            f.status = '';
            const s = th.querySelector('.ledger-f-status'); if (s) s.value = '';
        }
        listState.filters = f;
        reloadTransactionList();
        const toggle = th.querySelector('.ledger-filter-toggle');
        if (toggle && typeof bootstrap !== 'undefined') {
            const dd = bootstrap.Dropdown.getInstance(toggle);
            if (dd) dd.hide();
        }
    }

    function clearAllFilters() {
        listState.filters = {
            date_from: '', date_to: '', reference: '', description: '', pay_to: '',
            check_number: '', search: '', status: '', amount: '', amount_min: '', amount_max: '',
            account_id: 0, fund_id: 0
        };
        document.querySelectorAll('#ledgerTxTable .ledger-f-date-from, #ledgerTxTable .ledger-f-date-to, #ledgerTxTable .ledger-f-text, #ledgerTxTable .ledger-f-amount, #ledgerTxTable .ledger-f-amount-min, #ledgerTxTable .ledger-f-amount-max').forEach(el => { el.value = ''; });
        document.querySelectorAll('#ledgerTxTable .ledger-f-account, #ledgerTxTable .ledger-f-fund').forEach(el => { el.value = '0'; });
        document.querySelectorAll('#ledgerTxTable .ledger-f-status').forEach(el => { el.value = ''; });
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
            const sortBtn = e.target.closest('.ledger-sort-btn');
            if (sortBtn) {
                e.preventDefault();
                const col = sortBtn.dataset.sort;
                if (!col) return;
                if (listState.sort === col || (col === 'amount' && ['amount','debit','credit'].includes(listState.sort)) || (col === 'reference' && ['reference','ref'].includes(listState.sort))) {
                    listState.sort_dir = listState.sort_dir === 'asc' ? 'desc' : 'asc';
                } else {
                    listState.sort = col;
                    // Default: date newest first; text cols asc first; amount desc
                    listState.sort_dir = (col === 'date' || col === 'amount') ? 'desc' : 'asc';
                }
                reloadTransactionList();
            }
        });
        // Enter in filter inputs applies
        table.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter') return;
            const th = e.target.closest('th.ledger-th-filter');
            if (!th) return;
            if (e.target.matches('input, select')) {
                e.preventDefault();
                applyColumnFilterFromMenu(th);
            }
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

    function setDocUploadVisible(show) {
        const docForm = document.getElementById('txDocUploadForm');
        if (!docForm) return;
        if (show) docForm.classList.remove('d-none');
        else docForm.classList.add('d-none');
        const fileInput = document.getElementById('txDocFile');
        const btn = document.getElementById('txDocUploadBtn');
        if (fileInput) fileInput.value = '';
        if (btn) btn.disabled = true;
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
                refReuseWarn.textContent = 'Reuse of ' + seq + ' confirmed.';
            }
            return;
        }
        fetch('pages/ledger.php?reference_api=check&ref=' + encodeURIComponent(seq)
            + '&exclude_id=' + excludeId
            + '&kind=' + encodeURIComponent(REF_KIND))
            .then(r => r.json())
            .then(d => {
                if (!refReuseWarn) return;
                const parts = [];
                if (d && d.taken) {
                    const u = d.usage || {};
                    const who = [u.transaction_date, u.pay_to || u.description].filter(Boolean).join(' — ');
                    parts.push('⚠ Already used'
                        + (u.id ? (' by <strong>#' + u.id + '</strong>') : '')
                        + (who ? (' (' + escHtml(who) + ')') : '')
                        + '. Saving will ask you to confirm reuse.');
                    if (refReuseFlag) refReuseFlag.value = '0';
                } else {
                    if (refReuseFlag) refReuseFlag.value = '0';
                }
                if (d && d.range_advisory) {
                    parts.push('ℹ ' + escHtml(d.range_advisory));
                }
                if (parts.length) {
                    refReuseWarn.classList.remove('d-none');
                    refReuseWarn.innerHTML = parts.join('<br>');
                } else {
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
                    refReuseWarn.textContent = 'Reuse of ' + seqVal + ' confirmed.';
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

    const docFileInput = document.getElementById('txDocFile');
    const docUploadBtn = document.getElementById('txDocUploadBtn');
    function syncDocUploadBtn() {
        if (!docUploadBtn || !docFileInput) return;
        const inEdit = isTxEditMode();
        const hasFile = docFileInput.files && docFileInput.files.length > 0;
        docUploadBtn.disabled = !(inEdit && hasFile);
    }
    if (docFileInput) {
        docFileInput.addEventListener('change', syncDocUploadBtn);
    }
    if (docUploadBtn) {
        docUploadBtn.addEventListener('click', function() {
            const id = txIdField.value;
            if (!isTxEditMode()) {
                showToast('Enter edit mode to upload documents.', 'warning');
                return;
            }
            if (!docFileInput || !docFileInput.files || docFileInput.files.length === 0) {
                showToast('Please select a file to upload.', 'warning');
                return;
            }
            const fd = new FormData();
            fd.append('action', 'upload_document');
            fd.append('tx_id', id);
            fd.append('document', docFileInput.files[0]);
            docUploadBtn.disabled = true;
            fetch('pages/ledger.php', { method: 'POST', body: fd })
                .then(parseJsonResponse)
                .then(res => {
                    if (!isApiSuccess(res)) {
                        showToast(res.error || 'Upload failed.', 'danger');
                        syncDocUploadBtn();
                        return;
                    }
                    showToast(res.message || 'Upload Successful', 'success');
                    // Prefer server-returned documents list for instant refresh (preserve delete queue)
                    if (Array.isArray(res.documents)) {
                        if (currentViewData) currentViewData.documents = res.documents;
                        renderDocumentsList(res.documents, true);
                        setDocUploadVisible(true);
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
            // Only reference: and check: populate the form; all other key: value lines are ignored.
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
                    if (key === 'reference') {
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

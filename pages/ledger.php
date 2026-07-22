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
        $fullMemo = $det['memo'] ?? '';
        if (strpos($fullMemo, ' | ') !== false) {
            list($det['description'], $det['memo']) = explode(' | ', $fullMemo, 2);
        } else {
            $det['description'] = '';
            $det['memo'] = $fullMemo;
        }
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
            $mem = trim($_POST['memo'] ?? '');
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
                                        'memo' => (string)($lockedTx['memo'] ?? ''),
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
                    $vlines[] = [
                        'aid' => $aid,
                        'fid' => !empty($l['fund_id']) ? (int)$l['fund_id'] : null,
                        'nid' => !empty($l['natural_category_id']) ? (int)$l['natural_category_id'] : null,
                        'fid2' => !empty($l['functional_category_id']) ? (int)$l['functional_category_id'] : null,
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
                    $mm = $desc ? ($desc . ($mem ? ' | ' . $mem : '')) : $mem;

                    if ($tx_id > 0) {
                        $existing = ledgerFetchTransaction($db, $tx_id);
                        if (!$existing) {
                            $error = "Transaction not found.";
                        } elseif (!ledgerIsEditable($existing)) {
                            $error = "This transaction is read-only (cleared or reconciled).";
                        } else {
                            $oldRef = ledgerNormalizeReferenceNumber($existing['reference_number'] ?? null);
                            $budgetBind = $budgetId !== null ? (string)$budgetId : null;
                            $upd = $db->prepare("UPDATE transaction_details SET transaction_date=?, check_number=?, pay_to=?, reference_number=?, memo=?, budget_id=? WHERE id=?");
                            $upd->bind_param("ssssssi", $d, $c, $p, $ref, $mm, $budgetBind, $tx_id);
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
                                        'memo' => $mm,
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
                            $mm,
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

    // Dropdown options (needed for Add/Edit form)
    $ar = $db->query("SELECT id,name,normal_balance FROM accounts WHERE archived=FALSE ORDER BY name");
    $fr = $db->query("SELECT id,name,code FROM funds WHERE is_active=TRUE AND archived=FALSE ORDER BY name");
    $nr = $db->query("SELECT id,name FROM natural_categories WHERE archived=FALSE ORDER BY name");
    $fur = $db->query("SELECT id,name FROM functional_categories WHERE archived=FALSE ORDER BY name");
    $budgetOptions = budgetFetchTransactionOptions($db);
    $defaultBudgetIdToday = budgetDefaultIdForDate($db, date('Y-m-d'));

    $aopt = '';
    if ($ar) {
        while ($a = $ar->fetch_assoc()) {
            $nb = htmlspecialchars($a['normal_balance']);
            $aopt .= '<option value="' . (int)$a['id'] . '" data-normal-balance="' . $nb . '">' . htmlspecialchars($a['name']) . ' (' . $nb . ')</option>';
        }
    }
    $fopt = '<option value="">—</option>';
    if ($fr) {
        while ($f = $fr->fetch_assoc()) {
            $fopt .= '<option value="' . (int)$f['id'] . '">' . htmlspecialchars($f['name'] . ($f['code'] ? ' (' . $f['code'] . ')' : '')) . '</option>';
        }
    }
    $nopt = '<option value="">—</option>';
    if ($nr) {
        while ($n = $nr->fetch_assoc()) {
            $nopt .= '<option value="' . (int)$n['id'] . '">' . htmlspecialchars($n['name']) . '</option>';
        }
    }
    $fuopt = '<option value="">—</option>';
    if ($fur) {
        while ($f = $fur->fetch_assoc()) {
            $fuopt .= '<option value="' . (int)$f['id'] . '">' . htmlspecialchars($f['name']) . '</option>';
        }
    }

    // Curated accounts for Account View dropdown: only Assets, Liabilities, Equity (exclude revenue accounts like Contributions)
    $view_accts = [];
    $vaq = $db->query("SELECT id, name, normal_balance FROM accounts WHERE archived=FALSE ORDER BY FIELD(normal_balance, 'debit', 'credit'), name");
    if ($vaq) {
        while ($va = $vaq->fetch_assoc()) {
            if (stripos($va['name'], 'contribution') !== false) continue;
            $view_accts[] = $va;
        }
    }

    // Filters and pagination
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $search = trim($_GET['search'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 25;

    // Account View filter (defaults to Bank Account or first Asset only on bare loads; explicit 0 forces All)
    $account_param = $_GET['account_id'] ?? null;
    $raw_account_id = $account_param !== null ? (int)$account_param : 0;
    $filter_account_id = $raw_account_id;
    if ($account_param === null) {
        // Default to main Bank Account (or first debit/asset account)
        $dstmt = $db->prepare("SELECT id FROM accounts WHERE name = 'Bank Account' AND archived = FALSE LIMIT 1");
        $dstmt->execute();
        $drow = $dstmt->get_result()->fetch_assoc();
        $dstmt->close();
        if ($drow && !empty($drow['id'])) {
            $filter_account_id = (int)$drow['id'];
        } else {
            $dstmt = $db->prepare("SELECT id FROM accounts WHERE normal_balance = 'debit' AND archived = FALSE ORDER BY id LIMIT 1");
            $dstmt->execute();
            $drow = $dstmt->get_result()->fetch_assoc();
            $dstmt->close();
            if ($drow && !empty($drow['id'])) {
                $filter_account_id = (int)$drow['id'];
            }
        }
    }
    $dropdown_selected = ($account_param === null ? $filter_account_id : $raw_account_id);

    $conditions = [];
    $bind_params = [];
    $bind_types = '';
    if ($date_from) {
        $conditions[] = "td.transaction_date >= ?";
        $bind_params[] = $date_from;
        $bind_types .= 's';
    }
    if ($date_to) {
        $conditions[] = "td.transaction_date <= ?";
        $bind_params[] = $date_to;
        $bind_types .= 's';
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $conditions[] = "(td.pay_to LIKE ? OR td.reference_number LIKE ? OR td.check_number LIKE ? OR td.memo LIKE ? OR CAST(COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id), 0) AS CHAR) LIKE ?)";
        $bind_params = array_merge($bind_params, [$like, $like, $like, $like, $like]);
        $bind_types .= str_repeat('s', 5);
    }
    if ($filter_account_id > 0) {
        $conditions[] = "EXISTS (SELECT 1 FROM transaction_lines tl WHERE tl.transaction_detail_id = td.id AND tl.account_id = ?)";
        $bind_params[] = $filter_account_id;
        $bind_types .= 'i';
    }
    $where_clause = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

    // Load selected account info for signed amount calc
    $view_normal = '';
    if ($filter_account_id > 0) {
        $vn = $db->prepare("SELECT normal_balance FROM accounts WHERE id = ? LIMIT 1");
        $vn->bind_param('i', $filter_account_id);
        $vn->execute();
        if ($vnr = $vn->get_result()->fetch_assoc()) {
            $view_normal = $vnr['normal_balance'];
        }
        $vn->close();
    }

    // Total for pagination
    $count_stmt = $db->prepare("SELECT COUNT(*) AS total FROM transaction_details td" . $where_clause);
    if ($bind_types) {
        $count_stmt->bind_param($bind_types, ...$bind_params);
    }
    $count_stmt->execute();
    $total = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $count_stmt->close();

    $total_pages = max(1, (int)ceil($total / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    // List query with pagination
    $list_params = $bind_params;
    $list_types = $bind_types;
    $list_params[] = $per_page;
    $list_params[] = $offset;
    $list_types .= 'ii';

    $tx_stmt = $db->prepare("
        SELECT td.id, td.transaction_date, td.pay_to, td.reference_number, td.check_number, td.memo, td.status, td.cleared_date,
               td.validated_by_user_id, td.validated_at,
               COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id AND type='debit'), 0) AS total_debits,
               COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id AND type='credit'), 0) AS total_credits,
               COALESCE((SELECT SUM(amount) FROM transaction_lines WHERE transaction_detail_id=td.id), 0) AS total_amount,
               COALESCE((SELECT COUNT(*) FROM transaction_lines WHERE transaction_detail_id=td.id), 0) AS num_lines
        FROM transaction_details td
        $where_clause
        ORDER BY td.transaction_date DESC, td.id DESC
        LIMIT ? OFFSET ?
    ");
    if ($list_types) {
        $tx_stmt->bind_param($list_types, ...$list_params);
    }
    $tx_stmt->execute();
    $tx_result = $tx_stmt->get_result();

    // Collect rows (small page size)
    $tx_rows = [];
    if ($tx_result) {
        while ($r = $tx_result->fetch_assoc()) {
            $tx_rows[] = $r;
        }
        $tx_result->close();
    }

    // When an account is selected, show Debit/Credit totals for that account only
    $acct_debits = [];
    $acct_credits = [];
    if ($filter_account_id > 0 && count($tx_rows) > 0) {
        $ids = [];
        foreach ($tx_rows as $r) { $ids[] = (int)$r['id']; }
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
?>
<div class="container-fluid">
<?php if ($success || $error): ?>
<script type="application/json" id="ledger-flash"><?= json_encode(['message' => $success ?: $error, 'type' => $success ? 'success' : 'danger']) ?></script>
<?php endif; ?>

    <!-- Top Action Buttons -->
    <div class="d-flex flex-wrap gap-2 mb-2 ledger-action-bar">
        <?php if ($canWriteLedger): ?>
        <button type="button" id="addTxBtn" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> <span class="d-none d-sm-inline">Add Transaction</span><span class="d-sm-none">Add</span>
        </button>
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
    </div>

    <!-- Constrained viewport area: list fixed at 35%, form takes remainder and scrolls internally if long -->
    <div class="d-flex flex-column ledger-workspace">
        <!-- Filters -->
        <div class="mb-2 flex-shrink-0">
            <div class="row g-2 align-items-end ledger-filter-row">
                <div class="col-6 col-md-auto">
                    <label class="form-label small mb-1">Date From</label>
                    <input type="date" id="filterDateFrom" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-6 col-md-auto">
                    <label class="form-label small mb-1">Date To</label>
                    <input type="date" id="filterDateTo" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-12 col-sm-6 col-md-auto">
                    <label class="form-label small mb-1">Account View</label>
                    <select id="filterAccount" class="form-select form-select-sm" style="min-width:170px;">
                        <option value="0" <?= $dropdown_selected === 0 ? 'selected' : '' ?>>All Accounts</option>
<?php foreach ($view_accts as $va): ?>
<?php $vid = (int)$va['id']; ?>
                        <option value="<?= $vid ?>" <?= $vid === $dropdown_selected ? 'selected' : '' ?>><?= htmlspecialchars($va['name']) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label small mb-1"><span class="d-none d-md-inline">Search (Pay To / Ref # / Check # / Memo / Amount)</span><span class="d-md-none">Search</span></label>
                    <input type="search" id="filterSearch" class="form-control form-control-sm" value="<?= htmlspecialchars($search) ?>" placeholder="Search transactions...">
                </div>
                <div class="col-12 col-md-auto d-flex flex-wrap gap-2">
                    <button type="button" id="applyFilterBtn" class="btn btn-sm btn-primary flex-grow-1 flex-md-grow-0">Apply</button>
                    <button type="button" id="clearFilterBtn" class="btn btn-sm btn-outline-secondary flex-grow-1 flex-md-grow-0">Clear</button>
                </div>
                <div class="col-12 col-md-auto ms-md-auto text-muted small align-self-center">
                    <?= (int)$total ?> total
                </div>
            </div>
        </div>

        <!-- Transactions List (fixed 35% height of page, internally scrollable) -->
        <div class="card mb-2 flex-shrink-0 ledger-tx-list">
            <div class="card-header py-2">
                <strong>Transactions</strong>
                <small class="text-muted ms-2 d-none d-md-inline">(click row to view; checkbox or Ctrl/Shift+click for multi)</small>
                <small class="text-muted ms-2 d-md-none">(tap row to view)</small>
            </div>
            <div class="card-body p-0 d-flex flex-column" style="height: calc(100% - 2.25rem);">
                <div class="table-responsive" style="flex: 1 1 auto; overflow: auto; min-height: 0;">
                    <table class="table table-sm table-hover mb-0 align-middle" style="min-width: 720px;">
                        <thead class="table-dark" style="position: sticky; top: 0; z-index: 10;">
                            <tr>
                                <th style="width:28px"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                                <th>Date</th>
                                <th>Ref #</th>
                                <th>Pay To</th>
                                <th>Check #</th>
                                <th>Memo</th>
                                <th class="text-end text-nowrap" style="min-width:5.5rem" title="Debit amounts">Debit</th>
                                <th class="text-end text-nowrap" style="min-width:5.5rem" title="Credit amounts">Credit</th>
                                <th>Status</th>
                                <th class="text-center">Lines</th>
                            </tr>
                        </thead>
                        <tbody id="txTableBody">
                            <?php if (count($tx_rows) > 0): ?>
                                <?php foreach ($tx_rows as $r): ?>
                                    <?php
                                        $isCleared = ($r['status'] === 'cleared' || !empty($r['cleared_date']));
                                        $statusBadge = 'bg-secondary';
                                        $statusText = 'Pending';
                                        if ($r['status'] === 'cleared') { $statusBadge = 'bg-success'; $statusText = 'Cleared'; }
                                        elseif ($r['status'] === 'reconciled') { $statusBadge = 'bg-info'; $statusText = 'Reconciled'; }
                                        $tid = (int)$r['id'];
                                        if ($filter_account_id > 0) {
                                            $debAmt = $acct_debits[$tid] ?? 0.0;
                                            $credAmt = $acct_credits[$tid] ?? 0.0;
                                        } else {
                                            $debAmt = (float)($r['total_debits'] ?? 0);
                                            $credAmt = (float)($r['total_credits'] ?? 0);
                                        }
                                        $debDisplay = $fmtLedgerAmt($debAmt);
                                        $credDisplay = $fmtLedgerAmt($credAmt);
                                    ?>
                                    <tr data-id="<?= $tid ?>" data-cleared="<?= $isCleared ? '1' : '0' ?>" data-status="<?= htmlspecialchars($r['status']) ?>" data-debits="<?= htmlspecialchars((string)$debAmt) ?>" data-credits="<?= htmlspecialchars((string)$credAmt) ?>">
                                        <td><input type="checkbox" class="form-check-input tx-cb" value="<?= $tid ?>"></td>
                                        <td><?= htmlspecialchars($r['transaction_date']) ?></td>
                                        <td class="font-monospace"><?= htmlspecialchars($r['reference_number'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($r['pay_to'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($r['check_number'] ?? '') ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars(substr($r['memo'] ?? '', 0, 70)) ?></td>
                                        <td class="text-end font-monospace text-primary fw-semibold ledger-debit-col"><?= $debDisplay !== '' ? htmlspecialchars($debDisplay) : '<span class="text-muted">&nbsp;</span>' ?></td>
                                        <td class="text-end font-monospace text-success fw-semibold ledger-credit-col"><?= $credDisplay !== '' ? htmlspecialchars($credDisplay) : '<span class="text-muted">&nbsp;</span>' ?></td>
                                        <td><span class="badge <?= $statusBadge ?>"><?= $statusText ?></span></td>
                                        <td class="text-center"><?= (int)$r['num_lines'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">No transactions yet. Use "Add Transaction" to create one.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="paginationBar" class="d-flex justify-content-between align-items-center px-2 py-2 small bg-body-tertiary border-top flex-shrink-0 gap-2"
                     data-current-page="<?= (int)$page ?>" data-total-pages="<?= (int)$total_pages ?>">
                    <div class="text-nowrap">Page <?= (int)$page ?> of <?= (int)$total_pages ?></div>
                    <div class="d-flex gap-1">
                        <button type="button" id="prevPageBtn" class="btn btn-sm btn-outline-secondary" <?= $page <= 1 ? 'disabled' : '' ?>>Prev</button>
                        <button type="button" id="nextPageBtn" class="btn btn-sm btn-outline-secondary" <?= $page >= $total_pages ? 'disabled' : '' ?>>Next</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transaction Details Form (fills remaining space; body scrolls internally if content is long) -->
        <div id="txFormSection" class="card flex-grow-1 d-flex flex-column" style="min-height: 0;">
            <div class="card-header d-flex justify-content-between align-items-center flex-shrink-0">
                <div>
                    <strong id="formTitle">Transaction Details</strong>
                    <span id="formModeBadge" class="badge bg-body-secondary text-body ms-1"></span>
                </div>
            </div>
            <div class="card-body flex-grow-1 overflow-auto" style="min-height: 0;">
                <form id="txForm" method="post" data-dirty-track>
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
                        <div class="col-12 col-sm-4 col-md-3 col-xl-2">
                            <label class="form-label small mb-1">Description</label>
                            <input type="text" class="form-control form-control-sm" name="description" id="description" placeholder="Short description">
                        </div>
                        <div class="col-12 col-md-12 col-xl-3">
                            <label class="form-label small mb-1">Memo</label>
                            <input type="text" class="form-control form-control-sm" name="memo" id="memo" placeholder="Additional notes">
                        </div>
                    </div>

                    <div class="mt-2">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <h6 class="mb-0 small">Lines <small class="text-muted">(min 2 required)</small></h6>
                            <button type="button" id="addLineBtn" class="btn btn-sm btn-outline-primary">+ Add Line</button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-1">
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

                    <div class="mt-2 d-flex flex-wrap gap-2">
                        <button type="submit" id="saveBtn" class="btn btn-sm btn-primary" disabled>Save Transaction</button>
                        <button type="button" id="resetLinesBtn" class="btn btn-sm btn-outline-secondary">Reset to 2 Lines</button>
                        <button type="button" id="cancelFormBtn2" class="btn btn-sm btn-outline-secondary">Cancel</button>
                    </div>
                </form>
            </div>
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
    const formSection = document.getElementById('txFormSection');
    const formTitle = document.getElementById('formTitle');
    const cancelBtn = document.getElementById('cancelFormBtn');
    const cancelBtn2 = document.getElementById('cancelFormBtn2');

    const addTxBtn = document.getElementById('addTxBtn');
    const editTxBtn = document.getElementById('editTxBtn');
    const clearTxBtn = document.getElementById('clearTxBtn');
    const reconcileTxBtn = document.getElementById('reconcileTxBtn');

    const selectAll = document.getElementById('selectAll');
    const txTableBody = document.getElementById('txTableBody');

    const accountOpts = `<?= $aopt ?>`;
    const fundOpts = `<?= $fopt ?>`;
    const natOpts = `<?= $nopt ?>`;
    const funcOpts = `<?= $fuopt ?>`;
    const budgetOptions = <?= json_encode($budgetOptions, JSON_UNESCAPED_UNICODE) ?> || [];
    const defaultBudgetIdToday = <?= $defaultBudgetIdToday !== null ? (int)$defaultBudgetIdToday : 'null' ?>;
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

    function buildQueryString(preservePage = true) {
        const p = new URLSearchParams();
        const df = document.getElementById('filterDateFrom');
        if (df && df.value) p.set('date_from', df.value);
        const dt = document.getElementById('filterDateTo');
        if (dt && dt.value) p.set('date_to', dt.value);
        const sr = document.getElementById('filterSearch');
        if (sr && sr.value.trim()) p.set('search', sr.value.trim());
        const acc = document.getElementById('filterAccount');
        if (acc) p.set('account_id', acc.value);
        if (preservePage) {
            const bar = document.getElementById('paginationBar');
            if (bar) {
                const pg = parseInt(bar.dataset.currentPage || '1', 10);
                if (pg > 1) p.set('page', pg);
            }
        }
        const s = p.toString();
        return s ? '?' + s : '';
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

    function attachLineListeners(row) {
        if (row.dataset.attached === '1') return;
        row.dataset.attached = '1';
        const accSel = row.querySelector('.line-account');
        const debIn = row.querySelector('.line-debit-amt');
        const credIn = row.querySelector('.line-credit-amt');
        const remBtn = row.querySelector('.remove-line');

        // Account selection no longer locks Debit/Credit — user may debit or credit any account
        if (accSel) accSel.addEventListener('change', () => recalcTotals());
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

        recalcTotals();
    }

    function createLineRow(prefill = null, readonly = false) {
        const ro = readonly ? ' disabled' : '';
        const remStyle = readonly ? ' style="display:none"' : '';
        const row = document.createElement('tr');
        // Debit / Credit columns: user enters amount in either column for any account
        row.innerHTML = `
            <td><select class="form-select form-select-sm line-account" required${ro}>${accountOpts}</select></td>
            <td><select class="form-select form-select-sm line-fund"${ro}>${fundOpts}</select></td>
            <td><select class="form-select form-select-sm line-nat"${ro}>${natOpts}</select></td>
            <td><select class="form-select form-select-sm line-func"${ro}>${funcOpts}</select></td>
            <td>
                <input type="number" step="0.01" min="0.01" class="form-control form-control-sm line-amount line-debit-amt text-end font-monospace" placeholder=""${ro}>
            </td>
            <td>
                <input type="number" step="0.01" min="0.01" class="form-control form-control-sm line-amount line-credit-amt text-end font-monospace" placeholder=""${ro}>
            </td>
            <td><button type="button" class="btn btn-sm btn-outline-danger remove-line"${remStyle}>×</button></td>
        `;
        row.dataset.lineType = '';
        if (prefill) {
            const acc = row.querySelector('.line-account');
            if (prefill.account_id) acc.value = prefill.account_id;
            const fund = row.querySelector('.line-fund');
            if (prefill.fund_id !== undefined && prefill.fund_id !== '') fund.value = prefill.fund_id;
            const nat = row.querySelector('.line-nat');
            if (prefill.natural_category_id !== undefined && prefill.natural_category_id !== '') nat.value = prefill.natural_category_id;
            const func = row.querySelector('.line-func');
            if (prefill.functional_category_id !== undefined && prefill.functional_category_id !== '') func.value = prefill.functional_category_id;
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
        ['transaction_date', 'reference_number', 'pay_to', 'check_number', 'description', 'memo'].forEach(fid => {
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

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

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
        form.reset();
        linesBody.innerHTML = '';
        renderMetaSection(null);
        setDocUploadVisible(false);
        setMainFieldsReadOnly(true);
        setBudgetStatusWarning(false);
        formTitle.textContent = 'Transaction Details';
        updateModeBadge('blank');
        if (addLineBtn) addLineBtn.style.display = 'none';
        if (resetLinesBtn) resetLinesBtn.style.display = 'none';
        if (saveBtn) saveBtn.style.display = 'none';
        if (cancelBtn2) cancelBtn2.style.display = 'none';
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
        document.getElementById('memo').value = data.memo || '';
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
        document.getElementById('memo').value = data.memo || '';
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
    }

    function enableEditFromView() {
        const id = txIdField.value;
        if (!id) return;
        restorePendingDocDeletes(id);

        // Cleared/reconciled: unlock budget only
        if (isTxClearedOrReconciled(currentViewData)) {
            applyBudgetOnlyEditMode(currentViewData || { id: id });
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
    }

    function updateButtonStates() {
        const checked = txTableBody.querySelectorAll('.tx-cb:checked');
        const count = checked.length;
        const multi = count > 1;

        addTxBtn.disabled = multi;
        // Edit allowed for single selection including cleared/reconciled (budget-only there)
        editTxBtn.disabled = (count !== 1);
        clearTxBtn.disabled = (count === 0);
        reconcileTxBtn.disabled = (count === 0);
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
        const fields = ['reference_number', 'pay_to', 'check_number', 'description', 'memo'];
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

    // Basic client-side sorting for specified columns
    let currentSortCol = -1;
    let currentSortDir = 1;
    let lastAnchorRow = null;
    let currentViewData = null;

    function sortTable(colIdx) {
        const tbody = txTableBody;
        let rows = Array.from(tbody.querySelectorAll('tr'));
        if (rows.length < 2) return;

        const dir = (currentSortCol === colIdx) ? -currentSortDir : 1;
        currentSortCol = colIdx;
        currentSortDir = dir;

        rows.sort((ra, rb) => {
            const ta = ra.children[colIdx] ? ra.children[colIdx].textContent.trim() : '';
            const tb = rb.children[colIdx] ? rb.children[colIdx].textContent.trim() : '';

            if (colIdx === 1) { // Date
                const da = ta ? Date.parse(ta) : 0;
                const db = tb ? Date.parse(tb) : 0;
                return (da - db) * dir;
            } else if (colIdx === 2 || colIdx === 4 || colIdx === 6 || colIdx === 7) {
                // Ref #, Check #, Debit, Credit — numeric
                const na = parseFloat(ta.replace(/[^0-9.-]/g, '')) || 0;
                const nb = parseFloat(tb.replace(/[^0-9.-]/g, '')) || 0;
                return (na - nb) * dir;
            } else {
                // string compare (case-insensitive)
                const sa = ta.toLowerCase();
                const sb = tb.toLowerCase();
                if (sa < sb) return -dir;
                if (sa > sb) return dir;
                return 0;
            }
        });

        rows.forEach(r => tbody.appendChild(r));

        // update header indicators (basic)
        const table = tbody.closest('table');
        const ths = table.querySelectorAll('thead th');
        ths.forEach((th, i) => {
            let txt = th.textContent.replace(/\s*[↑↓]$/, '');
            if (i === colIdx) {
                txt += (dir > 0 ? ' ↑' : ' ↓');
            }
            th.textContent = txt;
        });
    }

    function initSorting() {
        const table = txTableBody.closest('table');
        if (!table) return;
        const ths = table.querySelectorAll('thead th');
        // 1=Date, 2=Pay To, 3=Ref #, 4=Check #, 6=Debit, 7=Credit, 8=Status
        const sortable = [1, 2, 3, 4, 6, 7, 8];
        ths.forEach((th, idx) => {
            if (sortable.includes(idx)) {
                th.style.cursor = 'pointer';
                th.addEventListener('click', () => sortTable(idx));
            }
        });
    }

    // Wire action buttons
    addTxBtn.addEventListener('click', () => {
        // Prompt only when the user has edited the form since it was loaded
        if ((isTxEditMode() || budgetOnlyEditMode) && isLedgerFormDirty()) {
            if (!confirmDiscardTx()) return;
        }
        // clear the current selection when starting add
        txTableBody.querySelectorAll('.tx-cb:checked').forEach(cb => cb.checked = false);
        if (selectAll) selectAll.checked = false;
        updateButtonStates();
        showFormForAdd();
        markTxFormClean();
    });

    editTxBtn.addEventListener('click', () => {
        const ids = getSelectedIds();
        if (ids.length !== 1) return;
        const curId = txIdField.value;
        const alreadyFullEdit = curId == ids[0] && !document.getElementById('transaction_date').readOnly;
        const alreadyBudgetOnly = curId == ids[0] && budgetOnlyEditMode;
        if (alreadyFullEdit || alreadyBudgetOnly) return; // already editing
        if (curId == ids[0] && currentViewData) {
            enableEditFromView();
            return;
        }
        fetch('pages/ledger.php?get_transaction=' + ids[0])
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
    });

    clearTxBtn.addEventListener('click', () => {
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

    reconcileTxBtn.addEventListener('click', () => {
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

    // Cancel form (revert edit->view or deselect+blank) — discard queued deletions
    function cancelFormAction() {
        if (!confirmDiscardTx()) return;
        clearPendingDocDeletes(true);
        const curId = txIdField.value;
        if (curId && currentViewData && String(currentViewData.id) === String(curId)) {
            populateView(currentViewData);
        } else {
            txTableBody.querySelectorAll('.tx-cb:checked').forEach(cb => cb.checked = false);
            if (selectAll) selectAll.checked = false;
            updateButtonStates();
            showBlankForm();
        }
        markTxFormClean();
    }
    if (cancelBtn) cancelBtn.addEventListener('click', cancelFormAction);
    if (cancelBtn2) cancelBtn2.addEventListener('click', cancelFormAction);

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
                    const who = [u.transaction_date, u.pay_to || u.memo].filter(Boolean).join(' — ');
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
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
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
                        + '<td class="small">' + escHtml(it.description || '—') + '</td>'
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
                const acc = row.querySelector('.line-account')?.value;
                const { amount: amt, type: lineType } = getLineAmountAndType(row);
                if (!acc || !amt || (lineType !== 'debit' && lineType !== 'credit')) return;

                lines.push({
                    account_id: acc,
                    fund_id: row.querySelector('.line-fund')?.value || '',
                    natural_category_id: row.querySelector('.line-nat')?.value || '',
                    functional_category_id: row.querySelector('.line-func')?.value || '',
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
        const checkedCbs = txTableBody.querySelectorAll('.tx-cb:checked');
        const count = checkedCbs.length;
        updateButtonStates();
        if (count === 1) {
            const id = parseInt(checkedCbs[0].value, 10);
            if (String(txIdField.value) !== String(id)) {
                loadView(id);
            }
            // same id: leave current mode (view or edit) as-is
        } else {
            showBlankForm();
        }
    }

    function loadView(id) {
        fetch('pages/ledger.php?get_transaction=' + id)
            .then(r => r.json())
            .then(data => {
                if (data && data.error) {
                    showBlankForm();
                    return;
                }
                populateView(data);
            })
            .catch(() => showBlankForm());
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

    // Initial state
    updateButtonStates();
    initSorting();

    // Always start with blank read-only form
    showBlankForm();

    // Wire filter apply/clear + search enter + pagination
    const applyFilterBtn = document.getElementById('applyFilterBtn');
    const clearFilterBtn = document.getElementById('clearFilterBtn');
    const filterSearchEl = document.getElementById('filterSearch');

    function fetchAndReplaceLedger(url) {
        if (!confirmDiscardTx()) return;
        fetch(url)
            .then(r => r.text())
            .then(html => { applyMainContent(html); });
    }

    if (applyFilterBtn) {
        applyFilterBtn.addEventListener('click', () => {
            fetchAndReplaceLedger('pages/ledger.php' + buildQueryString(false));
        });
    }
    if (clearFilterBtn) {
        clearFilterBtn.addEventListener('click', () => {
            if (!confirmDiscardTx()) return;
            const df = document.getElementById('filterDateFrom');
            const dt = document.getElementById('filterDateTo');
            const sr = document.getElementById('filterSearch');
            const accf = document.getElementById('filterAccount');
            if (df) df.value = '';
            if (dt) dt.value = '';
            if (sr) sr.value = '';
            if (accf) accf.value = '0';
            fetch('pages/ledger.php' + buildQueryString(false))
                .then(r => r.text())
                .then(html => { applyMainContent(html); });
        });
    }
    if (filterSearchEl) {
        filterSearchEl.addEventListener('keydown', function(ev) {
            if (ev.key === 'Enter') {
                fetchAndReplaceLedger('pages/ledger.php' + buildQueryString(false));
            }
        });
    }

    function loadWithPage(pageNum) {
        if (!confirmDiscardTx()) return;
        const params = new URLSearchParams();
        const dfEl = document.getElementById('filterDateFrom');
        const dtEl = document.getElementById('filterDateTo');
        const srEl = document.getElementById('filterSearch');
        const accEl = document.getElementById('filterAccount');
        if (dfEl && dfEl.value) params.set('date_from', dfEl.value);
        if (dtEl && dtEl.value) params.set('date_to', dtEl.value);
        if (srEl && srEl.value.trim()) params.set('search', srEl.value.trim());
        if (accEl) params.set('account_id', accEl.value);
        if (pageNum > 1) params.set('page', pageNum);
        const qstr = params.toString() ? ('?' + params.toString()) : '';
        fetch('pages/ledger.php' + qstr)
            .then(r => r.text())
            .then(html => { applyMainContent(html); });
    }

    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');
    if (prevPageBtn) {
        prevPageBtn.addEventListener('click', () => {
            const bar = document.getElementById('paginationBar');
            const cur = bar ? parseInt(bar.dataset.currentPage || '1', 10) : 1;
            loadWithPage(Math.max(1, cur - 1));
        });
    }
    if (nextPageBtn) {
        nextPageBtn.addEventListener('click', () => {
            const bar = document.getElementById('paginationBar');
            const cur = bar ? parseInt(bar.dataset.currentPage || '1', 10) : 1;
            const tot = bar ? parseInt(bar.dataset.totalPages || '1', 10) : 1;
            loadWithPage(Math.min(tot, cur + 1));
        });
    }

})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-ledger-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>

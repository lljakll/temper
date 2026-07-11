<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../../login.php');
    exit;
}

require_once __DIR__ . '/../workflow_engine.php';
require_once __DIR__ . '/../ledger_engine.php';

const CONTRIB_STATUS_DRAFT = 'draft_pending_second_count';
const CONTRIB_STATUS_DUAL_COMPLETE = 'dual_count_complete_pending_official';
const CONTRIB_STATUS_DEPOSITED = 'deposited';
const CONTRIB_STATUS_CANCELLED = 'cancelled';

const CONTRIB_STEP_TELLER = 'teller_create';
const CONTRIB_STEP_SECOND = 'second_teller_verify';
const CONTRIB_STEP_OFFICIAL = 'official_validate';

function contribDenominationKeys(): array {
    return ['100', '50', '20', '10', '5', '1', '0.25', '0.10', '0.05', '0.01'];
}

function contribEmptyDenominations(): array {
    $denoms = [];
    foreach (contribDenominationKeys() as $k) {
        $denoms[$k] = 0;
    }
    return $denoms;
}

function contribSumDenominations(array $denoms): float {
    $total = 0.0;
    foreach (contribDenominationKeys() as $k) {
        $count = (int)($denoms[$k] ?? 0);
        $total += $count * (float)$k;
    }
    return round($total, 2);
}

function contribSumChecks(array $checks): float {
    $total = 0.0;
    foreach ($checks as $c) {
        $total += (float)($c['amount'] ?? 0);
    }
    return round($total, 2);
}

function contribSumAllocations(array $allocations): float {
    $total = 0.0;
    foreach ($allocations as $a) {
        $total += (float)($a['amount'] ?? 0);
    }
    return round($total, 2);
}

function contribValidatePayload(array $payload): array {
    $denoms = $payload['cash_denominations'] ?? contribEmptyDenominations();
    $checks = $payload['checks'] ?? [];
    $allocations = $payload['fund_allocations'] ?? [];

    $cashTotal = contribSumDenominations($denoms);
    $checkTotal = contribSumChecks($checks);
    $allocTotal = contribSumAllocations($allocations);
    $grand = round($cashTotal + $checkTotal, 2);

    if ($grand <= 0) {
        return ['valid' => false, 'error' => 'Total contribution amount must be greater than zero.'];
    }
    if (abs($allocTotal - $grand) > 0.005) {
        return [
            'valid' => false,
            'error' => 'Fund allocations ($' . number_format($allocTotal, 2) . ') must equal cash + checks ($' . number_format($grand, 2) . ').',
        ];
    }
    foreach ($allocations as $a) {
        if (empty($a['fund_id']) || (float)($a['amount'] ?? 0) <= 0) {
            return ['valid' => false, 'error' => 'Each fund allocation requires a fund and positive amount.'];
        }
    }

    return [
        'valid' => true,
        'cash_total' => $cashTotal,
        'check_total' => $checkTotal,
        'grand_total' => $grand,
        'allocation_total' => $allocTotal,
    ];
}

function contribDefaultTransactionData(int $firstTellerId, string $serviceDate = ''): array {
    return [
        'type' => 'contribution',
        'service_date' => $serviceDate ?: date('Y-m-d'),
        'description' => 'Sunday Offering',
        'cash_denominations' => contribEmptyDenominations(),
        'checks' => [],
        'fund_allocations' => [],
        'totals' => ['cash' => 0, 'checks' => 0, 'grand' => 0],
        'first_teller_id' => $firstTellerId,
        'first_teller_at' => date('c'),
        'second_teller_id' => null,
        'second_teller_at' => null,
        'second_verify_denominations' => contribEmptyDenominations(),
        'official_id' => null,
        'official_at' => null,
        'official_verified' => [
            'denominations' => false,
            'checks' => false,
            'funds' => false,
        ],
    ];
}

/** @deprecated Use contribDefaultTransactionData() */
function contribDefaultPayload(int $firstTellerId, string $serviceDate = ''): array {
    return contribDefaultTransactionData($firstTellerId, $serviceDate);
}

function contribStepDefinitions(): array {
    return [
        ['key' => CONTRIB_STEP_TELLER, 'order' => 1, 'status' => 'pending', 'role' => 'Teller'],
        ['key' => CONTRIB_STEP_SECOND, 'order' => 2, 'status' => 'pending', 'role' => 'Second Teller'],
        ['key' => CONTRIB_STEP_OFFICIAL, 'order' => 3, 'status' => 'pending', 'role' => 'Official'],
    ];
}

function contribOrchestrationPayload(int $transactionId): array {
    return [
        'schema_version' => 2,
        'transaction_detail_id' => $transactionId,
    ];
}

function contribMergeInputIntoTransactionData(array $input, array $base, int $firstTellerId): array {
    $data = $base;
    $data['service_date'] = $input['service_date'] ?? $data['service_date'];
    $data['description'] = $input['description'] ?? $data['description'];
    if (isset($input['reference_number']) || isset($input['sequence_number'])) {
        $data['reference_number'] = ledgerNormalizeReferenceNumber($input['reference_number'] ?? $input['sequence_number'] ?? '');
    }
    $data['cash_denominations'] = $input['cash_denominations'] ?? $data['cash_denominations'];
    $data['checks'] = $input['checks'] ?? $data['checks'];
    $data['fund_allocations'] = $input['fund_allocations'] ?? $data['fund_allocations'];
    $data['first_teller_id'] = $firstTellerId;
    $data['first_teller_at'] = date('c');
    return $data;
}

function contribFetchLedgerData(mysqli $db, ?int $transactionId): array {
    if (!$transactionId) {
        return [];
    }
    $tx = ledgerFetchTransaction($db, $transactionId);
    return $tx['transaction_data'] ?? [];
}

function contribEnrichInstance(mysqli $db, array $instance): array {
    $txId = (int)($instance['transaction_detail_id'] ?? 0);
    if ($txId > 0) {
        $ledger = ledgerFetchTransaction($db, $txId);
        if ($ledger) {
            $instance['ledger'] = $ledger;
            $instance['payload'] = array_merge(
                $instance['payload'] ?? [],
                $ledger['transaction_data'] ?? []
            );
            // Prefer authoritative column on transaction_details
            $ref = ledgerNormalizeReferenceNumber($ledger['reference_number'] ?? null);
            if ($ref !== '' && preg_match('/^\\d{6}$/', $ref)) {
                $instance['reference_number'] = $ref;
                $instance['payload']['reference_number'] = $ref;
            }
            $instance['documents'] = $ledger['documents'] ?? [];
            $instance['ledger_events'] = $ledger['events'] ?? [];
        }
    } else {
        $instance['documents'] = [];
        $instance['ledger_events'] = [];
    }
    return $instance;
}

function contribCreate(mysqli $db, array $payload, array $actor): array {
    if (!userHasWorkflowCapability($db, (int)$actor['id'], 'workflow.contribution.create')) {
        return ['error' => 'You do not have permission to create contribution workflows.'];
    }

    $validation = contribValidatePayload($payload);
    if (!$validation['valid']) {
        return ['error' => $validation['error']];
    }

    $transactionData = contribMergeInputIntoTransactionData(
        $payload,
        contribDefaultTransactionData((int)$actor['id']),
        (int)$actor['id']
    );
    $transactionData['totals'] = [
        'cash' => $validation['cash_total'],
        'checks' => $validation['check_total'],
        'grand' => $validation['grand_total'],
    ];

    $serviceDate = $transactionData['service_date'];
    $description = $transactionData['description'] ?? 'Contribution';
    $title = $description . ' — ' . $serviceDate;
    $memo = 'Contribution workflow draft | Pending dual count';

    $refCheck = ledgerValidateReferenceNumber($payload['reference_number'] ?? $payload['sequence_number'] ?? null, true);
    if (empty($refCheck['ok'])) {
        return ['error' => $refCheck['error'] ?? 'Reference # is required (YY####).'];
    }
    $referenceNumber = $refCheck['value'];
    $allowReuse = !empty($payload['allow_reference_reuse']) || !empty($payload['allow_sequence_reuse']);
    if (!$allowReuse && ledgerReferenceNumberTaken($db, $referenceNumber)) {
        $usage = ledgerReferenceUsage($db, $referenceNumber);
        $hint = $usage
            ? (' (used by #' . (int)$usage['id']
                . (!empty($usage['transaction_date']) ? ' on ' . $usage['transaction_date'] : '')
                . (!empty($usage['pay_to']) ? ' — ' . $usage['pay_to'] : '')
                . ')')
            : '';
        return [
            'error' => 'Reference # ' . $referenceNumber . ' is already used' . $hint
                . '. Confirm reuse if this is intentional.',
            'reference_taken' => true,
            'usage' => $usage,
        ];
    }
    $transactionData['reference_number'] = $referenceNumber;

    $db->begin_transaction();
    try {
        $txId = ledgerCreateHeader(
            $db,
            $serviceDate,
            'Contribution Deposit',
            (string)$referenceNumber,
            $memo,
            'workflow',
            'draft',
            (int)$actor['id'],
            $transactionData
        );

        $grand = (float)($validation['grand_total'] ?? 0);
        ledgerLogEvent(
            $db,
            $txId,
            'draft_created',
            (int)$actor['id'],
            $actor['username'] ?? 'system',
            'Contribution draft created by first teller totaling $' . number_format($grand, 2) . '.',
            ['grand_total' => $grand]
        );

        $instanceId = workflowCreateInstance(
            $db,
            'contribution',
            $title,
            CONTRIB_STATUS_DRAFT,
            CONTRIB_STEP_SECOND,
            (int)$actor['id'],
            contribOrchestrationPayload($txId),
            contribStepDefinitions(),
            $actor,
            $txId
        );

        ledgerUpdateHeader($db, $txId, null, null, null, null, null, $instanceId);

        workflowCompleteStep($db, $instanceId, CONTRIB_STEP_TELLER, (int)$actor['id'], $actor['username'], [
            'transaction_detail_id' => $txId,
            'totals' => $transactionData['totals'],
        ], 'First teller count recorded.');

        workflowLogEvent(
            $db,
            $instanceId,
            workflowGetStepId($db, $instanceId, CONTRIB_STEP_TELLER),
            'step_completed',
            (int)$actor['id'],
            $actor['username'],
            'Teller count saved; pending second teller verification.',
            ['status' => CONTRIB_STATUS_DRAFT, 'transaction_detail_id' => $txId]
        );

        $db->commit();
        return ['success' => true, 'id' => $instanceId, 'transaction_id' => $txId];
    } catch (Throwable $e) {
        $db->rollback();
        return ['error' => 'Failed to create contribution: ' . $e->getMessage()];
    }
}

function contribSecondTellerSign(mysqli $db, int $instanceId, int $signerId, string $password, array $verifyDenoms, array $actor): array {
    if (!userHasWorkflowCapability($db, $signerId, 'workflow.contribution.second_sign')) {
        return ['error' => 'Selected user is not authorized as a second teller.'];
    }
    if (!verifyUserPassword($db, $signerId, $password)) {
        return ['error' => 'Incorrect password for second teller sign-off.'];
    }

    $instance = workflowFetchInstance($db, $instanceId);
    if (!$instance || $instance['workflow_type'] !== 'contribution') {
        return ['error' => 'Contribution workflow not found.'];
    }
    if ($instance['status'] !== CONTRIB_STATUS_DRAFT) {
        return ['error' => 'This contribution is not awaiting second teller verification.'];
    }

    $txId = (int)($instance['transaction_detail_id'] ?? 0);
    if ($txId <= 0) {
        return ['error' => 'Ledger draft not found for this contribution.'];
    }

    $transactionData = contribFetchLedgerData($db, $txId);
    $firstId = (int)($transactionData['first_teller_id'] ?? 0);
    if ($signerId === $firstId) {
        return ['error' => 'Second teller must be a different person than the first teller.'];
    }

    $firstCash = contribSumDenominations($transactionData['cash_denominations'] ?? []);
    $verifyCash = contribSumDenominations($verifyDenoms);
    if (abs($firstCash - $verifyCash) > 0.005) {
        return ['error' => 'Second teller cash count ($' . number_format($verifyCash, 2) . ') does not match first count ($' . number_format($firstCash, 2) . ').'];
    }

    $transactionData['second_teller_id'] = $signerId;
    $transactionData['second_teller_at'] = date('c');
    $transactionData['second_verify_denominations'] = $verifyDenoms;

    $signer = getUserWithRole($db, $signerId);
    $signerName = $signer['username'] ?? ($actor['username'] ?? '');

    ledgerUpdateHeader($db, $txId, null, null, null, null, $transactionData);
    $signerDisplay = $signer['display_name'] ?? $signerName;
    ledgerLogEvent(
        $db,
        $txId,
        'second_teller_signed',
        $signerId,
        $signerName,
        'Second teller ' . $signerDisplay . ' signed off on dual count ($' . number_format($verifyCash, 2) . ' cash verified).',
        ['second_teller_id' => $signerId, 'second_teller_name' => $signerDisplay, 'verify_cash_total' => $verifyCash]
    );

    workflowUpdateInstance($db, $instanceId, CONTRIB_STATUS_DUAL_COMPLETE, CONTRIB_STEP_OFFICIAL, $instance['payload']);
    workflowCompleteStep($db, $instanceId, CONTRIB_STEP_SECOND, $signerId, $signerName, [
        'verify_cash_total' => $verifyCash,
    ], 'Second teller dual count verified.');

    workflowLogEvent(
        $db,
        $instanceId,
        workflowGetStepId($db, $instanceId, CONTRIB_STEP_SECOND),
        'signed',
        $signerId,
        $signerName,
        'Second teller signed off on dual count.',
        ['second_teller_id' => $signerId, 'transaction_detail_id' => $txId]
    );

    return ['success' => true, 'transaction_id' => $txId];
}

function contribLookupAccountId(mysqli $db, string $name): ?int {
    $stmt = $db->prepare('SELECT id FROM accounts WHERE name = ? AND archived = FALSE LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

function contribLookupCategoryId(mysqli $db, string $name): ?int {
    $stmt = $db->prepare('SELECT id FROM natural_categories WHERE name = ? AND archived = FALSE LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

function contribBuildDepositLines(mysqli $db, array $transactionData): array {
    $bankId = contribLookupAccountId($db, 'Bank Account') ?? contribLookupAccountId($db, 'Cash');
    $contribId = contribLookupAccountId($db, 'Contributions');
    $naturalId = contribLookupCategoryId($db, 'Contributions');

    if (!$bankId || !$contribId) {
        return ['error' => 'Required accounts (Bank Account/Cash and Contributions) not found in chart of accounts.'];
    }

    $allocations = $transactionData['fund_allocations'] ?? [];
    $grand = (float)($transactionData['totals']['grand'] ?? 0);
    if ($grand <= 0) {
        return ['error' => 'Cannot create deposit with zero amount.'];
    }

    $lines = [
        [
            'account_id' => $bankId,
            'fund_id' => null,
            'amount' => $grand,
            'type' => 'debit',
            'natural_category_id' => $naturalId,
            'description' => 'Deposit to bank',
        ],
    ];

    foreach ($allocations as $alloc) {
        $lines[] = [
            'account_id' => $contribId,
            'fund_id' => (int)$alloc['fund_id'],
            'amount' => (float)$alloc['amount'],
            'type' => 'credit',
            'natural_category_id' => $naturalId,
            'description' => 'Contribution allocation',
        ];
    }

    return ['success' => true, 'lines' => $lines];
}

function contribOfficialValidate(
    mysqli $db,
    int $instanceId,
    int $officialId,
    string $password,
    array $verifiedFlags,
    array $actor
): array {
    if (!userHasWorkflowCapability($db, $officialId, 'workflow.contribution.official')) {
        return ['error' => 'Selected official is not authorized to validate deposits.'];
    }
    if (!verifyUserPassword($db, $officialId, $password)) {
        return ['error' => 'Incorrect password for official sign-off.'];
    }

    $instance = workflowFetchInstance($db, $instanceId);
    if (!$instance || $instance['workflow_type'] !== 'contribution') {
        return ['error' => 'Contribution workflow not found.'];
    }
    if ($instance['status'] !== CONTRIB_STATUS_DUAL_COMPLETE) {
        return ['error' => 'This contribution is not awaiting official validation.'];
    }

    if (empty($verifiedFlags['denominations']) || empty($verifiedFlags['checks']) || empty($verifiedFlags['funds'])) {
        return ['error' => 'Official must verify denominations, checks, and fund allocations.'];
    }

    $txId = (int)($instance['transaction_detail_id'] ?? 0);
    if ($txId <= 0) {
        return ['error' => 'Ledger draft not found for this contribution.'];
    }

    $transactionData = contribFetchLedgerData($db, $txId);
    $linesResult = contribBuildDepositLines($db, $transactionData);
    if (!empty($linesResult['error'])) {
        return $linesResult;
    }

    $transactionData['official_id'] = $officialId;
    $transactionData['official_at'] = date('c');
    $transactionData['official_verified'] = $verifiedFlags;

    $db->begin_transaction();
    try {
        ledgerReplaceLines($db, $txId, $linesResult['lines']);
        ledgerUpdateHeader(
            $db,
            $txId,
            null,
            null,
            null,
            'Workflow contribution deposit | Instance #' . $instanceId,
            $transactionData
        );
        ledgerSetValidated(
            $db,
            $txId,
            $officialId,
            $officialId,
            $actor['username'] ?? ''
        );

        $depositAmt = (float)($transactionData['totals']['grand'] ?? 0);
        $officialUser = getUserWithRole($db, $officialId);
        $officialName = $officialUser['display_name'] ?? ($actor['username'] ?? 'official');
        ledgerLogEvent(
            $db,
            $txId,
            'deposit_finalized',
            $officialId,
            $actor['username'] ?? '',
            'Contribution deposit of $' . number_format($depositAmt, 2) . ' finalized by ' . $officialName . '.',
            ['workflow_instance_id' => $instanceId, 'amount' => $depositAmt, 'finalized_by' => $officialName]
        );

        workflowUpdateInstance(
            $db,
            $instanceId,
            CONTRIB_STATUS_DEPOSITED,
            'complete',
            $instance['payload'],
            $txId
        );
        workflowCompleteStep($db, $instanceId, CONTRIB_STEP_OFFICIAL, $officialId, $actor['username'] ?? '', [
            'transaction_id' => $txId,
            'verified' => $verifiedFlags,
        ], 'Official validation and deposit creation.');

        workflowLogEvent(
            $db,
            $instanceId,
            workflowGetStepId($db, $instanceId, CONTRIB_STEP_OFFICIAL),
            'deposit_created',
            $officialId,
            $actor['username'] ?? '',
            'Ledger deposit #' . $txId . ' finalized from contribution workflow.',
            ['transaction_id' => $txId, 'amount' => $transactionData['totals']['grand'] ?? 0]
        );

        $db->commit();
        return ['success' => true, 'transaction_id' => $txId];
    } catch (Throwable $e) {
        $db->rollback();
        return ['error' => 'Failed to finalize deposit: ' . $e->getMessage()];
    }
}

function contribStatusLabel(string $status): string {
    return match ($status) {
        CONTRIB_STATUS_DRAFT => 'Draft — Pending Second Count',
        CONTRIB_STATUS_DUAL_COMPLETE => 'Dual Count Complete — Pending Official',
        CONTRIB_STATUS_DEPOSITED => 'Deposited',
        CONTRIB_STATUS_CANCELLED => 'Cancelled',
        default => $status,
    };
}

/**
 * Hub-page stats for the contribution workflow type (orchestration only).
 *
 * @return array{total: int, active: int, badges: list<array{label: string, class: string}>}
 */
function contribWorkflowHubStats(mysqli $db): array {
    $rows = workflowListInstances($db, 'contribution', 200);
    $pendingSecond = 0;
    $pendingOfficial = 0;

    foreach ($rows as $row) {
        if ($row['status'] === CONTRIB_STATUS_DRAFT) {
            $pendingSecond++;
        } elseif ($row['status'] === CONTRIB_STATUS_DUAL_COMPLETE) {
            $pendingOfficial++;
        }
    }

    $badges = [];
    if ($pendingSecond > 0) {
        $badges[] = [
            'label' => $pendingSecond . ' pending 2nd count',
            'class' => 'bg-warning text-dark',
        ];
    }
    if ($pendingOfficial > 0) {
        $badges[] = [
            'label' => $pendingOfficial . ' pending official',
            'class' => 'bg-info text-dark',
        ];
    }

    return [
        'total' => count($rows),
        'active' => $pendingSecond + $pendingOfficial,
        'badges' => $badges,
    ];
}
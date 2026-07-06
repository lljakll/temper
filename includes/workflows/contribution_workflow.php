<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../../login.php');
    exit;
}

require_once __DIR__ . '/../workflow_engine.php';

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

function contribDefaultPayload(int $firstTellerId, string $serviceDate = ''): array {
    return [
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

function contribStepDefinitions(): array {
    return [
        ['key' => CONTRIB_STEP_TELLER, 'order' => 1, 'status' => 'pending', 'role' => 'Teller'],
        ['key' => CONTRIB_STEP_SECOND, 'order' => 2, 'status' => 'pending', 'role' => 'Second Teller'],
        ['key' => CONTRIB_STEP_OFFICIAL, 'order' => 3, 'status' => 'pending', 'role' => 'Official'],
    ];
}

function contribCreate(mysqli $db, array $payload, array $actor): array {
    if (!userHasWorkflowCapability($db, (int)$actor['id'], 'workflow.contribution.create')) {
        return ['error' => 'You do not have permission to create contribution workflows.'];
    }

    $validation = contribValidatePayload($payload);
    if (!$validation['valid']) {
        return ['error' => $validation['error']];
    }

    $payload['totals'] = [
        'cash' => $validation['cash_total'],
        'checks' => $validation['check_total'],
        'grand' => $validation['grand_total'],
    ];
    $payload['first_teller_id'] = (int)$actor['id'];
    $payload['first_teller_at'] = date('c');

    $title = ($payload['description'] ?? 'Contribution') . ' — ' . ($payload['service_date'] ?? date('Y-m-d'));
    $instanceId = workflowCreateInstance(
        $db,
        'contribution',
        $title,
        CONTRIB_STATUS_DRAFT,
        CONTRIB_STEP_SECOND,
        (int)$actor['id'],
        $payload,
        contribStepDefinitions(),
        $actor
    );

    workflowCompleteStep($db, $instanceId, CONTRIB_STEP_TELLER, (int)$actor['id'], $actor['username'], [
        'totals' => $payload['totals'],
    ], 'First teller count recorded.');

    workflowLogEvent(
        $db,
        $instanceId,
        workflowGetStepId($db, $instanceId, CONTRIB_STEP_TELLER),
        'step_completed',
        (int)$actor['id'],
        $actor['username'],
        'Teller count saved; pending second teller verification.',
        ['status' => CONTRIB_STATUS_DRAFT, 'grand_total' => $validation['grand_total']]
    );

    return ['success' => true, 'id' => $instanceId];
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

    $payload = $instance['payload'];
    $firstId = (int)($payload['first_teller_id'] ?? 0);
    if ($signerId === $firstId) {
        return ['error' => 'Second teller must be a different person than the first teller.'];
    }

    $firstCash = contribSumDenominations($payload['cash_denominations'] ?? []);
    $verifyCash = contribSumDenominations($verifyDenoms);
    if (abs($firstCash - $verifyCash) > 0.005) {
        return ['error' => 'Second teller cash count ($' . number_format($verifyCash, 2) . ') does not match first count ($' . number_format($firstCash, 2) . ').'];
    }

    $payload['second_teller_id'] = $signerId;
    $payload['second_teller_at'] = date('c');
    $payload['second_verify_denominations'] = $verifyDenoms;

    $signer = getUserWithRole($db, $signerId);
    $signerName = $signer['username'] ?? ($actor['username'] ?? '');

    workflowUpdateInstance($db, $instanceId, CONTRIB_STATUS_DUAL_COMPLETE, CONTRIB_STEP_OFFICIAL, $payload);
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
        ['second_teller_id' => $signerId]
    );

    return ['success' => true];
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

function contribCreateDepositTransaction(mysqli $db, array $payload, int $instanceId): array {
    $bankId = contribLookupAccountId($db, 'Bank Account') ?? contribLookupAccountId($db, 'Cash');
    $contribId = contribLookupAccountId($db, 'Contributions');
    $naturalId = contribLookupCategoryId($db, 'Contributions');

    if (!$bankId || !$contribId) {
        return ['error' => 'Required accounts (Bank Account/Cash and Contributions) not found in chart of accounts.'];
    }

    $allocations = $payload['fund_allocations'] ?? [];
    $grand = (float)($payload['totals']['grand'] ?? 0);
    if ($grand <= 0) {
        return ['error' => 'Cannot create deposit with zero amount.'];
    }

    $serviceDate = $payload['service_date'] ?? date('Y-m-d');
    $desc = $payload['description'] ?? 'Contribution Deposit';
    $ref = 'WF-CONTRIB-' . $instanceId;
    $memo = 'Workflow contribution deposit | Instance #' . $instanceId;

    $db->begin_transaction();
    try {
        $ins = $db->prepare(
            "INSERT INTO transaction_details (transaction_date, pay_to, reference_number, memo, status)
             VALUES (?, ?, ?, ?, 'pending')"
        );
        $payTo = 'Contribution Deposit';
        $ins->bind_param('ssss', $serviceDate, $payTo, $ref, $memo);
        $ins->execute();
        $txId = (int)$ins->insert_id;
        $ins->close();

        $lineStmt = $db->prepare(
            'INSERT INTO transaction_lines (transaction_detail_id, account_id, fund_id, amount, type, natural_category_id, description)
             VALUES (?, ?, NULL, ?, ?, ?, ?)'
        );

        $debitType = 'debit';
        $lineDesc = 'Deposit to bank';
        $lineStmt->bind_param('iidsis', $txId, $bankId, $grand, $debitType, $naturalId, $lineDesc);
        $lineStmt->execute();

        foreach ($allocations as $alloc) {
            $fundId = (int)$alloc['fund_id'];
            $amt = (float)$alloc['amount'];
            $creditType = 'credit';
            $lineDesc = 'Contribution allocation';
            $lineStmt->bind_param('iiidsis', $txId, $contribId, $fundId, $amt, $creditType, $naturalId, $lineDesc);
            $lineStmt->execute();
        }
        $lineStmt->close();

        $db->commit();
        return ['success' => true, 'transaction_id' => $txId];
    } catch (Throwable $e) {
        $db->rollback();
        return ['error' => 'Failed to create ledger deposit: ' . $e->getMessage()];
    }
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

    $payload = $instance['payload'];
    $deposit = contribCreateDepositTransaction($db, $payload, $instanceId);
    if (!empty($deposit['error'])) {
        return $deposit;
    }

    $payload['official_id'] = $officialId;
    $payload['official_at'] = date('c');
    $payload['official_verified'] = $verifiedFlags;
    $payload['deposit_transaction_id'] = $deposit['transaction_id'];

    workflowUpdateInstance(
        $db,
        $instanceId,
        CONTRIB_STATUS_DEPOSITED,
        'complete',
        $payload,
        $deposit['transaction_id']
    );
    workflowCompleteStep($db, $instanceId, CONTRIB_STEP_OFFICIAL, $officialId, $actor['username'] ?? '', [
        'transaction_id' => $deposit['transaction_id'],
        'verified' => $verifiedFlags,
    ], 'Official validation and deposit creation.');

    workflowLogEvent(
        $db,
        $instanceId,
        workflowGetStepId($db, $instanceId, CONTRIB_STEP_OFFICIAL),
        'deposit_created',
        $officialId,
        $actor['username'] ?? '',
        'Ledger deposit #' . $deposit['transaction_id'] . ' created from contribution workflow.',
        ['transaction_id' => $deposit['transaction_id'], 'amount' => $payload['totals']['grand'] ?? 0]
    );

    return ['success' => true, 'transaction_id' => $deposit['transaction_id']];
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
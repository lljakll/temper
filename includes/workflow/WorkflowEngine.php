<?php
/**
 * Generic workflow engine (v1 skeleton).
 *
 * Loads an immutable YAML definition and manages disposable instances.
 * Sequential steps only; events-based for input/routing/validation.
 * Final auditable results belong in the ledger / document packages — not here.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../../login.php');
    exit;
}

require_once __DIR__ . '/../storage_paths.php';
require_once __DIR__ . '/DefinitionDictionary.php';
require_once __DIR__ . '/WorkflowDefinition.php';
require_once __DIR__ . '/WorkflowEvents.php';
require_once __DIR__ . '/WorkflowImporter.php';

final class WorkflowEngine
{
    private mysqli $db;
    private WorkflowEventBus $bus;
    private WorkflowImporter $importer;

    public function __construct(mysqli $db, ?WorkflowEventBus $bus = null)
    {
        $this->db = $db;
        $this->bus = $bus ?? WorkflowEventBus::createDefault();
        $this->importer = new WorkflowImporter($db);
    }

    public function getImporter(): WorkflowImporter
    {
        return $this->importer;
    }

    public function getEventBus(): WorkflowEventBus
    {
        return $this->bus;
    }

    /**
     * Verify required tables exist (setup_db.php creates them).
     *
     * @throws RuntimeException
     */
    public function requireTables(): void
    {
        static $verified = false;
        if ($verified) {
            return;
        }
        $required = [
            'workflow_definitions',
            'workflow_instances',
            'workflow_steps',
            'workflow_events',
        ];
        $missing = [];
        foreach ($required as $table) {
            $escaped = $this->db->real_escape_string($table);
            $result = $this->db->query("SHOW TABLES LIKE '{$escaped}'");
            if ($result === false || $result->num_rows === 0) {
                $missing[] = $table;
            }
            if ($result instanceof mysqli_result) {
                $result->close();
            }
        }
        if ($missing !== []) {
            throw new RuntimeException(
                'Workflow tables are not initialized (missing: ' . implode(', ', $missing) . '). '
                . 'Run: php setup_db.php or php setup_db.php --check'
            );
        }
        $verified = true;
    }

    /**
     * List active definitions available to start.
     *
     * @return list<array<string, mixed>>
     */
    public function listActiveDefinitions(): array
    {
        $this->requireTables();
        return $this->importer->listDefinitions(true);
    }

    /**
     * Start a new instance from the active definition of $workflowId.
     *
     * @param array $actor User row (id, username)
     * @param array<string, mixed> $initialPayload
     * @return array{ok:bool,instance_id?:int,error?:string}
     */
    public function startInstance(
        string $workflowId,
        array $actor,
        string $title = '',
        array $initialPayload = []
    ): array {
        $this->requireTables();

        $definition = $this->importer->loadDefinition($workflowId);
        if ($definition === null) {
            return ['ok' => false, 'error' => 'No active definition found for: ' . $workflowId];
        }

        if (!$this->actorMayExecute($definition, $actor)) {
            return ['ok' => false, 'error' => 'You do not have permission to start this workflow.'];
        }

        $firstStep = $definition->getFirstStepKey();
        if ($firstStep === null) {
            return ['ok' => false, 'error' => 'Definition has no steps.'];
        }

        $checksum = $definition->getChecksum() ?? '';
        $instanceTitle = $title !== '' ? $title : $definition->getTitle();
        $status = 'running';
        $createdBy = (int)$actor['id'];
        $payloadJson = json_encode($initialPayload, JSON_UNESCAPED_UNICODE);
        $defVersion = $definition->getVersion();
        $wfId = $definition->getId();

        $stmt = $this->db->prepare(
            'INSERT INTO workflow_instances
                (workflow_id, definition_version, definition_checksum, title, status, current_step,
                 created_by_user_id, payload, transaction_detail_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Database error creating instance.'];
        }
        $stmt->bind_param(
            'sissssis',
            $wfId,
            $defVersion,
            $checksum,
            $instanceTitle,
            $status,
            $firstStep,
            $createdBy,
            $payloadJson
        );
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['ok' => false, 'error' => 'Failed to create instance: ' . $err];
        }
        $instanceId = (int)$stmt->insert_id;
        $stmt->close();

        // Seed step rows (sequential)
        $ins = $this->db->prepare(
            'INSERT INTO workflow_steps
                (workflow_instance_id, step_key, step_order, status, required_role)
             VALUES (?, ?, ?, ?, ?)'
        );
        $order = 0;
        foreach ($definition->getSteps() as $step) {
            $key = (string)($step['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $order++;
            $stepStatus = ($key === $firstStep) ? 'active' : 'pending';
            $role = isset($step['role']) && is_string($step['role']) ? $step['role'] : null;
            $ins->bind_param('isiss', $instanceId, $key, $order, $stepStatus, $role);
            $ins->execute();
        }
        $ins->close();

        $this->emit(
            'created',
            $instanceId,
            'Workflow started: ' . $instanceTitle,
            [
                'workflow_id' => $wfId,
                'definition_version' => $defVersion,
                'definition_checksum' => $checksum,
                'current_step' => $firstStep,
            ],
            null,
            $firstStep,
            $actor
        );

        $this->emit(
            'step_started',
            $instanceId,
            'Step started: ' . $firstStep,
            [],
            $this->getStepId($instanceId, $firstStep),
            $firstStep,
            $actor
        );

        return ['ok' => true, 'instance_id' => $instanceId];
    }

    /**
     * Fetch instance with steps and recent events.
     *
     * @return array<string, mixed>|null
     */
    public function fetchInstance(int $instanceId): ?array
    {
        $this->requireTables();
        $stmt = $this->db->prepare('SELECT * FROM workflow_instances WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $instanceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        $row['payload'] = json_decode($row['payload'] ?? '{}', true) ?: [];
        $row['steps'] = $this->fetchSteps($instanceId);
        $row['events'] = $this->fetchEvents($instanceId, 30);
        return $row;
    }

    /**
     * Apply input event: merge fields into instance payload (validation stub).
     *
     * @param array<string, mixed> $input
     * @param array $actor
     * @return array{ok:bool,error?:string,warnings?:list<string>}
     */
    public function applyInput(int $instanceId, array $input, array $actor): array
    {
        $this->requireTables();
        $instance = $this->fetchInstance($instanceId);
        if (!$instance) {
            return ['ok' => false, 'error' => 'Instance not found.'];
        }
        if (($instance['status'] ?? '') !== 'running') {
            return ['ok' => false, 'error' => 'Instance is not running.'];
        }

        $definition = $this->loadDefinitionForInstance($instance);
        if ($definition === null) {
            return ['ok' => false, 'error' => 'Definition file missing for this instance checksum.'];
        }

        // v1: shallow merge; field-level validation deferred to runtime UI
        $payload = is_array($instance['payload']) ? $instance['payload'] : [];
        foreach ($input as $k => $v) {
            if (is_string($k) && $k !== '') {
                $payload[$k] = $v;
            }
        }

        $this->updatePayload($instanceId, $payload);

        $this->emit(
            'input',
            $instanceId,
            'Input applied on step ' . ($instance['current_step'] ?? ''),
            ['keys' => array_keys($input)],
            $this->getStepId($instanceId, (string)$instance['current_step']),
            (string)$instance['current_step'],
            $actor
        );

        return ['ok' => true];
    }

    /**
     * Complete the current step and route to the next (sequential v1).
     *
     * @param array<string, mixed> $stepPayload
     * @param array $actor
     * @return array{ok:bool,error?:string,completed?:bool,next_step?:?string}
     */
    public function completeCurrentStep(
        int $instanceId,
        array $actor,
        array $stepPayload = [],
        ?string $notes = null
    ): array {
        $this->requireTables();
        $instance = $this->fetchInstance($instanceId);
        if (!$instance) {
            return ['ok' => false, 'error' => 'Instance not found.'];
        }
        if (($instance['status'] ?? '') !== 'running') {
            return ['ok' => false, 'error' => 'Instance is not running.'];
        }

        $definition = $this->loadDefinitionForInstance($instance);
        if ($definition === null) {
            return ['ok' => false, 'error' => 'Definition file missing for this instance.'];
        }

        $currentKey = (string)$instance['current_step'];
        $stepDef = $definition->getStepByKey($currentKey);
        if ($stepDef === null) {
            return ['ok' => false, 'error' => 'Current step not found in definition.'];
        }

        // Emit validation event (stub — always passes in skeleton)
        $this->emit(
            'validation',
            $instanceId,
            'Validation passed for step ' . $currentKey,
            ['step' => $currentKey],
            $this->getStepId($instanceId, $currentKey),
            $currentKey,
            $actor
        );

        // Mark step completed
        $this->markStepCompleted($instanceId, $currentKey, $actor, $stepPayload, $notes);

        $this->emit(
            'step_completed',
            $instanceId,
            'Step completed: ' . $currentKey,
            $stepPayload,
            $this->getStepId($instanceId, $currentKey),
            $currentKey,
            $actor
        );

        // Run step actions (stubs)
        $actions = $stepDef['actions'] ?? [];
        if (is_array($actions)) {
            foreach ($actions as $action) {
                if (!is_array($action)) {
                    continue;
                }
                $this->runActionStub($instanceId, $currentKey, $action, $actor);
            }
        }

        $nextKey = $definition->getNextStepKey($currentKey);
        if ($nextKey === null) {
            // Complete workflow
            foreach ($definition->getOnComplete() as $action) {
                $this->runActionStub($instanceId, $currentKey, $action, $actor);
            }
            $this->setInstanceStatus($instanceId, 'completed', $currentKey);
            $this->emit(
                'completed',
                $instanceId,
                'Workflow completed',
                ['workflow_id' => $definition->getId()],
                null,
                $currentKey,
                $actor
            );
            return ['ok' => true, 'completed' => true, 'next_step' => null];
        }

        // Routing event
        $this->activateStep($instanceId, $nextKey);
        $this->setInstanceStatus($instanceId, 'running', $nextKey);

        $this->emit(
            'routing',
            $instanceId,
            "Routed {$currentKey} → {$nextKey}",
            ['from' => $currentKey, 'to' => $nextKey],
            $this->getStepId($instanceId, $nextKey),
            $nextKey,
            $actor
        );

        $this->emit(
            'step_started',
            $instanceId,
            'Step started: ' . $nextKey,
            [],
            $this->getStepId($instanceId, $nextKey),
            $nextKey,
            $actor
        );

        return ['ok' => true, 'completed' => false, 'next_step' => $nextKey];
    }

    /**
     * Cancel a running instance (disposable — no ledger impact unless actions already ran).
     *
     * @param array $actor
     * @return array{ok:bool,error?:string}
     */
    public function cancelInstance(int $instanceId, array $actor, string $reason = ''): array
    {
        $this->requireTables();
        $instance = $this->fetchInstance($instanceId);
        if (!$instance) {
            return ['ok' => false, 'error' => 'Instance not found.'];
        }
        if (($instance['status'] ?? '') !== 'running') {
            return ['ok' => false, 'error' => 'Only running instances can be cancelled.'];
        }

        $this->setInstanceStatus($instanceId, 'cancelled', (string)$instance['current_step']);
        $this->emit(
            'cancelled',
            $instanceId,
            $reason !== '' ? $reason : 'Workflow cancelled',
            ['reason' => $reason],
            null,
            (string)$instance['current_step'],
            $actor
        );
        return ['ok' => true];
    }

    /**
     * List instances (optional filter by workflow_id / status).
     *
     * @return list<array<string, mixed>>
     */
    public function listInstances(?string $workflowId = null, ?string $status = null, int $limit = 100): array
    {
        $this->requireTables();
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT id, workflow_id, definition_version, definition_checksum, title, status, current_step,
                       created_by_user_id, transaction_detail_id, created_at, updated_at
                FROM workflow_instances WHERE 1=1';
        $types = '';
        $params = [];
        if ($workflowId !== null && $workflowId !== '') {
            $sql .= ' AND workflow_id = ?';
            $types .= 's';
            $params[] = $workflowId;
        }
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = ?';
            $types .= 's';
            $params[] = $status;
        }
        $sql .= ' ORDER BY updated_at DESC LIMIT ?';
        $types .= 'i';
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Whether actor may execute a definition (permissions.execute list).
     * Empty execute list → Administrator only.
     * Entries may be permission keys (workflow.*) or role names.
     *
     * @param array $actor Must include id; role_names optional
     */
    public function actorMayExecute(WorkflowDefinition $definition, array $actor): bool
    {
        $userId = (int)($actor['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        if (function_exists('userIsAdministrator') && userIsAdministrator($this->db, $userId)) {
            return true;
        }

        $allowed = $definition->getExecutePermissions();
        if ($allowed === []) {
            return false;
        }

        // Permission keys
        if (function_exists('userHasPermission')) {
            foreach ($allowed as $perm) {
                if (str_contains($perm, '.') && userHasPermission($this->db, $userId, $perm)) {
                    return true;
                }
            }
        }

        // Role names
        $roleNames = $actor['role_names'] ?? null;
        if (!is_array($roleNames) && function_exists('loadUserAcl')) {
            $acl = loadUserAcl($this->db, $userId);
            $roleNames = $acl['role_names'] ?? [($acl['role_name'] ?? '')];
        }
        if (!is_array($roleNames)) {
            $roleNames = [];
        }
        foreach ($allowed as $entry) {
            if (in_array($entry, $roleNames, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Future ledger integration: attach transaction_detail_id to instance.
     */
    public function linkLedgerTransaction(int $instanceId, int $transactionDetailId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE workflow_instances SET transaction_detail_id = ? WHERE id = ?'
        );
        if ($stmt) {
            $stmt->bind_param('ii', $transactionDetailId, $instanceId);
            $stmt->execute();
            $stmt->close();
        }
    }

    // ── internals ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $instance
     */
    private function loadDefinitionForInstance(array $instance): ?WorkflowDefinition
    {
        $checksum = (string)($instance['definition_checksum'] ?? '');
        if ($checksum !== '') {
            $def = $this->importer->loadDefinitionByChecksum($checksum);
            if ($def !== null) {
                return $def;
            }
        }
        $wfId = (string)($instance['workflow_id'] ?? '');
        $ver = isset($instance['definition_version']) ? (int)$instance['definition_version'] : null;
        return $this->importer->loadDefinition($wfId, $ver);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSteps(int $instanceId): array
    {
        $steps = [];
        $stmt = $this->db->prepare(
            'SELECT * FROM workflow_steps WHERE workflow_instance_id = ? ORDER BY step_order ASC, id ASC'
        );
        $stmt->bind_param('i', $instanceId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['payload'] = $row['payload'] ? (json_decode((string)$row['payload'], true) ?: []) : [];
            $steps[] = $row;
        }
        $stmt->close();
        return $steps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchEvents(int $instanceId, int $limit = 50): array
    {
        $events = [];
        $stmt = $this->db->prepare(
            'SELECT id, workflow_step_id, event_type, user_id, username, summary, details, created_at
             FROM workflow_events WHERE workflow_instance_id = ? ORDER BY id DESC LIMIT ?'
        );
        $stmt->bind_param('ii', $instanceId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['details'] = $row['details'] ? (json_decode((string)$row['details'], true) ?: []) : [];
            $events[] = $row;
        }
        $stmt->close();
        return $events;
    }

    private function getStepId(int $instanceId, string $stepKey): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM workflow_steps WHERE workflow_instance_id = ? AND step_key = ? LIMIT 1'
        );
        $stmt->bind_param('is', $instanceId, $stepKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function updatePayload(int $instanceId, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->prepare('UPDATE workflow_instances SET payload = ? WHERE id = ?');
        $stmt->bind_param('si', $json, $instanceId);
        $stmt->execute();
        $stmt->close();
    }

    private function setInstanceStatus(int $instanceId, string $status, string $currentStep): void
    {
        $stmt = $this->db->prepare(
            'UPDATE workflow_instances SET status = ?, current_step = ? WHERE id = ?'
        );
        $stmt->bind_param('ssi', $status, $currentStep, $instanceId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @param array $actor
     * @param array<string, mixed> $stepPayload
     */
    private function markStepCompleted(
        int $instanceId,
        string $stepKey,
        array $actor,
        array $stepPayload,
        ?string $notes
    ): void {
        $payloadJson = $stepPayload !== [] ? json_encode($stepPayload, JSON_UNESCAPED_UNICODE) : null;
        $userId = (int)$actor['id'];
        $username = (string)($actor['username'] ?? '');
        $stmt = $this->db->prepare(
            "UPDATE workflow_steps
             SET status = 'completed', completed_by_user_id = ?, completed_at = NOW(),
                 signature_username = ?, notes = ?, payload = ?
             WHERE workflow_instance_id = ? AND step_key = ?"
        );
        $stmt->bind_param('isssis', $userId, $username, $notes, $payloadJson, $instanceId, $stepKey);
        $stmt->execute();
        $stmt->close();
    }

    private function activateStep(int $instanceId, string $stepKey): void
    {
        $stmt = $this->db->prepare(
            "UPDATE workflow_steps SET status = 'active'
             WHERE workflow_instance_id = ? AND step_key = ?"
        );
        $stmt->bind_param('is', $instanceId, $stepKey);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Action stub — logs action event; real handlers registered later.
     *
     * @param array<string, mixed> $action
     * @param array $actor
     */
    private function runActionStub(int $instanceId, string $stepKey, array $action, array $actor): void
    {
        $type = (string)($action['type'] ?? 'noop');
        $this->emit(
            'action',
            $instanceId,
            'Action: ' . $type,
            ['action' => $action, 'action_type' => $type],
            $this->getStepId($instanceId, $stepKey),
            $stepKey,
            $actor
        );
        // Integration hooks (future):
        // create_ledger_entry / finalize_ledger → ledger_engine.php
        // attach_document → storage/attachments/{ledger sequence}/
    }

    /**
     * @param array<string, mixed> $payload
     * @param array $actor
     */
    private function emit(
        string $type,
        int $instanceId,
        string $summary,
        array $payload = [],
        ?int $stepId = null,
        ?string $stepKey = null,
        array $actor = []
    ): void {
        $event = new WorkflowEvent(
            $type,
            $instanceId,
            $summary,
            $payload,
            $stepId,
            $stepKey,
            isset($actor['id']) ? (int)$actor['id'] : null,
            (string)($actor['username'] ?? 'system')
        );
        $this->bus->emit($event, $this->db);
    }
}

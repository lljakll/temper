<?php
/**
 * Workflow event bus (v1 skeleton).
 *
 * The engine is events-based: input, routing, validation, step lifecycle, etc.
 * Handlers are registered here; runtime will emit events through WorkflowEventBus.
 * Final ledger audit remains on ledger documents — workflow events are operational only.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../../login.php');
    exit;
}

require_once __DIR__ . '/DefinitionDictionary.php';

/**
 * Immutable event payload.
 */
final class WorkflowEvent
{
    public string $type;
    public int $instanceId;
    public ?int $stepId;
    public ?string $stepKey;
    /** @var array<string, mixed> */
    public array $payload;
    public ?int $userId;
    public string $username;
    public string $summary;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $type,
        int $instanceId,
        string $summary,
        array $payload = [],
        ?int $stepId = null,
        ?string $stepKey = null,
        ?int $userId = null,
        string $username = 'system'
    ) {
        $this->type = $type;
        $this->instanceId = $instanceId;
        $this->stepId = $stepId;
        $this->stepKey = $stepKey;
        $this->payload = $payload;
        $this->userId = $userId;
        $this->username = $username;
        $this->summary = $summary;
    }
}

/**
 * Handler interface for future action modules / integrations.
 */
interface WorkflowEventHandler
{
    /**
     * @return list<string> Event types this handler cares about, or ['*'] for all.
     */
    public function subscribedEvents(): array;

    public function handle(WorkflowEvent $event, mysqli $db): void;
}

/**
 * Default handler: persist to workflow_events + optional system audit log.
 */
final class WorkflowPersistEventHandler implements WorkflowEventHandler
{
    public function subscribedEvents(): array
    {
        return ['*'];
    }

    public function handle(WorkflowEvent $event, mysqli $db): void
    {
        $json = $event->payload !== [] ? json_encode($event->payload, JSON_UNESCAPED_UNICODE) : null;
        $stepId = $event->stepId;
        $userId = $event->userId;
        $type = $event->type;
        $username = $event->username;
        $summary = mb_substr($event->summary, 0, 255);
        $instanceId = $event->instanceId;

        $stmt = $db->prepare(
            'INSERT INTO workflow_events
                (workflow_instance_id, workflow_step_id, event_type, user_id, username, summary, details)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt) {
            $stmt->bind_param(
                'iisisss',
                $instanceId,
                $stepId,
                $type,
                $userId,
                $username,
                $summary,
                $json
            );
            $stmt->execute();
            $stmt->close();
        }

        // Lightweight system audit trail (not a substitute for ledger document packages)
        if (function_exists('logAuditAction')) {
            logAuditAction(
                $db,
                $userId,
                $username,
                'workflow.' . $type,
                'instance=' . $instanceId . ' ' . $summary
            );
        }
    }
}

/**
 * Stub handler for ledger integration hooks (no-op until runtime wires ledger_engine).
 */
final class WorkflowLedgerHookHandler implements WorkflowEventHandler
{
    public function subscribedEvents(): array
    {
        return ['action', 'completed'];
    }

    public function handle(WorkflowEvent $event, mysqli $db): void
    {
        // Future: when payload.action_type is create_ledger_entry / finalize_ledger,
        // call includes/ledger_engine.php helpers. Intentionally empty in skeleton.
        unset($event, $db);
    }
}

/**
 * Simple in-process event bus.
 */
final class WorkflowEventBus
{
    /** @var list<WorkflowEventHandler> */
    private array $handlers = [];

    public function register(WorkflowEventHandler $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function emit(WorkflowEvent $event, mysqli $db): void
    {
        foreach ($this->handlers as $handler) {
            $subs = $handler->subscribedEvents();
            if (in_array('*', $subs, true) || in_array($event->type, $subs, true)) {
                $handler->handle($event, $db);
            }
        }
    }

    /**
     * Default bus with persist + ledger stub handlers.
     */
    public static function createDefault(): self
    {
        $bus = new self();
        $bus->register(new WorkflowPersistEventHandler());
        $bus->register(new WorkflowLedgerHookHandler());
        return $bus;
    }
}

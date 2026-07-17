<?php
/**
 * Definition dictionary for Temper workflow YAML.
 *
 * Documents the supported keys, field types, actions, and permission patterns
 * used by the validator. Expand as the engine grows — keep v1 minimal.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../../login.php');
    exit;
}

final class WorkflowDefinitionDictionary
{
    /** Spec version this engine understands. */
    public const SPEC_VERSION = 1;

    /**
     * Required top-level keys for a valid definition.
     *
     * @return list<string>
     */
    public static function requiredRootKeys(): array
    {
        return ['id', 'title', 'steps'];
    }

    /**
     * Optional top-level keys (known; unknown keys generate warnings).
     *
     * @return list<string>
     */
    public static function knownRootKeys(): array
    {
        return [
            'id',
            'title',
            'description',
            'version',
            'spec_version',
            'permissions',
            'ui',
            'pages',
            'steps',
            'on_complete',
            'meta',
        ];
    }

    /**
     * Supported field input types (pages.*.groups.*.fields.*.type).
     *
     * @return list<string>
     */
    public static function fieldTypes(): array
    {
        return [
            'text',
            'textarea',
            'number',
            'currency',
            'date',
            'select',
            'checkbox',
            'file',
            'hidden',
            'readonly',
        ];
    }

    /**
     * Supported engine action types (steps[].actions[], on_complete[]).
     * Handlers are stubs in v1 — registered for validation only.
     *
     * @return list<string>
     */
    public static function actionTypes(): array
    {
        return [
            'noop',
            'log_event',
            'create_ledger_entry',   // future: ledger_engine hook
            'update_ledger_entry',
            'finalize_ledger',
            'attach_document',
            'require_signature',
            'notify',               // future
            'set_status',
            'validate_totals',
        ];
    }

    /**
     * Supported workflow event types emitted by the engine.
     *
     * @return list<string>
     */
    public static function eventTypes(): array
    {
        return [
            'created',
            'input',
            'routing',
            'validation',
            'validation_failed',
            'step_started',
            'step_completed',
            'page_view',
            'action',
            'completed',
            'cancelled',
            'error',
        ];
    }

    /**
     * UI styles for guided multi-page (primary) or future single-page.
     *
     * @return list<string>
     */
    public static function uiStyles(): array
    {
        return ['guided_multipage', 'single_page'];
    }

    /**
     * Instance lifecycle statuses.
     *
     * @return list<string>
     */
    public static function instanceStatuses(): array
    {
        return ['running', 'completed', 'cancelled'];
    }

    /**
     * Terminal instance statuses (no further routing).
     *
     * @return list<string>
     */
    public static function terminalStatuses(): array
    {
        return ['completed', 'cancelled'];
    }

    /**
     * Permission keys reserved for definition management (not usage).
     * Usage permissions are declared per-workflow under permissions.execute.
     *
     * @return list<string>
     */
    public static function managementPermissions(): array
    {
        return [
            'workflow.manage', // import / delete / version definitions
        ];
    }

    /**
     * Role names that may manage definitions (plus Administrator via *).
     *
     * @return list<string>
     */
    public static function managementRoles(): array
    {
        return ['Administrator', 'Workflow Manager'];
    }

    /**
     * Human-readable summary of the dictionary for admin/docs UI.
     *
     * @return array<string, mixed>
     */
    public static function summarize(): array
    {
        return [
            'spec_version' => self::SPEC_VERSION,
            'required_root_keys' => self::requiredRootKeys(),
            'known_root_keys' => self::knownRootKeys(),
            'field_types' => self::fieldTypes(),
            'action_types' => self::actionTypes(),
            'event_types' => self::eventTypes(),
            'ui_styles' => self::uiStyles(),
        ];
    }
}

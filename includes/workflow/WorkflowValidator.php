<?php
/**
 * Validates a parsed workflow definition against DefinitionDictionary.
 *
 * Errors block import. Warnings are reported but do not block storage
 * when the definition is otherwise valid.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../../login.php');
    exit;
}

require_once __DIR__ . '/DefinitionDictionary.php';
require_once __DIR__ . '/WorkflowDefinition.php';

final class WorkflowValidationResult
{
    /** @var list<string> */
    public array $errors = [];
    /** @var list<string> */
    public array $warnings = [];
    public ?WorkflowDefinition $definition = null;

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }
}

final class WorkflowValidator
{
    /**
     * Validate raw parsed YAML data.
     *
     * @param array<string, mixed> $data
     */
    public function validate(array $data, ?string $sourcePath = null, ?string $checksum = null): WorkflowValidationResult
    {
        $result = new WorkflowValidationResult();

        // Required root keys
        foreach (WorkflowDefinitionDictionary::requiredRootKeys() as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                $result->addError("Missing required root key: {$key}");
            }
        }

        // Unknown root keys → warning
        $known = array_flip(WorkflowDefinitionDictionary::knownRootKeys());
        foreach (array_keys($data) as $key) {
            if (!isset($known[$key])) {
                $result->addWarning("Unknown root key ignored by engine: {$key}");
            }
        }

        // id
        $id = is_string($data['id'] ?? null) ? trim($data['id']) : '';
        if ($id === '') {
            $result->addError('id must be a non-empty string (slug).');
        } elseif (!preg_match('/^[a-z][a-z0-9_]{1,62}$/', $id)) {
            $result->addError(
                'id must be a lowercase slug: start with a letter, then letters/digits/underscore (max 63).'
            );
        }

        // title
        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
        if ($title === '') {
            $result->addError('title must be a non-empty string.');
        } elseif (strlen($title) > 200) {
            $result->addError('title must be 200 characters or fewer.');
        }

        // version / spec
        if (isset($data['version']) && (!is_int($data['version']) && !ctype_digit((string)$data['version']))) {
            $result->addWarning('version should be an integer; treating as 1 if missing/invalid at runtime.');
        }
        $spec = (int)($data['spec_version'] ?? WorkflowDefinitionDictionary::SPEC_VERSION);
        if ($spec !== WorkflowDefinitionDictionary::SPEC_VERSION) {
            $result->addWarning(
                "spec_version {$spec} differs from engine support "
                . WorkflowDefinitionDictionary::SPEC_VERSION . '; validation uses current dictionary.'
            );
        }

        // permissions
        $this->validatePermissions($data['permissions'] ?? null, $result);

        // ui
        $this->validateUi($data['ui'] ?? null, $result);

        // pages (optional in v1 but recommended for multipage)
        $pageKeys = $this->validatePages($data['pages'] ?? null, $result);

        // steps (required)
        $stepKeys = $this->validateSteps($data['steps'] ?? null, $pageKeys, $result);

        // on_complete actions
        if (isset($data['on_complete'])) {
            $this->validateActionList($data['on_complete'], 'on_complete', $result);
        }

        // Cross-checks
        if ($stepKeys !== [] && ($data['ui']['include_review'] ?? false)) {
            // Review page key convention
            if ($pageKeys !== [] && !in_array('review', $pageKeys, true)) {
                $result->addWarning(
                    'ui.include_review is true but no page with key "review" was found; engine will synthesize a summary later.'
                );
            }
        }

        if ($result->isValid()) {
            $result->definition = new WorkflowDefinition($data, $sourcePath, $checksum);
        }

        return $result;
    }

    private function validatePermissions(mixed $perms, WorkflowValidationResult $result): void
    {
        if ($perms === null) {
            $result->addWarning('permissions block omitted; no execute restriction will be enforced from definition.');
            return;
        }
        if (!is_array($perms)) {
            $result->addError('permissions must be a mapping.');
            return;
        }
        if (isset($perms['execute'])) {
            if (!is_array($perms['execute'])) {
                $result->addError('permissions.execute must be a list of permission keys or role names.');
            } else {
                foreach ($perms['execute'] as $i => $item) {
                    if (!is_string($item) || trim($item) === '') {
                        $result->addError("permissions.execute[{$i}] must be a non-empty string.");
                    }
                }
                if ($perms['execute'] === []) {
                    $result->addWarning('permissions.execute is empty; only administrators will be able to start instances.');
                }
            }
        } else {
            $result->addWarning('permissions.execute not set; usage access is unrestricted by definition (still requires workflow.view).');
        }
        foreach (array_keys($perms) as $k) {
            if ($k !== 'execute' && $k !== 'view') {
                $result->addWarning("Unknown permissions key: {$k}");
            }
        }
    }

    private function validateUi(mixed $ui, WorkflowValidationResult $result): void
    {
        if ($ui === null) {
            $result->addWarning('ui block omitted; defaulting to guided_multipage at runtime.');
            return;
        }
        if (!is_array($ui)) {
            $result->addError('ui must be a mapping.');
            return;
        }
        if (isset($ui['style'])) {
            $style = (string)$ui['style'];
            if (!in_array($style, WorkflowDefinitionDictionary::uiStyles(), true)) {
                $result->addError(
                    'ui.style must be one of: ' . implode(', ', WorkflowDefinitionDictionary::uiStyles())
                );
            }
        }
    }

    /**
     * @return list<string> page keys
     */
    private function validatePages(mixed $pages, WorkflowValidationResult $result): array
    {
        if ($pages === null) {
            $result->addWarning('pages omitted; multipage UI will rely on step.page references only.');
            return [];
        }
        if (!is_array($pages) || $this->isAssoc($pages)) {
            $result->addError('pages must be a list of page mappings.');
            return [];
        }

        $keys = [];
        $fieldTypes = array_flip(WorkflowDefinitionDictionary::fieldTypes());

        foreach ($pages as $i => $page) {
            $path = "pages[{$i}]";
            if (!is_array($page)) {
                $result->addError("{$path} must be a mapping.");
                continue;
            }
            $key = isset($page['key']) && is_string($page['key']) ? trim($page['key']) : '';
            if ($key === '') {
                $result->addError("{$path}.key is required.");
            } elseif (in_array($key, $keys, true)) {
                $result->addError("Duplicate page key: {$key}");
            } else {
                $keys[] = $key;
            }
            if (empty($page['title']) || !is_string($page['title'])) {
                $result->addWarning("{$path}.title missing; UI will fall back to key.");
            }

            $groups = $page['groups'] ?? null;
            if ($groups !== null) {
                if (!is_array($groups) || $this->isAssoc($groups)) {
                    $result->addError("{$path}.groups must be a list.");
                } else {
                    foreach ($groups as $gi => $group) {
                        $gpath = "{$path}.groups[{$gi}]";
                        if (!is_array($group)) {
                            $result->addError("{$gpath} must be a mapping.");
                            continue;
                        }
                        $fields = $group['fields'] ?? null;
                        if ($fields === null) {
                            continue;
                        }
                        if (!is_array($fields) || $this->isAssoc($fields)) {
                            $result->addError("{$gpath}.fields must be a list.");
                            continue;
                        }
                        foreach ($fields as $fi => $field) {
                            $fpath = "{$gpath}.fields[{$fi}]";
                            if (!is_array($field)) {
                                $result->addError("{$fpath} must be a mapping.");
                                continue;
                            }
                            $fname = isset($field['name']) && is_string($field['name']) ? trim($field['name']) : '';
                            if ($fname === '') {
                                $result->addError("{$fpath}.name is required.");
                            }
                            $ftype = isset($field['type']) ? (string)$field['type'] : 'text';
                            if (!isset($fieldTypes[$ftype])) {
                                $result->addError(
                                    "{$fpath}.type '{$ftype}' is not in dictionary. Known: "
                                    . implode(', ', WorkflowDefinitionDictionary::fieldTypes())
                                );
                            }
                        }
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * @param list<string> $pageKeys
     * @return list<string> step keys
     */
    private function validateSteps(mixed $steps, array $pageKeys, WorkflowValidationResult $result): array
    {
        if ($steps === null) {
            $result->addError('steps is required and must be a non-empty list.');
            return [];
        }
        if (!is_array($steps) || $this->isAssoc($steps)) {
            $result->addError('steps must be a list of step mappings.');
            return [];
        }
        if ($steps === []) {
            $result->addError('steps must contain at least one step.');
            return [];
        }

        $keys = [];
        $actionTypes = array_flip(WorkflowDefinitionDictionary::actionTypes());

        foreach ($steps as $i => $step) {
            $path = "steps[{$i}]";
            if (!is_array($step)) {
                $result->addError("{$path} must be a mapping.");
                continue;
            }
            $key = isset($step['key']) && is_string($step['key']) ? trim($step['key']) : '';
            if ($key === '') {
                $result->addError("{$path}.key is required.");
            } elseif (in_array($key, $keys, true)) {
                $result->addError("Duplicate step key: {$key}");
            } else {
                $keys[] = $key;
            }

            if (isset($step['page'])) {
                $pageRef = is_string($step['page']) ? $step['page'] : '';
                if ($pageRef !== '' && $pageKeys !== [] && !in_array($pageRef, $pageKeys, true)) {
                    $result->addError("{$path}.page references unknown page key '{$pageRef}'.");
                }
            } else {
                $result->addWarning("{$path}.page not set; guided UI may not know which page to show.");
            }

            if (isset($step['role']) && !is_string($step['role'])) {
                $result->addError("{$path}.role must be a string (role name).");
            }
            if (isset($step['permission']) && !is_string($step['permission'])) {
                $result->addError("{$path}.permission must be a string (permission key).");
            }

            if (isset($step['actions'])) {
                $this->validateActionList($step['actions'], "{$path}.actions", $result, $actionTypes);
            }

            if (isset($step['validations'])) {
                if (!is_array($step['validations'])) {
                    $result->addError("{$path}.validations must be a list.");
                }
            }
        }

        return $keys;
    }

    /**
     * @param array<string, true>|null $actionTypes
     */
    private function validateActionList(
        mixed $actions,
        string $path,
        WorkflowValidationResult $result,
        ?array $actionTypes = null
    ): void {
        if (!is_array($actions) || $this->isAssoc($actions)) {
            $result->addError("{$path} must be a list of action mappings.");
            return;
        }
        $types = $actionTypes ?? array_flip(WorkflowDefinitionDictionary::actionTypes());
        foreach ($actions as $i => $action) {
            $apath = "{$path}[{$i}]";
            if (!is_array($action)) {
                $result->addError("{$apath} must be a mapping.");
                continue;
            }
            $type = isset($action['type']) ? (string)$action['type'] : '';
            if ($type === '') {
                $result->addError("{$apath}.type is required.");
            } elseif (!isset($types[$type])) {
                $result->addError(
                    "{$apath}.type '{$type}' is not in dictionary. Known actions: "
                    . implode(', ', WorkflowDefinitionDictionary::actionTypes())
                );
            }
        }
    }

    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}

<?php
/**
 * Immutable in-memory representation of a loaded workflow definition.
 *
 * Source of truth remains the YAML file on disk; this object is derived
 * for validation and runtime. Never write back into the YAML file.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../../login.php');
    exit;
}

final class WorkflowDefinition
{
    private string $id;
    private string $title;
    private string $description;
    private int $version;
    private int $specVersion;
    /** @var array<string, mixed> */
    private array $permissions;
    /** @var array<string, mixed> */
    private array $ui;
    /** @var list<array<string, mixed>> */
    private array $pages;
    /** @var list<array<string, mixed>> */
    private array $steps;
    /** @var list<array<string, mixed>> */
    private array $onComplete;
    /** @var array<string, mixed> */
    private array $raw;
    private ?string $sourcePath;
    private ?string $checksum;

    /**
     * @param array<string, mixed> $data Parsed YAML root
     */
    public function __construct(array $data, ?string $sourcePath = null, ?string $checksum = null)
    {
        $this->raw = $data;
        $this->sourcePath = $sourcePath;
        $this->checksum = $checksum;

        $this->id = self::asString($data['id'] ?? '');
        $this->title = self::asString($data['title'] ?? '');
        $this->description = self::asString($data['description'] ?? '');
        $this->version = max(1, (int)($data['version'] ?? 1));
        $this->specVersion = (int)($data['spec_version'] ?? WorkflowDefinitionDictionary::SPEC_VERSION);
        $this->permissions = is_array($data['permissions'] ?? null) ? $data['permissions'] : [];
        $this->ui = is_array($data['ui'] ?? null) ? $data['ui'] : [];
        $this->pages = self::asListOfMaps($data['pages'] ?? []);
        $this->steps = self::asListOfMaps($data['steps'] ?? []);
        $this->onComplete = self::asListOfMaps($data['on_complete'] ?? []);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getSpecVersion(): int
    {
        return $this->specVersion;
    }

    /** @return array<string, mixed> */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * Permission keys (or role names) allowed to execute / start this workflow.
     *
     * @return list<string>
     */
    public function getExecutePermissions(): array
    {
        $exec = $this->permissions['execute'] ?? [];
        if (!is_array($exec)) {
            return [];
        }
        $out = [];
        foreach ($exec as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return array<string, mixed> */
    public function getUi(): array
    {
        return $this->ui;
    }

    public function getUiStyle(): string
    {
        $style = $this->ui['style'] ?? 'guided_multipage';
        return is_string($style) && $style !== '' ? $style : 'guided_multipage';
    }

    public function includesReview(): bool
    {
        return !empty($this->ui['include_review']);
    }

    /** @return list<array<string, mixed>> */
    public function getPages(): array
    {
        return $this->pages;
    }

    /** @return list<array<string, mixed>> */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /** @return list<array<string, mixed>> */
    public function getOnComplete(): array
    {
        return $this->onComplete;
    }

    /** @return array<string, mixed> */
    public function getRaw(): array
    {
        return $this->raw;
    }

    public function getSourcePath(): ?string
    {
        return $this->sourcePath;
    }

    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    /**
     * Ordered step keys (sequential v1).
     *
     * @return list<string>
     */
    public function getStepKeys(): array
    {
        $keys = [];
        foreach ($this->steps as $step) {
            $k = self::asString($step['key'] ?? '');
            if ($k !== '') {
                $keys[] = $k;
            }
        }
        return $keys;
    }

    public function getFirstStepKey(): ?string
    {
        $keys = $this->getStepKeys();
        return $keys[0] ?? null;
    }

    public function getStepByKey(string $key): ?array
    {
        foreach ($this->steps as $step) {
            if (self::asString($step['key'] ?? '') === $key) {
                return $step;
            }
        }
        return null;
    }

    public function getPageByKey(string $key): ?array
    {
        foreach ($this->pages as $page) {
            if (self::asString($page['key'] ?? '') === $key) {
                return $page;
            }
        }
        return null;
    }

    /**
     * Next sequential step key after $currentKey, or null if last.
     */
    public function getNextStepKey(string $currentKey): ?string
    {
        $keys = $this->getStepKeys();
        $i = array_search($currentKey, $keys, true);
        if ($i === false) {
            return null;
        }
        return $keys[$i + 1] ?? null;
    }

    /**
     * Compact meta for the DB index row (not a copy of the file).
     *
     * @return array<string, mixed>
     */
    public function indexMeta(): array
    {
        return [
            'spec_version' => $this->specVersion,
            'ui_style' => $this->getUiStyle(),
            'include_review' => $this->includesReview(),
            'page_count' => count($this->pages),
            'step_keys' => $this->getStepKeys(),
            'execute_permissions' => $this->getExecutePermissions(),
        ];
    }

    private static function asString(mixed $v): string
    {
        if (is_string($v)) {
            return trim($v);
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        return '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function asListOfMaps(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }
}

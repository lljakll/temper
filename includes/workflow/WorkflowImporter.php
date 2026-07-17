<?php
/**
 * Import workflow YAML definitions into immutable filesystem storage
 * and register them in the workflow_definitions index table.
 *
 * - Does not modify the YAML content after import.
 * - Validates against DefinitionDictionary; errors block, warnings allowed.
 * - Checksum + relative path stored in DB for version/instance stability.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../../login.php');
    exit;
}

require_once __DIR__ . '/../storage_paths.php';
require_once __DIR__ . '/../audit.php';
require_once __DIR__ . '/YamlParser.php';
require_once __DIR__ . '/WorkflowValidator.php';
require_once __DIR__ . '/WorkflowDefinition.php';
require_once __DIR__ . '/DefinitionDictionary.php';

final class WorkflowImportResult
{
    public bool $success = false;
    /** @var list<string> */
    public array $errors = [];
    /** @var list<string> */
    public array $warnings = [];
    public ?int $definitionRowId = null;
    public ?string $workflowId = null;
    public ?int $version = null;
    public ?string $storedPath = null;
    public ?string $checksum = null;
    public ?string $message = null;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'definition_row_id' => $this->definitionRowId,
            'workflow_id' => $this->workflowId,
            'version' => $this->version,
            'stored_path' => $this->storedPath,
            'checksum' => $this->checksum,
            'message' => $this->message,
        ];
    }
}

final class WorkflowImporter
{
    private mysqli $db;
    private WorkflowValidator $validator;

    public function __construct(mysqli $db, ?WorkflowValidator $validator = null)
    {
        $this->db = $db;
        $this->validator = $validator ?? new WorkflowValidator();
    }

    /**
     * Import from an uploaded or local file path.
     *
     * @param array|null $actor User row with id/username for audit (null = CLI/system)
     * @param bool $activate Set new version active (default true); prior active versions deactivated
     */
    public function importFromFile(string $sourcePath, ?array $actor = null, bool $activate = true): WorkflowImportResult
    {
        $result = new WorkflowImportResult();

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            $result->errors[] = 'Source file is not readable.';
            $result->message = 'Import failed.';
            return $result;
        }

        $raw = file_get_contents($sourcePath);
        if ($raw === false || $raw === '') {
            $result->errors[] = 'Source file is empty or unreadable.';
            $result->message = 'Import failed.';
            return $result;
        }

        return $this->importFromString($raw, basename($sourcePath), $actor, $activate);
    }

    /**
     * Import from YAML string content.
     *
     * @param array|null $actor
     */
    public function importFromString(
        string $yamlContent,
        string $originalFilename = 'definition.yaml',
        ?array $actor = null,
        bool $activate = true
    ): WorkflowImportResult {
        $result = new WorkflowImportResult();

        // Parse
        try {
            $data = WorkflowYamlParser::parse($yamlContent);
        } catch (Throwable $e) {
            $result->errors[] = 'YAML parse error: ' . $e->getMessage();
            $result->message = 'Import failed.';
            return $result;
        }

        $checksum = hash('sha256', $yamlContent);

        // Validate
        $validation = $this->validator->validate($data, null, $checksum);
        $result->warnings = $validation->warnings;
        if (!$validation->isValid() || $validation->definition === null) {
            $result->errors = $validation->errors;
            $result->message = 'Validation failed; definition was not stored.';
            return $result;
        }

        $definition = $validation->definition;
        $workflowId = $definition->getId();
        $version = $definition->getVersion();

        // Ensure storage directory
        $dirInfo = getWorkflowDefinitionsDir();
        if (!empty($dirInfo['error'])) {
            $result->errors[] = 'Storage not writable: ' . $dirInfo['error'];
            $result->message = 'Import failed.';
            return $result;
        }
        $baseDir = $dirInfo['path'];

        // Immutable filename: {id}.v{version}.yaml — refuse overwrite of different content
        $storedName = $workflowId . '.v' . $version . '.yaml';
        $destPath = $baseDir . '/' . $storedName;
        $relativePath = 'workflow-definitions/' . $storedName;

        if (is_file($destPath)) {
            $existingHash = hash_file('sha256', $destPath);
            if ($existingHash === $checksum) {
                // Same content already stored — re-index / activate only
                $rowId = $this->upsertIndex(
                    $workflowId,
                    $version,
                    $relativePath,
                    $checksum,
                    $definition,
                    $activate,
                    $actor
                );
                $result->success = true;
                $result->definitionRowId = $rowId;
                $result->workflowId = $workflowId;
                $result->version = $version;
                $result->storedPath = $relativePath;
                $result->checksum = $checksum;
                $result->message = 'Definition already present with matching checksum; index refreshed.';
                $result->warnings[] = 'File was not rewritten (immutable store; identical content).';
                return $result;
            }
            $result->errors[] = "A different definition already exists at {$storedName}. "
                . 'Bump version in YAML or remove the old file via admin delete.';
            $result->message = 'Import failed.';
            return $result;
        }

        // Write exactly as provided (normalize line endings only? — no: store as-is)
        $written = @file_put_contents($destPath, $yamlContent, LOCK_EX);
        if ($written === false) {
            $result->errors[] = 'Could not write definition file to storage.';
            $result->message = 'Import failed.';
            return $result;
        }
        @chmod($destPath, 0664);

        try {
            $rowId = $this->upsertIndex(
                $workflowId,
                $version,
                $relativePath,
                $checksum,
                $definition,
                $activate,
                $actor
            );
        } catch (Throwable $e) {
            // Roll back file on DB failure
            @unlink($destPath);
            $result->errors[] = 'Database index failed: ' . $e->getMessage();
            $result->message = 'Import failed.';
            return $result;
        }

        $result->success = true;
        $result->definitionRowId = $rowId;
        $result->workflowId = $workflowId;
        $result->version = $version;
        $result->storedPath = $relativePath;
        $result->checksum = $checksum;
        $result->message = $activate
            ? "Imported and activated {$workflowId} v{$version}."
            : "Imported {$workflowId} v{$version} (inactive).";

        if ($result->warnings !== []) {
            $result->message .= ' See warnings.';
        }

        return $result;
    }

    /**
     * Soft-deactivate a definition version (file remains; instances keep checksum reference).
     */
    public function deactivate(string $workflowId, int $version, ?array $actor = null): WorkflowImportResult
    {
        $result = new WorkflowImportResult();
        $stmt = $this->db->prepare(
            'UPDATE workflow_definitions SET is_active = 0 WHERE workflow_id = ? AND version = ?'
        );
        if (!$stmt) {
            $result->errors[] = 'Database error preparing deactivate.';
            return $result;
        }
        $stmt->bind_param('si', $workflowId, $version);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            $result->errors[] = 'No matching definition row found.';
            $result->message = 'Deactivate failed.';
            return $result;
        }

        $this->audit($actor, 'workflow.definition.deactivate', "{$workflowId} v{$version}");
        $result->success = true;
        $result->workflowId = $workflowId;
        $result->version = $version;
        $result->message = "Deactivated {$workflowId} v{$version}.";
        return $result;
    }

    /**
     * Delete index row and optionally the file. Refuses if running instances reference this checksum.
     */
    public function delete(string $workflowId, int $version, bool $deleteFile = true, ?array $actor = null): WorkflowImportResult
    {
        $result = new WorkflowImportResult();

        $stmt = $this->db->prepare(
            'SELECT id, file_path, checksum FROM workflow_definitions WHERE workflow_id = ? AND version = ? LIMIT 1'
        );
        $stmt->bind_param('si', $workflowId, $version);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $result->errors[] = 'Definition not found in index.';
            $result->message = 'Delete failed.';
            return $result;
        }

        $checksum = (string)$row['checksum'];
        $check = $this->db->prepare(
            "SELECT COUNT(*) AS c FROM workflow_instances
             WHERE definition_checksum = ? AND status = 'running'"
        );
        if ($check) {
            $check->bind_param('s', $checksum);
            $check->execute();
            $c = (int)($check->get_result()->fetch_assoc()['c'] ?? 0);
            $check->close();
            if ($c > 0) {
                $result->errors[] = "Cannot delete: {$c} running instance(s) reference this definition checksum.";
                $result->message = 'Delete failed.';
                return $result;
            }
        }

        $del = $this->db->prepare('DELETE FROM workflow_definitions WHERE id = ?');
        $id = (int)$row['id'];
        $del->bind_param('i', $id);
        $del->execute();
        $del->close();

        if ($deleteFile) {
            $abs = getStoragePath() . '/' . ltrim((string)$row['file_path'], '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
        }

        $this->audit($actor, 'workflow.definition.delete', "{$workflowId} v{$version}");
        $result->success = true;
        $result->workflowId = $workflowId;
        $result->version = $version;
        $result->message = "Deleted {$workflowId} v{$version} from index"
            . ($deleteFile ? ' and filesystem.' : ' (file retained).');
        return $result;
    }

    /**
     * List indexed definitions.
     *
     * @return list<array<string, mixed>>
     */
    public function listDefinitions(bool $activeOnly = false): array
    {
        $sql = 'SELECT id, workflow_id, version, title, file_path, checksum, is_active, meta, imported_by_user_id, created_at, updated_at
                FROM workflow_definitions';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY workflow_id ASC, version DESC';
        $rows = [];
        $res = $this->db->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['meta'] = $row['meta'] ? (json_decode((string)$row['meta'], true) ?: []) : [];
                $row['id'] = (int)$row['id'];
                $row['version'] = (int)$row['version'];
                $row['is_active'] = (int)$row['is_active'] === 1;
                $rows[] = $row;
            }
            $res->close();
        }
        return $rows;
    }

    /**
     * Load definition object from index row (by workflow_id active version, or specific version).
     */
    public function loadDefinition(string $workflowId, ?int $version = null): ?WorkflowDefinition
    {
        if ($version !== null) {
            $stmt = $this->db->prepare(
                'SELECT * FROM workflow_definitions WHERE workflow_id = ? AND version = ? LIMIT 1'
            );
            $stmt->bind_param('si', $workflowId, $version);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM workflow_definitions WHERE workflow_id = ? AND is_active = 1
                 ORDER BY version DESC LIMIT 1'
            );
            $stmt->bind_param('s', $workflowId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return $this->definitionFromIndexRow($row);
    }

    /**
     * Load definition by stored checksum (for in-progress instances).
     */
    public function loadDefinitionByChecksum(string $checksum): ?WorkflowDefinition
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM workflow_definitions WHERE checksum = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->bind_param('s', $checksum);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return $this->definitionFromIndexRow($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function definitionFromIndexRow(array $row): ?WorkflowDefinition
    {
        $rel = (string)($row['file_path'] ?? '');
        $abs = getStoragePath() . '/' . ltrim($rel, '/');
        if (!is_file($abs)) {
            return null;
        }
        $raw = file_get_contents($abs);
        if ($raw === false) {
            return null;
        }
        $fileChecksum = hash('sha256', $raw);
        $indexed = (string)($row['checksum'] ?? '');
        if ($indexed !== '' && !hash_equals($indexed, $fileChecksum)) {
            // File tampered after import — still load but caller may want to warn
            error_log('[workflow] checksum mismatch for ' . $rel);
        }
        try {
            $data = WorkflowYamlParser::parse($raw);
        } catch (Throwable $e) {
            return null;
        }
        return new WorkflowDefinition($data, $abs, $fileChecksum);
    }

    private function upsertIndex(
        string $workflowId,
        int $version,
        string $relativePath,
        string $checksum,
        WorkflowDefinition $definition,
        bool $activate,
        ?array $actor
    ): int {
        $title = $definition->getTitle();
        $metaJson = json_encode($definition->indexMeta(), JSON_UNESCAPED_UNICODE);
        $userId = isset($actor['id']) ? (int)$actor['id'] : null;
        $isActive = $activate ? 1 : 0;

        if ($activate) {
            $deact = $this->db->prepare(
                'UPDATE workflow_definitions SET is_active = 0 WHERE workflow_id = ? AND is_active = 1'
            );
            if ($deact) {
                $deact->bind_param('s', $workflowId);
                $deact->execute();
                $deact->close();
            }
        }

        // Existing row for same id+version?
        $sel = $this->db->prepare(
            'SELECT id FROM workflow_definitions WHERE workflow_id = ? AND version = ? LIMIT 1'
        );
        $sel->bind_param('si', $workflowId, $version);
        $sel->execute();
        $existing = $sel->get_result()->fetch_assoc();
        $sel->close();

        if ($existing) {
            $id = (int)$existing['id'];
            $upd = $this->db->prepare(
                'UPDATE workflow_definitions
                 SET title = ?, file_path = ?, checksum = ?, is_active = ?, meta = ?, imported_by_user_id = ?
                 WHERE id = ?'
            );
            $upd->bind_param('sssissi', $title, $relativePath, $checksum, $isActive, $metaJson, $userId, $id);
            $upd->execute();
            $upd->close();
            $this->audit($actor, 'workflow.definition.reindex', "{$workflowId} v{$version}");
            return $id;
        }

        $ins = $this->db->prepare(
            'INSERT INTO workflow_definitions
                (workflow_id, version, title, file_path, checksum, is_active, meta, imported_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->bind_param(
            'sisssisi',
            $workflowId,
            $version,
            $title,
            $relativePath,
            $checksum,
            $isActive,
            $metaJson,
            $userId
        );
        $ins->execute();
        $newId = (int)$ins->insert_id;
        $ins->close();
        $this->audit($actor, 'workflow.definition.import', "{$workflowId} v{$version} checksum={$checksum}");
        return $newId;
    }

    private function audit(?array $actor, string $action, string $details): void
    {
        $uid = isset($actor['id']) ? (int)$actor['id'] : null;
        $uname = $actor['username'] ?? 'system';
        logAuditAction($this->db, $uid, $uname, $action, $details);
    }
}

<?php

/**
 * Database schema validator for setup_db.php --check mode.
 * Compares live database structure against CREATE TABLE definitions
 * from setup-database/*.php files.
 */

class DbSchemaValidator
{
    private mysqli $db;
    private string $databaseName;
    private bool $verbose;
    /** @var list<array{type: string, message: string, fix?: string}> */
    private array $issues = [];
    private int $tablesChecked = 0;
    private int $columnsChecked = 0;
    private int $foreignKeysChecked = 0;

    public function __construct(mysqli $db, bool $verbose = false)
    {
        $this->db = $db;
        $this->verbose = $verbose;
        $this->databaseName = $db->query('SELECT DATABASE()')->fetch_row()[0] ?? '';
    }

    /** @return list<array{type: string, message: string, fix?: string}> */
    public function getIssues(): array
    {
        return $this->issues;
    }

    public function hasIssues(): bool
    {
        return count($this->issues) > 0;
    }

    /**
     * @param array<string, string> $tables table_name => CREATE TABLE SQL
     */
    public function validateAll(array $tables): void
    {
        if ($this->verbose) {
            echo "\n";
        }

        $this->validateDatabaseAccessible();

        if ($this->hasIssues()) {
            return;
        }

        $this->validatePermissions();

        if ($this->verbose) {
            echo "\nTables (" . count($tables) . " expected)\n";
        }

        foreach ($tables as $tableName => $createSql) {
            $this->validateTable($tableName, $createSql);
            $this->validateForeignKeys($tableName, $createSql);
            $this->tablesChecked++;
        }
    }

    private function validateDatabaseAccessible(): void
    {
        if ($this->db->connect_error) {
            $this->addIssue('connection', 'Database connection failed: ' . $this->db->connect_error);
            return;
        }

        if ($this->databaseName === '' || $this->databaseName === null) {
            $this->addIssue(
                'database',
                'Configured database is not accessible or does not exist.',
                'CREATE DATABASE `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
            );
            return;
        }

        $result = $this->db->query('SELECT 1');
        if ($result === false) {
            $this->addIssue('database', 'Database exists but is not queryable: ' . $this->db->error);
            return;
        }

        $this->logVerbose('Connection', "Database '{$this->databaseName}' is accessible");
    }

    private function validatePermissions(): void
    {
        $checks = [
            'SELECT' => 'SELECT 1 AS ok',
            'CREATE TEMPORARY TABLE' => 'CREATE TEMPORARY TABLE __setup_perm_check (id INT)',
            'INSERT' => 'INSERT INTO __setup_perm_check (id) VALUES (1)',
            'UPDATE' => 'UPDATE __setup_perm_check SET id = 2 WHERE id = 1',
            'DELETE' => 'DELETE FROM __setup_perm_check WHERE id = 2',
        ];

        foreach ($checks as $permission => $sql) {
            if ($this->db->query($sql) === false) {
                $this->addIssue(
                    'permission',
                    "Database user lacks {$permission} permission: " . $this->db->error,
                    'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES ON `' . $this->databaseName . '`.* TO \'' . DB_USER . '\'@\'%\';'
                );
                return;
            }

            $this->logVerbose('Permissions (user: ' . DB_USER . ')', $permission);
        }
    }

    private function validateTable(string $tableName, string $createSql): void
    {
        $exists = $this->db->query(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = '" . $this->db->real_escape_string($this->databaseName) . "'
             AND table_name = '" . $this->db->real_escape_string($tableName) . "'"
        );

        if ($exists === false || $exists->num_rows === 0) {
            $fixSql = preg_replace('/CREATE TABLE IF NOT EXISTS/i', 'CREATE TABLE', $createSql);
            $this->addIssue(
                'missing_table',
                "Required table '{$tableName}' does not exist.",
                rtrim($fixSql) . ';'
            );
            if ($this->verbose) {
                echo "  ✗ {$tableName} — missing\n";
            }
            return;
        }

        if ($this->verbose) {
            echo "  {$tableName}\n";
            echo "    ✓ table exists\n";
        }

        $expectedColumns = $this->parseColumnsFromCreateSql($createSql);
        $actualColumns = $this->fetchActualColumns($tableName);

        foreach ($expectedColumns as $columnName => $expected) {
            if (!isset($actualColumns[$columnName])) {
                $this->addIssue(
                    'missing_column',
                    "Table '{$tableName}' is missing required column '{$columnName}'.",
                    "ALTER TABLE `{$tableName}` ADD COLUMN {$expected['definition']};"
                );
                if ($this->verbose) {
                    echo "    ✗ column {$columnName} — missing\n";
                }
                continue;
            }

            $actual = $actualColumns[$columnName];
            $actualType = $actual['data_type'] !== '' ? $actual['data_type'] : $actual['column_type'];
            $typeMismatch = !$this->typesMatch($expected['base_type'], $actualType, $actual['column_type']);
            $nullMismatch = $expected['nullable'] !== $actual['is_nullable'];
            $autoIncrementMismatch = $expected['auto_increment'] !== $actual['extra_has_auto_increment'];

            if ($typeMismatch || $nullMismatch || $autoIncrementMismatch) {
                $details = [];
                if ($typeMismatch) {
                    $details[] = "expected type '{$expected['display_type']}', found '{$actual['column_type']}'";
                }
                if ($nullMismatch) {
                    $details[] = 'expected ' . ($expected['nullable'] ? 'NULL' : 'NOT NULL')
                        . ', found ' . ($actual['is_nullable'] ? 'NULL' : 'NOT NULL');
                }
                if ($autoIncrementMismatch) {
                    $details[] = $expected['auto_increment']
                        ? 'expected AUTO_INCREMENT'
                        : 'expected no AUTO_INCREMENT';
                }

                $this->addIssue(
                    'column_mismatch',
                    "Table '{$tableName}' column '{$columnName}' mismatch: " . implode('; ', $details) . '.',
                    "ALTER TABLE `{$tableName}` MODIFY COLUMN {$expected['definition']};"
                );
                if ($this->verbose) {
                    echo "    ✗ column {$columnName} — " . implode('; ', $details) . "\n";
                }
            } elseif ($this->verbose) {
                $nullLabel = $actual['is_nullable'] ? 'NULL' : 'NOT NULL';
                $extra = $actual['extra_has_auto_increment'] ? ', AUTO_INCREMENT' : '';
                echo "    ✓ column {$columnName} ({$actual['column_type']}, {$nullLabel}{$extra})\n";
            }

            $this->columnsChecked++;
        }
    }

    private function validateForeignKeys(string $tableName, string $createSql): void
    {
        $exists = $this->db->query(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = '" . $this->db->real_escape_string($this->databaseName) . "'
             AND table_name = '" . $this->db->real_escape_string($tableName) . "'"
        );

        if ($exists === false || $exists->num_rows === 0) {
            return;
        }

        $expected = $this->parseForeignKeysFromCreateSql($createSql);
        if ($expected === [] && !$this->verbose) {
            return;
        }

        if ($this->verbose && $expected !== []) {
            echo "    Foreign keys\n";
        }

        $actual = $this->fetchActualForeignKeys($tableName);

        foreach ($expected as $fk) {
            $match = null;
            foreach ($actual as $actualFk) {
                if ($actualFk['column'] === $fk['column']
                    && $actualFk['ref_table'] === $fk['ref_table']
                    && $actualFk['ref_column'] === $fk['ref_column']) {
                    $match = $actualFk;
                    break;
                }
            }

            if ($match === null) {
                $constraint = 'fk_' . $tableName . '_' . $fk['column'];
                $onDelete = $this->formatOnDeleteClause($fk['on_delete']);
                $this->addIssue(
                    'missing_foreign_key',
                    "Table '{$tableName}' is missing foreign key on column '{$fk['column']}' "
                    . "referencing '{$fk['ref_table']}({$fk['ref_column']})'.",
                    "ALTER TABLE `{$tableName}` ADD CONSTRAINT `{$constraint}` "
                    . "FOREIGN KEY (`{$fk['column']}`) REFERENCES `{$fk['ref_table']}`(`{$fk['ref_column']}`){$onDelete};"
                );
                if ($this->verbose) {
                    echo "    ✗ {$fk['column']} -> {$fk['ref_table']}({$fk['ref_column']}) — missing\n";
                }
                continue;
            }

            if ($match['on_delete'] !== $fk['on_delete']) {
                $this->addIssue(
                    'foreign_key_mismatch',
                    "Table '{$tableName}' foreign key on '{$fk['column']}' has ON DELETE {$match['on_delete']}, "
                    . "expected ON DELETE {$fk['on_delete']}.",
                    "ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$match['constraint_name']}`; "
                    . "ALTER TABLE `{$tableName}` ADD CONSTRAINT `{$match['constraint_name']}` "
                    . "FOREIGN KEY (`{$fk['column']}`) REFERENCES `{$fk['ref_table']}`(`{$fk['ref_column']}`) "
                    . "ON DELETE {$fk['on_delete']};"
                );
                if ($this->verbose) {
                    echo "    ✗ {$fk['column']} -> {$fk['ref_table']}({$fk['ref_column']}) "
                        . "ON DELETE {$match['on_delete']} (expected {$fk['on_delete']})\n";
                }
            } elseif ($this->verbose) {
                $onDeleteLabel = $fk['on_delete'] !== 'RESTRICT' ? " ON DELETE {$fk['on_delete']}" : '';
                echo "    ✓ {$fk['column']} -> {$fk['ref_table']}({$fk['ref_column']}){$onDeleteLabel}\n";
            }

            $this->foreignKeysChecked++;
        }
    }

    /**
     * @return list<array{column: string, ref_table: string, ref_column: string, on_delete: string}>
     */
    private function parseForeignKeysFromCreateSql(string $createSql): array
    {
        $body = $this->extractCreateTableBody($createSql);
        $parts = $this->splitTableBody($body);
        $foreignKeys = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if (!preg_match('/FOREIGN\s+KEY/i', $part)) {
                continue;
            }

            if (!preg_match(
                '/FOREIGN\s+KEY\s*\(([^)]+)\)\s*REFERENCES\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*\(([^)]+)\)'
                . '(?:\s+ON\s+DELETE\s+(CASCADE|SET\s+NULL|NO\s+ACTION|RESTRICT))?/i',
                $part,
                $matches
            )) {
                continue;
            }

            $onDelete = isset($matches[4]) ? strtoupper(preg_replace('/\s+/', ' ', trim($matches[4])) ?? 'RESTRICT') : 'RESTRICT';

            $foreignKeys[] = [
                'column' => trim(str_replace('`', '', $matches[1])),
                'ref_table' => $matches[2],
                'ref_column' => trim(str_replace('`', '', $matches[3])),
                'on_delete' => $onDelete,
            ];
        }

        return $foreignKeys;
    }

    /**
     * @return list<array{column: string, ref_table: string, ref_column: string, on_delete: string, constraint_name: string}>
     */
    private function fetchActualForeignKeys(string $tableName): array
    {
        $foreignKeys = [];
        $stmt = $this->db->prepare(
            'SELECT kcu.column_name, kcu.referenced_table_name, kcu.referenced_column_name,
                    rc.delete_rule, kcu.constraint_name
             FROM information_schema.key_column_usage kcu
             JOIN information_schema.referential_constraints rc
               ON kcu.constraint_name = rc.constraint_name
              AND kcu.table_schema = rc.constraint_schema
             WHERE kcu.table_schema = ?
               AND kcu.table_name = ?
               AND kcu.referenced_table_name IS NOT NULL'
        );
        $stmt->bind_param('ss', $this->databaseName, $tableName);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $foreignKeys[] = [
                'column' => $row['column_name'],
                'ref_table' => $row['referenced_table_name'],
                'ref_column' => $row['referenced_column_name'],
                'on_delete' => strtoupper($row['delete_rule']),
                'constraint_name' => $row['constraint_name'],
            ];
        }

        $stmt->close();
        return $foreignKeys;
    }

    /**
     * @return array<string, array{definition: string, base_type: string, display_type: string, nullable: bool, auto_increment: bool}>
     */
    private function parseColumnsFromCreateSql(string $createSql): array
    {
        $body = $this->extractCreateTableBody($createSql);
        $parts = $this->splitTableBody($body);
        $columns = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if ($this->isTableLevelConstraint($part)) {
                continue;
            }

            if (!preg_match('/^`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+(.+)$/s', $part, $matches)) {
                continue;
            }

            $columnName = $matches[1];
            $definition = rtrim($matches[2], ", \t\n\r");
            $definition = preg_replace('/\s+CHECK\s*\([^)]*\)/i', '', $definition) ?? $definition;
            $definition = preg_replace('/\s+COMMENT\s+\'[^\']*\'/i', '', $definition) ?? $definition;

            $isPrimaryKey = (bool) preg_match('/\bPRIMARY\s+KEY\b/i', $definition);
            $columns[$columnName] = [
                'definition' => "`{$columnName}` {$definition}",
                'base_type' => $this->normalizeColumnType($definition),
                'display_type' => $this->extractDisplayType($definition),
                'nullable' => !$isPrimaryKey
                    && !preg_match('/\bNOT\s+NULL\b/i', $definition),
                'auto_increment' => (bool) preg_match('/\bAUTO_INCREMENT\b/i', $definition),
            ];
        }

        return $columns;
    }

    private function extractCreateTableBody(string $createSql): string
    {
        if (!preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?[\w]+`?\s*\((.*)\)\s*$/is', $createSql, $matches)) {
            return '';
        }

        return trim($matches[1]);
    }

    /**
     * @return list<string>
     */
    private function splitTableBody(string $body): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    private function isTableLevelConstraint(string $part): bool
    {
        return (bool) preg_match(
            '/^(PRIMARY\s+KEY|UNIQUE\s+(?:KEY|INDEX)|(?:CONSTRAINT\s+`?\w+`?\s+)?FOREIGN\s+KEY|KEY|INDEX|CHECK)\b/i',
            $part
        );
    }

    /**
     * @return array<string, array{column_type: string, data_type: string, is_nullable: bool, extra_has_auto_increment: bool}>
     */
    private function fetchActualColumns(string $tableName): array
    {
        $columns = [];
        $stmt = $this->db->prepare(
            'SELECT column_name, column_type, data_type, is_nullable, extra
             FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ?
             ORDER BY ordinal_position'
        );
        $stmt->bind_param('ss', $this->databaseName, $tableName);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $columns[$row['column_name']] = [
                'column_type' => strtolower($row['column_type']),
                'data_type' => strtolower($row['data_type']),
                'is_nullable' => strtoupper($row['is_nullable']) === 'YES',
                'extra_has_auto_increment' => stripos($row['extra'], 'auto_increment') !== false,
            ];
        }

        $stmt->close();
        return $columns;
    }

    private function extractDisplayType(string $definition): string
    {
        if (preg_match(
            '/^((?:tiny|small|medium|big)?int(?:\s+unsigned)?|varchar\(\d+\)|char\(\d+\)|decimal\(\d+,\d+\)|'
            . 'enum\([^)]+\)|text|longtext|mediumtext|tinytext|datetime|timestamp|date|json|boolean|bool'
            . '(?:\s+unsigned)?)/i',
            $definition,
            $matches
        )) {
            return strtolower($matches[1]);
        }

        return strtolower(trim(explode(' ', $definition)[0] ?? ''));
    }

    private function normalizeColumnType(string $definition): string
    {
        $display = $this->extractDisplayType($definition);

        $map = [
            'boolean' => 'tinyint(1)',
            'bool' => 'tinyint(1)',
            'integer' => 'int',
            'int unsigned' => 'int unsigned',
        ];

        if (isset($map[$display])) {
            return $map[$display];
        }

        if ($display === 'int' || $display === 'integer') {
            return 'int';
        }

        return $display;
    }

    private function typesMatch(string $expected, string $actual, string $actualColumnType = ''): bool
    {
        $expected = $this->canonicalType($expected);
        $actual = $this->canonicalType($actual);

        if ($expected === $actual) {
            return true;
        }

        // JSON may appear as longtext in column_type on older MariaDB
        if ($expected === 'json' && ($actual === 'json' || $actualColumnType === 'longtext')) {
            return true;
        }

        // MySQL reports BOOLEAN as tinyint(1)
        if ($expected === 'tinyint(1)' && ($actual === 'tinyint' || $actual === 'tinyint(1)')) {
            return true;
        }

        // int vs int(11) display width differences
        if (preg_match('/^int$/', $expected) && preg_match('/^int(\(\d+\))?$/', $actual)) {
            return true;
        }

        // decimal precision match
        if (preg_match('/^decimal\((\d+),(\d+)\)$/', $expected, $exp)
            && preg_match('/^decimal\((\d+),(\d+)\)$/', $actualColumnType ?: $actual, $act)) {
            return $exp[1] === $act[1] && $exp[2] === $act[2];
        }

        // varchar length match
        if (preg_match('/^varchar\((\d+)\)$/', $expected, $exp)
            && preg_match('/^varchar\((\d+)\)$/', $actualColumnType ?: $actual, $act)) {
            return $exp[1] === $act[1];
        }

        // enum values (ignore spacing differences)
        if (preg_match('/^enum\((.+)\)$/', $expected, $exp)
            && preg_match('/^enum\((.+)\)$/', $actualColumnType ?: $actual, $act)) {
            return $this->normalizeEnumValues($exp[1]) === $this->normalizeEnumValues($act[1]);
        }

        return false;
    }

    private function normalizeEnumValues(string $values): string
    {
        return strtolower(preg_replace('/\s+/', '', $values) ?? $values);
    }

    private function formatOnDeleteClause(string $rule): string
    {
        if ($rule === 'RESTRICT' || $rule === 'NO ACTION') {
            return '';
        }

        return ' ON DELETE ' . $rule;
    }

    private function canonicalType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = str_replace(['boolean', 'bool'], 'tinyint(1)', $type);
        $type = preg_replace('/\binteger\b/', 'int', $type) ?? $type;
        $type = preg_replace('/\s+/', ' ', $type) ?? $type;
        return $type;
    }

    private function addIssue(string $type, string $message, ?string $fix = null): void
    {
        $issue = ['type' => $type, 'message' => $message];
        if ($fix !== null) {
            $issue['fix'] = $fix;
        }
        $this->issues[] = $issue;
    }

    private function logVerbose(string $section, string $message): void
    {
        if (!$this->verbose) {
            return;
        }

        static $lastSection = '';
        if ($section !== $lastSection) {
            if ($lastSection !== '') {
                echo "\n";
            }
            echo "{$section}\n";
            $lastSection = $section;
        }

        echo "  ✓ {$message}\n";
    }

    public function printReport(bool $verbose = false): int
    {
        if (!$this->hasIssues()) {
            echo "\n=== Summary ===\n";
            if ($verbose) {
                echo "Checked {$this->tablesChecked} tables, {$this->columnsChecked} columns, "
                    . "{$this->foreignKeysChecked} foreign keys — all OK\n";
            }
            echo "Database is ready\n";
            return 0;
        }

        if (!$verbose) {
            echo "\n=== Issues Found ===\n";
        }

        foreach ($this->issues as $index => $issue) {
            $num = $index + 1;
            echo "{$num}. [{$issue['type']}] {$issue['message']}\n";
            if (!empty($issue['fix'])) {
                echo "   Suggested fix: {$issue['fix']}\n";
            }
        }

        echo "\n=== Summary ===\n";
        echo count($this->issues) . " issue(s) found. Database is NOT ready.\n";
        return 1;
    }
}

/**
 * @param list<callable(): array{tables: array<string, string>}> $schemaProviders
 * @return array<string, string>
 */
function setupDbCollectSchemas(array $schemaProviders): array
{
    $tables = [];
    foreach ($schemaProviders as $provider) {
        $schema = $provider();
        foreach ($schema['tables'] as $tableName => $createSql) {
            $tables[$tableName] = $createSql;
        }
    }
    return $tables;
}
#!/usr/bin/env php
<?php
/**
 * CLI: import a workflow YAML definition.
 *
 * Usage:
 *   php scripts/workflow-import.php path/to/definition.yaml
 *   php scripts/workflow-import.php --validate-only path/to/definition.yaml
 *   php scripts/workflow-import.php --inactive path/to/definition.yaml
 *
 * Requires DB connectivity. Import is unrestricted on CLI (ops tool);
 * web UI enforces Admin / Workflow Manager / workflow.manage.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/storage_paths.php';
require_once $root . '/includes/workflow_bootstrap.php';

$args = array_slice($argv, 1);
$validateOnly = false;
$activate = true;
$paths = [];

foreach ($args as $arg) {
    if ($arg === '--validate-only' || $arg === '-n') {
        $validateOnly = true;
        continue;
    }
    if ($arg === '--inactive') {
        $activate = false;
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php scripts/workflow-import.php [--validate-only] [--inactive] <file.yaml>\n";
        exit(0);
    }
    if (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
    $paths[] = $arg;
}

if ($paths === []) {
    fwrite(STDERR, "Error: provide a YAML file path.\n");
    exit(1);
}

$file = $paths[0];
if (!is_file($file)) {
    // Try relative to project root
    $alt = $root . '/' . ltrim($file, '/');
    if (is_file($alt)) {
        $file = $alt;
    } else {
        fwrite(STDERR, "File not found: {$file}\n");
        exit(1);
    }
}

$raw = file_get_contents($file);
if ($raw === false) {
    fwrite(STDERR, "Could not read file.\n");
    exit(1);
}

try {
    $data = WorkflowYamlParser::parse($raw);
} catch (Throwable $e) {
    fwrite(STDERR, "YAML parse error: " . $e->getMessage() . "\n");
    exit(2);
}

$checksum = hash('sha256', $raw);
$validator = new WorkflowValidator();
$validation = $validator->validate($data, $file, $checksum);

foreach ($validation->warnings as $w) {
    fwrite(STDERR, "WARNING: {$w}\n");
}
foreach ($validation->errors as $e) {
    fwrite(STDERR, "ERROR: {$e}\n");
}

if (!$validation->isValid()) {
    fwrite(STDERR, "Validation failed.\n");
    exit(3);
}

echo "OK: valid definition id=" . $validation->definition->getId()
    . " version=" . $validation->definition->getVersion()
    . " checksum=" . $checksum . "\n";

if ($validateOnly) {
    echo "Validate-only mode; not stored.\n";
    exit(0);
}

$db = getDbConnection();
try {
    $engine = new WorkflowEngine($db);
    $engine->requireTables();
    $result = $engine->getImporter()->importFromFile($file, [
        'id' => 0,
        'username' => 'cli',
    ], $activate);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(4);
}

if (!$result->success) {
    foreach ($result->errors as $e) {
        fwrite(STDERR, "ERROR: {$e}\n");
    }
    exit(5);
}

echo $result->message . "\n";
echo "Stored: {$result->storedPath}\n";
if ($result->warnings !== []) {
    foreach ($result->warnings as $w) {
        fwrite(STDERR, "WARNING: {$w}\n");
    }
}
exit(0);

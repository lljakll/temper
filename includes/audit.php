<?php
// Security: Prevent direct access to this helper file
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

/**
 * Read-only check: audit_log table must exist with required columns.
 * Does not CREATE TABLE. Schema is owned by setup_db / updates/*.sql.
 *
 * @return list<string>
 */
function checkAuditLogTable(mysqli $db): array
{
    $issues = [];
    $res = $db->query("SHOW TABLES LIKE 'audit_log'");
    if (!$res || $res->num_rows === 0) {
        if ($res) {
            $res->close();
        }
        return ['table audit_log is missing'];
    }
    $res->close();

    foreach (['id', 'user_id', 'username', 'action', 'details', 'ip_address', 'created_at'] as $col) {
        $c = $db->query("SHOW COLUMNS FROM audit_log LIKE '" . $db->real_escape_string($col) . "'");
        if (!$c || $c->num_rows === 0) {
            $issues[] = "column audit_log.{$col} is missing";
        }
        if ($c) {
            $c->close();
        }
    }

    return $issues;
}

/**
 * Ensure audit_log schema is present (read-only). Logs and throws if outdated.
 * Does not create the table at runtime.
 */
function ensureAuditLogTable(mysqli $db): void {
    static $done = false;
    if ($done) {
        return;
    }

    $issues = checkAuditLogTable($db);
    if ($issues !== []) {
        temperSchemaOutOfDate('audit_log', $issues);
    }

    $done = true;
}

function logAuditAction(mysqli $db, ?int $userId, string $username, string $action, string $details = ''): void {
    ensureAuditLogTable($db);

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $db->prepare('INSERT INTO audit_log (user_id, username, action, details, ip_address) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('issss', $userId, $username, $action, $details, $ip);
    $stmt->execute();
    $stmt->close();

    error_log(sprintf(
        '[AUDIT] user=%s action=%s details=%s ip=%s',
        $username,
        $action,
        $details,
        $ip ?? 'unknown'
    ));
}

function exportAuditLogCsv(mysqli $db): string {
    ensureAuditLogTable($db);

    $csv = "id,user_id,username,action,details,ip_address,created_at\n";
    $res = $db->query('SELECT id, user_id, username, action, details, ip_address, created_at FROM audit_log ORDER BY id ASC');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $fields = [
                $row['id'],
                $row['user_id'] ?? '',
                $row['username'],
                $row['action'],
                $row['details'] ?? '',
                $row['ip_address'] ?? '',
                $row['created_at'],
            ];
            $csv .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $fields)) . "\n";
        }
        $res->close();
    }
    return $csv;
}

function clearAuditLog(mysqli $db): void {
    ensureAuditLogTable($db);
    if (!$db->query('TRUNCATE TABLE audit_log')) {
        $db->query('DELETE FROM audit_log');
    }
}

?>

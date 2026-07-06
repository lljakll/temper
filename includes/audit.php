<?php
// Security: Prevent direct access to this helper file
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

function ensureAuditLogTable(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        username VARCHAR(50) NOT NULL,
        action VARCHAR(100) NOT NULL,
        details TEXT,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_log_created_at (created_at),
        INDEX idx_audit_log_action (action)
    )");
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
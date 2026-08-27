-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260725_03_formalize_audit_log.sql
-- Schema ver.  : 20260725_03_formalize_audit_log
-- App version  : 0.804
-- Min app ver. : 0.804
-- Author date  : 2026-07-25
--
-- NOTES / PURPOSE
-- ---------------
-- Formalize audit_log as a first-class setup table (was previously created on
-- demand by ensureAuditLogTable at runtime). From v0.804 the application no
-- longer runs live CREATE/ALTER/seed on page load — all ensure* helpers are
-- read-only schema checks. This patch creates audit_log if missing so existing
-- installs match setup_db.php / setup-database/09-audit-log.php.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- None. CREATE TABLE IF NOT EXISTS is safe when the table already exists
-- (common: older builds auto-created it on first audit write).
--
--   SHOW TABLES LIKE 'audit_log';
--   SHOW CREATE TABLE audit_log\G
--
-- Safe to re-run: CREATE is idempotent; history INSERT is skipped when this
-- patch is already recorded.
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260725_03_formalize_audit_log.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260725_03_formalize_audit_log.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes: formalize audit_log
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    username VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_log_created_at (created_at),
    INDEX idx_audit_log_action (action)
);

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.804',
    '20260725_03_formalize_audit_log',
    '20260725_03_formalize_audit_log.sql',
    'Read-only schema checks; audit_log in setup; no live DDL/seed'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.804'
       OR schema_version = '20260725_03_formalize_audit_log'
       OR patch_file = '20260725_03_formalize_audit_log.sql'
);

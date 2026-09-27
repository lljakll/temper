-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260927_0964_ledger_locked_attachment_add.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.964
-- Min app ver. : 0.964
-- Author date  : 2026-09-27
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: cleared and reconciled transactions can receive new
-- attachments. Existing files stay put (no delete, replace, or move). No DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.963 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.964 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260927_0964_ledger_locked_attachment_add.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260927_0964_ledger_locked_attachment_add.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (attachment add rules only). Schema stem carried forward from
-- 0.944 / 0.963:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.964',
    '20260827_0944_setup_baseline_consolidation',
    '20260927_0964_ledger_locked_attachment_add.sql',
    'Add attachments on cleared/reconciled transactions; existing files stay locked; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.964'
       OR patch_file = '20260927_0964_ledger_locked_attachment_add.sql'
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260826_0942_ledger_temp_bulk_apply.sql
-- Schema ver.  : 20260825_0938_user_preferences   (carried forward — no DDL)
-- App version  : 0.942
-- Min app ver. : 0.942
-- Author date  : 2026-08-26
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: temporary Ledger bulk-apply helper for similar
-- pending transactions (counterpart account, fund, description, line note).
-- Admin/Treasurer only. Skips cleared/reconciled. Uses existing ledger
-- update helpers. Marked TEMP_BULK_TXN_MANAGER for later removal.
-- No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.941 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.942 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260826_0942_ledger_temp_bulk_apply.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260826_0942_ledger_temp_bulk_apply.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (temporary bulk-apply helper is application code only). Schema stem
-- carried forward from 0.938:
--   20260825_0938_user_preferences

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.942',
    '20260825_0938_user_preferences',
    '20260826_0942_ledger_temp_bulk_apply.sql',
    'Temporary Ledger bulk-apply for similar pending txns (Admin/Treasurer); no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.942'
       OR patch_file = '20260826_0942_ledger_temp_bulk_apply.sql'
);

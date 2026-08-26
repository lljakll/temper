-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260826_0939_pending_transaction_delete.sql
-- Schema ver.  : 20260825_0938_user_preferences   (carried forward — no DDL)
-- App version  : 0.939
-- Min app ver. : 0.939
-- Author date  : 2026-08-26
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Admin and Treasurer may delete pending (not cleared,
-- not reconciled) ledger transactions. Confirmation requires a delete reason.
-- Header, lines, document records, and attachment files are removed. A system
-- audit_log entry is written because transaction_events cascade away with the
-- header. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.938 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.939 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260826_0939_pending_transaction_delete.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260826_0939_pending_transaction_delete.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (pending-only transaction delete + audit_log write). Schema stem
-- carried forward from 0.938:
--   20260825_0938_user_preferences

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.939',
    '20260825_0938_user_preferences',
    '20260826_0939_pending_transaction_delete.sql',
    'Pending-only transaction delete for Admin/Treasurer; required reason; file cleanup; audit_log (no DDL)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.939'
       OR patch_file = '20260826_0939_pending_transaction_delete.sql'
);

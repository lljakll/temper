-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260915_0958_ledger_bulk_pending_delete.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.958
-- Min app ver. : 0.958
-- Author date  : 2026-09-15
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Ledger bulk delete of pending (not cleared, not
-- reconciled) transactions for Administrator and Treasurer, plus Select all
-- filtered so the current filter set can be selected beyond the loaded
-- infinite-scroll page. Confirmation requires one delete reason for the
-- batch. Header, lines, documents, and attachment files are removed. One
-- audit_log batch row lists every deleted ref #. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.957 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.958 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260915_0958_ledger_bulk_pending_delete.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260915_0958_ledger_bulk_pending_delete.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (bulk pending delete + select-all-filtered). Schema stem carried
-- forward from 0.944 / 0.957:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.958',
    '20260827_0944_setup_baseline_consolidation',
    '20260915_0958_ledger_bulk_pending_delete.sql',
    'Ledger bulk pending delete + Select all filtered; skipped cleared/reconciled; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.958'
       OR patch_file = '20260915_0958_ledger_bulk_pending_delete.sql'
);

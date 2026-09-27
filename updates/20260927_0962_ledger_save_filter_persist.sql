-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260927_0962_ledger_save_filter_persist.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.962
-- Min app ver. : 0.962
-- Author date  : 2026-09-27
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: saving or editing a ledger transaction no longer
-- turns transaction fields (pay_to, description, reference_number,
-- check_number, budget_id) into extra column filters. The active filter
-- scheme stays the ledger_filters payload. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.961 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.962 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260927_0962_ledger_save_filter_persist.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260927_0962_ledger_save_filter_persist.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (ledger filter request handling only). Schema stem carried forward from
-- 0.944 / 0.961:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.962',
    '20260827_0944_setup_baseline_consolidation',
    '20260927_0962_ledger_save_filter_persist.sql',
    'Ledger filters persist across transaction save; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.962'
       OR patch_file = '20260927_0962_ledger_save_filter_persist.sql'
);

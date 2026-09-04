-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260904_0945_ledger_list_check_budget_columns.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.945
-- Min app ver. : 0.945
-- Author date  : 2026-09-04
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: main Ledger transaction list shows Check # and Budget
-- from the transaction header (check_number and assigned budget name). Empty
-- check and no budget display as blank. List API/query includes the fields for
-- infinite scroll and existing filters; Excel-style sort/filter apply when
-- present. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.944 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.945 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260904_0945_ledger_list_check_budget_columns.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260904_0945_ledger_list_check_budget_columns.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (Check # and Budget list columns are application code only). Schema stem
-- carried forward from 0.944:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.945',
    '20260827_0944_setup_baseline_consolidation',
    '20260904_0945_ledger_list_check_budget_columns.sql',
    'Ledger list Check # and Budget columns from transaction header; blank if none; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.945'
       OR patch_file = '20260904_0945_ledger_list_check_budget_columns.sql'
);

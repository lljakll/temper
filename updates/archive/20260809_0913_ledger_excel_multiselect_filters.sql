-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260809_0913_ledger_excel_multiselect_filters.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.913
-- Min app ver. : 0.913
-- Author date  : 2026-08-09
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Ledger Excel-style multi-select auto-filters (search,
-- Select All, per-value checkboxes, hierarchical date tree), server-side multi
-- IN filters + filter_values JSON endpoint, and Clear all filters toolbar
-- button. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.912 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.913 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260809_0913_ledger_excel_multiselect_filters.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260809_0913_ledger_excel_multiselect_filters.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (ledger UI/API only). Schema stem carried forward from 0.901:
-- 20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.913',
    '20260801_0901_account_type_classification',
    '20260809_0913_ledger_excel_multiselect_filters.sql',
    'Ledger Excel-style multi-select auto-filters + Clear all filters'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.913'
       OR patch_file = '20260809_0913_ledger_excel_multiselect_filters.sql'
);

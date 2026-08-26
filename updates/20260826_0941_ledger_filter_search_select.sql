-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260826_0941_ledger_filter_search_select.sql
-- Schema ver.  : 20260825_0938_user_preferences   (carried forward — no DDL)
-- App version  : 0.941
-- Min app ver. : 0.941
-- Author date  : 2026-08-26
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Ledger Excel-style column auto-filter search now
-- selects matching unique values and deselects non-matches so Apply filters
-- the ledger to the search results only. Select All remains scoped to the
-- visible/search-filtered list. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.940 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.941 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260826_0941_ledger_filter_search_select.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260826_0941_ledger_filter_search_select.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (ledger auto-filter search selection is client-side JS only). Schema
-- stem carried forward from 0.938:
--   20260825_0938_user_preferences

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.941',
    '20260825_0938_user_preferences',
    '20260826_0941_ledger_filter_search_select.sql',
    'Ledger auto-filter search selects matching values on Apply (Excel-style); no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.941'
       OR patch_file = '20260826_0941_ledger_filter_search_select.sql'
);

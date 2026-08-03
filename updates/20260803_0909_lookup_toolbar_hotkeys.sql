-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260803_0909_lookup_toolbar_hotkeys.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.909
-- Min app ver. : 0.909
-- Author date  : 2026-08-03
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: compact Lookup toolbar (title + filter + actions on
-- one row), table-only font size controls, and leader-key hotkeys (; then
-- command) with discoverable help. Funds / Accounts / Natural / Functional.
-- No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.908 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.909 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260803_0909_lookup_toolbar_hotkeys.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260803_0909_lookup_toolbar_hotkeys.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (lookup UI only). Schema stem carried forward from 0.901:
-- 20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.909',
    '20260801_0901_account_type_classification',
    '20260803_0909_lookup_toolbar_hotkeys.sql',
    'Lookup toolbar: compact title row, table font size, leader hotkeys (; commands)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.909'
       OR patch_file = '20260803_0909_lookup_toolbar_hotkeys.sql'
);

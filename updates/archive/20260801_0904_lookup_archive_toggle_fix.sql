-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260801_0904_lookup_archive_toggle_fix.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.904
-- Min app ver. : 0.904
-- Author date  : 2026-08-01
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: fix Archive/Unarchive on Lookup maintenance pages
-- (Funds, Accounts, Natural Classes, Functional Classes). Checkbox FormData
-- sent "on" which PHP cast to 0, so archive never set archived=1. Funds also
-- lacked an Archive button handler. Toggle now updates DB + UI correctly.
-- No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.903 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.904 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260801_0904_lookup_archive_toggle_fix.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260801_0904_lookup_archive_toggle_fix.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (lookup UI/behavior only). Schema stem carried forward from 0.901:
-- 20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.904',
    '20260801_0901_account_type_classification',
    '20260801_0904_lookup_archive_toggle_fix.sql',
    'Lookup Archive/Unarchive toggle: fix checkbox POST cast; Funds handler; UI refresh'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.904'
       OR patch_file = '20260801_0904_lookup_archive_toggle_fix.sql'
);

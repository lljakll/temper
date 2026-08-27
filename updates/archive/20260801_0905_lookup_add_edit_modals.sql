-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260801_0905_lookup_add_edit_modals.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.905
-- Min app ver. : 0.905
-- Author date  : 2026-08-01
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: convert Lookup maintenance Add/Edit forms (Funds,
-- Accounts, Natural Classes, Functional Classes) from inline page sections
-- to Bootstrap modal dialogs with shared dirty-form protection on dismiss
-- (backdrop click, Escape, Cancel) and list refresh after successful save.
-- No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.904 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.905 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260801_0905_lookup_add_edit_modals.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260801_0905_lookup_add_edit_modals.sql;
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
    '0.905',
    '20260801_0901_account_type_classification',
    '20260801_0905_lookup_add_edit_modals.sql',
    'Lookup Add/Edit forms: inline sections → modal dialogs with dirty-state protection'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.905'
       OR patch_file = '20260801_0905_lookup_add_edit_modals.sql'
);

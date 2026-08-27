-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260801_0902_modal_form_autofocus.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.902
-- Min app ver. : 0.902
-- Author date  : 2026-08-01
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: reliable first-field autofocus for form-bearing Bootstrap
-- modals (Tasks Add Task, Users/Roles, Budget cycle, Ledger import, backup unlock,
-- etc.) via shared TemperModalFocus in includes/footer.php. Re-asserts focus if
-- SPA/AJAX activity steals it while a form modal remains open. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.901 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.902 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260801_0902_modal_form_autofocus.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260801_0902_modal_form_autofocus.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (UI/behavior only). Schema stem carried forward from 0.901:
-- 20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.902',
    '20260801_0901_account_type_classification',
    '20260801_0902_modal_form_autofocus.sql',
    'Modal form autofocus: first data field on open; resist SPA/AJAX focus steal'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.902'
       OR patch_file = '20260801_0902_modal_form_autofocus.sql'
);

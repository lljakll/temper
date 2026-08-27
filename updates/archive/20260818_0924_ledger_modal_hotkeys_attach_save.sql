-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260818_0924_ledger_modal_hotkeys_attach_save.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.924
-- Min app ver. : 0.924
-- Author date  : 2026-08-18
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Ledger Add/Edit modal uses Ctrl/Cmd hotkeys (work
-- while a field is focused), paperclip appears immediately after save+upload,
-- and a selected-but-not-uploaded file no longer causes a double save /
-- duplicate Reference #. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.923 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.924 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260818_0924_ledger_modal_hotkeys_attach_save.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260818_0924_ledger_modal_hotkeys_attach_save.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (Ledger Add/Edit modal hotkeys, paperclip, and save-upload only).
-- Schema stem carried forward from 0.901:
--   20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.924',
    '20260801_0901_account_type_classification',
    '20260818_0924_ledger_modal_hotkeys_attach_save.sql',
    'Ledger Ctrl/Cmd modal hotkeys, immediate paperclip after save-upload, single-save selected file'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.924'
       OR patch_file = '20260818_0924_ledger_modal_hotkeys_attach_save.sql'
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260820_0929_mass_import_button_handlers.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.929
-- Min app ver. : 0.929
-- Author date  : 2026-08-20
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Mass Import Parse and Clear buttons did not run because
-- SPA fragment injection (innerHTML) skips <script> tags. The page now uses
-- the existing text/plain init-script bootstrap. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.928 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.929 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260820_0929_mass_import_button_handlers.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260820_0929_mass_import_button_handlers.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (Mass Import SPA script bootstrap only). Schema stem carried forward
-- from 0.901:
--   20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.929',
    '20260801_0901_account_type_classification',
    '20260820_0929_mass_import_button_handlers.sql',
    'Mass Import Parse/Clear buttons: SPA init-script bootstrap (no DDL)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.929'
       OR patch_file = '20260820_0929_mass_import_button_handlers.sql'
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260814_0916_attachment_upload_size.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.916
-- Min app ver. : 0.916
-- Author date  : 2026-08-14
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: raise/remove the hard-coded 2 MB transaction
-- attachment upload limit so the app respects the effective PHP ceiling
-- (upload_max_filesize / post_max_size), targeting 20 MB. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.915 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.916 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260814_0916_attachment_upload_size.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260814_0916_attachment_upload_size.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (upload validation only). Schema stem carried forward from 0.901:
-- 20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.916',
    '20260801_0901_account_type_classification',
    '20260814_0916_attachment_upload_size.sql',
    'Attachment upload size follows PHP ceiling (20 MB); removed hard-coded 2 MB cap'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.916'
       OR patch_file = '20260814_0916_attachment_upload_size.sql'
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260819_0927_backup_include_storage_files.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.927
-- Min app ver. : 0.927
-- Author date  : 2026-08-19
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: data-only and full backups are zip packages that include
-- the database dump plus on-disk user data (attachments, system config, and
-- legacy transaction_documents). Restore of a package restores both. Legacy
-- SQL-only dumps remain restorable (database only). No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.926 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.927 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260819_0927_backup_include_storage_files.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260819_0927_backup_include_storage_files.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (backup/restore package contents only). Schema stem carried forward from 0.901:
--   20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.927',
    '20260801_0901_account_type_classification',
    '20260819_0927_backup_include_storage_files.sql',
    'Backup packages include DB dump plus attachments and system config (no DDL)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.927'
       OR patch_file = '20260819_0927_backup_include_storage_files.sql'
);

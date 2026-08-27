-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260809_0911_check_pending_schema_updates.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.911
-- Min app ver. : 0.911
-- Author date  : 2026-08-09
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: improve php setup_db.php --check messaging so operators
-- see a clear warning when the database is behind latest updates/*.sql patches,
-- with next-step guidance, and an explicit "no schema updates are pending"
-- message when fully current. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.910 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.911 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260809_0911_check_pending_schema_updates.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260809_0911_check_pending_schema_updates.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (setup_db.php --check messaging only). Schema stem carried forward from 0.901:
-- 20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.911',
    '20260801_0901_account_type_classification',
    '20260809_0911_check_pending_schema_updates.sql',
    'setup_db.php --check: clear warning when updates/*.sql patches are pending'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.911'
       OR patch_file = '20260809_0911_check_pending_schema_updates.sql'
);

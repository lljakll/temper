-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260807_0910_login_timeout_warning_and_devmode.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.910
-- Min app ver. : 0.910
-- Author date  : 2026-08-07
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: application idle timeout is 10 minutes when Developer
-- Mode is off, and fully disabled when Developer Mode is on (host ≈24 min
-- session cleaner remains). Idle warning modal is forced above other Bootstrap
-- modals without closing open forms. Status panel warns when the app timer is
-- disabled. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.909 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.910 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None. Legacy login_timeout_* keys in storage/config/system.json are ignored.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260807_0910_login_timeout_warning_and_devmode.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260807_0910_login_timeout_warning_and_devmode.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (auth/config/UI behavior only). Schema stem carried forward from 0.901:
-- 20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.910',
    '20260801_0901_account_type_classification',
    '20260807_0910_login_timeout_warning_and_devmode.sql',
    'Login timeout: 10m when Dev Mode off; disabled when on; warning modal stacking fix'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.910'
       OR patch_file = '20260807_0910_login_timeout_warning_and_devmode.sql'
);

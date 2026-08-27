-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260801_0903_login_timeout_disabled_authoritative.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.903
-- Min app ver. : 0.903
-- Author date  : 2026-08-01
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Login Timeout "disabled" is fully authoritative. When
-- Disable Login Timeout is on in System Configuration, sessions never idle-expire
-- (no warning modal, no idle redirect). PHP session.gc_maxlifetime is aligned to
-- the config so residual session GC cannot force logout. Single shell timer only.
-- No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.902 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.903 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260801_0903_login_timeout_disabled_authoritative.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260801_0903_login_timeout_disabled_authoritative.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (auth/session behavior only). Schema stem carried forward from 0.901:
-- 20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.903',
    '20260801_0901_account_type_classification',
    '20260801_0903_login_timeout_disabled_authoritative.sql',
    'Login Timeout disabled is authoritative: no idle modal/redirect; session GC aligned'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.903'
       OR patch_file = '20260801_0903_login_timeout_disabled_authoritative.sql'
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260802_0907_login_timeout_from_developer_mode.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.907
-- Min app ver. : 0.907
-- Author date  : 2026-08-02
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Login Timeout is no longer free-form or disableable.
-- Duration is fixed by Developer Mode (Off → 5 minutes, On → 20 minutes), always
-- under the host ~24-minute session file cleaner. System Configuration Status
-- panel regroups Developer Mode, Hard delete, Login timeout (read-only), and
-- Environment. Warning modal and server idle enforcement remain. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.906 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.907 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None. Legacy login_timeout_* keys in storage/config/system.json are ignored.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260802_0907_login_timeout_from_developer_mode.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260802_0907_login_timeout_from_developer_mode.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (config/auth behavior only). Schema stem carried forward from 0.901:
-- 20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.907',
    '20260801_0901_account_type_classification',
    '20260802_0907_login_timeout_from_developer_mode.sql',
    'Login timeout fixed by Developer Mode (5m/20m); Status panel regroup; no disable'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.907'
       OR patch_file = '20260802_0907_login_timeout_from_developer_mode.sql'
);

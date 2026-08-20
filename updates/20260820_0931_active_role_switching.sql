-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260820_0931_active_role_switching.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.931
-- Min app ver. : 0.931
-- Author date  : 2026-08-20
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: session-based active role switching (single role at a
-- time, not the union of assigned roles) and username (Role) stamps on
-- audit_log / transaction_events. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.930 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.931 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260820_0931_active_role_switching.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260820_0931_active_role_switching.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (session active-role + audit username format only). Schema stem carried
-- forward from 0.901:
--   20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.931',
    '20260801_0901_account_type_classification',
    '20260820_0931_active_role_switching.sql',
    'Active role switching (single session role) and role-stamped audit usernames (no DDL)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.931'
       OR patch_file = '20260820_0931_active_role_switching.sql'
);

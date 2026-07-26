-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260726_02_admin_version_outdated_indicator.sql
-- Schema ver.  : 20260725_03_formalize_audit_log   (carried forward — no DDL)
-- App version  : 0.807
-- Min app ver. : 0.807
-- Author date  : 2026-07-26
--
-- NOTES / PURPOSE
-- ---------------
-- Process / versioning release only (no table DDL). Administrators see the
-- sidebar app version in red with a tooltip when the database app_version is
-- behind the latest version known from VERSION.md / updates/ / APP_VERSION.
-- Non-admins keep the normal display. No patches are auto-applied.
-- This file advances app_version from 0.806 → 0.807 and carries forward the
-- existing schema stem (20260725_03_formalize_audit_log).
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.806 (or apply prior
-- post-baseline patches first). Safe to re-run: INSERT skipped when 0.807 exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260726_02_admin_version_outdated_indicator.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260726_02_admin_version_outdated_indicator.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (process-only release; schema stem carried forward).

-- ---------------------------------------------------------------------------
-- Data migrations (if any)
-- ---------------------------------------------------------------------------
-- None.

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.807',
    '20260725_03_formalize_audit_log',
    '20260726_02_admin_version_outdated_indicator.sql',
    'Admin sidebar red version + tooltip when DB lags latest available release'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.807'
       OR patch_file = '20260726_02_admin_version_outdated_indicator.sql'
);

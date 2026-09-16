-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260915_0959_church_brand_settings.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.959
-- Min app ver. : 0.959
-- Author date  : 2026-09-15
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: System Configuration church / organization display
-- name and optional icon (upload or select from storage). Browser tab title,
-- favicon, sidebar brand, and mobile header all read the same system.json
-- settings. Default remains "Hope Baptist Treasurer" until changed. No table
-- DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.958 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.959 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260915_0959_church_brand_settings.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260915_0959_church_brand_settings.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (organization name/icon in system.json). Schema stem carried
-- forward from 0.944 / 0.958:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.959',
    '20260827_0944_setup_baseline_consolidation',
    '20260915_0959_church_brand_settings.sql',
    'Church name and icon in System Config for tab title, favicon, and sidebar; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.959'
       OR patch_file = '20260915_0959_church_brand_settings.sql'
);

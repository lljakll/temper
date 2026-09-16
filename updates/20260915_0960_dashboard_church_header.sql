-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260915_0960_dashboard_church_header.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.960
-- Min app ver. : 0.960
-- Author date  : 2026-09-15
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Dashboard page header uses the System Configuration
-- church / organization display name and optional icon, with a Dashboard
-- subtitle and the existing period hint. No table DDL. System Configuration
-- catalog is unchanged (read-only).
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.959 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.960 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260915_0960_dashboard_church_header.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260915_0960_dashboard_church_header.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (dashboard header markup only). Schema stem carried forward from
-- 0.944 / 0.959:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.960',
    '20260827_0944_setup_baseline_consolidation',
    '20260915_0960_dashboard_church_header.sql',
    'Dashboard header uses church name and icon from System Config; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.960'
       OR patch_file = '20260915_0960_dashboard_church_header.sql'
);

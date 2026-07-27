-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260726_0808_patch_naming_and_sidebar_dual_version.sql
-- Schema ver.  : 20260725_03_formalize_audit_log   (carried forward — no DDL)
-- App version  : 0.808
-- Min app ver. : 0.808
-- Author date  : 2026-07-26
--
-- NOTES / PURPOSE
-- ---------------
-- Process / versioning release only (no table DDL). Establishes the new patch
-- filename convention YYYYMMDD_<appversion_without_decimal>_description.sql
-- (example: 0.806 → 20260726_0806_description.sql). Sidebar shows App + DB
-- versions side-by-side for Administrators; DB portion is red with a tooltip
-- when behind the latest available release. Non-admins see app version only.
-- This file advances app_version from 0.807 → 0.808 and carries forward the
-- existing schema stem (20260725_03_formalize_audit_log).
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.807 (or apply prior
-- post-baseline patches first). Safe to re-run: INSERT skipped when 0.808 exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260726_0808_patch_naming_and_sidebar_dual_version.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260726_0808_patch_naming_and_sidebar_dual_version.sql;
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
    '0.808',
    '20260725_03_formalize_audit_log',
    '20260726_0808_patch_naming_and_sidebar_dual_version.sql',
    'Patch names use app version token; admin sidebar App+DB dual display with lag warning'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.808'
       OR patch_file = '20260726_0808_patch_naming_and_sidebar_dual_version.sql'
);

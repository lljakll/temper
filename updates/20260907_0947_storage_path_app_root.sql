-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260907_0947_storage_path_app_root.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.947
-- Min app ver. : 0.947
-- Author date  : 2026-09-07
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: storage path resolution always prefers
-- <application root>/storage (e.g. /var/www/temper/storage). Parent/sibling
-- folders named storage (e.g. /var/www/storage) are no longer auto-selected.
-- Explicit TEMPER_STORAGE_PATH still overrides. No table DDL. No file
-- migration or deletion.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.946 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.947 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None. If both /var/www/storage and /var/www/temper/storage exist, operators
-- should confirm System → Configuration Status shows the intended root.
-- Do not delete either tree as part of applying this patch.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260907_0947_storage_path_app_root.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260907_0947_storage_path_app_root.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (storage path resolution is application code only). Schema stem carried
-- forward from 0.944 / 0.946:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.947',
    '20260827_0944_setup_baseline_consolidation',
    '20260907_0947_storage_path_app_root.sql',
    'Storage root is app/storage; parent /storage is not auto-selected; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.947'
       OR patch_file = '20260907_0947_storage_path_app_root.sql'
);

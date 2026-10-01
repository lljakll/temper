-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261001_0968_ref_suggestion_view_mode.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.968
-- Min app ver. : 0.968
-- Author date  : 2026-10-01
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: the Ref # suggestion tip fills the field only in Add
-- and full Edit. View mode ignores that gesture, and cleared/reconciled
-- budget-only edit keeps Ref # read-only. No DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.967 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.968 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20261001_0968_ref_suggestion_view_mode.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261001_0968_ref_suggestion_view_mode.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (Ref # gesture only). Schema stem carried forward from
-- 0.944 / 0.967:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.968',
    '20260827_0944_setup_baseline_consolidation',
    '20261001_0968_ref_suggestion_view_mode.sql',
    'Ref # suggestion fills the field only in Add and Edit; View leaves it unchanged; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.968'
       OR patch_file = '20261001_0968_ref_suggestion_view_mode.sql'
);

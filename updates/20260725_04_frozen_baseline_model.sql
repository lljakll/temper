-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260725_04_frozen_baseline_model.sql
-- Schema ver.  : 20260725_03_formalize_audit_log   (carried forward — no DDL)
-- App version  : 0.805
-- Min app ver. : 0.805
-- Author date  : 2026-07-25
--
-- NOTES / PURPOSE
-- ---------------
-- Process / versioning release only (no table DDL). Enforces the long-term
-- model: setup_db.php is frozen at the v0.804 baseline; every later app version
-- (starting with 0.805) is recorded and applied only via updates/*.sql patches.
-- This file advances app_version from 0.804 → 0.805 and carries forward the
-- existing schema version stem (20260725_03_formalize_audit_log).
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires the history-shaped app_version table at least through v0.804
-- (fresh setup seeds that baseline; older installs need prior patches first).
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- Safe to re-run: history INSERT is skipped when 0.805 is already recorded.
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260725_04_frozen_baseline_model.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260725_04_frozen_baseline_model.sql;
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
-- schema_version = previous stem (no DDL this release).
-- patch_file = this file so operators can see which patch advanced the app.
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.805',
    '20260725_03_formalize_audit_log',
    '20260725_04_frozen_baseline_model.sql',
    'Frozen setup baseline at 0.804; post-0.804 releases via updates/ only'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.805'
       OR patch_file = '20260725_04_frozen_baseline_model.sql'
);

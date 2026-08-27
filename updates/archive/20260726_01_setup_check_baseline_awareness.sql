-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260726_01_setup_check_baseline_awareness.sql
-- Schema ver.  : 20260725_03_formalize_audit_log   (carried forward — no DDL)
-- App version  : 0.806
-- Min app ver. : 0.806
-- Author date  : 2026-07-26
--
-- NOTES / PURPOSE
-- ---------------
-- Process / versioning release only (no table DDL). setup_db.php --check now
-- compares the highest app_version in the database against the frozen setup
-- baseline (app 0.804 / schema 20260725_03_formalize_audit_log) and warns when
-- the database is behind or history is incomplete. This file advances
-- app_version from 0.805 → 0.806 and carries forward the existing schema stem.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.805 (or v0.804 + prior
-- post-baseline patches). Safe to re-run: INSERT skipped when 0.806 exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260726_01_setup_check_baseline_awareness.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260726_01_setup_check_baseline_awareness.sql;
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
    '0.806',
    '20260725_03_formalize_audit_log',
    '20260726_01_setup_check_baseline_awareness.sql',
    'setup_db.php --check reports setup baseline vs database app_version'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.806'
       OR patch_file = '20260726_01_setup_check_baseline_awareness.sql'
);

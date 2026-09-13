-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260913_0955_dashboard_glance.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.955
-- Min app ver. : 0.955
-- Author date  : 2026-09-13
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: rebuild Dashboard / Home as a treasurer glance page
-- (cash/bank, period in/out, restricted snapshot, uncleared count, optional
-- tasks). Reuses users.preferences key dashboard.total_cash.account_ids and
-- existing fund-balance rules. No DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.954 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.955 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260913_0955_dashboard_glance.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260913_0955_dashboard_glance.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (Home glance is application code only). Schema stem carried
-- forward from 0.944 / 0.954:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.955',
    '20260827_0944_setup_baseline_consolidation',
    '20260913_0955_dashboard_glance.sql',
    'Home treasurer glance: cash/bank, period in/out, WDR snapshot, uncleared count; reuse dashboard.total_cash.account_ids; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.955'
       OR patch_file = '20260913_0955_dashboard_glance.sql'
);

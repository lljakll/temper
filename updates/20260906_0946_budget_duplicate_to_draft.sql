-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260906_0946_budget_duplicate_to_draft.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.946
-- Min app ver. : 0.946
-- Author date  : 2026-09-06
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Budget module can duplicate any existing budget
-- (draft, approved, active, or closed) into a new Draft. The copy flow
-- collects a new name plus fiscal year / start and end dates. Budget lines
-- (account, amount, notes) are copied; approval/activation fields and
-- transaction links are not. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.945 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.946 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260906_0946_budget_duplicate_to_draft.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260906_0946_budget_duplicate_to_draft.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (Duplicate-to-Draft is application code only). Schema stem carried
-- forward from 0.944 / 0.945:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.946',
    '20260827_0944_setup_baseline_consolidation',
    '20260906_0946_budget_duplicate_to_draft.sql',
    'Budget duplicate copies any budget to a new Draft with lines; no approval/transactions; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.946'
       OR patch_file = '20260906_0946_budget_duplicate_to_draft.sql'
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261029_0977_line_budget_dropdown.sql
-- Schema ver.  : 20261029_0976_line_budget_assignment   (carried forward — no DDL)
-- App version  : 0.977
-- Min app ver. : 0.977
-- Author date  : 2026-10-29
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release. Each transaction line's budget dropdown lists every
-- budget the header can use (active, approved, closed), even when the header
-- budget is blank and before an account is chosen. The saved value is still
-- that budget's line for the account. None remains "not in any budget."
-- No DDL. Budget reports, fund rules, and the 0.976 backfill are unchanged.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.976 (or apply prior
-- patches first). Safe to re-run: INSERT is skipped when 0.977 / this
-- patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20261029_0977_line_budget_dropdown.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261029_0977_line_budget_dropdown.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None. Schema stem carried forward from 0.976:
--   20261029_0976_line_budget_assignment

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.977',
    '20261029_0976_line_budget_assignment',
    '20261029_0977_line_budget_dropdown.sql',
    'Line budget dropdown lists every selectable budget; header still only pre-selects a matching line; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.977'
       OR patch_file = '20261029_0977_line_budget_dropdown.sql'
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261029_0979_budget_label_prefix.sql
-- Schema ver.  : 20261029_0976_line_budget_assignment   (carried forward — no DDL)
-- App version  : 0.979
-- Min app ver. : 0.979
-- Author date  : 2026-10-29
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release. Budget dropdowns and budget change descriptions show
-- the stored budget name. The fiscal-year prefix is no longer concatenated.
-- The transaction form Budget line column is narrower. No DDL. Line budget
-- assignment, budget report math, and the removed header Budget field are
-- unchanged.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.978 (or apply prior
-- patches first). Safe to re-run: INSERT is skipped when 0.979 / this
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
--   mysql -u temper_user -p temper_db < updates/20261029_0979_budget_label_prefix.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261029_0979_budget_label_prefix.sql;
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
    '0.979',
    '20261029_0976_line_budget_assignment',
    '20261029_0979_budget_label_prefix.sql',
    'Budget labels use the stored name; Budget line column is narrower; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.979'
       OR patch_file = '20261029_0979_budget_label_prefix.sql'
);

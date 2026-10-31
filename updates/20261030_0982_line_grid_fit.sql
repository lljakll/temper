-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261030_0982_line_grid_fit.sql
-- Schema ver.  : 20261029_0976_line_budget_assignment   (carried forward — no DDL)
-- App version  : 0.982
-- Min app ver. : 0.982
-- Author date  : 2026-10-30
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release. The transaction lines table stays the width of the
-- modal. An interior column drag moves width between that column and the
-- next column. The table does not grow and the grid does not scroll sideways.
-- No DDL. Budget labels, header fields, and the set of columns are unchanged.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.981 (or apply prior
-- patches first). Safe to re-run: INSERT is skipped when 0.982 / this
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
--   mysql -u temper_user -p temper_db < updates/20261030_0982_line_grid_fit.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261030_0982_line_grid_fit.sql;
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
    '0.982',
    '20261029_0976_line_budget_assignment',
    '20261030_0982_line_grid_fit.sql',
    'Lines grid stays inside the modal; interior resize redistributes width; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.982'
       OR patch_file = '20261030_0982_line_grid_fit.sql'
);

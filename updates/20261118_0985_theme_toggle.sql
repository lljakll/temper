-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261118_0985_theme_toggle.sql
-- Schema ver.  : 20261029_0976_line_budget_assignment   (carried forward — no DDL)
-- App version  : 0.985
-- Min app ver. : 0.985
-- Author date  : 2026-11-18
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release. The sidebar gains a Light / Dark control. The choice
-- is stored in the browser (localStorage key temper-theme). An unset choice
-- stays dark. Light still uses assets/css/temper-theme.css. No DDL. Theme
-- colors, layout, and ledger behavior are unchanged.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.984 (or apply prior
-- patches first). Safe to re-run: INSERT is skipped when 0.985 / this
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
--   mysql -u temper_user -p temper_db < updates/20261118_0985_theme_toggle.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261118_0985_theme_toggle.sql;
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
    '0.985',
    '20261029_0976_line_budget_assignment',
    '20261118_0985_theme_toggle.sql',
    'Sidebar Light / Dark switch; choice stored in the browser; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.985'
       OR patch_file = '20261118_0985_theme_toggle.sql'
);

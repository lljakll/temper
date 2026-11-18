-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261118_0983_light_mode_theme.sql
-- Schema ver.  : 20261029_0976_line_budget_assignment   (carried forward — no DDL)
-- App version  : 0.983
-- Min app ver. : 0.983
-- Author date  : 2026-11-18
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release. One light-mode stylesheet
-- (assets/css/temper-theme.css) loads from the shared header after Bootstrap.
-- It sets CSS custom properties and overrides Bootstrap 5 light variables.
-- Dark mode is unchanged. No DDL. Ledger, budget, and attachment paths are
-- unchanged.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.982 (or apply prior
-- patches first). Safe to re-run: INSERT is skipped when 0.983 / this
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
--   mysql -u temper_user -p temper_db < updates/20261118_0983_light_mode_theme.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261118_0983_light_mode_theme.sql;
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
    '0.983',
    '20261029_0976_line_budget_assignment',
    '20261118_0983_light_mode_theme.sql',
    'Light-mode theme stylesheet; Bootstrap variables; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.983'
       OR patch_file = '20261118_0983_light_mode_theme.sql'
);

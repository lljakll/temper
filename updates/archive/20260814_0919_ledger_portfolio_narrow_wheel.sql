-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260814_0919_ledger_portfolio_narrow_wheel.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.919
-- Min app ver. : 0.919
-- Author date  : 2026-08-14
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: narrow the ledger attachment portfolio modal (~75vw),
-- enlarge the close button, and use the mouse wheel over the preview pane to
-- turn multi-page PDF pages. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.918 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.919 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260814_0919_ledger_portfolio_narrow_wheel.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260814_0919_ledger_portfolio_narrow_wheel.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (ledger portfolio UI only). Schema stem carried forward from 0.901:
-- 20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.919',
    '20260801_0901_account_type_classification',
    '20260814_0919_ledger_portfolio_narrow_wheel.sql',
    'Portfolio viewer: narrower modal, larger close, wheel page-turn'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.919'
       OR patch_file = '20260814_0919_ledger_portfolio_narrow_wheel.sql'
);

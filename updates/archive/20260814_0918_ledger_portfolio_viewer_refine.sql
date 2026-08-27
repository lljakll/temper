-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260814_0918_ledger_portfolio_viewer_refine.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.918
-- Min app ver. : 0.918
-- Author date  : 2026-08-14
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: refine the ledger attachment portfolio viewer — viewport
-- height modal, static-width document/page selector panes, large file-type
-- icons, PDF page thumbnail panel, and fit-height default zoom. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.917 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.918 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260814_0918_ledger_portfolio_viewer_refine.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260814_0918_ledger_portfolio_viewer_refine.sql;
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
    '0.918',
    '20260801_0901_account_type_classification',
    '20260814_0918_ledger_portfolio_viewer_refine.sql',
    'Portfolio viewer: viewport modal, static panes, page panel, fit-height zoom'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.918'
       OR patch_file = '20260814_0918_ledger_portfolio_viewer_refine.sql'
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260814_0917_ledger_attachment_portfolio.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.917
-- Min app ver. : 0.917
-- Author date  : 2026-08-14
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: add a paperclip indicator on ledger list rows that have
-- attachments, and a portfolio-style modal viewer (sidebar + preview pane) for
-- that transaction's documents. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.916 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.917 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260814_0917_ledger_attachment_portfolio.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260814_0917_ledger_attachment_portfolio.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (ledger list UI + document JSON endpoint only). Schema stem carried
-- forward from 0.901: 20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.917',
    '20260801_0901_account_type_classification',
    '20260814_0917_ledger_attachment_portfolio.sql',
    'Ledger list paperclip indicator and portfolio attachment viewer'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.917'
       OR patch_file = '20260814_0917_ledger_attachment_portfolio.sql'
);

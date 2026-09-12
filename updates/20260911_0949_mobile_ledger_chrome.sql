-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260911_0949_mobile_ledger_chrome.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.949
-- Min app ver. : 0.949
-- Author date  : 2026-09-11
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: compact mobile Ledger action flyout and header Filters
-- icon, remove header logout duplicate, show the full mobile transaction list
-- via existing infinite-scroll / load-more, and remove outdated Dashboard
-- cards in favor of a simple page shell. No table DDL. No API or posting-rule
-- changes. Column-filter dropdown positioning is not changed.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.948 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.949 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260911_0949_mobile_ledger_chrome.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260911_0949_mobile_ledger_chrome.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (responsive UI is application code only). Schema stem carried
-- forward from 0.944 / 0.948:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.949',
    '20260827_0944_setup_baseline_consolidation',
    '20260911_0949_mobile_ledger_chrome.sql',
    'Mobile Ledger action flyout and header Filters; full mobile list; Dashboard cards removed; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.949'
       OR patch_file = '20260911_0949_mobile_ledger_chrome.sql'
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260825_0937_fund_tag_asset_ignored.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.937
-- Min app ver. : 0.937
-- Author date  : 2026-08-25
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: fund balances ignore fund tags on Asset accounts.
-- Only income, expense, and equity (Net Assets WODR/WDR) lines change a fund.
-- Asset fund tags may still be stored; they have no effect on balances.
-- No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.936 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.937 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260825_0937_fund_tag_asset_ignored.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260825_0937_fund_tag_asset_ignored.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (fund-balance calculation rule only). Schema stem carried forward
-- from 0.901:
--   20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.937',
    '20260801_0901_account_type_classification',
    '20260825_0937_fund_tag_asset_ignored.sql',
    'Fund balances ignore asset-account fund tags; only income/expense/equity lines count (no DDL)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.937'
       OR patch_file = '20260825_0937_fund_tag_asset_ignored.sql'
);

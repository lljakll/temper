-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260825_0936_ledger_form_layout_dblclick.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.936
-- Min app ver. : 0.936
-- Author date  : 2026-08-25
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Ledger Add/Edit form field widths, Accounts dropdown
-- grouping by account type, Debit/Credit currency display, and a persistable
-- double-click View/Edit toggle on the Ledger toolbar. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.935 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.936 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260825_0936_ledger_form_layout_dblclick.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260825_0936_ledger_form_layout_dblclick.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (Ledger UI polish only). Schema stem carried forward
-- from 0.901:
--   20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.936',
    '20260801_0901_account_type_classification',
    '20260825_0936_ledger_form_layout_dblclick.sql',
    'Ledger form field widths, account-type grouping, currency Debit/Credit, double-click View/Edit toggle (no DDL)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.936'
       OR patch_file = '20260825_0936_ledger_form_layout_dblclick.sql'
);

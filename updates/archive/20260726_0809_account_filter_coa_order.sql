-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260726_0809_account_filter_coa_order.sql
-- Schema ver.  : 20260725_03_formalize_audit_log   (carried forward — no DDL)
-- App version  : 0.809
-- Min app ver. : 0.809
-- Author date  : 2026-07-26
--
-- NOTES / PURPOSE
-- ---------------
-- Process / UI release only (no table DDL). Ledger Account View filter now
-- defaults to "All Accounts" instead of Bank Account / first debit account.
-- All account dropdowns (ledger lines, Account View, budget lines, reports)
-- and the Accounts setup list are ordered by coa_number ascending, with
-- null/empty CoA numbers last, then name, then id for a stable order.
-- This file advances app_version from 0.808 → 0.809 and carries forward the
-- existing schema stem (20260725_03_formalize_audit_log).
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.808 (or apply prior
-- post-baseline patches first). Safe to re-run: INSERT skipped when 0.809 exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260726_0809_account_filter_coa_order.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260726_0809_account_filter_coa_order.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (process-only release; schema stem carried forward).

-- ---------------------------------------------------------------------------
-- Data migrations (if any)
-- ---------------------------------------------------------------------------
-- None.

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.809',
    '20260725_03_formalize_audit_log',
    '20260726_0809_account_filter_coa_order.sql',
    'Account View defaults to All Accounts; account dropdowns ordered by coa_number'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.809'
       OR patch_file = '20260726_0809_account_filter_coa_order.sql'
);

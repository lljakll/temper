-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261118_0984_mobile_transaction_form.sql
-- Schema ver.  : 20261029_0976_line_budget_assignment   (carried forward — no DDL)
-- App version  : 0.984
-- Min app ver. : 0.984
-- Author date  : 2026-11-18
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release. Below 768px the transaction form stacks into one
-- column: header fields, then one card per line. Save, Cancel, and the
-- balance totals stay in the footer. Desktop column widths are unchanged.
-- No DDL. Validation, budget rules, fund rules, and attachments are unchanged.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.983 (or apply prior
-- patches first). Safe to re-run: INSERT is skipped when 0.984 / this
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
--   mysql -u temper_user -p temper_db < updates/20261118_0984_mobile_transaction_form.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261118_0984_mobile_transaction_form.sql;
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
    '0.984',
    '20261029_0976_line_budget_assignment',
    '20261118_0984_mobile_transaction_form.sql',
    'Phone transaction form stacks header fields and line cards; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.984'
       OR patch_file = '20261118_0984_mobile_transaction_form.sql'
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260816_0923_ledger_add_attachments_deposit_budget.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.923
-- Min app ver. : 0.923
-- Author date  : 2026-08-16
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Add Transaction modal exposes document upload, Save
-- auto-uploads a selected/queued file instead of discarding it, and deposit
-- transactions leave Budget unselected by default. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.922 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.923 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260816_0923_ledger_add_attachments_deposit_budget.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260816_0923_ledger_add_attachments_deposit_budget.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (Ledger Add/Edit modal behavior only). Schema stem carried forward
-- from 0.901: 20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.923',
    '20260801_0901_account_type_classification',
    '20260816_0923_ledger_add_attachments_deposit_budget.sql',
    'Ledger Add attachments, save-upload of selected files, blank budget on deposits'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.923'
       OR patch_file = '20260816_0923_ledger_add_attachments_deposit_budget.sql'
);

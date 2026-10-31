-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261029_0978_transaction_form_layout.sql
-- Schema ver.  : 20261029_0976_line_budget_assignment   (carried forward — no DDL)
-- App version  : 0.978
-- Min app ver. : 0.978
-- Author date  : 2026-10-29
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release. The transaction form no longer has a header Budget
-- control. Budget is line-level only. View mode shows the header and lines as
-- text. Natural and Functional are not on the form. No DDL. Budget reports,
-- fund rules, and budget labels are unchanged.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.977 (or apply prior
-- patches first). Safe to re-run: INSERT is skipped when 0.978 / this
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
--   mysql -u temper_user -p temper_db < updates/20261029_0978_transaction_form_layout.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261029_0978_transaction_form_layout.sql;
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
    '0.978',
    '20261029_0976_line_budget_assignment',
    '20261029_0978_transaction_form_layout.sql',
    'Transaction form: no header budget, view is text, natural/functional removed; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.978'
       OR patch_file = '20261029_0978_transaction_form_layout.sql'
);

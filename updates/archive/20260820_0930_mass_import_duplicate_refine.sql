-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260820_0930_mass_import_duplicate_refine.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.930
-- Min app ver. : 0.930
-- Author date  : 2026-08-20
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Mass Import duplicate handling. Same-batch matches stay
-- in the review list (Legitimate / Allow) instead of the side-by-side modal;
-- the modal is ledger-only. Amount is a stronger confirming/disconfirming
-- factor; live re-check after detail edits. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.929 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.930 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260820_0930_mass_import_duplicate_refine.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260820_0930_mass_import_duplicate_refine.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (Mass Import duplicate logic/UI only). Schema stem carried forward
-- from 0.901:
--   20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.930',
    '20260801_0901_account_type_classification',
    '20260820_0930_mass_import_duplicate_refine.sql',
    'Mass Import: same-batch allow checkbox, amount-weighted dupes, live re-check (no DDL)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.930'
       OR patch_file = '20260820_0930_mass_import_duplicate_refine.sql'
);

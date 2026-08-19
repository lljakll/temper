-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260819_0926_import_fuzzy_account_match.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.926
-- Min app ver. : 0.926
-- Author date  : 2026-08-19
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Ledger Import from Text matches account names with
-- tolerant/fuzzy scoring and a Match-accounts step when the match is unclear.
-- No table DDL. Manual Add/Edit and other import fields are unchanged.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.925 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.926 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260819_0926_import_fuzzy_account_match.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260819_0926_import_fuzzy_account_match.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (Ledger Import-from-Text matching only). Schema stem carried forward
-- from 0.901:
--   20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.926',
    '20260801_0901_account_type_classification',
    '20260819_0926_import_fuzzy_account_match.sql',
    'Import from Text: fuzzy account matching and resolve dialog (no DDL)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.926'
       OR patch_file = '20260819_0926_import_fuzzy_account_match.sql'
);

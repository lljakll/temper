-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260824_0933_attachment_file_sync.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.933
-- Min app ver. : 0.933
-- Author date  : 2026-08-24
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: keep on-disk attachment files in sync with transaction
-- documents (delete file with record; move files when Ref # changes; purge
-- files on Clear All Transactions) and record those file changes in
-- transaction_events while the transaction is still editable. Cleared or
-- reconciled transactions remain immutable (no file or file-audit changes).
-- No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.932 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.933 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260824_0933_attachment_file_sync.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260824_0933_attachment_file_sync.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (attachment file-sync + audit behavior only). Schema stem carried
-- forward from 0.901:
--   20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.933',
    '20260801_0901_account_type_classification',
    '20260824_0933_attachment_file_sync.sql',
    'Sync attachment files with deletes, Ref # changes, and full transaction purge; audit while editable (no DDL)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.933'
       OR patch_file = '20260824_0933_attachment_file_sync.sql'
);

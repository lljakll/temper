-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261017_0974_attachment_upload_without_reference.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.974
-- Min app ver. : 0.974
-- Author date  : 2026-10-17
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: an upload or scan on a transaction with no Reference #
-- is stored under storage/attachments/{transactionId}/. Saving a Reference #
-- moves those files into storage/attachments/{YY####}/. No DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.973 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.974 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20261017_0974_attachment_upload_without_reference.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261017_0974_attachment_upload_without_reference.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (attachment storage only). Schema stem carried forward from
-- 0.944 / 0.973:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.974',
    '20260827_0944_setup_baseline_consolidation',
    '20261017_0974_attachment_upload_without_reference.sql',
    'Attachments upload without a Reference # into the transaction id folder and move when a ref is saved; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.974'
       OR patch_file = '20261017_0974_attachment_upload_without_reference.sql'
);

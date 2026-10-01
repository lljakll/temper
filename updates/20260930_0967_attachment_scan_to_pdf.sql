-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260930_0967_attachment_scan_to_pdf.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.967
-- Min app ver. : 0.967
-- Author date  : 2026-09-30
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: the attachment control can scan one or more photos
-- into a single PDF. That PDF uses the existing upload and optimize path.
-- No DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.966 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.967 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260930_0967_attachment_scan_to_pdf.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260930_0967_attachment_scan_to_pdf.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (scan step only). Schema stem carried forward from
-- 0.944 / 0.966:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.967',
    '20260827_0944_setup_baseline_consolidation',
    '20260930_0967_attachment_scan_to_pdf.sql',
    'Scan pages to one PDF on the attachment control; same upload and optimize path; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.967'
       OR patch_file = '20260930_0967_attachment_scan_to_pdf.sql'
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261017_0972_pdf_optimize_exit_status.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.972
-- Min app ver. : 0.972
-- Author date  : 2026-10-17
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: PDF optimize keeps a finished Ghostscript file that is
-- smaller than the upload even when PHP reports exit -1. The gs status is read
-- after all pipes are closed. A missing gs binary is logged as gs_missing.
-- No DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.971 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.972 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20261017_0972_pdf_optimize_exit_status.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261017_0972_pdf_optimize_exit_status.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (PDF optimize status only). Schema stem carried forward from
-- 0.944 / 0.971:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.972',
    '20260827_0944_setup_baseline_consolidation',
    '20261017_0972_pdf_optimize_exit_status.sql',
    'PDF optimize keeps a valid smaller Ghostscript file when PHP reports exit -1; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.972'
       OR patch_file = '20261017_0972_pdf_optimize_exit_status.sql'
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261011_0970_pdf_optimize_headless_gs.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.970
-- Min app ver. : 0.970
-- Author date  : 2026-10-11
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: PDF optimize always runs Ghostscript with
-- -sDEVICE=pdfwrite, -dSAFER, -dBATCH, -dNOPAUSE, and -dNOPROMPT. The gs
-- process does not use an X11 display device. Ebook and screen profiles are
-- unchanged. If Ghostscript fails, the original file is stored and the upload
-- says PDF compression failed. No DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.969 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.970 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20261011_0970_pdf_optimize_headless_gs.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261011_0970_pdf_optimize_headless_gs.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (headless Ghostscript only). Schema stem carried forward from
-- 0.944 / 0.969:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.970',
    '20260827_0944_setup_baseline_consolidation',
    '20261011_0970_pdf_optimize_headless_gs.sql',
    'PDF optimize uses pdfwrite with no X11 display; failed gs keeps the original and says compression failed; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.970'
       OR patch_file = '20261011_0970_pdf_optimize_headless_gs.sql'
);

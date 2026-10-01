-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261001_0969_scan_adjust_enhance_pages.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.969
-- Min app ver. : 0.969
-- Author date  : 2026-10-01
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: document scan is capture, Adjust (crop, flip, free
-- rotate), Enhance (grayscale and contrast sliders), a page browser, then a
-- named PDF through the existing upload and optimize path. No DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.968 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.969 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20261001_0969_scan_adjust_enhance_pages.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261001_0969_scan_adjust_enhance_pages.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (scan steps only). Schema stem carried forward from
-- 0.944 / 0.968:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.969',
    '20260827_0944_setup_baseline_consolidation',
    '20261001_0969_scan_adjust_enhance_pages.sql',
    'Scan is Adjust, Enhance, page browser, then a named PDF on the existing upload; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.969'
       OR patch_file = '20261001_0969_scan_adjust_enhance_pages.sql'
);

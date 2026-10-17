-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261011_0971_scan_camera_review.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.971
-- Min app ver. : 0.971
-- Author date  : 2026-10-11
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: document scan opens the camera first, then one review
-- screen (corners, free rotate, Original / Grayscale / Black & white, page
-- thumbnails). Save names one PDF and uses the existing upload and optimize
-- path. No DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.970 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.971 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20261011_0971_scan_camera_review.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261011_0971_scan_camera_review.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (scan flow only). Schema stem carried forward from
-- 0.944 / 0.970:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.971',
    '20260827_0944_setup_baseline_consolidation',
    '20261011_0971_scan_camera_review.sql',
    'Scan opens the camera, then one review screen with filters and page thumbnails; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.971'
       OR patch_file = '20261011_0971_scan_camera_review.sql'
);

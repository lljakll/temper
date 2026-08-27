-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260725_02_schema_version_as_filename.sql
-- Schema ver.  : 20260725_02_schema_version_as_filename
-- App version  : 0.803
-- Min app ver. : 0.803
-- Author date  : 2026-07-25
--
-- NOTES / PURPOSE
-- ---------------
-- Change app_version.schema_version from integer generations (1, 2, …) to the
-- canonical patch filename stem (e.g. 20260725_01_app_version_history). Every
-- app version row must store a schema version; releases with no DDL carry
-- forward the previous schema version id. Also corrects historical rows so
-- v0.802 records 20260725_01_app_version_history and v0.801 records
-- setup_baseline.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires the v0.802 history-shaped app_version table (patch_file / notes /
-- applied_at columns). If still on the single-row v0.801 table, apply
-- updates/20260725_01_app_version_history.sql first.
--
--   SHOW CREATE TABLE app_version\G
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- Safe to re-run: ALTER is skipped when schema_version is already VARCHAR;
-- history INSERT is skipped when this patch is already recorded.
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None. Integer 1 → setup_baseline; integer 2 or patch_file stem → filename id.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260725_02_schema_version_as_filename.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260725_02_schema_version_as_filename.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes: schema_version INT → VARCHAR(128)
-- ---------------------------------------------------------------------------

SET @temper_sv_is_varchar := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_version'
      AND COLUMN_NAME = 'schema_version'
      AND DATA_TYPE IN ('varchar', 'char', 'text')
);

SET @temper_sv_sql := IF(
    @temper_sv_is_varchar = 0,
    'ALTER TABLE app_version
        MODIFY COLUMN schema_version VARCHAR(128) NOT NULL',
    'SELECT ''schema_version already VARCHAR; skip ALTER'' AS temper_patch_notice'
);
PREPARE temper_sv_stmt FROM @temper_sv_sql;
EXECUTE temper_sv_stmt;
DEALLOCATE PREPARE temper_sv_stmt;

-- ---------------------------------------------------------------------------
-- Data migrations: map legacy integers / fill from patch_file
-- ---------------------------------------------------------------------------

-- Prefer stem of patch_file when present (authoritative for that release).
-- Note: TRIM(TRAILING '.sql' ...) is NOT a string-suffix trim in MySQL/MariaDB.
UPDATE app_version
SET schema_version = CASE
        WHEN LOWER(patch_file) LIKE '%.sql'
            THEN LEFT(patch_file, CHAR_LENGTH(patch_file) - 4)
        ELSE patch_file
    END
WHERE patch_file IS NOT NULL
  AND patch_file <> ''
  AND (
        schema_version REGEXP '^[0-9]+$'
        OR schema_version = ''
        OR schema_version IS NULL
      );

-- v0.802 without usable patch_file still maps generation 2 → first patch stem.
UPDATE app_version
SET schema_version = '20260725_01_app_version_history'
WHERE version = '0.802'
  AND (
        schema_version = '2'
        OR schema_version REGEXP '^[0-9]+$'
      );

-- Pre-patch baseline (v0.801 / generation 1 / empty).
UPDATE app_version
SET schema_version = 'setup_baseline'
WHERE schema_version = '1'
   OR schema_version = ''
   OR schema_version IS NULL
   OR (version = '0.801' AND schema_version REGEXP '^[0-9]+$');

-- Any remaining pure-integer generations: leave only if already named;
-- force empty/zero to baseline.
UPDATE app_version
SET schema_version = 'setup_baseline'
WHERE schema_version REGEXP '^[0-9]+$';

-- Ensure v0.802 is exactly the first patch stem (historical correction).
UPDATE app_version
SET schema_version = '20260725_01_app_version_history',
    patch_file = COALESCE(NULLIF(patch_file, ''), '20260725_01_app_version_history.sql')
WHERE version = '0.802';

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.803',
    '20260725_02_schema_version_as_filename',
    '20260725_02_schema_version_as_filename.sql',
    'schema_version stores patch filename stem (not integer)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE patch_file = '20260725_02_schema_version_as_filename.sql'
       OR schema_version = '20260725_02_schema_version_as_filename'
       OR (version = '0.803')
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260725_01_app_version_history.sql
-- Schema ver.  : 20260725_01_app_version_history
--                (historical builds stored integer 2; v0.803 renorms to this stem)
-- App version  : 0.802
-- Min app ver. : 0.802
-- Author date  : 2026-07-25
--
-- NOTES / PURPOSE
-- ---------------
-- Convert app_version from a single-row “current version” store to an
-- append-only history table (version + schema_version + patch_file + notes +
-- applied_at). Formalizes the fully manual schema-update process under
-- updates/. Fresh installs already get this shape from setup_db.php; this
-- patch is for installs that still have the v0.801 single-row table.
-- Canonical schema id for this patch is the filename stem
-- 20260725_01_app_version_history (see 20260725_02 for INT→VARCHAR migration).
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Safe when app_version has a single row (id = 1) from v0.801.
-- If the table already has AUTO_INCREMENT id and patch_file/notes columns,
-- skip this patch (schema already at version 2). Check with:
--
--   SHOW CREATE TABLE app_version\G
--   SELECT id, version, schema_version FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None. Existing version/schema_version values are preserved into history.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260725_01_app_version_history.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260725_01_app_version_history.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes: rebuild app_version as history table
-- ---------------------------------------------------------------------------

SET @temper_av_is_history := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_version'
      AND COLUMN_NAME = 'patch_file'
);

-- Only migrate when the legacy single-row shape is present (no patch_file col).
SET @temper_av_sql := IF(
    @temper_av_is_history = 0,
    'RENAME TABLE app_version TO app_version_legacy_20260725',
    'SELECT ''app_version already history-shaped; skip rename'' AS temper_patch_notice'
);
PREPARE temper_av_stmt FROM @temper_av_sql;
EXECUTE temper_av_stmt;
DEALLOCATE PREPARE temper_av_stmt;

CREATE TABLE IF NOT EXISTS app_version (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(32) NOT NULL,
    schema_version INT NOT NULL DEFAULT 1,
    patch_file VARCHAR(128) NULL DEFAULT NULL,
    notes VARCHAR(512) NULL DEFAULT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_app_version_applied (applied_at),
    KEY idx_app_version_schema (schema_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copy legacy row(s) if the rename happened and history is empty
SET @temper_av_legacy := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_version_legacy_20260725'
);

SET @temper_av_rows := (SELECT COUNT(*) FROM app_version);

SET @temper_av_sql := IF(
    @temper_av_legacy > 0 AND @temper_av_rows = 0,
    'INSERT INTO app_version (version, schema_version, patch_file, notes, applied_at)
     SELECT version, schema_version, NULL,
            ''Migrated from single-row app_version (v0.801)'',
            COALESCE(updated_at, CURRENT_TIMESTAMP)
     FROM app_version_legacy_20260725
     ORDER BY id ASC',
    'SELECT ''No legacy copy needed'' AS temper_patch_notice'
);
PREPARE temper_av_stmt FROM @temper_av_sql;
EXECUTE temper_av_stmt;
DEALLOCATE PREPARE temper_av_stmt;

SET @temper_av_sql := IF(
    @temper_av_legacy > 0,
    'DROP TABLE app_version_legacy_20260725',
    'SELECT ''No legacy table to drop'' AS temper_patch_notice'
);
PREPARE temper_av_stmt FROM @temper_av_sql;
EXECUTE temper_av_stmt;
DEALLOCATE PREPARE temper_av_stmt;

-- ---------------------------------------------------------------------------
-- Data migrations
-- ---------------------------------------------------------------------------
-- Ensure a baseline history row exists if the table was empty (edge case).
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT '0.801', 1, NULL, 'Baseline row created by 20260725_01 (no prior history)'
WHERE NOT EXISTS (SELECT 1 FROM app_version LIMIT 1);

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
-- Skip duplicate if this patch was already recorded (re-run safety).
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.802',
    2,
    '20260725_01_app_version_history.sql',
    'app_version full history; formalized manual schema patches (updates/)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE patch_file = '20260725_01_app_version_history.sql'
       OR (version = '0.802' AND schema_version = 2)
);

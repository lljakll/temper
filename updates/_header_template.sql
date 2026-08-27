-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : YYYYMMDD_<appversion_without_decimal>_short_description.sql
-- Schema ver.  : YYYYMMDD_<appversion_without_decimal>_short_description   (filename stem; no .sql)
-- App version  : X.YYY                (release that requires this patch)
-- Min app ver. : X.YYY                (do not apply on older codebases)
-- Author date  : YYYY-MM-DD
--
-- NOTES / PURPOSE
-- ---------------
-- <What this patch changes and why. One short paragraph is enough.>
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- <None | Describe rows that may fail constraints, required cleanup, or
--  SELECT statements operators should run before applying.>
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- <None | Example UPDATE/INSERT statements to fix conflicting data before
--  or after the schema change. These are guidance, not always required.>
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/YYYYMMDD_<appversion_without_decimal>_short_description.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /absolute/path/to/temper/updates/YYYYMMDD_<appversion_without_decimal>_short_description.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================
--
-- Filename convention (new patches, app 0.808+):
--   YYYYMMDD_<appversion_without_decimal>_short_description.sql
--   Example: app version 0.806 → 20260726_0806_description.sql
-- Older patches may still use YYYYMMDD_NN_description.sql; leave them as-is.
-- Helper: temperBuildPatchFilename() / temperAppVersionToPatchToken() in includes/app_version.php
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------

-- <DDL here>


-- ---------------------------------------------------------------------------
-- Data migrations (if any)
-- ---------------------------------------------------------------------------

-- <DML here>


-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
-- setup_db.php is frozen at v0.944. Post-0.944 releases MUST record here
-- (never by extending TEMPER_VERSION_HISTORY / setup seed).
--
-- schema_version = this patch's filename stem when DDL is included;
--                  otherwise carry forward the previous schema stem.
-- patch_file     = this file's basename (even for process-only patches).
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    'X.YYY',
    'YYYYMMDD_<appversion_without_decimal>_short_description',  -- or prior stem if no DDL
    'YYYYMMDD_<appversion_without_decimal>_short_description.sql',
    'Short note matching VERSION.md summary'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = 'X.YYY'
       OR patch_file = 'YYYYMMDD_<appversion_without_decimal>_short_description.sql'
);

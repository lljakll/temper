-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : YYYYMMDD_NN_short_description.sql
-- Schema ver.  : N                    (integer; must match app_version insert)
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
--   mysql -u temper_user -p temper_db < updates/YYYYMMDD_NN_short_description.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /absolute/path/to/temper/updates/YYYYMMDD_NN_short_description.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
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
INSERT INTO app_version (version, schema_version, patch_file, notes)
VALUES (
    'X.YYY',
    N,
    'YYYYMMDD_NN_short_description.sql',
    'Short note matching VERSION.md summary'
);

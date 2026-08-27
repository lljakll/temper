-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260726_0900_beta_baseline.sql
-- Schema ver.  : 20260726_0811_tx_memo_to_description   (carried forward — no DDL)
-- App version  : 0.900
-- Min app ver. : 0.900
-- Author date  : 2026-07-26
--
-- NOTES / PURPOSE
-- ---------------
-- Official beta (v0.900) milestone: consolidates the setup_db.php baseline so a
-- destructive setup leaves the database at 0.900 with full version history
-- through 0.900 and schema shape through 0.811 (transaction_details.description).
-- Demo seed data for accounts, budgets, and transactions is removed from setup;
-- lookup/reference data (roles, natural/functional categories, structural funds)
-- and default users remain.
--
-- This release has **no table DDL** for existing installs already at 0.811.
-- Fresh installs get the consolidated baseline from setup_db.php (no need to
-- replay pre-0.900 patches). Existing installs apply this patch only to record
-- the 0.900 history row.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.811 (or apply prior patches
-- first, or re-run full setup for a clean beta baseline). Safe to re-run:
-- INSERT is skipped when 0.900 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--   SHOW COLUMNS FROM transaction_details LIKE 'description';
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None. Optional: after a destructive setup_db.php for beta, re-enter real
-- accounts/budgets/transactions via the UI (no demo data is re-seeded).
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260726_0900_beta_baseline.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260726_0900_beta_baseline.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (process / baseline consolidation only). Schema stem carried forward
-- from 0.811: 20260726_0811_tx_memo_to_description

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.900',
    '20260726_0811_tx_memo_to_description',
    '20260726_0900_beta_baseline.sql',
    'Beta start: setup baseline consolidated through 0.811; no demo accounts/budgets/transactions'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.900'
       OR patch_file = '20260726_0900_beta_baseline.sql'
);

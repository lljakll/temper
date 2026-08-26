-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260826_0940_transaction_line_notes.sql
-- Schema ver.  : 20260825_0938_user_preferences   (carried forward — no DDL)
-- App version  : 0.940
-- Min app ver. : 0.940
-- Author date  : 2026-08-26
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: expose the unused transaction_lines.description column
-- in the ledger Add/Edit/View line grid as an optional per-line Note. Notes
-- persist with the lines and are recorded in the transaction audit trail when
-- added, changed, or cleared. They do not affect balances, funds, or posting.
-- No table DDL — the column already exists in the baseline schema.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.939 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.940 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--   SHOW COLUMNS FROM transaction_lines LIKE 'description';
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260826_0940_transaction_line_notes.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260826_0940_transaction_line_notes.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (optional per-line Note reuses transaction_lines.description). Schema
-- stem carried forward from 0.938:
--   20260825_0938_user_preferences

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.940',
    '20260825_0938_user_preferences',
    '20260826_0940_transaction_line_notes.sql',
    'Optional per-line Note on transaction_lines.description; Add/Edit/View + audit (no DDL)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.940'
       OR patch_file = '20260826_0940_transaction_line_notes.sql'
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260726_0811_tx_memo_to_description.sql
-- Schema ver.  : 20260726_0811_tx_memo_to_description
-- App version  : 0.811
-- Min app ver. : 0.811
-- Author date  : 2026-07-26
--
-- NOTES / PURPOSE
-- ---------------
-- Rename transaction_details.memo → description for a single header text field.
-- UI no longer uses separate Description + Memo with " | " concatenation; one
-- Description field maps 1:1 to this column. Existing values that used the
-- legacy "desc | memo" join have the delimiter replaced with a single space
-- so content is preserved without the pipe.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.810 (or apply prior
-- post-baseline patches first). Safe-ish to re-run: CHANGE COLUMN fails if
-- column is already description (operator should skip if already applied).
-- INSERT into app_version is skipped when 0.811 / this patch_file exists.
--
--   SHOW COLUMNS FROM transaction_details LIKE 'memo';
--   SHOW COLUMNS FROM transaction_details LIKE 'description';
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None required. Optional inspection of legacy concatenated values:
--
--   SELECT id, memo FROM transaction_details WHERE memo LIKE '% | %' LIMIT 20;
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260726_0811_tx_memo_to_description.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260726_0811_tx_memo_to_description.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Data migration (before rename): normalize legacy "desc | memo" joins
-- ---------------------------------------------------------------------------
UPDATE transaction_details
SET memo = REPLACE(memo, ' | ', ' ')
WHERE memo IS NOT NULL
  AND memo LIKE '% | %';

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- Rename header free-text column to description (TEXT, nullable, same as before).
ALTER TABLE transaction_details
    CHANGE COLUMN memo description TEXT NULL;

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.811',
    '20260726_0811_tx_memo_to_description',
    '20260726_0811_tx_memo_to_description.sql',
    'Rename transaction_details.memo to description; single Description field (no | join)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.811'
       OR patch_file = '20260726_0811_tx_memo_to_description.sql'
);

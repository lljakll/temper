-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261029_0976_line_budget_assignment.sql
-- Schema ver.  : 20261029_0976_line_budget_assignment
-- App version  : 0.976
-- Min app ver. : 0.976
-- Author date  : 2026-10-29
--
-- NOTES / PURPOSE
-- ---------------
-- Budget reports use transaction_lines.budget_line_id. The header
-- transaction_details.budget_id stays a form default and does not drive
-- remaining balance or budget-vs-actual. This patch adds an index and a
-- foreign key (ON DELETE SET NULL) on the existing budget_line_id column,
-- then backfills that column from the header budget when the line is blank
-- and that budget already has a line for the same account. It does not
-- insert budget lines. A line with no matching budget line stays null and
-- is not in any budget.
--
-- When several budget lines share one account, the lowest budget_lines.id
-- is used. Re-running this file does not repeat the backfill after the
-- 0.976 history row exists, so a later blank assignment is left blank.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- transaction_lines.budget_line_id must already exist (setup baseline
-- 0.944). The script selects that column first and stops if it is missing.
-- Invalid ids are set to NULL before the foreign key is added.
-- Requires app_version history through at least v0.975 (or apply prior
-- patches first).
--
--   SHOW COLUMNS FROM transaction_lines LIKE 'budget_line_id';
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None. Do not insert budget_lines to force a match.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20261029_0976_line_budget_assignment.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261029_0976_line_budget_assignment.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- Fail immediately when the baseline column is missing.
SELECT budget_line_id FROM transaction_lines LIMIT 0;

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------

-- Drop values that cannot reference budget_lines before the foreign key.
UPDATE transaction_lines
SET budget_line_id = NULL
WHERE budget_line_id IS NOT NULL AND budget_line_id <= 0;

UPDATE transaction_lines tl
LEFT JOIN budget_lines bl ON bl.id = tl.budget_line_id
SET tl.budget_line_id = NULL
WHERE tl.budget_line_id IS NOT NULL AND bl.id IS NULL;

CREATE INDEX IF NOT EXISTS idx_transaction_lines_budget_line_id
    ON transaction_lines (budget_line_id);

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'transaction_lines'
      AND CONSTRAINT_NAME = 'fk_transaction_lines_budget_line'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @fk_sql := IF(
    @fk_exists = 0,
    'ALTER TABLE transaction_lines ADD CONSTRAINT fk_transaction_lines_budget_line FOREIGN KEY (budget_line_id) REFERENCES budget_lines (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE temper_line_budget_fk FROM @fk_sql;
EXECUTE temper_line_budget_fk;
DEALLOCATE PREPARE temper_line_budget_fk;

-- ---------------------------------------------------------------------------
-- Data migration
-- ---------------------------------------------------------------------------
-- Only on the first successful record of this release. A later re-run must
-- not refill a line the user cleared.

SET @do_backfill := (
    SELECT COUNT(*) = 0
    FROM app_version
    WHERE version = '0.976'
       OR patch_file = '20261029_0976_line_budget_assignment.sql'
);
SET @backfill_sql := IF(
    @do_backfill = 1,
    'UPDATE transaction_lines tl INNER JOIN transaction_details td ON td.id = tl.transaction_detail_id INNER JOIN (SELECT budget_id, account_id, MIN(id) AS budget_line_id FROM budget_lines WHERE account_id IS NOT NULL AND account_id > 0 GROUP BY budget_id, account_id) match_bl ON match_bl.budget_id = td.budget_id AND match_bl.account_id = tl.account_id SET tl.budget_line_id = match_bl.budget_line_id WHERE tl.budget_line_id IS NULL AND td.budget_id IS NOT NULL AND td.budget_id > 0',
    'SELECT 1'
);
PREPARE temper_line_budget_backfill FROM @backfill_sql;
EXECUTE temper_line_budget_backfill;
DEALLOCATE PREPARE temper_line_budget_backfill;

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
-- setup_db.php is frozen at v0.944. Post-0.944 releases record here.
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.976',
    '20261029_0976_line_budget_assignment',
    '20261029_0976_line_budget_assignment.sql',
    'Line budget_line_id drives budget totals; header budget_id is the form default; backfill matches existing header lines only'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.976'
       OR patch_file = '20261029_0976_line_budget_assignment.sql'
);

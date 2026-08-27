-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260801_0901_account_type_classification.sql
-- Schema ver.  : 20260801_0901_account_type_classification
-- App version  : 0.901
-- Min app ver. : 0.901
-- Author date  : 2026-08-01
--
-- NOTES / PURPOSE
-- ---------------
-- Add required classic accounting element classification to Chart of Accounts:
-- accounts.account_type ENUM('asset','liability','equity','income','expense') NOT NULL.
-- Distinct from optional Natural / Functional category FKs. Clean 0.900 installs
-- have an empty accounts table, so NOT NULL is safe once any existing rows are
-- given a temporary value (operators must review backfilled classifications).
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.900 (beta baseline).
-- Safe-ish to re-run: ADD COLUMN fails if account_type already exists (skip if applied).
-- INSERT into app_version is skipped when 0.901 / this patch_file already exists.
--
--   SHOW COLUMNS FROM accounts LIKE 'account_type';
--   SELECT id, name, normal_balance, account_type FROM accounts ORDER BY id;
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- After apply, review any backfilled rows (temporary heuristic: debit→asset,
-- credit→liability). Set correct types via Accounts setup UI, for example:
--
--   UPDATE accounts SET account_type = 'expense' WHERE id = ?;
--   UPDATE accounts SET account_type = 'income'  WHERE id = ?;
--   UPDATE accounts SET account_type = 'equity'  WHERE id = ?;
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260801_0901_account_type_classification.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260801_0901_account_type_classification.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- Add nullable first so existing rows (if any) do not block NOT NULL.
ALTER TABLE accounts
    ADD COLUMN account_type ENUM('asset','liability','equity','income','expense') NULL
    AFTER normal_balance;

-- ---------------------------------------------------------------------------
-- Data migrations (if any)
-- ---------------------------------------------------------------------------
-- Temporary backfill for pre-existing Chart of Accounts rows.
-- Heuristic only — OPERATORS MUST REVIEW AND CORRECT account_type values.
-- Debit-normal → asset; credit-normal → liability (covers liability/equity/income).
UPDATE accounts
SET account_type = CASE
    WHEN normal_balance = 'credit' THEN 'liability'
    ELSE 'asset'
END
WHERE account_type IS NULL;

-- Enforce required classification going forward.
ALTER TABLE accounts
    MODIFY COLUMN account_type ENUM('asset','liability','equity','income','expense') NOT NULL;

CREATE INDEX idx_accounts_account_type ON accounts(account_type);

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
-- setup_db.php is frozen at v0.900. Post-0.900 releases MUST record here
-- (never by extending TEMPER_VERSION_HISTORY / setup seed).
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.901',
    '20260801_0901_account_type_classification',
    '20260801_0901_account_type_classification.sql',
    'Required accounts.account_type (asset/liability/equity/income/expense); CoA Normal Balance UX'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.901'
       OR patch_file = '20260801_0901_account_type_classification.sql'
);

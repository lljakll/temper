-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260827_0944_setup_baseline_consolidation.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation
-- App version  : 0.944
-- Min app ver. : 0.944
-- Author date  : 2026-08-27
--
-- NOTES / PURPOSE
-- ---------------
-- Advance the setup_db.php baseline to app v0.944 so a clean destructive
-- install creates the current live schema without replaying incremental
-- patches. setup_db.php now includes:
--   - accounts.account_type ENUM('asset','liability','equity','income','expense') NOT NULL
--     (folded from 0.901)
--   - users.preferences JSON NULL (folded from 0.938)
-- plus all earlier 0.900 shape (transaction_details.description, etc.).
--
-- Fresh installs: run php setup_db.php only. Do not replay pre-0.944 patches
-- (they are in updates/archive/). SCHEMA is current when setup_db.php matches
-- this milestone (schema stem 20260827_0944_setup_baseline_consolidation).
--
-- Existing live databases already at 0.943 with those columns: this file is
-- process-only besides idempotent ADD COLUMN IF NOT EXISTS (no-ops). It
-- records the 0.944 app_version row. Do not run destructive setup.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.900 (beta baseline).
-- Safe to re-run: ADD COLUMN IF NOT EXISTS is a no-op when present;
-- INSERT into app_version is skipped when 0.944 / this patch_file exists.
-- Role JSON append for page.ledger.mass_import is skipped when already present.
--
--   SHOW COLUMNS FROM accounts LIKE 'account_type';
--   SHOW COLUMNS FROM users LIKE 'preferences';
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- If account_type is added to pre-existing Chart of Accounts rows, a temporary
-- heuristic backfills debit→asset / credit→liability. Operators must review:
--
--   SELECT id, name, normal_balance, account_type FROM accounts ORDER BY id;
--   UPDATE accounts SET account_type = 'expense' WHERE id = ?;
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260827_0944_setup_baseline_consolidation.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260827_0944_setup_baseline_consolidation.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- Do not run php setup_db.php (destructive) against a live treasurer database.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes (idempotent — no-op on databases already at 0.938+ shape)
-- ---------------------------------------------------------------------------
ALTER TABLE accounts
    ADD COLUMN IF NOT EXISTS account_type ENUM('asset','liability','equity','income','expense') NULL
    AFTER normal_balance;

UPDATE accounts
SET account_type = CASE
    WHEN normal_balance = 'credit' THEN 'liability'
    ELSE 'asset'
END
WHERE account_type IS NULL;

ALTER TABLE accounts
    MODIFY COLUMN account_type ENUM('asset','liability','equity','income','expense') NOT NULL;

CREATE INDEX IF NOT EXISTS idx_accounts_account_type ON accounts(account_type);

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS preferences JSON NULL
    AFTER custom_permissions;

-- ---------------------------------------------------------------------------
-- Data migrations (if any)
-- ---------------------------------------------------------------------------
-- Folded from 0.928: grant mass-import on named roles when missing.
UPDATE roles
SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'page.ledger.mass_import')
WHERE name IN ('Treasurer', 'Finance Manager', 'Archivist')
  AND JSON_TYPE(permissions) = 'ARRAY'
  AND JSON_CONTAINS(permissions, JSON_QUOTE('page.ledger.mass_import')) = 0
  AND JSON_CONTAINS(permissions, JSON_QUOTE('*')) = 0;

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
-- setup_db.php is frozen at v0.944. Post-0.944 releases MUST record here
-- (never by extending TEMPER_VERSION_HISTORY / setup seed).
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.944',
    '20260827_0944_setup_baseline_consolidation',
    '20260827_0944_setup_baseline_consolidation.sql',
    'Setup baseline advanced to 0.944: accounts.account_type + users.preferences in setup_db.php; prior updates archived'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.944'
       OR patch_file = '20260827_0944_setup_baseline_consolidation.sql'
);

-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260825_0938_user_preferences.sql
-- Schema ver.  : 20260825_0938_user_preferences
-- App version  : 0.938
-- Min app ver. : 0.938
-- Author date  : 2026-08-25
--
-- NOTES / PURPOSE
-- ---------------
-- Add nullable JSON column users.preferences for small, keyed per-user settings
-- (dashboard card options, etc.). No preferences management UI in this release.
-- Dashboard Total Cash / Bank uses key dashboard.total_cash.account_ids.
--
-- Preference key convention (dot-separated, nested JSON):
--   <area>.<subject>[.<option>...]
--   dashboard.<card_id>.<option>   e.g. dashboard.total_cash.account_ids
--   ledger.<option>                e.g. ledger.double_click (reserved)
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.937 (or apply prior patches
-- first). Safe-ish to re-run: ADD COLUMN IF NOT EXISTS is a no-op when present;
-- INSERT into app_version is skipped when 0.938 / this patch_file exists.
--
--   SHOW COLUMNS FROM users LIKE 'preferences';
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None. Column is nullable; existing users keep preferences = NULL (defaults).
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260825_0938_user_preferences.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260825_0938_user_preferences.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS preferences JSON NULL
    AFTER custom_permissions;

-- ---------------------------------------------------------------------------
-- Data migrations (if any)
-- ---------------------------------------------------------------------------
-- None. NULL means “use feature defaults” (e.g. all asset accounts for
-- dashboard.total_cash.account_ids).

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
-- setup_db.php is frozen at v0.900. Post-0.900 releases MUST record here
-- (never by extending TEMPER_VERSION_HISTORY / setup seed).
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.938',
    '20260825_0938_user_preferences',
    '20260825_0938_user_preferences.sql',
    'users.preferences JSON; dashboard Total Cash account picker (dashboard.total_cash.account_ids)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.938'
       OR patch_file = '20260825_0938_user_preferences.sql'
);

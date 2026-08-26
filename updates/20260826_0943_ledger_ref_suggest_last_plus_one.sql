-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260826_0943_ledger_ref_suggest_last_plus_one.sql
-- Schema ver.  : 20260825_0938_user_preferences   (carried forward — no DDL)
-- App version  : 0.943
-- Min app ver. : 0.943
-- Author date  : 2026-08-26
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Ledger Add/Edit Ref # suggestion tip is last saved
-- transaction Ref # plus one (placeholder / double-click fill only; not
-- auto-filled). If last+1 is already used, skip to the next unused number in
-- that numeric sequence. Existing Already Used confirm-on-save is unchanged.
-- No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.942 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.943 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260826_0943_ledger_ref_suggest_last_plus_one.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260826_0943_ledger_ref_suggest_last_plus_one.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (Ref # suggestion is application code only). Schema stem
-- carried forward from 0.938:
--   20260825_0938_user_preferences

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.943',
    '20260825_0938_user_preferences',
    '20260826_0943_ledger_ref_suggest_last_plus_one.sql',
    'Ledger Ref # tip is last saved Ref # + 1 (placeholder/double-click); skip used in sequence; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.943'
       OR patch_file = '20260826_0943_ledger_ref_suggest_last_plus_one.sql'
);

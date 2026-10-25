-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20261024_0975_cleared_reconciled_edit_override.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.975
-- Min app ver. : 0.975
-- Author date  : 2026-10-24
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: an Administrator-only temporary override lets an
-- Administrator open and edit cleared and reconciled transactions, including
-- status. Each such save requires one comment. Turning the override off
-- restores status unless a save kept it. Field changes stay. Attachments are
-- not moved or deleted. The switch and its turn-on time live in system.json.
-- Audit uses the existing transaction_events and audit_log tables. No DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.974 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.975 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20261024_0975_cleared_reconciled_edit_override.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20261024_0975_cleared_reconciled_edit_override.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (system configuration and existing audit tables). Schema stem carried
-- forward from 0.944 / 0.974:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.975',
    '20260827_0944_setup_baseline_consolidation',
    '20261024_0975_cleared_reconciled_edit_override.sql',
    'Temporary admin unlock of cleared and reconciled transactions; re-lock restores status unless a save kept it; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.975'
       OR patch_file = '20261024_0975_cleared_reconciled_edit_override.sql'
);

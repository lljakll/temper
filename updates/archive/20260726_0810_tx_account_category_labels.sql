-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260726_0810_tx_account_category_labels.sql
-- Schema ver.  : 20260725_03_formalize_audit_log   (carried forward — no DDL)
-- App version  : 0.810
-- Min app ver. : 0.810
-- Author date  : 2026-07-26
--
-- NOTES / PURPOSE
-- ---------------
-- Process / UI release only (no table DDL). Transaction form line Natural and
-- Functional classes are displayed as read-only labels pulled from the selected
-- account (matching budget-page behavior). Users cannot override those classes
-- on the line; the server resolves them from the accounts table on save.
-- Advances app_version from 0.809 → 0.810 and carries forward schema stem
-- 20260725_03_formalize_audit_log.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.809 (or apply prior
-- post-baseline patches first). Safe to re-run: INSERT skipped when 0.810 exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260726_0810_tx_account_category_labels.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260726_0810_tx_account_category_labels.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (process-only release; schema stem carried forward).

-- ---------------------------------------------------------------------------
-- Data migrations (if any)
-- ---------------------------------------------------------------------------
-- None.

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.810',
    '20260725_03_formalize_audit_log',
    '20260726_0810_tx_account_category_labels.sql',
    'Transaction lines pull Natural/Functional from account as read-only labels'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.810'
       OR patch_file = '20260726_0810_tx_account_category_labels.sql'
);

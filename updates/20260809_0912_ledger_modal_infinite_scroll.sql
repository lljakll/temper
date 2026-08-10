-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260809_0912_ledger_modal_infinite_scroll.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.912
-- Min app ver. : 0.912
-- Author date  : 2026-08-09
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Ledger page UX redesign — Add/Edit/View transaction form
-- in a modal dialog (with dirty-form protection), continuous infinite-scroll list
-- with server-side Excel-style column auto-filters, sticky headers, View button +
-- double-click for read-only view, and Edit only via explicit Edit button.
-- No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.911 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.912 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260809_0912_ledger_modal_infinite_scroll.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260809_0912_ledger_modal_infinite_scroll.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (ledger UI/API only). Schema stem carried forward from 0.901:
-- 20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.912',
    '20260801_0901_account_type_classification',
    '20260809_0912_ledger_modal_infinite_scroll.sql',
    'Ledger redesign: modal Add/Edit/View, infinite scroll, Excel-style filters'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.912'
       OR patch_file = '20260809_0912_ledger_modal_infinite_scroll.sql'
);

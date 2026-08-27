-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260809_0914_ledger_filter_dropdown_layout.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.914
-- Min app ver. : 0.914
-- Author date  : 2026-08-09
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Ledger Excel-style filter dropdown layout fixes —
-- visible left-aligned checkboxes, single-line labels, horizontal scroll,
-- resizable panels (drag handle), Account name-only (no CoA), cleaner
-- unique-value labels. No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.913 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.914 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260809_0914_ledger_filter_dropdown_layout.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260809_0914_ledger_filter_dropdown_layout.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (ledger UI/CSS only). Schema stem carried forward from 0.901:
-- 20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.914',
    '20260801_0901_account_type_classification',
    '20260809_0914_ledger_filter_dropdown_layout.sql',
    'Ledger filter dropdown: visible checkboxes, no-wrap, resize, clean labels'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.914'
       OR patch_file = '20260809_0914_ledger_filter_dropdown_layout.sql'
);

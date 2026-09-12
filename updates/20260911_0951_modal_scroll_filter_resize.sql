-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260911_0951_modal_scroll_filter_resize.sql
-- Schema ver.  : 20260827_0944_setup_baseline_consolidation   (carried forward — no DDL)
-- App version  : 0.951
-- Min app ver. : 0.951
-- Author date  : 2026-09-11
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: Ledger Add/Edit/View modal body scrolls on small
-- screens (form-wrapped scrollable modal flex). Desktop column-filter resize
-- handle mouse-up no longer closes the dropdown. No filter-logic, posting, or
-- validation changes. No DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.950 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.951 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260911_0951_modal_scroll_filter_resize.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260911_0951_modal_scroll_filter_resize.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (modal/filter UI is application code only). Schema stem carried
-- forward from 0.944 / 0.950:
--   20260827_0944_setup_baseline_consolidation

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.951',
    '20260827_0944_setup_baseline_consolidation',
    '20260911_0951_modal_scroll_filter_resize.sql',
    'Mobile tx modal body scrolls; desktop filter resize mouse-up does not close dropdown; no DDL'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.951'
       OR patch_file = '20260911_0951_modal_scroll_filter_resize.sql'
);

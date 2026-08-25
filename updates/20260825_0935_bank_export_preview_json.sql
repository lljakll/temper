-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260825_0935_bank_export_preview_json.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.935
-- Min app ver. : 0.935
-- Author date  : 2026-08-25
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: fix temporary Bank Export Preview JSON. PHP 8.4
-- fgetcsv() deprecation notices were emitted into the parse response and
-- the client reported "unexpected server response". No table DDL.
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.934 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.935 / this patch_file exists.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260825_0935_bank_export_preview_json.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260825_0935_bank_export_preview_json.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (CSV parse / JSON response fix only). Schema stem carried forward
-- from 0.901:
--   20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.935',
    '20260801_0901_account_type_classification',
    '20260825_0935_bank_export_preview_json.sql',
    'Fix Bank Export Preview JSON broken by PHP 8.4 fgetcsv deprecation notices (no DDL)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.935'
       OR patch_file = '20260825_0935_bank_export_preview_json.sql'
);

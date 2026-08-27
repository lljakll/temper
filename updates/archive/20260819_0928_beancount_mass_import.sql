-- =============================================================================
-- TEMPER SCHEMA PATCH
-- =============================================================================
-- Filename     : 20260819_0928_beancount_mass_import.sql
-- Schema ver.  : 20260801_0901_account_type_classification   (carried forward — no DDL)
-- App version  : 0.928
-- Min app ver. : 0.928
-- Author date  : 2026-08-19
--
-- NOTES / PURPOSE
-- ---------------
-- Process-only release: temporary Beancount Mass Import under Ledger (paste,
-- two-pane review, duplicate resolution). No table DDL. Grants
-- page.ledger.mass_import on Treasurer, Finance Manager, and Archivist when
-- that key is missing (Administrator already has *).
--
-- DATA CONFLICTS / PRE-CHECKS
-- ---------------------------
-- Requires app_version history through at least v0.927 (or apply prior patches
-- first). Safe to re-run: INSERT is skipped when 0.928 / this patch_file exists;
-- role JSON append is skipped when the permission is already present.
--
--   SELECT id, version, schema_version, patch_file FROM app_version ORDER BY id;
--   SELECT name, permissions FROM roles WHERE name IN
--     ('Treasurer','Finance Manager','Archivist','Administrator');
--
-- HELPFUL DATA RESOLUTION (optional — run only if needed)
-- -------------------------------------------------------
-- None.
--
-- MYSQL COMMAND (copy-paste; adjust -u/-h/-p and database name as needed)
-- ----------------------------------------------------------------------
--   mysql -u temper_user -p temper_db < updates/20260819_0928_beancount_mass_import.sql
--
-- Or interactive:
--   mysql -u temper_user -p temper_db
--   SOURCE /var/www/temper/updates/20260819_0928_beancount_mass_import.sql;
--
-- BACKUP FIRST. There is no automatic rollback.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Schema changes
-- ---------------------------------------------------------------------------
-- None (Mass Import page + role permission JSON only). Schema stem carried
-- forward from 0.901:
--   20260801_0901_account_type_classification

-- ---------------------------------------------------------------------------
-- Data migrations (if any)
-- ---------------------------------------------------------------------------
-- Append page.ledger.mass_import to named roles when missing.
UPDATE roles
SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'page.ledger.mass_import')
WHERE name IN ('Treasurer', 'Finance Manager', 'Archivist')
  AND JSON_TYPE(permissions) = 'ARRAY'
  AND JSON_CONTAINS(permissions, JSON_QUOTE('page.ledger.mass_import')) = 0
  AND JSON_CONTAINS(permissions, JSON_QUOTE('*')) = 0;

-- ---------------------------------------------------------------------------
-- Record application in version history (required)
-- ---------------------------------------------------------------------------
INSERT INTO app_version (version, schema_version, patch_file, notes)
SELECT
    '0.928',
    '20260801_0901_account_type_classification',
    '20260819_0928_beancount_mass_import.sql',
    'Beancount Mass Import under Ledger; grant mass-import permission on named roles (no DDL)'
WHERE NOT EXISTS (
    SELECT 1 FROM app_version
    WHERE version = '0.928'
       OR patch_file = '20260819_0928_beancount_mass_import.sql'
);

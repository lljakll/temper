# Temper Application Version History

Human-readable changelog for the Hope Baptist Treasurer (Temper) application.

| Source | Role |
|--------|------|
| **This file** | Operator-facing release notes and schema-update pointers |
| **`app_version` table** | Append-only DB history (`version`, `schema_version`, `patch_file`, `applied_at`) |
| **`updates/*.sql`** | Manual schema patches (run with `mysql`; no in-app updater) |
| **`config.php` / `APP_VERSION`** | Codebase constant for the current app version |

**Code updates:** deploy / `git pull` manually.  
**Schema updates:** apply the listed SQL patch manually (see patch metadata header).

There is **no** automatic schema detection or apply UI in the application.

---

## Table of Contents

- [Conventions](#conventions)
- [v0.803](#v0803)
- [v0.802](#v0802)
- [v0.801](#v0801)

---

## Conventions

### Schema version = patch filename stem

The **schema version** is the patch file’s basename **without** `.sql`:

| Patch file | Schema version (`app_version.schema_version`) |
|------------|-----------------------------------------------|
| `updates/20260725_01_app_version_history.sql` | `20260725_01_app_version_history` |
| `updates/20260725_02_schema_version_as_filename.sql` | `20260725_02_schema_version_as_filename` |
| *(schema from `setup_db.php` only)* | `setup_baseline` |

Every app version history row **must** store a `schema_version`.  
If a release has **no** schema change, **reuse the previous** schema version stem (carry forward) and leave `patch_file` null.

### When a release needs a schema change

Include a **prominent** line in that version’s section:

```text
**SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
```

Immediately under it, name the exact patch file and schema version, for example:

- Patch: `updates/20260725_02_schema_version_as_filename.sql`
- Schema version: `20260725_02_schema_version_as_filename`

Operators open that file, read the header (notes, min app version, data conflicts, copy-paste `mysql` command), back up the DB, then run the file.

### Patch filenames

```text
YYYYMMDD_NN_short_description.sql
```

Aim for **one schema patch per app version**. Details and the SQL header template live in [`updates/README.md`](updates/README.md) and [`updates/_header_template.sql`](updates/_header_template.sql).

### Consolidation (developers)

After every **5–10** schema patches (or at a natural milestone), consolidate small patches into one clean schema update file, keep older files for historical upgrades, and document the consolidation under the release that introduces the consolidated patch.

### Fresh installs

`php setup_db.php` builds the current schema from `setup-database/*.php` and seeds the **complete** `app_version` history. Do not replay the entire `updates/` chain on a new database.

---

## v0.803

**Schema version = patch filename** — 2026-07-25

> ### **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
>
> **Patch file:** `updates/20260725_02_schema_version_as_filename.sql`  
> **Schema version:** `20260725_02_schema_version_as_filename`  
> **Min app version:** 0.803

- `app_version.schema_version` is now a **string**: the required patch filename stem (not an integer).
- Every version entry records a schema version; releases without DDL **carry forward** the previous stem.
- Corrected history so **v0.802** records schema version `20260725_01_app_version_history` and **v0.801** records `setup_baseline`.
- Updated helpers, setup seed, patch header template, and operator docs.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.802 | `updates/20260725_02_schema_version_as_filename.sql` only |
| v0.801 | `20260725_01_…` then `20260725_02_…` (in order) |
| Fresh install | No patches; setup seeds full history through 0.803 |

---

## v0.802

**Manual schema update process** — 2026-07-25

> ### **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
>
> **Patch file:** `updates/20260725_01_app_version_history.sql`  
> **Schema version:** `20260725_01_app_version_history`  
> **Min app version:** 0.802

- Formalized **fully manual** database updates: code via git/deploy; schema via SQL files under `updates/`.
- `app_version` is now an **append-only history** table (`version`, `schema_version`, `patch_file`, `notes`, `applied_at`) instead of a single current-version row.
- Added patch header template, operator README, and development consolidation rule (merge small patches every 5–10 changes).
- `setup_db.php` / `08-app-version.php` seed the full version history on fresh installs.
- No in-app update detection or apply UI (by design).

**Note:** Early 0.802 builds stored schema version as integer `2`. From **v0.803** onward the canonical value is the filename stem `20260725_01_app_version_history` (migrated by the 0.803 patch).

**Existing installs (v0.801):** deploy 0.802 code, then run the patch above.  
**Fresh installs:** no patch needed for 0.802 alone; prefer current setup for latest.

---

## v0.801

**First tracked alpha** — 2026-07-23

- Introduced hybrid application versioning: `VERSION.md` (this file) plus an
  `app_version` database table for the current version string.
- Sidebar displays the current version above the Welcome message (all roles);
  the version number links here for the full changelog.
- Schema established by `setup_db.php` only — schema version id **`setup_baseline`**
  (recorded as such from v0.803 history normalization; originally integer `1`).

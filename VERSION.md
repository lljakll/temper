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
- [v0.808](#v0808)
- [v0.807](#v0807)
- [v0.806](#v0806)
- [v0.805](#v0805)
- [v0.804](#v0804)
- [v0.803](#v0803)
- [v0.802](#v0802)
- [v0.801](#v0801)

---

## Conventions

### Frozen setup baseline vs post-baseline patches

| Layer | Role |
|-------|------|
| **`setup_db.php` + `setup-database/*`** | **Frozen at app v0.804.** Destructive setup always leaves the database at 0.804 with the 0.804 schema shape. |
| **`TEMPER_VERSION_HISTORY`** | Seeds **only** through 0.804. Do **not** add 0.805+ rows here. |
| **`updates/*.sql`** | **Only** path for 0.805 and later (DDL and/or `app_version` history rows). |

After a fresh setup, operators who want the current release apply every post-baseline patch listed under that version in this file (in order).

### Schema version = patch filename stem

The **schema version** is the patch file’s basename **without** `.sql` when that release changes schema:

| Patch file | Schema version (`app_version.schema_version`) |
|------------|-----------------------------------------------|
| `updates/20260725_01_app_version_history.sql` | `20260725_01_app_version_history` |
| `updates/20260725_02_schema_version_as_filename.sql` | `20260725_02_schema_version_as_filename` |
| `updates/20260725_03_formalize_audit_log.sql` | `20260725_03_formalize_audit_log` |
| *(schema from `setup_db.php` only)* | `setup_baseline` |

Every app version history row **must** store a `schema_version`.  
If a release has **no** schema change, **reuse the previous** schema version stem (carry forward). Process-only post-baseline releases still get a minimal `updates/*.sql` file that inserts the new `app_version` row (and may set `patch_file` to that filename for auditability).

### When a release needs a schema change (or any post-baseline patch)

Include a **prominent** line in that version’s section:

```text
**SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
```

Immediately under it, name the exact patch file and schema version, for example:

- Patch: `updates/20260725_02_schema_version_as_filename.sql`
- Schema version: `20260725_02_schema_version_as_filename`

Operators open that file, read the header (notes, min app version, data conflicts, copy-paste `mysql` command), back up the DB, then run the file.

### Patch filenames

**New patches (0.808+):**

```text
YYYYMMDD_<appversion_without_decimal>_short_description.sql
```

| Part | Example (`0.806`) |
|------|-------------------|
| Date | `20260726` |
| App version without decimal | `0806` |
| Description | `description` |
| Full name | `20260726_0806_description.sql` |

Helpers: `temperAppVersionToPatchToken()` / `temperBuildPatchFilename()` in `includes/app_version.php`.

**Legacy** patches may still use `YYYYMMDD_NN_short_description.sql` (e.g. `20260725_01_app_version_history.sql`). Leave those files as-is; only new patches use the app-version token form.

Aim for **one schema patch per app version**. Details and the SQL header template live in [`updates/README.md`](updates/README.md) and [`updates/_header_template.sql`](updates/_header_template.sql).

### Consolidation (developers)

After every **5–10** schema patches (or at a natural milestone), consolidate small patches into one clean schema update file, keep older files for historical upgrades, and document the consolidation under the release that introduces the consolidated patch.

### Fresh installs

`php setup_db.php` builds the **0.804 baseline** schema from `setup-database/*.php` and seeds `app_version` history **through 0.804 only**.  
Do **not** expect setup to plant 0.805+ rows. After setup, apply post-baseline patches under `updates/` (see the current version section). Do not replay pre-baseline patches that are already embodied in the setup scripts.

---

## v0.808

**Patch naming by app version + sidebar App/DB dual display** — 2026-07-26

> ### **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
>
> **Patch file:** `updates/20260726_0808_patch_naming_and_sidebar_dual_version.sql`  
> **Schema version:** `20260725_03_formalize_audit_log` *(carried forward — no DDL)*  
> **Min app version:** 0.808

- **New patch filename convention:** `YYYYMMDD_<appversion_without_decimal>_description.sql`  
  Example: app `0.806` → `20260726_0806_description.sql`. Existing older patches keep their historical names.
- Helpers added: `temperAppVersionToPatchToken()`, `temperBuildPatchFilename()` in `includes/app_version.php`.
- Templates/docs updated: `updates/_header_template.sql`, `updates/README.md`, this conventions section.
- **Sidebar versions:**
  - **Administrators:** `App: vX.Y  DB: vX.Y` side-by-side (link to `VERSION.md` unchanged).
  - When the database app version is behind the latest known release, the **DB** portion is shown in **red** with a tooltip (current DB vs latest available).
  - **Non-admins:** single normal (non-red) application version only — not the dual display.
- No table DDL in this release (process / UI / convention only).

**Upgrade path**

| From | Apply |
|------|--------|
| v0.807 | `updates/20260726_0808_patch_naming_and_sidebar_dual_version.sql` only |
| Fresh setup (0.804) | Post-baseline patches through 0.807, then `20260726_0808_…` |

---

## v0.807

**Admin sidebar indicator when DB lags latest release** — 2026-07-26

> ### **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
>
> **Patch file:** `updates/20260726_02_admin_version_outdated_indicator.sql`  
> **Schema version:** `20260725_03_formalize_audit_log` *(carried forward — no DDL)*  
> **Min app version:** 0.807

- Sidebar still shows the current **database** app version for all users (link to `VERSION.md` unchanged).
- **Administrators only:** if the DB version is behind the latest known release (`VERSION.md`, `updates/*.sql` headers, and `APP_VERSION`), the version number is shown in **red** with a tooltip such as:  
  `Database is at v0.803 — latest available is v0.805. See VERSION.md or updates/ folder.`
- Non-admin users always see the normal (non-red) version with the standard changelog tooltip.
- No automatic patch detection/application UI beyond this visual cue.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.806 | `updates/20260726_02_admin_version_outdated_indicator.sql` only |
| Fresh setup (0.804) | Post-baseline patches through 0.806, then `20260726_02_…` |

---

## v0.806

**setup_db.php --check baseline awareness** — 2026-07-26

> ### **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
>
> **Patch file:** `updates/20260726_01_setup_check_baseline_awareness.sql`  
> **Schema version:** `20260725_03_formalize_audit_log` *(carried forward — no DDL)*  
> **Min app version:** 0.806

- `php setup_db.php --check` now reports:
  - Database highest app version + schema (from `app_version`)
  - Frozen setup baseline app **0.804** / schema **`20260725_03_formalize_audit_log`**
  - Whether they match, the DB is ahead, behind, or history is incomplete
- If the database is **behind** the baseline or history is **missing/incomplete**, prints a clear **WARNING** that a full (destructive) `setup_db.php` run is required before applying newer patches, and recommends backing up data first.
- Structure validation is unchanged; `--check` remains read-only (no auto setup, no patch apply).
- This release has **no table DDL**; the patch only records the 0.806 history row.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.805 | `updates/20260726_01_setup_check_baseline_awareness.sql` only |
| Fresh setup (0.804) | Post-baseline patches through 0.805, then `20260726_01_…` |

---

## v0.805

**Frozen setup baseline + patch-only model** — 2026-07-25

> ### **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
>
> **Patch file:** `updates/20260725_04_frozen_baseline_model.sql`  
> **Schema version:** `20260725_03_formalize_audit_log` *(carried forward — no DDL)*  
> **Min app version:** 0.805

- Formalizes the long-term model: **`setup_db.php` stays frozen at v0.804**; **0.805+ advances only via `updates/*.sql`**.
- `TEMPER_VERSION_HISTORY` / setup seed intentionally stop at 0.804 (`TEMPER_SETUP_BASELINE_APP_VERSION`).
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.805** (superseded by later releases).
- This release has **no table DDL**; the patch only records the 0.805 history row (schema stem unchanged).

**Upgrade path**

| From | Apply |
|------|--------|
| v0.804 | `updates/20260725_04_frozen_baseline_model.sql` only |
| Fresh setup (0.804 baseline) | Same patch after setup to reach 0.805 |
| v0.803 or older | Prior patches through 0.804, then `20260725_04_…` |

---

## v0.804

**Read-only schema checks — no live DDL/seed** — 2026-07-25

> ### **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
>
> **Patch file:** `updates/20260725_03_formalize_audit_log.sql`  
> **Schema version:** `20260725_03_formalize_audit_log`  
> **Min app version:** 0.804

- All runtime `ensure*` / live-migration helpers are **read-only checks** only: they detect missing tables/columns and log/throw a clear “schema is out of date” error. They no longer `CREATE`/`ALTER` tables or insert seed data on page load.
- Covered paths: `app_version`, users/roles, budget simplified schema, ledger `budget_id` / reference number, `audit_log`, tasks.
- Version history seeding (`TEMPER_VERSION_HISTORY` / `seedAppVersionHistory`) runs **only** from `setup_db.php` / `setup-database/08-app-version.php`.
- Default role seeding (`ensureDefaultRoles`) is **setup-only** (no longer run from admin Users page load).
- Formalized `audit_log` in `setup-database/09-audit-log.php` (was previously created on demand by `ensureAuditLogTable`).
- Fail clearly when schema is missing/outdated; operators apply `updates/*.sql` or re-run setup for a fresh install. Validate with `php setup_db.php --check`.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.803 | `updates/20260725_03_formalize_audit_log.sql` only |
| v0.802 | `20260725_02_…` then `20260725_03_…` (in order) |
| v0.801 | `20260725_01_…` → `02` → `03` (in order) |
| Fresh install | Setup seeds through 0.804; then apply `20260725_04_…` for 0.805+ |

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
| Fresh install | No patches; setup seeds full history through 0.803 (prefer current setup for 0.804+) |

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

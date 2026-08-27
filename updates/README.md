# Temper — Manual Database Schema Updates

There is **no in-app update system**. Operators apply schema changes by hand.

| What | How |
|------|-----|
| **Code** | `git pull` / deploy as usual |
| **Schema** | Run the matching `.sql` file in this folder with the `mysql` client |
| **Changelog** | Human-readable notes live in [`../VERSION.md`](../VERSION.md) |
| **Version history (DB)** | Append-only rows in `app_version` (app version + **schema version** + timestamp) |

---

## Operator workflow (existing install)

1. Deploy the new application code.
2. Open [`../VERSION.md`](../VERSION.md). If a release includes:

   > **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**

   note the listed patch filename.
3. Open that file under this directory. Read the **metadata header** (notes, min app version, data conflicts, mysql command).
4. **Back up the database** before applying any patch.
5. Copy-paste and run the `mysql` command from the header (adjust user/host/database if your environment differs).
6. Confirm success; optionally check:

   ```sql
   SELECT id, version, schema_version, patch_file, applied_at
   FROM app_version
   ORDER BY id;
   ```

### Fresh installs vs post-baseline

- **`setup_db.php` is frozen at app v0.944.** It creates the current schema (`accounts.account_type`, `users.preferences`, and all earlier shape) and seeds `app_version` history **through 0.944**.
- Setup does **not** insert demo accounts, budgets, or transactions — only lookup data (roles, categories, funds) and default users.
- **Do not** add post-0.944 rows to setup seed scripts. Those releases exist only as files in this folder.
- After a destructive setup at 0.944, apply every **post-0.944** patch listed in [`VERSION.md`](../VERSION.md) (in order) to reach a later release.
- Pre-0.944 patches live in [`archive/`](archive/) for operators recovering old databases; they are already embodied in the setup scripts for new databases. **Do not replay the archive after a current setup.**

---

## Filename format

**New patches (app 0.808+):**

```
YYYYMMDD_<appversion_without_decimal>_short_description.sql
```

| Part | Meaning |
|------|---------|
| `YYYYMMDD` | Date the patch was authored (UTC or local project convention) |
| `<appversion_without_decimal>` | App version with dots removed (e.g. `0.806` → `0806`, `0.944` → `0944`) |
| `short_description` | Lowercase words separated by underscores |

**Example:** app version `0.944` → `20260827_0944_setup_baseline_consolidation.sql`

PHP helpers in `includes/app_version.php`:

- `temperAppVersionToPatchToken('0.806')` → `0806`
- `temperBuildPatchFilename('0.808', 'patch_naming_and_sidebar_dual_version', '20260726')` → `20260726_0808_patch_naming_and_sidebar_dual_version.sql`

**Legacy** patches (already shipped) may still use the older daily sequence form:

```
YYYYMMDD_NN_short_description.sql
```

Example: `20260725_01_app_version_history.sql` (now under `archive/`). Leave existing files as-is; only **new** patches must use the app-version token form.

The **schema version** is the filename **stem** (no `.sql`):

```text
20260827_0944_setup_baseline_consolidation
```

(or a carried-forward prior stem when the release has no DDL)

Store that stem in `app_version.schema_version` and in patch headers as **Schema ver.**

---

## SQL file structure

Every patch must start with the metadata header (see `_header_template.sql`), then the executable SQL.

Order of content:

1. Metadata comment block (required)
2. Schema DDL (`ALTER` / `CREATE` / `DROP` / indexes / FKs)
3. Data fixes (`UPDATE` / `INSERT` / cleanup) when needed
4. **Always** append an `app_version` history row at the end so the DB records that the patch was applied (`schema_version` = this file’s stem)

Do **not** rely on the application to detect or apply patches.

---

## Versioning conventions

- **App version** (e.g. `0.944`) — product / release version in `config.php` (`APP_VERSION`), `TEMPER_DEFAULT_APP_VERSION`, `VERSION.md`, and `app_version.version`.
- **Setup baseline app version** — `TEMPER_SETUP_BASELINE_APP_VERSION` (**0.944**). Highest version seeded by `setup_db.php`.
- **Schema version** — **patch filename stem** (no `.sql`) when the release includes DDL; stored in `app_version.schema_version` and `TEMPER_EXPECTED_SCHEMA_VERSION`.
  - Example: `20260827_0944_setup_baseline_consolidation` (current setup baseline schema stem)
  - Pre-patch alpha baseline id: `setup_baseline`
- **Every** app version history row **must** include a `schema_version`.
- If a new app version has **no** schema changes, **carry forward** the previous schema version stem (same string). Post-baseline process-only releases still ship a **minimal patch** that `INSERT`s the new `app_version` row (see `archive/20260726_0900_beta_baseline.sql` for the historical pattern).
- Aim for **one patch file per app version** after a setup freeze.
- **New** patch basenames use `YYYYMMDD_<appversion_without_decimal>_description.sql` (see above).

---

## Development rule: consolidate patches

After every **5–10** small schema patches (or at a natural milestone such as a new setup baseline):

1. Fold post-baseline DDL into `setup-database/*.php` so destructive setup matches the current schema shape.
2. Raise `TEMPER_SETUP_BASELINE_APP_VERSION` / expand `TEMPER_VERSION_HISTORY` through the new baseline.
3. Move folded incremental patches into [`archive/`](archive/) so they are not the required install path.
4. Document the consolidation in `VERSION.md`.
5. Fresh installs always take **baseline** schema from `setup-database/*.php` via `setup_db.php` (through **0.944**), then apply only **post-0.944** patches from this folder.

---

## Header template

Copy [`_header_template.sql`](_header_template.sql) when creating a new patch. Fill every field; use `None` where a section does not apply.

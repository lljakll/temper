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

- **`setup_db.php` is frozen at app v0.804.** It creates the baseline schema and seeds `app_version` history **through 0.804 only**.
- **Do not** add 0.805+ rows to setup seed scripts. Those releases exist only as files in this folder.
- After a destructive setup, apply every **post-0.804** patch listed in [`VERSION.md`](../VERSION.md) (in order) to reach the current release.
- Pre-0.804 patches remain here for operators upgrading old databases; they are already embodied in the setup scripts for new databases.

---

## Filename format

```
YYYYMMDD_NN_short_description.sql
```

| Part | Meaning |
|------|---------|
| `YYYYMMDD` | Date the patch was authored (UTC or local project convention) |
| `NN` | Two-digit sequence for that day (`01`, `02`, …) |
| `short_description` | Lowercase words separated by underscores |

**Example:** `20260725_01_app_version_history.sql`

The **schema version** is the filename **stem** (no `.sql`):

```text
20260725_01_app_version_history
```

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

- **App version** (e.g. `0.805`) — product / release version in `config.php` (`APP_VERSION`), `TEMPER_DEFAULT_APP_VERSION`, `VERSION.md`, and `app_version.version`.
- **Setup baseline app version** — `TEMPER_SETUP_BASELINE_APP_VERSION` (**0.804**). Highest version seeded by `setup_db.php`.
- **Schema version** — **patch filename stem** (no `.sql`) when the release includes DDL; stored in `app_version.schema_version` and `TEMPER_EXPECTED_SCHEMA_VERSION`.
  - Example: `20260725_03_formalize_audit_log`
  - Pre-patch baseline (schema from `setup_db.php` only): `setup_baseline`
- **Every** app version history row **must** include a `schema_version`.
- If a new app version has **no** schema changes, **carry forward** the previous schema version stem (same string). Post-baseline process-only releases still ship a **minimal patch** that `INSERT`s the new `app_version` row (see `20260725_04_frozen_baseline_model.sql`).
- Aim for **one patch file per app version** after the 0.804 freeze.

---

## Development rule: consolidate patches

After every **5–10** small schema patches (or at a natural milestone such as beta freeze):

1. Produce one **clean consolidated** schema update that represents the net schema delta since the last consolidation (or since the 0.804 setup baseline).
2. Prefer a single well-reviewed file over a long chain of tiny patches for operators who lag behind.
3. Document the consolidation in `VERSION.md` (which old patch files it supersedes, and that those files remain in git for history but are not re-applied on fresh installs).
4. Fresh installs always take **baseline** schema from `setup-database/*.php` via `setup_db.php` (through 0.804), then apply post-baseline patches from this folder — not by replaying the entire historical chain into setup seed.

Individual historical patches stay in this folder for operators upgrading from older releases and for auditability.

---

## Header template

Copy [`_header_template.sql`](_header_template.sql) when creating a new patch. Fill every field; use `None` where a section does not apply.

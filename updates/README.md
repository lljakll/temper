# Temper — Manual Database Schema Updates

There is **no in-app update system**. Operators apply schema changes by hand.

| What | How |
|------|-----|
| **Code** | `git pull` / deploy as usual |
| **Schema** | Run the matching `.sql` file in this folder with the `mysql` client |
| **Changelog** | Human-readable notes live in [`../VERSION.md`](../VERSION.md) |
| **Version history (DB)** | Append-only rows in `app_version` (app version + schema version + timestamp) |

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

Fresh installs do **not** need these patches: `setup_db.php` creates the current schema and seeds the full `app_version` history.

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

The filename **is** the schema patch id. Reference it exactly in `VERSION.md` and in the patch’s own `INSERT INTO app_version` row.

---

## SQL file structure

Every patch must start with the metadata header (see `_header_template.sql`), then the executable SQL.

Order of content:

1. Metadata comment block (required)
2. Schema DDL (`ALTER` / `CREATE` / `DROP` / indexes / FKs)
3. Data fixes (`UPDATE` / `INSERT` / cleanup) when needed
4. **Always** append an `app_version` history row at the end so the DB records that the patch was applied

Do **not** rely on the application to detect or apply patches.

---

## Versioning conventions

- **App version** (e.g. `0.802`) — product / release version in `config.php` (`APP_VERSION`), `TEMPER_DEFAULT_APP_VERSION`, `VERSION.md`, and `app_version.version`.
- **Schema version** (integer) — bumps when a patch changes structure. Stored in `app_version.schema_version` and `TEMPER_EXPECTED_SCHEMA_VERSION` in `includes/app_version.php`.
- Aim for **one schema patch per app version**. If multiple schema edits land in one release, either merge them into a single patch before release, or bump app versions accordingly.

---

## Development rule: consolidate patches

After every **5–10** small schema patches (or at a natural milestone such as beta freeze):

1. Produce one **clean consolidated** schema update that represents the net schema delta since the last consolidation (or since baseline).
2. Prefer a single well-reviewed file over a long chain of tiny patches for operators who lag behind.
3. Document the consolidation in `VERSION.md` (which old patch files it supersedes, and that those files remain in git for history but are not re-applied on fresh installs).
4. Fresh installs always take schema from `setup-database/*.php` via `setup_db.php`, not from replaying the entire `updates/` chain.

Individual historical patches stay in this folder for operators upgrading from older releases and for auditability.

---

## Header template

Copy [`_header_template.sql`](_header_template.sql) when creating a new patch. Fill every field; use `None` where a section does not apply.

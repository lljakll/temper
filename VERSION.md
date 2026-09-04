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
- [v0.945](#v0945)
- [v0.944](#v0944)
- [v0.943](#v0943)
- [v0.942](#v0942)
- [v0.941](#v0941)
- [v0.940](#v0940)
- [v0.939](#v0939)
- [v0.938](#v0938)
- [v0.937](#v0937)
- [v0.936](#v0936)
- [v0.935](#v0935)
- [v0.934](#v0934)
- [v0.933](#v0933)
- [v0.932](#v0932)
- [v0.931](#v0931)
- [v0.930](#v0930)
- [v0.929](#v0929)
- [v0.928](#v0928)
- [v0.927](#v0927)
- [v0.926](#v0926)
- [v0.925](#v0925)
- [v0.924](#v0924)
- [v0.923](#v0923)
- [v0.922](#v0922)
- [v0.921](#v0921)
- [v0.920](#v0920)
- [v0.919](#v0919)
- [v0.918](#v0918)
- [v0.917](#v0917)
- [v0.916](#v0916)
- [v0.915](#v0915)
- [v0.914](#v0914)
- [v0.913](#v0913)
- [v0.912](#v0912)
- [v0.911](#v0911)
- [v0.910](#v0910)
- [v0.909](#v0909)
- [v0.908](#v0908)
- [v0.907](#v0907)
- [v0.906](#v0906)
- [v0.905](#v0905)
- [v0.904](#v0904)
- [v0.903](#v0903)
- [v0.902](#v0902)
- [v0.901](#v0901)
- [v0.900](#v0900)
- [v0.811](#v0811)
- [v0.810](#v0810)
- [v0.809](#v0809)
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
| **`setup_db.php` + `setup-database/*`** | **Frozen at app v0.944.** Destructive setup always leaves the database at 0.944 with full history through 0.944 and current schema (`accounts.account_type`, `users.preferences`, and earlier shape including `transaction_details.description`). |
| **`TEMPER_VERSION_HISTORY`** | Seeds **through 0.944**. Do **not** add post-0.944 rows here. |
| **`updates/*.sql`** | **Only** path for releases **after 0.944** (DDL and/or `app_version` history rows). Pre-0.944 patches live in `updates/archive/` for historical recovery only. |

After a fresh setup at the current baseline, **SCHEMA is current** when `setup_db.php` matches this 0.944 milestone — no further patches are required until the next post-0.944 release. Operators on existing live databases apply the 0.944 consolidation patch (non-destructive), then any later listed patches. **Do not** run destructive setup against a database that has live treasurer data.

### Schema version = patch filename stem

The **schema version** is the patch file’s basename **without** `.sql` when that release changes schema:

| Patch file | Schema version (`app_version.schema_version`) |
|------------|-----------------------------------------------|
| `updates/20260725_01_app_version_history.sql` | `20260725_01_app_version_history` |
| `updates/20260725_02_schema_version_as_filename.sql` | `20260725_02_schema_version_as_filename` |
| `updates/20260725_03_formalize_audit_log.sql` | `20260725_03_formalize_audit_log` |
| `updates/20260726_0811_tx_memo_to_description.sql` | `20260726_0811_tx_memo_to_description` |
| `updates/20260827_0944_setup_baseline_consolidation.sql` | `20260827_0944_setup_baseline_consolidation` |
| *(schema from early setup only)* | `setup_baseline` |

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

After every **5–10** schema patches (or at a natural milestone such as beta), fold post-baseline DDL into `setup-database/*`, raise `TEMPER_SETUP_BASELINE_APP_VERSION`, seed full history through the new baseline, move older patch files to `updates/archive/`, and document under the consolidating release.

### Fresh installs

`php setup_db.php` builds the **0.944 baseline** schema from `setup-database/*.php` (including `accounts.account_type` and `users.preferences`) and seeds `app_version` history **through 0.944** (complete alpha + beta chain included).  
No demo accounts, budgets, or transactions are inserted — only lookup/reference data (roles, natural/functional categories, structural funds) and default users.  
Do **not** replay pre-0.944 patches (including `updates/archive/`) after a current setup; they are already embodied in the setup scripts. Releases after 0.944 require `updates/*.sql` patches listed under those versions. SCHEMA is current when `setup_db.php` matches this 0.944 milestone.

---

## v0.945

**Ledger list: Check # and Budget columns** — 2026-09-04

> No schema update required for this release (list display and query only).  
> **Patch file (history row):** `updates/20260904_0945_ledger_list_check_budget_columns.sql`  
> **Schema version:** `20260827_0944_setup_baseline_consolidation` *(carried forward)*  
> **Min app version:** 0.945

- Main Ledger transaction list shows **Check #** and **Budget** from the transaction header (`transaction_details.check_number` and assigned `budget_id` name).
- Empty check number and unassigned budget display as blank (no placeholder text).
- Values are included in the list API/query so infinite scroll and existing filters keep working.
- Excel-style column sort and auto-filter apply to both new columns (Budget filter includes blanks).
- Add/Edit/View modal behavior is unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.945**. Setup baseline remains **0.944**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.944 | `updates/20260904_0945_ledger_list_check_budget_columns.sql` (records 0.945; no DDL) |
| Fresh setup (0.944) | Same process-only patch after `php setup_db.php` |

---

## v0.944

**Setup baseline consolidation — current live schema in `setup_db.php`** — 2026-08-27

> ### **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
>
> **Patch file:** `updates/20260827_0944_setup_baseline_consolidation.sql`  
> **Schema version:** `20260827_0944_setup_baseline_consolidation`  
> **Min app version:** 0.944

- **`setup_db.php` advanced to 0.944.** A clean run now creates the **current** schema (the same shape as a 0.900 install after all patches through 0.938):
  - `accounts.account_type` `ENUM('asset','liability','equity','income','expense') NOT NULL` + `idx_accounts_account_type` (from 0.901)
  - `users.preferences` nullable JSON (from 0.938)
  - All earlier 0.900 shape (`transaction_details.description`, users/roles, audit_log, etc.)
- **Repair:** a fresh `setup_db.php` run on 0.938+ code was broken — `06-users-roles.php` calls `ensureUsersRolesSchema()`, which requires `users.preferences`, but the 0.900 CREATE TABLE did not include that column. `accounts.account_type` was likewise missing from setup while runtime checks and the CoA UI require it.
- **Seeds unchanged:** roles (including `page.ledger.mass_import` on Treasurer / Finance Manager / Archivist), natural/functional categories, structural funds, default users. No demo accounts, budgets, or transactions.
- **Patch chain:** pre-0.944 incremental files moved to `updates/archive/`. They are **not** the required install path.
- **`php setup_db.php --check`** reports setup baseline version, current DB version, and whether updates are pending (same class of milestone as 0.911 messaging).
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` / `TEMPER_SETUP_BASELINE_APP_VERSION` = **0.944**; `TEMPER_EXPECTED_SCHEMA_VERSION` = `20260827_0944_setup_baseline_consolidation`.
- **SCHEMA is current** when `setup_db.php` matches this milestone.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.943 (live DB already at `users.preferences`) | `updates/20260827_0944_setup_baseline_consolidation.sql` (records 0.944; idempotent DDL is a no-op) |
| v0.900–0.942 | Same consolidation patch (adds any missing columns; do **not** replay `updates/archive/`) |
| Fresh setup (0.944) | No further patches required for 0.944 |
| Below 0.900 | Archived patches in order, **or** a new empty install via `php setup_db.php`. Do **not** run destructive setup against live treasurer data. |

---

## v0.943

**Ledger: Ref # suggestion tip is last saved Ref # plus one** — 2026-08-26

> No schema update required for this release (suggestion value only).  
> **Patch file (history row):** `updates/20260826_0943_ledger_ref_suggest_last_plus_one.sql`  
> **Schema version:** `20260825_0938_user_preferences` *(carried forward)*  
> **Min app version:** 0.943

- The recommended Ref # on Add and Edit (when the field is blank) is now the **most recently saved** transaction’s Ref # **plus one** (e.g. saved `260801` → tip shows `260802`).
- Still a field tip/placeholder only — not auto-filled. Double-click accepts it; typing clears the tip. An already-entered Ref # is never overwritten.
- If last+1 is already used, the tip skips to the next unused number in that numeric sequence. Accepting a used number still shows **Already Used** and the existing confirm-on-save reuse prompt.
- Does not assign GUIDs or a new global 0…n scheme. Year-range fallback (YY0100+ for ledger entry) is used only when no YY#### Ref # has been saved yet.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.943**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.942 | `updates/20260826_0943_ledger_ref_suggest_last_plus_one.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.942 patches, then this process patch |

---

## v0.942

**Ledger: temporary bulk-apply for similar pending transactions** — 2026-08-26

> No schema update required for this release (temporary ledger helper only).  
> **Patch file (history row):** `updates/20260826_0942_ledger_temp_bulk_apply.sql`  
> **Schema version:** `20260825_0938_user_preferences` *(carried forward)*  
> **Min app version:** 0.942

- Temporary **Bulk apply** action on the Ledger toolbar (Admin / Treasurer). Select one or more rows with the existing Ctrl/Shift selection, then apply the same counterpart account, fund, description, and/or line note to every selected **pending** transaction.
- Blank fields are left unchanged. Bank/cash (asset) lines are not recoded; account, fund, and line note go on the counterpart (non-asset) line, matching normal fund-tagging rules.
- Confirmation is required and shows how many pending transactions will be updated. Cleared and reconciled rows in the selection are skipped and counted in the result.
- Writes use the existing ledger update path (header, replace lines, audit). No importer, fuzzy matching, templates, or per-row values. Marked `TEMP_BULK_TXN_MANAGER` for removal with the temporary importers.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.942**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.941 | `updates/20260826_0942_ledger_temp_bulk_apply.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.941 patches, then this process patch |

---

## v0.941

**Ledger: Excel-style column filter search selects matching values on Apply** — 2026-08-26

> No schema update required for this release (client-side ledger auto-filter only).  
> **Patch file (history row):** `updates/20260826_0941_ledger_filter_search_select.sql`  
> **Schema version:** `20260825_0938_user_preferences` *(carried forward)*  
> **Min app version:** 0.941

- Typing in a column auto-filter search box now **deselects unique values that do not match** and **selects the matching values**.
- Apply then filters the ledger to the search-matching values only (Excel-style), instead of treating the still-checked hidden values as “Select All / no filter”.
- Matching values remain selected (or become selected) so Apply does not require a second pass through the checkboxes.
- Manual check/uncheck without using search is unchanged. **(Select All)** still applies to the currently visible / search-filtered list.
- Per-column Clear and Clear all filters are unchanged. Server-side filtering, infinite scroll, and sort are unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.941**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.940 | `updates/20260826_0941_ledger_filter_search_select.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.940 patches, then this process patch |

---

## v0.940

**Ledger: optional per-line Note on transaction lines** — 2026-08-26

> No schema update required for this release (reuses existing `transaction_lines.description`).  
> **Patch file (history row):** `updates/20260826_0940_transaction_line_notes.sql`  
> **Schema version:** `20260825_0938_user_preferences` *(carried forward)*  
> **Min app version:** 0.940

- Each transaction line has an optional **Note** that applies only to that line (not the header Description).
- Exposed the unused `transaction_lines.description` column in the UI as “Note” — no new column.
- Compact Note input on each line in Add/Edit; read-only on each line in View.
- Saving Add/Edit persists line notes with the lines. Empty notes are allowed.
- Adding, changing, or clearing a line note is recorded in the transaction audit trail (`transaction_events`).
- Notes do not affect balances, fund calculations, or posting rules. The main ledger list is unchanged (no line-note columns).
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.940**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.939 | `updates/20260826_0940_transaction_line_notes.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.939 patches, then this process patch |

---

## v0.939

**Ledger: delete pending transactions (Admin / Treasurer only)** — 2026-08-26

> No schema update required for this release (ledger delete behavior only).  
> **Patch file (history row):** `updates/20260826_0939_pending_transaction_delete.sql`  
> **Schema version:** `20260825_0938_user_preferences` *(carried forward)*  
> **Min app version:** 0.939

- Pending transactions can be deleted from the Ledger toolbar (selected row) and from the Edit modal.
- Delete is limited to the **Administrator** and **Treasurer** active roles. Other roles with ledger write (e.g. Finance Manager) do not see the control and cannot delete via the API.
- Cleared and reconciled transactions cannot be deleted: the control is disabled/hidden, and the server refuses any such request (`DELETE … AND status = 'pending'`).
- Confirmation shows Ref #, date, amount, and payee, and requires a **Reason for delete**. An empty reason is rejected.
- A successful delete removes the header, all lines, document records, on-disk attachment files, and (via FK cascade) `transaction_events`. A system `audit_log` row (`ledger.transaction_deleted`) records user (with active role), timestamp, ref #, date, amount, payee, and the reason.
- No void / soft-delete. Line-only delete of a header is not added. Existing cleared/reconciled edit locks are unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.939**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.938 | `updates/20260826_0939_pending_transaction_delete.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.938 patches, then this process patch |

---

## v0.938

**Dashboard Total Cash / Bank Balances + per-user preferences storage** — 2026-08-25

> ### **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
>
> **Patch file:** `updates/20260825_0938_user_preferences.sql`  
> **Schema version:** `20260825_0938_user_preferences`  
> **Min app version:** 0.938

- **DDL:** `users.preferences` — nullable JSON. Small keyed per-user settings only (no preferences management UI).
- **Helpers:** `getUserPreference($db, $userId, $key, $default = null)` and `setUserPreference($db, $userId, $key, $value)` in `includes/user_preferences.php`.
- **Preference key convention** (dot-separated path; stored as nested JSON; reuse for future cards):
  - `<area>.<subject>[.<option>...]`
  - `dashboard.<card_id>.<option>` — e.g. `dashboard.total_cash.account_ids` (list of Chart of Accounts ids)
  - `ledger.<option>` — e.g. `ledger.double_click` (reserved; still browser `localStorage` in 0.936)
- **Total Cash / Bank Balances** card sums the selected **asset** accounts (no longer hardcoded names `Cash` / `Bank Account`, which left the total at $0).
  - Default when no preference is stored: every active `account_type = asset` account.
  - Gear control in the card header opens a multi-select of asset accounts; the choice is saved under `dashboard.total_cash.account_ids` and the total recalculates.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.938**; `TEMPER_EXPECTED_SCHEMA_VERSION` = `20260825_0938_user_preferences`. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.937 | `updates/20260825_0938_user_preferences.sql` |
| Fresh setup (0.900) | Apply 0.901 → 0.937 patches, then this schema patch |

---

## v0.937

**Fund tagging: ignore asset-account tags when computing fund balances** — 2026-08-25

> No schema update required for this release (fund-balance calculation rule only).  
> **Patch file (history row):** `updates/20260825_0937_fund_tag_asset_ignored.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.937

- Fund balances (dashboard, Fund Balances report, Restricted Funds inflows/outflows) ignore fund tags on Asset accounts (Checking, cash/bank, and any `account_type = asset`).
- Only income, expense, and equity / net assets (WODR and WDR) lines change a fund: income or Net Assets – WDR credits increase it; expense or Net Assets – WDR (release) debits decrease it.
- Asset lines may still store a fund tag; it has no effect on fund balances. Ledger Add/Edit help text and a muted Fund dropdown on asset lines make this visible.
- Ledger posting, cleared/reconciled behavior, and non-fund reports are unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.937**. Setup baseline remains **0.900**.

---

## v0.936

**Ledger Add/Edit form layout, account grouping, currency amounts, and double-click toggle** — 2026-08-25

> No schema update required for this release (Ledger UI polish only).  
> **Patch file (history row):** `updates/20260825_0936_ledger_form_layout_dblclick.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.936

- Add/Edit transaction: Date (and other clipped typed fields) are wide enough that the full value, including the year, stays visible while typing. Budget may still truncate when closed.
- Accounts dropdown is grouped by Account Type with a `---------` delimiter between groups, sorted by COA number within each group; the temporary `000000` account is last.
- Debit and Credit line amounts display as currency and reformat to currency when the input loses focus.
- Ledger toolbar: View/Edit toggle to the right of the action buttons sets the default double-click action on a row. Choice persists in the browser until changed. Cleared/reconciled Edit rules are unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.936**. Setup baseline remains **0.900**.

---

## v0.935

**Fix Bank Export Preview “unexpected server response”** — 2026-08-25

> No schema update required for this release (CSV parse / JSON response fix only).  
> **Patch file (history row):** `updates/20260825_0935_bank_export_preview_json.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.935

- Preview on the temporary Bank Export importer failed with “unexpected server response” because PHP 8.4 deprecation notices from `fgetcsv()` (missing `$escape` argument) were printed into the JSON body when `display_errors` is on, so the client could not parse the reply.
- `fgetcsv()` now passes length, separator, enclosure, and escape explicitly. POST JSON replies also start an output buffer so stray notices cannot leak in front of the payload.
- Import flow is otherwise unchanged. `TEMP_BANK_EXPORT_IMPORTER` flags remain.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.935**. Setup baseline remains **0.900**.

---

## v0.934

**Temporary FMB Checking Bank Export mass importer** — 2026-08-25

> No schema update required for this release (temporary CSV importer only).  
> **Patch file (history row):** `updates/20260825_0934_bank_export_import.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.934

- Adds a temporary, self-contained **Bank Export** importer under Ledger (accordion, alongside Ledger and Beancount Import).
- Access is limited to Administrator, Treasurer, Finance Manager, and Archivist (reuses `page.ledger.mass_import`).
- Accepts bank-export CSV (`Account Name`, `Processed Date`, `Description`, `Check Number`, `Credit or Debit`, `Amount`). Account Name is ignored; every row posts to **FMB: Checking Account** vs existing **Imbalance**.
- Credit (money in) debits Checking / credits Imbalance; Debit (money out) credits Checking / debits Imbalance. No funds, no Reference #, no duplicate check, no attachments.
- Paste or file-upload → preview → confirm write. Marked `TEMP_BANK_EXPORT_IMPORTER` for later removal; Beancount Mass Import and Import-from-Text are unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.934**. Setup baseline remains **0.900**.

---

## v0.933

**Keep attachment files in sync with ledger records and full transaction purge** — 2026-08-24

> No schema update required for this release (file-sync + audit behavior only).  
> **Patch file (history row):** `updates/20260824_0933_attachment_file_sync.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.933

- Deleting an attachment on an **editable** (pending) transaction now removes the on-disk file as well as the database row, including leftover copies under the Reference # folder, numeric-id folder, or legacy `transaction_documents/{id}/`. Empty folders are removed. The transaction audit trail records the file deletion.
- Changing **Ref #** on an editable transaction moves the attachment files to the new Reference # folder **before** the stored Ref # is updated, so files stay linked. Old folders are not left behind. The move is recorded in the transaction audit trail (`document_relocated`).
- Database Maintenance **Clear All Transactions** and **Clear All Financial Data** also purge on-disk transaction attachment files (`storage/attachments/` and legacy `storage/transaction_documents/`).
- Cleared or reconciled transactions remain immutable: attachment files cannot be deleted or renamed/moved, and no file-related audit events (`document_uploaded`, `document_deleted`, `document_relocated`) are written for them.
- Upload, view/download, ledger paperclip indicator, and the portfolio viewer are unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.933**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.932 | `updates/20260824_0933_attachment_file_sync.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.932 patches, then this process patch |

---

## v0.932

**Sidebar Switch Role button and role popout** — 2026-08-20

> No schema update required for this release (sidebar role-switcher UI only).  
> **Patch file (history row):** `updates/20260820_0932_sidebar_switch_role_popout.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.932

- The sidebar no longer lists each assigned role as a link above My Profile.
- The **active role** is shown as a label under the welcome name.
- Users with more than one role get a **Switch Role** button. Clicking it opens a drop-up list of assigned roles; choosing one switches the session role (same as before: permissions, menu, and audit stamp).
- Role assignment, session persistence, and `username (Role)` audit stamping are unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.932**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.931 | `updates/20260820_0932_sidebar_switch_role_popout.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.931 patches, then this process patch |

---

## v0.931

**Active role switching and role-stamped audit events** — 2026-08-20

> No schema update required for this release (session active-role + audit username format).  
> **Patch file (history row):** `updates/20260820_0931_active_role_switching.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.931

- Users with more than one assigned role can choose which role is **active** for the session from the sidebar (links next to the profile / welcome block).
- Permissions and menu visibility follow **only the active role**, not the union of all assigned roles. Role assignments themselves are unchanged.
- The active role persists in the session across page reloads until the user switches again or logs out. Login defaults to the user’s primary role.
- Administrator (and other super-role) access remains available by switching back to that role; last-admin protections still look at assigned roles so an admin is not locked out of their assignment.
- New `audit_log` and `transaction_events` rows stamp the active role next to the username (`admin (Treasurer)`, `jak (Archivist)`).
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.931**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.930 | `updates/20260820_0931_active_role_switching.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.930 patches, then this process patch |

---

## v0.930

**Mass Import: same-batch duplicates stay in review; amount weighs more; live re-check** — 2026-08-20

> No schema update required for this release (Mass Import duplicate logic/UI only).  
> **Patch file (history row):** `updates/20260820_0930_mass_import_duplicate_refine.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.930

- Same-batch duplicates are still flagged in the import list, but they no longer open the side-by-side modal. The detail pane has a **Legitimate / Allow** checkbox so they can be reviewed, edited, marked acceptable, and imported with Confirm.
- The side-by-side resolution modal runs **only** when an importing transaction matches one already in the ledger.
- Amount is a strong confirming/disconfirming factor. Substantially different amounts (e.g. $700 vs $69) are not treated as duplicates even if date, ref, or other fields are similar. Date, Ref #, and Check # remain primary when amounts are compatible.
- Editing date, ref, check, amount, payee, accounts, etc. in the detail pane re-runs duplicate detection against the rest of the paste and the ledger and updates list flags immediately.
- Flow, permissions, and menu placement are unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.930**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.929 | `updates/20260820_0930_mass_import_duplicate_refine.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.929 patches, then this process patch |

---

## v0.929

**Mass Import Parse and Clear buttons actually run** — 2026-08-20

> No schema update required for this release (SPA script bootstrap only).  
> **Patch file (history row):** `updates/20260820_0929_mass_import_button_handlers.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.929

- Mass Import is loaded as an AJAX fragment. A normal `<script>` tag is not executed after `innerHTML` injection, so **Parse** and **Clear** did nothing.
- The page now uses the same `text/plain` init-script + onload bootstrap as Ledger and other SPA pages, so Parse runs the Beancount parser and advances to review (or shows errors), and Clear empties the paste box.
- No change to Mass Import flow, role checks, or menu placement.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.929**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.928 | `updates/20260820_0929_mass_import_button_handlers.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.928 patches, then this process patch |

---

## v0.928

**Beancount Mass Import under Ledger (temporary historical loader)** — 2026-08-19

> No schema update required for this release (Mass Import is a self-contained page + permission data).  
> **Patch file (history row):** `updates/20260819_0928_beancount_mass_import.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.928

- New **Mass Import** function (separate from the Add Transaction **Import from Text** modal; not a button on the main Ledger page).
- Ledger sidebar is an accordion for permitted roles: **Ledger** (main view) and **Import**. Export is not included yet.
- Restricted to **Administrator**, **Treasurer**, **Finance Manager**, and **Archivist** (permission `page.ledger.mass_import`, plus those role names). Financial Secretary and other roles do not see Import.
- Paste a block of Beancount transactions → parse into a dated review queue with the existing fuzzy account/fund matching applied automatically.
- Two-pane review: list (Date, Ref #, Amount, Pay To) and a correction panel. Corrections stay in the queue until Confirm / Import.
- Confirm writes non-duplicates in one batch. Flagged duplicates (vs ledger and vs other items in the same paste) are resolved in a side-by-side modal; each confirmed item is written immediately. Unresolved duplicates cannot remain when the process finishes.
- Imports transactions only (no attachments). Accounts, funds, and budgets must already exist.
- Module is isolated in `includes/beancount_mass_import.php` + `pages/ledger_import.php` so it can be deleted after historical load.
- Patch also appends `page.ledger.mass_import` to Treasurer, Finance Manager, and Archivist role JSON when missing (no table DDL).
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.928**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.927 | `updates/20260819_0928_beancount_mass_import.sql` (records version; grants mass-import permission on named roles; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.927 patches, then this process patch |

---

## v0.927

**Backup packages include on-disk user data (attachments and system config)** — 2026-08-19

> No schema update required for this release (backup/restore process only).  
> **Patch file (history row):** `updates/20260819_0927_backup_include_storage_files.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.927

- New **data-only** and **full** backups are **zip packages** that contain the database dump **and** selected storage files: `attachments/` (transaction documents), `config/` (system settings such as `system.json`), and legacy `transaction_documents/`.
- Logs, exports, working scratch files, and the `backups/` directory itself are not included. Browser-only preferences (theme, font size, sidebar) are not stored on the server.
- Restore of a zip package restores both the database and those storage files. Legacy **SQL-only** dumps (`.sql`) still restore database rows only and leave files on disk unchanged.
- The data-only vs full distinction is unchanged: data-only omits schema and the `app_version` / `audit_log` tables; full dumps still include DROP/CREATE and every table. Both types now carry the same user-data files.
- SQL / CSV / both remains the dump format *inside* the package (one zip, not two files). Listing, download, checksum, unlock/delete, and auto-backup flows are unchanged aside from the package contents.
- PHP upload ceilings were raised so package restore can include attachments; ledger attachment size stays capped at 20 MB in application code.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.927**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.926 | `updates/20260819_0927_backup_include_storage_files.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.926 patches, then this process patch |

---

## v0.926

**Ledger Import from Text: tolerant account matching and a resolve dialog** — 2026-08-19

> No schema update required for this release (Ledger Import-from-Text matching only).  
> **Patch file (history row):** `updates/20260819_0926_import_fuzzy_account_match.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.926

- Import from Text no longer requires an exact chart name. Matching is tolerant of extra spaces, punctuation, case, Beancount-style `Assets:…` paths, colon-separated chart names (`Supplies:Kitchen`), COA numbers, common abbreviations, and minor spelling differences.
- A high-confidence unique match is applied automatically (with a fuzzy warning when the paste was not an exact name).
- When the match is ambiguous or below the confidence threshold, the paste is **not discarded**. A **Match accounts** step lists each unclear line and lets the user pick the chart account or skip the line, then populate the Add form.
- Sequence / ref / check / amount / fund parsing is unchanged. Manual Add/Edit entry is unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.926**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.925 | `updates/20260819_0926_import_fuzzy_account_match.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.925 patches, then this process patch |

---

## v0.925

**Backup page lists every file in `storage/backups` on load** — 2026-08-18

> No schema update required for this release (Backup page listing only).  
> **Patch file (history row):** `updates/20260818_0925_backup_list_existing_on_load.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.925

- The Backup page scans `storage/backups` when it loads and shows **every** existing backup file in **Saved backups** (the previous 12-item cap is removed).
- Restore archives (`restored_*.sql` / `.zip`) that already sit in that directory are included, with download / unlock / delete unchanged.
- Newly created backups still appear after generation (page reload after Create).
- Backup creation and file contents are unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.925**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.924 | `updates/20260818_0925_backup_list_existing_on_load.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.924 patches, then this process patch |

---

## v0.924

**Ledger modal Ctrl/Cmd hotkeys, paperclip refresh, and single-save attach** — 2026-08-18

> No schema update required for this release (Ledger Add/Edit modal behavior only).  
> **Patch file (history row):** `updates/20260818_0924_ledger_modal_hotkeys_attach_save.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.924

- **Add/Edit hotkeys** use **Ctrl+key** (⌘+key on Mac) instead of the `;` leader. They work while a form field (including Date) has focus. Inside the modal they override other key capture except cut, copy, paste, and select-all.
- After an attachment is uploaded on Save, the **paperclip** appears on that ledger row immediately (no manual page or list refresh).
- A file sitting in the picker when the user clicks **Save** is included in that one save/upload sequence. It no longer rides along on the transaction POST, which had caused a second insert and a duplicate Reference # error.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.924**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.923 | `updates/20260818_0924_ledger_modal_hotkeys_attach_save.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.923 patches, then this process patch |

---

## v0.923

**Ledger Add attachments, save-upload, and deposit budget default** — 2026-08-16

> No schema update required for this release (Ledger modal behavior only).  
> **Patch file (history row):** `updates/20260816_0923_ledger_add_attachments_deposit_budget.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.923

- **Add Transaction** now shows the Documents / file-upload controls so attachments can be chosen during create, not only after a later Edit.
- Files queued or still sitting in the file picker are **uploaded automatically after Save** (they are no longer discarded). A confirmation is not required; a toast reports upload success or failure.
- **Deposits** (debit to an asset / cash-bank account, with no asset credit) no longer auto-fill the Budget dropdown. The user can still pick a budget manually. Expense-style entries still get the date-based default.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.923**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.922 | `updates/20260816_0923_ledger_add_attachments_deposit_budget.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.922 patches, then this process patch |

---

## v0.922

**Ledger keyboard shortcuts and View → Edit** — 2026-08-16

> No schema update required for this release (Ledger UI / hotkeys only).  
> **Patch file (history row):** `updates/20260816_0922_ledger_hotkeys_view_edit.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.922

- **Ledger leader-key hotkeys** match Lookup maintenance pages: press `;` then a command (ignored while typing in a field).
- Supported commands: focus first column filter, clear all filters, Add Transaction, View selected, Edit selected, close the current modal, Import from Text, add a line in Add/Edit.
- **Ctrl+S / ⌘S** saves only while the Add/Edit transaction modal is the top modal; otherwise the browser “Save Page” shortcut is left alone.
- While the **View** modal is open, `;` then `e` (or the new **Edit** button) switches to Edit for the same transaction when the user has write permission. Cleared/reconciled transactions still use budget-only edit.
- Shortcuts are discoverable via the keyboard-icon help popover (same feel as Lookup pages).
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.922**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.921 | `updates/20260816_0922_ledger_hotkeys_view_edit.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.921 patches, then this process patch |

---

## v0.921

**Data-only backups exclude system tables (`app_version`, `audit_log`)** — 2026-08-14

> No schema update required for this release (backup/restore process only).  
> **Patch file (history row):** `updates/20260814_0921_data_backup_exclude_system_tables.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.921

- **Data-only backups** (SQL, CSV, and auto-backup) no longer include `app_version` or `audit_log`.
- **Roles** and all other operational tables remain in the data-only dump.
- **Restore** of a data-only backup leaves existing `app_version` history intact (older dumps that still contain those tables are also skipped at restore time). `audit_log` is likewise left intact.
- **Full (schema + data)** backups and Database Maintenance full restore are unchanged.
- Create, download, and restore flows are otherwise unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.921**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.920 | `updates/20260814_0921_data_backup_exclude_system_tables.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.920 patches, then this process patch |

---

## v0.920

**Import `sequence:` → Ref #; simplify already-used warning** — 2026-08-14

> No schema update required for this release (ledger UI only).  
> **Patch file (history row):** `updates/20260814_0920_import_sequence_ref_warning.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.920

- **Import from Text:** metadata keys `sequence:` and `ref:` are recognized in addition to `reference:` and populate the Ref # field.
- **Ref # field warning:** contribution-range advisory is no longer shown. When the number is already used, the only message under the field is yellow **Already Used** (no icon, no transaction id, no extra wording). Confirm-on-save for reuse is unchanged.
- Other transaction form behavior and toasts are unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.920**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.919 | `updates/20260814_0920_import_sequence_ref_warning.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.919 patches, then this process patch |

---

## v0.919

**Ledger portfolio viewer: narrower modal, clearer close, wheel page-turn** — 2026-08-14

> No schema update required for this release (ledger UI only).  
> **Patch file (history row):** `updates/20260814_0919_ledger_portfolio_narrow_wheel.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.919

- **Modal width:** portfolio dialog is about **25% narrower** (~75vw) so side backdrop remains visible and click-outside-to-close is easier. Viewport height, static selector panes, and fit-height default zoom are unchanged.
- **Close control:** the header X is a larger outlined button (`bi-x-lg`) instead of the default small Bootstrap close glyph.
- **Scroll-wheel page turning:** with a multi-page PDF selected, the wheel over the main preview pane goes to the next page (down) or previous page (up). The wheel does not zoom or scroll page content in that pane.
- Document list icons, page thumbnails, Download, paperclip indicator, filters, sort, and infinite scroll are unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.919**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.918 | `updates/20260814_0919_ledger_portfolio_narrow_wheel.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.918 patches, then this process patch |

---

## v0.918

**Ledger portfolio viewer: viewport modal, page panel, fit-height zoom** — 2026-08-14

> No schema update required for this release (ledger UI only).  
> **Patch file (history row):** `updates/20260814_0918_ledger_portfolio_viewer_refine.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.918

- **Modal size:** attachment portfolio dialog fills the viewport height (and nearly full width).
- **Static selector panes:** document list is a fixed 248px column; the PDF page panel is a fixed 148px column. The preview pane takes the remaining space. Selector widths are not percentage/flex-grown.
- **Document list:** each file uses a large type-only icon (PDF / JPG / PNG / TXT / DOC, etc.) plus a clearly wrapped filename (no tiny inline text-sized icons).
- **PDF page panel:** multi-page PDFs show a page list with thumbnails, Prev/Next, and the first page selected by default.
- **Default zoom:** preview fits the page to the pane (height, capped by width) so no scrollbar appears until the user zooms in. Zoom − / + / Fit on the toolbar.
- Paperclip indicator, download, images, unsupported-type info, filters, sort, and infinite scroll are unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.918**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.917 | `updates/20260814_0918_ledger_portfolio_viewer_refine.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.917 patches, then this process patch |

---

## v0.917

**Ledger attachment paperclip + portfolio viewer** — 2026-08-14

> No schema update required for this release (ledger UI/API only).  
> **Patch file (history row):** `updates/20260814_0917_ledger_attachment_portfolio.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.917

- **Ledger list:** rows that have attachments show a **paperclip** icon (count badge when more than one). Individual filenames are not listed in the table.
- **Portfolio viewer:** clicking the paperclip opens a modal with a left sidebar of all attached documents and a main display pane.
  - **PDF:** first page is rendered in the pane with Prev/Next page navigation.
  - **Images** (jpg, png, etc.): displayed directly.
  - **Other types:** file information plus a clear Download button.
  - Toolbar **Download** always applies to the currently selected document.
- Indicator and viewer work with **infinite scroll**, column **filters**, and **sorting** (attachment count is included on each list page).
- Existing transaction form document list / single-file preview, upload, and delete behavior is unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.917**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.916 | `updates/20260814_0917_ledger_attachment_portfolio.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.916 patches, then this process patch |

---

## v0.916

**Ledger attachment upload size follows PHP (20 MB)** — 2026-08-14

> No schema update required for this release (upload validation only).  
> **Patch file (history row):** `updates/20260814_0916_attachment_upload_size.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.916

- Removed the hard-coded **2 MB** application cap on transaction attachments (`ledgerMaxDocumentBytes()`).
- Allowed size is now the **effective PHP upload ceiling**: the smaller of `upload_max_filesize` and `post_max_size` (fallback 20 MB if PHP reports unlimited/unreadable).
- Rejection message reports the **actual** limit (no longer always “2 MB”).
- Files rejected by PHP (`UPLOAD_ERR_INI_SIZE` / `FORM_SIZE`) now show that size message instead of “Please select a file to upload” (empty `tmp_name` is checked after the size error codes).
- Directory PHP settings (`.htaccess` / `.user.ini`, and `deploy/temper.conf`) set `upload_max_filesize=20M` and `post_max_size=32M` so 10–20 MB files are not cut off by the previous 8 MB `post_max_size`.
- File-type, MIME, empty-file, and other attachment validation is unchanged. Existing attached documents are unaffected.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.916**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.915 | `updates/20260814_0916_attachment_upload_size.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.915 patches, then this process patch |

---

## v0.915

**Toast stacking above modals + ledger document upload file detection** — 2026-08-12

> No schema update required for this release (UI/API only).  
> **Patch file (history row):** `updates/20260812_0915_toast_zindex_and_doc_upload.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.915

- **Toasts site-wide:** `#appToastContainer` is reparented to `document.body` and given z-index **10900** so success/error/warning toasts appear **above any open Bootstrap modal** (and below the idle session-timeout layer at 20050). Users can read messages without closing the modal first.
- **Ledger document upload:** Upload now resolves the live file input at click time, builds multipart `FormData` with field `tx_document` (explicit filename), and no longer fails with “Please select a file to upload” when a file is already chosen. Server accepts `tx_document` (preferred) or legacy `document`.
- Multi-select filters, dirty-form protection, validations, and other upload/attachment rules are unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.915**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.914 | `updates/20260812_0915_toast_zindex_and_doc_upload.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.914 patches, then this process patch |

---

## v0.914

**Ledger auto-filter dropdown layout & display cleanup** — 2026-08-11

> No schema update required for this release (ledger UI/API only).  
> **Patch file (history row):** `updates/20260811_0914_ledger_filter_dropdown_layout.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.914

- Fixed Excel-style filter dropdown **checkbox visibility**: list rows no longer use Bootstrap `form-check` float/negative-margin (which clipped checkboxes inside the scroll area). Checkboxes stay fully visible and left-aligned.
- Filter list items are **single-line** (`white-space: nowrap`); long values do not wrap.
- Each filter dropdown is **resizable** (CSS `resize` + visible drag cue) so users can widen/tall-en the panel.
- Value list supports **horizontal and vertical scrolling** when content exceeds the current panel size.
- **Account** filter list shows **account name only** (CoA number removed from labels).
- **Fund** filter list shows **fund name only** (fund code removed from labels).
- Other columns keep meaningful display values only (status title-case labels, currency-formatted amounts, date tree day numbers, blanks as `(Blanks)`).
- Multi-select, (Select All), live search, server-side filtering, active-filter indicators, and Clear all filters are unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.914**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.913 | `updates/20260811_0914_ledger_filter_dropdown_layout.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.913 patches, then this process patch |

---

## v0.913

**Ledger Excel-style multi-select auto-filters** — 2026-08-09

> No schema update required for this release (ledger UI/API only).  
> **Patch file (history row):** `updates/20260809_0913_ledger_excel_multiselect_filters.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.913

- Replaced simple column filter controls (contains text, From/To dates, single-select Account/Fund/Status) with **true Excel-style multi-select auto-filters** on Date, Ref #, Pay To, Description, Account, Fund, Amount, and Status.
  - Search box live-filters the unique-value list.
  - **(Select All)** checkbox plus per-value checkboxes (OR match when applied).
  - Date column uses a hierarchical **year → month → day** tree (with search and Select All), not a From/To range picker.
  - Unique values loaded server-side via `?filter_values=1&column=…` (respects other active filters; own column excluded).
- **Clear all filters** toolbar button (separate from transaction **Clear** status action) clears every active column filter in one click; per-column **Clear** remains inside each dropdown.
- Active filter columns keep the warning underline / filled funnel indicator.
- Filters remain **server-side** against the full dataset; infinite scroll / lazy loading continues to honor active multi-select filters.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.913**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.912 | `updates/20260809_0913_ledger_excel_multiselect_filters.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.912 patches, then this process patch |

---

## v0.912

**Ledger redesign: modal form, infinite scroll, Excel-style filters** — 2026-08-09

> No schema update required for this release (ledger UI/API only).  
> **Patch file (history row):** `updates/20260809_0912_ledger_modal_infinite_scroll.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.912

- **Transaction Add / Edit / View** open in a Bootstrap modal dialog (same form, validations, funds/accounts, attachments, reference-number behavior).
  - Add and Edit: full editing (budget-only for cleared/reconciled, unchanged rules).
  - View: read-only modal.
  - Dirty-form protection on Add/Edit (click-away, Cancel, Escape) via existing `TemperDirtyForms` / `hide.bs.modal` handling. View mode does not require it.
- **Transaction list**
  - Classic Prev/Next pagination removed.
  - Continuous scrolling list with incremental JSON loading (`?list_transactions=1`) when many rows exist.
  - Filters always applied **server-side** against the full dataset (lazy-loaded pages never hide matching rows).
- **Excel-style auto-filter headers** on Date, Reference, Pay To, Description, Account, Fund, Amount, Status (funnel control + sort on each title).
  - Active columns highlighted; toolbar **Clear all filters** button.
- **Row interaction**
  - Double-click → read-only View modal.
  - Toolbar **View** → read-only View modal.
  - Toolbar **Edit** only → editable Edit modal (not double-click).
  - Single-click still selects (checkbox / Ctrl / Shift multi-select); bulk Clear/Reconcile preserved.
- Sticky table header (titles + filter controls stay visible while scrolling).
- Lightweight “Loading more…” indicator for incremental fetch; default sort newest first (header sort still available).
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.912**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.911 | `updates/20260809_0912_ledger_modal_infinite_scroll.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.911 patches, then this process patch |

---

## v0.911

**setup_db.php --check: clear pending schema-update messaging** — 2026-08-09

> No schema update required for this release (check messaging only).  
> **Patch file (history row):** `updates/20260809_0911_check_pending_schema_updates.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.911

- **`php setup_db.php --check` pending updates section:**
  - Keeps existing structure summary and setup baseline comparison (DB vs frozen 0.900).
  - When the database is **behind** the latest available `updates/*.sql` release(s), prints a **prominent WARNING: SCHEMA UPDATES ARE REQUIRED** block listing pending patch files and next steps (backup → apply patches → re-run `--check`).
  - When fully current, explicitly states **No schema updates are pending**.
  - Overall summary line reflects pending vs current status.
- Detection still uses existing lag helpers (`getDatabaseVersionLagStatus` / patch header scan); no change to structure validation or baseline assessment logic.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.911**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.910 | `updates/20260809_0911_check_pending_schema_updates.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.910 patches, then this process patch |

---

## v0.910

**Login timeout warning reliability + Developer Mode disables app timer** — 2026-08-07

> No schema update required for this release (auth/config/UI only).  
> **Patch file (history row):** `updates/20260807_0910_login_timeout_warning_and_devmode.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.910

- **Warning modal reliability (highest priority):**
  - Idle-timeout warning always appears when **60 seconds** remain on the application timer.
  - Modal stacks **above every other UI layer** (open Bootstrap modals, forms, backdrops) via dedicated high z-index and backdrop marking; it is interactable (“Stay logged in”) without closing or destroying open forms or unsaved data.
  - SPA fragment cleanup never removes the shell timeout modal or its backdrop while open.
  - On full expiry, redirect to login remains authoritative (`logout.php?expired=1`).
- **Developer Mode behavior (authoritative):**
  - **Off** → application idle timeout **10 minutes** (client timer + server idle check).
  - **On** → application idle timeout **fully disabled** (no warning modal, no client redirect, server `isSessionWithinIdleLimit` short-circuits). Host/system session cleaner **≈24 minutes** still applies.
- **System Configuration Status panel:** when Developer Mode is On, shows a clear warning that the application timer is disabled and the host session timeout is in effect; Login timeout badge reflects disabled state.
- Removed the prior “always-on 5m/20m by Developer Mode” model; single control plane remains Developer Mode + fixed 10-minute window.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.910**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.909 | `updates/20260807_0910_login_timeout_warning_and_devmode.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.909 patches, then this process patch |

---

## v0.909

**Lookup compact toolbar, table font size, hotkeys** — 2026-08-03

> No schema update required for this release (lookup UI only).  
> **Patch file (history row):** `updates/20260803_0909_lookup_toolbar_hotkeys.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.909

- **Compact layout** on all Lookup pages (Funds, Accounts, Natural Classes, Functional Classes): page title, filter, font controls, hotkey help, and action buttons share **one toolbar row** above the table (less vertical chrome).
- **Table-only font size**: **A−** / **A+** adjust `--temper-lookup-font-size` on the data table only (preference stored in `localStorage`).
- **Leader-key hotkeys**: press **`;`** (when not typing and no modal open), then a command within ~2.5s:
  - `f` / `/` filter · `a` Add · `e` Edit · `d` Delete · `r` Archive · `s` Show/Hide archived · `+`/`-` font · `?`/`h` help · `Esc` cancel
- Discoverability: keyboard icon opens a popover list; a temporary banner appears while hotkey mode is active.
- Shared helper `TemperLookupPage.init()` (wraps filter/sort); live filter, column sort, modals, and row actions preserved.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.909**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.908 | `updates/20260803_0909_lookup_toolbar_hotkeys.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.908 patches, then this process patch |

---

## v0.908

**Lookup table live filter + column sort** — 2026-08-03

> No schema update required for this release (lookup UI only).  
> **Patch file (history row):** `updates/20260803_0908_lookup_table_filter_sort.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.908

- Added a **live search box** above every Lookup maintenance table (filters as you type; no search button):
  - **Funds**, **Accounts**, **Natural Classes**, **Functional Classes**
- Filter matches **all visible columns** (case-insensitive substring).
- **Clickable column headers** sort the table:
  - First click → ascending; second click on the same column → descending
  - Visual indicator: caret up/down on the active column (`aria-sort` for accessibility)
- Filter and sort work together: filter controls row visibility; sort reorders data rows in the DOM (selection / edit / archive actions preserved).
- Shared client helper: `TemperLookupTable.enhance()` in `includes/footer.php`; light styles in `includes/header.php`.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.908**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.907 | `updates/20260803_0908_lookup_table_filter_sort.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.907 patches, then this process patch |

---

## v0.907

**Login timeout fixed by Developer Mode (5m / 20m)** — 2026-08-02

> No schema update required for this release (config / auth behavior only).  
> **Patch file (history row):** `updates/20260802_0907_login_timeout_from_developer_mode.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.907

- Removed user-facing Login Timeout controls (disable switch and free-form seconds) from System Configuration.
- Idle timeout is always on and derived from **Developer Mode**:
  - **Off** → **5 minutes** (`300` s)
  - **On** → **20 minutes** (`1200` s)
- Both values stay under the host ~24-minute PHP session file cleaner; app `session.gc_maxlifetime` is capped below 1440 s.
- Status panel regrouped: **Developer** block lists Developer Mode, Hard delete users, Login timeout (read-only “5 minutes” / “20 minutes”), and Environment; other items remain under **Other**.
- Saving Developer Mode updates the effective timeout for the app and the Status display (and reschedules the shell idle timer in the same tab). Warning modal before logout is preserved.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.907**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.906 | `updates/20260802_0907_login_timeout_from_developer_mode.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.906 patches, then this process patch |

---

## v0.906

**Fixed / floating desktop sidebar** — 2026-08-01

> No schema update required for this release (shell CSS only).  
> **Patch file (history row):** `updates/20260801_0906_sidebar_fixed_float.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.906

- Desktop navigation sidebar (`#appSidebar`, md+) is **`position: fixed`** so it stays on screen while main content scrolls.
- **`#temperSidebarCol`** remains an in-flow width spacer (expanded / collapsed rail widths) so content is never hidden under the panel.
- **Collapsed** rail and **hover-expand** peek still work; hover widen stays fixed (overlays content without reflowing the spacer).
- **Mobile** offcanvas + bottom nav behavior unchanged.
- Collapse toggle, localStorage preference, and hover delay config unchanged.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.906**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.905 | `updates/20260801_0906_sidebar_fixed_float.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.905 patches, then this process patch |

---

## v0.905

**Lookup Add/Edit forms as modal dialogs** — 2026-08-01

> No schema update required for this release (lookup UI only).  
> **Patch file (history row):** `updates/20260801_0905_lookup_add_edit_modals.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.905

- Converted Add/Edit on all Lookup maintenance pages from **inline** page sections to **Bootstrap modal dialogs**:
  - **Funds** (`setup_funds.php`)
  - **Accounts** (`setup_accounts.php` — `modal-lg` + scrollable for field density)
  - **Natural Classes** (`setup_naturalclasses.php`)
  - **Functional Classes** (`setup_functionalclasses.php`)
- **Dirty-form protection** (shared `TemperDirtyForms` + `hide.bs.modal`): if the form has unsaved changes, Confirm before discard on backdrop click, Escape, Cancel/close, SPA navigation, or browser leave.
- After a **successful save**, existing `submitFormAndReload` marks the form clean and reloads the list fragment (modal closes with content refresh). Validation errors keep the modal open with form state.
- Preserved server-side validation, archive/delete actions, permissions (`admin.lookups`), account-type / normal-balance caution, and field behavior.
- Modals reparented via `mountModalOnBody` / `showFragmentModal` for correct SPA stacking and autofocus.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.905**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.904 | `updates/20260801_0905_lookup_add_edit_modals.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.904 patches, then this process patch |

---

## v0.904

**Lookup Archive/Unarchive toggle fix** — 2026-08-01

> No schema update required for this release (lookup UI/behavior only).  
> **Patch file (history row):** `updates/20260801_0904_lookup_archive_toggle_fix.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.904

- Fixed Archive/Unarchive on all Lookup maintenance pages: **Funds**, **Accounts**, **Natural Classes**, **Functional Classes**.
- **Root cause:** HTML checkboxes without `value="1"` submit as `"on"`; server used `(int)$_POST['archived']`, and `(int)"on"` is `0` in PHP, so archive never set `archived=1`.
- **Funds** was also missing the Archive button click handler entirely.
- Client now posts explicit `archived=0|1` FormData for the toggle; server uses shared `temperParsePostArchived()` (accepts `1`/`on`/`true`).
- After a successful toggle the list reloads (row drops when archiving with Show Archived off; Archived column / button label update when shown). Button label becomes **Archive** or **Unarchive** from the selected row. Confirmation dialogs preserved.
- Also sets/clears `archived_at` on toggle. Checkboxes for Archived (and Mutable Fund on accounts) use `value="1"`.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.904**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.903 | `updates/20260801_0904_lookup_archive_toggle_fix.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.903 patches, then this process patch |

---

## v0.903

**Login Timeout disabled state is fully authoritative** — 2026-08-01

> No schema update required for this release (auth / session behavior only).  
> **Patch file (history row):** `updates/20260801_0903_login_timeout_disabled_authoritative.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.903

- When **Disable Login Timeout** is on in System Configuration:
  - Server never idle-expires the session (`isSessionWithinIdleLimit` short-circuits).
  - Client never shows the timeout warning modal and never redirects for idle time.
  - PHP `session.gc_maxlifetime` is extended so residual session GC cannot force logout while the app idle check is off.
- When timeout is **enabled**, existing behavior remains: warning modal near the end of the idle window, then redirect after the configured duration; GC lifetime tracks the configured seconds (plus headroom).
- Single control plane: System Config only. Shell idle timer in `includes/header.php` is the only client enforcer; Configuration save re-arms or fully disarms without a full reload.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.903**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.902 | `updates/20260801_0903_login_timeout_disabled_authoritative.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 → 0.902 process/schema patches, then this process patch |

---

## v0.902

**Modal form autofocus (first field; resist SPA focus steal)** — 2026-08-01

> No schema update required for this release (process / UI only).  
> **Patch file (history row):** `updates/20260801_0902_modal_form_autofocus.sql`  
> **Schema version:** `20260801_0901_account_type_classification` *(carried forward)*  
> **Min app version:** 0.902

- Shared `TemperModalFocus` in `includes/footer.php` (wired once for the SPA shell):
  - On `shown.bs.modal`, if the modal contains a form / data-entry fields, focus the first logical field (skips hidden, disabled, readonly text, footer/header chrome, close button).
  - Re-asserts focus over a short window and restores focus if SPA/AJAX/DOM activity moves it outside the open form modal.
  - Opt-out: `data-no-autofocus` on the modal or a field; prefer a specific field with `data-autofocus`.
- Covers Tasks (Add Task), Users/Roles, Budget activate/close, Ledger import text, backup unlock, and any other form-bearing Bootstrap modal without per-page layout changes.
- Tasks page no longer uses a one-shot `setTimeout` focus (global handler is the single source of truth).
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.902**. Setup baseline remains **0.900**.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.901 | `updates/20260801_0902_modal_form_autofocus.sql` (records version; no DDL) |
| Fresh setup (0.900) | Apply 0.901 schema patch, then this process patch |

---

## v0.901

**Chart of Accounts: required Account Type; Normal Balance guidance** — 2026-08-01

> ### **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
>
> **Patch file:** `updates/20260801_0901_account_type_classification.sql`  
> **Schema version:** `20260801_0901_account_type_classification`  
> **Min app version:** 0.901

- **DDL:** `accounts.account_type` — `ENUM('asset','liability','equity','income','expense') NOT NULL`, plus index `idx_accounts_account_type`.
  - Classic accounting element classification (Asset / Liability / Equity / Income / Expense).
  - Distinct from optional Natural and Functional category FKs (unchanged).
- **Existing rows:** patch backfills a temporary type from `normal_balance` (`debit` → `asset`, `credit` → `liability`). **Operators must review and correct** any pre-existing Chart of Accounts.
- Clean 0.900 installs have an empty `accounts` table; new accounts require Account Type on create.
- **Accounts setup UI (`setup_accounts.php`):**
  - Required Account Type select on add/edit; column shown in the account list.
  - On add, Normal Balance auto-populates from Account Type (asset/expense → debit; liability/equity/income → credit) but remains fully editable.
  - Non-blocking caution when Normal Balance diverges from the usual value for the selected type; confirm dialog on save (does not hard-block).
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` = **0.901**; `TEMPER_EXPECTED_SCHEMA_VERSION` = `20260801_0901_account_type_classification`.
- Setup baseline remains frozen at **0.900** — apply this patch after setup (or after upgrading from 0.900).

**Upgrade path**

| From | Apply |
|------|--------|
| v0.900 | `updates/20260801_0901_account_type_classification.sql` |
| Fresh setup (0.900) | Same patch after `php setup_db.php` |
| Older | Reach 0.900 first, then this patch |

---

## v0.900

**Official beta start — clean setup baseline** — 2026-07-26

> ### **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
>
> **Patch file:** `updates/20260726_0900_beta_baseline.sql`  
> **Schema version:** `20260726_0811_tx_memo_to_description` *(carried forward — no DDL for installs already at 0.811)*  
> **Min app version:** 0.900

**Start of beta.** From this release onward: **bugfixes, clarifications, and enhancements only** (no further alpha feature churn as a phase).

- **Setup baseline consolidated at 0.900:** `setup_db.php` + `setup-database/*` fold all alpha patches through 0.811 into a single destructive-setup outcome.
  - Fresh setup seeds **full** `app_version` history **0.801 → 0.900**.
  - Schema shape matches post-0.811 (`transaction_details.description` created directly; no `memo` column on new installs).
- **No demo / seed operational data:**
  - Accounts table is empty (create real CoA via Accounts setup).
  - Budgets / budget lines are empty.
  - Ledger / transactions (details, lines, documents, events) are empty.
  - Lookup/reference data **remains**: roles, natural/functional categories, structural funds, default users.
- Codebase `APP_VERSION` / `TEMPER_DEFAULT_APP_VERSION` / `TEMPER_SETUP_BASELINE_APP_VERSION` = **0.900**.
- Existing installs already at 0.811: apply the process-only patch below (records 0.900; no table DDL). Optional: re-run full setup for a clean empty beta database (destructive — backup first).

**Upgrade path**

| From | Apply |
|------|--------|
| v0.811 | `updates/20260726_0900_beta_baseline.sql` only |
| Fresh setup (0.900) | No further patches required for 0.900 |
| v0.810 or older | Prior patches through 0.811, then `20260726_0900_…` — or full `setup_db.php` for a clean beta baseline |

---

## v0.811

**Transaction Description field; rename `memo` → `description`** — 2026-07-26

> ### **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
>
> **Patch file:** `updates/20260726_0811_tx_memo_to_description.sql`  
> **Schema version:** `20260726_0811_tx_memo_to_description`  
> **Min app version:** 0.811

- Removes the duplicate **Memo** input from the Add/Edit transaction form; single **Description** field remains.
- Stops concatenating Description + Memo with `" | "` on save / split on load.
- **DDL:** `transaction_details.memo` renamed to `transaction_details.description`.
- Legacy values that used `" | "` are normalized to a space-separated single description before the rename.
- All ledger/engine/report reads and writes use `description` (search, list, import text, reference usage, audit change text).
- **Note (historical):** When 0.811 shipped, the then-frozen 0.804 setup still created `memo`. From **v0.900** onward, setup creates `description` directly.

**Upgrade path**

| From | Apply |
|------|--------|
| v0.810 | `updates/20260726_0811_tx_memo_to_description.sql` only |
| Fresh setup (0.900+) | Already included; no 0.811 patch needed |
| Fresh setup (legacy 0.804) | Post-baseline patches through 0.810, then `20260726_0811_…` |

---

## v0.810

**Transaction lines: Natural/Functional from account (budget-style)** — 2026-07-26

> ### **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
>
> **Patch file:** `updates/20260726_0810_tx_account_category_labels.sql`  
> **Schema version:** `20260725_03_formalize_audit_log` *(carried forward — no DDL)*  
> **Min app version:** 0.810

- **Add/Edit Transaction** line Natural and Functional classes are **read-only labels** pulled from the selected account (same pattern as budget lines).
- Account options carry CoA / natural / functional metadata; changing the account updates the labels immediately.
- Users can no longer pick Natural/Functional independently on a transaction line.
- On save, the server **re-resolves** Natural/Functional from the account record (client values cannot override).
- No table DDL in this release (UI + save-path logic only).

**Upgrade path**

| From | Apply |
|------|--------|
| v0.809 | `updates/20260726_0810_tx_account_category_labels.sql` only |
| Fresh setup (0.804) | Post-baseline patches through 0.809, then `20260726_0810_…` |

---

## v0.809

**Account View default + CoA-ordered account dropdowns** — 2026-07-26

> ### **SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS**
>
> **Patch file:** `updates/20260726_0809_account_filter_coa_order.sql`  
> **Schema version:** `20260725_03_formalize_audit_log` *(carried forward — no DDL)*  
> **Min app version:** 0.809

- **Ledger Account View filter** defaults to **All Accounts** (no longer Bank Account / first debit account on bare page load).
- **Account dropdowns** ordered by `coa_number` ascending across the app:
  - Ledger transaction line account picker
  - Ledger Account View filter
  - Budget line account picker (`budgetFetchAccountLookups`)
  - Reports account filter
- **Accounts setup list** uses the same CoA ordering for consistency.
- Null or empty CoA numbers sort **last**, then by name, then id (stable).
- No table DDL in this release (UI / query ordering only).

**Upgrade path**

| From | Apply |
|------|--------|
| v0.808 | `updates/20260726_0809_account_filter_coa_order.sql` only |
| Fresh setup (0.804) | Post-baseline patches through 0.808, then `20260726_0809_…` |

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

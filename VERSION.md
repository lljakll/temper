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
| **`setup_db.php` + `setup-database/*`** | **Frozen at app v0.900 (beta).** Destructive setup always leaves the database at 0.900 with full history through 0.900 and schema shape through 0.811 (`transaction_details.description`). |
| **`TEMPER_VERSION_HISTORY`** | Seeds **through 0.900** (complete alpha history + beta baseline). Do **not** add post-0.900 rows here. |
| **`updates/*.sql`** | **Only** path for releases **after 0.900** (DDL and/or `app_version` history rows). Historical pre-0.900 patches remain for operators upgrading old databases. |

After a fresh setup at the current baseline, no further patches are required until the next post-0.900 release. Operators on older databases apply listed patches (or re-run full setup for a clean beta baseline).

### Schema version = patch filename stem

The **schema version** is the patch file’s basename **without** `.sql` when that release changes schema:

| Patch file | Schema version (`app_version.schema_version`) |
|------------|-----------------------------------------------|
| `updates/20260725_01_app_version_history.sql` | `20260725_01_app_version_history` |
| `updates/20260725_02_schema_version_as_filename.sql` | `20260725_02_schema_version_as_filename` |
| `updates/20260725_03_formalize_audit_log.sql` | `20260725_03_formalize_audit_log` |
| `updates/20260726_0811_tx_memo_to_description.sql` | `20260726_0811_tx_memo_to_description` |
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

After every **5–10** schema patches (or at a natural milestone such as beta), fold post-baseline DDL into `setup-database/*`, raise `TEMPER_SETUP_BASELINE_APP_VERSION`, seed full history through the new baseline, keep older patch files for historical upgrades, and document under the consolidating release.

### Fresh installs

`php setup_db.php` builds the **0.900 beta baseline** schema from `setup-database/*.php` (including `transaction_details.description`) and seeds `app_version` history **through 0.900** (complete alpha chain included).  
No demo accounts, budgets, or transactions are inserted — only lookup/reference data (roles, natural/functional categories, structural funds) and default users.  
Do **not** replay pre-0.900 patches after a current setup; they are already embodied in the setup scripts. Releases after 0.900 require `updates/*.sql` patches listed under those versions.

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

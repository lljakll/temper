# Temper Church Treasurer System — Codebase Analysis

**Hand-off document for continuing development**  
**Generated:** 2026-07-28  
**Source:** Live codebase at `/var/www/temper` (branch `master`, clean working tree)  
**Live DB check:** `php setup_db.php --check` → structure ready; app/schema at **0.900** (match baseline)

This synopsis is factual and based on current files. Older docs (`docs/codebaseWorkflow.md`, `docs/dev-roadmap.md`) are partially outdated relative to the code; where they conflict, **this document and `VERSION.md` take precedence**.

---

## 1. Project structure

```
/var/www/temper/                    # Git repo root = application root (no nested app/)
├── index.php                       # SPA shell (#main-content)
├── login.php / logout.php
├── auth.php                        # Sessions, idle timeout, requireLogin()
├── config.php                      # APP_VERSION, DB credentials, APP_ENV, getDbConnection()
├── setup_db.php                    # Destructive master DB setup + --check / --dry-run
├── VERSION.md                      # Operator changelog + schema patch pointers
│
├── includes/                       # Shared PHP (layout, engines, utils)
│   ├── header.php / nav.php / footer.php   # Shell UI + loadPage() SPA JS
│   ├── page_bootstrap.php          # Auth + RBAC for pages/*.php
│   ├── permissions.php             # RBAC catalog, roles, ACL
│   ├── ledger_engine.php           # Transactions, refs, attachments, events
│   ├── budget_utils.php            # Budget lookups, multi-active helpers
│   ├── backup_utils.php            # Data-only / full backups, restore, auto-backup
│   ├── audit.php                   # audit_log write + CSV export
│   ├── app_version.php             # Version history helpers (read-only at runtime)
│   ├── system_config.php           # storage/config/system.json settings
│   └── storage_paths.php           # Writable storage resolution
│
├── pages/                          # AJAX-loaded feature modules
│   ├── dashboard.php, ledger.php, reports.php, budget.php, tasks.php
│   ├── profile.php, force-password.php, session_ping.php
│   ├── admin.php, admin-users.php, admin-config.php
│   ├── admin-backup.php, admin-database.php
│   └── setup_*.php                 # Funds, accounts, natural/functional classes
│
├── setup-database/                 # Ordered schema+seed scripts (frozen baseline)
│   ├── 01-accounts … 09-audit-log.php
│   ├── setup_cli.php, schema_validator.php
│
├── updates/                        # Manual SQL patches (post-setup only)
│   ├── YYYYMMDD_….sql
│   ├── _header_template.sql, README.md
│
├── storage/                        # Writable runtime data (www-data)
│   ├── attachments/                # Transaction docs by Reference # folder
│   ├── backups/, exports/, logs/, config/, transaction_documents/
│
├── scripts/auto_backup.php         # CLI cron runner for data-only auto-backup
├── deploy/                         # Apache vhost templates (paths may be stale)
├── docs/                           # Roadmap, guide, user stories, this file
└── devFiles/                       # Legacy/dev artifacts (not production runtime)
```

**Stack:** PHP (no framework, no Composer) + MariaDB/MySQL (`temper_db`) + Apache; frontend Bootstrap 5.3 + jQuery 3.7 from CDN. Prepared statements throughout.

**DB credentials (config.php):** host `127.0.0.1`, database `temper_db`, user `temper_user`.

---

## 2. Current architecture

### High-level request flow

1. **Login** (`login.php` → `auth.php` `login()`): sets session (`user_id`, `user_name`, `username`, optional `must_change_password`), regenerates session id, updates `last_login`.
2. **Shell** (`index.php` → `header.php` + `nav.php` + empty `#main-content` + `footer.php`): persistent SPA chrome (sidebar, theme, idle-timeout modal, toast container).
3. **Page load:** client `loadPage('ledger')` (in `header.php`) `fetch`es `pages/<name>.php` and injects HTML via `applyMainContent()`.
4. **Fragment pages:** each `pages/*.php` starts with `page_bootstrap.php` (config, DB, `requireLogin()`, page-level RBAC), then handles its own GET (HTML/JSON APIs) and POST (actions).
5. **Auth failures on AJAX:** `401` + `X-Auth-Required: 1` (and JSON when appropriate); shell redirects to login.

### Entry points

| Entry | Role |
|-------|------|
| `login.php` / `logout.php` | Authentication |
| `index.php` | Authenticated SPA shell |
| `pages/*.php` | Feature UI + JSON/form endpoints (loaded via AJAX or direct) |
| `setup_db.php` | CLI: full destructive setup, `--check`, `--dry-run`, `--help` |
| `scripts/auto_backup.php` | CLI: scheduled data-only backup |

### Design principles (as implemented)

- **Ledger-first:** double-entry transactions are the core; no separate “workflow engine” in the running tree.
- **No live DDL:** runtime `ensure*` helpers only **check** schema and throw/log if outdated (`temperSchemaOutOfDate`). Schema ownership is setup + `updates/*.sql` only.
- **Fund balances computed dynamically** from `transaction_lines` joined to debit-normal accounts (not relied on `funds.current_balance` for reporting).
- **Natural/functional categories** live on **accounts**; budget lines and transaction lines resolve categories from the selected account (transaction UI shows them as read-only labels as of v0.810).

---

## 3. Core modules and status

### 3.1 Authentication / Users & Roles — **Complete (beta-ready)**

| Piece | Status |
|-------|--------|
| Session login/logout | Done |
| Idle timeout (system config + client modal + server check) | Done |
| Force-password gate (`must_change_password` → `force-password` page; nav hidden) | Done |
| Auto-archive of incomplete force-password accounts | Done (timer in system config) |
| Multi-role (`user_roles` + primary `users.role_id`) | Done |
| JSON permissions on roles; `*` = full admin | Done |
| Page-level RBAC (`temperPagePermissionMap` + `requirePagePermission`) | Done |
| Action-level gates (e.g. `page.ledger.write`, `page.budget.write`) | Done on major write paths |
| Admin Users & Roles UI (`admin-users.php`) | Done (~1.8k lines): create/edit, roles, custom perms, archive, hard-delete (dev only) |
| Profile self-service | Done |
| Seed roles | Administrator, Treasurer, Financial Secretary, Finance Manager, Archivist, Board Member, Member |

**Default seed users (setup):** admin, treasurer, finance, board, member — password hash for `"password"`.  
**Live DB snapshot (2026-07-28):** only `admin` present; operational tables empty (see §5).

### 3.2 Chart of Accounts / Funds / Categories — **Complete**

| Lookup | Page | Schema notes |
|--------|------|--------------|
| Accounts (CoA) | `setup_accounts.php` | `coa_number`, `normal_balance`, natural/functional FKs, archive, `mutable_fund` |
| Funds | `setup_funds.php` | `type` WODR/WDR, code, archive/active |
| Natural classes | `setup_naturalclasses.php` | Seeded on setup |
| Functional classes | `setup_functionalclasses.php` | Seeded on setup |

- Account dropdowns ordered by `coa_number` (v0.809).
- **Beta setup:** accounts table starts **empty** (no demo CoA); operators create real accounts.
- **Beta setup:** structural funds (GOF WODR + Missions/Benevolence/Building WDR) are seeded as reference data.
- Permission: `admin.lookups`.

### 3.3 Ledger / Transactions — **Complete core; polish ongoing**

**Engine:** `includes/ledger_engine.php` (~1.4k lines)  
**UI:** `pages/ledger.php` (~4k lines — largest module)

| Capability | Status |
|------------|--------|
| Double-entry multi-line CRUD | Done; debits must equal credits |
| Fund per line (WODR/WDR) | Done |
| Natural/Functional from account (read-only labels) | Done (v0.810) |
| Status: pending → cleared / reconciled | Done (bulk actions; not full bank recon) |
| Reference # `YY####` (suggest, validate, reuse advisory) | Done |
| Single **Description** field (`transaction_details.description`) | Done (v0.811; was memo/join) |
| Attachments (PDF/JPG/PNG/DOC/DOCX, max 2 MB) | Done; stored under `storage/attachments/{ref or id}/` |
| Transaction events audit trail | Done (`transaction_events`) |
| `created_by_user_id` / `validated_by_user_id` columns | Schema present; validation helpers exist |
| Link to budget period (`budget_id`) | Done |
| Read-only when cleared/reconciled (budget reassignment still allowed) | Done |
| Filters: date, search, account view + running balance; Account View defaults to All | Done (v0.809) |
| Level-3 style text paste import (populate form only) | Done (client-side parse) |
| Full bank matching / statement reconciliation | **Not implemented** (status-only “reconcile”) |
| Release-from-restrictions first-class type | **Not implemented** (manual journal) |

### 3.4 Budgets — **Functional; some polish open**

**UI:** `pages/budget.php` · **Utils:** `includes/budget_utils.php`

| Capability | Status |
|------------|--------|
| Lifecycle: draft → approved → active → closed | Done |
| Approved: amounts locked; notes editable | Done |
| Activate approved budget (ref # + approved date required) | Done |
| **Multiple concurrent active budgets** allowed | Done (cycle no longer closes other actives; explicit close action) |
| Lines tied to accounts only (categories from account) | Done |
| CoA-ordered account picker | Done |
| Budget “Remaining” column in line UI | **Still placeholder (`—`)** |
| Budget copy action | **Not present** |
| Budget vs actual | In **Reports** (with variance, % used), not full polish on budget page |

### 3.5 Workflows — **Not present in current tree (deferred)**

- A workflow engine skeleton was added ~2026-07-17 (`includes/workflow/*`, `pages/workflows.php`, etc.).
- **Removed entirely** on 2026-07-19 (`c9eff13`) along with workflow setup script and admin pages.
- `docs/TODO.md` now states: Workflow system deferred to **v1.5+** as an **external module**, not embedded in Temper core.
- User stories (`docs/user_stories/userStory-contrib.md`, etc.) still describe intended workflow UX for later work.
- **No** Workflow sidebar entry, tables, or engine code remains.

### 3.6 Reporting / Dashboard — **Foundation complete**

**Dashboard** (`dashboard.php`): fund balances (WODR/WDR style aggregation), cash/bank totals, recent activity, upcoming tasks, permission-filtered quick links.

**Reports** (`reports.php`) JSON via `?run_report=`:

| Report key | Purpose |
|------------|---------|
| `fund-balances` | Fund balances as-of date |
| `transaction-listing` | Filtered transaction list |
| `budget-vs-actual` | Budget lines vs ledger actuals; **variance** and **% used** |
| `restricted-funds` | WDR beginning / inflows / outflows / ending |

Client-side export buttons exist (download/export helpers in page JS). **Board-ready PDF packages** are not a dedicated module.

### 3.7 Backup & Restore — **Robust / complete for beta**

| Feature | Location | Status |
|---------|----------|--------|
| Data-only SQL/CSV backups | `admin-backup.php` + `backup_utils.php` | Done; SHA-256 sidecars |
| Restore data-only | admin-backup | Done (with safeguards) |
| Full schema+data dump | `admin-database.php` | Done (Database Maintenance) |
| Maintenance clears (txns, budgets, etc.) | admin-database | Done; requires recent backup |
| Auto-backup schedule | system config + `scripts/auto_backup.php` | Done (hourly/daily/weekly) |
| Audit CSV export | audit helpers / database page | Done |

Administrator role required for Backup/Restore and Configuration (not only permission bit).

### 3.8 System Configuration — **Complete for current catalog**

`includes/system_config.php` + `pages/admin-config.php` + `storage/config/system.json`:

- Developer Mode (gates hard-delete with `APP_ENV`)
- Auto-archive timer / disable
- Login timeout / disable
- Sidebar hover expand/collapse delays
- Auto-backup enable, frequency, format

### 3.9 Versioning & Database Update model — **Complete (beta process)**

See §4–§5. Hybrid model:

| Source | Role |
|--------|------|
| `APP_VERSION` in `config.php` | Codebase constant (**0.900**) |
| `VERSION.md` | Human changelog + “SCHEMA UPDATE REQUIRED” pointers |
| `app_version` table | Append-only history (`version`, `schema_version`, `patch_file`, `notes`, `applied_at`) |
| `updates/*.sql` | Manual schema/process patches |
| Sidebar | Non-admins: App version only. Admins: **App + DB**; DB in red if lagging latest |

**No in-app schema apply UI** (by design).

### 3.10 Other modules

| Module | Status |
|--------|--------|
| Tasks / Reminders (`tasks.php`) | Functional CRUD with due-status UX |
| Archival Data Loader | **Not built**; Archivist role + `archive.import` permission reserved |
| Full Document system (global docs UI) | **Deferred**; ledger attachments exist |
| Full Audit system (dedicated UI) | **Deferred**; `audit_log` + admin action logging exist |
| GUID primary keys | **Not started** |

---

## 4. Database approach

### Setup baseline (frozen at **0.900 beta**)

- **`setup_db.php`** + **`setup-database/*`** create full schema and seed **through app version 0.900**.
- Schema shape embodies patches through **0.811** (`transaction_details.description`; no `memo` on fresh installs).
- **Seeds:** roles, natural/functional categories, structural funds, default users, empty accounts/budgets/transactions.
- **Destructive:** double case-sensitive confirmation (`yEaH` then `YeP`).
- **`--check`:** validates structure via `schema_validator.php` + baseline vs `app_version` (`assessSetupBaselineVsDatabase`). Read-only.

### Post-baseline patches

- Releases **after 0.900** go **only** through `updates/*.sql`.
- Do **not** append post-0.900 rows to `TEMPER_VERSION_HISTORY` in `app_version.php` until a future consolidation.
- Patch naming (0.808+): `YYYYMMDD_<appversion_without_decimal>_short_description.sql`.
- Schema version id = patch filename **stem** (no `.sql`); process-only releases carry forward prior schema stem.
- Every patch must `INSERT` into `app_version` (see `updates/_header_template.sql`).

### No live DDL

Since v0.804, runtime code must not `CREATE`/`ALTER`/seed on page load. Missing schema → clear error directing operators to `updates/*.sql` or full setup.

### Core tables (current)

`accounts`, `natural_categories`, `functional_categories`, `funds`,  
`transaction_details`, `transaction_lines`, `transaction_documents`, `transaction_events`,  
`budgets`, `budget_lines`,  
`users`, `roles`, `user_roles`,  
`tasks`, `audit_log`, `app_version`

---

## 5. Current version and beta status

| Item | Value |
|------|--------|
| **Application version** | **0.900** |
| **Phase** | **Official beta start** (2026-07-26) |
| **Setup baseline app** | 0.900 |
| **Setup baseline schema** | `20260726_0811_tx_memo_to_description` |
| **Expected schema** | Same (no post-0.900 DDL yet) |
| **Versioning scheme (roadmap)** | Historically 1.xa alpha / 1.xb beta; **code now uses 0.8xx alpha → 0.900 beta** |
| **Live DB (this host)** | At 0.900 / matching schema; **0 accounts, 0 budgets, 0 transactions**; categories/roles present; funds may be empty if cleared post-setup |

**Beta policy (from VERSION.md):** from 0.900 onward — bugfixes, clarifications, and enhancements only (no further alpha feature-churn phase). Large deferred systems (Workflow/Document/Audit UI) are explicitly out of core for now (`docs/TODO.md`).

**Alpha chain recorded in DB/history:** 0.801 → 0.811 → 0.900.

---

## 6. Notable recent changes and design decisions

### Versioning / ops (late July 2026)

1. **Manual patch model** (0.802+): no programmatic “fake” version bumps without applying SQL; history is append-only.
2. **Schema version = patch stem** (0.803).
3. **Read-only runtime schema checks** (0.804); audit_log formalized in setup.
4. **Frozen baseline** model (0.805 → raised to **0.900** at beta).
5. **Admin App/DB dual version + red lag indicator** (0.807–0.808).
6. **`setup_db.php --check` baseline awareness** (0.806).
7. **Beta baseline consolidation** (0.900): setup folds all alpha schema; no demo ledger/CoA/budget data.

### Product decisions

- **Ledger-first over embedded workflows:** workflow skeleton removed; workflows deferred to external module (v1.5+).
- **Categories on accounts:** budget and transaction UIs derive natural/functional from CoA (simplified schema; no categories on `budget_lines`).
- **Multiple active budgets** for year-end deconfliction (restriction removed).
- **Attachments on ledger** rather than a separate Documents product (for now).
- **Data-only vs full schema backups** split (Backup/Restore vs Database Maintenance).
- **Administrator-only** for backup and system configuration UIs.
- **Hard-delete users** only when Developer Mode + non-production env (or explicit `ALLOW_HARD_DELETE`).

### UX / polish shipped near beta

- Description-only transaction field (no dual memo).
- CoA-ordered dropdowns; Account View default “All Accounts”.
- Text import into transaction form.
- Mobile-aware shell, theme (light/dark/auto), session timeout UX, toast system.

---

## 7. Open issues / areas needing attention

### Product / feature gaps

1. **Budget UI “Remaining”** still shows `—` (actual variance lives mainly in Reports).
2. **Budget copy** not implemented (roadmap once claimed it; code has no copy action).
3. **Bank reconciliation / matching** — only status flags, not statement workflow.
4. **Archival Data Loader** — user story + Archivist role exist; no UI/import tool.
5. **GUID migration** — not started.
6. **Board-ready PDF / polished exports** — basic client export only.
7. **Release from restrictions** — not a first-class transaction type.
8. **Roadmap still lists Workflow/Document/Audit as alpha goals** — code/`TODO.md` defer them; **roadmap file is stale** (see §8).

### Documentation / deploy drift

1. **`docs/dev-roadmap.md`** (Rev 2.6, 2026-07-02) still says “1.0a Current” and prioritizes full Workflow system — superseded by 0.900 beta and workflow removal.
2. **`docs/codebaseWorkflow.md`** describes old `app/` layout, `treasurer_db`, unwired roles, missing attachments — largely obsolete.
3. **`deploy/treasurer.conf`** still points DocumentRoot at `/var/www/temper/app` (app was flattened to repo root).
4. Live environment may have **cleared funds** or non-default categories after setup — operators should re-seed structural funds or restore from backup if needed after maintenance clears.

### Process

1. After ~5–10 post-0.900 schema patches, **consolidate** into setup baseline again (documented rule in VERSION.md).
2. Fresh beta installs need **real CoA + budgets + transactions** entered (or archival tool when built); no demo data.

### Security / ops notes

- Default seed password `"password"` must be changed in production.
- `config.php` holds DB password in plain text (typical for this LAMP layout; protect filesystem).
- Upload size 2 MB for attachments; `upload_size_fix.sh` exists for php.ini.

---

## 8. Roadmap comparison (`docs/dev-roadmap.md` Rev 2.6)

Roadmap status line still claims **1.0a alpha** with next focus on Workflows/Documents/Audit. **Actual product state: v0.900 beta**, with those systems deferred.

### Completed (relative to roadmap “Completed” + later alpha polish)

| Roadmap item | Outcome |
|--------------|---------|
| Production deployment + storage/permissions | Done (root layout; storage resolution) |
| Robust backup system | Done (+ data/full split, auto-backup, checksums) |
| Double-entry ledger fund-aware CRUD | Done |
| Budget module lifecycle | Done (+ multi-active) |
| Basic dashboard, search/filter, reports foundation | Done |
| Transaction attachments | Done (beyond original “polish” list) |
| User tagging / events / created_by | Partially done (schema + events; rich approval UX limited) |
| Description field (memo polish) | Done as single Description |
| Audit table in dedicated setup script | Done (`09-audit-log.php`) |
| Users/roles enforcement | Done (was a gap in older docs) |
| Remove single-active-budget restriction | Done |
| Versioning / manual DB update model | Done (not on original roadmap; foundational for beta) |

### Deferred / removed from near-term core

| Roadmap item | Outcome |
|--------------|---------|
| Full Workflow System | **Removed from tree**; deferred v1.5+ external module (`docs/TODO.md`) |
| Full Document System (sidebar product) | Deferred; ledger attachments remain |
| Full Audit System (sidebar product) | Deferred; `audit_log` for admin actions remains |
| GUID migration | Still open / not started |
| Archival Data Loader | Still open (story + role only) |
| Member portal groundwork | Still open (1.0b roadmap) |

### Still open (beta polish / 1.0)

| Item | Notes |
|------|--------|
| Budget Remaining + stronger BvA presentation | Partial (reports have variance; budget page Remaining is stub) |
| Budget copy | Missing |
| Bank recon / matching workflows | Missing (formerly under Workflow umbrella) |
| Enhanced testing, hardening, successor docs | Explicit 1.0b goals |
| Roadmap document itself | Needs rewrite for 0.900 beta and deferred workflows |

### Suggested hand-off priorities (not prescriptions)

1. Keep **0.9xx beta discipline**: small enhancements/fixes via `VERSION.md` + patches when schema changes.
2. Real-data readiness: CoA setup, funds integrity after maintenance, seed password hygiene.
3. Budget Remaining / BvA UX if treasurers need it daily.
4. Archival loader if historical data load is next operational need.
5. Refresh **dev-roadmap.md** and retire stale claims in **codebaseWorkflow.md**.
6. Treat workflows as a **future external module**, not resume deleted skeleton without a new design pass.

---

## 9. Quick reference for a new conversation thread

```text
App version     : 0.900 (beta)
Code root       : /var/www/temper
DB              : temper_db @ 127.0.0.1 (temper_user)
Schema owner    : setup_db.php (baseline 0.900) + updates/*.sql
Validate        : php setup_db.php --check
Changelog       : VERSION.md
Largest modules : pages/ledger.php, includes/ledger_engine.php, pages/admin-users.php,
                  includes/backup_utils.php, includes/permissions.php, includes/app_version.php
SPA load        : index.php shell → loadPage() → pages/<name>.php
Auth            : auth.php + page_bootstrap.php + permissions.php
No workflows    : removed Jul 2026; deferred per docs/TODO.md
```

**Authoritative accounting doctrine:** `docs/TreasurersGuideCE.md` (Treasurer’s Guide Conceptual Edition).

---

*End of synopsis. Update this file when major architectural or version milestones land.*

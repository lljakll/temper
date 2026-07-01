Temper Church Treasurer System — Codebase & Workflow Summary

Hope Baptist Church, Nashville, GA | Based on actual files in /var/www/temper | Roadmap v2.1 (June 30, 2026)

───

1. Project Structure

/var/www/temper/                    # Git repo root
├── app/                            # Apache DocumentRoot (web app)
│   ├── config.php                  # DB credentials, app constants
│   ├── auth.php                    # Session login/logout
│   ├── login.php / logout.php
│   ├── index.php                   # SPA shell (sidebar + AJAX content area)
│   ├── includes/                   # Shared layout & utilities
│   │   ├── header.php, nav.php, footer.php
│   │   ├── storage_paths.php       # Writable storage resolution
│   │   ├── backup_utils.php        # Backup filename helpers
│   │   └── audit.php               # Audit log table + CSV export
│   ├── pages/                      # Feature modules (AJAX-loaded fragments)
│   ├── setup-database/             # Ordered schema + seed scripts (01–07)
│   ├── setup_db.php                # Master DB setup (drop + recreate all)
│   └── docs/                       # Treasurer's Guide, Roadmap, systemConfig
├── storage/                        # Production writable data (preferred)
│   ├── backups/   exports/   logs/
├── deploy/treasurer.conf           # Apache vhost template
├── migrate-to-var-www.sh           # Production migration script
└── verify-deployment.sh            # Post-deploy health checks

Stack: PHP + MariaDB (treasurer_db) on Apache, Bootstrap 5.3 + jQuery 3.7 frontend. No framework — plain PHP with prepared statements.

───

2. High-Level Architecture


            login.php          ┌─────────────────────┐
           ┌──────────────────▶│ auth.php / sessions │
┌─────────┐│                   └─────────────────────┘                                                              ╭──────────────────────╮
│ Browser ├┤                   ┌────────────────┐                         ┌────────────────────┐┌──────────────────▶│ MariaDB treasurer_db │
└─────────┘│index.php          │ Header + Nav + │      fetch pages/*.php  │ Dashboard, Ledger, ││                   ╰──────────────────────╯
           └──────────────────▶│ #main-content  ├────────────────────────▶│  Budget, Reports,  ├┤
                               └────────────────┘                         │      Admin...      ││                   ┌──────────────────────────┐
                                                                          └────────────────────┘└──────────────────▶│ /var/www/temper/storage/ │
                                                                                                                    └──────────────────────────┘

Request flow:
1. User authenticates via login.php → session (user_id, user_name).
2. index.php loads a persistent shell: dark sidebar (nav.php), empty #main-content.
3. loadPage('dashboard') in header.php fetches pages/<name>.php via fetch() and injects HTML.
4. Each page fragment handles its own GET (display/JSON APIs) and POST (form actions), then returns HTML partials.
5. footer.php provides shared JS: toasts, submitFormAndReload(), applyMainContent().

This is a lightweight single-page pattern — no router, no Composer, no ORM.

───

3. Database Model (Core Tables)

┌────────────────────────┬───────────────────────────────────────────────────────────────────────┐
│ Table                  │ Role                                                                  │
├────────────────────────┼───────────────────────────────────────────────────────────────────────┤
│ accounts               │ Chart of accounts; normal_balance = debit/credit                      │
├────────────────────────┼───────────────────────────────────────────────────────────────────────┤
│ natural_categories     │ Natural classification (Contributions, Salaries, Operating…)          │
├────────────────────────┼───────────────────────────────────────────────────────────────────────┤
│ functional_categories  │ Functional classification (Program, M&G, Fundraising…)                │
├────────────────────────┼───────────────────────────────────────────────────────────────────────┤
│ funds                  │ Fund accounting; type = WODR or WDR                                   │
├────────────────────────┼───────────────────────────────────────────────────────────────────────┤
│ transaction_details    │ Header: date, pay-to, ref#, memo, status (pending/cleared/reconciled) │
├────────────────────────┼───────────────────────────────────────────────────────────────────────┤
│ transaction_lines      │ Double-entry lines: account, fund, amount, debit/credit, categories   │
├────────────────────────┼───────────────────────────────────────────────────────────────────────┤
│ budgets / budget_lines │ Annual budgets with natural/functional/account lines                  │
├────────────────────────┼───────────────────────────────────────────────────────────────────────┤
│ users / roles          │ Auth (roles table exists; enforcement not yet wired)                  │
├────────────────────────┼───────────────────────────────────────────────────────────────────────┤
│ tasks                  │ Treasurer reminders (created on first page load)                      │
├────────────────────────┼───────────────────────────────────────────────────────────────────────┤
│ audit_log              │ Admin actions (created on demand via audit.php)                       │
└────────────────────────┴───────────────────────────────────────────────────────────────────────┘

Setup: app/setup_db.php drops and recreates all tables in dependency order, then runs setup-database/01 through 07 with realistic seed data (~34 transactions).

───

4. Main Modules & How They Interact

Dashboard (pages/dashboard.php)
• Computes WODR vs WDR totals from transaction_lines joined to debit-normal accounts.
• Shows per-fund balances, cash/bank total, and recent transactions.
• Links into Ledger for detail.

Ledger (pages/ledger.php) — Core accounting engine
• CRUD for transactions with multi-line double-entry form.
• Each line: account, optional fund, natural + functional category, amount.
• Server validates: ≥2 lines, debits = credits (±$0.005), account normal_balance drives debit/credit type.
• Status workflow: pending → cleared → reconciled (bulk actions; reconcile is status-only, not bank matching).
• Filtering: date range, text search (pay-to, ref#, memo, amount), account view with running balance.
• JSON endpoint ?get_transaction=<id> for edit modal.

Budget (pages/budget.php)
• Lifecycle: draft → approved → active → closed.
• Approved budgets lock amounts; notes remain editable.
• Cycle Budget: closes active budget, promotes an approved one (requires reference # + approved date from business meeting minutes).
• Budget lines tie to natural/functional categories and accounts.
• "Remaining" column in UI is placeholder (—); actual tracking lives in Reports.

Reports (pages/reports.php)
JSON API via ?run_report=<name>:
┌─────────────────────┬──────────────────────────────────────────────────────────┐
│ Report              │ Purpose                                                  │
├─────────────────────┼──────────────────────────────────────────────────────────┤
│ fund-balances       │ All funds with WODR/WDR subtotals                        │
├─────────────────────┼──────────────────────────────────────────────────────────┤
│ transaction-listing │ Filtered transaction detail                              │
├─────────────────────┼──────────────────────────────────────────────────────────┤
│ budget-vs-actual    │ Budget lines vs actuals from ledger                      │
├─────────────────────┼──────────────────────────────────────────────────────────┤
│ restricted-funds    │ WDR fund activity (beginning, inflows, outflows, ending) │
└─────────────────────┴──────────────────────────────────────────────────────────┘

Tasks (pages/tasks.php)
• Simple CRUD reminders with due-date status (upcoming, due_soon, overdue, in_progress, done).

Admin
┌─────────────────────────────┬──────────────────────────────────────────────────────────────────────────────────────────┐
│ Page                        │ Function                                                                                 │
├─────────────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────┤
│ admin.php                   │ Hub: recent backups, lookup links, placeholders for Users/Roles/Settings/Audit           │
├─────────────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────┤
│ admin-backup.php            │ Create, download, restore, delete backups; SHA-256 checksums; password-unlock for delete │
├─────────────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────┤
│ admin-database.php          │ Destructive maintenance (clear transactions, budgets, full financial reset)              │
├─────────────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────┤
│ setup_funds.php             │ WODR/WDR fund CRUD                                                                       │
├─────────────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────┤
│ setup_accounts.php          │ Chart of accounts CRUD                                                                   │
├─────────────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────┤
│ setup_naturalclasses.php    │ Natural category CRUD                                                                    │
├─────────────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────┤
│ setup_functionalclasses.php │ Functional category CRUD                                                                 │
└─────────────────────────────┴──────────────────────────────────────────────────────────────────────────────────────────┘

───

5. Key Data Flows

A. Transaction Entry (Double-Entry + Fund Accounting)

User fills form → POST action=save
  → Validate date, ≥2 lines, debits = credits
  → INSERT transaction_details (status=pending)
  → INSERT transaction_lines (per account normal_balance → debit/credit)
       each line: account_id, fund_id, natural_category_id, functional_category_id

Fund balance is computed dynamically — sum of debit-normal account lines per fund_id, not stored in funds.current_balance.

Treasurer's Guide alignment:
• WODR/WDR fund types on every restricted line.
• Natural + functional classification on each line.
• Inter-fund transfers supported (e.g., seed data moves cash between General Operating and Building funds).

Gap: No dedicated "Release from Restrictions" workflow (Guide §10). Restricted spending is recorded via normal expense lines against WDR funds; explicit WDR→WODR reclassification journal is manual, not a first-class transaction type.

B. Backup / Restore

create_backup → generateDatabaseBackup() (PHP SQL dump)
  → write to {storage}/backups/backup_YYYY-MM-DD_HHMMSS.sql
  → SHA-256 checksum file (.sha256)
  → integrity validation (reject HTML/PHP contamination)

restore → user confirms overwrite
  → archive current DB as restored_*.sql
  → sanitize + validate uploaded SQL
  → multi_query() execution

Storage resolution (includes/storage_paths.php):
1. TEMPER_STORAGE_PATH env var (Apache SetEnv)
2. ../storage (i.e., /var/www/temper/storage) — preferred production path
3. app/storage — dev fallback
4. /var/www/html/temper-data/storage, temp dir

Subdirs: backups/, exports/, logs/ (PHP errors → app.log).

C. Budget Handling

Draft: create/edit lines freely
  → POST action=save (status=draft|approved)

Approved: amounts locked, reference # + approved date required
  → notes editable via action=save_notes

Active: promoted via Cycle Budget
  → prior active → closed
  → approved → active (with start date)

Reporting: budget-vs-actual report joins budget_lines to transaction_lines
  by natural/functional/account within fiscal period

───

6. Treasurer's Guide Adherence

The app implements the Guide's conceptual model (app/docs/TreasurersGuideCE.md, Rev 1.0):

┌──────────────────────────────────┬─────────────────────────────────────────────────────────────┐
│ Guide Concept                    │ Implementation                                              │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│ WODR / WDR net asset classes     │ funds.type; dashboard + reports aggregate by type           │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│ Fund accounting / donor intent   │ fund_id on every transaction line                           │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│ Natural classification           │ natural_categories + line-level assignment                  │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│ Functional classification        │ functional_categories + line-level assignment               │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│ Double-entry                     │ transaction_lines.type debit/credit with balance validation │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│ Budget as stewardship discipline │ Full lifecycle with board approval metadata                 │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│ Restricted fund reporting        │ restricted-funds report                                     │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│ Release from restrictions        │ Not automated — manual journal entries only                 │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│ Board-ready reports              │ Partial — JSON reports exist; PDF/export polish pending     │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│ Internal controls / roles        │ Schema exists; UI enforcement not yet built                 │
└──────────────────────────────────┴─────────────────────────────────────────────────────────────┘

───

7. Production Setup

┌──────────────┬───────────────────────────────────────────────────────────────────────┐
│ Item         │ Value                                                                 │
├──────────────┼───────────────────────────────────────────────────────────────────────┤
│ App root     │ /var/www/temper/app (Apache DocumentRoot)                             │
├──────────────┼───────────────────────────────────────────────────────────────────────┤
│ Storage      │ /var/www/temper/storage (www-data owned, chmod 775)                   │
├──────────────┼───────────────────────────────────────────────────────────────────────┤
│ DB           │ treasurer_db @ 127.0.0.1, user treasurer_user                         │
├──────────────┼───────────────────────────────────────────────────────────────────────┤
│ Apache       │ deploy/treasurer.conf → treasurer_error.log / treasurer_access.log    │
├──────────────┼───────────────────────────────────────────────────────────────────────┤
│ Permissions  │ www-data:www-data on storage; jak:www-data on app with group write    │
├──────────────┼───────────────────────────────────────────────────────────────────────┤
│ Env override │ TEMPER_STORAGE_PATH in Apache vhost for non-default paths             │
├──────────────┼───────────────────────────────────────────────────────────────────────┤
│ Verification │ verify-deployment.sh checks paths, HTTP codes, storage diagnostics    │
├──────────────┼───────────────────────────────────────────────────────────────────────┤
│ Migration    │ migrate-to-var-www.sh moves repo from dev home dir to /var/www/temper │
└──────────────┴───────────────────────────────────────────────────────────────────────┘

Note: Both /var/www/temper/storage and app/storage/ may exist; production should use the repo-root storage/ via path resolution.

───

8. Roadmap v2.1 — Status & Gaps

Phase 1: Foundation ✅ Complete
Production deployment, backup/restore, storage paths, dashboard/navigation.

Phase 2: Core Accounting — Mostly Complete
┌──────────────────────────────────┬─────────────────────────────────────────────────────────────┐
│ Done                             │ Remaining                                                   │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│ Double-entry CRUD                │ Full bank reconciliation workflow                           │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│ Fund-aware entry                 │ Transaction form polish                                     │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│ Budget create/edit/approve/cycle │ Budget "copy" (roadmap claims done; no copy action in code) │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│ Basic ledger search/filter       │ Memo/attachment support                                     │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│                                  │ Enhanced budget-vs-actual reporting                         │
├──────────────────────────────────┼─────────────────────────────────────────────────────────────┤
│                                  │ Budget UI "Remaining" column (shows —)                      │
└──────────────────────────────────┴─────────────────────────────────────────────────────────────┘

Phase 3: Reporting & Polish — Next Focus
• Board-ready PDF reports
• Dashboard charts
• User roles & permissions (schema seeded; admin cards are placeholders)
• Lookup table management polish
• CSV/PDF export

Phase 4: Extensibility & Handover
• Member portal groundwork
• Successor documentation
• Performance tuning

Stated next action (Roadmap): Finish Phase 2 polish → begin Phase 3 reporting.

───

9. Developer Quick-Start

1. Clone/open workspace at /var/www/temper/app.
2. Configure config.php DB credentials; ensure MariaDB treasurer_db exists.
3. Initialize DB: php setup_db.php (destructive — drops all tables).
4. Serve: Apache pointing at /var/www/temper/app, or dev equivalent.
5. Login: default user from 06-users-roles.php seed (check that file for credentials).
6. Writable storage: confirm getStorageDiagnostics() reports writable /var/www/temper/storage.
7. Navigate: all features load via sidebar loadPage() calls — when adding a module, create pages/<name>.php and add a nav link.

Auth note: auth.php currently contains debug echo output in login() — should be removed before production hardening.

───

10. Module Interaction Diagram

╭ mermaid: flowchart ────────────────────────────────────────╮
│ flowchart TB                                               │
│     subgraph lookups [Admin Lookups]                       │
│         Funds[setup_funds]                                 │
│         Accts[setup_accounts]                              │
│         Nat[setup_naturalclasses]                          │
│         Func[setup_functionalclasses]                      │
│     end                                                    │
│                                                            │
│     Ledger[Ledger] -->|reads| Funds                        │
│     Ledger -->|reads| Accts                                │
│     Ledger -->|reads| Nat                                  │
│     Ledger -->|reads| Func                                 │
│     Ledger -->|writes| TX[(transaction_details + lines)]   │
│                                                            │
│     Budget[Budget] -->|reads| Nat                          │
│     Budget -->|reads| Func                                 │
│     Budget -->|reads| Accts                                │
│     Budget -->|writes| BGT[(budgets + budget_lines)]       │
│                                                            │
│     Dashboard[Dashboard] -->|aggregates| TX                │
│     Reports[Reports] -->|queries| TX                       │
│     Reports -->|queries| BGT                               │
│     Reports -->|queries| Funds                             │
│                                                            │
│     Backup[admin-backup] -->|dumps/restores| DB[(MariaDB)] │
│     Backup -->|writes| Storage[storage/backups]            │
╰────────────────────────────────────────────────────────────╯

───

This summary reflects the codebase as of June 30, 2026. The system has a solid LAMP foundation with working ledger, fund accounting, budgeting lifecycle, and a mature backup system. The immediate development trajectory is Phase 2 polish (reconciliation, form UX, budget tracking) followed by Phase 3 board-ready reporting and role-based access control.
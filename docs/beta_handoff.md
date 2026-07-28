# Temper Church Treasurer System — Beta Handoff (v0.900)

**Date:** 2026-07-28  
**Current Version:** **0.900 (Official Beta)**  
**Code root:** `/var/www/temper`  
**Database:** `temper_db` @ `127.0.0.1`  
**Authoritative accounting rules:** `docs/TreasurersGuideCE.md`

---

## Current State Summary

Temper is a simple PHP + MariaDB LAMP application (Bootstrap 5 + jQuery) with no framework. It is a ledger-first church treasurer system for Hope Baptist Church.

**Core completed modules (beta-ready):**
- Authentication, multi-role RBAC, force-password, idle timeout
- Chart of Accounts, Funds, Natural/Functional categories
- Full double-entry ledger (multi-line, attachments, Reference # YY####, Description field, budget link)
- Budget lifecycle (draft → approved → active → closed) with multiple concurrent active budgets
- Dashboard + basic reports (fund balances, transaction listing, budget-vs-actual, restricted funds)
- Robust backup/restore (data-only + full, auto-backup, checksums)
- System Configuration
- Hybrid versioning system (`VERSION.md` + `app_version` table)

**Explicitly deferred / removed:**
- Full Workflow system (removed from tree; planned as external module for v1.5+)
- Full Document system (ledger attachments remain)
- Full Audit UI (audit_log table exists)
- Archival Data Loader (role/permission reserved only)
- GUID primary keys
- Board-ready PDF packages

---

## Versioning & Database Update Model (Critical)

- **`setup_db.php`** is the frozen **0.900 baseline**. A destructive run produces a clean 0.900 database (roles, categories, structural funds, empty accounts/budgets/transactions).
- All future changes go through **manual** `updates/*.sql` patches only.
- **No live DDL** — runtime code only *checks* schema and errors if outdated.
- Patch naming (current): `YYYYMMDD_<appversion_without_decimal>_description.sql`
- Every patch must insert a row into `app_version`.
- Sidebar (Admin only): shows `App: vX.XXX  DB: vX.XXX` — DB version turns red with tooltip when behind.
- `php setup_db.php --check` now reports baseline vs current DB version.

**Operator path after a pull:**
1. Check `VERSION.md` for “SCHEMA UPDATE REQUIRED”
2. Apply any listed `.sql` files manually
3. Confirm with `php setup_db.php --check`

---

## Beta Policy (from 0.900 onward)

- **Bugfixes, clarifications, and enhancements only**
- No major new feature development
- Large systems (Workflows, full Documents, full Audit UI) stay deferred
- Real (non-critical) data will be entered for testing; demo/seed transactional data has been removed

---

## Known Open Items / Polish Opportunities

| Item | Notes |
|------|-------|
| Budget “Remaining” column | Still shows placeholder `—` (variance lives in Reports) |
| Budget copy | Not implemented |
| Bank reconciliation / matching | Only status flags exist |
| Archival Data Loader | Role exists; no UI yet |
| Roadmap document (`docs/dev-roadmap.md`) | Stale — still talks about 1.0a and full Workflows |
| Deploy config | Some paths may still reference old `app/` layout |

---

## Suggested Immediate Priorities in New Thread

1. Confirm clean 0.900 state on primary machine (`php setup_db.php --check` + version history)
2. Begin real CoA / fund / budget setup with non-critical data
3. Fix any bugs found during real-data entry
4. Optionally refresh `docs/dev-roadmap.md` to reflect 0.900 beta reality
5. Decide whether Budget Remaining / copy are early beta enhancements

---

## Quick Reference

```text
App version     : 0.900 (beta)
Validate        : php setup_db.php --check
Changelog       : VERSION.md
Largest files   : pages/ledger.php, includes/ledger_engine.php, pages/admin-users.php
No workflows    : removed; deferred to v1.5+ external module
```

**Full detailed analysis:** `docs/codebase_analysis.md` (generated 2026-07-28)

---

*End of Beta Handoff summary.*

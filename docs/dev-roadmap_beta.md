# Temper – Church Treasurer System Development Roadmap

**Hope Baptist Church, Nashville, GA**  
**Revision: 3.0**  
**Date: July 28, 2026**  
**Current Version: 0.900 (Official Beta)**

**Single Source of Truth:**  
The **Treasurer’s Guide** (`docs/TreasurersGuideCE.md`) remains the authoritative source for all accounting principles, fund accounting rules, donor stewardship, and audit standards.

**Current Architecture:** Simple PHP + MariaDB LAMP stack (Bootstrap 5 / jQuery) at `/var/www/temper`

**Philosophy:** Frequent small, visible wins + production-ready stability + easy handover to successor.

---

## Versioning Scheme (Current)

| Stage              | Version Pattern     | Meaning                                      |
|--------------------|---------------------|----------------------------------------------|
| Alpha (complete)   | 0.801 → 0.811       | Feature discovery and major architecture     |
| **Beta (current)** | **0.900 → 0.9xx**   | Bugfixes, clarifications, enhancements only  |
| Release            | 1.0                 | First production release                     |
| Post-1.0           | 1.x / 2.0           | Point releases and major feature modules     |

---

## Completed (as of 0.900 Beta)

### Foundation & Data Integrity
- [x] Production-style deployment layout
- [x] Robust backup / restore (data-only + full, checksums, auto-backup)
- [x] Storage path handling and permissions
- [x] Hybrid versioning system (`VERSION.md` + `app_version` table)
- [x] Manual schema update model (`updates/*.sql`)
- [x] No live DDL at runtime (read-only schema checks)

### Core Accounting Engine
- [x] Double-entry ledger with full transaction CRUD
- [x] Fund-aware transaction entry
- [x] Reference # system (YY####)
- [x] Attachments on transactions
- [x] Single Description field
- [x] Budget module (create, edit, activate, close, multi-active support)
- [x] Natural / Functional categories pulled from accounts
- [x] Chart of Accounts with optional CoA number

### Users, Roles & Security
- [x] Full RBAC (multi-role, custom permissions, archive)
- [x] Force password change + auto-archive
- [x] Idle timeout with warning modal
- [x] Administrator-only system configuration and backups

### Reporting & Dashboard
- [x] Basic dashboard (balances, recent activity, tasks)
- [x] Core reports (Fund Balances, Transaction Listing, Budget vs Actual, Restricted Funds)

---

## Beta Focus (0.900 – 0.9xx)

**Policy:** Bugfixes, clarifications, and enhancements only. No major new feature systems.

### High Priority Polish
- [ ] Budget page “Remaining” column (currently placeholder)
- [ ] Budget copy function
- [ ] Stronger Budget vs Actual presentation on budget page
- [ ] Any real-data bugs discovered during CoA / transaction entry

### Medium Priority
- [ ] Archival Data Loader (for historical budgets/transactions)
- [ ] Improved mobile table/form usability
- [ ] Roadmap and documentation cleanup (this file, codebaseWorkflow.md, deploy configs)

### Low Priority / Later
- [ ] Bank statement matching / full reconciliation workflow
- [ ] Board-ready PDF export packages
- [ ] GUID primary keys (if still desired)

---

## Deferred to Post-1.0 (or v1.5+)

These were explored in alpha and explicitly deferred:

- **Full Workflow System** (Contribution, Reimbursement, Invoice, etc.) — planned as an external module, not embedded in core
- **Full Document System** (sidebar product) — ledger attachments remain
- **Full Audit System UI** — `audit_log` table exists for admin actions
- Member portal groundwork
- Advanced release-from-restrictions transaction type

---

## Path to 1.0 Release

1. Complete high-priority beta polish items
2. Run extended real-data testing (non-critical data)
3. Final security / permission review
4. Successor / operator documentation
5. Performance and hardening pass
6. Tag **1.0**

---

## Current Status (July 28, 2026)

**Phase:** Official Beta (v0.900)  
**Database baseline:** 0.900 (clean install via `setup_db.php`)  
**Update method:** Manual patches in `updates/` only  
**Transactional seed data:** Removed — ready for real (non-critical) data entry

**Next Action:** Begin real Chart of Accounts and budget setup; fix bugs as they surface during actual use.

---

**Approval Note:** This roadmap reflects the actual state of the codebase at the start of beta and supersedes all previous alpha roadmaps (including Rev 2.6).

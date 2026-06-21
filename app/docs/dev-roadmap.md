# Temper – Church Treasurer System Development Roadmap
**Hope Baptist Church, Nashville, GA**  
**Revision: 2.0**  
**Date: June 21, 2026**

**Single Source of Truth:**  
The **Treasurer’s Guide** remains the authoritative source for all accounting principles, fund accounting rules, donor stewardship, and audit standards.

**Current Architecture:** Simple PHP + MariaDB LAMP stack (Bootstrap/jQuery) running at `/var/www/temper`

**Philosophy:** Frequent small, visible wins + production-ready stability + easy handover to successor.

---

### Core Priorities (Still in Effect)
- Accurate fund accounting (WODR/WDR, releases from restrictions, functional + natural classification)
- Strong budgeting module (budget entry, copy previous year, variance reporting)
- Reliable double-entry ledger with transaction management
- Professional, board-ready reports
- Backup / restore robustness (already significantly advanced)

---

## Phase 1: Foundation & Data Integrity (Completed)
**Status: Mostly Done**

- Production deployment to `/var/www/temper` with proper permissions and symlink workflow
- Robust backup system:
  - Filename-based timestamps (YYYY-MM-DD_HHMMSS)
  - SHA256 checksums with .sha256 companion files
  - SQL integrity validation (no HTML/warnings in dumps)
  - Recent backups card on admin overview
  - Restore validation improvements
- Storage path handling and error resilience
- Basic dashboard and navigation

**Small Wins Achieved:** Reliable backup/restore workflow, safe environment for real data entry.

---

## Phase 2: Core Accounting Engine (High Priority – Next Focus)
**Goal:** Enable safe, confident entry of real church data.

**Key Features:**
- Double-entry ledger with full transaction CRUD (create, read, update, delete)
- Fund-aware transaction entry (unrestricted/restricted, releases, natural/functional classification)
- Budget module (create, edit, copy from prior year, budget vs actual tracking)
- Basic reconciliation tools
- Transaction search/filtering and memo/attachment support

**Small Wins to Target:**
- [ ] Simple transaction entry form working with fund rules
- [ ] First budget vs actual report
- [ ] Ability to enter real offerings and expenses safely

**Milestone:** Stable core ledger + budgeting system ready for daily use and real data.

---

## Phase 3: Reporting & Polish (Following Phase 2)
- Professional board-ready reports (Fund Statements, Budget vs Actual, Functional Expense, etc.)
- Dashboard enhancements with key balances and charts
- User roles & permissions (Treasurer full access, board read-only)
- Lookup tables / chart of accounts management
- Export capabilities (PDF/CSV)

---

## Phase 4: Extensibility & Handover
- Member portal groundwork (read-only views)
- Documentation / successor guide
- Additional modules (if needed: payroll, membership integration, etc.)
- Performance tuning and final hardening

---

**Current Status (June 21, 2026):**  
Strong foundation and backup system in place. Ready to accelerate work on the ledger and budgeting modules now that safe data management is reliable.

**Next Action:** Begin detailed work on transaction/ledger entry and budgeting features.

---

**Approval Note:** This roadmap reflects actual development progress and restores budgeting + ledger as top priorities.

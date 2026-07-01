# Temper – Church Treasurer System Development Roadmap

**Hope Baptist Church, Nashville, GA**  
**Revision: 2.1**  
**Date: June 30, 2026**

**Single Source of Truth:**  
The **Treasurer’s Guide** remains the authoritative source...

**Current Architecture:** Simple PHP + MariaDB LAMP stack (Bootstrap/jQuery) running at `/var/www/temper`

**Philosophy:** Frequent small, visible wins + production-ready stability + easy handover to successor.

---

### Core Priorities (Still in Effect)
- Accurate fund accounting...
- Strong budgeting module...
- Reliable double-entry ledger...
- Professional, board-ready reports
- Backup / restore robustness (already significantly advanced)

---

## Phase 1: Foundation & Data Integrity (Completed)
**Status: Done**

- [x] Production deployment...
- [x] Robust backup system...
- [x] Storage path handling...
- [x] Basic dashboard and navigation

**Small Wins Achieved:** Reliable backup/restore workflow + safe environment for real data.

---

## Phase 2: Core Accounting Engine (Mostly Complete)
**Goal:** Solid ledger and budgeting foundation.

**Key Features:**
- [x] Double-entry ledger with full transaction CRUD
- [x] Fund-aware transaction entry
- [x] Budget module (create, edit, copy, tracking)
- [ ] Basic reconciliation tools
- [ ] Transaction search/filtering and memo/attachment support

**Remaining Small Wins:**
- [ ] Polish transaction entry form
- [ ] Enhanced budget vs actual reporting
- [ ] Full reconciliation workflow

**Milestone:** Core accounting engine stable for daily use.

---

## Phase 3: Reporting & Polish (Next Focus)
- [ ] Professional board-ready reports...
- [ ] Dashboard enhancements with charts
- [ ] User roles & permissions
- [ ] Lookup tables / chart of accounts management
- [ ] Export capabilities (PDF/CSV)

---

## Phase 4: Extensibility & Handover
- [ ] Member portal groundwork
- [ ] Documentation / successor guide
- [ ] Additional modules (if needed)
- [ ] Final performance tuning & hardening

---

**Current Status (June 30, 2026):**  
Strong foundation and core ledger/budgeting functionality in place. Focus is now shifting to polishing Phase 2 items and moving into reporting & user management.

**Next Action:** Complete remaining Phase 2 polish, then begin Phase 3 reporting features.
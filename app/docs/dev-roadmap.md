# Temper – Church Treasurer System Development Roadmap

**Hope Baptist Church, Nashville, GA**  
**Revision: 2.6**  
**Date: July 02, 2026**

**Single Source of Truth:**  
The **Treasurer’s Guide** remains the authoritative source for all accounting principles, fund accounting rules, donor stewardship, and audit standards.

**Current Architecture:** Simple PHP + MariaDB LAMP stack (Bootstrap/jQuery) running at `/var/www/temper`

**Philosophy:** Frequent small, visible wins + production-ready stability + easy handover to successor.

**Versioning Scheme:**
- **1.xa (Alpha)**: Major feature development and implementation. Get new systems (Workflows, Documents, Audit, etc.) functionally complete.
- **1.xb (Beta)**: Polish, refinement, testing, hardening, edge cases, and real-world validation.
- **1.0**: Initial Release. Future releases follow standard semantic versioning.

---

### Core Priorities (Still in Effect)
- Accurate fund accounting (WODR/WDR, releases, functional + natural classification)
- Strong budgeting module
- Reliable double-entry ledger
- Professional, board-ready reports + auditability
- Backup / restore robustness
- Workflow, Document, and Audit systems for controlled, auditable processes

---

## Version 1.0a – Alpha (Current)
**Status:** Core engine functional. Major features under active development.

**Completed:**
- Production deployment + storage/permissions
- Robust backup system
- Double-entry ledger with fund-aware CRUD
- Budget module (lifecycle, copy, basic tracking)
- Basic dashboard, search/filtering, reports foundation

**Alpha Goals (Major Feature Implementation):**

- **Transaction Entry Polish**
  - Add rich memo field support
  - Implement file attachments (receipts, PDFs, images) with storage handling
  - Add user tagging to transactions (who entered/approved)
  - Improve overall form UX and validation feedback

- **Enhanced Budget vs Actual Reporting**
  - Implement accurate “Remaining” calculations
  - Add variance highlighting and better visual presentation
  - Support year-to-date and period comparisons

- **Setup & Infrastructure Improvements**
  - Move Audit table creation to its own dedicated setup-database script
  - Implement first-run build system (auto DB setup, seeding, configuration checks on new installation)

- **GUID Migration (Phased)**
  - Convert primary keys to GUIDs for key tables (transactions, accounts, funds, etc.)
  - Maintain backward compatibility during transition

- **Full Workflow System**
  - Create dedicated Workflow sidebar entry
  - Build core workflow engine
  - Implement guided workflows for:
    - Reimbursements
    - Invoices
    - Payments
    - Contributions
    - Payroll
    - Bank Transaction Matching
    - Bank Statement Reconciliation
  - Workflows will guide users through required actions, require document uploads, gather approvals, and generate auditable paperwork packages with approval sheets

- **Full Document System**
  - Create dedicated Documents sidebar entry
  - Implement document storage, upload, and management
  - Support linking documents to transactions, workflows, and audits

- **Full Audit System**
  - Create dedicated Audit sidebar entry
  - Build audit listing interface
  - Implement detailed audit view (with linked documents and history)
  - Support viewing and managing document packages within audits

**Milestone:** All major feature sets functionally complete → ready for Beta polish.

---

## Version 1.0b – Beta
**Goal:** Refined, hardened, and ready for limited real data use.

**Key Focus Areas:**
- Polish and UX refinement across all modules (especially new Workflow/Document/Audit systems)
- Comprehensive testing of workflows and approval processes
- Performance tuning and security hardening
- Edge case handling and data integrity validation
- Detailed successor documentation + handover guide
- Member portal groundwork (read-only)

**Beta Success Criteria:**
- Stable, intuitive, and auditable system
- Workflows reliably guide users and produce complete audit packages
- Positive feedback from test users with minimal issues
- Strong confidence in production readiness

---

## Version 1.0 – Initial Release
**Goal:** Production-ready for full church use.

---

**Current Status (July 02, 2026):**  
At **1.0a**. Focus on completing major feature implementation (Workflows, Documents, Audit, etc.).

**Next Action:** Prioritize foundational Workflow + Document + Audit systems alongside transaction polish.

**Approval Note:** Reconciliation tools now properly nested under Workflow System per your feedback.
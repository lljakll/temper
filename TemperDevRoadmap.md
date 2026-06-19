# Church Treasurer System – Architecture & Development Roadmap
**Hope Baptist Church of Nashville, Georgia**  
**Revision: 1.7**  
**Date: 27 May 2026**

**Single Source of Truth:**  
The **Treasurer’s Guide Conceptual Edition Rev 1.0** is the authoritative source for all accounting principles, stewardship rules, fund accounting logic, and financial practices.

### Core Requirements & Constraints
- Primary user (Treasurer) needs full read/write capability.
- Other users need read-only access via roles.
- Data centralized on church Linux server.
- No public internet exposure.
- Strictly follow Treasurer’s Guide (WODR / WDR, releases from restrictions, functional + natural classification, donor stewardship, audit-readiness).
- Development using local LLM (Qwen3-Coder:30B primarily).

**Tech Stack:** Plain PHP + MariaDB + Bootstrap 5 + jQuery  
**Development Environment:** Linux, Apache2, OpenCode + Grok (Architect)

---

## 1. Architecture Overview

**Core Stack**
- Backend: PHP + MariaDB (mysqli)
- Frontend: Bootstrap 5 + jQuery
- Simple, auditable, maintainable code

---

## 2. Detailed Development Roadmap

### Phase 0: Project Setup & Foundations (3–5 days)
**Goal:** Get a working system quickly.

- Initialize folder structure and database
- Basic navigation (sidebar)
- Login / authentication stub
- Simple dashboard

**Small Wins:**
- [ ] Basic dashboard page loading
- [ ] Simple navigation working
- [ ] Database connection + test tables created

**Deliverables:** Functional skeleton with sidebar navigation.

### Phase 1: Core Accounting & Budgeting (2–3 weeks)
- Fund accounting schema (funds, transactions, releases, net assets)
- Budgeting system
- Transaction entry with proper fund rules and classification
- Basic Dashboard with fund balances

**Small Wins:**
- [ ] Unrestricted contribution entry
- [ ] Basic Budget vs Actual view

### Phase 2: Major Features & Polish (3–4 weeks)
- Full transaction CRUD
- Reports (Fund Statements, Functional Expense, Reconciliation)
- Advanced budgeting + variance warnings
- User roles & permissions

### Phase 3: Extensibility & Production Readiness (2–3 weeks)
- Security hardening
- Backup procedures
- Logging and audit trails
- UI refinements
- Future module groundwork

---

## 3. Code Quality & Maintainability Guidelines

**File Size Limits:**
- **Ideal**: ≤ 500 lines per file
- **Safe**: ≤ 700 lines
- **Hard Limit**: Do not exceed 900 lines

Keep prompts small and focused when working with Q3:30B to avoid hallucinations.

**Motivation Philosophy:** Frequent small, visible wins.

**Approval Status:** Revised v1.7 — Plain PHP + Bootstrap direction locked.

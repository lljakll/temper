# User Story: Archival Data Loader (Historical Import Tool)

**ID:** US-ARCHIVE-001  
**Version:** 1.0  
**Date:** July 05, 2026  
**Author:** Jak  
**Status:** Draft  

## Objective
Provide a secure, controlled, temporary tool for bulk-loading historical budgets and transactions (back to ~2015 or earlier) without forcing them through the new strict workflows, while maintaining accounting continuity and strong auditability.

## Roles
- **Archivist** — Dedicated role for historical data loading. Cannot be combined with Treasurer role on the same user account.
- **Admin** — Full access (including Archivist functions).
- **Treasurer** — No access to Archival Loader (must log out and switch to Archivist role to use it).

## Functional Requirements

### Access & Security
- Located under the Admin menu.
- Visible only to users with Admin or Archivist roles.
- Enforce role separation: Treasurer + Archivist cannot be assigned to the same user. Force logout when switching roles.
- Hard guardrail: Only allows data for fiscal years **prior to the current active year**.

### Core Features
- **Bulk Upload Support** (preferred)
  - CSV / Excel import for budgets and transactions.
  - Mapping interface for columns.
- **Manual Entry Fallback** for smaller corrections.
- **Budget Import**
  - Load previous year budgets.
  - Immediately set status to "Closed / Archived".
- **Transaction Import**
  - Load historical transactions.
  - Immediately mark as "Reconciled".
  - Support attachment of scanned document packets.
- **Accounting Continuity**
  - Ensure imported data correctly affects fund and account balances.
  - Support proper year-end carry-forward (e.g., 2025 closing transactions flow into 2026 opening balances).
  - Only budgets are truly "closed" — accounts and funds remain active year-to-year.

### Audit & Logging
- All imported records generate audit summary entries (lightweight, not full duplication).
- Clear indication that the record was loaded via Archival tool.
- Full logging of who performed the import and when.

## Success Criteria
- Historical data (at least 7–10 years) can be loaded efficiently.
- No risk of current-year data being entered via this tool.
- Accounting continuity is preserved (balances, funds, accounts flow correctly).
- Strong audit trail for all archival imports.
- Tool can be disabled or hidden after initial data migration.

## Notes / Open Questions
- How far back do we realistically want to go? (2015 minimum, 1991 maximum)
- Should the tool support partial-year imports?
- Printable audit reports of imported data?

## Related Documents
- Treasurer’s Guide (stewardship, audit readiness)
- userStory-contrib.md (workflow patterns)
- Workflow System, Document System, Audit System
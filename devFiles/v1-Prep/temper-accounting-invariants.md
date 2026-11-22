# Temper — Accounting Invariants
**Source of truth:** Treasurer’s Guide (`treasurersGuide.md`)  
**Status:** Beta / pre-1.0  
**Last updated:** 2026-11-20

These are the non-negotiable accounting rules. If a feature, report, or prompt conflicts with this list, the Guide wins and the feature changes.

## Priorities
1. Donor stewardship
2. Compliance with U.S. GAAP for not-for-profits (ASU 2016-14)
3. Audit-readiness
4. Accurate fund accounting

Do not introduce Beancount syntax or examples into the Treasurer’s Guide or the Temper system prompt.

## Fund Accounting
- Fund tags on **asset** accounts (checking, cash, bank) are ignored when calculating fund balances.
- Only fund tags on **non-asset** lines (Income, Expense, Net Assets / Equity) affect fund balances.
- Tag on an Income or Net Assets – WDR credit line increases the tagged fund.
- Tag on an Expense debit line decreases the tagged fund.
- Tag on a Net Assets – WDR debit line (release) decreases the tagged fund.
- This prevents double-counting and keeps cash as a shared asset. The restriction lives with the economic reason for the transaction.

## Standard Patterns
1. **Opening balance (restricted):** Debit Checking (no fund tag), Credit Net Assets – WDR (tag the fund).
2. **Restricted contribution:** Debit Checking (no fund tag), Credit Income (tag the fund). Alternative: credit Net Assets – WDR directly and tag that line.
3. **Restricted expense:** Debit Expense (tag the fund), Credit Checking (no fund tag).
4. **Release from restrictions:** Debit Net Assets – WDR (tag the fund being released), Credit Net Assets – WODR.

## Budget
- Budget assignment is **line-level**. `transaction_lines.budget_line_id` is the source of truth for reports, remaining balance, and budget-vs-actual.
- A null `budget_line_id` means the line is not in any budget (insurance reimbursement, non-budget).
- A single transaction may contain lines from different budgets (cross-year) and lines with no budget.
- The church operates on **calendar year (CY)**, not fiscal year. Never auto-prefix budget names with FY or a derived year.
- Header-level budget is not used for totals.

## Clear / Reconcile / Corrections
- Cleared and reconciled transactions are immutable by default (including status and attachments, except append-only adds where already allowed).
- Operational correction for a mistake on a cleared or reconciled transaction is **void + re-enter**, not un-clear.
- A temporary admin unlock exists for beta repair only. It is not the ongoing method. It requires an audit comment and restores status on re-lock unless explicitly flagged.

## Audit
- Material changes write to the audit / transaction event log with user, active role, and a readable summary.
- File modifications on modifiable transactions are logged. After reconciliation/clearing, the transaction (including its audit trail) is immutable.
- Beta is auditable, not certified. Go-live certification requires hashing final attachment bytes and an off-server witness. Root on the host cannot be stopped in-app.

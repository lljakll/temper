# User Story: Contribution Processing (Sunday Offerings & Similar)

**ID:** US-CONTRIB-001  
**Version:** 1.0  
**Date:** July 02, 2026  
**Author:** Jak  
**Status:** Draft  

## Objective
Provide a secure, auditable, multi-person workflow for processing contributions (especially Sunday morning cash + checks) that enforces dual counting, proper fund allocation, official validation, and automatic ledger entry while maintaining strong internal controls and donor stewardship per the Treasurer’s Guide.

## Roles
- **Teller** (limited role) — Performs initial count and data entry.
- **Second Teller** — Performs independent verification and signs off.
- **Church Official** (Treasurer / Financial Secretary) — Performs final validation and approves deposit.

## Workflow Steps

### 1. Teller Creates New Contribution
- Teller logs in with Teller role (limited UI — only Contribution Workflow is available).
- Starts a new Contribution workflow instance.
- Records dual count:
  - Cash: Input count by denomination (e.g., $1, $5, $10, $20, $50, $100).
  - Checks: For each check — Payor name, check number, check date, amount, and notes (including any fund designations for tax statements).
- Allocates amounts to WDR / WODR funds (e.g., Youth Fund — $200.00).
- System automatically flags the logged-in user as the first teller.
- Saves as "Draft – Pending Second Count".

### 2. Second Teller Verification & Sign-off
- Second teller opens the pending contribution.
- Reviews / re-enters denomination counts and check details for verification.
- Selects their name from dropdown and provides password / secondary authentication to "sign" the count.
- System records both tellers and timestamps.
- Status moves to "Dual Count Complete – Pending Official Validation".

### 3. Church Official Validation & Deposit
- Treasurer / Financial Secretary opens the entry.
- Performs validation:
  - Re-confirms denomination counts (with optional tick-box verification).
  - Validates check entries.
  - Verifies fund allocations and total amount.
- "Signs" the record with their credentials.
- Submits for deposit creation.
- Workflow creates:
  - A deposit transaction in the ledger (marked read-only, status = deposited but not yet cleared/reconciled).
  - Updates fund balances.
  - Generates a deposit slip / summary (printable if needed).
- Entire process is logged as an auditable event (lightweight summary record in audit table, not full duplication).

### 4. Post-Deposit
- Transaction appears in ledger as read-only.
- Available for later clearing and reconciliation (via bank matching workflow).
- All documents, signatures, and validation steps remain attached as an auditable package.

## General Principles (Applies to All Transaction Workflows)
- Primary path for all normal transactions (contributions, reimbursements, invoices/payments, payroll, etc.) must go through the appropriate guided workflow.
- Direct ledger entry screen exists as emergency / out-of-scope / review-only backup (editable only for authorized users on non-finalized entries).
- Every workflow must:
  - Guide the user through required steps.
  - Require appropriate document uploads.
  - Enforce multi-person approvals/sign-offs.
  - Produce a complete auditable package (documents + approval sheet).
  - Create the final ledger entry only after proper validation.
- All actions are logged with user, timestamp, and summary for random audit sampling.

## Success Criteria
- Strong segregation of duties and dual controls.
- Clear audit trail for every contribution/deposit.
- Reduced risk of errors or misallocation.
- Easy to train new tellers and officials.
- Donor intent (fund designations) properly captured and enforced.

## Related Documents
- Treasurer’s Guide (fund accounting, stewardship, internal controls)
- Workflow System Design (core engine)
- Document System
- Audit System

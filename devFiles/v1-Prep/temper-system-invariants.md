# Temper — System Invariants
**Status:** Beta / pre-1.0  
**Last updated:** 2026-11-20  
**Schema reference:** `artifacts/temper_schema.sql` (database name: `temper_db`)

These are the structural rules of the application. Implementation must not regress them.

## Data Model
- `transaction_details` = header (date, ref, pay to, check #, description, status, etc.).
- `transaction_lines` = the debit/credit lines (account, fund, amount, type, `budget_line_id`, note).
- `transaction_documents` hang off `transaction_detail_id`. Stored filename is what the row opens, not the original upload name.
- Attachment folders on disk are named by **reference number**, not by transaction id.
- `app_version` records applied versions. It is system data.

## Budget (line-level)
- `transaction_lines.budget_line_id` drives reports and remaining balance.
- Null = not in any budget.
- Cross-year reimbursements use different budget lines on the same transaction.
- Do not restore a header Budget control that drives totals.
- Do not prefix budget labels with FY## or “FY YYYY —”. Show the stored name.
- Natural and Functional are not shown on the transaction form. They remain available for reports.

## Attachments
- On ref change, move only document rows that belong to the transaction being edited. Never move or delete another transaction’s files that share the old ref folder.
- Leave a non-empty old folder in place. An empty one may be removed.
- Duplicate ref is a warning the user may override. Override must not steal attachments.
- Uploads and scans must succeed when ref is still blank. Store against the transaction id and relocate when a ref is saved.
- Optimize-on-upload stays (Ghostscript, headless `-sDEVICE=pdfwrite`). Keep the smaller result; store original on real failure.
- Desktop scanning is external: user scans to PDF and uploads. No in-app desktop scanner.
- Mobile camera scan remains for phone capture.

## Clear / Reconcile
- Default: cleared and reconciled are immutable.
- Temporary admin-only unlock exists, off by default. It is a beta repair tool.
- Unlock records original status on edit, requires one comment per save, and restores status on re-lock unless “Keep this status when locking” was set.
- Remove or tightly gate this before a second administrator exists.
- Operational method remains void + re-enter.

## UI
- Light mode is one stylesheet (`assets/css/temper-theme.css`) overriding Bootstrap 5 variables. Do not restyle pages individually.
- Light/Dark toggle lives in the sidebar and persists. Default is dark until chosen.
- Desktop lines grid uses percentage widths of the table: Account 22%, Fund 18%, Budget line 8%, Note 30%, Debit 10%, Credit 10%, Remove 2%.
- Interior column borders are user-resizable. The table must stay inside the modal (no horizontal scrollbar, no outer-edge resize, no row resize).
- View mode is plain text, not controls.
- Mobile transaction create/edit/view is a stacked card layout. Desktop grid is unchanged. No horizontal scroll to reach Debit/Credit. Save/Cancel/totals stay visible.

## Funds
- Fund tags on asset accounts do not affect fund balances.
- Only non-asset lines (Income, Expense, Net Assets) affect fund balances.
- Do not re-introduce double-counting of cash.

## Cleanup Candidates (do not treat as features)
- Mutable Fund flag on accounts (likely unfinished).
- Temporary importers and bulk tools (intended for removal before 1.0).
- Dual PDF/attachment viewers (post-release polish: unify).
- Full tagging system (post-release; hashtags in description are the interim).

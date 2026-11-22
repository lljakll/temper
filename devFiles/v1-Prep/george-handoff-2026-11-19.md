# Temper — Handoff to George
**From:** Brock (Sr. Dev Partner)  
**To:** George (Implementation / Prompt Engineering)  
**Date:** 2026-11-19  
**Current app version:** ~0.983 (post #66–#68)  
**Phase:** Beta (v0.900+). Bugfixes, clarifications, and focused enhancements only. No major new features until 1.0 planning.

## Operating Model
- Live data lives on the beta server (reachable via Tailscale). Code is developed locally, pushed to git, then pulled on the beta host.
- Do not shuttle full DB backups just to move data when working mobile.
- Prod/beta DB name is `temper_db` (not `temper`).
- Schema reference (schema-only dump) is maintained at `artifacts/temper_schema.sql`. Update it when a real schema patch lands.
- Versioning remains hybrid: `VERSION.md` + `app_version` table. Schema changes still require a proper patch in `updates/` with full metadata. No automatic DDL or table creation on page load.
- BL5 convention: the finalized prompt is the log entry. First line is the BL header. George ignores the header line. Versioning footer stays on implementation prompts.

## Accounting Source of Truth
The Treasurer’s Guide is the single source of truth for accounting principles.  
Priorities: donor stewardship, U.S. GAAP (ASU 2016-14), audit-readiness, accurate fund accounting.  
Do not introduce Beancount syntax into the Guide or the Temper system prompt.

## Locked Decisions (Do Not Regress)

### Budget
- Budget assignment is **line-level**. `transaction_lines.budget_line_id` is the source of truth for reports, remaining balance, and budget-vs-actual.
- `transaction_details.budget_id` is not a driver of totals (header budget field was removed from the form).
- A null `budget_line_id` means the line is not in any budget (e.g. insurance reimbursement).
- A single transaction may contain lines from different budgets (cross-year reimbursements) and lines with no budget.
- Church operates on **calendar year (CY)**, not fiscal year. Never auto-prefix budget names with `FY##`, `FY YYYY —`, or any year derived from the date range. Show the name as stored.
- Natural and Functional categories are not shown on the transaction form. They remain available for reports.

### Attachments & Ref Changes
- On reference-number change, relocate **only** the document rows that belong to the transaction being edited. Never move or delete files that belong to another transaction sharing the same ref folder.
- Empty old folders may be removed; folders that still contain other transactions’ files must be left alone.
- Duplicate ref is a warning the user may override. Overriding must not steal another transaction’s attachments.
- Scans/uploads must succeed even when the transaction has no ref # yet. Store against the transaction id (pending folder if needed) and relocate into the ref folder when a ref is saved.
- Optimize-on-upload (Ghostscript) stays. Prefer the smaller output. Headless calls must use `-sDEVICE=pdfwrite`. Store original on real failure.
- Desktop scanning is intentionally **not** built in. Users scan externally and upload the PDF. Mobile camera scan remains.

### Clear / Reconcile
- Default locks remain. Cleared and reconciled transactions are immutable.
- There is a temporary admin-only unlock toggle (off by default). It is a beta repair tool, not the operational correction method.
- Operational method for a mistake on a cleared/reconciled transaction is **void + re-enter**.
- The unlock records original status on edit, requires one audit comment per save, and restores status on re-lock unless the admin explicitly flags “Keep this status when locking.”
- Remove or tightly control this toggle before additional administrators exist.

### UI / Theme / Forms
- Light mode is a single stylesheet (`assets/css/temper-theme.css`) loaded from the shared header. It overrides Bootstrap 5 variables. Do not restyle pages one-by-one.
- A Light/Dark toggle exists in the sidebar and persists the choice. Default remains dark until the user chooses.
- Transaction lines grid (desktop): percentage widths of the table (Account 22%, Fund 18%, Budget line 8%, Note 30%, Debit 10%, Credit 10%, Remove 2%). Interior column borders are user-resizable; the table itself must stay inside the modal (no horizontal scrollbar, no outer-edge resize, no row resize).
- View mode is plain text, not controls.
- Mobile transaction create/edit/view uses a stacked card layout (one column). Desktop grid is unchanged. Debit/Credit must be reachable without horizontal scroll. Save/Cancel/totals stay visible.

### Fund Rules (unchanged)
- Fund tags on asset accounts are ignored for fund balances.
- Only fund tags on non-asset lines (Income, Expense, Net Assets) affect fund balances.
- Do not re-introduce double-counting of cash.

## Recent Work (BL #50–#68)
Completed and tested as of this handoff:
- #50–#53: Ghostscript headless + optimize reliability; Google Drive–style mobile scan; HTTP camera fallback.
- #54: Keep attachment when ref is blank; relocate on ref save.
- #55: Admin temporary unlock of cleared/reconciled with audit comment and restore-on-lock.
- #56: Read-only investigation of missing attachment folders (caused by whole-folder relocate on ref change).
- #57: Scope relocate to the edited transaction’s document rows only.
- #58–#60, #62: Line-level budget; populate line dropdowns; remove all FY prefixes.
- #61, #63–#65: Transaction form cleanup (remove header budget, drop Natural/Functional from form, percentage columns, interior resize that does not widen the table).
- #66–#68: Light-mode theme stylesheet, mobile stacked transaction form, sidebar Light/Dark toggle. Tested successfully.

## Known / Parked Items
- Post-release polish: unify the two PDF/attachment viewers; full tagging system + hashtag harvest.
- Point-release map (`artifacts/point-release-map.md`): 1.0 freeze books; recurring + multi-entry + generic import = 1.1; bank clear/reconcile + offsite backup = 1.2; Audit/Docs/Workflows/GUIDs = 1.5.
- Go-live audit seal: beta is auditable, not certified. After optimize, hash final attachment bytes. Off-server hash/audit witness is the real lock (root on the host cannot be stopped in-app).
- Temporary importers and bulk tools were intended for removal before 1.0. Confirm none remain in the active path.
- Mutable Fund flag on accounts is a cleanup candidate (likely unfinished).
- User preferences live in a JSON column on `users` for future card/dashboard settings.
- Attachment compression and scan polish are adequate for beta; further scanner integration was explicitly deferred in favor of external scan + upload.

## Implementation Rules for George
1. Explore before changing. Prefer the smallest change that satisfies the requirement.
2. Do not regress the locked decisions above.
3. Schema changes only via formal patches in `updates/`. Increment app version and update `VERSION.md` + `app_version`.
4. Keep changes modular. Flag temporary or beta-only code clearly.
5. Mobile and desktop layouts are separate. Do not collapse them into one grid.
6. Accounting behavior follows the Treasurer’s Guide. When in doubt, ask Brock before inventing a rule.
7. No YOLO whole-repo rewrites. Targeted fixes and mechanical cleanups only.

## Next Expected Work
- Continue beta polish and data-entry support.
- Prepare the 1.0 documentation packet (user manual + developer truths). Brock will own the content outline; George will implement or generate from approved outlines.
- Point-release features stay parked until explicitly pulled forward.

---
*This handoff supersedes ad-hoc prompt context for planning. Refer to the Treasurer’s Guide for accounting rules and to `artifacts/temper_schema.sql` for current schema shape.*

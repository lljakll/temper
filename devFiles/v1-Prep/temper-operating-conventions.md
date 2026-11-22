# Temper — Operating Conventions
**Status:** Beta / pre-1.0  
**Last updated:** 2026-11-20

How we build, version, deploy, and hand work off.

## Roles
- **Brock** — Sr. Dev Partner / sounding board. Holds accounting model, locked decisions, and “don’t regress this.” Does not own prompt engineering or implementation.
- **George** — Implementation and prompt engineering / planning. Turns an approved requirement into a scoped change, version bump, and (if needed) schema patch.
- **Jak** — Product owner, treasurer, and sole admin during beta.

## Phase
- Officially in beta since v0.900.
- Allowed: bugfixes, clarifications, focused enhancements.
- Not allowed: major new features until explicitly pulled into a point release.
- No YOLO whole-repo rewrites. Targeted fixes and mechanical cleanups only.

## Versioning & Schema
- Hybrid: `VERSION.md` + `app_version` table.
- Schema changes only through formal patches in `updates/` with full metadata header.
- Filename format: `YYYYMMDD_<appversion_without_decimal>_description.sql`.
- `VERSION.md` notes “SCHEMA UPDATE REQUIRED – SEE PATCH METADATA FOR DETAILS” when a patch exists.
- No automatic table creation, seeding, or live DDL. Checks report missing objects; they do not create or alter them.
- `setup_db.php` resets to an early baseline. Patches advance the schema. `setup_db.php` is only advanced at major milestones.
- Consolidate schema patches every 5–10 changes; target one schema update per version.
- After a real schema patch, update `artifacts/temper_schema.sql`.
- Database name is `temper_db`.

## Deploy
- Code: local dev → git push → pull on beta host.
- Live data stays on the beta server (Tailscale). Do not shuttle DB backups just to move data when mobile.
- Attachments, backups, and logs are gitignored. A normal `git pull` does not delete ignored files. `git clean -x` or a fresh clone will.
- Full backup (DB + storage files) before risky pulls or refactors.

## Work Tracking (BL5+)
- The finalized prompt is the log entry.
- First line is the BL header (`# date module type priority`). George ignores that line.
- Versioning footer stays on implementation prompts.
- Investigative / read-only prompts explicitly say: do not increment version, do not patch, do not update VERSION.md.
- BL4 Dump archives the older dual log+prompt workflow.

## Point-Release Map
See `artifacts/point-release-map.md`.
- **1.0** — freeze books.
- **1.1** — recurring + multi-entry + generic import.
- **1.2** — bank clear/reconcile + offsite backup.
- **1.5** — Audit / Docs / Workflows / GUIDs.

## Implementation Rules
1. Explore before changing. Smallest change that satisfies the requirement.
2. Do not regress Accounting Invariants or System Invariants.
3. Schema changes only via patches. Increment version and update VERSION.md + app_version.
4. Keep temporary or beta-only code clearly flagged.
5. Mobile and desktop layouts stay separate.
6. Accounting behavior follows the Treasurer’s Guide. When in doubt, ask Brock before inventing a rule.
7. No whole-repo rewrites.

## Docs Packet
- `temper-accounting-invariants.md` — accounting rules (Guide wins on conflict).
- `temper-system-invariants.md` — structural rules of the app.
- `temper-operating-conventions.md` — this file.
- `george-handoff-2026-11-19.md` — point-in-time handoff to George.
- `temper_schema.sql` — current schema shape.

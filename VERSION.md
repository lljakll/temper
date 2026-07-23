# Temper Application Version History

Human-readable changelog for the Hope Baptist Treasurer (Temper) application.
Application version is also stored in the database (`app_version` table) for
runtime display and future schema-version matching.

## Table of Contents

- [v0.801](#v0801)

---

## v0.801

**First tracked alpha** — 2026-07-23

- Introduced hybrid application versioning: `VERSION.md` (this file) plus a
  single-row `app_version` database table for the current version string.
- Sidebar displays the current version above the Welcome message (all roles);
  the version number links here for the full changelog.
- Schema includes a `schema_version` column ready for future app/schema
  compatibility checks.

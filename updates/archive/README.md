# Archived schema patches (pre-0.944)

These files are **not** the required install path.

Through application **v0.944**, `setup_db.php` creates the current schema
(`accounts.account_type`, `users.preferences`, and all earlier shape) and
seeds `app_version` history **0.801 → 0.944**.

| Install type | What to run |
|--------------|-------------|
| **Fresh** | `php setup_db.php` only. Do not replay files in this folder. |
| **Existing live DB at 0.943** | `updates/20260827_0944_setup_baseline_consolidation.sql` (process row + idempotent DDL). |
| **Existing live DB 0.900–0.942** | Same 0.944 consolidation patch (idempotent DDL brings schema current). |
| **Ancient DB below 0.900** | Prefer a new install, or apply these archived files in order after backup. Do **not** run destructive setup against live treasurer data. |

Active (post-consolidation) patches live in the parent `updates/` directory.
Keep these files for audit and for operators recovering very old databases.

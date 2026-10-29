# Owner-selected shared UAT password - 2026-10-30

The owner requested one shared password for the 43 temporary UAT accounts. Add a password and confirmation form on `/admin/uat`; the owner must enter and submit the value. No actual requested password is embedded in source, defaults, logs or page props.

The original administrator can set it once for the exact complete, unused, unchanged and unexpired roster while UAT mode is enabled. This may replace an earlier random-password recovery and its encrypted export. Normal random recovery cannot undo shared setup. Used, changed, other-owner, partial or already-shared rosters are refused. Each user receives its own normally salted password hash. Personal accounts, LGU assignments, CPU circles, roles and expiry remain unchanged.

The existing encrypted owner-only retry export reflects the submitted shared password and expires within 30 minutes or at account expiry. The form clears password fields after completion and uses standard password input names excluded from flashed validation input. The shared password grants access to any roster email, so this is explicitly temporary testing access.

No database migration. Live password submission remains an owner action under browser-control handoff rules.

Validation: SQLite 517 passed, two skipped (519 total, 5069 assertions); PostgreSQL UAT 14 passed (402 assertions); frontend 46 passed; production TypeScript/Vite build, changed-file Pint and browser creation/retry download/shared setup/displayed-code sign-in passed. No live shared-password submission or live UAT sign-in was performed during implementation.

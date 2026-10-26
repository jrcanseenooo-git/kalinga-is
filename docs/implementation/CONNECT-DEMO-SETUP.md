# Connect demo setup repair - 2026-10-23

Historical checkpoint: the eight-circle demo result below was superseded by
the owner-approved Tabuk-only demo scope repair. See
[Connect messaging delivery](CONNECT-MESSAGING-2026-10-23.md) for current behavior.

## Finding and change

KEEP existing Connect authorization and LGU boundaries. REFACTOR demo setup:
KalingaDemoSeeder created LGUs and assignments directly, bypassing the admin
workflow that creates circles and provisions memberships. It now calls
KalingaConnectDemoSeeder after the demo assignments exist.

The dedicated seeder adds missing circles and memberships for the three named
demo worker/CPU accounts and eight exact DEMO-LGU codes only. It checks active
accounts, supported roles, active LGUs and currently effective assignments.
Disabled circles and ended memberships remain unchanged. Existing messages,
cases, grants, assignments and passwords are preserved. Each inserted circle
or membership has a metadata-only audit event in the same transaction.

## Existing local demo installation

Run `php artisan db:seed --class=KalingaConnectDemoSeeder` from the project root.
This command is restricted to local/testing environments. It does not create
missing accounts or LGU assignments. Full demo installation still uses the
existing DatabaseSeeder; do not rerun that full seeder over working case data
just to repair Connect.

The current local PostgreSQL installation was repaired without resetting it.
The worker and supervisor each see eight circles because their existing demo
assignments cover all eight LGUs. The missing cpu@kalinga.test local demo
account was separately restored using the documented local demo password,
with an active Tabuk-only assignment and an audit event. Its Connect response
contains one circle with three eligible members. No case-specific grant was
added by this repair. Group membership does not grant access to protected cases.

## Verification and limits

KalingaConnectDemoSeederTest: four tests, 41 assertions passed. Covers shared
worker/CPU visibility, repeat execution without duplicate rows/audits,
preserved messages and departed memberships, expired assignments, non-demo
account exclusion and disabled circles. Actual local controller responses
confirmed worker/supervisor circle visibility and CPU Tabuk membership.

No schema or frontend changes. No browser visual acceptance, full regression
suite, production certification or external deployment is claimed. The ZIP
previously supplied by the owner is unchanged; repairs are in this project
folder and its configured local database.

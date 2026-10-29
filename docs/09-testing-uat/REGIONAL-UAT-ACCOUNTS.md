# Regional UAT accounts - 30 October 2026

This feature is disabled unless `UAT_ENABLED=true`. Keep `APP_DEBUG=false` on Render. Obtain owner approval before publishing, enabling the feature or creating live accounts.

## What is created

The administrator-only page at `/admin/uat` previews a fixed roster and creates 43 accounts in one transaction:

| Region / municipality | Regional participants | ITO testing | Total |
|---|---:|---:|---:|
| CAR / Paracelis, Mountain Province | 7 | 3 | 10 |
| MIMAROPA / Brooke's Point, Palawan | 7 | 3 | 10 |
| Region VIII / City of Catbalogan, Samar | 7 | 6 | 13 |
| Region XIII (Caraga) / Socorro, Surigao del Norte | 7 | 3 | 10 |

Each regional group has two Case Managers, CPU Health, CPU Barangay/BCPC, CPU Law Enforcement, an LGU Head and a Field Office account. Five ITOs each get Case Manager, CPU Health and LGU Head logins. BCPC maps to the existing `barangay_official` discipline; the administrator reviews this mapping on the page before creation.

Creation adds/reuses the four exact PSGC LGU entries and coordination circles. It assigns 39 operational accounts to their LGU and circle; the four Field Office accounts use their region scope. It does not create case records or case-specific CPU grants. Workers start their own fictional intake; a referral/MDT grant is still necessary for CPU case access. Separate ITO logins are not isolated tenants: supervisors retain their LGU-wide view and coordination circles are shared by LGU.

## Sign-in

Accounts use reserved test addresses such as `car-cm01@uat.kalinga.test` as login identifiers. These are not mailboxes. Each account has a unique generated 20-character password, stored hashed. After password authentication, the dedicated UAT account sees a six-digit test code and types it into the ordinary verification form. No email is sent for these accounts. The UI explicitly states that the displayed code does not verify email ownership; this is password-only assurance with a test verification step, not genuine MFA.

Codes retain their normal expiry, consumption and wrong-attempt limits. Ordinary accounts continue using their existing verification method. Production debug flags do not disclose codes for ordinary accounts. Dedicated UAT access requires the marker, exact fixed-roster identity, role, discipline, location, active status, enabled switch and unexpired access. Merely registering an address with the test domain does not grant UAT access. UAT verification has its own audit event.

## Deployment and operation

1. Review and publish the change only after owner approval. The new migration adds nullable `users.uat_key` (unique) and `users.uat_expires_at`; it changes no existing account privileges or records.
2. In Render set `UAT_ENABLED=true` and keep `APP_DEBUG=false`, then deploy the approved revision. The existing startup script runs the migration. Do not use `APP_DEBUG=true`, disable global MFA or alter `DEMO_*` identities for this roster.
3. Sign in as System Administrator. In Users choose **UAT account setup**, or visit `/admin/uat`.
4. Review the roster, shared LGU visibility, BCPC mapping and expiry. Default expiry is 23:59 today in Philippine time; the server accepts at most seven days from now.
5. Confirm and create the roster once. The browser downloads `kalinga-uat-credentials.json`. Save it privately before leaving the session. The credentials are returned only in this response; passwords are not retained for later download and are excluded from audit changes and Inertia page props.
6. Distribute each participant's own credential rows privately. Never send the complete credential file to the participant group. Do not email test codes to the reserved addresses.
7. Test one fresh sign-in for each role and region, then check allowed and denied access using fictional records. Prepare office profiles/signatories through the ordinary office configuration if needed for reports; the roster does not invent them.
8. At expiry, subsequent protected requests and new logins are denied, including already-open UAT sessions. Switching `UAT_ENABLED=false` and redeploying also denies all marked UAT accounts. Records remain for audit; there is no automatic deletion or automatic extension.

Existing-account/email collisions block the entire batch without password changes. Conflicting/inactive LGUs or circles require review and roll back the batch. If the request's outcome is uncertain, inspect Users first; retries cannot reset existing credentials. If the one-time credential download is lost, stop and arrange an explicitly approved credential recovery/rotation; do not delete rows or rerun the old demo seeder expecting recovery.

## Verification and limits

`UatAccountsTest` covers exact counts, hashed unique passwords, LGU/circle and region scope, no secret audit values, collision rollback, authorized creation, expiry, disabled access, code display without SMTP and protection of ordinary accounts. Existing authentication suites cover invalid/expired code handling.

Validation on 2026-10-30: SQLite PHP tests passed (512 executed, two skipped); PostgreSQL tests passed (513 executed, one skipped) against a disposable UTC-configured local database; frontend tests passed (46); production TypeScript/Vite build and changed-file Pint checks passed. The dedicated browser regression passed actual 43-account creation, one-time download, collision prevention and a new UAT worker's displayed-code sign-in. The broader existing-screen browser run is separate and has report loading-indicator ARIA and short intake error-response wait failures under investigation; do not treat it as a clean full-browser pass.

These checks are not evidence of successful Render deployment or live provisioning. Complete live smoke tests after approved deployment. Genuine email-delivery MFA must be tested separately with reachable ordinary accounts; a UAT code test cannot pass that acceptance criterion.

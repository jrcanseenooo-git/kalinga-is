# Temporary regional UAT accounts - 2026-10-30

Adds an opt-in, system-administrator-only setup page for the owner's 43-account regional roster: CAR 10, MIMAROPA 10, Region VIII 13 and Caraga 10. Each account has a unique password and the requested operational scope. The page returns one credential download; repeat requests never reset an account.

Dedicated UAT accounts show a test code after password entry without using SMTP. This does not prove mailbox ownership or constitute a separate factor. Production debug remains off, normal-account verification remains in place, and UAT access ends at the selected expiry or when UAT mode is disabled. Codes and credentials are excluded from audit values; test verification is identified separately in the audit catalogue.

Migration `2026_10_30_000100` adds nullable, unique `users.uat_key` and nullable `users.uat_expires_at`. No existing case data or account privileges are changed by the migration. User mass-assignment does not expose these fields. No application-wide permission changes and no automatic production seeding were added.

Operational instructions and limitations: `docs/09-testing-uat/REGIONAL-UAT-ACCOUNTS.md`. New feature tests: `tests/Feature/UatAccountsTest.php`. Data dictionary and its generator include the two columns.

Owner review before enabling: BCPC uses the existing Barangay Official CPU discipline; ITO supervisor accounts share normal LGU-wide visibility; use fictional data only. Live provisioning remains an explicit administrator action. This release does not create case records, office profiles or case-specific CPU grants.

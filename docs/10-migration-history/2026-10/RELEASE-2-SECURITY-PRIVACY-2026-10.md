# Release 2 - Security and privacy hardening (2026-10-22)

Apply on top of Release 1. Then:

    php artisan migrate --force      # installs the append-only audit trigger (PostgreSQL)
    npm run build
    php artisan optimize:clear

Delete tests/Feature/ProductionAssetsTest.php if you copied Release 1; it is
now part of tests/Feature/SecurityHardeningTest.php.

New .env settings (see .env.example):
- TRUSTED_PROXIES - "*" or the proxy addresses when Kalinga runs behind a
  proxy or load balancer (already set in render.yaml).
- MFA_ROLES - roles that must use a second factor. Default now includes
  cpu_member (OWNER REVIEW).

The full register of controls, findings and residual risks is in
docs/05-governance/SECURITY-CONTROLS.md (VA and PIA evidence). Run
`bash scripts/security-check.sh` before each release.

## What changed
1. Verification codes: five wrong codes end the session and the password
   must be entered again (was unlimited). Recovery codes compared in
   constant time. Session renewed after verification.
2. Sign-in: per-account limit (10 failures in 15 minutes from any address)
   in addition to the per-address limit. Password reset and 2FA set-up are
   throttled.
3. CPU members must use 2FA (configurable).
4. Audit trail: every row now records the client IP address; sign-in,
   failed sign-in, lockout, logout, password reset and case views are
   recorded; rows are append-only in PostgreSQL (update, delete and truncate
   refused). The admin audit page shows the address. All 69 audit writes now
   go through one class (app/Support/AuditLog.php).
5. Headers: one implementation for pages and error pages; COOP, CORP and
   explicit worker/manifest sources added; HSTS always in production; error
   pages no longer allow inline scripts.
6. Production fail-safes: debug output forced off, public storage disk
   refused for case files, unused signed file route disabled.
7. Bugs: malformed case id in offline sync crashed on PostgreSQL (500);
   one records endpoint validated before authorising; case timeline sent
   whole audit rows (now only what the page shows, without views or IPs).

## Tests
- New: BruteForceProtectionTest, AuthAuditTrailTest,
  CaseRouteAccessSweepTest (every case route, every method, worker from
  another LGU, system administrator, CPU member without referral).
- php artisan test: 439 passed (SQLite). Key security tests also pass on
  PostgreSQL. Running the whole suite on PostgreSQL showed 44 failures; 43
  were test-only (SQLite trigger syntax and JSON comparisons in tests,
  converted in Release 3) and 1 was the real offline-sync bug fixed above.

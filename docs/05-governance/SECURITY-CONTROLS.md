# Security Controls Register - Kalinga CEFMU Case Management Information System

Status: maintained with the code. Last reviewed 2026-10-22.
Purpose: evidence for the Vulnerability Assessment (VA) and the Privacy Impact
Assessment (PIA). Each control names the code that implements it and the
automated test that proves it. Run `bash scripts/security-check.sh` before
every release.

References: Republic Act No. 10173 (Data Privacy Act of 2012) and its
Implementing Rules; NPC Circular No. 16-01 (security of personal data in
government agencies); OWASP Top 10 (2021). Organisational measures (DPO,
policies, training, physical security) are outside this register.

## 1. OWASP Top 10 mapping

| OWASP risk | Control in Kalinga | Where | Test evidence |
|---|---|---|---|
| A01 Broken access control | Every request is authorised on the server (permission gates, LGU/case scope); UI hiding is never relied on. Case ids in URLs are numeric only. Authorisation runs before validation. | app/Services/RolePermissions.php, CaseAccessScope.php, AppServiceProvider (route patterns) | CaseRouteAccessSweepTest (every {case} route, every method, three outside roles), RoleAuthorizationTest, CaseReportsTest |
| A02 Cryptographic failures | HTTPS forced in production with HSTS; session cookie encrypted, HttpOnly, Secure, SameSite=Lax; passwords bcrypt; 2FA secrets, recovery codes and email codes encrypted; offline data AES-GCM in IndexedDB | config/session.php, SecurityHeaders, User model, resources/js/Offline | SecurityHardeningTest, offline e2e suite |
| A03 Injection | Query builder bindings everywhere; raw SQL takes constants only; Vue escapes output; the one v-html renders a server-generated QR code | app (reviewed), resources/js | security-check.sh lists any v-html |
| A04 Insecure design | Need-to-know views (initials on reports, masked contacts, health content withheld, aggregate-only analytics with small-cell suppression); offline pack limited to assigned cases with expiry | CaseReports.php, AnalyticsController | CaseReportsTest, AggregatePrivacyTest, AnalyticsSuppressionTest |
| A05 Security misconfiguration | Security headers on every response including errors (CSP with nonce, no inline script, frame-ancestors none, COOP/CORP, nosniff); debug forced off in production; stray public/hot ignored; public disk refused for case files; Laravel signed file route disabled | SecurityHeaders.php, AppServiceProvider, config/filesystems.php | SecurityHardeningTest |
| A06 Vulnerable components | composer audit and npm audit in the release check | scripts/security-check.sh | npm audit: 0 vulnerabilities (2026-10-22) |
| A07 Identification and authentication failures | Password policy (10+ chars, mixed case, number, symbol, breached-password check); sign-in throttled per email+address and per account; 2FA required for configured roles; 5 wrong codes end the session; constant-time recovery-code check; session renewed after login and 2FA; reset and 2FA endpoints throttled | LoginRequest, TwoFactorController, routes/auth.php, config/kalinga.php | BruteForceProtectionTest, TwoFactorTest, AccountEnumerationTest |
| A08 Software and data integrity failures | Offline sync is idempotent with version checks and conflict states; uploads quarantined until malware scan clears them | OfflineSyncController, CaseArtifactController | OfflineSyncIntegrityTest, offline-sync e2e |
| A09 Logging and monitoring failures | Audit trail with client IP for sign-in (success, failure, lockout, logout, reset), case views, report generation, exports and every change; append-only at the database (PostgreSQL trigger refuses update, delete and truncate) | app/Support/AuditLog.php, migration 2026_10_22_000200 | AuthAuditTrailTest |
| A10 Server-side request forgery | The application makes no server-side requests to user-supplied URLs | - | - |

## 2. Findings fixed in this review (2026-10-22)

| # | Severity | Finding | Fix |
|---|---|---|---|
| 1 | High | Verification codes (2FA) could be guessed without limit | Five wrong codes end the session; route throttled; event audited |
| 2 | High | Reports printed reproductive and mental health plan goals | Health-domain content withheld in every report |
| 3 | Medium | Sign-in could be attempted from many addresses against one account | Per-account limit (10 per 15 minutes) in addition to per-address |
| 4 | Medium | Behind a proxy HTTPS was not detected, so HSTS was never sent | TRUSTED_PROXIES setting; HSTS always in production |
| 5 | Medium | Audit rows could be edited or deleted by anyone with database access | Append-only trigger (PostgreSQL) |
| 6 | Medium | Sign-in activity and case views were not audited; IP address never recorded | Auth event listeners, case.viewed (once per 30 minutes), IP on every audit row |
| 7 | Medium | Malformed ids caused server errors (500) on several routes and on offline sync (PostgreSQL) | Numeric route patterns; sync validates the id first |
| 8 | Low | Error pages allowed inline scripts; header code duplicated | One header method for pages and errors |
| 9 | Low | Recovery codes compared in non-constant time | hash_equals |
| 10 | Low | Password reset and 2FA set-up not throttled | throttle middleware |
| 11 | Low | Laravel's signed private-file route enabled but unused | Disabled |
| 12 | Low | One records-governance endpoint validated before authorising (422 instead of 403) | Authorise first |
| 13 | Low | Case timeline sent full audit rows to the browser | Only id, event, changes, outcome, time |
| 14 | Info | Deployment copy contained public/hot | Ignored in production; release check fails if present |

## 3. Production settings checklist
- APP_ENV=production, APP_DEBUG=false, APP_KEY set and kept secret
- SESSION_SECURE_COOKIE=true, SESSION_ENCRYPT=true
- TRUSTED_PROXIES set when behind a proxy or load balancer
- FILESYSTEM_DISK=s3 (private bucket) or local; never public
- SCAN_DRIVER=clamav (uploads stay quarantined until cleared)
- MFA_ENFORCEMENT=true; MFA_ROLES lists every role that sees case data
- Database on PostgreSQL with encryption at rest and backups (hosting provider)
- Run `php artisan migrate --force` so the audit trigger is installed
- Supabase: DB_URL is the session pooler string, DB_SSLMODE=require, SSL enforced in Supabase, Data API switched off, and `php artisan kalinga:lock-down-database-api` run after migrations (the Docker start script does this)

## 4. Residual risks and owner decisions
- OWNER REVIEW: session idle timeout is 120 minutes (SESSION_LIFETIME). Shorter
  values (for example 30) reduce risk on shared devices.
- OWNER REVIEW: CPU members now require 2FA (MFA_ROLES). Remove cpu_member only
  with DPO approval.
- Personal data is not encrypted column-by-column; encryption at rest relies on
  the database host. Recommended: managed PostgreSQL with disk encryption.
- composer audit could not be run from the review environment (no access to
  Packagist); run scripts/security-check.sh before release.
- The database account used by the application can drop the audit trigger;
  production should use a separate migration account so the application
  account cannot alter triggers.
- A third-party penetration test is recommended before nationwide roll-out.

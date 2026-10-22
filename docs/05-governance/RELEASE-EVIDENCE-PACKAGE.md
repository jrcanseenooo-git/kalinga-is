# Release Evidence Package - Kalinga IS v1.0

**Status:** DRAFT - Requires owner sign-off before production deployment.
**Date:** 2026-10-02
**System:** Kalinga IS v1.0 (pre-production)

---

## 1. Release Overview

| Attribute | Value |
|-----------|-------|
| System | Kalinga CEFMU Case Management Information System |
| Version | 1.0 (pre-production) |
| Stack | Laravel 12 / PHP 8.3, Vue 3 / TypeScript, PostgreSQL, Inertia.js, Tailwind CSS |
| Runtime | Docker (php:8.3-fpm-alpine + nginx) |
| Target platform | Render (managed PostgreSQL, S3-compatible storage) |
| Prepared by | Technical Team |
| Date | 2026-10-02 |

---

## 2. Test Evidence

### 2.1 Automated Test Suite

| Metric | Value |
|--------|-------|
| Framework | PHPUnit (via `php artisan test`) |
| Total tests | 229 |
| Total assertions | 1,087 |
| Failures | 0 |
| Errors | 0 |
| Test database | SQLite in-memory |
| Last run date | 2026-10-12 |

### 2.2 Test Coverage Areas

| Area | Tests | Key Assertions |
|------|-------|----------------|
| Authentication | Login, logout, password reset, password update, registration | Active-account gate, password policy enforcement, no auto-login on registration |
| MFA (Two-Factor) | Setup, confirm, challenge, verify, recovery codes, role enforcement | TOTP verification, recovery code consumption, privileged-role enforcement toggle |
| Authorization / RBAC | Role permissions, case access scope, LGU scope | 15 roles × permission matrix, jurisdiction boundaries, IDOR prevention |
| Case workflow | Creation, status transitions, assignment, closure | State machine enforcement, assigned-worker scope |
| Assessments & safety | Risk assessment workflow, safety triage | Professional-action gates, immutable finalized history |
| Plans & continuity | Plan lifecycle, handover workflow | Review by different author, cross-scope restrictions |
| Referrals | Creation, consent validation, scope enforcement | Lawful-basis recording, consent-gate for consent-based sharing |
| Child participation | Access restrictions, safe redaction | Additional permission gates, role exclusions |
| Records governance | Classification, legal holds, retention, disclosure | Hold enforcement, disposition separation of duties |
| MDT collaboration | Session management, attachment quarantine | Scope-limited access, scan-state gates on download |
| Documents | Upload quarantine, download authorization, restricted metadata | Quarantine path, scan-state enforcement, metadata redaction |
| Analytics & exports | Aggregate privacy, small-cell suppression, role scope | Minimum group size 5, aggregate-only CSV, scope filtering |
| Public reports | Intake, screening, jurisdiction routing | LGU-scoped visibility, lifecycle transitions |
| Search | Scope enforcement, cross-user isolation | Assigned-case limitation, no cross-worker leakage |
| Offline sync | Queue management, conflict handling | Encrypted packs, authorization checks, idempotent operations |
| Tasks | Workflow, scope enforcement | Owner-only modification, case-link authorization |
| Notifications | Delivery, scope | Role-appropriate routing |

### 2.3 Manual Test Procedures

| Procedure | Status |
|-----------|--------|
| UAT core scenarios | Document exists: `docs/09-testing-uat/UAT-CORE.md` |
| Presentation checklist | Document exists: `docs/09-testing-uat/PRESENTATION-CHECKLIST.md` |
| Responsive/mobile testing | Automated Chromium smoke and overflow checks passed at desktop and Pixel 7 mobile viewport; field-device UAT remains an owner acceptance activity |
| Accessibility testing | Automated axe checks passed for public login/dashboard; authenticated case-workspace findings (tab target, file label, contrast) were remediated |

### 2.4 Code Coverage

PHPUnit code coverage metrics are not currently configured. Adding coverage reporting (`--coverage-html` or `--coverage-clover`) is recommended for future releases but is not a deployment blocker given the breadth of functional test coverage.

---

## 3. Security Evidence

### 3.1 Remediation Completed

**Phase 1 - Security-Critical (6 items):**

| # | Item | Description |
|---|------|-------------|
| 1 | AUTH-1 | Password policy: min 10 chars, mixed case, numbers, symbols, breach database check |
| 2 | AUTH-2 | Registration produces inactive account; admin activation required |
| 3 | AUTH-3 | Shared user DTO; sensitive fields (MFA secret, recovery codes) in `$hidden` |
| 4 | SESSION-1 | Encrypted sessions, secure cookies by default |
| 5 | REG-1 | Registration disabled by default in production |
| 6 | DEPLOY-1/2/3 | Production Docker container (php-fpm + nginx), security headers, hidden dotfiles |

**Phase 2 - Security-Important (5 items):**

| # | Item | Description |
|---|------|-------------|
| 7 | AUTH-4 | TOTP-based MFA with recovery codes; required for privileged roles |
| 8 | AUTH-5 | MFA enforcement middleware with configurable toggle |
| 9 | FILE-1 | MDT attachment quarantine (pending_scan → cleared) |
| 10 | DEPLOY-5 | Mail provider configuration in render.yaml |
| 11 | MFA migration | Database migration for MFA columns and MDT scan_state |

**Phase 3 - Hardening (5 items):**

| # | Item | Description |
|---|------|-------------|
| 12 | FILE-2 | Malware scanner integration point (configurable driver, admin clearance with audit) |
| 13 | CSP-1 | Nonce-based Content Security Policy for scripts |
| 14 | DB-1/2/3 | Performance indexes on cases and legal_holds |
| 15 | DEP-1 | Dependency audit - 0 vulnerabilities (composer + npm) |
| 16 | DEPLOY-4 | APP_URL configuration in render.yaml |

### 3.2 Dependency Audit

| Tool | Result | Date |
|------|--------|------|
| `composer audit` | 0 vulnerabilities | 2026-10-02 |
| `npm audit` | 0 vulnerabilities | 2026-10-02 |

### 3.3 Security Controls Summary

| Control | Status |
|---------|--------|
| Server-side RBAC (Gates + CaseAccessScope) | Implemented |
| CSRF protection | Implemented (Laravel default) |
| XSS prevention | Implemented (CSP nonces, Vue auto-escaping, Blade escaping) |
| SQL injection prevention | Implemented (Eloquent parameterized queries) |
| Session security | Implemented (encrypted, secure cookies, HTTPS-only, HSTS) |
| Password policy | Implemented (NIST-aligned, breach database check) |
| MFA | Implemented (TOTP for privileged roles) |
| File upload security | Implemented (quarantine, scan-state gate, no public URLs) |
| Content Security Policy | Implemented (nonce-based script-src) |
| Security headers | Implemented (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy) |
| Audit logging | Implemented (append-only, metadata-only) |

### 3.4 Known Security Limitations

| # | Limitation | Risk Level | Mitigation |
|---|-----------|------------|------------|
| 1 | No real-time intrusion detection | Medium | Periodic audit log review; incident response plan |
| 2 | Audit logs in same database (tamperable) | Medium | Application-level append-only; recommend write-once storage |
| 3 | No bulk session invalidation endpoint | Medium | Sessions table can be truncated by admin; documented in incident response |
| 4 | No rate limiting beyond Laravel defaults | Low | Platform-level rate limiting available on Render |
| 5 | Malware scanner not yet integrated (files stay quarantined) | Low | Admin manual clearance with audit trail; no file served without clearance |

---

## 4. Privacy Evidence

| Document | Location | Status |
|----------|----------|--------|
| Privacy Impact Assessment | `docs/05-governance/PRIVACY-IMPACT-ASSESSMENT.md` | Draft - requires DPO review |
| Privacy and access model | `docs/03-architecture/privacy-access-model.md` | Current |
| Privacy data flow | `docs/03-architecture/privacy-data-flow.md` | Current |
| Records lifecycle governance | `docs/03-architecture/RECORDS-LIFECYCLE-AND-DOCUMENT-GOVERNANCE.md` | Current |
| Audit retention policy | `docs/05-governance/AUDIT-RETENTION-POLICY.md` | Draft - requires owner decisions |

---

## 5. Architecture Evidence

| Document | Location | Status |
|----------|----------|--------|
| System architecture | `docs/03-architecture/ARCHITECTURE.md` | Current |
| Security and offline design | `docs/03-architecture/SECURITY-AND-OFFLINE.md` | Current |
| Data model | `docs/04-database/DATA-MODEL.md` | Current |
| Data dictionary | `docs/04-database/DATA-DICTIONARY.md` | Current |
| Role migration matrix | `docs/03-architecture/role-migration-matrix.md` | Current |
| LGU scope and reporting | `docs/03-architecture/LGU-SCOPE-CPU-COORDINATION-AND-REPORTING.md` | Current |

---

## 6. Deployment Evidence

| Item | Status |
|------|--------|
| Dockerfile | Production-ready (php:8.3-fpm-alpine + nginx) |
| render.yaml Blueprint | Configured with managed PostgreSQL, S3 storage, mail, session encryption |
| Environment variables | Documented in `.env.example` with production annotations |
| Health check | `/up` endpoint configured |
| Local setup guide | `docs/07-installation/LOCAL-SETUP.md` |
| Render deployment guide | `docs/07-installation/RENDER-DEPLOYMENT.md` |
| Operations guide | `docs/08-admin-guide/OPERATIONS.md` |

---

## 7. Pre-Deployment Checklist

### 7.1 Technical (Development Team)

- [x] All 229 automated tests pass (1,087 assertions)
- [ ] `composer audit` returns 0 vulnerabilities
- [ ] `npm audit` returns 0 vulnerabilities
- [x] Production build completes without errors (`npm run build`)
- [ ] Database migrations run successfully on clean PostgreSQL
- [x] Seed data (roles, permissions, normalized LGUs, demo case grants) loads correctly in the clean automated environment
- [x] Health check endpoint responds in the local acceptance environment
- [ ] HTTPS and HSTS verified
- [ ] CSP headers verified (no unsafe-inline for scripts)
- [ ] Session encryption verified
- [ ] MFA enrollment flow verified for privileged roles
- [ ] File upload quarantine verified
- [x] Responsive layout verified at 360, 390, 768, and 1440 CSS pixels

### 7.2 Owner/DPO Decisions (Blocking)

- [ ] Data Protection Officer designated
- [ ] Data retention schedule approved with specific periods
- [ ] Data controller designation confirmed
- [ ] Document classification catalogue approved
- [ ] Incident response contacts designated
- [ ] Privacy Impact Assessment reviewed and approved
- [ ] Data processing agreements executed with hosting/storage/mail providers

### 7.3 Owner/DPO Decisions (Post-Deployment)

- [ ] DSAR procedure established
- [ ] Malware scanning provider selected and integrated
- [ ] Managed-device enrollment policy for offline use
- [ ] Cross-border transfer compliance confirmed
- [ ] Audit log integrity enhancement (write-once storage)
- [ ] Periodic access review schedule established

---

## 8. Sign-Off

| Role | Name | Signature | Date |
|------|------|-----------|------|
| System Owner | | | |
| Data Protection Officer | | | |
| Technical Lead | | | |
| DSWD Privacy Focal | | | |

---

## 9. Release Notes

### New in v1.0

- Complete case management workflow (intake → assessment → planning → intervention → monitoring → closure)
- Four-role RBAC catalogue with server-side enforcement and normalized jurisdiction scoping
- Records lifecycle governance with classification, legal holds, retention review, and disclosure ledger
- MDT collaboration workspace with quarantined attachments
- Child participation module with additional access restrictions
- Offline field mode with encrypted case packs and controlled sync
- Aggregate analytics with privacy-preserving small-cell suppression
- Public report intake with LGU routing
- TOTP-based MFA for privileged roles
- Nonce-based Content Security Policy
- File quarantine architecture with configurable scanner integration
- Production Docker deployment with nginx reverse proxy

### Known Limitations

- Malware scanner integration point exists but no scanner driver configured (files stay in quarantine until admin-cleared)
- No automated bulk session invalidation UI (available via database)
- No code coverage metrics (test count/assertion coverage documented)
- Disposition execution disabled pending retention schedule approval
- Cross-LGU handover disabled pending policy validation
- Provider portal (referral-scoped external access) not yet implemented

---

**Prepared by:** Technical Team
**Date:** 2026-10-12

## 10. Final integrated product structure (2026-10-12)

- Central Office enters through the **CEFMU National Management Dashboard**. Administration is a separate navigation group and does not grant protected case-file access.
- Case managers enter through **My Work Dashboard**, with navigation grouped as Overview, Casework, Coordination & Services, Reports & Analytics, and Field Work.
- CPU members enter through **My Referred Cases** and can open only active, time-bound, case-specific MDT assignments.
- The Case Workspace follows the helping process: Overview; Client & Intake; Safety; Comprehensive Assessment; Child Participation; Plan & Services; MDT / CPU; Review & Conference; Records & Closure.
- Demo and automated-test data now use normalized LGU units, effective user assignments, case LGU identifiers, and an explicit CPU case grant. Free-text location data alone remains insufficient for authorization.

## 11. Final acceptance evidence (2026-10-12)

- Backend regression: **229 tests passed, 1,087 assertions** with positive and negative authorization, workflow, security, privacy, records, MDT, and offline-sync coverage.
- Frontend verification: TypeScript checking and the Vite production build complete successfully.
- Role journeys: Central Office Admin, Case Manager, and CPU Member flows pass on desktop and mobile Chromium, including automated serious/critical axe checks on the protected workspaces.
- Responsive matrix: authenticated dashboard, case list, and integrated case workspace pass at **360, 390, 768, and 1440 px** without document-level horizontal overflow.
- Offline browser acceptance: encrypted authorized packs are stored in IndexedDB, and expired packs lock case details while preserving the encrypted recovery queue.
- General smoke acceptance: login, invalid credentials, public dashboard, authenticated worker flow, overflow, and public accessibility checks pass on desktop and Pixel 7 profiles.
- Browser diagnostics use traces and screenshots without requiring Playwright's optional ffmpeg binary, keeping the acceptance suite runnable in restricted/offline build environments.

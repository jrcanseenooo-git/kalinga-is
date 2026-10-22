# Priority 3 - Pre-Production Readiness Gap Matrix

**System:** Kalinga CEFMU Case Management Information System  
**Inspection date:** 2026-10-02  
**Baseline:** 151 tests / 761 assertions - all passing  
**Scope:** Read-only repository inspection; no code changes made  

---

## Classification Key

| Status | Meaning |
|--------|---------|
| **READY** | Implementation verified, no defect found |
| **READY WITH POLICY VALIDATION** | Technical implementation is sound; requires organizational policy, manual procedure, or owner sign-off before production |
| **REMEDIATION REQUIRED** | Technical defect or gap that must be fixed before production |
| **BLOCKED** | Cannot proceed without external dependency or owner decision |

---

## Summary

| # | Review Area | Classification |
|---|-------------|---------------|
| 1 | Authentication | **REMEDIATION REQUIRED** |
| 2 | Authorization / IDOR | **READY** |
| 3 | Sensitive-Data Leakage | **REMEDIATION REQUIRED** |
| 4 | File Upload Security | **REMEDIATION REQUIRED** |
| 5 | CSRF / XSS | **READY WITH POLICY VALIDATION** |
| 6 | Database Integrity | **READY** |
| 7 | Backup / Restore | **REMEDIATION REQUIRED** |
| 8 | Audit Governance | **READY WITH POLICY VALIDATION** |
| 9 | Logging / Error Handling | **READY WITH POLICY VALIDATION** |
| 10 | Dependencies / Secrets | **READY WITH POLICY VALIDATION** |
| 11 | Deployment Readiness | **REMEDIATION REQUIRED** |
| 12 | PIA / DPIA | **READY WITH POLICY VALIDATION** |
| 13 | Data Subject Access (DSA) | **READY WITH POLICY VALIDATION** |
| 14 | Incident Response | **READY WITH POLICY VALIDATION** |
| 15 | Mobile UAT | **READY WITH POLICY VALIDATION** |
| 16 | Accessibility | **READY WITH POLICY VALIDATION** |
| 17 | Release Evidence | **READY WITH POLICY VALIDATION** |
| 18 | Overall Readiness | **REMEDIATION REQUIRED** - 5 areas need technical fixes; 8 areas need policy validation |

---

## Detailed Findings

### 1. Authentication - REMEDIATION REQUIRED

**Verified implementation:**
- Session-based auth via Laravel Eloquent provider, database session driver
- Login POST throttled at 5 attempts/minute
- Registration gated behind `config('kalinga.registration_enabled')` - defaults `false` in production
- Bcrypt rounds: 12
- Session lifetime: 120 minutes
- Midnight idle logout enforced (Asia/Manila, 5-minute grace)
- Active-user check on every request; deactivated users force-logged-out
- Session regenerated on login
- Password reset tokens: 60-minute expiry, 60-second throttle

**Defects requiring remediation:**

| ID | Finding | Severity | Detail |
|----|---------|----------|--------|
| AUTH-1 | No MFA/2FA | High | No TOTP, SMS, or backup-code implementation. Government system handling sensitive child-protection data should require MFA for privileged roles at minimum. |
| AUTH-2 | No password complexity policy | High | `Password::defaults()` is never customized in any service provider. Effective policy is only `min(8)` - no mixed case, digits, symbols, or breach-list check. |
| AUTH-3 | Registration auto-login with no role/approval | Medium | If registration is accidentally enabled in production, new users get immediate dashboard access with no role assignment and no admin approval gate. `active` column defaults to `null` (not `false`), so `EnsureActiveUser` does not block them. |
| AUTH-4 | `.env.example` sets `SESSION_ENCRYPT=false` | Medium | Session data stored unencrypted in database. Sensitive for a case-management system. `render.yaml` correctly sets `true` for Render, but any non-Render deployment copying `.env.example` gets plaintext sessions. |
| AUTH-5 | `SESSION_SECURE_COOKIE` has no default | Medium | `config/session.php` line 172: `env('SESSION_SECURE_COOKIE')` with no fallback. If the env var is unset, cookies may transmit over plain HTTP. Should default to `true`. |
| AUTH-6 | Password confirmation timeout: 3 hours | Low | `config/auth.php` line 113: 10800 seconds. Generous for sensitive operations. Consider 1 hour. |

**Owner-review flags:**
- MFA scope decision: all users, or privileged roles only (system_admin, cpo, lgu_supervisor, fo_user, data_custodian)?
- Registration: should new users default to `active = false` and require admin activation?

---

### 2. Authorization / IDOR - READY

**Verified implementation:**
- Server-side RBAC: 15 roles, 60+ permissions defined in `RolePermissions` service
- All permissions registered as Laravel Gates in `AppServiceProvider`
- `CaseAccessScope.scopeCases()`: field workers → assigned cases; CPO/supervisor → LGU-scoped; FO → province-scoped; default → no access
- `CaseAccessScope.allowsCase()` mirrors scope logic for single-record checks
- `LguAssignmentScope` for normalized PSGC-backed LGU boundaries
- MDT access: case-specific grants with category scoping, time windows, LGU matching, revocation
- Reviewer separation enforced across assessments, child participation, case plans, closure
- Identity merge: jurisdiction-scoped, cross-jurisdiction blocked, stale-state protection
- Records governance: legal holds block disposition, proposal → review → approve chain (different users enforced)
- Every controller method calls `authorize()`, `abort_unless()`, or scope-checks before data access
- No IDOR vulnerabilities found - record IDs are validated against user jurisdiction and permission scope
- `system_admin` deliberately excluded from `cases.view` - no standing case-file access

**No defects found.**

---

### 3. Sensitive-Data Leakage - REMEDIATION REQUIRED

**Verified implementation:**
- Case narratives gated behind scoped endpoints; `show()` exposes metadata only
- Child participation redacted for unauthorized users (`participation.restricted_view`)
- Management dashboard: aggregate-only, `MINIMUM_CELL = 5` suppression
- Public dashboard: aggregate-only, `PUBLIC_MINIMUM = 5` suppression
- Social Case Management Report: restricted content excluded by default
- Search: returns safe columns only, field workers scoped to assigned cases
- Notification previews: safe metadata (title, body, action_url)
- No `v-html` in any Vue template - Vue auto-escapes all interpolation
- CPU chat case references don't grant case-file access
- MDT workspace: category-scoped data only, no Health/SRH/Child Participation/assessments/identity exposure

**Defects requiring remediation:**

| ID | Finding | Severity | Detail |
|----|---------|----------|--------|
| LEAK-1 | `HandleInertiaRequests` shares full User model | Medium | `$request->user()` serializes the entire User Eloquent model to every Inertia page. Hidden fields are `password` and `remember_token` only. All other columns (`email`, `province`, `municipality`, `role`, `active`, `email_verified_at`, `access_revoked_at`, timestamps) are exposed to the frontend on every page load. Should use a restricted DTO/resource. |
| LEAK-2 | `getClientOriginalName()` stored in database | Low | Both `CaseArtifactController` and `MdtCollaborationController` store the user-supplied original filename. Not an XSS risk (Vue escapes), but could leak filesystem metadata. Consider sanitizing before storage. |

**Owner-review flags:**
- Confirm which User fields the frontend legitimately needs (likely: id, name, role, active, permissions).

---

### 4. File Upload Security - REMEDIATION REQUIRED

**Verified implementation:**
- MIME validation: PDF, JPEG, PNG, DOCX enforced server-side
- Size limit: 10 MB
- Hashed filenames via `$file->store()` - original name not used in filesystem path
- Private storage: `storage/app/private` (not publicly accessible)
- Quarantine architecture for case artifacts: `cases/$case/quarantine` path, `scan_state=pending_scan`, governance overlay created
- Download authorization: scope check + classification check + scan gate
- Upload throttled at 12/minute
- No executable file types accepted

**Defects requiring remediation:**

| ID | Finding | Severity | Detail |
|----|---------|----------|--------|
| FILE-1 | MDT attachments skip quarantine/scan workflow | Medium | `MdtCollaborationController` stores files directly without creating a `record_governance` entry, quarantine path, or `scan_state`. Inconsistent with the case-artifact security model. |
| FILE-2 | No actual malware scanner configured | Medium | `scan_state` architecture exists but no scanner integration (ClamAV, cloud API, etc.) is implemented. Files remain in `pending_scan` indefinitely. |
| FILE-3 | Legacy bypass on downloads | Low | Downloads are allowed when `scan_state` is `pending_scan` AND `legacy_record = true`. This is a deliberate design for pre-existing documents, but should be documented and time-bounded. |

**Owner-review flags:**
- Scanner decision: ClamAV sidecar in Docker, cloud scanning API, or deferred to Render add-on?
- Should MDT attachments follow the same quarantine pipeline as case documents?

---

### 5. CSRF / XSS - READY WITH POLICY VALIDATION

**Verified implementation:**
- Laravel's default CSRF middleware active; no exemptions configured
- Inertia.js handles CSRF token injection automatically
- SameSite=lax on session cookie
- HttpOnly=true on session cookie
- No `v-html` usage in any Vue template (zero occurrences across all 35 Vue pages)
- Vue 3 auto-escapes all template interpolation
- X-Content-Type-Options: nosniff
- X-Frame-Options: DENY

**Hardening gap (not a vulnerability):**

| ID | Finding | Severity | Detail |
|----|---------|----------|--------|
| CSP-1 | CSP uses `unsafe-inline` for both `script-src` and `style-src` | Low | `SecurityHeaders.php` line 26. Required by Vite's development injection but weakens XSS defense in production. Consider nonce-based CSP for production builds. |

**Policy validation needed:**
- Confirm whether nonce-based CSP is feasible with the Vite/Inertia production build pipeline.

---

### 6. Database Integrity - READY

**Verified implementation:**
- PostgreSQL with foreign key constraints on all major relationships
- Unique constraints: `case_number`, `reference_no`, `psgc_code`, `offline_id`
- Transactions used for multi-step operations (case creation, offline sync, identity merge, disposition chain, user updates)
- Lock-for-update (`lockForUpdate`) on case creation from reports to prevent duplicates
- Stale-state protection with client signatures on identity merge
- Candidate decisions frozen after merge proposal
- `offline_version` column with atomic increment for conflict detection
- 38 migrations with `down()` methods for reversibility
- `deleted_at` soft-delete pattern where appropriate

**Minor optimization opportunities (not defects):**

| ID | Finding | Detail |
|----|---------|--------|
| DB-1 | Missing composite index | `cases(status, lgu_id)` - would improve dashboard queries |
| DB-2 | Missing index | `cases.category` - would improve filtered queries |
| DB-3 | Missing composite index | `legal_holds(case_id, released_at)` - would improve active-hold checks |

**No blocking defects found.**

---

### 7. Backup / Restore - REMEDIATION REQUIRED

**Verified implementation:**
- PostgreSQL database (supports `pg_dump`/`pg_restore`)
- Render managed Postgres: automatic daily backups included in paid tier
- File storage default: local disk (`storage/app/private`)
- S3/R2 credentials in `.env.example` suggest cloud storage is planned

**Defects requiring remediation:**

| ID | Finding | Severity | Detail |
|----|---------|----------|--------|
| BACKUP-1 | No file backup strategy | High | Uploaded case documents stored on local container disk. Container restarts lose all uploaded files. Must use S3/R2 or equivalent persistent storage for production. |
| BACKUP-2 | No backup verification procedure | Medium | No documented restore-test process. |
| BACKUP-3 | No application-level backup script | Low | No `artisan` command or script for coordinated DB + file backup. |

**Owner-review flags:**
- S3/R2 bucket decision: Cloudflare R2 (configured in render.yaml as env vars) vs AWS S3?
- Backup retention period policy needed.

---

### 8. Audit Governance - READY WITH POLICY VALIDATION

**Verified implementation:**
- `audit_logs` table used comprehensively throughout the application
- Audited operations include: user access changes, case transitions, offline sync, document access/download, governance actions (classification, disposition, legal holds, disclosure), CPU chat messages/captures, MDT operations, admin changes, management exports, social report generation
- Audit entries capture: `user_id`, `event`, `outcome`, `auditable_type`, `auditable_id`, `changes` (JSON), `created_at`
- Audit log viewer restricted to `audits.view` permission
- Management CSV exports audited with metadata-only event

**Policy validation needed:**

| ID | Finding | Detail |
|----|---------|--------|
| AUDIT-1 | No audit log tamper protection | Audit logs stored in same database as application data. A compromised database admin could alter audit records. Consider write-once storage or signed audit entries for production. |
| AUDIT-2 | No audit retention/rotation policy | No mechanism to archive or rotate old audit logs. |
| AUDIT-3 | Audit log search/export for investigations | Viewer shows paginated list; no filtering by event type, user, date range, or case for investigative queries. |

---

### 9. Logging / Error Handling - READY WITH POLICY VALIDATION

**Verified implementation:**
- `render.yaml` sets `LOG_CHANNEL=stderr` (appropriate for container deployment)
- `APP_DEBUG=false` in production render.yaml
- Laravel exception handling active (no custom override found - uses framework defaults)
- No sensitive data in error responses (Inertia returns error pages, not stack traces, in production)

**Policy validation needed:**

| ID | Finding | Detail |
|----|---------|--------|
| LOG-1 | No error monitoring service | No Sentry, Bugsnag, or equivalent configured. Production errors will only appear in container stderr. |
| LOG-2 | No structured log format | Default Laravel logging; no JSON-structured logs for log aggregation. |
| LOG-3 | No alerting | No mechanism to notify the team of elevated error rates or critical failures. |

---

### 10. Dependencies / Secrets - READY WITH POLICY VALIDATION

**Verified implementation:**
- No hardcoded secrets in application source (confirmed via grep)
- `.env.example` comprehensive and well-annotated
- `APP_KEY` auto-generated via render.yaml generateValue
- S3/R2 credentials marked `sync: false` (manual entry required)
- Admin provisioning via env vars (acceptable for initial setup)
- Docker build uses `composer install --no-dev` (no dev dependencies in production image)

**Policy validation needed:**

| ID | Finding | Detail |
|----|---------|--------|
| DEP-1 | No dependency audit | `composer audit` and `npm audit` should be run and documented before production. |
| DEP-2 | No automated dependency update process | No Dependabot, Renovate, or equivalent configured. |
| DEP-3 | `.env.example` has `REGISTRATION_ENABLED=true` | Copying to `.env` without changing enables open registration. Render.yaml does not explicitly set this to `false`. |

---

### 11. Deployment Readiness - REMEDIATION REQUIRED

**Verified implementation:**
- Three-stage Dockerfile: Composer, Node/Vite, PHP runtime (php:8.3-cli-alpine)
- `docker-compose.yml` for local development (app + Postgres 18)
- `render.yaml` Blueprint: Docker web service + managed Postgres (Singapore region)
- Health check at `/up` registered in `bootstrap/app.php`
- `docker/start.sh`: migrate, seed reference data, provision admin, cache config/routes/views, serve
- Deployment documentation: `docs/07-installation/RENDER-DEPLOYMENT.md`, `LOCAL-SETUP.md`
- Production env vars in render.yaml: `APP_DEBUG=false`, `SESSION_ENCRYPT=true`, `LOG_CHANNEL=stderr`

**Defects requiring remediation:**

| ID | Finding | Severity | Detail |
|----|---------|----------|--------|
| DEPLOY-1 | `artisan serve` used in production | High | `docker/start.sh` uses PHP's built-in development server. Not suitable for production traffic. Needs Nginx + PHP-FPM, FrankenPHP, or RoadRunner. |
| DEPLOY-2 | No mail provider configured | Medium | `MAIL_MAILER` defaults to `log`. Password resets, email verification, and notifications will silently fail. |
| DEPLOY-3 | No queue worker process | Low | `QUEUE_CONNECTION=sync` in render.yaml (all jobs inline). Acceptable for initial deployment but limits scalability. |
| DEPLOY-4 | No `APP_URL` in render.yaml | Low | Must be set manually after Render assigns the URL. |

**Owner-review flags:**
- Web server decision: FrankenPHP (single binary, simpler) vs Nginx + PHP-FPM (traditional)?
- Mail provider: Resend, Mailgun, or AWS SES?

---

### 12. PIA / DPIA - READY WITH POLICY VALIDATION

**Verified implementation:**
- Privacy-by-design patterns throughout: minimum-necessary data access, jurisdiction scoping, restricted sections, aggregate suppression thresholds
- Privacy notice workflow: `privacy_notice_provided_at`, `privacy_notice_method` tracked per case
- Consent management: active consent with `sharing_allowed` verified before referrals
- Offline data: encrypted IndexedDB, authorized case packs with expiry, device revocation
- Data flow documentation: `docs/03-architecture/privacy-data-flow.md`
- Privacy access model: `docs/03-architecture/privacy-access-model.md`

**Policy validation needed:**

| ID | Finding | Detail |
|----|---------|--------|
| PIA-1 | No formal PIA/DPIA document | Technical safeguards are in place, but a formal Privacy Impact Assessment document is required for DPA/NPC compliance. This is a policy document, not a technical artifact. |
| PIA-2 | Data retention periods not defined | `RecordsGovernance` has lifecycle states and disposition workflow, but specific retention periods (e.g., 15 years for child welfare records) must come from DSWD/legal guidance. |

---

### 13. Data Subject Access (DSA) - READY WITH POLICY VALIDATION

**Verified implementation:**
- Case data is accessible through standard case-view endpoints (authorized users only)
- Audit trail provides investigation capability
- Export capability exists for management reports (CSV)
- Social Case Management Report generates per-case summary

**Policy validation needed:**

| ID | Finding | Detail |
|----|---------|--------|
| DSA-1 | No automated DSA request workflow | No self-service data access request mechanism for data subjects (clients/children/guardians). This is common in government case-management systems - DSA requests are typically handled through formal channels, not self-service. Confirm with DSWD/DPO whether a system workflow is required. |
| DSA-2 | No data portability export | No mechanism to export a subject's complete data in a portable format. May be required under DPA. |

---

### 14. Incident Response - READY WITH POLICY VALIDATION

**Verified implementation:**
- Audit logs provide investigation capability
- Session invalidation available (forced logout on deactivation)
- Offline device revocation on user deactivation
- User deactivation with `access_revoked_at` timestamp
- Midnight idle logout enforcement
- CORS origin allowlist enforcement

**Policy validation needed:**

| ID | Finding | Detail |
|----|---------|--------|
| IR-1 | No documented incident response plan | Need: notification procedures, escalation matrix, evidence preservation steps, NPC breach notification timeline (72 hours under DPA). |
| IR-2 | No bulk session invalidation | No mechanism to force-logout all users or invalidate all sessions during a security incident beyond individual user deactivation. |

---

### 15. Mobile UAT - READY WITH POLICY VALIDATION

**Verified implementation:**
- Mobile-first design approach mandated in AGENTS.md
- Tailwind CSS with responsive utilities
- Presentation checklist exists: `docs/09-testing-uat/PRESENTATION-CHECKLIST.md`
- UAT core checklist exists: `docs/09-testing-uat/UAT-CORE.md`
- Offline workspace designed for mobile field use

**Policy validation needed:**

| ID | Finding | Detail |
|----|---------|--------|
| UAT-1 | No automated mobile testing | No Playwright/Cypress mobile viewport tests. Manual testing required. |
| UAT-2 | UAT execution pending | Checklists exist but execution with field workers/CPOs has not been documented. |

---

### 16. Accessibility - READY WITH POLICY VALIDATION

**Verified implementation:**
- Tailwind CSS used (generally supports accessible patterns)
- Semantic HTML structure via Vue component architecture
- No accessibility-breaking patterns found (no `v-html`, proper form structure)

**Policy validation needed:**

| ID | Finding | Detail |
|----|---------|--------|
| A11Y-1 | No formal accessibility audit | No WCAG 2.1 AA compliance review performed. Proportional review recommended for government system. |
| A11Y-2 | No screen reader testing | No evidence of assistive technology testing. |

---

### 17. Release Evidence - READY WITH POLICY VALIDATION

**Verified implementation:**
- 151 tests / 761 assertions - all passing
- Test coverage spans: authentication, casework flow, risk assessment, comprehensive assessment, child participation, MDT collaboration, case conference, case plan lifecycle, records governance, identity integrity, LGU scope/CPU coordination, role authorization, jurisdiction/account access, offline intake sync, offline sync conflict
- Negative tests: unauthorized access, invalid transitions, scope violations
- Architecture documentation: 35 docs across 10 directories

**Policy validation needed:**

| ID | Finding | Detail |
|----|---------|--------|
| REL-1 | No code coverage metrics | PHPUnit coverage not configured. Consider adding for release evidence. |
| REL-2 | No release notes template | No changelog or release notes structure. |
| REL-3 | No sign-off procedure | No formal acceptance sign-off document template. |

---

## Remediation Priority Order

Based on severity and dependency analysis, recommended remediation sequence:

### Phase 1 - Security-Critical (before any deployment) - COMPLETE

1. **AUTH-2**: Strengthen password policy - add `Password::defaults()` configuration in `AppServiceProvider` with mixed case, numbers, symbols, and breach-list check
2. **AUTH-5**: Set `SESSION_SECURE_COOKIE` default to `true` in `config/session.php`
3. **AUTH-4**: Set `SESSION_ENCRYPT` default to `true` in `.env.example`
4. **LEAK-1**: Replace raw User model in `HandleInertiaRequests` with restricted DTO
5. **AUTH-3**: Default new users to `active = false`; require admin activation
6. **DEPLOY-1**: Replace `artisan serve` with production web server (FrankenPHP or Nginx+PHP-FPM)

### Phase 2 - Security-Important (before production traffic) - COMPLETE

7. **BACKUP-1**: Configure S3/R2 for persistent file storage
8. **FILE-1**: Route MDT attachments through quarantine/governance pipeline
9. **AUTH-1**: Implement MFA for privileged roles (scope requires owner decision)
10. **DEPLOY-2**: Configure mail provider
11. **DEP-3**: Ensure `REGISTRATION_ENABLED=false` in all production env templates

### Phase 3 - Hardening (recommended, not blocking) - COMPLETE

12. **FILE-2**: Integrate malware scanner
13. **CSP-1**: Implement nonce-based CSP
14. **DB-1/2/3**: Add performance indexes
15. **DEP-1**: Run and document `composer audit` + `npm audit`
16. **DEPLOY-4**: Document APP_URL configuration

### Phase 4 - Policy Documents (parallel with remediation) - COMPLETE

17. **PIA-1**: Formal PIA/DPIA document → `docs/05-governance/PRIVACY-IMPACT-ASSESSMENT.md`
18. **IR-1**: Incident response plan → `docs/05-governance/INCIDENT-RESPONSE-PLAN.md`
19. **AUDIT-1/2**: Audit integrity and retention policy → `docs/05-governance/AUDIT-RETENTION-POLICY.md`
20. **REL-1/2/3**: Release evidence package → `docs/05-governance/RELEASE-EVIDENCE-PACKAGE.md`

---

## What Is NOT in Scope for Remediation

Per owner instruction, the following are explicitly out of scope:

- New product features or modules
- GIDA/offline expansion
- AI risk scores or automated case decisions
- Automatic identity merge
- Automatic record purge/destruction
- GitHub remote creation or deployment to Render
- Changes to the reference project at `dswd-kalinga`

---

## Test Baseline Snapshot

```
Pre-remediation:  151 passed (761 assertions)
Post-remediation: 159 passed (779 assertions)  ← all phases complete
Engine:           PHPUnit (Laravel 12 / PHP 8.3)
Database:         SQLite in-memory (test) / PostgreSQL (production)
```

All pre-existing tests continued to pass through remediation. 8 new tests and 18 new assertions were added for MFA, password policy, and registration hardening.

# Privacy Impact Assessment - Kalinga CEFMU Case Management Information System

**Status:** DRAFT - Requires owner/DPO review and approval before production use.
**Date:** 2026-10-02 (updated 2026-10-22 for Releases 1 to 3)
**System:** Kalinga IS v1.0 (pre-production)
**Data Controller:** DSWD Field Office / LGU Social Welfare Office (to be confirmed)
**DPO Contact:** [To be designated]

---

## 1. System Description

Kalinga IS is a web-based case management information system for child, early and forced marriage and unions (CEFMU) cases under DSWD Project Kalinga. It supports intake, assessment, planning, intervention, referral, monitoring, closure and reporting. The pilot covers CEFMU cases handled by the C/MSWDO of the pilot LGUs.

**Deployment:** Cloud-hosted (Render) with PostgreSQL database, S3-compatible object storage, HTTPS-only access.
**Users (4 roles):** social workers (C/MSWDO case managers, who also review each other's plans and closures), Field Office users, CPU members (access only to a referred case while the referral is open), and system administrators (no access to case records).
**Subjects:** Children, families, alleged perpetrators, witnesses, and other persons involved in CEFMU cases.

---

## 2. Data Inventory

### 2.1 Personal Data Collected

| Category | Data Elements | Lawful Basis | Retention |
|----------|--------------|--------------|-----------|
| Client identity | Name, alias, birth date, sex, address, contact | Mandate / legal obligation | Per approved retention schedule |
| Family/household | Household composition, relationships, guardian details | Mandate / legal obligation | Per approved retention schedule |
| Case narrative | Intake details, assessment findings, safety triage, monitoring notes | Mandate / legal obligation | Per approved retention schedule |
| Health/SRH | Health conditions, sexual/reproductive health data | Mandate / legal obligation | Per approved retention schedule |
| Child participation | Child's views, quotations, best-interest considerations | Mandate / consent where applicable | Per approved retention schedule |
| Legal/evidentiary | Legal documents, court records, evidence files | Mandate / legal obligation | Per approved retention schedule |
| Referral data | Referral basis, consent status, provider details | Mandate / consent | Per approved retention schedule |
| User accounts | Name, email, role, MFA status, password hash | Contractual / legitimate interest | Account lifecycle |
| Audit trail | Event type, actor, timestamp, record reference | Legal obligation / legitimate interest | Per audit retention policy |

### 2.2 Sensitive Personal Information (SPI)

Per Republic Act 10173 (Data Privacy Act of 2012), the system processes SPI including:
- Information about children (minors)
- Health and sexual/reproductive health data
- Information about alleged offenses
- Case assessments and safety determinations

### 2.3 Data Flow Summary

1. **Collection:** Public report intake (anonymous or identified) -> screening by authorized worker -> case creation with client records.
2. **Processing:** Assessment, planning, intervention, monitoring, referral - all within authenticated, authorized, jurisdiction-scoped sessions.
3. **Storage:** PostgreSQL database (encrypted at rest via provider). Documents on S3-compatible storage (private, no public URLs).
4. **Offline processing:** Encrypted IndexedDB packs (AES-GCM, device-only passphrase) for social workers with their own assigned cases only; packs expire after 72 hours and devices can be revoked. No full registry on device.
5. **Sharing:** Referrals with recorded lawful basis. Disclosure ledger for external requests. No automatic provider access.
6. **Reporting:** Aggregate-only analytics with small-cell suppression (minimum group size: 5). No case-level PII in reports or exports.
7. **Disposal:** No automatic purge or destruction. Retention review is human-initiated. Disposition execution is currently disabled.

---

## 3. Privacy Risks and Mitigations

### 3.1 Unauthorized Access

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Unauthorized case access | Medium | High | Server-side RBAC with 4 roles and permission gates. CaseAccessScope enforces assignment, LGU area and referral-grant boundaries. Every case route is tested against outside roles (CaseRouteAccessSweepTest). UI hiding is never authorization. |
| Privilege escalation | Low | Critical | Gates defined server-side in AppServiceProvider. No client-side permission bypass possible. Password policy enforced (min 10 chars, mixed case, numbers, symbols, breach check). |
| Session hijacking | Low | High | Encrypted sessions, secure cookies, HTTPS-only, HSTS, CSRF protection, CSP with nonce-based scripts. |
| Unauthorized MFA bypass | Low | High | Second factor (authenticator app or emailed code) required for all four roles (MFA_ROLES; CPU members included pending owner review). Five wrong codes end the session; 10 failed sign-ins in 15 minutes lock the account. Recovery codes are encrypted at rest and compared in constant time. |

### 3.2 Data Exposure

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| PII in analytics/reports | Low | High | Aggregate-only endpoints with small-cell suppression. CSV exports audited and aggregate-only. Printed case reports show initials and masked contact numbers by default, withhold health, reproductive health and mental health content, restricted assessment sections and MDT notes, and are audited (case_report.generated). |
| Sensitive data in logs/URLs | Low | Medium | No case narrative in audit payloads, URL parameters, console output, or error messages. Restricted document metadata redacted for unauthorized users. |
| Offline data exposure | Medium | High | AES-GCM encrypted IndexedDB. Authorized cases only. No full registry on device. Sync queue with explicit status tracking. |
| Document content exposure | Low | High | File quarantine (pending_scan -> cleared). Private storage paths. No public URLs. Download via controlled application response with audit. |

### 3.3 Data Integrity

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Unauthorized case modification | Low | High | Server-side validation. Workflow state transitions allowlisted. Database transactions for multi-step operations. Audit trail. |
| Sync conflicts | Medium | Medium | Idempotent sync operations. Conflict detection with case-version checks. Failed/conflict states tracked. |
| Audit tampering | Medium | High | Audit logs are append-only: a PostgreSQL trigger refuses UPDATE, DELETE and TRUNCATE. Sign-ins, failures, lockouts, sign-outs, password resets and case views are audited with the client IP address. **Residual risk:** the application database account could drop the trigger; use a separate migration account in production. |

### 3.4 Data Minimization

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Excessive data collection | Low | Medium | Progressive disclosure UI. Need-to-know access model. Offline packs exclude documents and unnecessary contact details. |
| Excessive retention | Medium | Medium | Records governance with lifecycle states and retention review dates. **Requires:** Approved retention schedule with specific periods. |

---

## 4. Data Subject Rights

### 4.1 Current Implementation

| Right | Implementation Status |
|-------|----------------------|
| Access | The assigned social worker or Field Office user can locate records within their scope and print the case reports. No self-service portal for data subjects. |
| Correction | Authorized case workers can update records within case scope. Audit trail preserves change history. |
| Erasure | Not automatically available. Records governance supports retention review but disposition execution is disabled. Legal holds block disposal. |
| Portability | No automated export of individual data subject records. |
| Object | No automated processing decisions exist. No AI risk scores or automatic case decisions. |
| Be informed | Privacy notice responsibility rests with the data controller (DSWD/LGU). |

### 4.2 Gaps Requiring Policy Decision

- Formal data subject access request (DSAR) procedure and response timeline
- Identity verification procedure for data subjects requesting access
- Process for handling access requests involving minors (guardian/legal representative)
- Portability format and scope (which fields, exclusions for third-party data)
- Criteria for lawful refusal of erasure (ongoing legal proceedings, retention obligations)

---

## 5. Third-Party Processing

| Processor | Purpose | Data Shared | Safeguards |
|-----------|---------|-------------|------------|
| Render (hosting) | Application hosting | All application data | Encrypted at rest, HTTPS in transit, managed PostgreSQL |
| S3-compatible storage | Document storage | Uploaded case documents | Private bucket, no public access, server-side encryption |
| Mail provider | Notifications | Email addresses, notification content | TLS in transit. **Requires:** Data processing agreement. |

---

## 6. Cross-Border Transfer

**Current status:** Application data stored on Render infrastructure. Region configuration (Singapore) should be validated against NPC requirements for data localization. Owner must confirm whether cross-border transfer rules under the DPA apply and whether supplementary measures are needed.

---

## 7. Breach Notification

Per Republic Act 10173 and NPC Circular 16-03:
- Personal data breach must be reported to the NPC within 72 hours of discovery.
- Affected data subjects must be notified when the breach involves sensitive personal information or is likely to cause serious harm.
- See the Incident Response Plan for operational procedures.

---

## 8. Owner/DPO Decisions Required Before Production

| # | Decision | Urgency |
|---|----------|---------|
| 1 | Designate Data Protection Officer | Critical |
| 2 | Approve official data retention schedule with specific periods | Critical |
| 3 | Confirm data controller designation (DSWD FO vs. LGU) | Critical |
| 4 | Approve document classification catalogue | High |
| 5 | Establish DSAR procedure and response timeline | High |
| 6 | Confirm cross-border transfer compliance | High |
| 7 | Execute data processing agreements with Render, storage, mail providers | High |
| 8 | Define consent management for referral data sharing | Medium |
| 9 | Approve malware scanning provider and operating procedure | Medium |
| 10 | Define managed-device enrollment and lost-device response for offline use | Medium |

---

## 9. Review Schedule

This PIA must be reviewed:
- Before production deployment
- After any significant change to data processing activities
- After any security incident
- At minimum annually

---

**Prepared by:** Technical Team
**Review required by:** Data Protection Officer, System Owner, DSWD Privacy Focal

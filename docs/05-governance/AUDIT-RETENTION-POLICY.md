# Audit and Records Retention Policy - Kalinga CEFMU Case Management Information System

**Status:** DRAFT - Requires owner/DPO approval of retention periods and disposal authority before production use.
**Date:** 2026-10-02
**System:** Kalinga IS v1.0 (pre-production)

---

## 1. Purpose

This policy defines the retention, protection, review, and eventual disposition of audit logs and case records within the Kalinga IS system. It supports compliance with the Data Privacy Act of 2012 (RA 10173) principle of proportionality and storage limitation, and establishes accountability for the records lifecycle.

---

## 2. Scope

This policy covers:
- Application audit logs (the `audit_logs` table)
- Case records and associated data (cases, clients, assessments, plans, referrals, notes, documents)
- Records governance metadata (the `record_governance` overlay)
- User account records
- Offline sync queue records
- Database backups

---

## 3. Audit Log Retention

### 3.1 What Is Logged

The audit system records security-relevant and accountability events including:
- Authentication events (login, logout, MFA verification, failed attempts)
- Account lifecycle (creation, activation, deactivation, role changes, MFA setup)
- Case lifecycle (creation, status changes, assignment, closure, reopening)
- Data access (case views, document downloads, export generation)
- Data modification (assessments, plans, referrals, notes, monitoring records)
- Records governance (classification changes, retention reviews, legal holds, disclosures)
- Offline operations (sync, conflicts, device registration)
- Administrative actions (configuration changes, bulk operations)

### 3.2 What Is NOT Logged

Audit entries record event metadata only. They must not contain:
- Case narrative content or assessment details
- Client names, addresses, or contact information in the changes payload
- Document file content or restricted document filenames
- Child participation narratives
- Disclosure narrative content (only count and record references)

### 3.3 Audit Log Retention Schedule

| Record Type | Minimum Retention | Rationale |
|-------------|-------------------|-----------|
| Security events (auth, MFA, account changes) | [OWNER DECISION: suggest 7 years] | Security investigation, compliance evidence |
| Case data access events | [OWNER DECISION: suggest matching case retention] | Accountability for data subject access |
| Case modification events | [OWNER DECISION: suggest matching case retention] | Data integrity and audit trail |
| Administrative events | [OWNER DECISION: suggest 7 years] | System governance |
| Export/disclosure events | [OWNER DECISION: suggest 10 years] | Compliance evidence for data sharing |

### 3.4 Audit Log Integrity

**Current safeguards:**
- Audit logs are append-only at the application level (no update or delete operations exposed)
- Audit entries include actor, timestamp, event type, and record reference
- Audit viewer displays metadata only, not raw change payloads

**Residual risk:** Audit logs reside in the same PostgreSQL database as application data. A compromised database administrator could alter records.

**Recommended enhancements (owner decision):**
- Write-once audit storage or log streaming to an external immutable store
- Periodic hash-chain verification of audit log integrity
- Database role separation so the application account cannot ALTER or TRUNCATE the audit_logs table

---

## 4. Case Records Retention

### 4.1 Retention Schedule

Specific retention periods must be set by the system owner based on DSWD policy, applicable laws, and NPC guidance. The following are suggested starting points for review:

| Record Category | Suggested Minimum Retention | Basis |
|----------------|----------------------------|-------|
| Child welfare case records | [OWNER DECISION: suggest 15 years after case closure or child reaching majority, whichever is later] | Child protection statutory requirements |
| CEFMU investigation records | [OWNER DECISION: suggest 15 years after case closure] | Legal proceedings, statute of limitations |
| Legal/evidentiary documents | [OWNER DECISION: suggest 15 years or duration of legal proceedings + 5 years] | Evidentiary preservation |
| Health/SRH records | [OWNER DECISION: per DOH/DSWD policy] | Health records regulation |
| Referral records | [OWNER DECISION: suggest matching case retention] | Accountability for data sharing |
| Aggregate/statistical reports | [OWNER DECISION: suggest indefinite for anonymized aggregates] | Program evaluation |
| User account records | [OWNER DECISION: suggest 5 years after account deactivation] | Accountability |

### 4.2 Lifecycle States

The records governance system supports these lifecycle states:

| State | Meaning | Transition Authority |
|-------|---------|---------------------|
| Active | Record in active use | Automatic on creation |
| Archived | Case closed; record retained per schedule | Authorized supervisor |
| Retention review | Retention period approaching; review required | System flag + authorized reviewer |
| Proposed for disposition | Reviewer recommends disposition | Authorized proposer |
| Approved for disposition | Separate authority approves | Authorized approver (different from proposer) |
| Disposed | Destruction or transfer complete | **Currently disabled - execution not implemented** |

### 4.3 Safety Controls

- No current workflow can physically delete a case record or transition it to Disposed
- Retention review date means "review required," never "delete now"
- Approved for Disposition means execution is disabled pending approved retention schedule, disposal authority, and method
- The same user cannot both propose and approve a disposition
- Active legal holds block any disposition proposal
- Closure does not delete case records, documents, assessments, or audits

---

## 5. Legal Holds

Legal holds prevent the disposition of records that may be relevant to legal proceedings, investigations, or regulatory inquiries.

| Aspect | Implementation |
|--------|---------------|
| Scope | Case-level (protects all governed records in the case) or record-level |
| Placement authority | Authorized supervisor, FO user, or data custodian within jurisdiction |
| Release authority | Same authorization level; different user from placer recommended |
| Effect | Blocks all disposition proposals for held records |
| Audit | Placement, release, and attempted disposition during hold are audited |
| Retention | Hold history is retained even after release |

---

## 6. Document Governance

### 6.1 Classification

Documents are classified upon upload. The configurable catalogue includes:
- General Case
- PII
- Sensitive Personal Information (SPI)
- Restricted
- Health/SRH
- Child Participation
- Legal/Evidentiary
- Administrative

Classification entries require owner/DPO policy validation before they are treated as approved (`policy_validated` flag).

### 6.2 Scan State

| State | Meaning |
|-------|---------|
| pending_scan | Newly uploaded; not available for download |
| cleared | Scan integration approved the file (or admin manually cleared) |
| file_missing | File not found at expected storage path |
| legacy_unverified | Pre-existing document not yet scanned; available under controlled compatibility |

### 6.3 Document Retention

Documents follow the same retention schedule as their parent case record. Individual document retention cannot be shorter than the case retention period unless explicitly approved by the DPO.

---

## 7. Database Backups

| Aspect | Policy |
|--------|--------|
| Frequency | [OWNER DECISION: suggest daily automated + before major changes] |
| Retention | [OWNER DECISION: suggest 90 days rolling + monthly archives for 1 year] |
| Encryption | Required at rest (provider-managed or customer-managed keys) |
| Testing | Backup restoration must be tested quarterly |
| Access | Backup access restricted to designated system administrators |
| Location | Consistent with data residency requirements (see PIA Section 6) |

---

## 8. Offline Data Retention

| Aspect | Policy |
|--------|--------|
| Offline pack duration | Device packs expire after configured period; expired packs must be re-authorized |
| Sync queue | Pending/failed items retained until successfully synced or manually resolved |
| Device decommission | All offline data must be cleared when a device is decommissioned or reassigned |
| Lost device | Revoke device authorization immediately; see Incident Response Plan |
| Browser storage | IndexedDB encrypted data only; no sensitive data in LocalStorage; cleanup on logout |

---

## 9. Disposition Procedures

**Disposition execution is currently disabled in the system.** Before enabling:

### 9.1 Prerequisites (Owner Decisions Required)

1. Approved retention schedule with specific periods per record category
2. Designated disposal authority (role and individual)
3. Approved destruction method (secure deletion, crypto-shredding, or physical media destruction)
4. Evidence preservation requirements (what metadata to retain after disposition)
5. DPO sign-off on the disposition procedure
6. Segregation of duties confirmed (proposer, reviewer, approver, executor are separate)

### 9.2 Disposition Workflow (When Enabled)

1. System flags records reaching retention review date
2. Authorized proposer reviews and proposes disposition with justification
3. Different authorized reviewer evaluates the proposal
4. Different authorized approver grants final approval
5. System verifies no active legal holds exist
6. Designated executor performs destruction using approved method
7. Disposition certificate generated with: record references, destruction method, date, executor, approver
8. Disposition certificate retained permanently

---

## 10. Policy Review

This policy must be reviewed:
- Before production deployment
- When retention requirements change (legislative, regulatory, or organizational)
- After any incident involving records integrity
- At minimum annually

---

**Prepared by:** Technical Team
**Approval required by:** System Owner, Data Protection Officer, DSWD Records Management

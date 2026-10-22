# Incident Response Plan - Kalinga CEFMU Case Management Information System

**Status:** DRAFT - Requires owner approval and contact designation before production use.
**Date:** 2026-10-02
**System:** Kalinga IS v1.0 (pre-production)

---

## 1. Purpose

This plan establishes procedures for detecting, responding to, containing, and recovering from security incidents affecting the Kalinga IS system. It ensures compliance with the Data Privacy Act of 2012 (RA 10173) requirement for timely breach notification.

---

## 2. Scope

This plan covers:
- Unauthorized access to the application, database, or hosting infrastructure
- Data breaches involving personal or sensitive personal information
- Compromise of user credentials or sessions
- Malware affecting uploaded documents
- Infrastructure outages affecting data integrity
- Offline device loss or compromise
- Insider misuse of access privileges

---

## 3. Incident Classification

| Severity | Description | Examples | Response Time |
|----------|------------|----------|---------------|
| **Critical** | Confirmed breach of SPI; active exploitation; data destruction | Database exfiltration; admin account compromise; ransomware | Immediate (within 1 hour) |
| **High** | Likely breach; unauthorized access confirmed; system integrity compromised | Unauthorized case access detected in audit; credential stuffing; upload of confirmed malware | Within 4 hours |
| **Medium** | Suspicious activity; potential vulnerability exploited | Failed MFA attempts above threshold; unusual export patterns; sync anomalies | Within 24 hours |
| **Low** | Minor policy violation; no data exposure confirmed | Single failed login; non-sensitive configuration issue | Within 72 hours |

---

## 4. Roles and Responsibilities

| Role | Responsibility | Contact |
|------|---------------|---------|
| **Incident Commander** | Overall response coordination, escalation decisions | [To be designated] |
| **System Administrator** | Technical investigation, containment, recovery | [To be designated] |
| **Data Protection Officer** | Breach assessment, NPC notification, data subject notification | [To be designated] |
| **System Owner** | Authorization for emergency actions, communication approval | [To be designated] |
| **DSWD Privacy Focal** | Organizational reporting, policy coordination | [To be designated] |

---

## 5. Detection

### 5.1 Automated Detection (Current Capabilities)

| Source | What It Detects |
|--------|----------------|
| Audit logs | Unauthorized access attempts, privilege escalation, unusual export activity, document access patterns |
| Application logging (stderr) | Application errors, authentication failures, validation rejections |
| Render platform monitoring | Infrastructure health, deployment failures, resource exhaustion |
| Session management | Concurrent session anomalies, expired session reuse attempts |

### 5.2 Manual Detection

- Periodic audit log review by data custodian or system administrator
- User-reported suspicious activity
- Field worker reporting device loss or compromise
- External notification (NPC, CERT-PH, third-party disclosure)

### 5.3 Detection Gaps to Address

- No real-time alerting on audit log patterns (requires monitoring integration)
- No automated brute-force/credential-stuffing detection beyond Laravel rate limiting
- No file integrity monitoring on stored documents
- No device attestation for offline-enabled devices

---

## 6. Response Procedures

### 6.1 Initial Assessment (First 1 Hour)

1. **Acknowledge** the incident report and assign an incident commander.
2. **Classify** severity using the table in Section 3.
3. **Determine scope:** Which data, users, cases, or systems are affected?
4. **Preserve evidence:** Do not modify or delete logs, sessions, or database records related to the incident. Note timestamps.
5. **Notify** the system owner and DPO if severity is High or Critical.

### 6.2 Containment

| Action | When to Use | How |
|--------|-------------|-----|
| Deactivate compromised user account | Confirmed credential compromise | Set `active = false` on the user record via admin panel |
| Force session invalidation | Session hijacking suspected | Clear the `sessions` database table for the affected user; if bulk invalidation needed, truncate the sessions table |
| Disable registration | Automated account creation attack | Set `REGISTRATION_ENABLED=false` (already default for production) |
| Quarantine uploaded files | Malware detected | Files already upload to quarantine path; block clearance |
| Revoke offline device authorization | Device lost or compromised | Remove device registration; affected offline packs become invalid on expiry |
| Restrict network access | Active exploitation from known source | Apply IP restrictions at Render/infrastructure level |
| Take application offline | Active data exfiltration; integrity compromise | Suspend Render service or set `APP_MAINTENANCE=true` |

### 6.3 Investigation

1. Review audit logs for the affected time period, filtering by:
   - User ID (if known)
   - Event type (login, case access, export, document download)
   - IP address and session information
   - Time range
2. Review application logs for errors, unusual requests, or exploitation patterns.
3. If database compromise is suspected, compare current data against most recent backup.
4. Document findings with timestamps, affected records, and evidence references.

### 6.4 Eradication and Recovery

1. Patch the vulnerability or close the attack vector.
2. Reset credentials for all affected accounts. Require MFA re-enrollment if MFA was compromised.
3. Restore from backup if data integrity was compromised (verify backup integrity first).
4. Re-scan quarantined documents if the scanning process was affected.
5. Verify containment actions are still in place and no re-entry has occurred.

### 6.5 NPC Breach Notification (Required Within 72 Hours)

Per NPC Circular 16-03, if the incident involves personal data and meets the notification threshold:

1. **DPO prepares** the NPC notification within 72 hours of becoming aware of the breach.
2. **Notification content:**
   - Nature of the breach
   - Personal data possibly involved (categories, approximate number of data subjects)
   - Likely consequences
   - Measures taken or proposed to address the breach
   - Measures taken to mitigate possible adverse effects
   - Contact information of the DPO
3. **Data subject notification** is required when the breach involves SPI or is likely to cause serious harm. Notification must include the nature of the breach and recommended protective measures.

### 6.6 Data Subject Notification

When required:
1. Notify affected data subjects without undue delay.
2. Use direct communication (not public posting) where possible.
3. Include: what happened, what data was involved, what they should do, and who to contact.
4. For cases involving minors, notify the guardian or legal representative.

---

## 7. Post-Incident

1. **Post-incident report:** Document the timeline, root cause, impact, response actions, and lessons learned.
2. **Update controls:** Implement preventive measures identified during the investigation.
3. **Update this plan** if gaps were identified in the response process.
4. **Retain incident records** per the audit retention policy.
5. **Conduct a review meeting** with the incident team within 2 weeks of resolution.

---

## 8. Emergency Actions Requiring Owner Authorization

The following actions require explicit system owner approval before execution:

- Truncating the sessions table (bulk session invalidation)
- Taking the application offline
- Restoring from backup (overwrites current data)
- Disclosing incident details to external parties beyond NPC
- Engaging external forensic investigators

---

## 9. Communication

| Audience | Channel | Approval Required |
|----------|---------|-------------------|
| NPC | Official NPC breach notification form | DPO |
| Affected data subjects | Direct written notification | DPO + System Owner |
| DSWD management | Internal reporting channel | System Owner |
| Technical team | Secure internal communication | Incident Commander |
| Public/media | Only if required by NPC or legal obligation | System Owner + DSWD Communications |

---

## 10. Testing and Maintenance

- This plan must be reviewed at minimum annually.
- A tabletop exercise should be conducted before production deployment.
- Contact information must be verified quarterly.
- Any structural change to the system (new data flows, new integrations, infrastructure migration) requires plan review.

---

**Prepared by:** Technical Team
**Approval required by:** System Owner, Data Protection Officer

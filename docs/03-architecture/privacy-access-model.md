# Privacy and access model

## Purpose

Kalinga IS holds child-protection case information. Access is enforced on the server with Laravel Gates, role permissions, jurisdiction scope, and object checks. A hidden navigation item is never an authorization control.

## Data classes and access

| Data class | Examples | Access model |
| --- | --- | --- |
| Public aggregate | Suppressed counts on the public dashboard | No personal data; cells below five are not shown. |
| Internal aggregate | Operational and quarterly totals | Casework roles receive only their permitted scope; aggregate-only roles receive groups of five or more. |
| PII | Name, birth date, contact details, address | Only casework roles with `cases.view` and a matching assignment/jurisdiction. |
| Sensitive case content | Safety triage, assessments, notes, consents, monitoring, referrals, documents | Same case scope plus the action permission. |
| Child Participation narrative | Child's views, quotations, participation context, best-interest consideration, feedback | Case scope plus `participation.restricted_view`; no automatic MDT access; unpermitted reads receive only a safe placeholder/operational metadata. |
| Audit metadata | Event, actor, time, record type and ID | `audits.view`; raw change payloads are not returned by the audit UI. |

## Canonical roles

`system_admin` administers accounts, configuration, audits and retention, but has no protected case-file permission. Case workers and social workers are limited to assigned cases. CPO and LGU supervisors are limited to their municipality. Field Office users are limited to their province. CPU monitors and analytics focals receive aggregate-only analytics with small-cell suppression. Data custodians receive audit and retention functions, not case files.

Referral and health-provider roles are reserved for a future referral-scoped portal. They are not granted case-list or case-file access in the current application. This is intentional until a provider-specific, minimum-necessary data-sharing flow is implemented and approved.

Child Participation is additionally restricted. Casework roles may manage and read it only within case scope; CPO, LGU supervisor, and Field Office roles may independently review/finalize a record they did not author. System administrators, aggregate roles, referral providers, and any future MDT role have no automatic Child Participation access.

## Enforcement sequence

1. Authenticate the active account.
2. Check the named action permission.
3. For protected cases, resolve the case and enforce assigned, municipal, or provincial scope.
4. Validate request data server-side and apply lifecycle-transition rules.
5. Write an audit event without putting sensitive field values into user-facing audit responses.

## Reporting, exports, and public views

Public reports are separate from case folders. Case workers can view only public submissions endorsed to their assigned municipality. A report becomes a protected case only after its allowed screening, validation, acceptance, and case-creation transitions.

Exports are aggregate-only and audited. Aggregate-only roles and public outputs suppress grouped results smaller than five. Casework roles retain scoped operational aggregates for their own work; exports still exclude client-level fields.

## Review items

The owner/DPO must approve the official retention schedule, lawful-basis wording, data-sharing agreements, and any future provider portal before activation. This document describes implemented controls, not a substitute for those approvals.

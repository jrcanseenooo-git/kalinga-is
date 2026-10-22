# Records Lifecycle and Document Governance

## Safety boundary

This release does **not** implement a purge scheduler, physical deletion, or a route that can transition a record to `Disposed`. A retention review date means **review required**, never delete now. `Approved for Disposition` means execution is disabled pending a formally approved retention schedule, disposal authority, and method.

Case archive (soft deletion) remains separate from retention disposition. Closure does not delete case records, documents, assessments, or audits.

## Canonical records and lifecycle overlay

`documents` remains the canonical case-file metadata and private storage reference. `record_governance` is a controlled overlay using only the aliases `case` and `document`; arbitrary class names and record types are rejected. It contains classification, retention-rule reference, review date, lifecycle state, and scan state.

Legacy documents are backfilled as `legacy_unverified`: they remain available under the existing authorized private-download flow and are never falsely labelled cleared. They are not automatically placed into version lineage. New uploads receive a UUID lineage only when explicitly started or linked to a selected prior document; filename similarity alone never creates lineage.

## Classification and restricted data

The configurable catalogue includes General Case, PII, SPI, Restricted, Health/SRH, Child Participation, Legal/Evidentiary, and Administrative. Catalogue entries begin `policy_validated = false` and must be visibly treated as **POLICY VALIDATION REQUIRED** until the owner/DPO approves them.

Restricted metadata is redacted for a case-scoped user without `records.restricted_view`: no document ID, filename, title, description, path, uploader notes, or classification detail is returned. Only `Restricted document` with minimal safe lifecycle/date metadata may be shown. Restricted files remain unavailable through direct download routes, search, analytics, exports, notifications, and overview props without the explicit permission.

## Scan boundary

New uploads are stored in a private quarantine path and created as `pending_scan`. They cannot be downloaded until a future approved scan integration changes state to `cleared`. This release provides no pretend malware scanner and no automatic scanner job. Failed or malicious future results must remain quarantined and audited. Legacy/unverified documents retain the controlled compatibility exception above; owner policy is required before rescanning/reclassification.

## Holds, retention and disposition

`legal_holds` supports a case scope (which protects every applicable governed record in that case at enforcement time) and explicit record scope through `legal_hold_records`. Placement and release require authorization and are audited; the original hold history is retained. An active hold blocks disposition proposals.

Retention rules are configurable but policy gated. Altering a rule cannot silently recalculate or dispose historic records. Human workflow is `Retention Review → Proposed → Authorized Review → Approved for Disposition (execution disabled)`. Proposal, review, approval, and any future execution are distinct roles/actions. The same ordinary worker cannot self-propose and self-approve a destructive outcome.

## Disclosure ledger

`record_disclosures` records recipient, organization, purpose, lawful authority, authorization, delivery method, and acknowledgement. `record_disclosure_items` references the exact record/version. It stores no copied child, health, or case narrative.

## Permissions and access

| Permission | Intended users | Scope |
|---|---|---|
| `records.classify` | Authorized casework roles | Assigned/municipal/provincial case scope |
| `records.restricted_view` | CPO, LGU supervisor, FO user | Case scope plus minimum necessary |
| `records.retention.review`, `records.hold.manage` | Authorized supervisor/FO/data custodian | Case scope; custodian jurisdiction policy remains owner validation |
| `records.disposition.propose/review/approve` | Authorized governance roles | Policy gated; execution unavailable |
| `records.disclosure.record` | Authorized governance roles | Case scope and exact record reference |

All downloads use a controlled application response and audit event. There are no public paths or permanent file URLs.

## Audit and offline

Audit events record action metadata only: classification change, restricted download, scan state, holds, retention review, disposition action, and disclosure count/reference. They must not contain filenames when restricted, file content, case narratives, or disclosure narratives. Documents remain excluded from Field Mode/offline packs. Offline document capture, encryption, revocation, scan handoff, and conflict recovery are future work.

## Owner/DPO policy decisions required

- official retention schedules and approved classifications;
- legal-hold/disposal authorities and segregation roles;
- approved destruction method/evidence and whether archived is a lifecycle state;
- malware scanning provider, operating procedure, and legacy reclassification plan;
- disclosure/DSA handling across LGUs and external recipients;
- data-custodian jurisdiction assignment model.

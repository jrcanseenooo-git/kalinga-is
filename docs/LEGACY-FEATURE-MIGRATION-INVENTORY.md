# Legacy Kalinga Feature Migration Inventory

Source reviewed: `C:\Users\Acer\OneDrive\Documents\github_projects\dswd-kalinga` on 2026-09-25.

This is a functional inventory for the Laravel + Vue + PostgreSQL replacement. It records legacy behaviour to migrate; it does not copy the old Apps Script, Google Sheets, Google Drive, password hashing, or transport design. The end-user **Data Dictionary** module is deliberately excluded, as requested. Reference data remains an internal administration and documentation concern.

## Module parity

| Legacy module or capability | New-system status | Required migration work |
| --- | --- | --- |
| Login, logout, password reset, session control | Partial | Keep Laravel authentication; add legacy user lifecycle controls: create, activate/deactivate, forced password reset, and scoped assignment. Do not carry forward legacy SHA-256 hashes. |
| Dashboard and personal work dashboard | Partial | Retain current dashboard and heatmap; add SLA attention counts, role/scoped workloads, due work, and legacy case-status metrics. |
| Reports and screening queue | Partial | Split report submission, queue, detail/review, duplicate outcome, validation, acceptance, rejection, referral-out, and appeal/re-open actions. Preserve each lifecycle timestamp. |
| General Intake / Case registration | Partial | Existing form contains the main fields; complete duplicate scoring, full reference choices, submission validation, geolocation metadata, and role/coverage checks. |
| Case registry, detail and lifecycle | Partial | Keep folders and soft archive; add legacy edit flow, reopen flow, status history presentation, location history, and SLA badges. |
| Case workspace | Partial | Existing assessment, plan, referrals, family, consent, documents and monitoring are present. Complete intervention-level progress, responsible person/office, linked-child separate-case decision trail, document metadata/version succession/removal, and case-folder PDF. |
| Referrals | Partial | Preserve current referral workflow; add receiving-office/assigned-provider details, expected action, response/outcome, unavailable reason, target date, response SLA, and provider-scoped access. |
| Activities / calendar | Partial | Current tasks are not equivalent to legacy activities. Add activity type, calendar scheduling, linked-record de-duplication, completion details, reminders, and reminder audit trail. |
| Analytics and reports | Partial | Keep privacy-safe aggregate dashboard and CSV; add quarterly monitoring dataset, results-framework indicators, approved PDF/CSV exports, and legacy small-N suppression on every aggregate output. |
| Interactive geographic map | Partial | Current regional map and PSA PSGC data are in place. Complete drill-down/selector parity, map fit/zoom behaviour, scoped map counts, and geographic access filtering. |
| Documents / evidence | Partial | Existing upload, download, private disk and version number exist. Add category, description, checksum, superseded-document relation, soft removal with reason, and case-folder export. |
| Consent | Partial | Existing lawful basis, purpose and withdrawal exist. Add consent method, provider/guardian details, child assent, withdrawal reason, and sharing decision history. |
| Relationships and family composition | Partial | Existing family table and guardian flag exist. Add contact details, linked client records, and explicit decision/audit trail when a related child should or should not receive a separate case. |
| Offline PWA / GIDA field work | Partial | Encrypted case-pack and sync queue now exist. Add protected route caching tests, permission parity for every queued activity, secure device-retirement procedure, and controlled conflict-resolution UI. No offline document uploads. |
| User administration and roles | Partial | Current roles are fewer than legacy roles. Port the approved role catalogue, action-level permission matrix, geographic/assignment coverage, deactivation, and administrator audit events. |
| Field redaction / sensitivity tiers | Missing | Implement legacy `PUBLIC`, `INTERNAL`, `PII`, `SPI`, and `CASE_NOTES` field classification. Enforce redaction server-side, including API, search, case details, exports, offline packs, and referral views. |
| Audit logs | Partial | Current events exist. Add field-level redacted diffs, denied-access events, export/download events, filtering, and retention-aware audit review. |
| Form builder and lookup management | Partial | Current supplemental field builder exists. Add lookup option create/edit/deactivate/reorder and render approved supplemental fields in their assigned form. Keep protected core fields non-editable. |
| Public dashboard | Equivalent with verification needed | Keep no-PII/small-N suppression; apply the same suppression policy to every new public aggregate. |
| FAQ / help | Missing | Add public help/FAQ with offline, privacy, intake, and support guidance. |
| Governance / backup status | Missing as an in-app view | Render/PostgreSQL backups must remain platform-managed. Add a read-only governance/operational status page and backup/restore evidence log; do not implement a browser-triggered database dump. |

## Cross-cutting legacy rules to preserve

1. Two-layer authorization: role/action permission **and** record coverage (assigned case, LGU, province, or referral basis).
2. Soft deletion and immutable audit history; no hard deletion of case content.
3. Sensitive data is never accepted through URL query parameters.
4. Explicit state machines for reports, cases, referrals, and activities.
5. SLA clocks with an explicit starting event, not an implied timestamp.
6. Local offline data is encrypted, limited to authorized records, and synchronised with server conflict detection.
7. Public and aggregate outputs use small-number suppression and contain no identity or narrative data.

## Intentional replacement decisions

- Apps Script/Sheets/Drive become Laravel, PostgreSQL, private object storage, queues, and server-side authorization.
- Legacy password hashes are not migrated; production users require a secure reset/onboarding path.
- Legacy manual Apps Script reminders become a Laravel scheduled command/queue worker.
- Legacy Data Dictionary page is not an end-user module in this system, per project direction.
- Cloud backups are handled by Render PostgreSQL/object-storage controls, with evidence visible in the governance module rather than by exposing raw backup files in the browser.

## Delivery order

1. **Security foundation:** action-level permissions, coverage filtering, field redaction, audit diffs, and full legacy role catalogue.
2. **Casework parity:** report queue/detail, lifecycle completion, relationships, consent, documents, case-folder PDF, and location history.
3. **Operations parity:** activities/calendar/reminders, referrals/provider workflow, SLA engine, notifications, and offline conflict resolution.
4. **Reporting parity:** quarterly monitoring, results-framework exports, geographic drill-down, public suppression checks, FAQ, and governance status.
5. **Cutover:** production data migration rehearsal, UAT by each role, offline field test, backup/restore rehearsal, and Render deployment.

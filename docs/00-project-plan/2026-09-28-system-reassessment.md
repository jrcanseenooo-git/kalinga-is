# 28 September 2026 - System reassessment and delivery plan

## Review basis

The legacy/reference inventory, migration matrix, current Laravel/Vue implementation, migrations, server-side gates, offline code, automated tests, and representative desktop/mobile screens were reviewed. The legacy system remains the functional baseline; its prior UI is not the design target.

## Findings and priority

### Critical - implemented in this pass

1. **Reference-number collision risk:** case and report references were derived from table counts, which is unsafe for concurrent online/offline reconnection traffic. A locked yearly `reference_sequences` store and `ReferenceNumberService` now generate `RPT-YYYY-NNNNN` and `KLG-YYYY-NNNNN` references. Existing numbers are detected on the first sequence use.
2. **Duplicate plaintext offline path:** an unused Offline/Index screen and its plaintext IndexedDB queue remained alongside the encrypted field workspace. They were removed after reference verification. The active offline path is encrypted IndexedDB only.

### High - next implementation dependency

1. **Unified offline sync engine:** extract the duplicated queue/sync code used by the report form and Offline Workspace into one typed composable/service. It should supply queue counts, last successful sync, failed item recovery, retry controls, and a single online-event handler.
2. **Case Workspace redesign:** the current page exposes too many forms simultaneously. Rebuild it around a compact case summary and contextual sections (Overview, Intake, Assessment, Plan, Referrals, Monitoring, Notes, Documents, History, Closure), with primary next action visible first and mobile sections as accordions/drawers.
3. **Offline readiness lifecycle:** add visible device-preparation status, last pack time, pack expiry/refresh decision, pending/failed queue count in the global field workflow, and an approved device cleanup/logout path. Pack retention and revoke policy require owner approval.
4. **Role/data matrix:** encode an approved matrix for supervisor/CPO/FO/LGU region scope, address/phone access, reports/search, exports, and offline packs. Current broad roles follow existing behavior but are not an approved minimum-necessary policy.

### Medium

1. Add audit events for sensitive reads, pack generation, failed/retried sync, and denied document/export attempts, subject to retention policy.
2. Replace controller-heavy query/workflow code with policy, query, transition, and domain-service layers before adding more case complexity.
3. Add browser-level mobile/offline smoke coverage and keyboard/zoom/accessibility checks to CI/release checks.
4. Make worker dashboards action-first: assigned work, pending sync, failed sync, due follow-ups, referrals, and next actions before aggregate charts.
5. Improve map state and analytics filters with privacy-safe aggregation, loading/no-data/error states, and touch-friendly detail views.

### Low / owner-review decisions

- Data retention, legal basis/consent rules, public release suppression threshold, allowed device classes, MFA/account lifecycle, and regional data-sharing rules need accountable policy owner approval. The system must not invent these rules.

## Delivery sequence

1. Consolidate encrypted offline queue/sync behavior and add recovery tests.
2. Redesign the case workspace using the existing validated endpoints and workflow data.
3. Implement the role/data matrix after owner approval; add authorization tests before UI exposure.
4. Enhance worker/supervisor dashboards and analytics only after workflow and privacy safeguards are stable.
5. Maintain architecture, PWA/data-flow, UAT, and accessibility documentation with every delivered capability.

## Evidence from this pass

- Additive migration: `2026_09_28_000100_create_reference_sequences_table` applied locally.
- Full feature/unit suite: **76 passed, 234 assertions**.
- Reference-number regression coverage: `ReferenceNumberServiceTest`.

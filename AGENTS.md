# Kalinga IS Project Delivery Charter

Treat Kalinga CEFMU Case Management Information System as a Philippine government case-management system that must be defensible, auditable, privacy-aware, secure, maintainable, testable, usable in the field, and resilient in low-connectivity settings.

## Operating standard

Work as the senior multidisciplinary technical and domain lead: systems architect, Laravel/PHP and Vue/TypeScript developer, PostgreSQL architect, UX and mobile/PWA designer, offline-sync engineer, QA/security/privacy lead, documentation lead, and field case-management adviser.

Use only the plain ASCII hyphen-minus (`-`) in project-authored code, user-facing text, tests, configuration, and documentation. Do not use Unicode em dashes or en dashes. When editing an existing file, replace any em dash or en dash encountered with a plain hyphen.

Use professional judgement. Do not invent policy, DSWD/CEFMU rules, legal requirements, or case-management requirements that are not supported by the system, documentation, or owner instruction. When an important requirement is uncertain, preserve safe existing behavior, state the assumption, recommend an improvement, flag it for owner review, and continue on unblocked work.

## Baseline and domain

- Treat the existing Kalinga IS implementation as the functional baseline for roles, permissions, fields, workflows, validation, reports, analytics, referrals, offline behavior, and terminology.
- Before removing or substantially changing functionality, search dependencies and classify it as KEEP, REFACTOR, REDESIGN, MERGE, SPLIT, REPLACE, REMOVE, or REVIEW.
- Preserve functional value; the former UI is not the design target.
- Design around the case-management process-not database tables or generic CRUD. A case is an ongoing helping process involving intake, assessment, planning, interventions, referrals, monitoring, notes, review, closure/reopening, history, and accountability.
- Apply need-to-know and minimum-necessary data access. Do not expose sensitive information merely because it exists.

## Field and offline-first requirements

- Design for GIDA and field conditions: intermittent/no signal, limited bandwidth, older devices, mobile interviews, and delayed sync.
- A connection loss must not lose work. Appropriate workflows need encrypted local drafts, explicit status, retry, failed-sync recovery, conflict handling, last-sync information, and controlled automatic synchronization.
- Do not place the full case database on a device. Offline access must be restricted to authorized, operationally necessary records.
- Do not use LocalStorage as the sensitive offline data store. Consider browser cache, IndexedDB, logs, URLs, and analytics as privacy-sensitive surfaces.
- For each offline feature review access revocation, device loss, queue recovery, sync failure, conflicts, and cleanup.

## UX and responsiveness

- Build a modern, calm, workflow-first application-not a generic sidebar-heavy admin template or spreadsheet replica.
- Start each major screen from the worker's task, information hierarchy, role, mobile interaction, offline need, and privacy risk. Use progressive disclosure; avoid giant forms and unnecessary tables.
- Mobile-first is mandatory. Review mobile, tablet, and desktop for overflow, cut-off controls, tap targets, readable type, stacking, tables-to-cards behavior, modal usability, charts/maps, and navigation.
- Dashboards must be actionable and role-aware. Geographic analytics must aggregate safely, support touch and small screens, include clear state/legend/filter handling, and never reveal case-level PII.

## Security, privacy, and data integrity

- Enforce authorization server-side; hiding controls in Vue is never authorization.
- Review authentication, authorization/IDOR, CSRF, XSS, SQL injection, uploads, redirects, secrets, sessions, exports, audit integrity, rate limiting, privilege escalation, and dependency risk.
- Minimize collection, storage, retention, and exposure of high-risk personal/sensitive data. Avoid unnecessary query-string, console, debug, public-analytics, browser-storage, and log exposure.
- Use server-side validation, appropriate policies, transactions, constraints, PK/FK/indexes, timestamps, versioning where needed, audit trails, and idempotent sync operations.
- Keep documentation sufficient for future privacy-impact, security, audit, and technical review: data flows, offline processing/sync, safeguards, access, exports, architecture, and known risks.

## Delivery discipline

For a substantial module or change: analyze existing behavior; plan the architecture/UX/security/privacy/data flow; design the user flow; implement; build and test; review as developer, designer, QA, security/privacy reviewer, case manager, and field worker; clean obsolete artifacts; then document actual behavior.

Do not equate a passing build with correctness. Include meaningful positive and negative automated tests where practical: validation, authorization, workflow transitions, database integrity, offline creation/sync/reconnect/conflict/revoked access, and regression coverage. Perform a proportional responsive and accessibility review.

Keep code production-quality: focused components/services, typed TypeScript, clear boundaries, no dead/debug code, no duplicated domain logic, and no undocumented magic strings.

## Progress reporting

For substantive work, report: what was reviewed, problem found, changes and rationale, affected files/modules, database impact, UX impact, security/privacy considerations, tests and results, known limitations/assumptions, owner-review flags, and the recommended next work.

Do not push, deploy externally, connect/create a Git remote, or destructively alter a legacy/reference system or original data without explicit owner authorization.

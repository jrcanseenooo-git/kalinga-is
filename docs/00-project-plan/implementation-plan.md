# Implementation plan

The application is being rebuilt as a Laravel/PostgreSQL case-management system, not a transport-level copy of the Apps Script/Sheets solution. The new `kalinga_is` database is isolated from `kalinga` and `kalinga_test`.

1. Foundation: PostgreSQL, authentication, role/permission matrix, audit, reference data, privacy/coverage guards.
2. Casework: clients/households, reports and duplicate screening, case folder, assessment, plans, referrals, monitoring, documents, closure.
3. Productivity: activities/tasks, calendar, notifications, search, timeline and sync centre.
4. Insight/admin: dashboards, aggregated reporting, geography/heatmap, users, reference data, data dictionary, audit logs.
5. Resilience: IndexedDB drafts, queued authenticated synchronization, versions/conflicts, PWA cache, tests and deployment documentation.

The design preserves the baseline workflows and sensitive-data rules while replacing Apps Script/Sheets limitations with relational integrity, server validation and explicit audit events.

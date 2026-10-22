# Current-system inventory

The read-only baseline is a Vue 3/Vite SPA backed by Google Apps Script, Google Sheets and Drive. Its documented modules are authentication, reports/screening, case registry/workspace, relationships/consent, interventions, referrals, activities/calendar, documents, analytics/quarterly reports, administration and audit logging. It has 13 roles, a 41-action permission matrix, sensitivity redaction tiers, state machines for reports/cases/referrals, seven explicit SLA rules, D3 geography/heatmap, and an IndexedDB read-and-queue pattern.

The new design preserves its functional policies while removing the single action endpoint, whole-sheet reads, manual locks, weak SHA-256 password hashes, and Drive-sharing coupling. The legacy source and its databases are not modified.

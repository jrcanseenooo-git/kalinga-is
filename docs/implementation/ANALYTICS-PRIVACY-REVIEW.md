# R03 analytics privacy review

Date: 2026-10-17. Scope: R03-A primary suppression and current permission enforcement. R03-B inference/release-policy review remains open.

## Findings and disposition

Classification: KEEP existing scoped operational analytics and audit/export paths; REFACTOR aggregate-only count serialization and monthly count presentation; REVIEW secondary suppression and differencing. No dataset, role, schema, official KPI, deadline or approval chain is introduced.

Before repair, a system_admin with four cases received cases=4 and clients=4 in Analytics/Index Inertia props even though the status/geography groups were omitted. Monthly intake returned exact small counts. Quarterly totals and both CSV paths also returned exact small counts. Existing tests explicitly expected the small totals. Four new regressions reproduced these defects before implementation.

AnalyticsController now applies the existing aggregate-only threshold of five to every direct count in summary KPIs, six monthly trend buckets, quarterly KPIs and CSV KPI values. Zero also returns Suppressed, matching ManagementDashboardController's existing convention. Grouped queries retain their current threshold rules. Scope for social_worker and fo_user operational analytics is unchanged; they retain exact authorized counts. No new permission grants.

| Endpoint | Required existing permissions | Count behavior |
| --- | --- | --- |
| analytics.index | analytics.view via view-analytics Gate | Aggregate-only direct values below five suppressed; worker/FO scoped counts exact |
| analytics.export | analytics.view and analytics.export | Same count boundary, existing metadata audit |
| analytics.quarterly | analytics.view and analytics.quarterly | Same count boundary within reporting quarter |
| analytics.quarterly.export | analytics.view, analytics.quarterly and analytics.export | Same count boundary, existing metadata audit |

Controller-provided canExport/canQuarterly capabilities mirror the server Gates for page controls. The frontend accepts the explicit number-or-Suppressed contract. Monthly counts have a semantic table and numeric-only bars; a suppressed count has no bar and is never parsed to zero/NaN. The monthly total is withheld if any constituent bucket is suppressed. Empty group summaries do not display a misleading zero donut.

Existing public aggregate endpoints and ManagementDashboardController were reviewed for context but not modified; their grouped primary thresholds were already present. This package does not certify those surfaces against inference attacks.

## Tests and practical limits

AnalyticsSuppressionTest uses explicit synthetic fixtures and be() to bypass the legacy actingAs adapter that can mutate role/LGU test data. Coverage: every count 0-4, threshold five, mixed visible total/suppressed subcounts, payload and CSV parity, quarterly counts, metadata audit, independent denied export/quarterly permissions, and exact province-scoped FO behavior. Existing worker export/assignment regressions remain part of targeted/full runs. Two old tests were changed because their raw-small-total expectations contradicted the master specification, not to conceal an unrelated failure.

Backend: 27 targeted tests passed (406 assertions, 6.48s); full suite 243 passed (1409 assertions, 76.57s). Final npm run build passed (including vue-tsc). Three targeted browser tests passed (29.2s), exercising both pages at 360/768/1440px with overflow, suppression rendering, numeric-style and serious/critical accessibility assertions. Synthetic mobile summary and desktop quarterly screenshots were visually reviewed. PHP syntax checks passed for controller and new tests. No application database changes, data imports, remote creation or deployment. No new dependency, offline data surface or external data transmission.

## R03-B remains unresolved

Primary suppression alone does not prevent reconstruction. For example, a published overall total of six and a published disjoint subgroup of five can expose the remaining count of one by subtraction. Repeated geographic/status/date filters, quarterly comparisons, successive exports and data changes over time can similarly expose small differences. Fixing raw counts below five does not resolve these risks.

Before aggregate release acceptance, owner/DPO review must define complementary suppression, cross-table/period consistency, permitted filters, export audience/purpose, release cadence, and safeguards against repeated-query differencing. No new blanket export ban, noisy counts, query-budget system or access-role change was inferred or implemented. Do not treat these candidate controls as approved policy. These questions are tracked as B01/R03-B and remain prerequisites for production privacy acceptance.

Next implementation task: R06-A, detailed CPU directory/active-LGU parity and MDT grant-list lifecycle review with failing synthetic access regressions before repair. No other module is declared complete by this audit.

# CEFMU Registry remediation register

**Reviewed:** 28 September 2026 (Asia/Manila)  
**Scope:** authenticated casework, private analytics/search, offline workspace, and the current test suite.  
**Purpose:** record verified implementation findings and the next decisions required for a government case-management deployment. This is not a substitute for DSWD policy, a data-protection impact assessment, or a production security assessment.

## Changes completed in this pass

| Priority | Change | Evidence / regression coverage |
| --- | --- | --- |
| Critical | Restricted private analytics, CSV export, and quarterly analytics for `case_worker` and `social_worker` to cases assigned to the signed-in user. | `AnalyticsExportTest::test_case_worker_export_contains_only_assigned_case_aggregates` |
| Critical | Restricted global report search for field workers to reports they created or reports attached to their assigned cases. | `SearchTest::test_case_worker_search_does_not_expose_another_workers_report` |
| High | Applied the same authorized-role guard to the Offline Workspace page as the protected offline-pack endpoint. | `OfflineWorkspaceAccessTest::test_read_only_user_cannot_download_or_sync_offline_case_data` |
| High | Preserved worker-level scope and minimised the currently returned offline-pack fields; syncing to another worker's case remains forbidden. | `OfflineWorkspaceAccessTest` |

## Verified current-state findings

### Critical / high: access and privacy

1. **Field-worker data isolation is now covered for the reviewed search and analytics paths.** Before this pass, the private analytics and report-search queries could return global data after the user passed the general casework gate. The implemented role scope closes that gap for field-worker roles. Supervisory and administrative roles retain their existing system-wide access because the current role matrix permits it.
2. **Offline workspace entry is now least-privilege consistent.** The pack and sync APIs already had role checks; the page route now has the same check so a read-only user cannot enter a screen that implies offline case access.
3. **Public analytics suppression is present, but needs a policy owner decision.** Small-area suppression exists in the reviewed export/public analytics logic. The accountable DSWD/CEFMU privacy owner must approve thresholds, categories, and release rules before real-data publication.

### High: decisions still required before production

1. **Role-to-data matrix:** explicitly approve which supervisor, CPO, FO, LGU, and administrator roles may search reports, see client addresses, export analytics, and access each region. Current broader-role behaviour follows the app's existing gate; it is not a documented data-minimisation policy.
2. **Offline operating policy:** define the permitted offline duration, device enrollment, pack size/risk classification, remote wipe/revocation process, and the exact records/documents that may be copied locally. The current implementation limits packs to 100 cases and excludes phone/birthdate, but that is a technical safeguard-not a formally approved policy.
3. **PII visibility review:** the Case Registry table intentionally shows client location/address to authorized casework users. Confirm column-level need-to-know requirements per role and per operational context before production data is loaded.
4. **Authentication rollout:** Google sign-in visual affordance is not by itself an approved identity assurance design. Confirm the identity provider, approved account domains, account lifecycle, MFA/session rules, emergency access, and audit retention.

### Medium: architecture and delivery risks

1. Several controllers and the case workspace are large and carry querying, authorization, transformation, and rendering responsibilities together. Split them into policy/service/query layers before adding more workflow complexity; this will make reviews and regression tests more reliable.
2. Workflow timestamps and state transitions should continue to be guarded through a single transition service with an auditable event record. Do not allow direct status edits to bypass required validation, consent, referral, or closure controls.
3. Full-suite runtime needs a CI job with database-backed migration, authorization, offline-sync conflict, and browser/mobile smoke coverage. Focused tests pass locally; this pass did not establish a production deployment certification.

### Low: product and accessibility follow-up

1. Validate desktop, tablet, small mobile, keyboard-only, high-zoom, and screen-reader flows for Login, Case Registry, Case Workspace, Offline Workspace, and public dashboard.
2. Maintain readable status labels, visible focus state, no color-only urgency cues, and an explicit empty/error/offline state in each workflow.

## Next implementation sequence

1. Obtain the approved role/data and offline policies above; encode them as permissions and automated tests.
2. Add audit-event coverage for sensitive reads, exports, download attempts, workflow transitions, offline pack generation, and sync conflicts, subject to the retention policy.
3. Modularize the case workflow and strengthen transition/invariant tests before expanding referral, closure, or reporting features.
4. Run CI quality gates (migrations, feature tests, static analysis, front-end build, and mobile/browser smoke tests) for every release candidate.

## Test result for this pass

Focused Laravel tests passed on 28 September 2026:

```text
8 passed, 24 assertions
OfflineWorkspaceAccessTest
SearchTest
AnalyticsExportTest
```

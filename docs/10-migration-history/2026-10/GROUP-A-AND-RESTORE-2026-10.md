# Group A redesign + restore of overwritten work (2026-10-19)

Built on your latest upload (kalinga-is_1.zip). Copy the files over the project
(same paths), then `npm run build` and `php artisan optimize:clear`.
No new migration in this package. It also includes the vite.config.js dev-server
fix sent earlier.

IMPORTANT for future packages: these files were made from your upload of
2026-10-19 22:09. If you or another tool changed any of them after that, tell
me before pasting so nothing newer is overwritten again.

## 1. Restore - work my earlier zips had overwritten

My Phase 1 and Connect zips replaced newer versions of four files that had been
changed in parallel (CpuCoordinationController.php, Cpu/Coordination.vue,
CaseworkController.php, Cases/Show.vue). Your tests and helper files survived,
so the missing parts were rebuilt against them:

- CpuCoordinationController.php
  - POST /cpu/coordination/{circle}/case-references (caseReferences): bounded
    lookup (20 + has_more), q max 100, returns only id/case_number/status,
    private no-store, current circle LGU (legacy rows need matching municipality
    and province), own assignment or current MDT grant, no administrator access,
    selected_id separately authorized.
  - Message send: locks the circle, re-checks the sender (fresh account,
    membership, LGU) and every mention after all writes, audit inside the same
    transaction. Any failure rolls back message, mentions, notifications, audit.
  - Reviewed capture: locks the case, re-checks account, membership, case and
    grant after the formal write, advances the case version, audit in the same
    transaction.
- CaseworkController.php: case status change accepts optional expected_version
  (CaseVersionGuard, checked under the case lock, 409 when stale) and returns
  {saved:true, version} for JSON callers.
- Cases/Show.vue: Closure & aftercare tab uses your CaseClosurePanel again
  (lazy-loaded on first open); the old inline closure form is removed.
- Cpu/Coordination.vue: rebuilt on your useConnectWorkspace composable (per-group
  drafts, held-send locks, lost-acknowledgement review, readback failure,
  reauthorization) and your CoordinationCaseSelect picker, in the group-chat
  layout. The send confirmation step is kept as you had verified it.
- tests/e2e/foundation.spec.ts: confirmation clicks restored; the failed-send
  test now refreshes and explicitly allows the send (no automatic retry).

## 2. Group A - case tabs and Referrals page

- Monitoring & review (CaseMonitoringPanel.vue): visit table and progress-note
  timeline lead the tab; "Add visit" / "Add progress note" open the form. A form
  with unsaved entries stays open. Addendum flow unchanged.
- Case plan (CasePlanGoalsPanel.vue): plan summary strip + goals table
  (No., domain, goal, target date, priority, services, status).
- Services & referrals (CaseServicesPanel.vue): referrals table, interventions
  table, then MDT / CPU access as a table with "Add MDT member" and
  "Assign MDT action" buttons.
- Documents: file table (name, category, uploaded, uploaded by, status,
  download) with an "Upload document" button.
- Referrals page: tinted counts, filter tabs with counts, text filter, one row
  per referral with inline next-status control; "New referral" opens a dialog.
  A case page link (?case=<id>) preselects that case if you can refer it.
- Tables stack into labelled cards below 768 px (.ui-table-stack in app.css).

Privacy: goals and interventions are not added to the case page props. They are
fetched only when the Case plan or Services tab opens, from the existing
permission-checked plan endpoint (cases.plans.index). Documents now include the
uploader's name for unrestricted documents only.

## Tests here
- php artisan test: 405 passed, 0 failed (was 13 failing in the upload).
- npm run test:frontend: 28 passed. vue-tsc and vite build pass.
- Browser (your Playwright foundation suite, isolated synthetic SQLite, run
  here with Chromium): every spec passes except one participation test
  ("saves distinct sources ... finalization": the "Participation record status
  updated." message does not appear). It fails the same way on the untouched
  upload, so it predates this package; flagged for follow-up.
  Run `npm run test:e2e` on your machine (real Chrome) as the final check.

## Other fixes found while testing
- Several e2e helpers opened a case from page 1 of the unfiltered case list;
  with the newer fixtures (31 synthetic cases) the case was on page 2, so the
  result depended on test order. They now search for the case number first.
- Dashboard: "Dashboard" is the page heading again (greeting shown below it).
- Case page "Needs attention" hints: contrast raised to WCAG AA.
- Top-bar case search no longer triggers the generic "Ready to submit?" dialog.
- My cases on phones: Status and Priority share a row so the first case is
  visible on the first screen.
- Connect: own-message chips use solid colours (contrast), list text darker.
- Monitoring and participation responsive tests check the heading is in view
  instead of an exact scroll position (the tabs are shorter now).

## E2E selector updates
ui-monitoring (Add visit / Add progress note, Case plan tab name),
referral-workflow (New referral button), foundation (see above).

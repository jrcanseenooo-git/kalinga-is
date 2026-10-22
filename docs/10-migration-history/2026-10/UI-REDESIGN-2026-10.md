# Kalinga IS - UI redesign to the Kalinga mockups (2026-10-19)

Reference: KALINGA_IS_MOCKUPS_AND_CODEX_HANDOFF (Flat Master Board, Dashboard Option 2).
Scope: presentation only. No database migration, no permission or workflow change.

## How to apply
1. Copy the files in this package over the project (same folder paths).
2. Run `npm run build` (or `npm run dev` while testing).
3. Run `php artisan test` and `npm run test:e2e`.

## What changed
- Design tokens (resources/css/app.css): lavender canvas, 12px rounded cards with
  thin borders, pill badges, tinted KPI tiles, underline tabs, table header style,
  date badge for schedules. Primary buttons use brand-700.
- App shell (Layouts/AuthenticatedLayout.vue): top-bar case search, date chip,
  user menu (profile, help, sign out), distinct icons for Kalinga Connect,
  MDT referral pathway and Offline workspace, pill-style active navigation.
  The sidebar sign-out button is kept.
- Dashboard: greeting header with Today / Assigned office cards, tinted KPI tiles,
  priority pills and overdue count, schedule with date badges, restyled line and
  donut charts with percentages, quick-action tiles.
- My cases: status tabs with counts (All, Active, For follow-up, Closure review,
  Closed). Counts use the same authorized case scope as the list
  (CaseworkController@cases, new `statusCounts` prop).
- Case workspace: profile-style header (initials avatar, status/priority/risk pills,
  classification, case worker, last activity) with six quick-action tiles that open
  existing sections; warnings grouped into one "Needs attention" panel; tabs restyled.
- National management dashboard: tinted KPI tiles; suppressed values shown as "<5 suppressed".
- Shared components restyled: StatusBadge, MetricCard, PageHeader, Stepper,
  DataTable, ChartCard. Gradient headers replaced by flat panels on other pages.
- Shared label helpers: resources/js/utils/caseDisplay.ts (status/priority/risk tones,
  Title Case labels instead of raw values such as IN_PROGRESS).

## Tests
- php artisan test: 364 passed, 1 failed. The failure (ClosureLineageTest, legacy
  reopening) existed before these changes.
- npm run test:frontend: 28 passed.
- vue-tsc and vite build pass.
- E2E selectors updated for renamed UI text: tests/e2e/ui-transform.spec.ts
  ("My cases" heading, "Upcoming schedule"), tests/e2e/ui-interventions.spec.ts
  (top-bar search replaces the "Find a case" link). E2E suites were not run here.

## Not yet redesigned (next pass)
Intake form body, Kalinga Connect chat, MDT pathway, calendar, notifications,
admin pages and the report generator inherit the new tokens but keep their layouts.

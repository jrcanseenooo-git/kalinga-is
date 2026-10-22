# Kalinga IS - Phase 1: case journey (2026-10-19)

Builds on UI-REDESIGN-2026-10.md (this package includes those files too).
Presentation and navigation only: no migration, no permission or status-rule change.

## Apply
1. Copy the files over the project (same paths), then `npm run build`.
2. Run `php artisan test` and `npm run test:e2e`.

## What changed (resources/js/Pages/Cases/Show.vue)
- Journey tracker at the top of every case: 7 steps (Intake, Safety & risk,
  Assessment, Case plan, Services & referrals, Monitoring & review,
  Closure & aftercare). A step shows a check when its record exists. The
  current step is the first one not yet recorded (or Closure when the case is
  For Closure Review / Closed). Guidance only: status changes keep their
  existing server rules, and closure still requires a closure summary.
- "Next step" card with plain guidance and a link to that step, beside the
  next recorded task.
- Case tabs regrouped into the journey (9 tabs, was 10):
  Overview | 1 Client & Intake | 2 Safety & risk | 3 Assessment (comprehensive
  assessment + child participation + earlier assessment records) | 4 Case plan
  (plan summary: version, goals, open interventions, next review; opens the plan
  editor) | 5 Services & referrals (referrals + MDT / CPU pathway) |
  6 Monitoring & review (visits, notes, case conferences) | 7 Closure & aftercare
  (closure record; link to aftercare, reintegration, transfer) | Documents.
- "Needs attention" shows on Overview only; Overview shows the journey step
  instead of the duplicated stage text.
- Quick-action tiles removed from the case header (the journey tracker now does
  that job).
- Old section keys still work (participation -> Assessment, conference ->
  Monitoring & review), so existing links and alerts land in the right step.

## E2E selectors updated
ui-transform, ui-assessment, ui-participation, ui-monitoring, ui-conferences,
role-flows, foundation specs: new tab names, 9 tabs, "Documents" as last tab.
Not run here (needs the e2e database and Chrome) - please run locally.

## Tests here
php artisan test: 364 passed, 1 failed (pre-existing ClosureLineageTest).
npm run test:frontend: 28 passed. vue-tsc and vite build pass.

## Not yet done (next)
- The plan editor (goals, interventions, aftercare) still opens on its own page;
  embedding it inside the Case plan tab is the remaining Phase 1 item.
- Phase 2 report generator, using the proposed report form.

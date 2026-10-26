# Release 6 - Monitoring roles, KaVAT and the Kalinga forms (2026-10-28)

Owner request of 2026-10-26 ("Go, do the Phase 3 and 4") from the
improvement plan. Branch `feature-enhancements`.

## Phase 3 - Roles, monitoring and management

### Roles

| Role | Scope | What they do |
|---|---|---|
| System Administrator | System | IT only: accounts, settings, audit logs. No case content. |
| Project Manager | National (`users.region_scope = ["*"]`) or listed regions (for example `["Region VIII"]`) | Approves accounts, LGU registry, user management, office profile and signatories (one LGU for a regional focal, a picker when national), monitoring case table, remarks. |
| Field Office | Its own region (`users.region`) | Same as the Project Manager, limited to its region. |
| LGU Head (Supervisor) | Its assigned LGU(s) | Monitoring only: no cases, no intake, no LGU profile. Gives remarks; approves or returns closure. |
| Case Manager (`social_worker`) | Assigned cases | Casework. |
| CPU member | Referred cases only | Unchanged. |

- The scope rule lives in `App\Services\MonitoringScope` (by `cases.lgu_id`).
- Monitoring > Cases: secured name (alias or masked, for example
  `J**S***D**UZ`), case manager, LGU, region and status. Monitors never
  receive the child's full name, contact numbers, narratives or KaVAT
  answers. Viewing a case summary is audited (`case.monitored`).
- Remarks (`case_remarks`): monitors write remarks; the case manager is
  notified, can reply and mark them read; the monitor is notified of the
  reply. LGU Head closure approval and return are recorded as remarks.
- Service providers were removed from the menus, pages and referral form.
  The `service_providers` table and old links are kept for history.

## Phase 4 - Case workspace

### New tabs

Overview, Client & Intake, KaVAT, Kalinga forms, Progress notes & referrals,
Closure & aftercare, Documents.

- Removed: the 7-step journey bar and the Safety & risk, Assessment, Case
  plan and Services & referrals tabs. Their records are not deleted: earlier
  safety assessments, comprehensive assessments and case plans stay in the
  database and in the plan workspace (`/cases/{id}/plan-workspace`), which is
  still used for aftercare and reintegration.
- Old links (`?section=safety`, `assessment`, `care`, `collaboration`,
  `monitoring`) open the matching new tab.
- A "Next step" box follows the forms sequence, with three quick figures:
  latest KaVAT score, forms finalized (out of 3) and grants released.
- Overview shows remarks from the supervisor and monitors (reply and mark as
  read) and the case conference card, which now stands outside the cycle.
- Progress notes & referrals: notes and visits, then "Referrals to CPU
  members" and "Referrals to external agencies" as two separate lists (the
  note form asks which one), services provided, the grants log and MDT
  access.
- Closure & aftercare: a closure checklist (KaVAT at closure, Intervention
  Plan and Social Case Study Report finalized, no open referrals or tasks,
  all remarks read) above the existing closure record and review.

### KaVAT (`App\Services\Kavat`, `kavat_assessments`)

- The instrument from the Enhanced Pilot Guidelines: Part 1 (25 statements,
  7 domains, reversed items 4, 5, 9, 14, 18, 22, 24) and Part 2 (9 sections).
- Formulas as written: empowerment = Part 1 / 125 x 100; objective risk =
  Part 2 / 98 x 100; safety = 100 - risk; final = empowerment x 0.40 +
  safety x 0.60; below 70 = High Priority (Eligible).
- Live score while answering (the browser uses the same definition), domain
  interpretations, red flags for refusals, autosave with revision checks,
  finalize (locked), discard draft, history with a score trend.
- Domains rated low become suggested rows in the Intervention Plan.
- Checked against the guideline worked example (Part 1 = 65, Part 2 = 88,
  final 26.92) in PHP and in the browser scoring.

### Kalinga forms (`App\Services\CaseForms`, `case_forms`, `case_form_versions`)

- Sequence: Kalinga Intake Sheet, Case Summary Report, Progress Notes,
  Intervention Plan, Social Case Study Report (final report).
- Case Summary and SCSR are written in paragraphs per section with a short
  guide; identifying information, CEFMU incidence, family composition,
  interventions and status are filled in from the record when printed. The
  first section offers a starting sentence built from the intake.
- Intervention Plan is a matrix: problem / need, objective, activities,
  person/s responsible, time frame, expected output.
- Saves as the case manager types (every 1.5 s of inactivity). Each save
  carries the revision it was based on; if another device saved first, the
  page asks which copy to keep. Offline in the browser: the text stays on the
  page and saves when the connection returns.
- Finalize keeps a numbered copy; the form stays editable for the next
  version. Edits are audited once per person, form and half hour.
- Reports print the written form when it has content (otherwise the earlier
  record-based layout). The SCSR also prints the plan matrix and the latest
  KaVAT score.

### Grants log (`case_grants`)

- Per case: type, amount, date released, fund source, purpose, received by.
- Voided with a reason, never deleted; voided entries are crossed out and not
  counted. Totals show on the case and on the monitoring summary.

### Field mode (offline, GIDA)

- The encrypted pack now carries, for the case manager's own open cases,
  the Kalinga forms and any KaVAT in progress, with the instrument.
- In Field mode, a case has "Note or visit", "KaVAT" and "Kalinga forms".
  Edits save into the encrypted pack; at sync each edited document becomes
  one queued update (`case_form` or `kavat`) carrying its base revision. A
  KaVAT can also be started offline. Finalizing happens online.
- If the server copy changed meanwhile, the item becomes a conflict with
  "Keep mine" or "Use the newer copy". Nothing is overwritten silently.
- The server keeps only a fingerprint (SHA-256) of a synced document in
  `offline_sync_items`, not a second copy of the narrative.

## Owner review

1. KaVAT section 6 lists a maximum of 10 but its two questions reach 15; the
   Part 2 total of 98 only works with 15, so 15 is used.
2. The guideline worked example writes 54 x 0.40 for an empowerment rate of
   52 (its result, 26.92, is correct with 52).
3. KaVAT sections 6 and 7 have no "not applicable" choice for a child
   without a partner.
4. Kalinga form section titles and guide questions; SCSR section order
   follows the sample.
5. Grant types and whether the fund source must come from a fixed list.
6. CPU vs external grouping of referral recipients
   (`resources/js/utils/caseOptions.ts`).
7. Dashboard "total grants disbursed" is planned with the dashboard and
   analytics work (Phase 6).

## Fixes found on the way

- `tests/TestCase.php`: Pint renamed the helper `testLgu` to `test_lgu`
  (its method-casing rule), which broke legacy fixtures; renamed to
  `fixtureLgu`.
- The route sweep test now ignores rate limiting (more case routes than the
  per-minute limit); it still checks every case route refuses strangers.

## Tests

- PHP: `KalingaFormsAndKavatTest` (scoring, KaVAT flow, forms autosave and
  conflicts, versions, reports, grants, field-mode sync and conflicts),
  `CaseMonitoringTest`; full suite on SQLite and PostgreSQL.
- Front end: `tests/frontend/kavat.test.mjs` (browser scoring = server).
- Browser: `tests/e2e/kalinga-forms.spec.ts`; updated specs for the new tabs.
  `ui-assessment.spec.ts` and `ui-participation.spec.ts` were removed with the
  Assessment tab.

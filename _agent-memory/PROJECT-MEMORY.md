# Project memory for AI agents - Kalinga IS

Working notes for any AI agent (or developer) picking up this project:
the plan, what is done, and what to do next. **Read this first, then update
it after every task you finish** (move the task to "Done", add the date and
commit, and rewrite "Next").

This folder is temporary. It is not part of the system: nothing in the app,
build, tests or deployment reads it, and it is excluded from the Docker
image. **To remove it before turnover:** delete the `_agent-memory/` folder
and the one line marked `agent-memory` in `AGENTS.md`.

Last updated: 2026-10-29 (Asia/Manila)

---

## 1. Project at a glance

- Kalinga CEFMU Case Management Information System, DSWD Social Technology
  Bureau. Owner: Emmannn (STB DFD).
- Stack: Laravel 12, Inertia 2, Vue 3 + TypeScript, Tailwind 4, PostgreSQL.
- Repo: `jrcanseenooo-git/kalinga-is` (private). Work branch:
  `feature-enhancements`. Deploy = owner merges a pull request into `main`;
  Render rebuilds; `docker/start.sh` runs `php artisan migrate --force`.
  Database on Supabase.
- Rules: `AGENTS.md` (project charter). Key ones: plain ASCII hyphen only
  (no em or en dashes); do not invent policy, flag it for owner review; push
  or deploy only with owner authorization (pushing `feature-enhancements` is
  authorized); no sensitive data in LocalStorage; audit logs read-only and
  never store passwords, codes or tokens.
- Owner preference: tick off each task in the progress list as soon as it is
  done.

## 2. The plan (improvement plan of 2026-10-26, 8 phases)

| Phase | Scope | Status |
|---|---|---|
| 1 | System audit logs (14 log types, filters, export), CEFMU incidence, renames, alias | Done - Release 5 |
| 2 | 4-step Kalinga Intake, saved intakes | Done - Release 5 |
| 3 | Roles and monitoring: Project Manager, Field Office (own region), LGU Head (monitor only), remarks, secured names | Done - Release 6 |
| 4 | Case workspace: 7 tabs, KaVAT, Kalinga forms with versions, grants log, CPU vs external referrals, field-mode sync of forms | Done - Release 6 |
| 5 | Calendar invites, reason to reschedule or cancel, notify supervisor / FO / PM, history and audit, phone push, offline delivery | Done - Release 7 |
| 6 | Dashboard = to-do list only; analytics drill-down map PH > region > province > LGU > barangay, trend lines, quarter filter, PDF export with watermark and audit, self-hosted maps | Done - Release 7 |
| 7 | Every dropdown editable (incl. risk factors, disability types), extra intake fields with preview, in-use warning, user feedback module | Done - Release 7 |
| 8 | Project team chat: PMs search case managers by region, direct messages, groups ("Region VIII") | Done - Release 7 |

Release notes with full detail: `docs/10-migration-history/2026-10/`
(RELEASE-5, RELEASE-6, RELEASE-7).

Owner answers that still apply:
- Field Office works like a Project Manager, limited to its own region.
- Risk factor points are set by the owner's team.
- Grants are a separate log per case.

### Batch of 2026-10-28 (owner request, in progress)

| # | Item | Status |
|---|---|---|
| 1 | Remove the Consent register on the case page (intake privacy notice and consent/assent photo already cover consent) | Done (b44ef0b) |
| 2 | Kalinga Intake: larger, clearer layout for older users; responsive; smart hints; address tickboxes off by default; label "Mobile Number (Parent or Child)"; family composition never adds a second empty card and drops empty entries | Done (a034a60) |
| 3 | Consent/assent photo: Take photo must open the device camera; smart capture checks | Done (b44ef0b) |
| 4 | Modern confirmation dialogs / modals system-wide | Done (c5d3038) |
| 5 | KaVAT: owner info - the baseline KaVAT is done before intake in the project's separate tool (a Google Sheet with formulas) to identify vulnerable CEFMU. Owner decision 2026-10-28: Kalinga records the baseline RESULT only (date, scores, priority, optional photo of the sheet), no re-answering; the KaVAT in Kalinga is for the quarterly conduct, compared against the baseline. Improve form and make it smart | Done (c5d3038) |
| 6 | Better PDF report (analytics print showed mobile chrome, 7 pages, blank map) | Done (b50d38d) |
| 7 | Smart (rule-based, no external AI) assistance across the whole system; rename the cut-off "Office profile & signatories" nav label | Done (8637205) |
| 8 | Bug sweep, tests, docs, push | Done (02edb5e) |

### Batch of 2026-10-28 evening (owner request, done 2026-10-29, Release 9)

Owner decisions: case status fully automatic (Open -> In progress when work
is recorded; Awaiting referral while a referral is unanswered; For follow-up
when referrals answered but plan items open; manual buttons removed, closure
steps stay). Plan rows keep the free-text Time frame (printed) and gain
target start/end dates (calendar and monitoring only, not printed). KaVAT is
Baseline (external score), Midline and Endline only; in-system test KaVATs
are deleted (owner approved). Aftercare = plan at closure + follow-up visits.
Assumption to flag: Midline is suggested 3 months after the case opened (info
reminder); Endline when the case is in closure review. Owner to confirm timing.
Plan deadline: due = intake date + 7 days; submission = first finalized plan version
(case_form_versions v1). Supervisor = LGU Head of the case LGU (App\Services\Supervisors).
Referrals now conducted from plan rows (referrals.plan_row_key); the note form no
longer creates referrals.
FLAG for owner: hiding Plan & continuity also hides the formal handover button (the
only way to change a case manager); page /cases/{id}/plan-workspace still works.

| # | Item | Status |
|---|---|---|
| A | Intake: saved-intake Continue hangs on loading; step shows negative after Back; better smart start page; "Child Contact Details" (child mobile 11 digits, plus Messenger etc.); text size for the form only | Done (a371bbb) |
| B | Sidebar unread badges: Kalinga Connect, Project team chat | Done (36d8fa2) |
| C | Automatic case status | Done (9eff657) |
| D | KaVAT Baseline/Midline/Endline, editable for corrections, no "reversed" label, no option points, 3-result history | Done (33d1023) |
| E | Forms order Intake > CSR > SCSR > Intervention Plan and Referrals > Progress Notes (also Reports menu); Intake print fixes; CSR template; SCSR carries CSR | Done (be28bbc) |
| F | Intervention Plan and Referrals (no KaVAT suggestions, target dates, Conduct referral per row, 7-day deadline + supervisor status) | Done (4c48ec6) |
| G | Monitoring tab (plan monitoring) | Done (60c1480) |
| H | Calendar: invite anyone by name; supervisor sees CM activities; reschedule = attention item for CM and supervisor; plan dates on calendar | Done (c573dd4) |
| I | Hide Case Plan and Plan & continuity; service Other specify; grants = Transportation Support Allowance only, no fund source | Done (b7c7271) |
| J | Dashboard -> Workboard; analytics (Reporting Pipeline, age-sex by exact age, priority charts on top, services chart, average plan submission time) | Done (fdd5133) |
| K | Aftercare module | Done (47a0255) |
| L | Offline field mode upgrade | Done (f05e89f) |
| M | Tests, docs, push | Done (25c6ed8 + test fixes) |

## 3. Current progress

- All 8 phases built, tested and merged to `main` (PR #3, merged
  2026-10-27 09:58). Render deploy started from that merge.
- Tests at merge: PHP 493 passed (SQLite) and 494 (PostgreSQL); front-end
  37 passed; browser suite 242 passed.
- After the merge: README development-account table corrected (commit on
  `feature-enhancements`, not yet merged to `main`).
- 2026-10-28: direct case reassignment removed at the owner's request (the
  Assignment card, route `cases.assign`, permission and messages). Cases
  change case manager only through the formal handover in the plan
  workspace. Do not reintroduce it.
- Phone notification keys (VAPID) were generated on 2026-10-27 and given to
  the owner to add in Render > Environment (`VAPID_PUBLIC_KEY`,
  `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`). Not yet confirmed as set. Never
  write the private key in this file or in the repo.

## 4. Next things to do

0. Owner: open a pull request from `feature-enhancements` to `main` for
   Release 9 (everything after ce07506) and run the Release 9 checklist in
   `docs/10-migration-history/2026-10/RELEASE-9-...md`. Answer the two new
   review items (Midline timing; formal handover while Plan & continuity is
   hidden).
1. Owner: confirm the Render deploy is Live and the VAPID keys are set; test
   "Turn on phone notifications" from the bell on a phone.
2. Owner: run the release-7 checklist (dashboard, calendar invite and
   reason, analytics map and PDF, form options, feedback, team chat).
3. Owner to finish the open items in section 5 (list defaults, Release 6
   KaVAT and form questions); apply the answers.
5. Housekeeping noticed: release note files RELEASE-6 and RELEASE-7 carry
   dates 2026-10-28 and 2026-10-29 in their names although work finished
   2026-10-27; rename if the owner wants accurate dates.

Standing note from the owner (2026-10-27): more enhancements, additions and
fixes are coming. Do the project clean-up (this folder, the `AGENTS.md`
line, obsolete files) only at the very end, when the owner says the work is
finished and the folder is being turned over.

## 5. Waiting for owner decision

Answered 2026-10-28: 1 keep lock-screen text; 2 CPU members keep the
calendar; 3 add the case manager to change notices and make the change
explicit for Project Managers (done); 4 map source OK; 6 team chat stays
Project Managers only (Field Offices do not start conversations); 7
feedback kinds OK.

Still open:
1. (5) Owner will check the default choices in the 13 lists, especially
   risk factor points and the moderate / high / critical score levels.
2. From Release 6: KaVAT section 6 maximum (10 vs 15), worked example 54
   vs 52, no "not applicable" in KaVAT sections 6-7, Kalinga form section
   titles, grant types and fund source list, CPU vs external referral
   grouping.

## 6. How to work on this project

- Local run, accounts and checks: `README.md` and
  `docs/07-installation/LOCAL-SETUP.md`.
- Before pushing: `php vendor/bin/pint app tests database routes config`,
  `php artisan test`, the PostgreSQL run, `npx vue-tsc --noEmit`,
  `npm run test:frontend`, `npm run build` (a new page needs a build or
  PHP tests fail on the Vite manifest), and the browser suite
  `npx playwright test -c playwright.review.config.ts` (about 15-25 min).
- New browser spec files must be added to `testMatch` in
  `playwright.foundation.config.ts`.
- New tables: add them to `scripts/generate_data_dictionary.py` (TABLES and
  GROUPS) and regenerate `docs/04-database/`.
- New permissions: `RolePermissions::PERMISSIONS` and `GRANTS`, plus a
  migration upsert. New audit events: `AuditCatalogue::EVENTS`.
- Help guides for users: `resources/js/data/helpGuides.ts`.
- Each finished change: release note in `docs/10-migration-history/`, then
  update this file.

## 7. Task log (newest first)

| Date | Task | Commit |
|---|---|---|
| 2026-10-29 | Release 9 finished: intake fixes and Child Contact Details, chat badges, automatic status, KaVAT Midline/Endline with corrections, forms order and prints, Intervention Plan and Referrals with 7-day deadline, Monitoring tab, calendar team view and reviewed moves, grants transport only, Workboard and analytics, aftercare, field mode upgrade. Tests: PHP 502 (SQLite) / 503 (PostgreSQL), front end 46, browser 253 | a371bbb..25c6ed8 |
| 2026-10-28 | PR #5 merged to main (everything up to ce07506 is live) | 897f65e |
| 2026-10-28 | Batch of 2026-10-28 finished; all tests green (PHP SQLite and PostgreSQL, front end, browser 246); not yet merged to main | 02edb5e |
| 2026-10-28 | Analytics PDF report layout; Kalinga Assist on every page; nav label Office & signatories; release notes RELEASE-8 | b50d38d, 8637205 |
| 2026-10-28 | Consent register removed; camera capture with smart checks; intake redesign + text size; modern dialogs; KaVAT baseline result + quarterly; Philippine date fix (dates were rejected before 8 AM) | b44ef0b..c5d3038 |
| 2026-10-28 | Removed direct case reassignment (Assignment card, route, permission, tests) | 6b240ad |
| 2026-10-28 | Owner decisions recorded; change notices also go to case managers, explicit for PMs, labels in the bell | 9dcc537 |
| 2026-10-27 | Created this project memory folder | 98a00c6 |
| 2026-10-27 | README: current development accounts | d745097 |
| 2026-10-27 | Generated VAPID keys for Render (given to owner, not stored) | - |
| 2026-10-27 | PR #3 merged to main: Phases 5-8 | 1156bab |
| 2026-10-27 | Analytics cards fit 360px; browser test fixes | 45265f7 |
| 2026-10-27 | Phases 5-8 release notes, help guides, data dictionary | 6dc687f |
| 2026-10-27 | Phase 8 team chat | 9cb96cb |
| 2026-10-27 | Phase 7 form options and feedback | b0fa03c |
| 2026-10-27 | Phase 6 to-do dashboard and analytics map | 7f82916 |
| 2026-10-26 | Phase 5 calendar sharing and phone notifications | c30da33 |
| 2026-10-26 | Phase 4 KaVAT, forms, case workspace, grants (Phase 3 just before) | b399647 |

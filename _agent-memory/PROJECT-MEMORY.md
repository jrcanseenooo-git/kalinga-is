# Project memory for AI agents - Kalinga IS

Working notes for any AI agent (or developer) picking up this project:
the plan, what is done, and what to do next. **Read this first, then update
it after every task you finish** (move the task to "Done", add the date and
commit, and rewrite "Next").

This folder is temporary. It is not part of the system: nothing in the app,
build, tests or deployment reads it, and it is excluded from the Docker
image. **To remove it before turnover:** delete the `_agent-memory/` folder
and the one line marked `agent-memory` in `AGENTS.md`.

Last updated: 2026-10-30 (Asia/Manila)

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

UAT activation: owner approved publishing/deploying the reviewed 43-account change, enabling on-screen UAT codes and creating accounts expiring 2026-10-30 23:59 Asia/Manila. Published branch uat-regional-accounts, PR #9. SQLite PHP 512 passed/two skipped; PostgreSQL 513 passed/one skipped with the disposable database set to UTC (its inherited Windows timezone initially caused an expiry-trigger test failure). Dedicated creation/download/code-login browser regression passed. Broader existing-screen browser run continues with report loading-indicator ARIA failures and short intake error-response timing failures; do not call it a clean pass. Application admin session restored; owner is signing into Render hosting separately. No live account creation yet. Keep APP_DEBUG=false. Next: deploy approved head, enable UAT mode, provision once, save credentials privately, verify all live role/location sign-ins, then update this note with actual counts and deployed revision.

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
| 2026-10-30 | Owner requested one shared password for all 43 temporary UAT accounts. Added an owner-entered password/confirmation form and guarded once-only shared setup on the complete unchanged unused original-owner roster; accepts after prior random recovery, independent normal salts, preserves roles/LGU/circles/expiry, excludes personal accounts and replaces encrypted retry export. No actual chosen password embedded in repository. Final SQLite 517 passed/2 skipped (519 total, 5069 assertions), PostgreSQL UAT 14 passed/402 assertions, frontend 46 passed, production build/Pint and shared-password browser sign-in passed. Live page shows earlier random recovery completed; existing private export preserved outside repository before deployment. Next: deploy shared form, owner enters/submits password, save updated export, verify all 43 live logins and package recipient cards. | Shared setup pending publication |
| 2026-10-30 | Original UAT PR #9 merged and Render revision 9e4360a live; UAT enabled and production debug false verified. Live creation completed in 68 seconds but Nginx returned 504 at 60 seconds, losing the original credential response. No passwords distributed and no live UAT login checks. Delivery fix: 180-second upstream timeout, transactionally encrypted owner-only 30-minute retry export in existing cache table, scheduled expiry purge and explicit once-only recovery of complete unused unchanged original-owner roster. Recovery preserves scope and expiry and must be submitted by owner under browser handoff rules. SQLite final 515 passed/2 skipped, PostgreSQL UAT 12 passed/343 assertions, frontend 46 passed, production build/Pint passed, browser create/retry-download/acknowledge/code-login passed. Wider old browser suite stopped at test 224/253 with known loading-bar ARIA and short intake wait failures; longer intake waits passed. Delivery fix PR #10 merged as a0ab8160b9e32593566b5017d39fe4124b243dd9 and confirmed Live in Render deploy dep-dbhqcclckfvc73frmllg. Live page confirms 43 accounts. Next: waiting for owner to submit password recovery; then save private export and verify all 43 live accounts. Do not redeploy/clear cache until the retry export is saved. | 3235e04 / merge a0ab816 |
| 2026-10-30 | Prepared opt-in 43-account regional UAT setup at /admin/uat with marked/expiring accounts, unique credentials, correct LGU/circle/FO scope and on-screen codes only for roster accounts. Personal verification retained; no production debug exposure. New nullable users.uat_key/uat_expires_at migration, docs/dictionary, nine feature tests. Full SQLite PHP: 512 passed, two skipped (514 total); final UAT refinement rerun 9 passed/316 assertions; frontend 46 passed; TypeScript/Vite build and Pint pass. Local desktop/mobile setup and UAT code-to-Paracelis Workboard browser checks passed. Live admin inspected but NO live changes/push/deploy. Next: owner approval to publish/enable/create with expiry and BCPC mapping, Render environment access, PostgreSQL/full-browser validation and live smoke tests. | Local changes pending publication approval |
| 2026-10-30 | Prepared 43-account UAT roster for Paracelis, Brooke's Point, City of Catbalogan and Socorro (Surigao del Norte): 28 participants + 15 ITO role accounts. Verified PSGC codes and existing role/discipline mappings. Output UAT-ACCOUNT-PLAN.md; 43 unique roster rows checked. No live accounts created. Next: reachable emails/alias inbox, live administrator or owner-operated Render access, BCPC mapping confirmation, then provision and verify active LGU/circle/region scopes and login delivery. Separate ITO logins do not isolate LGU supervisor visibility. | Local plan only |
| 2026-10-29 | Local demo/UAT preparation review for 30 October: live login/registration render checked; Release 9 confirmed on main fde5ac7; 46 frontend tests passed after npm ci. vue-tsc blocked by missing Composer Ziggy dependency. Prepared READ-FIRST, DEMO-SCRIPT, UAT-CHECKLIST and VALIDATION under the parent chat outputs folder. No app edits, push, deployment or live data writes. Next: approved test accounts, fresh live login/code delivery, role/LGU scope and authenticated rehearsal; confirm actual Render revision/storage/scanner. | Local review only |
| 2026-10-29 | CEFMU classification counts only the 4 codes (Analytics, Quarterly); quarterly services from services_provided; services ranked bar chart (waffle removed); sidebar Case intake > Cases > Calendar; plan step button Print; demo seeder uses CEFMU codes | 42e1680 |
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

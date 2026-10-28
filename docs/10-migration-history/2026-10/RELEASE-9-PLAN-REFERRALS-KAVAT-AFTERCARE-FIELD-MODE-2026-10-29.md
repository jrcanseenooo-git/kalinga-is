# Release 9 - Intervention Plan and Referrals, KaVAT three administrations, aftercare, field mode (2026-10-29)

Owner requests of 2026-10-28 (evening batch and attachment). Branch
`feature-enhancements`. Migrations `2026_10_29_000700` to `2026_10_29_001300`.

## Owner decisions applied

- Case status is automatic (rules below); only the closure steps are manual.
- Plan rows keep the free-text Time frame (printed) and add target start and
  end dates (calendar and monitoring only, not printed).
- KaVAT records made in Kalinga until now were test data and are deleted
  (migration `2026_10_29_000900`).
- Aftercare = a plan at closure plus follow-up visits after closure.

## Kalinga Intake

- Fixed: Continue on a saved intake loaded forever (blank fields came back
  as null and broke the form). Saved intakes are restored null-safe online
  and in field mode (`restoreIntake`).
- Fixed: the step number could show a negative value after Back (clamped,
  and Next/Back ignore double taps).
- New start page: "Pick up where you left off" (intake saved within 24
  hours), the four steps, what to have ready, saved intakes with progress and
  days before they are deleted.
- "Safe contact" is now **Child Contact Details**: the child's mobile number
  (11 digits, 09XXXXXXXXX, no spaces; +63 and spaces are tidied while
  typing) plus other ways to reach the child (Facebook Messenger, Viber and
  others) in `clients.contact_channels`.
- Text size applies to the forms only (`.kalinga-form`, CSS zoom); the rest
  of the app, including the sidebar, keeps its size. The control moved out of
  the user menu into the forms.

## Sidebar

- Unread badges on Kalinga Connect and Project team chat, like the Calendar
  badge (`direct messages read_at`; `ChatUnread`).

## Automatic case status

- Open until work is recorded; In progress once a KaVAT, form, note, visit,
  service, grant or referral exists; Awaiting referral while a referral is
  sent and unanswered; For follow-up when referrals are answered but plan
  items are open. Never goes back to Open.
- The status line on the case page says why. Manual status buttons are
  removed; "Start closure review" / "Stop the closure review", Close and
  Reopen stay. Each automatic change is in the status history and the audit
  log (`case.status_automatic`); it never bumps the field-mode version.

## KaVAT

- Baseline (separate tool, result only), Midline and Endline (answered in
  Kalinga, one each per case). The quarterly rule is removed.
- A finalized KaVAT can be corrected: the answers reopen, the previous
  result is kept until the correction is finalized with a reason (audited
  `kavat.corrected`) or cancelled.
- History shows Baseline / Midline / Endline cards with the change since the
  baseline. The word "reversed" and the points next to options are no longer
  shown.
- Reminders: Midline 3 months after the case opened (assumption, owner to
  confirm), Endline when the case goes to closure review.

## Kalinga forms

- Order (forms steps and Reports menu): Intake, Case Summary Report, Social
  Case Study Report, Intervention Plan and Referrals, Progress Notes. The plan
  prints as "Intervention Plan".
- Intake print: Address and child contact details; Primary caregiver /
  custody; actual addresses instead of "Same as the child" (also family);
  Health row only with real data; sections 8-11 removed; Privacy notice and
  consent / assent (no basis for data sharing) is 8; Prepared / noted by is 9.
- Case Summary Report: Presenting problem, Brief case history, Assessment,
  Recommendations.
- Social Case Study Report starts from the Case Summary text in the matching
  sections; the carried text is saved into the case study.

## Intervention Plan and Referrals

- KaVAT row suggestions removed.
- Each row has, not printed, a status (not started, in progress, done), target
  start / end dates and an Actions column: **Conduct** opens a referral
  prefilled from the row. The referral is saved as a referral progress note,
  so its history stays in Progress notes; `referrals.plan_row_key` links it
  to the row. The referrals list moved from Progress notes to the plan.
- Deadline: the plan is submitted (first finalized) within 7 days of the
  intake. The plan step shows the due date; reminder 2 days before; overdue
  alerts to the case manager and the supervisor (LGU Head); the supervisor
  is told when the plan is submitted and how many days it took.

## Monitoring tab

- New tab beside Closure & aftercare (read only): plan items done / in
  progress / not started / past target end, each item's dates and referrals,
  the 7-day status and the latest progress notes.

## Calendar

- Invite people: name search in any letter case, and role words
  (supervisor, CPU, focal, project manager); CPU members also from the LGU CPU
  list; Field Office focals by region or scope.
- The supervisor sees the case managers' activities (read only, without
  details), with a show / hide toggle.
- A rescheduled activity is an attention item on the Workboard for the case
  manager and the supervisor until the supervisor marks it reviewed
  (`task_history.reviewed_at / reviewed_by`, audited).
- Intervention Plan target dates and aftercare visits appear on the
  calendar (item number only, never plan text). Case plan review dates are no
  longer shown.

## Hidden for now

- Plan & continuity button on the case header and the Case plan record type.
  The backend and the page stay. OWNER REVIEW: the formal case handover was
  reached from that page; it still works at `/cases/{id}/plan-workspace`.

## Services and grants

- Progress note record type Other and service type Other need a
  specification (`case_notes.note_type_other`).
- Grants log: Transportation Support Allowance only, no fund source; purpose
  of the travel and received by are required; total, releases and last
  release shown. Grant types are no longer an editable option set; older
  entries keep their label.

## Workboard and analytics

- Dashboard is renamed Workboard.
- Analytics: CEFMU classification, cases by exact current age and an
  age-sex pyramid on top; Intervention Plan submission time (average days,
  on time, late, past due); Referral pipeline renamed Reporting Pipeline;
  services provided as ranked bars with cases served and amounts. Small
  groups stay withheld.

## Aftercare

- Aftercare panel in Closure & aftercare: plan (living arrangement, support
  people, services that continue, risks, office, visit frequency, first visit,
  end date) and follow-up visits after closure (Stable, Needs support, At
  risk). At risk tells the supervisor and suggests reopening; the case is
  never reopened automatically. Next visit on the calendar and reminders.
  Closure readiness asks for the aftercare plan.

## Field mode

- Progress notes offline with record type, time it happened, action taken
  and next steps; monitoring visits with risks and next steps. Items queued
  by older versions still sync.
- Aftercare visits offline for closed cases (sync resource
  `aftercare_visit`). The pack carries closed cases only while aftercare
  visits remain, plus the plan deadline and next aftercare visit per case.
- Case list search (name, case number, barangay) and due hints.

## Database

| Migration | Change |
|---|---|
| 000700 | `clients.contact_channels` (json) |
| 000800 | direct messages `read_at` |
| 000900 | KaVAT correction columns; deletes in-system KaVAT test records |
| 001000 | `referrals.plan_row_key` |
| 001100 | `task_history.reviewed_at`, `reviewed_by` |
| 001200 | `case_notes.note_type_other` |
| 001300 | aftercare plan columns on `case_aftercare_plans`; `aftercare_visits` |

## Tests

PHP feature tests (SQLite and PostgreSQL) and browser tests cover each item,
including new suites: CaseStatusAutomationTest, ChatBadgesTest,
InterventionPlanReferralsTest, AftercareTest, FieldModeUpgradeTest, and the
browser specs calendar-team, aftercare and field-update.

## Owner review

- When the Midline is due (now suggested 3 months after opening).
- Formal handover access while Plan & continuity is hidden.
- Earlier open items: Google Sheet formula and rounding for the baseline,
  list defaults.

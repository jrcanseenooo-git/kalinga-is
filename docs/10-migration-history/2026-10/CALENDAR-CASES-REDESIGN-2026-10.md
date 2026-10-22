# Calendar and Cases redesign, smart case list (2026-10-21)

No database changes. After copying the files:

    npm run build
    php artisan optimize:clear

## Sidebar
- Field mode card: the status line now wraps instead of being cut off
  ("Online - ready for no-signal areas").

## Calendar - the month grid is the main content
- Removed the three summary boxes and the long description. The page starts
  with the title and "Add activity", then the calendar.
- Overdue activities show as one slim red line ("1 activity is past due")
  only when there are any; "Review" opens the activity.
- Desktop: month grid on the left; on the right a "Today / Selected day"
  panel listing that day's activities, "Needs attention" (only when
  something is overdue) and "Coming up".
- Click a date to select it; its activities list in the side panel.
  Double-click a date, or use the small + in its corner, to add an activity.
- Phones (Google Calendar style): each date shows short coloured title bars
  (up to 3, then "+n"); tap a date and its activities list below the grid
  with times, type and place. Single-letter weekday headings, short month
  name, icon-only Month/Agenda switch. The phone now opens in Month view.
- The colour key is folded under "Colour key" to save space.

## Cases - the case list is the main content
- Compact header: "My cases", "9 active - 3 need action", New case intake.
- One panel holds search, filters, views and the list. Filters (status,
  priority, opened dates, sort, active only) open from a "Filters" button
  that shows how many are set.
- Views as pills: Needs action, All, Active, For follow-up, Closure review,
  Closed, each with a count.
- Desktop and tablet: one table - client (initials, name, case number and
  classification), Next step, Area, Opened, Status (+ priority only when
  High or Urgent). Area and Opened hide on narrower screens.
- Phones: a light list instead of cards - name and status on one line,
  case number, town and date on the next, then priority (if high/urgent)
  and the next step. About half the height of the old cards.

## Smart assistance in the case list (rule-based, no external AI)
- Next step per case: the most pressing rule from the dashboard's
  "Kalinga suggests" (app/Services/SmartSuggestions.php: overdue referral,
  high-risk safety review, assessment not started, no note in 30 days, care
  plan review due). The link opens the right tab of the case.
- "Needs action" view: cases with at least one next step. Computed only on
  cases the user may open; no names are used.
- Smart search (resources/js/utils/caseSearch.ts): plain words in the search
  box become filters, for example "urgent follow-up opened this month".
  Kalinga shows what it understood as chips and offers "Search the exact
  words instead". Recognised: statuses, priorities, active/open, needs
  action/overdue, today, this/last week, this/last month, this year. Other
  words stay a name or case-number search. Runs in the browser; only the
  resulting filters go to the server, as before.

## Tests
- New: tests/frontend/caseSearch.test.mjs (4 tests);
  SmartSuggestionsTest::test_case_list_shows_next_step_and_needs_action_view_within_scope.
- Updated: foundation.spec.ts (filters open from the Filters button; the
  analytics monthly table opens from "View data table").

## OWNER REVIEW
- Next-step rules and thresholds are the same as the dashboard suggestions.
- Words recognised by the smart search (Filipino words can be added, for
  example "kagyat" for urgent, once the team agrees on them).

---

# Plan: Intake form and Cases table improvements (next phase)

## A. Intake form
Problems today: one very long page; the online intake (Cases/Create) and the
Field mode intake are two separate forms that can drift apart; free text
where a list would do; validation shows late.

1. One intake, two places. Build the intake as one set of step components
   used by both the online page and Field mode, so fields, rules and
   wording are identical. Offline storage stays the encrypted draft store.
2. Short steps, one topic per screen (mobile first, sticky Back / Next /
   Save draft bar):
   1) Child and safe contact  2) Situation and risk  3) Family and
   household  4) Referral source, notice and consent  5) Review and submit.
   A step list shows done / needs attention; any step can be reopened.
3. Smart help while filling in (rule-based):
   - Possible duplicate check when online: same name and birthdate among
     cases the worker may open, shown as a warning, never a block.
   - Age computed from birthdate; age-based guidance.
   - Address cascade (region > province > city/municipality > barangay)
     from the PSGC list already in the system (public/data/psgc-2q-2026.json).
   - Sections appear only when relevant (for example IP group only when the
     child is from an Indigenous community).
   - "Kalinga suggests" panel moves beside the step it relates to.
   - Review step lists missing required items with links back.
4. Autosave every change to the encrypted draft (online and offline), with
   "Saved on this device" / "Sent" status.
5. Tests: one browser test per step at 360 / 768 / 1440 px, offline intake
   through the shared form, sync, duplicate warning, required-field review.
OWNER REVIEW: step order and required fields against the DSWD/CEFMU intake
form; duplicate-check matching rule.

## B. Cases table
1. Sort by clicking column headers (name, opened, priority, status).
2. Quick look: open a side drawer from a row with stage progress, last
   progress note date, next calendar activity, open referrals and the next
   step, without opening the full case (need-to-know fields only).
3. Supervisor view: "Assigned to" column and a worker filter for roles that
   oversee other workers' cases (read-only for Central Office).
4. Show "On this device" for cases in the Field mode pack.
5. 25 rows per page with page-size choice; remember the last view per user
   (stored on the server, not in browser storage).
6. Optional export of the visible list (no narratives), audited, only if
   the owner approves it.
7. Tests: sort, drawer access per role, supervisor scoping, mobile list.
OWNER REVIEW: export permission; which fields the quick look may show.

## C. "Smarter" Kalinga - suggested direction
Keep the rule-based approach (works offline, explainable, no data leaves
the system) and extend it: next steps everywhere a case appears, plain-word
search on other lists (calendar, Connect), intake help as above, and a
Help search that answers "how do I..." questions. A generative AI
assistant would need an owner decision, a privacy impact assessment and a
data-sharing review before any case data is sent to an outside service.

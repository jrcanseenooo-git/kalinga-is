# Case details, progress notes and referral tracking (2026-10-20)

Built on your upload kalinga-is_2.zip (identical to the previous package).
Copy the files over the project (same paths), then run:

    php artisan migrate
    npm run build
    php artisan optimize:clear

Two ADDITIVE migrations (no existing rows are changed or deleted):
- 2026_10_20_000100_add_referral_tracking: referrals.outcome, referrals.status_reason,
  new table referral_events (status history).
- 2026_10_20_000200_add_action_and_next_steps_to_case_notes: case_notes.action_taken,
  case_notes.next_steps.

## 1. Bug: "Unprocessable Content" error page on the referral form
Cause: choosing "Documented consent" for a case with no active sharing consent was
refused with a bare 422 abort, which Inertia shows as the developer error page.
- The consent check now returns a normal field error under "Lawful basis".
- The New referral form knows which cases have active sharing consent: the consent
  option is disabled for those without it, with a note on where to record consent.
- Generally (bootstrap/app.php): any 409/422 refusal raised during a normal page
  form submission now returns to the form with the message (error key "form") and
  a toast, instead of the error page. JSON/axios endpoints are unchanged.
- Success and error messages from the server are now shown as a toast on every
  page (FlashToast.vue; HandleInertiaRequests shares `flash`). Before, messages
  such as "Referral draft created." were not displayed on most pages.

## 2. Referral tracking (from the earlier Kalinga referral design)
- Every status change is recorded in referral_events with who and when, optional
  remarks, the outcome (required when Completed) and the reason (required when
  Declined, Returned, Unavailable or Withdrawn). The transition is checked first,
  then the required outcome or reason.
- Referrals page: "Overdue follow-up" count; each row shows the follow-up due
  (response due 5 days after sending, completion due 30 days after acceptance -
  values from the earlier Kalinga SLA rules, configurable in config/kalinga.php,
  OWNER REVIEW: confirm against the local referral protocol); "Track" opens the
  step bar, recorded outcome/reason and full status history with the update form.
- "Service delivery by service type": referred, open, completed, did not proceed
  and average days to acceptance, computed from the referrals the user can see.
- Case > Services & referrals: the referral table shows the due date and a
  "Track" row with the same history.
- Referrals made before this change show their stored dates, labelled as such.

## 3. Case details (from the earlier Kalinga case page)
The Overview tab now opens with:
- Client profile with Personal / Address & contact / Family & support views
  (read-only; "Edit in Client & Intake" opens the editor).
- Case information: CEFMU classification, form of union, category, circumstance,
  mode of admission, referred by/date, intake completed, opened, case worker,
  education at intake, pregnancy or parenting, current risk; plus the existing
  record tiles and documentation checklist.
- Progress notes and case activity: one timeline of progress notes (with action
  taken / next steps), monitoring visits, referral updates and case status
  changes, newest first, with "Add note or visit".
Only data the case page already received is shown; no new data is sent.

## 4. Progress notes
New optional fields "Action taken" and "Next steps" on progress notes, shown in
the note history and the case timeline. Finalized notes stay unchanged; use an
addendum to correct them.

## Not included (flag for decision)
- Services provided with amounts / total assistance (earlier design) - needs an
  agreed list of assistance types and whether amounts may be recorded.
- Client location history map (earlier design) - needs a privacy decision on
  storing a child's coordinates.
- The Client & Intake tab still opens on the edit form; the read-only profile is
  on the Overview.

## Tests
- New tests/Feature/ReferralTrackingTest.php (consent field error, history,
  required outcome/reason, Inertia refusal to form, note fields).
- php artisan test: 410 passed. npm run test:frontend: 28 passed.
- Browser suite (your Playwright foundation suite, Chromium, isolated synthetic
  SQLite): all specs pass. referral-workflow.spec.ts updated (outcome on
  Completed, Track dialog check).
- The long-standing participation test failure was a test timing race: the
  shared confirmation dialog keeps the page inert for 200 ms while it closes, and
  the test typed the reviewer comment inside that window, so the comment was
  sent empty. The test now waits for the dialog to close. No product change.

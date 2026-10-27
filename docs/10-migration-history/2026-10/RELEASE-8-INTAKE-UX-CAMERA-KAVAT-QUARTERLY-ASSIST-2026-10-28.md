# Release 8 - Intake usability, camera capture, quarterly KaVAT, Kalinga Assist (2026-10-28)

Owner requests of 2026-10-28. Branch `feature-enhancements`.

## Removed

- **Consent register** (case page, Client & Intake): the Kalinga Intake
  privacy notice and the signed consent / assent form photo are the case's
  consent record. Removed: the register view, `cases.consents.store` /
  `cases.consents.withdraw` routes, `PlanningController::consent` /
  `withdrawConsent`, the `consents.record` permission (migration
  `2026_10_29_000500` deletes it). The `consents` table and rows are kept for
  history; an active sharing consent recorded there still counts.
- In its place the case page shows "Consent given at intake" (privacy notice
  method and time, the signed form with a View link) and lets the case team
  add or replace the form photo later (`cases.consent-form.store`, permission
  `cases.update`).
- Referrals on consent now need the signed form photo (or a former active
  sharing consent). Message: "This case has no signed consent / assent form
  yet. Add the photo in Client & Intake, or choose another documented basis."
- **Direct case reassignment** (earlier the same day; see Release 7 notes).

## Kalinga Intake

- Larger labels, fields (48 px high, 16 px text) and tick boxes; one card per
  section; fewer columns on mid-size screens.
- Text size control (normal / large / larger) on the intake and in the user
  menu. It applies to the whole app on that device (a reading preference in
  localStorage, no personal data).
- Address tick boxes start unticked (permanent address, caregiver, family
  members); the server default for a family member is also "not the same".
- "Mobile Number (*Parent or Child*)"; numbers typed with spaces or +63 are
  tidied to 09XXXXXXXXX; names typed all in capitals or small letters get
  proper capitals.
- Age check warns at 18 and over ("CEFMU cases are for children under 18").
- Family composition: "Add family member" never adds a second empty card
  (it opens the empty one), closing an empty card removes it, empty cards are
  left out, and a half-filled member (no name or no relationship) is flagged
  instead of silently dropped (it used to be lost). Kalinga offers to add
  the primary caregiver to the family in one tap.

## Photo of the signed form

- "Take photo" opens the device camera inside Kalinga (back camera on phones,
  webcam on laptops) with a frame guide and live light hints. Before, the
  security header blocked the camera completely (`Permissions-Policy:
  camera=()`), so only a file picker could open; it is now
  `camera=(self)` (Kalinga's own pages only; microphone still blocked).
- If the camera cannot open, "Use the phone camera app" and "Choose image"
  remain.
- Smart checks on this device: too dark, glare, faint writing, blurry, too
  small; "Make the text clearer" (black and white, scanner-like) turns on by
  itself for faint writing. The server still re-encodes the image and strips
  metadata.

## Dialogs

- All 25 browser `confirm()` boxes are replaced by Kalinga's own dialog
  (`askConfirm`, `ConfirmDialogHost`), with the same design as the form
  confirmation: icon, plain title, explanation, large buttons, red for
  actions that discard or end something. The leave-with-unsaved-work guard
  uses it too (the browser's own reload/close warning stays, as browsers
  require).

## KaVAT

- Owner information: the baseline KaVAT is done before intake in the
  project's separate tool (a Google Sheet with formulas) to identify the
  child. Decision: Kalinga records the baseline **result only**
  (`kavat_baselines`: date, empowerment %, safety %, final score and priority
  computed with the KaVAT formula, notes, optional photo of the sheet); no
  re-answering. Corrections are audited (`kavat.baseline_recorded`, old and
  new score).
- KaVATs started in Kalinga are **quarterly** (the quarter is shown, for
  example "Q4 2026 KaVAT"), at closure, or other. "Baseline" and "Review" are
  no longer offered (old records keep their labels).
- Reminders: "Record the baseline KaVAT result" and "Quarterly KaVAT due
  (Q4 2026)" on the dashboard, Kalinga Assist and the case page (due when no
  KaVAT was finalized this quarter and the case was opened before this
  quarter).
- Form: answers are large buttons (fixes the stretched round buttons),
  "Go to the next unanswered", empowerment / safety show "-" until answered
  (no more "Safety 100%" with nothing answered), history and the live score
  compare with the baseline.

## Dates (bug)

- The server clock is UTC, so between midnight and 8 AM Philippine time
  "today" in date checks was still yesterday: KaVAT, grants, intake, notes and
  other dates entered as today were rejected (this is the "one day off" seen
  on the KaVAT). All date rules now use `App\Support\PhilippineDate::today()`
  and the browser uses `manilaToday()`.

## PDF report (analytics)

- Printing hides the app frame on every page (header, sidebar, bottom bar,
  skip link), adds a report heading (area, period, prepared by, printed time,
  confidential note), A4 landscape with page numbers, totals first, charts
  two per row, cards never split. The demo report went from 8+ pages to 5.

## Kalinga Assist

- "Assist" button in the header on every page: what needs the user's
  attention (same rules as the dashboard, case numbers only, endpoint
  `assist.index`), tips for the page in view, and answers to typed questions
  from the Help guides (with everyday and common Filipino words such as
  "paano", "bata", "larawan"). Rule-based and explainable; no external AI.
- Navigation label "Office profile & signatories" (cut off) is now
  "Office & signatories".

## Owner review

1. Referral on consent now requires the signed form photo. Confirm this rule
   (the alternative is to accept the privacy notice alone).
2. Quarterly KaVAT due rule: calendar quarters, starting the quarter after
   the case was opened. Confirm.
3. Baseline final score is computed from the two rates with the KaVAT
   formula; the form shows it so it can be checked against the sheet.
   Confirm the sheet uses the same formula and rounding.
4. Photo quality limits (dark, blurry, small) are practical guides; adjust
   after field use if they warn too often.

## Tests

- PHP: `ClientContextWorkflowTest` (rewritten), `KalingaAssistTest`,
  KaVAT baseline, quarterly due and early-morning date tests in
  `KalingaFormsAndKavatTest`, `SecurityHeadersTest` (camera policy).
  `ConsentWorkflowTest` and `CaseAssignmentTest` removed with their features.
- Front end: `photoQuality.test.mjs`, `intakeText.test.mjs`, Manila date test.
- Browser: `intake-smart.spec.ts` (simulated camera, intake behaviour,
  Kalinga Assist), KaVAT baseline, analytics print; specs updated for the
  new dialogs.

# Batches 3 and 4 - Field mode, charts, Help & guides, smart assistance (2026-10-21)

No database changes in these batches. After copying the files:

    npm run build
    php artisan optimize:clear

## Batch 3 - Offline field workspace (GIDA)
Goal: the worker should not have to think about offline.
- New layout: a header with Online/Offline, "Field app ready" and last sync;
  a "Ready for no-signal areas?" checklist (device protected, app saved on the
  device, cases downloaded); three status cards (cases on device, waiting to
  sync, device security); quick links to New intake, Saved in field mode and
  Update a case. My devices and Conflicts stay as tabs.
- NEW INTAKE WITH NO SIGNAL AND NO CASE PACK: an intake needs no case data, so
  it now works as soon as the device is set up and unlocked, even before the
  case pack is downloaded or after it expires. Case updates still need a
  current pack.
  - How ownership stays safe without the pack: at setup the account id is
    sealed inside the encrypted passphrase verifier. Drafts and queued intakes
    open only for that account. Workspaces set up before this change keep the
    old rule (pack required) until they are set up again.
  - The server is unchanged: sync still requires the registered device, an
    unexpired authorization and a matching LGU. When signal returns, Kalinga
    refreshes the expired pack first, then syncs.
- "Intakes saved in field mode": every intake made on the device with its
  status (Waiting to sync, Syncing, Synced - case created, Will retry, Needs
  review); unfinished drafts can be continued.
- NO INTERRUPTION: when signal returns while an intake is being filled in,
  Kalinga shows "Syncing waits until you save the intake you are working on"
  and syncs only after it is saved (or cleared), then shows a notice with the
  result. When nothing is in progress it syncs right away.
- The sidebar Field mode card shows "n waiting to sync" (only a count is
  kept in browser storage, never case content).
- Offline intake shows the same "Kalinga suggests" guidance as the online intake.
- OWNER REVIEW: the case pack lasts 72 hours (server setting). Longer GIDA trips
  may need a longer period; intakes are no longer affected by it.

## Batch 4 - Charts, Help & guides, smart assistance
Charts (resources/js/Components/Charts): donut, area trend line, funnel,
ranked lollipop, waffle and column charts, each with printed values and a
"View data table" option. Analytics: status donut, referral pipeline funnel,
monthly intake line (withheld months are gaps, never zero), ranked areas,
classification and sex donuts, age columns, services waffle. Quarterly:
donuts, waffle and ranked areas. Only the age chart uses columns (a histogram).

Help & guides: rewritten around the actual screens, by role (each user sees
only guides for what they can do): first day, intake, case page, progress
notes, referrals, services, calendar, reports, LGU profile, Kalinga Connect,
field mode set-up and use, lost device, privacy, analytics. Search box answers
typed questions by word matching, with suggested questions; topic filter.

Smart assistance (all rule-based inside Kalinga, no external AI, works offline
where the screen works offline; rules are in the code for review):
- Dashboard "Kalinga suggests" (app/Services/SmartSuggestions.php): overdue
  referral responses/completions, high-risk cases without a safety review in
  7 days, no assessment 7 days after opening, no progress note in 30 days,
  care plan review due within 7 days. Shows case numbers only; opens the right
  tab (/cases/{id}?section=...).
- Intake "Kalinga suggests" (resources/js/utils/intakeGuidance.ts): emergency
  pathway for critical risk, immediate action for high risk, WCPU/medico-legal
  referral, PNP-WCPD/prosecutor coordination when the partner is an adult
  (RA 11596), officiant/facilitator note, maternal health, return to school,
  NCIP / NCMF coordination, age check, safety plan reminder.
- Progress notes: purpose suggests the service category; next steps prefilled
  with the response due date; privacy nudge when the child's name is typed in
  a note.
- Calendar: title fills from type and case; clash warning within one hour;
  case dates appear automatically.
- LGU profile: report readiness checklist and missing CPU roles.
- Analytics: "What the numbers say" findings (intake change, referral
  completion rate, referrals that did not proceed, active share, top area).
- Reports: draft text assembled from the record (background, actions taken,
  findings, recommendations, current status).
OWNER REVIEW: thresholds and wording of the rules.

## Mobile
Checked at 360 px and 768 px: dashboard, cases, case page and every case tab,
intake, calendar, LGU profile, analytics, quarterly, help, field mode, Kalinga
Connect, notifications, profile, both reports and the plan workspace. No
horizontal overflow (the LGU CPU table becomes cards; the report family table
scrolls inside its box).

## Test results (all batches)
- php artisan test: 419 passed.
- npm run test:frontend: 28 passed.
- Browser suite (Playwright, Chromium): 235 tests; after updating the specs to
  the new screens, every test passes (last full run 205 passed, the 30 that
  failed were rerun and pass). New browser tests: referral from a progress
  note to Completed, intake with no signal and no case pack then sync, sync
  waits for the intake in progress.

## Files removed
See REMOVED-FILES-2026-10.txt (two retired pages to delete).

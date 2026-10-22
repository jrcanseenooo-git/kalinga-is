# Batch 2 - Progress notes with referrals and services, case reports (2026-10-21)

Apply after Batch 1 (same package). Then run:

    php artisan migrate
    npm run build
    php artisan optimize:clear

One ADDITIVE migration:
- 2026_10_22_000100_add_note_referrals_and_services_provided:
  referrals.referred_to, purpose, case_note_id, lgu_cpu_member_id,
  recipient_user_id, mdt_case_access_id, target_date; new table
  services_provided (service type, detail, amount, date provided, provided
  by, linked referral and note, recorded by).

## 1. Referrals live in progress notes (earlier Kalinga design)
- The My work "Referrals" page is removed; /referrals now opens the dashboard.
  Dashboard "Provider response / Referral completion overdue" links open Cases.
- Case > Progress notes (was "Monitoring & review"): progress notes come
  first, visit records are the second tab. Buttons: Refer, Record service,
  Add progress note. A summary strip shows notes, open/overdue referrals,
  services and total assistance.
- Note type "Referral to CPU member / agency" opens referral fields:
  - Refer to: the CPU members listed in LGU profile & signatories, or a
    partner agency (WCPU, PNP-WCPD, prosecutor, PAO, school, DSWD FO,
    NCIP, NCMF, NGO, health office, BCPC, C/MSWDO).
  - Purpose: a list that fits the recipient (earlier design's mapping).
  - Service category: suggested from the purpose.
  - Expected by (optional) and basis for sharing (consent only when an
    active sharing consent is on record).
  - Next steps fill in "Follow up with ... by <response due date>".
- Saving creates the note and the referral (status Sent) together. If the
  recipient is a CPU member with a Kalinga account in the same LGU, that
  member gets case access for the referral only (MDT grant: case summary,
  stage, referral/service, selected notes) and a notification. The access
  ends when the referral is completed or closed.
- The note card shows the referral, its status and due date, and
  "Track / update" with the step bar, history and the status form.
  The Services & referrals tab lists the same referrals with Track.
- CPU members cannot create referrals.

## 2. Services provided (highlighted)
- Note type "Service provided": service type (medical, financial,
  transportation, legal, psychosocial, educational, shelter, food/relief,
  livelihood, other), specific service, amount (required for financial
  assistance, optional for medical/transport/educational/livelihood, not
  kept for others), date provided, provided by, optional link to a referral.
- Shown as a green card on the note, in the case Overview (count, total
  assistance, latest four), at the top of Services & referrals (table,
  totals by type) and in the case activity timeline.

## 3. Case Summary Report and Social Case Study Report
Buttons on the case header: "Case summary" and "Case study report".
- Layout follows the Kalinga report mockups: Kalinga header, title band,
  report number/date/preparer/designation/area box, LGU line with logo,
  numbered colour sections.
- Case Summary: case info, client info, background, presenting issue,
  actions taken (dated: notes, referrals, services), assessment/findings,
  care plan/recommendations, current status, prepared/noted by.
- Social Case Study Report: identifying information (with religion, IP
  group), family composition, background, problem presented, assessment
  sections, interventions and services (with total assistance),
  recommendations, current status, prepared/noted by.
- Prepared by: the assigned case manager with the position on their profile.
  Noted by: the office head in LGU profile & signatories.
- Names print as initials unless "Show full name" is chosen.
- Wording can be corrected on screen before "Print / Save as PDF"; on-screen
  edits are not saved. Health/SRH, restricted assessment sections and MDT
  notes are left out. Each generation is audit-logged.
- OWNER REVIEW: confirm the section order against the official DSWD SCSR
  format your office uses; the layout can be adjusted.

## Tests
- New tests/Feature/NoteReferralsAndReportsTest.php: referral note creates
  the referral, links the note, opens and later closes the CPU member's
  access; consent/foreign-LGU/missing-field refusals; CPU member refused;
  service amounts; future service date refused; both reports with
  signatories, excluded sections, scope and role refusals.
- Updated for the retired page: AssignedCaseScopeTest, OperationsAccessTest,
  ReferralLifecycleIntegrationTest; e2e referral-workflow.spec.ts now runs the
  referral from a progress note to Completed.

# Batch 1 - Navigation, calendar, LGU profile and dropdowns (2026-10-21)

Copy the files over the project (same paths), then run:

    php artisan migrate
    npm run build
    php artisan optimize:clear

Two ADDITIVE migrations (no existing rows are changed or deleted):
- 2026_10_21_000100_create_lgu_profiles: tables lgu_profiles and lgu_cpu_members,
  users.position, and the lgu.profile.view / lgu.profile.manage permissions.
- 2026_10_21_000200_add_event_details_to_tasks: tasks.event_type, tasks.location,
  tasks.details.

## 1. Sidebar
- Grouped as Case work (Cases, New case intake, Calendar), Coordination
  (Kalinga Connect), Insights (Analytics) and Settings (LGU profile &
  signatories, Administration).
- Badges: active cases you can open, and calendar activities due today or
  overdue (counts only, no case content).
- "My work" and "MDT referral pathway" are removed from the menu. Referrals
  move into the case progress notes in Batch 2 (the old page still opens by
  its address until then).
- A "Field mode" card at the bottom shows online/offline and, from Batch 3,
  how many offline records are waiting to sync. Help & guides sits under it.
- Mobile bottom bar: Dashboard, Cases, New case intake, Calendar, Kalinga
  Connect.

## 2. Calendar (replaces the Tasks tab)
- Tap any date to add an activity in a pop-up: type (home visit, monitoring
  visit, case conference, assessment/interview, medical/medico-legal,
  court/prosecutor hearing, referral follow-up, family meeting,
  report/deadline, other), case, title, date and time (Philippine time),
  place and logistics notes.
- The title fills itself from the type and case number until you type your own.
- Tap an activity to see details, edit, mark done or cancel. Done and
  cancelled activities stay visible (struck through) for 90 days.
- Dates from case records appear on their own, dashed: scheduled case
  conferences, intake safety review dates, care plan review dates and
  referral response/completion due dates. They change only in the case.
- Month view and Agenda view (Agenda opens by default on phones), colour
  legend, "Coming up" and "Needs attention" (overdue, with Mark done and
  Reschedule).
- /tasks now redirects to /calendar; dashboard links point to the calendar.

## 3. LGU profile & signatories (new; Settings)
Suggested label: "LGU profile & signatories".
- Office name, short name (CSWDO/MSWDO), address, contact number, email.
- Logo upload (PNG/JPG/WEBP up to 1 MB), stored privately and shown only to
  signed-in users who may see that LGU.
- Office head / supervisor: name, position, designation. Signs as "Noted by"
  on the Social Case Study Report and Case Summary (Batch 2).
- CPU composition: name, position/designation, office/agency, role in the
  CPU, optional link to a CPU member account in the same LGU, active flag.
- Report readiness checklist and a signature preview.
- Who can edit: the case manager for the LGUs they are assigned to, and the
  system administrator. Field Office users can view LGUs in their province.
  CPU members cannot open it. Every change is in the audit log.
- Each user can now set their position under Profile and security; it is
  printed under the preparer's name on reports.

## 4. Dropdowns instead of free text
Shared lists in resources/js/utils/caseOptions.ts (OWNER REVIEW: confirm the
lists with the project team; editing a list there changes every form). Every
dropdown keeps "Other (specify)", so older free-text values are kept.
- Intake: referred by (referral source), caregiver relationship; new fields
  civil status, religion, Indigenous Peoples group, highest education, and a
  family composition list (relationship, sex, birthdate, education,
  occupation). These were accepted by the server but had no input before.
- Client & Intake: relationship to client, lawful basis (consent register).
- Monitoring: visit place, addendum reason. Child participation: decision.
  Safety plan: responsible person.
- Care plan: service category and responsible office. Case conference
  actions: category and responsible office.
- MDT access: professional role, organization, purpose. MDT notes: category.
- Records governance: record type, hold reason, authority, recipient
  organization, purpose, lawful authority, delivery method.
- Provider directory: service type. Referral: service category.
- Offline intake: referred by; offline visit place.
Names, contact numbers and narratives stay free text.

## Tests
- New tests/Feature/LguProfileAndCalendarTest.php (scoped editing, CPU
  members, logo protection, Field Office view-only, calendar event fields,
  case dates on the calendar, editing someone else's activity refused).
- Updated: CalendarOpenActivitiesTest (closed activities now stay visible),
  WorkerActionQueueTest (/tasks redirects), e2e foundation.spec.ts (sidebar
  links, calendar pop-up).
- Final results for all batches are in BATCH3-4-FIELD-MODE-CHARTS-HELP-SMART-2026-10.md.

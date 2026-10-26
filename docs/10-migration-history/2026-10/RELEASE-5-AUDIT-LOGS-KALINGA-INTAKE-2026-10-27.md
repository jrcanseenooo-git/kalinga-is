# Release 5 - System audit logs and the 4-step Kalinga Intake (2026-10-27)

Owner requests of 2026-10-26: Phase 1 (audit logs, CEFMU incidence, renames,
alias) and Phase 2 (new intake, saved intakes) of the improvement plan.
Branch `feature-enhancements`.

## 1. System audit logs

- Every entry now carries the recommended fields: Log ID (`AUD-000125`),
  timestamp (Philippine time on screen), user, user role at the time, log
  type, module, action, record ID (the case number when the entry concerns a
  case), description, old value, new value, IP address and status.
- 14 log types (`App\Support\AuditCatalogue`): Authentication, User
  Management, Access and Permission, Data Creation, Data Modification, Data
  Deletion, Data Access, Workflow and Status, Document and File, Report
  Generation, System Configuration, Security, System Error, Integration and
  Synchronization. Known events are listed by name; new events are
  classified from their prefix (module) and last word (action and type).
- New logging:
  - account changes split into role assigned, activated, deactivated and
    area of responsibility updated, each with old and new value;
  - access denied (any refused page, once per user and page per minute);
  - security alert after 5 refused requests within 10 minutes;
  - application and database errors (error class and route only, never the
    message or data);
  - failed file upload, offline sync conflict, Google sign-in refused,
    form field saved, first administrator created, audit export.
- Administration > Audit logs: summary cards (today, failed or denied,
  security alerts, system errors), search, filters (date range, user, role,
  log type, module, action, status, record ID) and export to Excel (.xlsx)
  or CSV with the same filters. Exports are logged.
- Read-only: there is no route that edits or deletes an entry; PostgreSQL
  refuses UPDATE, DELETE and TRUNCATE (append-only trigger, unchanged).
  Passwords, codes, tokens, session values and similar keys are removed from
  the details before an entry is written.
- Existing entries: the migration fills only the new, empty columns (type,
  module, action, record ID, description, old/new value, role). On
  PostgreSQL the append-only trigger is switched off for that one statement
  and back on in the same transaction; no existing value changes. The role
  of older entries is the user's role at migration time.

## 2. Kalinga Intake (4 steps)

| Step | Content |
|---|---|
| 1 Privacy notice | Notice and how it was given; optional photo of the signed consent / assent form (camera or image). |
| 2 Child and safe contact | Name, alias or nickname, birth date, sex and profile; present address (case LGU) and permanent address ("same as present" tickbox); safe contact; primary caregiver with address ("same address as the child"); family composition cards with civil status and address. |
| 3 Case details | CEFMU classification (early marriage, early union, forced marriage, forced union; any combination) with short definitions; "Married": formal child marriage (civil, church/religious, traditional/cultural/customary) and informal union (cohabitation); married or cohabiting now / before / no (current or former CEFMU); partner's current age; length of the marriage or cohabitation; current residence; schooling, pregnancy, mode of reporting, referral, priority. |
| 4 Safety triage | CEFMU risks observed; Kalinga adds their points and suggests the initial risk level; a lower level than suggested needs a reason (recorded and audited). |

- Removed: the "Immediate response" step and the union form dropdown. Older
  records keep their values; reports fall back to them.
- After the case folder is created, the case opens.
- The same intake is used in Field mode (one component, `IntakeForm`), and the
  server checks both with one set of rules (`App\Services\CaseIntake`).
  Field-mode intakes saved by the previous version still sync.

### Risk points (option set)

- Risk factors and the level thresholds are stored in `reference_data`
  (sets `intake_risk_factors` and `risk_level_rule`) with points, order and a
  definition, so the form builder can edit them (Phase 7).
- Starting values (OWNER REVIEW, the team sets the points): 1 point per
  factor, "Immediate danger to the child's safety" 4 points; moderate from 1,
  high from 2, critical from 4 points.

### Consent / assent form photo

- The phone shrinks the photo to a JPEG (1600 px) before keeping it. The
  server accepts only JPEG, PNG or WebP up to 6 MB, decodes it and draws a new
  JPEG (2000 px), so only pixels remain: no hidden content, camera or
  location data. Stored privately as a case document, scan state
  `sanitized`, downloadable by the case team. Field-mode sync keeps only a
  fingerprint of the photo in its queue record.
- The Docker image now includes the GD extension and PHP upload limits of
  10 MB (file) / 12 MB (request).
- Hosting note: on the free Render plan the disk is wiped on restart. Before
  real cases, use private Supabase Storage (`FILESYSTEM_DISK=s3`).

### Saved intakes

- Once step 1 is done, answers save automatically (every few seconds and on
  each step). "Save and continue later" returns to the intake start page,
  which lists Saved intakes (masked name, step reached, last saved, deletion
  date) with Continue and Discard.
- Only the case manager who started it can see or open it. Answers are
  encrypted with the application key; the list stores only the masked name.
- Deleted when the case folder is created, when discarded, or after 30 days
  without changes (`kalinga:purge-intake-drafts`, daily 01:15). OWNER REVIEW:
  keeping period.

## 3. Names on forms

- New field: alias or nickname (intake step 2, and the case page client
  details).
- Reports: "Name on form" chooses Masked (default), Alias or Full name. The
  masked name follows the owner's example: "John Santos Dela Cruz" becomes
  `J**S***D**UZ` (first letters, last two letters of the surname). Contact
  numbers and other people's names show only with the full name.
- Same rule on the server (`App\Support\ClientName`) for the masked case
  lists planned for Project Managers.

## 4. Labels

- "Mode of admission" is now "Mode of reporting".
- "Occasion type" (child participation) and "Type of activity" (calendar)
  are now "Activity type".

## Database changes

| Migration | Change |
|---|---|
| 2026_10_27_000100_classify_audit_logs | audit_logs: log_type, module, action, record_ref, description, old_value, new_value, user_role; indexes |
| 2026_10_27_000200_add_cefmu_incidence_and_client_alias | cases: union_forms, union_status, partner_current_age, union_duration_months, current_residence; clients: alias |
| 2026_10_27_000300_add_option_set_fields_to_reference_data | reference_data: sort_order, weight, meta |
| 2026_10_27_000400_intake_addresses_risk_points_consent_form | clients: caregiver address, permanent_same_as_present; relationships: civil_status and address; cases: risk_points, suggested_risk_level, risk_level_reason, consent_form_type, consent_document_id |
| 2026_10_27_000500_create_intake_drafts_table | intake_drafts |

## Verification

- PHP: 477 passed on SQLite (2 PostgreSQL-only skipped) and 478 on PostgreSQL. New: SystemAuditLogTest (6), KalingaIntakeTest (6), field-mode consent photo sync.
- Migrations run on a copy of the review database: every existing audit entry classified, append-only trigger enabled again afterwards.
- Front end: type check clean, 34 unit tests pass (new: intake step routing and risk suggestion); Pint clean.
- Browser: 262 tests. First full run: 255 passed; the 7 failures were tests still using old labels ("Show full name", "occasion", lower-case "early union") and one sign-in navigation race. After updating them, all re-run tests pass (intake, saved intakes, field-mode drafts and sync, reports, client details, child participation).

## Owner review

- Definitions of the four CEFMU classifications and the marriage types.
- Risk points and thresholds.
- 30-day keeping period for saved intakes.
- KaVAT guideline issues noted in the plan (sample score 52 vs 54,
  unfinished sentence on refusals, no "not applicable" for children without a
  partner) - for Phase 4.

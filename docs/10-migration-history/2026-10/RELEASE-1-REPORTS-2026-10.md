# Release 1 - Case reports suite (2026-10-22)

No database changes. After copying the files:

    npm run build
    php artisan optimize:clear

Delete `public/hot` from any server copy (see "Deployment safety").

## Reports
All reports share one frame (resources/js/Components/Reports/ReportSheet.vue),
so the design is defined once.

### Design (flat, Kalinga branding, same layout)
- Same structure as before: brand / title band / report details header, LGU
  line, numbered sections, two- and three-column rows, signature block,
  confidentiality footer.
- Flat: no gradients, shadows or rounded cards; one Kalinga purple for the
  title band, section numbers and headings; thin grey rules; plain tables.
- Professional wording: formal sentences ("As of October 22, 2026, the case
  is in progress at the intervention stage. There is one (1) referral
  awaiting completion."), consistent "Not recorded" placeholders, sentence
  case values, government date style.
- Print: A4, page numbers "Page x of y", table headers repeat on each page.

### Reports (case header > Reports, or Overview > Case reports)
| Report | No. prefix | Content |
|---|---|---|
| Kalinga Intake Sheet (General Intake Sheet) | GIS | Intake details, identifying information, address and safe contact, parent/guardian, family composition, CEFMU situation, initial safety and risk, problem presented, immediate actions, social worker's assessment, recommendations, privacy notice and consent, signatures |
| Progress Notes | PN | Case information, summary of contacts, notes in date order (purpose, observations and assessment, action/resolution, next steps, recorded by) with addenda under the note they correct; optional period (From/To) |
| Intervention Plan (intervention plan and contract) | IP | Plan information, overall objective, goals with interventions, responsible persons, timeframe and status, latest plan review, conforme lines for the child and parent/guardian, signatures |
| Social Case Study Report | SCSR | Unchanged content, new design and wording |
| Case Summary Report | CSR | Unchanged content, new design and wording |

### Case reports panel (case Overview)
Replaces the old collapsed "Case documentation checklist". Lists the five
reports with Complete / In progress / Not started, a "Complete" button that
opens the right tab, and "Print". Adapted from the SIPAG eSCMS household list.

### Privacy in reports
- Initials by default; "Show full name" reveals names and contact numbers for
  the copy that goes into the official case folder. Contact numbers are
  otherwise masked (*******123).
- Only case-team progress notes are printed (MDT notes and restricted
  records are left out). Legacy notes without a contact time are dated by
  when they were recorded.
- FIXED: health content was printed. The Case Summary / Case Study
  "Recommendations" listed every care-plan goal, including reproductive
  health and mental health goals, although the report said health details
  were left out. Health-domain goals are now withheld in every report; the
  Intervention Plan shows only the area ("Health information. Details are
  kept in the case record"). The Intake Sheet shows only that health
  information exists.
- Every generation is audited (event case_report.generated, with the type
  and period).
- OWNER REVIEW: which sensitive items a CPU or Central Office copy may carry;
  conforme wording on the Intervention Plan.

## Bugs fixed
1. Health and SRH plan goals printed in reports (see above).
2. Non-numeric ids in URLs (for example /cases/undefined) caused a server
   error (500). Record ids are now numeric-only for all routes; anything
   else is "Not found" (404). Also a vulnerability-assessment finding.
3. Deployment safety: the uploaded project contained public/hot (left by
   "npm run dev"). On a server it makes every page load scripts from
   localhost:5173, so pages appear blank. Production now ignores it.
4. Legacy progress notes (no contact time) were missing from reports.
5. Unstable browser test for history privacy after logout (the test read
   storage between the two page loads of logout); the app was correct.

## Code structure
- app/Services/CaseReports.php - all report data and privacy rules
  (controller is now 40 lines).
- resources/js/Components/Reports/ - ReportSheet, ReportSection,
  ReportSignatures, FamilyTable, and one body per report.
- resources/js/utils/reportFormat.ts - dates, counts, initials, masking.
- resources/js/types/reports.ts - report prop types.

## Tests
- New: tests/Feature/CaseReportsTest.php (content, privacy exclusions,
  addenda, period filter and validation, health-goal withholding, access
  rules, tampered ids); tests/Feature/ProductionAssetsTest.php;
  tests/e2e/case-reports.spec.ts (menu, all five reports at 360 and 1280 px,
  accessibility scan, initials by default, period filter).
- php artisan test: 426 passed.

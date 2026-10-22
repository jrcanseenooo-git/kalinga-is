# Referral lifecycle integration - P3-A3

Updated: 2026-10-18. KEEP existing referral fields, lawful-basis choices, approved status graph and permissions. REFACTOR worklist UI and transaction boundary; no schema or new disclosure/chat workflow.

Pre-fix: responsive workflow test failed at inaccessible case selector; three backend regressions failed for missing permission-filtered transition props, ignored expected status, and inactive provider acceptance. Further negative test proved archived-case referral transition acceptance. Fixed using existing graph/Gates, optional expected_status for existing-client compatibility, transaction/row locks and archive/current-case authorization. Transition and metadata audit commit together; injected audit-storage failure rolls both back. PostgreSQL actual parallel-lock behavior remains production acceptance work.

Frontend uses typed records, shared flat PageHeader, labelled creation controls and complete validation errors, authorized case links, recorded lifecycle dates, permission-filtered status controls and accessible responsive filters. Existing global submission confirmation is retained. New referral provider validation accepts only currently active providers, matching the selector; historical referrals remain intact. CPU case-granted MDT and Kalinga Connect boundaries are unchanged.

Verification active: targeted 16/148 passed; build passed (54.79s) after correcting an unsupported Inertia reload option. Further audit/archived/revoked tests added. Full backend and final 360/768/1440 browser lifecycle/axe checks running. Initial post-fix browser run stopped at the existing generic submission confirmation; tests now exercise that real confirmation. Do not mark P3-A3 complete until final checks pass.

## P3-A3 verified - 2026-10-18

Full backend 289 tests / 1898 assertions (79.86s), build54.79s, 3/3 browser lifecycle creation-to-completion/reload/axe/overflow checks at360/768/1440 (1.0m) pass. UI uses existing controlled graph and permission-filtered actions, preserves generic confirmation and minimum-necessary case references, shows all create validation errors and recorded dates. Stale expected status is rejected; transition/audit rollback, archived/revoked/other-worker denial and inactive-provider rejection verified. Five new backend tests, no schema/live data/role change. Full runtime PostgreSQL/field/domain acceptance and partner-specific MDT UX remain outstanding.

Continuing P1-B1: typed, flat, accessible provider-directory component adoption with responsive create/filter cards; retain admin-only contact access and existing provider creation. This follows the documented P1-B reusable-screen adoption and directory module, without new disclosures or policy requirements.

One full-suite attempt aborted during vendor autoload with a transient BatchesTableCommand.php read error before tests; retry completed289/1898. This was an environment read failure, not a waived test.

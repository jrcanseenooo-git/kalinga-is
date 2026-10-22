# R06-C case reassignment integrity QA - 2026-10-18

Status: VERIFIED bounded integrity package. Final evidence below supersedes historical intermediate results.

Initial new regression run: 11 failed / 1 passed (29 assertions). Existing endpoint accepted outside-LGU, missing, expired, revoked and future memberships; stale versions were overwritten; candidate payload included ineligible workers; former-owner redirect led to denied content. Original audit rollback already passed.

Implementation: shared current LGU predicate for candidate listing and submission; active case-worker recipient and fresh active actor; existing Gates/read scope retained; case/user/membership locks; expected offline version; archived case rejection; transactional assignment/version/notification/audit plus final eligibility recheck. Safe known-route redirect and generic status notice after ownership changes. No role expansion, schema change or jurisdiction backfill. Missing canonical case LGU now requires authorized review before transfer while preserving existing legacy read access.

Verification: targeted 23 tests / 207 assertions pass (3.55s). Full backend 307 / 2084 pass (97.97s). Production vue-tsc/Vite build passes (23.58s). Six responsive workspace browser checks pass after changes. First transfer browser test timed out locating recipient select; investigate and rerun before verification. No browser transfer success claimed yet.

Affected source: CaseworkController; CaseAssignmentEligibility; LguAssignmentScope; Cases/Show.vue and Index.vue. Tests: CaseReassignmentIntegrityTest; corrected eligible legacy CaseAssignmentTest fixture; guarded separate synthetic browser transfer fixture and ui-transform test. Existing offline encryption/queue behavior is unchanged.

Conditional owner review: if operational legacy cases have no canonical lgu_id, an authorized jurisdiction mapping decision is needed. No real records queried or inferred from residence, and no automatic normalization performed. PostgreSQL locking/concurrency acceptance remains B06. Role policy B02 remains unchanged.

R06-C final verification: VERIFIED bounded package. Late-expiry rollback test18/150 passes. Full backend309/2109 (113.99s), frontend20 and real browser transfer13.5s pass; eight safety tests in same final9/9 run pass. Former-owner no longer sees transferred case, direct request403, eligible recipient login/detail/list works. Wrapped-label exact locator timeouts were test selection errors; native combobox role/name resolves them. Build23.58s; no schema or live data changes. PostgreSQL/production/domain acceptance remain open.

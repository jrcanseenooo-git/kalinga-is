# Offline readiness evidence

Date: 2026-10-17. This is a technical evidence register, not field acceptance.

Historical continuation note: R03-A changed online analytics only. The current full suite passed 243 tests / 1409 assertions, including existing offline server regressions. No offline implementation or disconnected-field test was repeated or newly claimed. Prior field limitations below remain unchanged; PROGRESS_TRACKER.md holds current-session evidence.

The application database was not reset or seeded. PHPUnit used the configured in-memory SQLite database with synthetic records. Browser foundation checks use a new temporary synthetic SQLite database on loopback port 8017; the initial foundation checks did not test disconnection. Later P3-O1/O2/O3 and R09-A/B evidence below includes actual disconnected saved-note browser runs.

| Scenario | Evidence this package | Status / remaining work |
| --- | --- | --- |
| Assigned case outside current LGU | AssignedCaseScopeTest::test_offline_pack_does_not_disclose_assigned_cases_outside_current_lgu failed before repair | Repaired using canonical case scope; full suite passes |
| LGU assignment removed before next pack | Same test deactivates assignment and expects empty pack | Automated pass |
| Case query assignment and LGU expiry | AssignedCaseScopeTest checks same-LGU other worker, cross-LGU own case, expired and inactive assignment | Automated pass |
| Existing offline device authorization, sync, intake and conflict regressions | OfflineDeviceManagement, OfflineWorkspaceAccess, OfflineSyncConflict, OfflineIntakeSync in full suite | Automated suite passes; detailed field validation still needed |
| No signal / reconnect / intermittent signal | Saved note and unfinished intake actual offline reload verified; queued intake retry/sync verified | P3-O7 bounded criteria pass; intermittent/slow real links and field acceptance pending |
| Slow 2G/3G and interrupted upload | Not exercised | Pending controlled network test |
| Session expiry while queue contains work | Generic401/403/419 queue retention; P3-O7 intake401/reload/retry/sync passes | Bounded recovery verified; deployment session/device policy pending |
| Duplicate sync / competing authorized edits | CAS concurrent append, stale draft revision, simultaneous same-draft promotion and UUID replay verified | P3-O4/O7 pass; PostgreSQL actual concurrency and field UAT pending |
| Browser closes during intake or queue write | Not exercised | Pending encrypted draft durability test |
| Storage quota exhausted | Injected QuotaExceededError during encrypted note append; retained input and one recovered item pass | P3-O3 note recovery verified; full intake/quota and cross-tab pending |
| Pack expires while disconnected | Existing implementation has 72-hour expiry | Pending field-clock/tampering review and device-policy approval |
| Device or membership revoked while disconnected | Immediate remote wipe is impossible without reconnection | Pending compensating safeguard and lost-device procedure acceptance |
| Cleanup/device reassignment | Confirmed revocation wipe failed before repair, then deletion/local lock pass; v11 shell startup/cache checks pass | Device reassignment, multi-tab memory and logout policy pending |

No CPU chat, MDT narrative, report output, new data category, or new local persistence was added to offline packs. The scope repair affects future pack downloads; it cannot recall a previously downloaded disconnected pack. Existing encryption, expiry, revocation and sync protocols remain unchanged. Policy owners must validate device controls and expiry limits.

## P3-O1 continuation - 2026-10-17

New actual browser evidence supersedes the earlier no-disconnected-experiment entries for a saved case note only: network disabled, encrypted note queued, service-worker reload, passphrase unlock, pending state retained, reconnect with exactly one sync request, synced state retained through another reload. Existing IndexedDB ciphertext check and expired-pack case/intake lock checks also pass. Tests use a fresh synthetic temporary foundation database. No offline implementation changes or new data categories. Unsaved intake interruption, quota, concurrent tabs/queue writes, revocation/device loss, clock tampering, slow links, conflict browser recovery and field acceptance remain pending. The expiry test title no longer claims queue preservation without exercising it.


## P3-O2 and R09-A verified continuation

Final browser run 11/11 passes, including encrypted-note durability, pack ciphertext, expiry, notification-free cached shell and confirmed revocation deletion. The revocation test failed before repair because open IndexedDB connections blocked deletion; the repair closes transaction connections and immediately removes decrypted field state on confirmed revocation. Shell v10 replaces prior shell caches on reconnection; encrypted record format is unchanged. Blocked cross-tab deletion has a recovery message; full multi-tab key/memory behavior remains pending. Next: quota failure/retry, queue concurrency, unsaved intake, shared-device owner binding and broader field UAT.

## Latest verified checkpoint - P3-O3 and R09-B

Final build passes; final isolated offline browser run 6/6 passes (48.2s): anonymous startup, actual disconnected saved-note reload/reconnect once, pack encryption, expiry, confirmed revocation deletion and injected quota/retry. A prior repeat failed before field setup at authentication; the authorized-only preparation/login-redirect guard repair is documented in PROGRESS_TRACKER.md. Shell v11 is current. No full offline-module, field or device-policy acceptance claim. Exact next P3-O4: preserve a second queued operation while a previous /sync response is in flight.



## P3-O4 verified - 2026-10-18

282 backend / 1839 assertions, 14 frontend tests, final build and 19 selected browser checks pass. Browser cases cover held ACK plus new append, concurrent tabs, lost ACK UUID retry, 401/403/419, quota after server commit, reload, local lock, mismatched ACK/conflict retention, 30 large notes, three responsive queue checks and five prior offline scenarios. Source/runtime surface checks show ciphertext, not queue narratives, in IndexedDB; no queue writes to localStorage, console or uncontrolled cache APIs. Shell v12 is current; disconnected old clients cannot be recalled instantly. PostgreSQL locking integration, multi-tab lock memory, account binding, intake drafts, conflict review and field UAT remain. P3-O5 account binding is next and already has a regression queued.

## P3-O5 / R09-C verified - 2026-10-18

Account switch disclosure failed before repair; original-owner recovery and other-account denial now pass. Two readiness repeats exposed an activated but uncontrolled page. v13 explicitly claims clients, confirms control plus HTML cache and supports retry preparation. Final build, 15 frontend tests and 6 selected browser cases pass (55.7s), including actual disconnection, ciphertext and Inertia-cache protection. No encrypted data reset or case-scope expansion. Original-owner passphrase/device policy, immediate disconnected revocation and broader field UAT are not certified. Next P3-O6 is explicit reviewed conflict retry.

## P3-O6 verification checkpoint - 2026-10-18

Implemented full saved-entry review before conflict retry, explicit confirmation, newer authorized base-version validation, unchanged UUID/content, original version/review metadata, and atomic changed/removed-item rejection. Cancellation makes no queue writes. A quota failure retains the conflict and permits recovery. No schema or production data changes.

Reproduced expired-pack narrative disclosure with a failing browser regression before the visibility fix. Conflict previews now require an unlocked, current owner-bound pack and case membership; review closes when that authorization disappears. Encrypted entries remain available for authorized recovery.

Evidence: full backend 284 tests / 1851 assertions passed (79.86s); frontend 17 passed; latest production build passed (24.43s). Selected browser run 10/10 passed (1.5m): controlled shell preparation/retry, HTML-only caching, concurrent queue append, acknowledgement quota recovery, reload and lock during held acknowledgement, account switching, explicit review with cancellation/quota/retry, expired narrative withholding, and existing field-save quota recovery. Additional removed-case and offline/revocation checks pending at this checkpoint.

No whole module is declared complete. Remaining offline work includes unfinished intake drafts, cross-tab decrypted-memory revocation, PostgreSQL concurrency, and field/device acceptance. B01-B06 remain policy/operational constraints.

### Final verified checkpoint - 2026-10-18

P3-O6 verified as a bounded recovery feature. Additional browser runs passed: 2/2 removed-case disclosure/rebase denial and confirmed revocation wipe (25.3s); 2/2 anonymous-shell protection and actual offline saved-note reload/reconnect exactly once (21.2s). Together with the selected 10/10 run, all 14 selected final browser checks passed. Backend full suite remains 284/1851, frontend 17/17, build 24.43s. No later application-source changes invalidate these results. No schema or live data changes. No whole module declared complete.

Exact next eligible task: P3-O7 - reproduce loss of an unfinished field intake on reload, then implement owner-bound encrypted draft recovery and atomic promotion into the upload queue; verify concurrency, quota, interrupted saves, validation, account/revocation/expiry boundaries and mobile accessibility before completion. Current working files are saved locally; no Git metadata is available for a commit. B01-B07 remain documented; none requires stopping this independent package.

## P3-O7 active - 2026-10-18

Unfinished intake loss on actual offline reload reproduced before repair. Encrypted owner/device-bound versioned autosave, safe recovery, atomic queue promotion with UUID/content receipt, stale-tab safeguards and metadata-only cross-tab security signals implemented. No schema/live database changes. Frontend 19/19, relevant backend 36/204, build 31.34s. Expanded browser checks running; P3-O7 not yet verified. See INTAKE-DRAFT-RECOVERY.md for evidence and limits. Next independent functional package after verification: P3-A3 structured referral lifecycle UI using existing approved transitions/Gates; other modules remain in scope.

## P3-O7 verified - 2026-10-18

Verified encrypted unfinished intake autosave/recovery and atomic queue promotion with unchanged draft/upload UUID, owner/device binding, fresh-pack checks, optimistic revisions, metadata-only promotion receipts, duplicate protection and recoverable quota/interruption errors. Expiry hides narratives; confirmed revocation wipes drafts; metadata-only cross-tab lock/revocation signals clear open workspace memory. No schema/live data changes. Before-fix actual offline reload regression failed; post-fix passes.

Evidence: full backend 284 tests / 1851 assertions (75.46s); targeted offline/scope 36/204; frontend 19/19; production build 31.34s. Expanded browser 16/16 passed (2.1m), including original offline reproduction, quota/aborted promotion rollback, interrupted draft save recovery, stale-tab separate recovery, ciphertext/log/cache surfaces, pack expiry/reauthorization, original-owner recovery, cross-tab lock/revocation and note regressions. Five additional checks pass: 360/768/1440 scoped accessibility and no overflow, simultaneous two-tab promotion yields one queue identity, and expired-server authorization retains intake for retry/sync. The accompanying unrelated-to-offline referral pre-fix regression is expected to fail and is recorded under P3-A3. Do not count that failure as offline success.

Limits: only committed edits survive a sudden process/device loss; saving/failed status and reload warning distinguish uncommitted edits. Remote revocation while disconnected remains impossible. BroadcastChannel cross-tab signals require browser support. PostgreSQL, real field devices/performance, policy and domain acceptance remain B01-B06. No whole module is declared complete.

Proceeding immediately to P3-A3 existing referral lifecycle UI and data-integrity checks. No additional offline work expands this package; all remaining modules stay in scope. Existing approved status graph/Gates will drive controls, with stale-status rejection and current provider eligibility. No new chat/disclosure/policy model is introduced.

## P3-O7-A final-review integrity follow-up - testing

A held-promotion test failed on still-editable intake fields while the promotion button was disabled. New edits during the final atomic transition could be cleared with the submitted snapshot. Added a disabled aria-busy intake fieldset for that exact interval. No storage/auth/schema changes. Final build and held-promotion/rollback/concurrent promotion checks pending; do not mark this follow-up verified yet. Next independent package remains P2-B4. This specifically addresses a demonstrated critical integrity gap, not indefinite offline expansion.


## P3-O7-A verified final checkpoint - 2026-10-18

Held-promotion regression failed before the field guard and now passes. The intake fieldset is disabled and aria-busy only during saving/promotion, preventing late edits being cleared with a submitted snapshot. No storage/auth/backend/schema change. Latest production build25.18s; final10/10 browser checks pass (1.4m): actual offline draft reload, quota/aborted promotion rollback, simultaneous same-draft promotion, held-promotion guard, online server-error-to-created-case at360/768/1440 with keyboard cancellation, local safety validation and encrypted conflict-review dialog regression. Frontend20/20 and full backend289/1898 remain applicable; no backend changes since that full run. All final processes have completed.

Four functional work packages P3-O7/P3-A3/P1-B1/P2-B3 plus this critical follow-up are verified bounded increments. No whole module or production acceptance claim. Exact next P2-B4 recorded safety/risk history and accessible review. Remaining modules and B01-B07 retained; no approval blocker prevents that independent work. State saved locally, no schema/live-data/remote/deployment changes.

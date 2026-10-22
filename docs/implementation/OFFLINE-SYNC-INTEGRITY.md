# Offline synchronization integrity evidence

Updated: 2026-10-18. Package P3-O4. This is technical evidence, not full offline/field or production acceptance.

## Reviewed behavior and reproduced defects

Existing Workspace sync iterated a reactive queue snapshot and called saveQueue(updated), overwriting newly appended work. The held-real-response browser regression failed before repair after a second saved item vanished. Existing add/remove/retry were independent decrypt/read/replace operations and were vulnerable to concurrent tabs. Three backend regressions also failed: no UUID echo, changed-content replay accepted, and successful-replay shortcut bypassed current LGU authorization. Tests were synthetic only. The usage-limit reviewer failure interrupted validation; it did not roll back source changes or establish a safety violation.

## Implemented contract and data flow

REFACTOR existing store/queue/controllers; KEEP AES-GCM/PBKDF2 format, assigned expiring pack, UUID identity, workflow permissions and status receipts. No schema migration or new data category.

- Each queue mutation reads encrypted operations, decrypts and transforms, encrypts with a fresh IV, then compares the old ciphertext/IV within one IndexedDB readwrite transaction before replacing it. It checks the same device metadata verifier and current local key epoch. A contender reloads and merges rather than replacing stale data. Twenty failed compare attempts surface a recoverable busy error. Transactions contain no awaited crypto or network calls.
- A sync pass snapshots at most 50 pending/failed items below the retry limit. Newly created entries remain for the next pass. Conflicts are excluded from automatic retry. Only status=synced plus the matching offline_id acknowledges a request. HTML/other JSON/mismatched UUIDs cannot clear work.
- Each result commits by local_id against the exact sent entry; an edited/retried/removed entry is not resurrected or overwritten. Acknowledged entries retain a metadata receipt with payload={} to minimize local data; failed/new/conflicted entries retain encrypted payloads. No whole-queue save remains in callers. Explicit discard remains a separate, confirmed user action and is disabled during local sync.
- An interrupted/unknown acknowledgement or quota failure leaves the same UUID recoverable. Server transaction locks serialize same-account retries; the UUID unique constraint remains. Retry checks owner/resource/content plus current device and normalized case/LGU authorization before returning an acknowledgement. Unsupported update operations are rejected, not silently inserted. PostgreSQL-specific concurrent lock behavior still needs deployment integration evidence.
- Local lock/unmount invalidates a pending sync session; key epochs/device metadata prevent late queue mutation after lock/wipe. Immediate remote revocation while disconnected remains impossible. Cross-tab UI/key-memory invalidation remains separate device-policy work.

## Privacy surfaces

No queue data or errors are written to localStorage/sessionStorage, browser console, telemetry or cache APIs. Ciphertext and existing passphrase/device metadata remain in IndexedDB. The case pack/queue are not service-worker cached. Field HTML shell excludes notification previews; shell version v12 advances the new frontend on reconnection. Account shell metadata remains as documented; disconnected old clients cannot be recalled immediately. Offline-intake notifications use generic text/worklist links without names, narratives or case numbers. Protected server audit records retain operational identifiers but not uploaded note content. Backend sync receipt payloads remain in the existing authorized database; retention/encryption infrastructure needs B04/B06 review.

## Evidence so far

- Before fix: one held-response browser failure and three backend failures.
- Initial repaired path: 15 targeted backend / 136 assertions; 14 frontend orchestration tests; build; eight browser integrity cases passed.
- Expanded backend: 32 targeted offline/deadline tests / 140 assertions passed; full suite 282 / 1839 passed (82.28s).
- An initial full run had 276 pass / one failing old deadline fixture at UTC/Philippine date rollover. The fixture now uses Philippine today, matching the existing command definition; production deadline logic was not changed.
- Final build passed (24.96s); selected browser run 19/19 passed (2.0m): held upload, concurrent encrypted tab append, lost ACK/retry, 401/403/419, ACK-persistence quota, reload, lock, mismatched ACK/conflict, large queue, scoped mobile accessibility and the six existing offline cases. This includes all 14 new integrity/large-queue/responsive cases and five old offline cases; the old pack-ciphertext case was not selected in this run.

## Remaining acceptance

PostgreSQL concurrent integration, unsupported-browser security signaling, slow-link/clock/device-policy field UAT and operational backup/scanner/privacy controls remain. Every other module stays in MODULE_COMPLETION_MATRIX.md. No whole module is marked complete from this package.


## Follow-up P3-O5 / R09-C (historical testing checkpoint)

Account-switch disclosure was reproduced before the owner guard; owner isolation and original-owner recovery now pass individually. A repeated actual-offline test exposed an activated but uncontrolled field page twice. This blocks final follow-up acceptance; it is not a queue-overwrite recurrence. Shared authorized field bootstrap now requests client claim, bounds readiness waits, requires page control plus current HTML cache, and exposes a real retry action. Service-worker writes await completion and reject Inertia JSON/login redirects. Shell v13 is current. Five ownership/bootstrap/disconnection/ciphertext cases plus two cache/retry cases are the final verification focus. Backend remains the verified 282 / 1839 baseline; frontend is 15 passing cases. Do not claim full field readiness or policy acceptance from these bounded repairs.


## P3-O6 verification checkpoint - 2026-10-18

Implemented full saved-entry review before conflict retry, explicit confirmation, newer authorized base-version validation, unchanged UUID/content, original version/review metadata, and atomic changed/removed-item rejection. Cancellation makes no queue writes. A quota failure retains the conflict and permits recovery. No schema or production data changes.

Reproduced expired-pack narrative disclosure with a failing browser regression before the visibility fix. Conflict previews now require an unlocked, current owner-bound pack and case membership; review closes when that authorization disappears. Encrypted entries remain available for authorized recovery.

Evidence: full backend 284 tests / 1851 assertions passed (79.86s); frontend 17 passed; latest production build passed (24.43s). Selected browser run 10/10 passed (1.5m): controlled shell preparation/retry, HTML-only caching, concurrent queue append, acknowledgement quota recovery, reload and lock during held acknowledgement, account switching, explicit review with cancellation/quota/retry, expired narrative withholding, and existing field-save quota recovery. Additional removed-case and offline/revocation checks pending at this checkpoint.

No whole module is declared complete. Remaining offline work includes unfinished intake drafts, cross-tab decrypted-memory revocation, PostgreSQL concurrency, and field/device acceptance. B01-B06 remain policy/operational constraints.

### Final verified checkpoint - 2026-10-18

P3-O6 verified as a bounded recovery feature. Additional browser runs passed: 2/2 removed-case disclosure/rebase denial and confirmed revocation wipe (25.3s); 2/2 anonymous-shell protection and actual offline saved-note reload/reconnect exactly once (21.2s). Together with the selected 10/10 run, all 14 selected final browser checks passed. Backend full suite remains 284/1851, frontend 17/17, build 24.43s. No later application-source changes invalidate these results. No schema or live data changes. No whole module declared complete.

Exact next eligible task: P3-O7 - reproduce loss of an unfinished field intake on reload, then implement owner-bound encrypted draft recovery and atomic promotion into the upload queue; verify concurrency, quota, interrupted saves, validation, account/revocation/expiry boundaries and mobile accessibility before completion. Current working files are saved locally; no Git metadata is available for a commit. B01-B07 remain documented; none requires stopping this independent package.


## P3-O7 subsequent verification - 2026-10-18

Unfinished encrypted intake drafts and atomic promotion are now verified in INTAKE-DRAFT-RECOVERY.md, including real offline reload, quota/abort rollback, concurrent revisions/promotion, owner isolation, expiry/reauthorization, metadata-only cross-tab lock/revocation clearing and privacy surfaces. Frontend19 at package acceptance; current20 includes online intake helper. Full backend289/1898, current build33.17s. P3-A3, P1-B1 and P2-B3 functional/UI packages subsequently verified. Exact next P2-B4 recorded safety/risk history; no indefinite offline expansion. Shell v14 is current. Old active/next-task paragraphs above are historical checkpoints.

# P3-O7 encrypted intake drafts - active QA

Updated: 2026-10-18. Initial automated real network-off/reload regression failed: SyntheticRecoverable became an empty first-name field after unlock. No application changes had been applied before reproduction.

KEEP existing intake fields, privacy notice validation, LGU authorization, encryption and server sync. REFACTOR unfinished field-intake persistence. New draft records are encrypted with the existing workspace AES-GCM key in IndexedDB; no localStorage, cache, URL or log writes. Drafts bind user and device inside ciphertext. Mutations require an unexpired owner-bound pack and compare pack ciphertext and device verifier in the committing transaction. Revision conflicts retain entered text and expose explicit retry/separate-draft recovery.

Completed draft promotion uses one transaction for encrypted drafts and queue. The draft UUID becomes the upload UUID; the draft becomes an encrypted metadata-only receipt with content digest. Concurrent promotion of the same content returns the same queue identity; changed content and stale edits cannot resurrect a promoted draft. Per-entry server idempotency and current authorization remain enforced. No schema or server data migration.

Autosave status distinguishes saving, committed and failed edits. Browser close/reload warns about uncommitted edits. Sudden device/process failure before a successful commit can recover only the last confirmed saved revision; no impossible guarantee is made for uncommitted keystrokes. Quota/interruption guidance retains entered text while the page remains open. Expiry hides narratives and preserves ciphertext for fresh authorized recovery. Confirmed revocation deletes the workspace; metadata-only BroadcastChannel lock/revocation signals clear open workspace tabs where supported. Immediate remote revocation without a connection remains impossible.

Active evidence: frontend 19/19; targeted backend 36/204; build 31.34s. Initial browser expansion: 4 passed; 2 promotion scenarios correctly failed server authorization because the test used Tabuk City while the normalized synthetic LGU is City of Tabuk. Fixture corrected; no production authorization change. Final expanded browser checks are running. P3-O7 remains in testing until acceptance evidence passes.

Remaining operational acceptance: production PostgreSQL concurrency, field device/clock/performance, device sharing/retention/recovery policy and full form domain/UAT. These are not permission to delay other safe functional/UI modules indefinitely. After P3-O7 verification, proceed to P3-A3 structured referral lifecycle UI, using existing approved transitions and server gates; no new disclosure or official policy is inferred.

## P3-O7 verified - 2026-10-18

Verified encrypted unfinished intake autosave/recovery and atomic queue promotion with unchanged draft/upload UUID, owner/device binding, fresh-pack checks, optimistic revisions, metadata-only promotion receipts, duplicate protection and recoverable quota/interruption errors. Expiry hides narratives; confirmed revocation wipes drafts; metadata-only cross-tab lock/revocation signals clear open workspace memory. No schema/live data changes. Before-fix actual offline reload regression failed; post-fix passes.

Evidence: full backend 284 tests / 1851 assertions (75.46s); targeted offline/scope 36/204; frontend 19/19; production build 31.34s. Expanded browser 16/16 passed (2.1m), including original offline reproduction, quota/aborted promotion rollback, interrupted draft save recovery, stale-tab separate recovery, ciphertext/log/cache surfaces, pack expiry/reauthorization, original-owner recovery, cross-tab lock/revocation and note regressions. Five additional checks pass: 360/768/1440 scoped accessibility and no overflow, simultaneous two-tab promotion yields one queue identity, and expired-server authorization retains intake for retry/sync. The accompanying unrelated-to-offline referral pre-fix regression is expected to fail and is recorded under P3-A3. Do not count that failure as offline success.

Limits: only committed edits survive a sudden process/device loss; saving/failed status and reload warning distinguish uncommitted edits. Remote revocation while disconnected remains impossible. BroadcastChannel cross-tab signals require browser support. PostgreSQL, real field devices/performance, policy and domain acceptance remain B01-B06. No whole module is declared complete.

Proceeding immediately to P3-A3 existing referral lifecycle UI and data-integrity checks. No additional offline work expands this package; all remaining modules stay in scope. Existing approved status graph/Gates will drive controls, with stale-status rejection and current provider eligibility. No new chat/disclosure/policy model is introduced.

## P3-O7-A critical promotion follow-up - testing

Final review found that fieldSaving disabled the promotion action but left draft fields editable during the transaction. A held-SHA256-promotion browser regression failed: first-name input remained enabled. Draft text entered after the promotion snapshot could be cleared on success. The intake fields are now a disabled, aria-busy fieldset for the exact persistence/promotion interval. Draft retry/recovery controls already respect fieldSaving. No storage/auth/schema change; same stable UUID and atomic transaction retained. Final build and held-promotion/rollback/concurrency/browser checks pending. This is a concrete integrity dependency, not an expansion into unrelated offline features.


## P3-O7-A verified final checkpoint - 2026-10-18

Held-promotion regression failed before the field guard and now passes. The intake fieldset is disabled and aria-busy only during saving/promotion, preventing late edits being cleared with a submitted snapshot. No storage/auth/backend/schema change. Latest production build25.18s; final10/10 browser checks pass (1.4m): actual offline draft reload, quota/aborted promotion rollback, simultaneous same-draft promotion, held-promotion guard, online server-error-to-created-case at360/768/1440 with keyboard cancellation, local safety validation and encrypted conflict-review dialog regression. Frontend20/20 and full backend289/1898 remain applicable; no backend changes since that full run. All final processes have completed.

Four functional work packages P3-O7/P3-A3/P1-B1/P2-B3 plus this critical follow-up are verified bounded increments. No whole module or production acceptance claim. Exact next P2-B4 recorded safety/risk history and accessible review. Remaining modules and B01-B07 retained; no approval blocker prevents that independent work. State saved locally, no schema/live-data/remote/deployment changes.

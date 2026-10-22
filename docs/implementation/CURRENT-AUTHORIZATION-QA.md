# P5-H2 current authorization and unsaved browser navigation QA - 2026-10-18

Status: VERIFIED bounded increment. Final evidence and remaining limits are in the final checkpoint.

Three real-browser regressions failed before repair:
- Authorized FO actor reassigns a dedicated synthetic case; original worker's encrypted Back restores stale case props without fresh GET, despite server403 on new access.
- Authorized reassignment removes worker's current case access; section save correctly returns403, but sensitive child editor remains rendered with unsubmitted text.
- Native Back discards unfinished participation context without cancellation; expected case URL/input retention fails and registry is shown.

Server case-scope controls are intact; these are stale client presentation and navigation/work-loss defects. No new permissions or domain policy are involved.

Implementation: current server/controlled-field-shell authorization on visited Inertia history entries; key clearing through supported router.clearHistory; plaintext legacy entries still discarded. Shared unsaved-work registry protects native Back, ordinary page navigation, logout and reload, with cancellation restoring the current URL/encrypted state and live input. Confirmed403 bypasses discard confirmation, hides authenticated layout content and reopens the current URL for fresh authorization. Assessment and participation JSON clients handle403; shared Inertia invalid403 handler covers form clients. Risk form guard uses an acknowledged-data baseline so successful saves do not leave false dirty state. Offline encrypted stores/queues and their recovery protocol are untouched.

Limits: this does not remotely erase knowledge/data already viewed, unvisited historical browser copies, or guarantee disconnected immediate revocation. Unsaved online memory is discarded after confirmed access denial; network/validation failures retain it, and native Back may be cancelled. Durable encrypted online-draft/re-authentication continuity remains P2-B5. No automatic offline encrypted-store wipe is added.

Pending: collect production type/build result; rerun actual external reassignment, confirmed-denial and native Back cancellation; verify authorized history/reload/logout, field disconnected reload, saved risk clean state and all affected case workflows. UI-08 correction test helper now waits for selected-record heading before recording the response, avoiding an earlier pending GET race. No acceptance or security-compliance claim until verified.

## Final bounded verification

Status: VERIFIED bounded increment. Final evidence and remaining limits are in the final checkpoint.

## Resume verification checkpoint - 2026-10-18

Newly VERIFIED bounded packages: UI-08 child participation and P5-H2 current authorization/navigation. Full backend329 tests/2253 assertions (68.17s), frontend24/24, production build20.03s. Browser verification was split:13 workflow/responsive/recovery passes in the broader run before a final document-navigation test synchronization failure; final6/6 focused checks (1.1m) passed after awaiting the actual403 document. No failing acceptance check is omitted: new occasion, all8 sections at360/390/768/1024/1440/1920, independent return/resubmit/review/finalization, source separation, correction/addendum, retained context, confirmed denial, native Back cancellation, external reassignment, legacy/authorized history, logout, safety, assessment and disconnected intake/note recovery passed. QA records explain exact limits; no whole module or production acceptance.

Continued into UI-09 case-planning audit: R06-D formal-handover completion was reproduced assigning a case after recipient LGU revocation (204 instead of422). Completion now locks case/transfer/users/memberships, rechecks fresh actor/recipient access, and writes ownership/version/history/audit in one transaction. Full329 includes four negative eligibility cases; subsequent33/301 targeted checks include injected audit failure with full rollback. No schema/live data/permission/remote/deployment changes. Existing valid legacy fixture now explicitly provisions recipient membership; production authorization is not relaxed.

Exact next task: finish R06-D acceptance (real-browser valid formal handover and denied revoked-recipient behavior, selection/stale-state/legacy policy review), then UI-09 case planning transformation against08_Case_Plan.png. Current formal-handover list/create still uses legacy municipality eligibility and must be reviewed; bounded completion repair does not certify the full handover module. Retain P2-B6 concurrency/atomic audit review, P2-B5 encrypted online continuity, P2-C2 Philippine timestamps and every remaining module. B01-B07 remain unresolved; no owner input blocks this next safe work.
# Blockers and review decisions

Updated: 2026-10-18. No owner input is needed for R03-A's enforcement of the existing threshold-five and permission catalogue. No blocking condition is inferred from missing progress files.

| ID | Decision / constraint | Affected work | Safe continuation |
| --- | --- | --- | --- |
| B01 | Secondary suppression, repeated queries, linked totals, longitudinal differencing and approved export release policy | R03-B, aggregate release acceptance | Repair primary small-count leaks now; document remaining inference risk, no compliance claim |
| B02 | Distinct supervisor/management roles and contradictions in older role documentation | Material access-model changes | Preserve current server Gates/scopes; review explicit assignments |
| B03 | Official CEFMU forms, signatories, report approvals and approved source fields | Official report generation/release | Draft labeling and current allowlist remain safe |
| B04 | Retention, CPU attachments/sharing, lawful basis, controller and device policy | New disclosures, storage/retention expansion | Preserve existing online-only chat/MDT/report boundary and encryption |
| B05 | Actual applicable CANE+D/CEFMU workflow text and legacy deadline approval | New fields/states/scores/deadlines | Preserve approved existing baseline; no invented requirements |
| B06 | PostgreSQL integration, backup restore, scanner deployment and field/offline UAT not verified | Production acceptance | Synthetic tests, source review and isolated browser tests continue |
| B07 | Source folder has no Git metadata | Commit/diff evidence | Record exact file/task/test changes; no remote creation |

These are production/policy acceptance constraints, not a request to stop safe implementation. Do not reset databases, deploy, add sensitive fields or change disclosure rules to bypass them.

Current continuation: R06-A/B, working drafts, worker queue, case-list/workspace increments and Philippine task times are verified. P3-A1/A2 Connect and P3-O1/O2 durability/revocation plus R09-A shell minimization are verified. P3-O3 quota recovery and R09-B authenticated startup are verified. B01 remains unresolved: primary suppression does not stop subtraction across published totals or repeated filters. Existing UTC timestamps were not backfilled; legacy task-time interpretation needs review. No new approval blocker prevents independent work. Tests do not resolve B06 or establish legal compliance.


Technical follow-ups (not approval blockers) - 2026-10-18: P3-O4/O5/O6/O7 queue, ownership, conflict and draft recovery are verified bounded packages. Same-browser supported-channel lock/revocation memory clearing is tested. Actual disconnected remote revocation remains impossible and older/unsupported browser device policy remains unresolved. P3-A3 referral integrity, P1-B1 directory and P2-B3 intake recovery are also verified. Owner UI priority now schedules UI-01/UI-02/UI-03 before P2-B4, which remains in backlog. Safe UI work proceeds without owner intervention. PostgreSQL/real devices/slow links/backup/scanner/domain acceptance remain B06; no full offline or legal compliance claim.

B05 service accountability review - 2026-10-19: Decide which users may be recorded in intervention responsible_user_id, including role, active status, LGU/case-grant eligibility and approval authority. Existing endpoint checks existence only; no new person selector or permission grant is added. Existing responsible-office entry, service provider selection and outcome recording continue safely. This is a review flag, not a reason to stop independent work.

B05 conference record/event lifecycle review - 2026-10-19: The existing API allows narrative, attendance, decisions and actions only while status=draft; marking scheduled/in_progress makes those entries read-only until independent return to draft. Decide whether those statuses represent the meeting event or the reviewed record, and whether the author may continue recording before for_review/final. Preserve current server rules pending that decision; verified save-first guards prevent unfinished entries being stranded by transitions/rescheduling. Independent review, final immutability and linked addendum remain unchanged. Existing confidentiality labels are recorded metadata within current case access; an additional label-specific disclosure policy is not inferred. This does not block safe work on other modules.

B05 closure provenance/retention review - 2026-10-19: Specify review handling when a legacy closure author is unknown, and approve the applicable retention-review interval. Existing behavior permits legacy unknown-author review and sets a one-year review date; neither is certified as a DSWD/legal requirement. The current technical work preserves historical evidence and current Gates, without inventing an author, legal deadline or policy approval. This does not block history-safe technical implementation.

B05 MDT completion semantics review - 2026-10-19: Decide whether one completed action or all required actions terminate an expire-on-completion assignment, and specify the intended behavior when expire_on_completion=false. Existing endpoint sets a terminal completed grant on action completion; the atomicity repair preserves that behavior and normal completed-grant denial. This is a domain review flag, not a blocker to fresh authorization, audit rollback, accurate readback or independent UI work. Selected-note/document sharing flags also require an approved selection/disclosure workflow before expanding the actual shared payload.

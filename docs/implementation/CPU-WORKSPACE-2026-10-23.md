# CPU assignment workspace delivery - 2026-10-23

## Review and classification

Owner requested a usable CPU referral screen for presentation. KEEP existing
grant eligibility, category allowlist, notes/finalization/addenda, actions,
quarantine, expiry and server authorization. REFACTOR selection and request
state. REDESIGN the case-specific workspace and worklist links with the
existing flat white/purple design. No role grants, case records, legal rules or
policy deadlines are invented or changed.

The reported local Health Officer case had two active assignments for the same
CPU member/case. The earlier assignment contained an action; the later did not.
Both worklist cards linked to the case alone, and the endpoint always selected
the latest assignment. The old page also omitted referral purpose/remarks,
action instructions, permitted summary/plan/referral context and note findings/
recommendations. Its Grant status card incorrectly used the case status.

## Implemented behavior

- My referred cases cards identify their assignment and unanswered-action count,
  and link with an explicit mdt_access_id. Work is ordered by unanswered actions
  and assignment date/ID. The CPU can select between their own currently active
  assignments for that case.
- Every CPU read/write keeps the selection pinned to the same eligible grant.
  Explicit invalid, foreign, revoked or ended selections are denied without
  fallback. Case-only links select an assignment with an unanswered action
  before the latest ID. Actions and category permissions are never merged.
- The screen starts with referral purpose, referral note when recorded,
  referring staff member, role/office, assigned time, actual assignment status
  and access expiry. What you need to do shows action titles, full instructions,
  due dates, response state and actual action controls.
- Case status and stage are separately labeled. Summary, case plans and service
  referral metadata render only when returned by the selected grant's existing
  allowlist. Client identity, restricted assessment, Health/SRH and Child
  Participation payloads remain excluded. Selected-note/document category flags
  are not interpreted as authority to expose the general record repository.
- Notes display findings and recommendations, saved draft/final state and
  linked addenda. Finalization remains server-enforced and immutable. Files
  remain assignment-scoped and download links include the selection; pending
  scans are visibly unavailable.
- Case discussion remains a secondary, collapsed section. Its existing
  case-wide visibility to authorized case participants is stated explicitly;
  it is not a private assignment thread or the LGU group chat.
- Philippine time helpers replace raw UTC display. The layout has no new
  gradients, oversized decorative artwork or table-only mobile interaction.

## Recovery and privacy

Typed state is handled by useMdtWorkspace and types/mdt.ts. Drafts/files remain
in tab RAM only, with the existing unsaved-navigation guard. Assignment changes
and action responses require saving or explicitly discarding unfinished work.
The selected assignment ID alone may appear in the URL; purpose, notes, client
identity and discussion text do not. Changed selection preserves history state
and updates only that numeric URL selector, allowing an explicit refresh to
retain the selection.

Writes lock while awaiting acknowledgement. Validation failures retain entries
and expose an accessible field summary. Unknown outcomes stop further writes;
refresh and explicit review are required before another attempt. No automatic
retry or server-wide idempotency claim is made. After confirmed denial, loaded
context, notes, files, discussion and draft text are cleared; another active
grant is not substituted. Connectivity is required, and tab drafts do not
survive a browser restart. This module is not added to offline packs/storage.

Completing an action follows the existing endpoint semantics and returns to
the worklist instead of refetching an ended grant. The UI warns that completion
ends active access under the current workflow. B05 remains: determine the
intended behavior for multiple assigned actions and expire_on_completion=false.
This delivery does not change that domain policy. Existing attachment
filesystem/audit atomicity and deployed scanner validation remain separate
review items; quarantine and download controls are preserved.

## Affected modules and data impact

Backend: MdtCollaborationController uses the existing MdtGrantScope for selected
read/write authorization, action priority and worklist counts. The read model
adds referring staff id/name, own note author id and attachment scan state.
Frontend: Mdt/CaseWorkspace.vue is a small route wrapper;
Components/Mdt/CpuAssignmentWorkspace.vue handles presentation;
useMdtWorkspace.ts handles requests and recovery; types/mdt.ts defines DTOs;
Mdt/Workspace.vue links to the correct assignment. In-app Help and
architecture documentation describe actual behavior.

No migration, schema change, grant revocation, reassignment, deletion,
backfill or demonstration-case write was needed. Read-only verification in the
actual local PostgreSQL database confirmed that the Health Officer account now
defaults to assignment 2 with one action, while explicit assignment 3 retains
zero actions. Both keep the same distinct assignment status and case status.
No data was pushed or deployed externally. Source and production build outputs
are saved in the project folder; existing absence of Git metadata limits
commit/diff provenance.

## Verification

```text
php artisan test
php artisan test --filter=CpuAssignmentWorkspaceTest
npm run test:frontend
npm run build
node node_modules/@playwright/test/cli.js test -c playwright.cpu.config.ts
```

Full backend: 461 passed, 2 PostgreSQL-only checks skipped, 3408 assertions.
Frontend unit checks: 33 passed. Production build passed. Focused existing
MDT/access checks: 42 passed, 296 assertions. New selection tests cover pending
action priority, exact-link selection, category separation, pinned notes and
responses, invalid/foreign/revoked selection and other-CPU isolation. A further
selected-attachment/quarantine regression is recorded with final results below.

The first new backend fixture omitted the mandatory case opened_at timestamp;
it was corrected without relaxing the schema. The first browser fixture used
created_by instead of authored_by/plan_date for a case plan; corrected against
the existing schema. The first browser run reached 10/11 passing checks, with
the note-category locator revealing implicit-label ambiguity. The actual label
was changed to an explicit for/id association. No lifecycle assertions were
removed. The final browser run also checks unknown-save recovery before the
terminal completion workflow. Final results and visual review follow below.

Screenshots are under evidence/cpu-2026-10-23 at 360, 390, 768, 1024, 1440 and
1920 CSS pixels. Browser tests use a fresh guarded synthetic SQLite database,
not the local demonstration PostgreSQL database. Field-device UAT, scanner
deployment and PostgreSQL concurrency acceptance remain outside this bounded
screen delivery.

## Suggested presentation

Final verification: 12/12 CPU browser checks passed (1.9 minutes), including
actual saved note/findings, finalization, retained linked addendum, blocked
assignment/action transitions with unfinished entries, denied-access clearing,
unknown-save recovery without automatic duplicate writes, six-width axe/
overflow checks and terminal action response/completion. Final targeted backend
selection/file tests: 5 passed, 68 assertions. PHP style checks passed. The
production build and 33 frontend checks also passed after the category label
was repaired. Desktop/tablet/phone screenshots were visually inspected for
hierarchy, stacking and form/readback clarity. No whole-system or operational
production acceptance is inferred.

1. Sign in with the existing Health Officer CPU account.
2. Open My referred cases, find DEMO-KLG-2026-0002 and choose the assignment with
   a pending action. Read the purpose, referring worker and requested work.
3. Show the detailed action instructions and due date, then permitted context.
4. Demonstrate saving a note only on a fictional demonstration record. Explain
   finalization and the linked-addendum correction path.
5. Show the selector for the separate active assignment and explain why its
   task list differs. Do not create duplicate tasks to work around selection.
6. Complete an action only if ending that demo assignment's active access is
   intended. Preserve the pending action for repeated demonstrations otherwise.

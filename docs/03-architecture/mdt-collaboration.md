# MDT access and collaboration

## Boundary

MDT membership is not case access. An active, case-specific `mdt_case_access` grant is required for every read, note, discussion, and action. This first release is online-only; MDT records are excluded from Field Mode and offline packs.

## Tables and lifecycle

- `mdt_case_access`: purpose-limited disclosure grant, professional role/office, authorized data categories, acknowledgement, expiry, completion, and revocation provenance.
- `mdt_actions`: a case-manager-created action with a due date and response/completion status.
- `mdt_case_notes`: formal professional documentation. A final note is immutable. A linked final `addendum` has its own author, date, and reason and never overwrites the original.
- `mdt_discussions` and `mdt_discussion_reads`: case-specific coordination only. A discussion message is never silently converted into a case note.
- `mdt_attachments`: private, grant-scoped supporting files with purpose/category, optional action/note linkage, uploader, and upload/download audit events. They are not entries in the general case-document store.

Active grants start only at their start time and are immediately invalid after expiry, revocation, return, user deactivation, or case closure. The current action endpoint records the grant as completed when an action is completed, and completed grants are excluded from normal active access. The intended behavior for multiple actions and expire_on_completion=false remains the B05 owner-review flag; the UI warns about the actual access-ending behavior and does not invent a new completion policy. The hourly kalinga:expire-mdt-access command persists expiry status for operational reporting; endpoint checks enforce expiry even if the scheduler is delayed. Historical authorship and audit events are retained. Case closure blocks new posts, notes, actions, and attachments. Managers with normal scoped case authority can retain discussion visibility under the existing case authorization.

## Capability model

The case manager chooses only from a controlled allow-list: case summary, stage, case plan, referral/service status, selected notes, and selected documents. The current MDT endpoint returns only case number, stage/status, explicitly permitted non-narrative summary/plan/referral data. It never returns client identity, comprehensive assessment, safety/risk assessment, Health/SRH, Child Participation, unrestricted notes, or attachments. Future access to any sensitive category needs a distinct, purpose-specific policy and endpoint.

| Actor | Can do |
|---|---|
| Case manager / authorized casework role | Create/revoke MDT grant; assign action; review response; retains primary case ownership |
| Active MDT member | Read only permitted data; acknowledge; post MDT discussion; draft/finalize their own MDT note |
| MDT member without active grant | No case list, detail, discussion, note, document, assessment, or Child Participation access |

MDT members cannot edit intake/client identity, assessments, safety/risk, Child Participation, official formulation, primary case plan, closure, or another professional's finalized case note.

## Privacy, notifications, and audit

Assignment and action notifications contain only the assignment/action type and operational state; they contain no client name, narrative, diagnosis, child views, Health/SRH data, or case facts. Audit records contain grant metadata/categories and workflow identifiers, never discussion, note, or attachment content. Search, analytics, exports, and public dashboards do not join MDT tables in this release.

Disclosure logging records recipient, professional role/office, purpose, data categories, assigning actor/date, expiry, revocation actor/date, and optional lawful-basis text. Owner/DPO review is still required for the definitive lawful-basis catalogue and DSA wording.

## Workspace and attachment usability

The MDT workspace presents only the active grant's case reference, permitted metadata, assigned actions, coordination discussion, formal notes, and grant-scoped attachments. It provides explicit action-response controls, a draft-note/finalize/addendum sequence, a lightweight unread indicator, and status feedback for network operations. Final notes are never editable in the UI: a user must create a reasoned addendum linked to the final source note.

Attachment upload accepts only PDF, JPEG, PNG, DOC, or DOCX files up to 10 MB. Every item carries a purpose/category and may be linked to the current grant's MDT action or note. Download always traverses the audited grant-scoped endpoint; stored paths are not exposed as public URLs.

## Read state and access matrix

Opening an authorized discussion records a per-message last-read timestamp in mdt_discussion_reads. The current discussion endpoint is case-wide: CPU members with an active assignment for that case and managers with scoped case authority can read the case coordination thread. It is not a private assignment-specific conversation and is not the LGU group chat. Actions, formal notes and attachments remain scoped to the selected assignment. The new UI states this existing distinction; no discussion disclosure scope was expanded.

## Multiple assignments and presentation workspace - 2026-10-23

The worklist now links to a specific mdt_access_id. CPU endpoints validate that
selection against the current user's eligible assignments for the same case;
a forbidden or ended selection never falls back to another grant. Case-only
links prioritize an assignment with an unanswered action, then the latest ID.
The case workspace provides an explicit selector for that user's active
assignments, with pending counts. Reads and writes keep the selection pinned.
No actions or category permissions are unioned across assignments.

The task-first screen shows referral purpose, remarks, assigning worker,
assignment status, instructions and due dates before permitted case metadata,
professional notes and supporting files. Case status is separately labeled.
The referrer's staff name, note author ID and attachment scan state are included
to support provenance and accurate controls. Client identity and sensitive
unapproved categories remain excluded. See
[CPU workspace delivery](../implementation/CPU-WORKSPACE-2026-10-23.md).

| Category | MDT member by default | Explicit grant required |
|---|---:|---:|
| Case number/status | No | Yes |
| Case plan/referral metadata | No | Yes, selected category |
| General case documents | No | Never; only a separately uploaded MDT attachment |
| Child Participation | No | Not supported in this module |
| Health/SRH and assessments | No | Not supported in this module |

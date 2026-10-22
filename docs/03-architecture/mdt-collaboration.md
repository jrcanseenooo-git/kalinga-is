# MDT access and collaboration

## Boundary

MDT membership is not case access. An active, case-specific `mdt_case_access` grant is required for every read, note, discussion, and action. This first release is online-only; MDT records are excluded from Field Mode and offline packs.

## Tables and lifecycle

- `mdt_case_access`: purpose-limited disclosure grant, professional role/office, authorized data categories, acknowledgement, expiry, completion, and revocation provenance.
- `mdt_actions`: a case-manager-created action with a due date and response/completion status.
- `mdt_case_notes`: formal professional documentation. A final note is immutable. A linked final `addendum` has its own author, date, and reason and never overwrites the original.
- `mdt_discussions` and `mdt_discussion_reads`: case-specific coordination only. A discussion message is never silently converted into a case note.
- `mdt_attachments`: private, grant-scoped supporting files with purpose/category, optional action/note linkage, uploader, and upload/download audit events. They are not entries in the general case-document store.

Active grants start only at their start time and are immediately invalid after expiry, revocation, return, user deactivation, or case closure. Completion ends access only when the grant's `expire_on_completion` setting is enabled; otherwise the action is completed while the time-limited grant remains active. The hourly `kalinga:expire-mdt-access` command persists expiry status for operational reporting; endpoint checks enforce expiry even if the scheduler is delayed. Historical authorship and audit events are retained. Case closure blocks new posts, notes, actions, and attachments. Managers/supervisors with normal scoped case authority can retain read-only discussion visibility for supervision/retention.

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

Opening an authorized discussion records a per-message last-read timestamp in `mdt_discussion_reads`; this supports an unread count without social-media-style receipts. An active MDT member can read/post only their assigned thread. The assigned case manager and scoped supervisor may read the thread, but cannot use it as unrestricted messaging. The manager's normal case scope still governs that read.

| Category | MDT member by default | Explicit grant required |
|---|---:|---:|
| Case number/status | No | Yes |
| Case plan/referral metadata | No | Yes, selected category |
| General case documents | No | Never; only a separately uploaded MDT attachment |
| Child Participation | No | Not supported in this module |
| Health/SRH and assessments | No | Not supported in this module |

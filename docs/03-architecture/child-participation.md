# Child Participation / Best-Interest Record

## Purpose and boundaries

This is a formal, decision- or occasion-specific record of how a child was enabled to participate and how their views were considered. It is not a consent or assent record, forensic interview, automated best-interest score, Safety/Risk Assessment, Comprehensive Assessment, or ordinary contact note. Routine contacts remain in case notes/contact history; significant decisions use this reviewed and finalized record.

The visible Case Workspace tab is **Child Participation**. The Overview exposes final-record metadata only, never the child's narrative, preferences, quotations, or reviewer comments.

## Fixed data model

`child_participation_records` is deliberately explicit rather than a generic questionnaire. It stores a decision/occasion, controlled participation status, version lineage, author/reviewer/finalizer metadata, developmentally appropriate context, distinct child expression/quotation/observation/reported-information fields, professional consideration, decision alignment, feedback-to-child fields, and sharing-preference consideration. `child_participation_workflow_events` is append-only workflow history. No new decision should be inserted into an old record: a separate occasion starts a new lineage; a correction or supplementary addendum uses the same lineage.

Controlled statuses are `participated`, `partially_participated`, `child_declined`, `deferred`, `not_appropriate_unsafe`, `child_unable_to_participate`, and `not_yet_conducted`. A neutral reason is required for statuses other than participated/partially participated. Refusal or non-participation is never treated as adverse.

Controlled sections are Participation Context, Child's Views, Communication / Accommodations, Decision Under Consideration, How Views Were Considered, Best-Interest Considerations, Feedback to Child, and Safeguards / Follow-up. Child expression, direct quotation, worker observation, reported information, and professional consideration are separate. Quotations are optional and should be minimum necessary.

## Workflow and authority

| Transition | Authority | Rule |
| --- | --- | --- |
| Draft → For review | `participation.manage` | Author only |
| For review → Reviewed | `participation.review` | Different authorized, case-scoped reviewer/supervisor |
| For review → Returned for revision | `participation.review` | Different reviewer; comments required |
| Reviewed → Final | `participation.review` | Different authorized reviewer/supervisor; never the author |

Final records are immutable. A full revision copies the finalized fields into a new draft in the same lineage; an addendum is supplementary and does not alter the final source. Each field-group save writes only its controlled section fields, so saving one section cannot clear another.

## Permissions and privacy

`participation.manage` and `participation.restricted_view` are casework-role permissions, still subject to server-side case scope. `participation.review` is limited to CPO, LGU supervisor, and field-office roles. System administrators, aggregate roles, referral partners, and future MDT users receive no automatic access. Future MDT access requires explicit case assignment, purpose, minimum necessary scope, and a dedicated grant.

All participation narratives are restricted. A user without `participation.restricted_view` receives only a safe placeholder and strictly operational metadata; direct detail, index, workflow-comment responses, audit payloads, page props, search, dashboards, analytics, heatmaps, notifications, and unrestricted exports do not expose narrative content. A child's sharing preference is documented as context only; it does not itself control lawful disclosures or authorization.

## Policy flags and offline boundary

The module does not decide best interests or required intervention. Adults/duty-bearers retain responsibility. Owner validation is still required for the approved best-interest factor catalogue, legal disclosure/assent policy, and any future field-mode support. Field Mode is intentionally not enabled: any future offline draft support must use encrypted, minimum-necessary IndexedDB data with revocation cleanup, explicit sync state, conflict handling, and no narrative logs.

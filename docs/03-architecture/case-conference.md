# Case Conference

## Purpose and boundary

A Case Conference is a formal, multidisciplinary decision and action record for one scoped case. It is not meeting minutes, an alternate risk assessment, or a duplicate Child Participation record. It is online-only in this release.

## Workflow and authority

`Draft → Scheduled → In Progress → For Review → Final`.

An independent authorized reviewer may return `For Review → Draft` only with comments; the returner and timestamp are retained. Rescheduling keeps the record scheduled and writes a history entry containing the prior date, new date, and reason. Final records are immutable. A post-final correction is a separately final linked addendum with author, date, and reason.

Casework roles create and author drafts within normal case scope. CPO, LGU Supervisor, or FO users independently review/finalize. Listing a person as a participant, including an MDT user, never grants Kalinga access. MDT work remains subject to the existing case-specific MDT grant.

## References and privacy

Child participation uses only participation status and an optional authoritative Child Participation record ID. Safety uses only an optional finalized Risk Assessment ID. Conference records never copy those narratives. Conference content is excluded from search, dashboards, analytics, public reporting, notifications, and audit payloads. The Case Overview may show only final date/type, action counts, and next review.

## Actions and linked execution

`case_conference_actions` coordinates agreed work. It can link to a Task, Referral, Intervention, MDT action, or risk-reassessment request. The linked operational record is authoritative for execution status; the conference action exposes that record's status and has no competing completion state. The generic `tasks.source_type` / `source_id` fields permit safe source linkage without hard-coding a conference-only column. Server validation accepts a link only when it belongs to the same case.

Creating a consequential Task, Referral, Intervention, MDT grant/action, reassessment, or visit remains an explicit future user action with confirmation. Entering a decision or recommendation never creates one automatically.

## Pre-production policy dependencies

- formal conference retention/print-record format;
- owner/DPO approval of conference confidentiality labels and external-attendee practice;
- approved aftercare workflow for new conferences on closed cases.

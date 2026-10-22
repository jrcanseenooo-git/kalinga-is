# Progress Notes and Casework Compatibility

## Purpose

Progress notes are the formal, chronological record of professional casework. They document a contact, monitoring activity, service delivery, coordination, case review, or another professional event. They are distinct from CPU coordination chat, MDT notes, assessments, case-conference minutes, and the Case Plan.

## Record model and workflow

`case_notes` now records:

| Field | Meaning |
| --- | --- |
| `record_type` | `progress_note` or controlled `addendum` |
| `note_type` | Contact, monitoring, service delivery, coordination, case review, or other |
| `occurred_at` | When the professional event occurred, not merely when it was encoded |
| `finalized_at`, `finalized_by` | Final-on-save authorship and timestamp |
| `previous_note_id`, `addendum_reason` | Immutable correction/supplement lineage |
| `source_type`, `source_id`, `provenance_summary` | Controlled provenance when a reviewed CPU coordination message is recorded formally |

An ordinary note is finalized when saved. There is no note-edit endpoint. A correction or later fact is saved as an addendum linked to the original finalized note, with a mandatory reason. This preserves the original record and its audit trail.

The worker may explicitly create one follow-up Task while finalizing a note. The Task stores `source_type=case_note` and `source_id`; the Task remains the authoritative execution-status record. Creating a note never silently creates a task.

Server-side requirements are `cases.note`, normal case scope, and sensitive-write rate limiting. CPU chat capture requires its existing LGU-circle membership and ordinary case authorization before it may create a formal progress note. A case reference in chat does not create case access.

## Legacy generic assessments

The older `assessments` table remains intact for compatibility, migration, and controlled reconciliation. It is **not** the canonical assessment workflow. The canonical workflow is the versioned, permission-filtered Comprehensive Assessment Workspace.

The Case Workspace receives only generic-assessment metadata (count, version, status, updated date) and intentionally does not receive legacy summary or response narratives. The old create endpoint remains in place only pending an owner-approved migration/retirement plan; new workers are directed to the dedicated Assessment Workspace instead.

## Current operational boundaries

- Case closure/aftercare rules for adding retrospective progress records remain an owner-policy decision; this change preserves the existing closure access behavior rather than inventing a rule.
- Offline note sync remains a minimal field workflow. Synced notes are finalized and do not include addendum creation; addenda remain an online controlled workflow until the Field Mode policy and conflict UX are approved.
- Every record must remain minimum-necessary. Full narratives must not be copied to dashboards, notifications, aggregate analytics, search snippets, or audit `changes` payloads.

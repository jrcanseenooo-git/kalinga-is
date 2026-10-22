# Comprehensive Social Case Assessment Workspace

## Purpose and boundary

This workspace records a structured professional social-case assessment. It is distinct from the Safety/Risk Assessment and future Child Participation record. The Case Overview receives only safe metadata (version, status, assessment date); it never receives section narratives.

## Controlled section catalogue and data dictionary

Sections are fixed in server validation: `presenting_situation`, `family_relationships`, `cefmu_context`, `economic_education`, `health_srh`, `psychosocial_environment`, `strengths_support`, `perspectives`, `professional_assessment`, `case_formulation`, and `recommendations`.

`case_assessments` holds the case, version number, source finalized version, `record_type` (`full_revision` or `addendum`), amendment reason, assessment date, workflow status, author/reviewer/finalizer identities and dates, and `is_current`. `case_assessment_sections` holds one independently saved section for a version: completion state, source attribution, verified facts, reported information, observation, conflicting information, professional analysis, and restricted flag. `case_assessment_workflow_events` is append-only history for submit, review, return, and finalization comments.

Completion states: completed, partially completed, deferred, information unavailable, or not applicable. Source attribution is controlled and makes reported information, worker observation, records, and professional analysis visibly distinct.

## Workflow and authority

| Transition | Required authority | Constraint |
| --- | --- | --- |
| Draft → For review | `assessments.manage` | Author only |
| For review → Reviewed | `assessments.review` | A different, case-scoped reviewer |
| For review → Returned for revision | `assessments.review` | Different reviewer and non-empty comments |
| Reviewed → Final | `assessments.review` | A different authorized reviewer/supervisor; never the author |

Finalization is reviewer/supervisor authority. The author/case manager may submit a draft and revise it after return, but cannot finalize their own assessment. The reviewer who reviewed it or another authorized, case-scoped reviewer/supervisor may finalize it.

Finalized records are immutable: section save accepts only the author’s draft. The current version is the newest final assessment by assessment date, then ID; backdated finals do not displace a newer current final.

## Versioning and addenda

`Full revised assessment` creates a new draft, linked to a finalized predecessor, and copies its sections so the worker can revise them without modifying history. `Addendum` creates a separate linked draft without copying or altering the final record; its reason is mandatory and it accepts only the supplementary `professional_assessment` note. Both have their own workflow, author, reviewer, finalization, and lineage. They are not silent edits.

## Restricted Health/SRH content

Health/SRH sections must be flagged `restricted`. Read filtering occurs server-side in the sole comprehensive-assessment detail endpoint: a user without `assessments.restricted_view` receives only section key, completion state, restricted flag, and `Restricted section` placeholder. Narrative fields never enter the page props, version index, Case Overview summary, audit change payload, dashboard, search, export, notification, or analytics path in this module. Write access to a restricted section also requires the same dedicated permission and case scope.

Current canonical grants: case workers/social workers/CPO/LGU supervisors/field-office users have `assessments.manage` and restricted view while case-scoped; CPO/LGU supervisors/field-office users additionally have `assessments.review`. System administrators, aggregate users, referral providers, and future MDT users have no automatic access. Future MDT access requires a separate case-specific assignment and minimum-necessary rule.

| Role group | Draft/section save | Review/return/finalize | Restricted read |
| --- | --- | --- | --- |
| Case worker / social worker | Assigned case only | No | Assigned case only |
| CPO / LGU supervisor / field-office user | Case scope only | Case scope only; must not be author | Case scope only |
| System admin, aggregate roles, partner/referral roles, MDT without a future assignment | No | No | No |

## Compatibility and offline notes

The legacy generic `assessments` table remains untouched for compatibility. It is not migrated automatically into `case_assessments`; mapping and owner validation are required before any migration. Field Mode/offline sync is not enabled for Comprehensive Assessment yet. When added, only authorized, encrypted, minimum-necessary draft sections may be held in IndexedDB with visible sync state, retry/conflict handling, revocation cleanup, and no narrative in logs or analytics.

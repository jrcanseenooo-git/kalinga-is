# Case Plan, Intervention, Reintegration, Aftercare, and Continuity

## Purpose and scope

This module turns verified assessment findings into a controlled helping-plan lifecycle. It is not a replacement for the Comprehensive Assessment, Safety/Risk Assessment, Child Participation record, Case Conference, MDT record, Referral, Task, or Monitoring Visit. Those records remain authoritative in their own workflows.

## Plan lifecycle

Plan statuses are deliberately separate from the case lifecycle:

`Draft → For Review → Active → Under Review → Completed`, with `Superseded` retained for a replaced historical version.

An authorized reviewer may return a plan from **For Review** to **Returned** with retained comments. The author may edit and resubmit it. A new version remains a draft and does not supersede the current plan until a different authorized reviewer activates it. Activation supersedes the prior current plan. Review records are append-only; the application exposes no edit/delete operation for them.

Case Manager/author may prepare, submit, and request review. A different CPO, LGU Supervisor, or Field Office user may review, return, activate, or complete the plan. The author cannot activate or complete their own plan.

## Goals and interventions

`case_plan_goals` is first-class and contains domain, need/problem, intended change, priority, target date, outcome, and observations. Interventions are linked to a goal.

An intervention may have an **origin** (`conference_action`) and, separately, an optional **operational record** (`task`, `referral`, `mdt_action`, or `risk_reassessment_request`). Origin answers “where was this agreed?”; the operational record answers “which workflow executes it?”. When an operational record is linked, its status is the execution-status source of truth. The planning workspace cannot change linked execution status; it records outcomes, barriers, and next action only.

## Reintegration and aftercare

Reintegration is optional and explicitly records whether it applies. It may reference a plan, Child Participation record, and Safety/Risk Assessment; it does not copy their sensitive narratives. Aftercare is a separate continuity record with responsible person/office and initial follow-up. No automatic schedule is imposed: local frequency, closure readiness, and aftercare requirements remain owner-policy settings pending LGU/FO validation.

## Transfer and handover

`case_transfers` and `case_transfer_history` preserve the same case identity. A transfer follows `Draft → Requested → Endorsed → Accepted → Completed`, with return-for-clarification or cancellation. Assignment changes only when completion commits in one transaction.

Cross-LGU jurisdiction transfer is **disabled by default** until the owner approves an atomic jurisdiction-handoff policy (source access termination, receiving LGU scope, DPO/DSA controls, and failure recovery). Same-jurisdiction handoff is available to active casework users. This avoids accidental duplicate access during unresolved policy conditions.

## Closure readiness

`case_lifecycle_policies` records closure rules as either `block` or `warning`. Defaults make high/critical current risk a blocker; open work and aftercare are warnings. The UI/API labels these as policy validation required. Closure transition enforces configured blockers but never makes a professional closure decision automatically.

## Privacy and future Field Mode

The Case Overview exposes only plan version/status, review due date, and counts. It excludes goals, intervention activity, outcomes, review comments, reintegration detail, and continuity narratives. Audit payloads record IDs, workflow events, and changed field names-not narratives. This release is online-only. Any later Field Mode implementation must use encrypted device storage, a minimal authorized case set, idempotency keys, `updated_at`/version conflict handling, revocation cleanup, retry/recovery, and explicit conflict resolution; it must not use LocalStorage for sensitive drafts.

## Compatibility

The older `case_plans`/`interventions` storage remains in place and is extended by this module. The historical generic planning endpoint is retained only as an explicitly deprecated compatibility boundary and must not create unreviewed active plans. New workspace actions use the lifecycle endpoints under `/cases/{case}/plans`.

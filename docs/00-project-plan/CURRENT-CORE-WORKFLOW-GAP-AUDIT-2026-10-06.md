# Current Core Workflow Gap Audit - 2026-10-06

This is a source-based audit of the current Laravel/Vue repository, not a policy assertion. It separates delivered functions from the remaining work needed for a defensible CEFMU social case-management deployment.

| Workflow/module | Current state | Gap or risk found | Action in this coordinated pass | Remaining owner/policy dependency |
| --- | --- | --- | --- | --- |
| Intake, report screening, duplicate review | Implemented with report lifecycle, acceptance, conversion and identity review | LGU reporting scope still depends partly on evolving user-assignment data | No workflow rewrite | Final LGU assignment and public-report operating procedure |
| Case folder, case status, closure/reopening | Implemented with scope checks, status history, closure review, readiness checks and new plan cycle | Closure/aftercare thresholds are policy-configured | No rewrite | Closure blocker thresholds and aftercare authority |
| Safety/Risk | Versioned, final-only current risk, professional actions/factors, reassessment visibility | Alert scheduling/deduplication and device-field use remain pre-production items | No rewrite | Local risk escalation protocol |
| Comprehensive Assessment | Canonical versioned workspace, restricted section reads, reviewer separation and addenda | Legacy generic assessment was still visibly editable beside it | Removed legacy editing from workspace; preserved metadata-only compatibility view | Approved migration/retirement of legacy generic assessment endpoint |
| Child Participation, MDT, Conference, Case Plan | Delivered as distinct workflows with scoped access, record lineage and privacy boundaries | UAT and policies listed in module records remain | No rewrite | Operational governance/UAT approvals |
| Progress notes and monitoring | Basic note insertion and monitoring existed | Notes were untyped text without event date, finalization or correction lineage; follow-up depended on separate manual task creation | Structured final-on-save progress notes, controlled addenda, provenance preservation, explicit linked follow-up task | Retrospective-note rules after closure; offline addendum policy |
| Referrals, tasks, calendar | State-based referrals and owned tasks exist | Activity taxonomy/reminder policy can be expanded after user-management/RBAC stabilization | Linked note follow-up uses Task as authoritative execution record | Reminder service/SLA policy |
| Documents, retention, legal holds | Document governance, quarantine, classification, lifecycle and disclosures are implemented | Production malware scanning/storage key/retention schedule need operational evidence | No rewrite | DPO/records-owner schedule and infrastructure evidence |
| Offline/GIDA | Encrypted minimal case packs, field intake/note/monitoring sync and conflict detection exist | Broader offline editing, device retirement, background sync and real field UAT are incomplete | Kept note sync minimal and final-on-sync | Field Mode/device/retention policy and offline UAT |
| Analytics/reporting | Aggregate dashboards, small-cell suppression, CSV neutralization and management export delivered | Formal signed/approved report templates and production data-governance review remain | No rewrite | Undersecretary report template and DPO small-cell policy |
| RBAC/privacy/audit | Role/action permissions, case/LGU scope, restricted reads, CORS/headers/rate limits and audit events exist | User management is still being remodeled; security validation cannot substitute for owner policy | Fixed a dashboard role-comparison defect that could expose LGU workload to non-workers | Final user-management governance, VA/penetration test, PIA/DSA |

## Highest-priority pass delivered

1. Removed the overlapping generic-assessment authoring surface from the Case Workspace without deleting old data.
2. Upgraded progress documentation from unstructured free text to a formal, lineaged record with server-side scope enforcement.
3. Added explicit, traceable note-to-task follow-up creation rather than inferred actions.
4. Corrected the role comparison in LGU workload calculation so only Social Workers receive it.

The next core workflow candidate after owner review is **referral service-delivery/outcome evidence and provider response responsibility**, not another assessment form. It should follow final user-management/RBAC decisions because provider and receiving-office scope depend on them.

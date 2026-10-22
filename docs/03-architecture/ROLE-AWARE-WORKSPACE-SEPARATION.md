# Role-aware workspace separation

## Intent

Kalinga IS does not use one universal dashboard. The landing workspace is selected by the user's authorized operational role so that administrative, aggregate-management, and casework activities are not conflated.

## Current workspaces

| Workspace | Current access | Purpose | Deliberately excluded |
| --- | --- | --- | --- |
| Administration overview | `admin.manage` | Account approvals, role/LGU assignment, governance activity, and navigation to administration tools | Client identities, case files, case narratives, and casework queues |
| Central Office management overview | `management.dashboard.view` | Aggregate management reporting and entry points to focused analysis | Case identifiers, client identities, sensitive narratives, client map pins, and unsuppressed low-count cells |
| Central Office: Case pipeline | `management.dashboard.view` | Aggregate status, stage, approved risk metadata, and timeliness indicators | Case-level worklists and individual records |
| Central Office: Geographic coverage | `management.dashboard.view` | Aggregate region, province, and LGU coverage | Client-level location data or geographic pins |
| Central Office: Service continuity | `management.dashboard.view` | Aggregate referral, aftercare, and overdue-action context | Referral or case narratives |
| Casework dashboard | Casework/analytics permissions and case scope | Worker task and authorized case workflow | Out-of-scope cases and Central Office administration |

## Access and privacy safeguards

- The existing `management.dashboard.view` and `management.report.export` server-side gates remain authoritative.
- Current canonical RBAC has no separate Central Office role. The current management permission is granted to `system_admin`; adding a distinct Central Office role or assignment requires owner-approved RBAC governance and migration work.
- Central Office views query aggregate fields only. They do not select client/case narratives.
- Counts and grouped cells below five are suppressed; management CSV export follows the same rule and is audited.
- The Administration Overview contains access-governance metadata only; it is not a backdoor into casework.

## Navigation behavior

- System administrators are redirected from `/dashboard` to `/admin/overview`.
- Central Office management analysis is intentionally accessed through separate focused navigation entries, rather than a single all-purpose screen.
- `/management-dashboard` remains as the compatibility URL for the Central Office management overview.

## Owner decisions still required

1. Whether Central Office users require a distinct role, a scoped assignment, or a separately approved management permission.
2. Final CEFMU indicator definitions and the suppression threshold/policy.
3. Approved executive/Undersecretary report templates and release process.

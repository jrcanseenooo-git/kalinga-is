# Role migration matrix

This matrix records the Priority 1 alignment of the new Laravel system to the
old Kalinga domain catalogue (`dswd-kalinga/appscript/Domain.gs`). It is a
functional baseline, not a grant of broader access.

| Old role | Old permissions / purpose | New equivalent | Status | Required action |
|---|---|---|---|---|
| `admin` / `system_admin` | Accounts, configuration, audit, retention; no substantive case file | `system_admin` | ALIGNED | Legacy `admin` maps to `system_admin`; no `cases.view` grant. |
| `case_worker` | Assigned casework, notes, plans, referrals, documents | `case_worker` | ALIGNED | Detailed records remain assignment-scoped; non-identifying LGU workload totals are allowed. |
| - | Social-work casework role used by the new system | `social_worker` | RETAINED | Explicitly granted the same assigned-case workflow as case worker. |
| `cpo` | Screen, validate, accept reports; open cases | `cpo` | ALIGNED | Legacy `cefm_admin` is migrated to this jurisdiction-scoped role. |
| `lgu_supervisor` | LGU oversight, validation/acceptance, assignment and closure | `lgu_supervisor` | ALIGNED | Municipality scope remains required. |
| `fo_user` | Province-level oversight | `fo_user` | ALIGNED | Province scope remains required. |
| `referral_provider` | Referral-scoped response only | `referral_provider` | PARTIAL | Role is seeded; referral-scoped portal is not yet implemented. |
| `health_provider` | Health referral-scoped response | `health_provider` | PARTIAL | Role is seeded; health-referral view is not yet implemented. |
| `barangay_user` | Submit reports only | `barangay_user` | REPLACED | Public reporting is available without a case-file account; role remains for authenticated future intake. |
| `data_encoder` | Report encoding; no validation/closure authority | `data_encoder` | ALIGNED | Restricted to report/document intake permissions. |
| `mdt_member` | CPU/MDT contribution for referred cases | `mdt_member` | ALIGNED | Active case-specific MDT grant remains mandatory; LGU circle membership is not case access. |
| `cpu_monitor` | Aggregate monitoring only | `cpu_monitor` | TRANSITIONAL | No national analytics or case-file permission; LGU CPU collaboration is delivered through `mdt_member` plus an active case grant. |
| `analytics_focal` | Aggregate reports and exports only | `analytics_focal` | ALIGNED | No case-file permission; analytics only. |
| `data_custodian` | Retention/disposal and privacy administration | `data_custodian` | ALIGNED | No case-file permission; audit/retention only. |
| `viewer` | New safe default for self-registration | `viewer` | RETAINED | No protected permissions. |

## Enforcement model

- `app/Services/RolePermissions.php` is the canonical role/permission
  catalogue.
- Laravel Gates enforce every named permission server-side.
- `CaseAccessScope` applies assignment or jurisdiction checks after the
  coarse permission check.
- `LguAssignmentScope` introduces effective-dated PSGC/LGU assignments. Legacy text locations are a controlled compatibility fallback while deployment assignments are seeded.
- Inertia receives only the current user’s permission names for navigation
  visibility. This is a usability feature; the backend remains authoritative.
- Role seed data is synchronized by the Priority 1 migration and
  `ReferenceDataSeeder`.

## Deliberate privacy separation

System administration does not imply substantive casework access. A
`system_admin` can manage users and governance functions but cannot use case
routes or receive client/case content. Case access requires both `cases.view`
and record-level assignment/jurisdiction authorization.

# Core UAT checks

| Area | Procedure | Expected result |
|---|---|---|
| Authentication | Sign in as case worker | Dashboard opens with limited casework access |
| Intake | Create a case with narrative | Client, report, case, audit event and initial status history are created |
| Workflow | Move OPEN to IN_PROGRESS | Status and history update; invalid direct close is rejected |
| Case notes | Add a note | Note appears in the case workspace and updates activity time |
| Offline sync | Queue a note while offline, reconnect | Server validates, authorizes, records audit and returns sync status |
| Documents | Upload a supported file | Private storage metadata/version/audit entry is created |
| Administration | Sign in as system admin and change a role | Role updates and audit entry is visible |
| Analytics | Open Insights | Aggregates render without PII or narrative content |
| Report intake | Create a report, move it to validation and accept it | The report can be converted into a linked case folder only after acceptance |
| Case planning | Add an assessment, intervention, and review date | Assessment version and case plan are retained in the folder |
| Consent | Record consent and withdraw it | Consent purposes, sharing choice, and withdrawal are shown and audited |
| Referral | Create a referral draft and progress it | Invalid status jumps are rejected and the provider hand-off is audited |
| Tasks | Add a linked task and complete it | It appears in the worker work list, notification feed, and audit log |
| Offline conflicts | Submit a stale queued operation | The server returns a conflict and the Sync Center keeps it for review |
| RBAC | Log in with a read-only account | Sensitive folders, private analytics, and changes are denied |

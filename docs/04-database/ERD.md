# Logical ERD

```text
users ──< cases (assigned_to) >── clients ──< relationships
              │       │
              │       └── reports
              ├──< case_notes
              ├──< case_status_history
              ├──< case_plans ──< interventions
              ├──< assessments
              ├──< referrals >── service_providers
              ├──< monitoring_visits
              ├──< tasks
              ├──< documents
              └──< consents

users ──< audit_logs       users ──< notifications
users ──< offline_sync_items
roles >──< permissions (permission_role)
```

Primary records use surrogate integer keys. Case and report display identifiers are unique human-readable sequence numbers. Foreign keys enforce ownership and child-record integrity. Sensitive client/case/document rows use soft deletion where appropriate; audit rows are append-only.

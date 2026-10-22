# Module migration matrix

| Existing function | New module | PostgreSQL entities | Disposition |
|---|---|---|---|
| Reports and screening | Report intake | reports, clients | Redesign |
| Case registry and workspace | Casework | cases, case_status_history, case_notes | Redesign |
| Family and consent | Client context | relationships, consents | Preserve and improve |
| Assessment and plans | Assessment/planning | assessments, case_plans, interventions | Merge |
| Referrals and services | Service delivery | referrals, service_providers | Redesign |
| Monitoring and activities | Field work | monitoring_visits, tasks | Redesign |
| Documents | Document centre | documents | Replace Drive metadata model |
| Audit/privacy | Governance | audit_logs, roles, permissions | Preserve and strengthen |
| Offline queue | Sync centre | offline_sync_items + IndexedDB | Replace |
| Analytics and heatmap | Insights | aggregate queries + geography | Redesign |

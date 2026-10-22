# Automation and operations status

**Reviewed:** 28 September 2026 (Asia/Manila)

## Implemented automation

### Deadline alerts

`kalinga:dispatch-deadline-alerts` creates **in-app notifications** for:

- open tasks that are due within 24 hours or already overdue; and
- active case-plan reviews due today or overdue, for the assigned case worker.

The command is scheduled hourly in `routes/console.php`. It is idempotent: the same task or plan deadline does not generate duplicate notifications on later runs.

### Host activation required

The schedule declaration does not itself run a server process. The deployment environment must run one of the following Laravel-supported scheduler entries:

```text
# Production cron, once per minute
* * * * * cd /path/to/kalinga-is && php artisan schedule:run >> /dev/null 2>&1

# Local or supervised worker
php artisan schedule:work
```

The system should alert an administrator if scheduled execution stops. The exact monitoring service and notification escalation policy remain a deployment/operations decision.

## Verified dynamic behavior

- Dashboard deadline counts are calculated at request time from tasks, reports, case activity, plans, and referrals.
- Region selection refreshes dashboard analytics through the server-side region filter.
- Offline packs remain role-gated, scoped to assigned cases for field workers, and conflict-aware on sync.
- The header notification inbox shows only the authenticated user's most recent alerts and unread count.

## Current hardening applied

- Field workers and social workers are limited to their own/assigned report queue records, including report state transitions and conversion to case folders.
- Private search and analytics/export results are scoped to assigned cases for field-worker roles.
- The Offline Workspace page and APIs share the same authorized-role control.

## Next decisions before expanding automation

1. Confirm approved reminder cadence and escalation recipients for overdue reports, referrals, and first-contact deadlines; do not automate messages without an operational owner.
2. Define a retention policy for read notifications and audit records, then add a controlled archival/purge job.
3. Add infrastructure monitoring for scheduler health, database backup success, queue health (if queues are enabled), and failed offline syncs.
4. Split the large casework/operations controllers into policy, workflow, and query services before adding more workflow automation.

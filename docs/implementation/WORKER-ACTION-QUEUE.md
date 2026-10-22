# Worker action queue and drill-down

Date: 2026-10-17. Package P2-A. Acceptance evidence is in PROGRESS_TRACKER.md; no whole case-management module is declared complete.

The dashboard now begins with four clickable counts and My cases requiring action. Active cases means authorized cases whose recorded status is not CLOSED. Due within 24 hours means the current user's open, case-linked tasks with due_at between request time and the next 24 hours. Overdue actions means equivalent tasks with a recorded due_at before request time. Follow-up cases means authorized cases whose stored status is FOR_FOLLOW_UP; no deadline or priority is inferred from that state.

The action queue contains up to 10 recorded open case-linked tasks, dated tasks first in due-date order, then existing recorded priority (urgent/high/normal/other) for ties, then task ID. Undated tasks follow dated tasks. It exposes the case reference, stage/status, task title, recorded due date and priority; no client name or assessment narrative is added. Dates in the queue are serialized with an explicit offset and displayed in Asia/Manila. These are operational sorting rules, not automated child-protection decisions or new SLAs.

KPI links use validated case status/active filters and task due/linked filters. Any existing dashboard region filter is carried through. The list retains pagination and search; task filters include a clear-filter link. Existing operational deadline and geographic/analytics content is retained under expandable sections to keep the primary work surface short. LGU non-identifying workload remains distinct from assigned case access.

WorkerTasks is the common task/case selector boundary for the worklist, calendar and queue. An owned task with no linked case remains visible. A linked task requires current authorization to the associated non-archived case, even if the task is still assigned to the user. Task completion/cancellation now also rechecks linked-case authorization. This fixes a source-verified leak in selectors/calendar and prevents editing a linked task after case access is revoked.

Preserved: current case lifecycle, task states, existing 24-hour window, case-workflow authorization, regional FO oversight and personal tasks. No schema, role grants, sensitive fields, database reset or offline storage change. Follow-up cases without recorded tasks are reachable via their KPI, not fabricated as tasks.

Pending: true scheduling/conference integration and notifications in the broader plan, further focused charts, full casework domain acceptance and field/offline UAT. Existing legacy deadline definitions still require owner review. No claim of full dashboard or GIDA readiness is made.

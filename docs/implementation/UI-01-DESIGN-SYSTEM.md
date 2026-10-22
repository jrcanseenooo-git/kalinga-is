# UI-01 shared Kalinga design system

Updated: 2026-10-18. Owner's latest UI priority supersedes the previous next-task order. P2-B4 and all uncompleted functional work remain in backlog. Existing backend/security/offline work is KEEP.

## Visual direction and dispositions

All30 individual module panels and full Dashboard Option2 were inspected, rather than relying on boards. Concept labels/counts/child portraits, general CANE+D categories and illustrative approvals do not authorize data/schema/policy changes. Existing Kalinga logo and purple/blue/yellow identity are retained. Purple800 provides accessible action text contrast; brighter core brand colors remain accents. Neutral white/slate, thin borders,6-8px radii,14px body controls,16px section headings,24px page titles,8px spacing rhythm and44px touch controls replace decorative cards/gradients/shadows.

REFACTOR AuthenticatedLayout navigation/notification composition, PageHeader, MetricCard, SectionNav and submission confirmation. REDESIGN Dashboard information hierarchy using real authorized data. KEEP permissions, native-dialog key handling, skip link/mobile drawer focus trap, existing session guards, offline protection, case-task ordering and all existing auxiliary data. Caseload/geographic/workload views remain in explicit secondary disclosures; no working data feature removed.

## Component-to-application map

| Category | Shared implementation | Real adoption |
| --- | --- | --- |
| Shell/sidebar/mobile | AuthenticatedLayout; responsive drawer/232px sidebar/96px rail | All authenticated pages; role-Gated links |
| Header/breadcrumbs | PageHeader; UI/Breadcrumbs | Dashboard and existing pages; compact shell orientation |
| KPI | MetricCard | Four clickable existing priority definitions |
| Panels/section headers | UI/Panel | Queue, activities, quick actions and charts |
| Search/filter/form fields | UI/FilterBar; UI/FormField; existing validated inputs | Cases/Index FilterBar/FormField adopted; actual scoped server search |
| Tables/cards/pagination | UI/DataTable; UI/Pagination | Queue and Cases/Index table/cards; scoped registry pagination adopted |
| Status | UI/StatusBadge | Recorded priority and overdue text |
| Notification panel | UI/NotificationPanel | Existing scoped preview/read-all/inbox endpoints |
| Steps/navigation tabs/accordion | UI/Stepper; SectionNav; UI/Accordion | Existing intake steps, section route navigation and dashboard disclosure |
| Modals/confirmation | Modal; SubmissionConfirmation | Native modal, existing single-confirmed submission flow and logout |
| Charts/legend/alternative | UI/ChartCard | Actual six-month openings and actual recorded-status distribution; data tables |
| Loading/empty/error | Inertia progress; existing processing controls; UI/ContentState | Real navigation; empty queue/schedule/chart and reusable error/loading variants |
| Report preview | UI/ReportPreview | Existing authorized working draft; no official template invented |
| Connectivity | UI/ConnectionStatus | Real online/offline state; encrypted workspace unchanged |

No screen is declared visually verified from source/build alone. Every module retains its separate UI transformation row. UI-01 currently IMPLEMENTED in real shell/core hosts; full screen adoption continues through subsequent UI tasks.

## Responsive contract

360/390: drawer and compact fixed navigation,2x2 concise priorities, queue cards before secondary charts.768: no permanent sidebar; queue cards plus schedule in flexible columns.1024: collapsible sidebar and2x2 KPIs.1440/1920: four KPIs, compact action table beside schedule, two charts plus quick actions. Full case narratives/precise addresses do not enter the new dashboard payload. Management/CPU starts remain separately authorized.

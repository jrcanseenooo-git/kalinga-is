# Kalinga IS enhancement plan

Date: 2026-10-17. Status: active, incremental delivery. No production release approval.

Continuation control: MASTER_PLAN.md now indexes this document; TASK_REGISTER.md, CURRENT_SPRINT.md, PROGRESS_TRACKER.md and BLOCKERS.md track execution. These five files were missing on resume. R03-A addresses primary aggregate-count suppression and existing export/quarterly permission enforcement; R03-B separately retains secondary-suppression and differencing policy review. Do not mark the entire R03 risk resolved from primary-suppression tests.

R03-A is now verified: 243 backend tests / 1409 assertions, final build and three responsive analytics browser tests pass. See ANALYTICS-PRIVACY-REVIEW.md for scope and residual risks. Next eligible implementation task is R06-A; all remaining original modules stay in the matrix below.

## Authority and evidence

The owner's request and AGENTS.md govern this work. The complete Downloads master implementation prompt is the requested specification, subject to existing code, verified workflows and qualified policy review. The pasted autonomous directive and ZIP instructions are supporting material, not independent authorization to reset data, deploy, change disclosure policy or transmit case information. Mockup identities, forms, signatures, counts and categories are illustrative only.

Reviewed: README, dependency manifests, web routes, middleware/bootstrap and Gates, User, RolePermissions, CaseAccessScope, LguAssignmentScope, casework/dashboard, analytics, report, offline and CPU endpoints; required page entry points; migration schema/constraint declarations; architecture, privacy, retention, prior inventory and UAT documentation. ZIP inventory includes 30 module panels, two dashboard alternatives, four report images and four reference boards; both flat master boards were visually inspected. No live case database or secrets were inspected. This is a source-level baseline audit, not exhaustive workflow acceptance or a legal-compliance certification. Subsequent packages require detailed implementation review and tests of each affected workflow.

PHP CLI 8.2.12 and Node 22.17.0 are available. Manifests specify Laravel 12, Vue 3, Inertia 2, TypeScript, Tailwind 4 and Vite 6. PostgreSQL remains the application database; PHPUnit explicitly uses in-memory SQLite. This folder has no Git metadata. No remote will be created. File changes and test evidence must be recorded here until owner-approved version control is available.

## Architecture and data flow

- Authenticated HTTP requests pass active-account, session, MFA and security middleware. AppServiceProvider registers permission Gates from RolePermissions. Controllers use Gates plus case/LGU/MDT scope. No separate policy directory or rich Eloquent case models: User is the model and most domain persistence uses Query Builder. Preserve those boundaries while extracting focused services where justified.
- clients -> cases (optional reports) -> assessment/participation/risk, plans/interventions, tasks, referrals/providers, conferences, notes, monitoring, documents, closure/aftercare. Migrations also define identity reconciliation, records governance, audit, normalized LGU assignment, CPU membership, MDT grants and offline device/sync records. Foreign keys, indexes, unique reference/version/idempotency keys are defined in migrations. Live PostgreSQL constraint enforcement and migration state remain unverified.
- Online casework: permission -> authorized query -> minimized Inertia props -> validated transition/transaction -> history/audit. CPU circle membership is independent from MDT case grants. Report generation rechecks case scope and audits access/download.
- Offline: approved pack -> device/passphrase encryption in IndexedDB -> encrypted operation queue -> authenticated sync -> current permission/version/idempotency checks -> acknowledgement/conflict recovery. CPU chat, MDT and generated reports remain online-only. Existing offline pack limits are 50 assigned cases and 72 hours; these are implementation values, not newly approved policy.
- Aggregate reporting: separate management permission and dataset; suppression threshold five. Repeated-filter inference and secondary suppression require further review before release.

## Existing versus proposed module and gap matrix

Existing means source implementation found, not acceptance passed. E = enhance existing, N = new capability, B = policy blocked. All current working features are KEEP unless explicitly classified below. No removal is authorized by this plan.

| Module / reference | Existing route, implementation and data | Disposition and gap | Verification |
| --- | --- | --- | --- |
| Shell/design system | AuthenticatedLayout, shared controls, CSS tokens | REFACTOR/E: flat surfaces, accessible navigation, reusable page header; keep logo and role Gates | Build, keyboard, responsive shell |
| Worker dashboard | dashboard, CaseworkController, Dashboard; scoped cases/tasks/referrals | REDESIGN/E: action-first queue, honest KPI definitions, compact charts; keep geographic/workload functional value | RoleAuthorization, LguScopeCpuCoordination |
| Intake and household | cases.create/store, Cases/Create, clients/reports/cases/family | REFACTOR/E: guided progression, safe encrypted save/resume; preserve privacy and validation fields | CaseworkFlow, IdentityIntegrity, OfflineIntakeSync |
| Case list and advanced search | cases.index, search, SearchController, Cases/Index | REFACTOR/E: exact detail-scope parity, authorized filters, pagination; minimize addresses/query-string PII | Search, jurisdiction and new scope regression |
| Unified case workspace | cases.show, Cases/Show, related child tables | REFACTOR/E: contextual sections and next action without duplicate modules | CaseworkFlow, CaseMaintenance |
| Safety/risk | PlanningController, repeatable risk assessments | KEEP/E: accessible history and source distinctions, no automatic score | RiskAssessmentWorkflow |
| Comprehensive assessment | versioned sections and transitions in PlanningController | KEEP/E: honest section completion, reviewer history, restricted access | ComprehensiveAssessmentWorkflow |
| Child participation | ChildParticipationController and versioned child records | KEEP/E: protect statements, maintain distinct decision context | ChildParticipationWorkflow |
| Plans/interventions/services | Plans/Workspace, PlanningController, CasePlanLifecycleController | KEEP/E: integrated goals, review, continuity and interventions | PlanningWorkflow, CasePlanLifecycle |
| Monitoring/progress notes | case monitoring and versioned/final notes | KEEP/E: structured progress/follow-up timeline | ProgressNoteWorkflow, CaseArtifact |
| Conferences/supervision | Conferences/Index, CaseConferenceController, plan/assessment reviews | E/B: focused review queue; no distinct supervisor role in current catalogue | CaseConference, role review |
| Closure/aftercare | PlanningController, CaseLifecyclePolicy, closure/aftercare tables | KEEP/E: checklist and immutable history; preserve transition constraints | CaseClosureWorkflow |
| Kalinga Connect | cpu.coordination.*, Cpu/Coordination, LGU circles/messages/reads/mentions | REFACTOR/E: independent navigation, chat ergonomics/pagination/member validation; attachments/retention expansion B | LguScopeCpuCoordination plus revocation tests |
| MDT CPU referral pathway | referrals.*, mdt.*, cases.mdt.*, Referrals/Index, Mdt workspaces, grants/actions/notes | REDESIGN/E/B: integrate structured referral and partner action timeline; keep case-granted discussion secondary; new routing states require approval | ReferralWorkflow, MdtCollaborationAccess |
| Provider directory | providers.*, Providers/Index, service_providers | KEEP/E: scoped service search and usable mobile presentation | ProviderDirectory |
| Tasks/calendar | tasks.*, calendar.index, dedicated Tasks and Calendar pages | KEEP/E: deadlines and keyboard event details | TaskWorkflow, CalendarOpenActivities |
| Notifications | notifications.*, OperationalNotification, shell/inbox | KEEP/E: metadata-only previews, accessible disclosure and error feedback | NotificationInbox |
| Offline field workspace | Offline/Workspace, Offline* controllers, encrypted client modules | KEEP/E: no data loss, queue recovery, expiry/revocation/conflict evidence | Offline* tests and dedicated GIDA matrix |
| Case documents | CaseArtifactController, private document versions, FileScanService | KEEP/E: safe upload/download, classification/provenance and scan state | CaseArtifact, FileScanService, RecordsGovernance |
| Case summary/report generator | cases.social-report.*, SocialCaseManagementReportController, Reports/SocialCaseManagement | E: explicit draft label/source metadata; current output CSV, not PDF | LguScopeCpuCoordination, report leakage tests |
| Study/intervention/monitoring/list outputs | existing authorized source tables, analytics CSV | N/B: official templates, field allowlists, approval/signatory and release rules absent | New template/redaction/export tests before release |
| Management/analytics | management.*, AnalyticsController, Management/Overview/Section, Analytics pages | KEEP/E/B: separate aggregate-only workspaces; suppression/differencing and export-permission audit | ManagementAnalyticsPrivacy, AggregatePrivacy, AnalyticsExport |
| Accounts/LGU/roles/settings | AdminController, Admin pages, normalized assignments, settings | KEEP/E/B: actual four roles; no invented management toggle or new privilege | JurisdictionAndAccountAccess, RoleAuthorization |
| Forms/templates | admin.forms, existing definitions | KEEP/E/B: distinguish registry metadata from approved official documents | Admin authorization and template review |
| Import/export | ValidateLegacyCsv command, migration tooling, authorized exports | KEEP/E/N/B: dry-run/reconciliation before any import execution; no general importer inferred | LegacyCsvValidator, CSV injection tests |
| Audit/retention/disclosure | RecordsGovernanceController/service, audit and governance tables | KEEP/E/B: append-only application audit; immutable storage/retention decisions unresolved; disposal stays disabled | RecordsGovernance |
| Identity/legacy reconciliation | IdentityReviewController, IdentityIntegrity, candidate/link/merge tables | KEEP/E: narrow query access, preserve provenance, approval and transaction integrity | IdentityIntegrity |
| Public intake/insights/help/profile/auth | public report/dashboard, auth/2FA/Google/profile/help routes | KEEP/E: secondary public reporting; no public case-existence lookup | Public*, Auth*, SecurityHeaders |

## Permission matrix and discrepancies

Canonical current roles are system_admin, social_worker, cpu_member and fo_user. Permission grants include dynamic additions in RolePermissions::allows and rolePermissions. Review old role labels as migration history, not current authority.

| Surface | social_worker | fo_user | cpu_member | system_admin |
| --- | --- | --- | --- | --- |
| Case detail/worklist | Assigned case AND active normalized LGU | Assigned province scope | Denied; separate limited MDT workspace only | Denied |
| Risk/assessment/participation | Scoped permissioned access | Scoped permissioned access | Denied by default | Denied |
| Case plan/review/closure | Existing specific Gates plus scope/state | Existing specific Gates plus scope/state | No primary casework editing | Denied |
| CPU group chat | Chat permission + membership + active LGU | No default chat grant | Chat permission + membership + active LGU | View/manage permission still subject to endpoint membership checks |
| MDT workspace | Scoped case manager | Scoped oversight | Current purpose-limited case grant | No default case grant |
| Offline | offline.use; assigned limited pack | No grant | No grant | No grant |
| Individual social report | reports.social.generate + assigned case scope | No default generation grant | Denied | Denied |
| Aggregate analytics | Scoped operational data | Scoped province data | No grant | Aggregate-only; threshold five requires endpoint review |
| Management overview/export | Denied | Denied | Denied | management.dashboard.view/report.export |
| Administration/audit | No default admin grant | No default admin grant | Denied | admin.manage/audits.view |

Critical source finding at initial audit: scopeCases used LGU alone for social_worker while allowsCase required both assignment and LGU. Search, dashboard, list, referral selectors and identity queries inherited the broader query. The first package now aligns query scope with existing detail authorization. Separate LGU workload aggregates intentionally remain available. This is enforcement repair of the documented assignment rule, not a new role policy. Offline pack scope was also repaired; see DELIVERY-STATUS.md for tests and limitations.

HANDOFF incorrectly describes fo_user as aggregate-only and server enforcement as policies; current implementation permits province-scoped casework via Gates/services. Older architecture docs describe removed roles and admin dashboard landing differently. Tests/TestCase::actingAs mutates some legacy fixtures and can convert social_worker to fo_user; new access regressions must use explicit normalized fixtures and authentication without that adapter. No automatic role migration is part of this work.

## Privacy and security risk register

| ID | Risk / evidence | Priority | Resolution or next action |
| --- | --- | --- | --- |
| R01 | List scope broader than case detail; identities sent in Inertia props | Critical | Regression first, align assigned-case query, retain aggregate workload |
| R02 | Legacy role/docs/test-adapter disagreement | High | Document current grants; owner must approve distinct supervisor/management roles before changes |
| R03 | Analytics grouped suppression does not establish safe totals or repeated-filter exports | High | Audit all KPI/export paths, secondary suppression and export Gates; no certification |
| R04 | Individual report contains identifying data and lacks explicit draft status | High | Label draft in preview/CSV; approved field/template/signatory rules needed before official release |
| R05 | Offline pack query checks assignment without normalized LGU | High | Separate regression and scope-parity review, including revoked/expired LGU assignment |
| R06 | Chat directory/per-message queries may have active-assignment parity and N+1 gaps | High | Review membership lifecycle on every related read; paginate and batch queries |
| R07 | Disconnected device cannot receive immediate revocation | High | Encrypted expiring packs, reconnect checks, lost-device operating procedure; owner-approved device policy |
| R08 | Audit application append-only but same database; retention values are draft | High | Owner/DPO retention and integrity controls; never enable deletion on a guessed schedule |
| R09 | Legacy narrative/query-string/address exposure and service-worker caching | High | Minimize payloads; assess URL/history/cache and authenticated offline boundaries |
| R10 | Scan/backup/storage/security configurations not operationally verified | High | Isolated upload/scanner tests, PostgreSQL restore rehearsal and production configuration review |
| R11 | Oversized case component and stale UI copy obscure workflow boundaries | Medium | Focused components and canonical terminology without removing working actions |

## Data and policy requirements

No schema change, live migration, seeding, user-role mutation, external deployment or sensitive-data transmission is needed for the first package. Future additions require additive reversible migrations, explicit field purpose and retention, FK/index/version/idempotency design, synthetic tests and backfill dry-run; destructive or material access changes require owner approval.

Owner/DPO/qualified case-protection review: actual revised CANE+D text and applicable CEFMU procedures; approved intake and 11 assessment sections; child participation safeguards; current legacy 24/72-hour, 7/30-day deadline definitions; referral routing and disclosure basis; supervisor separation of duties; management role; lawful basis and controller designation; retention/chat file storage/device expiry; secondary suppression; official report templates and signature/release authority. Preserve existing safe behavior pending decisions. PIA and retention documents contain historical/unverified assertions and must not be treated as compliance certificates.

Primary references checked 2026-10-17: [NPC DPA](https://privacy.gov.ph/data-privacy-act-/), [NPC IRR](https://privacy.gov.ph/implementing-rules%20regulations-data-privacy-act-2012/), [government agency security circular](https://privacy.gov.ph/npc-circular-16-01-security-of-personal-data-in-government-agencies/), [RA 11596 IRR](https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/9/96305), [DOJ protocol launch](https://www.doj.gov.ph/news_article.html?newsid=6EHYSJJRWJejGgXDreYliNWg_2ceVxZ_5Tt0sygYFjE). These establish reference materials for qualified review, not approval of application fields or local policies. The launch announcement is not the full protocol.

## Dependency-aware delivery and acceptance

| Package | Dependencies | Deliverable / completion evidence |
| --- | --- | --- |
| P0 audit | Source/reference review | This plan, source inventory, risk/decision register and persistent status |
| P0-S1 security regression | R01 | Failing-then-passing scoped query test, cross-role/assignment regression and retained LGU aggregate |
| P1 shell foundation | P0 | Flat shell, separate CPU chat/referral navigation, shared PageHeader, focus/skip-link/current-page semantics, keyboard mobile dismissal; build and responsive tests |
| P2 worker workflows | Scope repair, approved existing transitions | Action queue and four defined KPIs, filters/intake/workspace components, versions, review and closure; positive/negative lifecycle tests |
| P3 coordination/offline | P2 boundaries, R05/R06 | Structured referral integration, chat ergonomics, member revocation, metadata notifications; encrypted pack and reconnect matrix |
| P4 oversight/reports | Approved audience/template decisions | Review worklist, safe aggregate views, draft preview and approved exports; leakage and inference tests |
| P5 readiness | All packages | PostgreSQL-specific integration, security/accessibility/performance and field UAT, backup restore, owner/DPO sign-off; deployment separately authorized |

Every package updates DELIVERY-STATUS.md with affected files, behavior, data/security impact, tests, assumptions and next work. Each module progresses source-found -> audited -> implemented -> automated-tested -> responsive-reviewed -> domain-accepted; do not collapse these states into complete.

## Test plan

1. Run existing PHPUnit suite against configured in-memory SQLite and frontend type/build checks as baseline. Never follow README/HANDOFF migrate/seed instructions against the application database.
2. New access tests use synthetic users/cases/LGUs, cover same-LGU other worker, own case, cross-LGU own case, expired/revoked assignment, FO province, CPU/admin denial, search/referral selector/dashboard payloads and aggregate workload preservation.
3. Exercise CPU read/search/post/capture revocation and MDT expiry/revocation separately. Test unauthorized document/report URLs, CSV injection, restricted content redaction, final note immutability and invalid transitions.
4. Browser tests use a new isolated synthetic SQLite fixture database and a separate loopback port. Existing global setup uses migrate:fresh on database/e2e.sqlite; do not invoke it against an existing database or real endpoint. Review fixture lifecycle first.
5. Check 360, 768 and 1440px: no overflow, visible/touchable controls, keyboard focus, drawer dismissal, notifications, logout and separate chat/referral links. Automated accessibility checks plus manual visual review; passing build alone is insufficient.
6. Offline matrix separately covers no/intermittent signal, slow requests, session expiry, browser close, quota failure, duplicate sync, version conflict, revoked/expired access, recovery and wipe. Do not mark these passed from source inspection.

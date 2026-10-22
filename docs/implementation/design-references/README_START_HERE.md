# KALINGA IS — CEFMU UI Reference and Codex Handoff

## Purpose
These are **concept mockups**, NOT screenshots of an implemented system and NOT approved data collection/reporting forms. All people, cases, counts, dates, decisions and agencies shown are illustrative. The user prefers a professional, restrained, flat enterprise UI over gradients, decorative silhouettes, overly colorful panels or dense AI-art collage styling.

This package contains **30 individually cropped reference panels**, **2 full dashboard alternatives**, **4 full-size report references**, and the two original consolidated flat-design boards. The original board art was generated at limited resolution, so the smaller cropped panels may not be legible. **Do not treat text, numbers or invented labels inside images as authoritative specifications.** Build genuine responsive interface components from the actual code and governance docs, not screenshots as one giant image.

## Before editing
- Read the actual repository README, routes, role/permission service, Dashboard.vue, Cases/Show.vue, Management/Overview.vue, Management/Section.vue, Analytics/Index.vue, and accompanying casework controllers/services/tests.
- Review docs/03-architecture/ROLE-AWARE-WORKSPACE-SEPARATION.md and docs/03-architecture/LGU-SCOPE-CPU-COORDINATION-AND-REPORTING.md, docs/05-governance/PRIVACY-IMPACT-ASSESSMENT.md, and current security, operations, and UAT notes.
- Preserve the existing Laravel + Vue + Inertia architecture and existing workflows/data. Do not reset/replace DBs or seed fictional content into production. Do not introduce open-access dashboard endpoints or bypass role checks.

## Product domain and scope
Kalinga IS supports the **Committee for the Eradication of Forced Marriage and Unions (CEFMU)**. Use the Revised CANE+D child-protection case-management protocol as a process and safeguarding reference **only insofar as applicable to CEFMU cases**. Do NOT expand Kalinga IS into a generic all-category CANE+D registry. Validate intake, safety/risk assessment, child participation, social case assessment, case planning, referral, conferencing, interventions, monitoring, closure and aftercare with qualified CEFMU/DSWD policy owners.

IMPORTANT correction from product owner:
- **Kalinga Connect** = LGU-bounded **CPU (Child Protection Unit) group-chat messaging**, similar to enterprise team/group chat. It is primarily for ordinary authorized CPU team coordination, not automatic disclosure of client details.
- **MDT Coordination** = the **CPU referral pathway**, including referral intake/screening, routing, concerned partners, endorsements, response tracking, handoffs, timelines and outcomes. Treat the mockup caption 'MDT Collaboration' as 'MDT Coordination / CPU Referral Pathway'.
- Case-specific multidisciplinary discussion may exist under authorized case grants, but it is separate from the general CPU group chat, with no implicit transfer of access.

## UX direction
- Keep actual Kalinga identity/logo and codebase's purple #8d5fcc, sky blue #83cbdd, pale yellow #fffda6 as accent tokens; prioritize white, slate and accessible text over pastel floods.
- Flat enterprise layouts, restrained 8px spacing rhythm, typography hierarchy, simple thin borders, subtle/no shadows; responsive 1280–1440 desktop first with tablet/mobile support.
- Z-scan for caseworker home: top heading/search/time -> 4 actionable KPIs -> prioritized assigned case/action table -> scheduling -> only 2 key charts -> secondary shortcuts. Do not mix aggregate management access into case worker permissions.
- Prioritize 'what needs action next' rather than showing every field simultaneously. Use concise nav, searchable/filterable tables, properly labelled filters, breadcrumbs and consistent empty/loading/error states.
- Do not invent statuses, classification categories, due-date SLAs, clinical risk algorithms, or data points; map and test against existing backend definitions. Treat mockup examples as placeholder-only.

## Critical privacy and safeguarding acceptance rules
1. **Least privilege, purpose limitation, need-to-know, role + LGU + case scoping** enforced **server-side**, never solely hidden by Vue.
2. Management and executive dashboards must be **aggregate-only**, no individual identity, narrative, address, case pin or detailed chat. Maintain existing small-cell suppression (<5) and examine differencing/secondary suppression before any export. Export requests are audited.
3. Case-worker lists show only assigned/authorized cases. Restricted Health/SRH, participation, MDT records and documents require specific access gates; generic case-summary export must exclude them unless an explicitly approved release policy allows otherwise.
4. CPU group-chat membership does NOT imply case access. Case numbers can be referenced without auto-exposing case files; member lifecycle and LGU scope must be checked on every request. Avoid sensitive names, chat previews, attachments or narratives in notifications.
5. **Do not automatically turn group chat into formal case notes**. Require authorized worker review, editable professional summary, explicit confirmation, provenance and audit trail. Protect any MDT-specific conversation using case-specific grants/expiry.
6. Protect child identity in reports, print/PDF, shared screens, search results and offline copies; apply controlled disclosure/redaction and appropriate legal basis/consent handling. No fictitious privacy toggle can override authorization.
7. Offline packs must remain encrypted, locally scoped, revocable per existing capability, synced with conflict handling and expiry controls. Never store unencrypted sensitive information or messages in localStorage or external analytics.
8. Implement accessible controls, keyboard use, contrast, pagination, error states, sensible mobile layouts and print-specific layouts.
9. Every significant operation (report generation/download, access, export, case changes, referral, chat capture and disclosure) should follow documented audit controls.
10. Confirm official CEFMU templates, signatory policy, sharing/retention rules, indicator definitions and response-time standards with owner/DPO/qualified protection staff before deployment.

## Suggested phased delivery to Codex
**Phase 0 — Analyze:** produce a module-to-existing-code map, gaps, risk register, precise user stories and tests; seek sign-off before data-model or policy changes.
**Phase 1 — UI foundation:** create shared design tokens, accessible common components, app shell, responsive nav; no schema changes.
**Phase 2 — Case worker:** dashboard, assigned action queue, case list, case workspace, intake, risk, assessment, participation, plan, intervention, monitoring, closure.
**Phase 3 — Coordination:** Kalinga Connect CPU group chat and independently permissioned MDT CPU referral pathway, service-provider workflows, scheduling and notifications.
**Phase 4 — Oversight/reports:** supervisor worklist, management aggregate analytics, report chooser/preview/export and printable case summary/study/report templates; enforce permitted fields and signing logic.
**Phase 5 — Security/UAT:** authorization matrices, leakage/inference tests, offline sync/revocation tests, audit tests, role-specific acceptance tests and expert casework validation.

For each phase: list affected files, make small testable changes, use migration-safe patterns, run tests/build, and report what's implemented vs blocked for policy approval. No blind rewrite of working services.

## References to validate against (official sources)
- RA 10173, Data Privacy Act of 2012: https://officialgazette.gov.ph/2012/08/15/republic-act-no-10173/
- NPC IRR: https://privacy.gov.ph/implementing-rules%20regulations-data-privacy-act-2012/
- DOJ announcement, Revised CANE+D Protocol, 14 Aug 2025: https://www.doj.gov.ph/news_article.html?newsid=6EHYSJJRWJejGgXDreYliNWg_2ceVxZ_5Tt0sygYFjE
- DSWD CANE+D protocol support: https://www.dswd.gov.ph/dswd-pledges-support-for-enhanced-protocol-on-case-management-of-child-victims-of-abuse/
- Obtain and review the actual revised protocol and CEFMU-specific procedures/approvals, rather than inferring requirements from press releases.

## Report design caution
The supplied report samples have illustrative names, signatures, and narrative sections. **Do not export these as-is.** Generate from authorized case data only, redact according to report purpose and permissions, require appropriate review/sign-off fields before calling a document official, and label drafts clearly.

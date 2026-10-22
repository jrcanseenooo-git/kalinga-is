# LGU Scope, CPU Coordination, and Management Reporting

## Access model

- `system_admin` is national Central Office administration and aggregate management reporting only. It has no ordinary `cases.view` permission and cannot use case-detail routes merely because it can view national metrics.
- `case_worker` / `social_worker` may work only on cases assigned to them. Their dashboard also provides non-identifying LGU workload totals; it does not make other workers' case records browseable.
- `mdt_member` is a CPU/MDT counterpart. Membership has no standing case-file permission. A current `mdt_case_access` grant remains necessary for a particular case and is independently checked for expiry, revocation, deactivation, and case closure.
- `cpu_monitor` is a transitional, LGU-deployment role. It has no national analytics grant and no case-file permission; new CPU collaboration should use `mdt_member` plus an active case-specific grant.
- `fo_user` remains a separate province/regional oversight role. It must not be used to provide Case Manager or CPU access.

`lgu_units` and `user_lgu_assignments` are the authoritative target assignment model. They retain PSGC code, active/effective dates, and assignment history. Legacy user municipality/province fields remain a migration fallback only. New cases created by a user with an active normalized assignment must match that LGU. An MDT grant to a normalized member outside the case LGU is rejected.

## CPU Group Chat

Each `lgu_coordination_circles` row is an LGU-bounded operational coordination circle. Membership is explicit (`lgu_coordination_members`) and separately verified against an active LGU assignment before read, post, search, or capture operations.

Group messages may have parent/thread links, mentions, urgent/follow-up markers, and an optional case reference. The response contains only a case number and a `can_open_case` capability. A tag never grants access; opening a case still evaluates ordinary case scope or an active MDT grant.

Chat search is restricted to active members of the same LGU circle. Mention notifications are metadata-only and contain no message body, client identity, narrative, restricted document information, Health/SRH, or Child Participation content.

This is distinct from:

1. CPU Group Chat - operational LGU coordination;
2. case-specific MDT Discussion - active-grant/case-scope collaboration; and
3. formal Progress Notes / MDT Notes - controlled case documentation.

## Controlled record capture

`POST /cpu/coordination/{circle}/messages/{message}/capture` never copies a conversation automatically. It requires an authorized case, a reviewed/editable professional summary, explicit confirmation, and a selected record type. It stores only the source-message ID plus concise provenance on the resulting note and writes a metadata-only audit event. MDT-note capture additionally requires an active case-specific MDT grant.

## Notification rule

Coordination notifications use generic operational wording. Intake self-assignment and task notifications were aligned to this rule. Notification records must not contain child names, case narratives, Health/SRH, Child Participation content, sensitive identifiers, or restricted-document metadata.

## Reporting

`/management-dashboard` is Central Office aggregate-only. It offers filters where supported and applies a minimum-cell suppression threshold of five to both grouped rows and KPI values, including a narrowly filtered result; it returns `Suppressed` rather than an exact small count. The downloadable CSV embeds generation time and the suppression rule, escapes CSV cells, and is audit logged without saving case narratives. Heatmaps remain scoped aggregate views, expose a keyboard-operable region selector, and include a table alternative; they must never become client-pin maps. Final official CEFMU KPI definitions, secondary-suppression/differencing rules, and public-release thresholds remain **POLICY VALIDATION REQUIRED**.

`/cases/{case}/social-case-management-report` is case-scoped and audited. Its current approved-safe output includes basic case/client header, plan/referral/aftercare metadata. Health/SRH, Child Participation, MDT content, restricted documents, and unrestricted narrative fields are excluded. Formal template, signatory, and disclosure rules remain **POLICY/TEMPLATE VALIDATION REQUIRED**.

## Offline boundary

CPU coordination chat and report generation are online-only in this release. No group-chat content or report output is placed into Field Mode packs or offline queues. Offline support requires a separate encrypted-device, revocation, queue-conflict, retention, and disclosure assessment.

## Release QA note

The authenticated logout flow performs a full navigation to the login page after server-side session invalidation and CSRF-token regeneration. This prevents an immediately-following local sign-in from submitting a stale Inertia CSRF token. The global application-shell notification and logout icon controls remain a tracked pre-release touch-target improvement.

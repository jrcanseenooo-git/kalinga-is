# Connect messaging delivery - 2026-10-23

## Scope and baseline review

Owner approved the messaging proposal with flat colors matching the existing
interface. KEEP group authorization, case-reference lookup, reviewed group
capture, search, pagination, per-conversation memory drafts and uncertain-send
recovery. REFACTOR shared member eligibility into CoordinationAccess. REDESIGN
the existing composer selection panel. ADD direct conversations between two
currently eligible members of the same LGU circle. No presence, calls or
attachments are fabricated.

The previous additive circle repair exposed eight groups because demo workers
already had eight effective assignments. Hiding groups would leave those
assignments active. The demo setup now provisions Tabuk assignments only.
KalingaConnectDemoScopeSeeder closes historical extra DEMO-LGU-02 through 08
assignments and memberships only for the three named worker/supervisor/CPU demo
accounts. It retains the rows, records an audit event and never deletes cases,
messages or groups. Other users, real LGUs and legitimate existing multi-LGU
assignments are unchanged. Non-demo assignment changes use the existing admin
workflow; this is not a new single-LGU policy for every deployment.

## Actual behavior and UX

- The three local demo accounts each see the City of Tabuk CPU Coordination
  Circle only. Current membership plus current LGU assignment remain required.
- Direct messages can be opened from the eligible member's Message button or
  the desktop Direct messages list. On phones/tablets, open Members first.
- Private reads, POST search, bounded earlier history, replies, urgency and
  follow-up use the exact circle/sender/recipient pair. Group previews and
  formal capture do not consume private messages. A third participant's own
  conversation cannot reveal the pair's text.
- Group case/urgency/follow-up/mention selections appear as removable chips
  inside the composer. Their underlying metadata is retained rather than
  injected into body text. Focusing the textarea closes the panel without
  clearing draft text or selections. The panel can be reopened, closed with its
  close button or dismissed with Escape. Selection controls remain keyboard
  accessible with visible focus.
- The compact selection panel is anchored above the composer with bounded
  internal scrolling so the phone input remains visible. The chat uses a solid
  pale background and existing purple accents, without gradients.
- Drafts are separate for each circle/recipient pair, in tab RAM only. Sends
  lock controls until acknowledgement. Failed readback after acknowledgement
  does not resend. Unknown outcomes require refreshing and explicit review.
- Private conversations support plain coordination text and the existing
  urgency/follow-up flags. They have no case tags, mentions, attachments or
  Record as note. Case access is never inferred from circle membership.

## Database and security

Migration 2026_10_23_120000 adds coordination_direct_messages with circle/user/
parent foreign keys and directional compound indexes. No existing schema or
case narratives are rewritten. Store authorization rechecks both participants
inside a transaction locking the circle, LGU, users, memberships and assignment
rows, with metadata-only audit in the same transaction. Reply IDs are validated
against the same pair and circle. Reads/search/sends reject inactive, departed,
expired or future assignments, disabled circles and ineligible permissions.
Responses are private/no-store. Chat bodies and search terms are not copied to
audit metadata or URLs by the UI. Browser persistent storage is not used for
drafts or private history.

The local migration and scope repair were applied. Read-only controller
verification returned exactly one Tabuk circle for worker@kalinga.test,
supervisor@kalinga.test and cpu@kalinga.test. The existing post-migration
kalinga:lock-down-database-api command was run locally; PostgreSQL reports RLS
enabled for the private-message table. For another installation, run normal
migrations and that existing lockdown command as documented by deployment.
Run the demo scope seeder only on an intended local/testing demo installation.

## Affected modules and reproduction

Backend: CoordinationAccess, CpuCoordinationController,
CoordinationDirectMessageController, routes/web.php and the additive migration.
Frontend: Cpu/Coordination.vue, useConnectWorkspace.ts, connect.ts,
CoordinationCaseSelect.vue and in-app Help & guides. Demo: KalingaDemoSeeder and
KalingaConnectDemoScopeSeeder. Database dictionary, generator catalogue and
privacy data flow document the new processing surface.

Verification commands:

```text
php artisan test
php artisan test --filter="CoordinationDirectMessageTest|KalingaConnectDemoSeederTest"
npm run test:frontend
npm run build
node node_modules/@playwright/test/cli.js test -c playwright.connect.config.ts
```

The dedicated browser configuration uses the installed Chrome channel and a
fresh guarded synthetic SQLite database, not the local working PostgreSQL
database. The browser fixture now reuses seeded circles/memberships instead of
violating their unique constraints. New browser tests are also registered in
playwright.foundation.config.ts.

## Evidence and limitations

Full backend: 451 passed, 2 PostgreSQL-only checks skipped, 3330 assertions.
After adding six further pair-eligibility scenarios, targeted backend:
17 passed, 111 assertions. Frontend: 33 passed. Production build and PHP style
checks passed. An initial browser run had seven overbroad summary-selector
failures; the next had one hidden-checkbox test-click failure. These were test
locator issues, corrected without dropping assertions. A subsequent complete
25-test Connect run passed. Visual review then found phone input clipping with
the panel open and the panel was anchored above the input. The final complete
25-test Connect run passed in 2.7 minutes, including open-panel input visibility
and serious/critical axe checks at all six widths. Contact controls also exclude
roles lacking the existing chat-post permission; the server remains authoritative.

Screenshots: evidence/connect-2026-10-23 contains options, composer and private
conversation views at 360, 390, 768, 1024, 1440 and 1920 CSS pixels. Tests include
serious/critical axe findings, overflow, input visibility, chip preservation,
direct send/recipient read, third-member isolation, drafts and lost
acknowledgement recovery, alongside existing group/capture checks.

Direct messaging is online-only and refresh-based. No push notifications,
unread/read receipts or live delivery claims are added. Reloading closes the
private view; reopening the recipient reloads its persisted server history.
Unsent drafts are held only in the open tab, not across a browser restart.
Production retention/review policy, field-device UAT and PostgreSQL concurrency
certification remain separate owner/operational acceptance work. Approved
privacy/retention decisions are not inferred from this feature. The original
ZIP is unchanged and nothing was pushed or deployed externally.

## Account-specific follow-up and CPU case-workspace findings

The owner's later screenshot used the existing Kalinga CPU Member Health Officer
account (local user 5), not the canonical cpu@kalinga.test account checked in the
earlier repair. It already had a current Tabuk assignment but no circle
membership. Its missing membership was added through CpuCoordinationController
addMember, under an active system administrator and an enclosing transaction,
using the existing current-LGU validation and metadata-only audit. No existing
departed membership was restored. The actual account's controller readback now
returns one Tabuk circle, three existing group messages and four eligible
members. Its role, password, LGU assignment and case grants are unchanged.
The display name mentions Health Officer while the stored discipline is
social_worker_cpo; discipline is not silently inferred from a display name.

Read-only inspection of the separate MDT case workspace found that caseData
already returns grant-authorized summary, plans and referral-service metadata,
but Mdt/CaseWorkspace.vue does not render these fields or the referral purpose.
Its Grant status card uses data.status (the case status) instead of the grant
status. These are distinct presentation/read-model gaps, not a reason to expose
the full case file or broaden grants. No MDT page, permissions or disclosure
payload was changed in this Connect delivery. Any follow-up must preserve the
grant's authorized categories and selected-record boundaries.

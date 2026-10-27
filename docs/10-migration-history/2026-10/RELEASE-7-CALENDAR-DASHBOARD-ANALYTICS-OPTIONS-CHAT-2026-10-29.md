# Release 7 - Shared calendar, to-do dashboard, analytics map, form options and team chat (2026-10-29)

Owner request ("Finish all phases until complete") from the improvement
plan, Phases 5 to 8. Branch `feature-enhancements`.

## Phase 5 - Calendar sharing and phone notifications

- Invitations (`task_participants`): the person creating an activity picks
  colleagues under "Invite people". Invitees are notified, see the activity
  in their own calendar and answer Going or Not going. Who may be invited is
  decided by `App\Services\CalendarSharing` (same LGU team, the LGU Head,
  the Field Office of the region and Project Managers covering it).
- Rescheduling or cancelling needs a reason (at least 5 characters). The
  change and reason are kept in `task_history` (History on the activity) and
  audited as `task.rescheduled` / `task.cancelled`.
- Who is told about a change: invitees still going, invited case managers
  (even if not going), the case manager assigned to the linked case, the LGU
  Head (supervisor), the Field Office of the region and the Project Managers
  covering it (owner decision 2026-10-28).
- The notice title starts with "Rescheduled:" or "Cancelled:", gives the old
  and new date and the reason, and shows a Rescheduled / Cancelled label in
  the bell and on the Notifications page. Project Managers and Field Offices
  get an extra first line: "For your information: this activity was
  RESCHEDULED" (or CANCELLED).
- Phone notifications: Web Push with VAPID keys (`App\Services\WebPush`, no
  outside library). Every in-app notification also sends a push with **no
  content** (nothing passes through the push service). On arrival the phone
  asks Kalinga for the newest unread notifications: if the browser is still
  signed in it shows up to three titles and short texts (the same text as
  the bell; these carry no child names); otherwise it shows a generic
  "You have a new notification. Open Kalinga to see it." Pushes are kept by the push service for
  one day, so a phone that was offline receives them when it reconnects.
  Expired subscriptions are removed. Users turn this on in the bell panel.
- The app is installable (web manifest, icons; service worker cache v17).
- CPU members gained the calendar (`activities.manage`) so they can receive
  and answer invitations.

**Deploy step:** run `php artisan kalinga:vapid-keys` once and set
`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` and `VAPID_SUBJECT`
(for example `mailto:` the system mailbox) on Render. Without them the
phone switch is hidden and everything else works.

## Phase 6 - To-do dashboard and analytics

- The Dashboard is a to-do list only (`App\Services\DashboardTodos`): case
  tasks due, calendar invitations, closures to review, remarks and replies,
  the watch list, accounts to approve, security items and saved intakes,
  each for the roles that do that work, plus "Coming up" and shortcuts.
  Charts moved to Analytics. CPU members go straight to their referrals.
- Analytics drill-down map: Philippines > region > province > city or
  municipality > barangay (NCR goes from region to cities). Every card,
  chart and trend follows the selected place, year and quarter.
- Trend lines: last 12 months and last 8 quarters.
- Export PDF prints the page with a watermark (user and time); each export
  is audited as `analytics.pdf_exported`.
- Maps are self-hosted: `public/data/boundaries` (PSGC 3rd quarter 2026,
  simplified TopoJSON, MIT licence, see `SOURCE.md`), drawn with
  `topojson-client`. No outside map service is called.
- Small-number suppression (fewer than five) still applies at every level.

## Phase 7 - Editable lists, form preview and feedback

- Administration > Form options (`options.manage`: System Administrator and
  Project Manager) edits 13 lists (`config/option_sets.php`), including
  disability types, risk factors (with points), grant types, relationships,
  referral sources and partner agencies.
- Changing or switching off a choice in use shows how many open cases and
  saved intakes use it and asks for confirmation (HTTP 409 until
  confirmed). Switched-off choices stay on old records.
- Risk score levels (moderate, high, critical) are editable there.
- Extra intake fields: text, long text, list, date, number or yes/no, with
  help text, a live preview, and on/off. Values are stored in
  `cases.custom_fields`. The old Forms admin page redirects here.
- Feedback (`user_feedback`): "Send feedback" in the user menu (kind,
  subject, message, optional rating; only the page path is kept). The
  inbox (`feedback.review`) sets status and replies; the sender is notified.

## Phase 8 - Project team chat

- `connect_threads`, `connect_thread_members`, `connect_messages`.
- Project Managers (`connect.network`) search active case managers, LGU
  Heads and Field Offices in the regions they cover (national PMs: all
  regions), message them directly and create named groups such as
  "Region VIII". People added can read and reply, and can leave groups.
- Separate from the LGU coordination circles; never linked to a case.
  Sending is limited to 40 messages a minute per user.

## Owner review

Decided by the owner on 2026-10-28:

1. Lock-screen text: keep (title and short text when signed in).
2. CPU members keep the calendar: yes.
3. Change notices: add the case manager and make the change explicit for
   Project Managers - done (see Phase 5 above).
4. Map boundary source (PSGC 3Q 2026, simplified): accepted.
5. Default choices in the lists, risk points and score levels: owner to
   check in Administration > Form options (open).
6. Field Offices starting team chat conversations: no; Project Managers only.
7. Feedback kinds: accepted.

Still to do on deploy: set the VAPID keys on Render.

## Tests

- PHP: `CalendarSharingAndPushTest`, `TodoDashboardAndDrillDownTest`,
  `FormOptionsAndFeedbackTest`, `ConnectNetworkTest`, plus updated
  operations, dashboard and authorization tests; full suite on SQLite and
  PostgreSQL.
- Browser: `analytics-dashboard.spec.ts`; dashboard tests in
  `ui-transform.spec.ts` rewritten for the to-do list.
- Data dictionary regenerated with the seven new tables and
  `cases.custom_fields`. Help guides added: dashboard, phone notifications,
  form options, feedback, team chat; calendar and analytics updated.

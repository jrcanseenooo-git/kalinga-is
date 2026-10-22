# Kalinga Connect - group chat layout (2026-10-19)

## Apply
Copy the files over the project (same paths), then run `npm run build` and `php artisan optimize:clear`.
No database migration.

## What changed
- resources/js/Pages/Cpu/Coordination.vue - rebuilt as a messenger layout:
  conversation list (circle, latest message, time, unread badge), chat bubbles
  (your messages on the right, others on the left with initials avatar, name, role),
  day separators, grouped consecutive messages, reply quotes, Urgent / Follow-up /
  case chips, members panel (side column on wide screens, slide-over on smaller ones),
  composer bar with @ mentions, # case tag, urgent and follow-up toggles.
  Enter sends, Shift + Enter adds a new line. Reply and Record as note stay on
  every message. Phones: circles become a swipeable row; composer options sit
  behind a + button.
- The "Ready to submit?" confirmation no longer appears for each chat message
  (it does for Record as note, which creates a formal record).
- app/Http/Controllers/CpuCoordinationController.php - each message now says
  whether it is yours (is_own; author ids are not sent). The circle list includes
  member count, latest message excerpt (80 characters), time and unread count,
  only for circles the user already belongs to.
- tests/e2e/foundation.spec.ts - removed the confirmation clicks after Send message.

## Unchanged
Permissions, LGU membership checks, search (POST, no query text in URLs),
pagination (50 per page, Load earlier messages), Record as note flow, audit logs.

## Tests here
php artisan test: 364 passed, 1 failed (pre-existing ClosureLineageTest).
npm run test:frontend: 28 passed. vue-tsc and vite build pass.
Run `npm run test:e2e` locally.

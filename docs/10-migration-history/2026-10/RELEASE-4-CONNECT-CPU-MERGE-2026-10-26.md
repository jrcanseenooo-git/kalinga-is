# Release 4 - Connect private messages, CPU workspace, merged with the online build (2026-10-26)

The owner's updated project (kalinga-is_5, work dated 2026-10-23) was built on
Release 3a and did not yet contain the deployment fixes made for the online
demo (Render + Supabase). This release combines both.

## From the owner's update (kept as delivered)

- Kalinga Connect private messages between two current members of the same
  LGU circle (`coordination_direct_messages`, `CoordinationDirectMessageController`,
  shared eligibility in `CoordinationAccess`). Details:
  `docs/implementation/CONNECT-MESSAGING-2026-10-23.md`.
- CPU assignment workspace (`Components/Mdt/CpuAssignmentWorkspace.vue`,
  `useMdtWorkspace`). Details: `docs/implementation/CPU-WORKSPACE-2026-10-23.md`.
- Demo scope: demo accounts belong to the City of Tabuk only
  (`KalingaConnectDemoScopeSeeder`, `KalingaConnectDemoSeeder`).
- UI component, intake draft and test updates.

## Kept from the online build (not in the owner's copy)

- Dockerfile copies `build/` into the asset stage (Vite helper).
- nginx FastCGI buffers raised (fixes 502 on pages with long headers).
- `config/app.php`: Render's APP_KEY format and RENDER_EXTERNAL_URL.
- `docker/start.sh`: demo seeding and the in-container scheduler.
- `render.yaml` (free single service, Supabase, Gmail, Google sign-in, demo).
- `SeedDemoData` command (`kalinga:seed-demo`).

## Changes made while merging

- The two Connect demo seeders refused to run outside local/testing and found
  the demo accounts by their `@kalinga.test` addresses. Online, the accounts
  use real addresses and the server runs as production. The seeders now accept
  account ids (`forUsers([...])`) and also run when `DEMO_SEED=true`.
- `kalinga:seed-demo` applies the Tabuk-only demo scope to an existing online
  demo on the next start. Effect online: the demo case worker and supervisor
  keep only their City of Tabuk assignment and circle, so they see the Tabuk
  demo cases; cases in the other demo LGUs stay in the database.
- Not carried over: `public/hot` (development leftover) and
  `resources/js/Pages/Welcome.vue` (removed in Release 3).
- New test `tests/Feature/OnlineDemoSeedingTest.php`.

## Verification

- PHP: 465 passed on PostgreSQL; 464 passed and 2 skipped (PostgreSQL-only) on SQLite.
- Front end: 33 passed; type check clean; Pint clean.
- Browser suite: 261 tests. 257 passed on the first run; the 4 Connect tests still expected the old group name and the old "Open permitted case" button. After updating them, the foundation file passed 26 of 26.

## To deploy

Merge to `main` on GitHub; Render deploys automatically. The new migration
creates `coordination_direct_messages`, and the start script locks it
against the Supabase Data API like every other table.

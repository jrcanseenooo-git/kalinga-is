# Kalinga CEFMU Case Management Information System

Web-based, mobile-first case management for child, early and forced marriage
and unions (CEFMU) cases under DSWD Project Kalinga. Case managers of the
pilot LGUs (C/MSWDO) handle each case from intake to closure, refer children
to CPU members with time-limited access, work offline in GIDA areas through
Field mode, and print the case management reports (Kalinga Intake Sheet,
Progress Notes, Intervention Plan, Social Case Study Report, Case Summary).

Stack: Laravel 12, Inertia 2, Vue 3 with TypeScript, Tailwind CSS 4,
PostgreSQL. Status: pilot build, not yet deployed to production.

## Documentation

| For | Where |
|---|---|
| Users, ICTMS, PIA, VA, QA | [docs/DOCUMENTATION-INDEX.md](docs/DOCUMENTATION-INDEX.md) (Google Docs and repo documents) |
| Developers | [docs/DEVELOPER-GUIDE.md](docs/DEVELOPER-GUIDE.md) |
| Deployment | [docs/07-installation/RENDER-SUPABASE-DEPLOYMENT.md](docs/07-installation/RENDER-SUPABASE-DEPLOYMENT.md) (Render + Supabase) |
| Security controls | [docs/05-governance/SECURITY-CONTROLS.md](docs/05-governance/SECURITY-CONTROLS.md) |
| Release history | [docs/10-migration-history/](docs/10-migration-history/) |

## Run locally

Requirements: PHP 8.2+ (8.3 recommended), Composer 2, Node.js 22, PostgreSQL 15+.

```bash
cp .env.example .env          # set DB_* for your local PostgreSQL database
composer install
npm install
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve             # http://127.0.0.1:8000
```

Use `npm run dev` in a second terminal while changing the front end.

Development accounts (password `password`, local only):

| Account | Role |
|---|---|
| worker@kalinga.test | Case manager |
| supervisor@kalinga.test | LGU Head (supervisor) |
| pm@kalinga.test | Project Manager (national) |
| fo@kalinga.test | Field Office (CAR) |
| cpu@kalinga.test | CPU member |
| admin@kalinga.test | System administrator |

In local development the six-digit sign-in code is shown on the page. Never
copy these accounts, demo data or `.env` credentials to a server.

## Check before every release

```bash
composer test          # PHP tests on SQLite
composer test:pgsql    # PHP tests on PostgreSQL (database kalinga_test)
npm run test:frontend  # front-end unit tests
npx playwright test -c playwright.review.config.ts   # browser tests
composer lint          # Pint code style
composer security      # scripts/security-check.sh
```

## Rules for contributors

Read [AGENTS.md](AGENTS.md). In short: privacy by design, no invented policy
(flag it for owner review), plain ASCII hyphens in text, and no push or
deployment without the owner's authorization.

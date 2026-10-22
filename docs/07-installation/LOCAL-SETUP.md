# Local setup

Prerequisites: PHP 8.2+ (8.3 recommended), Composer 2, Node 22, npm and PostgreSQL 15+. See also ../DEVELOPER-GUIDE.md.

1. Copy `.env.example` to `.env` if needed, set `APP_KEY` with `php artisan key:generate`, and configure `DB_CONNECTION=pgsql`. Use the dedicated `kalinga_is` database, not `kalinga` or `kalinga_test`.
2. Run `composer install`, `npm install --cache .npm-cache`, `php artisan migrate --seed`, and `npm run build`.
3. Start Laravel with `php artisan serve`, then open `http://127.0.0.1:8000`. During development run `npm run dev` in a second terminal.
4. Verify with `php artisan test` and `npm run build`.

## Browser and responsive tests

Run `npx playwright test -c playwright.review.config.ts` for the full review suite (240 tests). The suite rebuilds the ignored `database/e2e.sqlite` database from migrations, and seeds only fictional development records. It never uses the configured PostgreSQL database unless `PLAYWRIGHT_BASE_URL` is explicitly supplied. Failure screenshots, videos, traces, and the HTML report are written to ignored test artifact directories.

Use `npm run test:e2e:headed` for supervised visual review and `npm run test:e2e:report` to inspect the last HTML report.


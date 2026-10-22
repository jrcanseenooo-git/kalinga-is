# Release 3 - Bug fixes, refactoring, UI/UX polish and documentation (October 2026)

Builds on Release 1 (reports) and Release 2 (security and privacy). No new
database migration in this release; run `php artisan migrate --force` only if
Release 2 was not yet migrated.

## Install

1. Copy the files of this package over the project (same folder layout).
2. Delete the files listed in `REMOVED-FILES-RELEASE-3.txt`.
3. Run `composer dump-autoload`, `npm install`, `npm run build`, `php artisan optimize:clear`.
4. Run the checks in the README ("Check before every release").

## Bugs fixed

| Area | Problem | Fix |
|---|---|---|
| Dates (whole system) | Stored times are UTC, but many screens read them as the device's local time. Late-evening activities showed on the wrong day (for example 8:30 PM on Sep 19 instead of 4:30 AM on Sep 20). | One helper, `utils/philippineTime.ts` (`formatPh`, `manilaDayKey`, `ageFromBirthdate`), used by every screen and report. Regression test added. |
| Offline sync (PostgreSQL) | A non-numeric case id in a sync item caused a server error. | Id validated before the query. Found by running the test suite on PostgreSQL. |
| Tests on PostgreSQL | 23 tests built SQLite-only triggers, so the suite could not run on the production database. | `tests/Support/SyntheticTrigger.php`; the full suite now passes on SQLite and PostgreSQL. |
| MDT access expiry | Timestamps rounded up on PostgreSQL, so an access that had just expired looked active for under a second. | Times truncated to the second. |
| Dashboard | "My cases requiring action" squeezed a table into the side column on laptops; case numbers wrapped letter by letter. | Card layout in the side column. |
| In-app help | The report guide described two reports and old buttons. | Updated for the five reports and the Reports menu. |

## Refactoring and clean-up

- Duplicate date formatting in 20 files replaced by the shared helper.
- 10 unused components and the unused `storeReport` method removed (list in `REMOVED-FILES-RELEASE-3.txt`).
- Code style: `pint.json` (Laravel preset); all PHP formatted; `composer lint` checks it.
- Composer scripts: `test`, `test:pgsql`, `lint`, `format`, `security`.
- Root folder tidied: release notes moved to `docs/10-migration-history/2026-10/`, the old handoff note to `docs/00-project-plan/`.
- Test helpers `assertAuditChanges()` and `assertAuditNeverContains()` in `tests/TestCase.php`.

## UI/UX

- Confirmation dialog: names the action ("Finalize note?"), uses the form's own wording when set, red for destructive actions, "Go back" to cancel. Bottom sheet on phones.
- All dialogs open as bottom sheets on phones and centred on larger screens.
- Loading: thin purple progress bar; placeholder layout after 350 ms on slow connections; boot screen while the app starts.
- Error pages in the Kalinga style for 401, 403, 404, 419, 429, 500 and 503, with plain wording.
- Pagination shows "Page x of y" on phones with accessible labels.
- Page titles and labels in sentence case ("Submit for review", "Child participation", "LGU registry", "Records governance").
- Colour contrast raised on 22 screens; tap targets at least 44 px on admin filters and analytics links; no sideways scrolling at 360 px.

## Documentation

- Google Docs: System Documentation (ICTMS format), User Manual, Quick User Guide, QA/VA/PIA Support Pack; Google Sheet: Data Dictionary. Links in `docs/DOCUMENTATION-INDEX.md`.
- Repo: README, `docs/DEVELOPER-GUIDE.md`, diagrams (`docs/diagrams`: context, process flow, ERD), data dictionary (`docs/04-database/DATA-DICTIONARY.md`, `data-dictionary.csv`) and its generator `scripts/generate_data_dictionary.py`.

## Owner review items carried forward

- Hosting region is Singapore in `render.yaml`; confirm against DSWD/DICT cloud policy and cross-border rules.
- No Central Office role exists; the national overview is admin-only.
- CPU members require two-factor sign-in; session idle time is 120 minutes.
- Report content withheld for CPU and Central Office copies; conforme wording.
- `composer audit` must be run where Packagist is reachable.

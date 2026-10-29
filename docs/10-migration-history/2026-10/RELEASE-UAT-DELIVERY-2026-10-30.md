# UAT credential delivery recovery - 2026-10-30

The live 43-account creation committed after approximately 68 seconds, but Nginx returned 504 after 60 seconds. The browser never received the original one-time credential response. The accounts exist; the original passwords cannot be recovered.

- Raise FastCGI read timeout to 180 seconds while retaining normal password hashing.
- Commit an APP_KEY-encrypted, creating-administrator-scoped retry export in the existing database cache table, in the same transaction as creation or recovery. Available for at most 30 minutes and never beyond account expiry. No schema migration.
- Authenticated downloads are no-store attachments. Page props contain only availability, never passwords. Acknowledgement removes the copy; expiry blocks reads independently of the scheduled minute-by-minute purge. Cache clearing removes the retry export.
- Explicit recovery can replace the original creator's entire fixed roster only once, while all 43 accounts are unchanged, active, unexpired and have no recorded sign-in. Recovery preserves roles, locations, memberships and expiry, and audits only account keys. Partial, other-owner, used or previously-reissued rosters are refused.
- The owner must submit password recovery themselves under browser-control handoff rules. No live recovery was performed during implementation.

Validation: final SQLite suite 515 passed, two skipped (517 total, 5010 assertions); PostgreSQL UAT suite 12 passed (343 assertions); frontend 46 passed; production TypeScript/Vite build passed; dedicated browser creation, identical retry download, server-copy removal and displayed-code worker login passed. Changed PHP files passed Pint. Existing broad browser regression limitations from the original UAT release remain separate.

# Administrator operations

## Access

Only `system_admin` and `cefm_admin` users can use the governance centre. Role changes are recorded in `audit_logs`. Administrators should not use case-worker accounts for access administration.

## Backup

Back up only the isolated database: `pg_dump -h 127.0.0.1 -U postgres -Fc -f kalinga_is.backup kalinga_is`. Restore to a non-production validation database first with `pg_restore -h 127.0.0.1 -U postgres -d kalinga_is_restore --clean kalinga_is.backup`.

Do not run destructive commands against `kalinga` or `kalinga_test`; they are unrelated databases. Permanent production attachments must use private object storage and be backed up under the same retention policy as the database.

## Deployment

The Render release process, database access controls, object-storage requirements, initial-admin provisioning, and post-deploy validation are documented in [RENDER-DEPLOYMENT.md](../07-installation/RENDER-DEPLOYMENT.md). Production startup seeds only roles/permissions; it never loads the local demo accounts or fictional case data.

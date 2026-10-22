# Deployment runbook - Render (application) and Supabase (PostgreSQL)

Kalinga IS runs on Render as a Docker web service plus a cron service. All
system data is stored in, and read from, a Supabase PostgreSQL database.
Case documents go to a private S3-compatible bucket (Supabase Storage works).
Users reach the system online through the Render HTTPS address.

```
Browser / phone --HTTPS--> Render web service (Laravel, Singapore)
                              |  TLS, session pooler, port 5432
                              v
                           Supabase PostgreSQL (Singapore)  <-- all records
Render cron (every 5 min) ----^        Supabase Storage (private bucket) <-- documents
```

Do not deploy without the owner's authorization (AGENTS.md). Use fictional
data until the PIA, VA and QA are complete.

## Why these settings

| Setting | Reason |
|---|---|
| Session pooler, port 5432 | Render has no outbound IPv6. The Supabase direct connection is IPv6 unless the paid IPv4 add-on is bought; the shared pooler is IPv4 and supports everything Laravel needs (prepared statements, migrations, triggers). |
| `DB_SSLMODE=require` | The connection is always encrypted. For certificate checking use `verify-full` with the Supabase root certificate in `DB_SSLROOTCERT`. |
| Both services in Singapore | Supabase has no Philippine region; Singapore (ap-southeast-1) is the nearest. Every page makes several database queries, so the app must sit next to the database. OWNER REVIEW: hosting location and cross-border rules. |
| Data API off and `kalinga:lock-down-database-api` | Supabase can publish the `public` schema through a REST API keyed by a public key. Kalinga never uses it. The API is switched off, and at every start the app revokes the API roles' grants and turns on row level security on every table (the app is the table owner, so it is not affected). |

## Part A - Supabase

1. Create a Supabase organization owned by the office (not a personal account). Turn on two-factor sign-in for every member and add only the people who administer the system.
2. Create a project:
   - Region: **Southeast Asia (Singapore)**.
   - Database password: long and random; keep it in the office password manager.
   - Plan: a paid plan for the pilot. Free projects are paused after 7 days of low activity, and backup options depend on the plan. Confirm backups and point-in-time recovery on the Supabase pricing page.
3. Turn off the Data API: Project Settings (or Integrations) > **Data API** > switch off **Enable Data API**, then save.
4. Database settings > SSL configuration: turn on **Enforce SSL on incoming connections**.
5. Get the connection string: select **Connect** at the top of the project > **Session pooler**. It looks like
   `postgresql://postgres.<project-ref>:[YOUR-PASSWORD]@aws-<n>-ap-southeast-1.pooler.supabase.com:5432/postgres`
   Copy the host exactly as shown (do not type it). Replace `[YOUR-PASSWORD]` with the password; if the password has symbols such as `@ : / ? # %`, URL-encode them (for example `@` becomes `%40`).
6. Documents bucket (if you use Supabase Storage):
   - Storage > **New bucket** `kalinga-documents`, **Public bucket off**. Add no storage policies.
   - Storage > Settings > **S3 connection**: note the endpoint (`https://<project-ref>.storage.supabase.co/storage/v1/s3`) and region, then **New access key**. Keep the secret key in the password manager.
7. Optional hardening once Render is running: Database settings > **Network restrictions**: allow only Render's outbound addresses for the Singapore region (listed on the Render service's **Connect > Outbound** tab) and the office address used for administration.

## Part B - Render

1. Put the project in a private GitHub repository controlled by the office. Never commit `.env`.
2. Render > **New > Blueprint** > choose the repository. It creates `kalinga-is` (web) and `kalinga-is-scheduler` (cron), both in Singapore. Use a paid instance type for the web service: free web services sleep when idle and take about a minute to wake. Cron jobs are a paid service type.
3. Enter the secret values when asked:

| Variable | Value |
|---|---|
| `DB_URL` | The session pooler string from step A5 |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | Storage access key from step A6 |
| `AWS_DEFAULT_REGION` | The region shown with the S3 endpoint (for example `ap-southeast-1`) |
| `AWS_BUCKET` | `kalinga-documents` |
| `AWS_ENDPOINT` | `https://<project-ref>.storage.supabase.co/storage/v1/s3` |
| `MAIL_*` | The approved mail service (needed for sign-in codes by email and password resets) |
| `PROVISION_ADMIN_NAME`, `PROVISION_ADMIN_EMAIL`, `PROVISION_ADMIN_PASSWORD` | The first system administrator |
| `APP_URL` | After the first deploy: the `https://...onrender.com` address (or the office domain), then redeploy |

`DB_SSLMODE=require`, `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT`, `TRUSTED_PROXIES` and the other settings come from `render.yaml`. The cron service reads `DB_URL` and the storage keys from the web service.

4. Deploy. On every start the container runs, in order: `migrate --force` (creates or updates the tables in Supabase, including the append-only audit trigger), `kalinga:lock-down-database-api`, the reference-data seeder (roles, permissions, lists; no personal data), and `kalinga:provision-initial-admin` (only if that email does not exist yet).
5. Sign in as the administrator, set up the second factor, change the password, then delete `PROVISION_ADMIN_PASSWORD` from Render.

## Part C - Check that everything is stored in Supabase

1. Render: the service shows **Live** and `/up` is healthy. The deploy log shows `Row level security enabled on ... tables; API grants revoked for anon, authenticated.`
2. Supabase > Table editor: the Kalinga tables are there and each shows RLS enabled.
3. In Kalinga, create a test case with fictional data. In Supabase > Table editor > `cases`, the row appears. Delete nothing in Supabase directly.
4. Supabase > SQL editor: `update audit_logs set event = 'x' where id = (select min(id) from audit_logs);` must fail with "audit_logs is append-only".
5. Upload a test document; within five minutes the cron scan moves it from pending to cleared, and the file appears in the private bucket.
6. Open `https://<project-ref>.supabase.co/rest/v1/cases` in a browser: it must not return data.

## Part D - Running it

- **Connections:** the web container runs up to 5 PHP workers (PHP-FPM default), so it holds at most about 5 database connections plus the cron. This fits the smallest Supabase pool. If you raise PHP workers, check the pool size in Supabase > Database settings.
- **Transaction pooler (port 6543)** is only needed for many more connections. If you switch, set `DB_EMULATE_PREPARES=true` and keep migrations on the session pooler.
- **Changes in Supabase directly** (Table editor, SQL editor) skip Kalinga's checks and audit trail. Use them only for approved administration, never to edit case records.
- **Backups:** confirm the plan's daily backups, and test a restore into a separate project before the pilot starts.
- **Migrating local data:** do not copy the local demo database. Production starts empty apart from the reference data and the first administrator.
- **Keys:** if the database password or a storage key is exposed, reset it in Supabase and update the Render variable, then redeploy.

# CSV migration mapping

| Legacy export | Required source fields | New PostgreSQL destination | Notes |
|---|---|---|---|
| `clients.csv` | `first_name`, `last_name` | `clients.first_name`, `clients.last_name` | Match duplicates by normalized name plus birthdate where available. |
| `reports.csv` | `reference_no`, `narrative`, `priority` | `reports.reference_no`, `reports.narrative`, `reports.priority` | Preserve legacy reference number when it is unique; map legacy statuses through an approved status table. |
| `cases.csv` | `case_number`, `status`, `priority` | `cases.case_number`, `cases.status`, `cases.priority` | Create a client/report foreign-key mapping first; do not import a case without a valid client link. |

The first import must be a dry-run against a restored copy or a dedicated validation database. Record rejects, duplicate resolutions, and count reconciliation in the migration history before production cutover.

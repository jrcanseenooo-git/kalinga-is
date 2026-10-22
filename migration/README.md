# Legacy migration workspace

The legacy Kalinga application and its database are reference-only. This folder contains mapping and validation material for a future, explicitly approved import; no command here connects to or modifies the legacy system.

## Safe workflow

1. Export copies of legacy `clients`, `reports`, and `cases` to a new working directory outside the legacy application.
2. Review [the CSV mapping](mappings/legacy-csv-mapping.md) and remove any columns that should not be migrated.
3. Run the dry-run validator:

```powershell
php artisan kalinga:validate-legacy-csv C:\path\to\approved-export
```

4. Resolve header or quality errors, document transformation decisions, then request approval before building/running a write import.

The validator only reads the copied CSV files and never writes to PostgreSQL.

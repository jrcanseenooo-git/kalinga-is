<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Closes the hosted database's own public API to the case tables.
 *
 * Supabase publishes the "public" schema through its Data API, and new tables
 * there can be granted to the "anon" and "authenticated" roles, whose key is
 * public. Kalinga never uses that API: every read and write goes through the
 * Laravel application. This command, run after each migration, removes those
 * grants and turns on row level security for every table, so the tables are
 * unreachable through the Data API even if it is switched on by mistake.
 *
 * The application connects as the table owner, which is not subject to row
 * level security, so the application itself is unaffected. On a database
 * without the Supabase roles (local PostgreSQL, Render PostgreSQL) only row
 * level security is enabled. Safe to run repeatedly.
 */
class LockDownDatabaseApi extends Command
{
    protected $signature = 'kalinga:lock-down-database-api';

    protected $description = 'Revokes Supabase anon/authenticated access to the public schema and enables row level security on every table.';

    private const API_ROLES = ['anon', 'authenticated'];

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->info('Not PostgreSQL; nothing to lock down.');

            return self::SUCCESS;
        }

        $tables = collect(DB::select("select tablename from pg_tables where schemaname = 'public'"))->pluck('tablename');
        foreach ($tables as $table) {
            DB::statement('alter table public.' . $this->quote($table) . ' enable row level security');
        }

        $roles = collect(DB::select('select rolname from pg_roles where rolname = any(?)', ['{' . implode(',', self::API_ROLES) . '}']))->pluck('rolname');
        foreach ($roles as $role) {
            $quoted = $this->quote($role);
            DB::statement("revoke all on all tables in schema public from {$quoted}");
            DB::statement("revoke all on all sequences in schema public from {$quoted}");
            DB::statement("revoke all on all functions in schema public from {$quoted}");
            DB::statement("alter default privileges in schema public revoke all on tables from {$quoted}");
            DB::statement("alter default privileges in schema public revoke all on sequences from {$quoted}");
            DB::statement("alter default privileges in schema public revoke all on functions from {$quoted}");
        }

        $this->info(sprintf('Row level security enabled on %d tables; API grants revoked for %s.', $tables->count(), $roles->isEmpty() ? 'no API roles (not Supabase)' : $roles->implode(', ')));

        return self::SUCCESS;
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}

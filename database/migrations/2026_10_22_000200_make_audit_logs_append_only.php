<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit trail is append-only at the database level (PostgreSQL).
 *
 * Rows cannot be changed or deleted, and the table cannot be truncated,
 * even by someone with direct database access through the application
 * account. The one permitted change is the foreign key clearing user_id when
 * an account is deleted (ON DELETE SET NULL); every other column must stay
 * the same. Closes the "audit tampering" residual risk in the PIA.
 * SQLite (local tests) is skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION kalinga_audit_logs_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'UPDATE'
       AND NEW.user_id IS NULL
       AND (to_jsonb(NEW) - 'user_id') = (to_jsonb(OLD) - 'user_id') THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'audit_logs is append-only (% refused)', TG_OP USING ERRCODE = 'insufficient_privilege';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs;
CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs
    FOR EACH ROW EXECUTE FUNCTION kalinga_audit_logs_guard();

DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;
CREATE TRIGGER audit_logs_no_truncate BEFORE TRUNCATE ON audit_logs
    FOR EACH STATEMENT EXECUTE FUNCTION kalinga_audit_logs_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs; DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs; DROP FUNCTION IF EXISTS kalinga_audit_logs_guard();');
    }
};

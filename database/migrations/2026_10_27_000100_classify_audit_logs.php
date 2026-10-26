<?php

use App\Support\AuditCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log fields for the System Audit Logs page: log type (14 types),
 * module, action, record reference, description, old and new value and the
 * user's role at the time.
 *
 * Existing rows get the classification derived from their event. On
 * PostgreSQL the append-only trigger is switched off for this one backfill
 * (only the new, empty columns are filled; no existing value changes) and
 * switched back on in the same transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('log_type', 40)->nullable()->after('event');
            $table->string('module', 60)->nullable()->after('log_type');
            $table->string('action', 20)->nullable()->after('module');
            $table->string('record_ref', 80)->nullable()->after('auditable_id');
            $table->string('description', 255)->nullable()->after('record_ref');
            $table->string('old_value', 255)->nullable()->after('description');
            $table->string('new_value', 255)->nullable()->after('old_value');
            $table->string('user_role', 40)->nullable()->after('user_id');
            $table->index('created_at');
            $table->index(['log_type', 'created_at']);
            $table->index(['module', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('record_ref');
        });

        $pgsql = DB::getDriverName() === 'pgsql';
        $guarded = $pgsql && DB::selectOne("select 1 as present from pg_trigger where tgname = 'audit_logs_append_only'");

        DB::transaction(function () use ($guarded) {
            if ($guarded) {
                DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_append_only');
            }
            $roles = DB::table('users')->pluck('role', 'id');
            DB::table('audit_logs')->orderBy('id')->chunkById(500, function ($rows) use ($roles) {
                $caseIds = $rows->where('auditable_type', 'case')->pluck('auditable_id')
                    ->merge($rows->map(fn ($row) => json_decode($row->changes ?? '', true)['case_id'] ?? null))->filter()->unique();
                $numbers = $caseIds->isEmpty() ? collect() : DB::table('cases')->whereIn('id', $caseIds->all())->pluck('case_number', 'id');
                foreach ($rows as $row) {
                    $class = AuditCatalogue::classify($row->event);
                    $changes = json_decode($row->changes ?? '', true) ?: [];
                    $caseId = $row->auditable_type === 'case' ? $row->auditable_id : ($changes['case_id'] ?? null);
                    DB::table('audit_logs')->where('id', $row->id)->update([
                        'log_type' => $class['type'], 'module' => $class['module'], 'action' => $class['action'],
                        'description' => $class['description'],
                        'record_ref' => $caseId && isset($numbers[$caseId]) ? $numbers[$caseId] : ($row->auditable_id ? $row->auditable_type . '#' . $row->auditable_id : null),
                        'old_value' => isset($changes['from']) && is_scalar($changes['from']) ? mb_substr((string) $changes['from'], 0, 255) : null,
                        'new_value' => isset($changes['to']) && is_scalar($changes['to']) ? mb_substr((string) $changes['to'], 0, 255) : null,
                        'user_role' => $row->user_id ? ($roles[$row->user_id] ?? null) : null,
                    ]);
                }
            });
            if ($guarded) {
                DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_append_only');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['log_type', 'created_at']);
            $table->dropIndex(['module', 'created_at']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['record_ref']);
            $table->dropColumn(['log_type', 'module', 'action', 'record_ref', 'description', 'old_value', 'new_value', 'user_role']);
        });
    }
};

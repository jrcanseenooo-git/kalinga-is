<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Single entry point for writing the audit trail (audit_logs).
 *
 * insert() accepts the same row array (or list of rows) as
 * DB::table('audit_logs')->insert() and adds what callers do not supply:
 * the client IP address and timestamps. record() is the short form for new
 * code. Audit rows must never contain names, narratives or other case
 * content; store ids and codes only.
 */
final class AuditLog
{
    /** @param array<string, mixed>|list<array<string, mixed>> $rows */
    public static function insert(array $rows): bool
    {
        $list = array_is_list($rows) ? $rows : [$rows];
        $ip = app()->runningInConsole() && !app()->runningUnitTests() ? null : request()->ip();
        $now = now();
        $list = array_map(fn (array $row) => $row + ['ip_address' => $ip, 'created_at' => $now, 'updated_at' => $now], $list);

        return DB::table('audit_logs')->insert($list);
    }

    /** @param array<string, mixed> $changes */
    public static function record(string $event, string $type, ?int $id = null, array $changes = [], ?int $userId = null, string $outcome = 'success'): void
    {
        self::insert(['user_id' => $userId ?? auth()->id(), 'event' => $event, 'auditable_type' => $type, 'auditable_id' => $id,
            'changes' => json_encode($changes), 'outcome' => $outcome]);
    }
}

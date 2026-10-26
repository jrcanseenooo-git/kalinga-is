<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Single entry point for writing the audit trail (audit_logs).
 *
 * insert() accepts the same row array (or list of rows) as
 * DB::table('audit_logs')->insert() and adds what callers do not supply:
 * the client IP address, timestamps, the user's role, and the log type,
 * module, action, description, record reference and old/new value derived
 * from the event (see AuditCatalogue). record() is the short form for new
 * code.
 *
 * Audit rows must never contain names, narratives or other case content;
 * store ids and codes only. Passwords, tokens, codes and secrets are removed
 * from the changes before they are written.
 */
final class AuditLog
{
    private const SECRET_KEYS = '/pass(word)?|token|secret|otp|code_hash|recovery|api_key|remember|cookie|session|authorization/i';

    /** @param array<string, mixed>|list<array<string, mixed>> $rows */
    public static function insert(array $rows): bool
    {
        $list = array_is_list($rows) ? $rows : [$rows];
        $ip = app()->runningInConsole() && !app()->runningUnitTests() ? null : request()->ip();
        $now = now();
        $list = array_map(fn (array $row) => self::complete($row) + ['ip_address' => $ip, 'created_at' => $now, 'updated_at' => $now], $list);

        return DB::table('audit_logs')->insert($list);
    }

    /** @param array<string, mixed> $changes */
    public static function record(string $event, string $type, ?int $id = null, array $changes = [], ?int $userId = null, string $outcome = 'success', array $extra = []): void
    {
        self::insert(['user_id' => $userId ?? auth()->id(), 'event' => $event, 'auditable_type' => $type, 'auditable_id' => $id,
            'changes' => json_encode($changes), 'outcome' => $outcome] + $extra);
    }

    /** Never lets an audit failure break the request that is being audited (errors, denials). */
    public static function safely(string $event, string $type, ?int $id = null, array $changes = [], ?int $userId = null, string $outcome = 'success', array $extra = []): void
    {
        try {
            self::record($event, $type, $id, $changes, $userId, $outcome, $extra);
        } catch (\Throwable) {
            // The audit table may be what failed; nothing more can be done here.
        }
    }

    /** @param array<string, mixed> $row */
    private static function complete(array $row): array
    {
        $changes = is_string($row['changes'] ?? null) ? (json_decode($row['changes'], true) ?? []) : ($row['changes'] ?? []);
        $changes = is_array($changes) ? self::scrub($changes) : [];
        $row['changes'] = json_encode($changes);

        $class = AuditCatalogue::classify((string) $row['event']);
        $row['log_type'] ??= $class['type'];
        $row['module'] ??= $class['module'];
        $row['action'] ??= $class['action'];
        $row['description'] ??= $class['description'];
        $row['description'] = mb_substr((string) $row['description'], 0, 255);
        $row['outcome'] ??= 'success';

        if (!array_key_exists('user_role', $row)) {
            $userId = $row['user_id'] ?? null;
            $row['user_role'] = $userId === null ? null
                : ((auth()->check() && auth()->id() === $userId) ? auth()->user()->role : DB::table('users')->where('id', $userId)->value('role'));
        }
        if (!array_key_exists('record_ref', $row)) {
            $row['record_ref'] = self::recordRef($row['auditable_type'] ?? null, $row['auditable_id'] ?? null, $changes);
        }
        foreach (['old_value' => ['from', 'old', 'previous'], 'new_value' => ['to', 'new', 'status']] as $column => $keys) {
            if (array_key_exists($column, $row)) {
                $row[$column] = $row[$column] === null ? null : mb_substr((string) $row[$column], 0, 255);

                continue;
            }
            $row[$column] = null;
            foreach ($keys as $key) {
                if (isset($changes[$key]) && is_scalar($changes[$key])) {
                    $row[$column] = mb_substr((string) $changes[$key], 0, 255);
                    break;
                }
            }
        }
        // "status" alone is a new value only when there is also an old one.
        if ($row['old_value'] === null && !isset($changes['to']) && !isset($changes['new'])) {
            $row['new_value'] = null;
        }

        return $row;
    }

    private static function recordRef(?string $type, mixed $id, array $changes): ?string
    {
        $caseId = $type === 'case' ? $id : ($changes['case_id'] ?? null);
        if ($caseId && is_numeric($caseId) && ($number = DB::table('cases')->where('id', $caseId)->value('case_number'))) {
            return $number;
        }

        return $id ? $type . '#' . $id : null;
    }

    /** Removes passwords, tokens and similar values at any depth. */
    private static function scrub(array $changes): array
    {
        foreach ($changes as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEYS, $key)) {
                unset($changes[$key]);
            } elseif (is_array($value)) {
                $changes[$key] = self::scrub($value);
            }
        }

        return $changes;
    }
}

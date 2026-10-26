<?php

namespace App\Services;

use App\Support\AuditLog;
use App\Support\ClientName;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Saved intakes (encrypted, owner only). See the intake_drafts migration.
 */
final class IntakeDrafts
{
    /** Days a saved intake is kept without changes. OWNER REVIEW: retention period. */
    public const KEEP_DAYS = 30;

    public static function save(string $id, int $userId, ?int $lguId, int $step, array $payload): object
    {
        $existing = DB::table('intake_drafts')->where('id', $id)->first();
        abort_if($existing && (int) $existing->user_id !== $userId, 404);
        $row = [
            'user_id' => $userId, 'lgu_id' => $lguId, 'step' => max(1, min(4, $step)),
            'label' => mb_substr(ClientName::masked($payload['first_name'] ?? null, $payload['middle_name'] ?? null, $payload['last_name'] ?? null), 0, 80),
            'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            'expires_at' => now()->addDays(self::KEEP_DAYS), 'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('intake_drafts')->where('id', $id)->update($row);
        } else {
            DB::table('intake_drafts')->insert($row + ['id' => $id, 'created_at' => now()]);
            AuditLog::record('intake_draft.created', 'intake_draft', null, [], $userId, 'success', ['record_ref' => 'draft:' . substr($id, 0, 8), 'description' => 'Intake saved to continue later']);
        }

        return DB::table('intake_drafts')->where('id', $id)->first(['id', 'step', 'label', 'expires_at', 'updated_at']);
    }

    /** @return array{id: string, step: int, payload: array<string, mixed>}|null */
    public static function open(string $id, int $userId): ?array
    {
        $row = DB::table('intake_drafts')->where('id', $id)->where('user_id', $userId)->where('expires_at', '>', now())->first();
        if (!$row) {
            return null;
        }

        return ['id' => $row->id, 'step' => (int) $row->step, 'payload' => json_decode(Crypt::decryptString($row->payload), true) ?: []];
    }

    /** @return list<object> the user's saved intakes, newest first, without answers */
    public static function list(int $userId): array
    {
        return DB::table('intake_drafts')->where('user_id', $userId)->where('expires_at', '>', now())->orderByDesc('updated_at')
            ->get(['id', 'label', 'step', 'updated_at', 'expires_at'])->all();
    }

    public static function discard(string $id, int $userId): bool
    {
        $deleted = DB::table('intake_drafts')->where('id', $id)->where('user_id', $userId)->delete() > 0;
        if ($deleted) {
            AuditLog::record('intake_draft.discarded', 'intake_draft', null, [], $userId, 'success', ['record_ref' => 'draft:' . substr($id, 0, 8), 'description' => 'Saved intake discarded']);
        }

        return $deleted;
    }

    /** The case folder was created from this saved intake: the saved copy is no longer needed. */
    public static function finish(string $id, int $userId, int $caseId): void
    {
        if (DB::table('intake_drafts')->where('id', $id)->where('user_id', $userId)->delete() > 0) {
            AuditLog::record('intake_draft.completed', 'case', $caseId, ['case_id' => $caseId], $userId, 'success', ['description' => 'Saved intake completed; case folder created']);
        }
    }

    public static function purgeExpired(): int
    {
        $count = DB::table('intake_drafts')->where('expires_at', '<=', now())->delete();
        if ($count > 0) {
            AuditLog::record('intake_draft.expired', 'intake_draft', null, ['count' => $count], null, 'success', ['description' => "{$count} saved intake(s) deleted after " . self::KEEP_DAYS . ' days without changes']);
        }

        return $count;
    }
}

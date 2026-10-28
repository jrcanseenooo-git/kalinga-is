<?php

namespace App\Services;

use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Automatic case status (owner decision 2026-10-28). The status follows the
 * work the case manager records, so nobody moves it by hand:
 *
 * - Open: nothing recorded yet after intake.
 * - In progress: work is recorded (KaVAT, a Kalinga form, a note, a visit, a
 *   service, a grant or a referral).
 * - Awaiting referral: a referral was sent and not answered yet.
 * - For follow-up: every referral has an answer but the Intervention Plan
 *   still has rows that are not done.
 *
 * Closure stays a deliberate step: "For closure review", "Closed" and
 * "Reopened" are set only by the closure workflow and are never changed here.
 * A reopened case returns to the automatic statuses as soon as work is
 * recorded again.
 */
final class CaseStatusAutomation
{
    /** Statuses this service may set or leave. */
    public const AUTOMATIC = ['OPEN', 'IN_PROGRESS', 'AWAITING_REFERRAL', 'FOR_FOLLOW_UP', 'REOPENED'];

    /** Referral statuses that mean "sent, waiting for the receiving office". */
    private const WAITING = ['PENDING', 'SENT'];

    /** Referral statuses that are an answer from the receiving office. */
    private const ANSWERED = ['ACCEPTED', 'IN_PROGRESS', 'COMPLETED', 'DECLINED', 'RETURNED', 'UNAVAILABLE'];

    /** @return array{status: string, reason: string} */
    public static function evaluate(int $caseId, string $current): array
    {
        $exists = fn (string $table) => DB::table($table)->where('case_id', $caseId)->exists();
        $hasWork = $exists('kavat_assessments') || $exists('kavat_baselines') || $exists('case_notes') || $exists('monitoring_visits')
            || $exists('services_provided') || $exists('case_grants') || $exists('referrals')
            || DB::table('case_forms')->where('case_id', $caseId)->exists();
        if (!$hasWork) {
            // Never back to Open once the case has moved on (for example a returned closure).
            return in_array($current, ['OPEN', 'REOPENED'], true) ? ['status' => $current, 'reason' => 'No case work recorded yet.'] : ['status' => $current, 'reason' => 'Case work is being recorded.'];
        }
        $referrals = DB::table('referrals')->where('case_id', $caseId)->pluck('status');
        if ($referrals->contains(fn ($status) => in_array($status, self::WAITING, true))) {
            return ['status' => 'AWAITING_REFERRAL', 'reason' => 'A referral is waiting for an answer.'];
        }
        if ($referrals->contains(fn ($status) => in_array($status, self::ANSWERED, true)) && self::planHasOpenRows($caseId)) {
            return ['status' => 'FOR_FOLLOW_UP', 'reason' => 'Referrals are answered; Intervention Plan items are still open.'];
        }

        return ['status' => 'IN_PROGRESS', 'reason' => 'Case work is being recorded.'];
    }

    /** Rows of the Intervention Plan that are not marked done. */
    public static function planHasOpenRows(int $caseId): bool
    {
        $content = DB::table('case_forms')->where('case_id', $caseId)->where('form_type', 'plan')->value('content');
        $rows = (array) ((is_string($content) ? json_decode($content, true) : (array) $content)['rows'] ?? []);
        foreach ($rows as $row) {
            $filled = collect((array) $row)->except(['status', 'id', 'target_start', 'target_end'])->filter(fn ($value) => is_string($value) && trim($value) !== '')->isNotEmpty();
            if ($filled && (($row['status'] ?? 'not_started') !== 'done')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bring the case status in line with the recorded work. Returns the new
     * status when it changed, otherwise null.
     */
    public static function sync(int $caseId, ?int $actorId = null): ?string
    {
        return DB::transaction(function () use ($caseId, $actorId) {
            $case = DB::table('cases')->where('id', $caseId)->whereNull('deleted_at')->lockForUpdate()->first(['id', 'status', 'assigned_to']);
            if (!$case || !in_array($case->status, self::AUTOMATIC, true)) {
                return null;
            }
            ['status' => $status, 'reason' => $reason] = self::evaluate($caseId, $case->status);
            if ($status === $case->status) {
                return null;
            }
            $by = $actorId ?? $case->assigned_to;
            // The offline version is not changed: a status the system derives must not turn
            // the next field-mode sync into a conflict.
            DB::table('cases')->where('id', $caseId)->update(['status' => $status, 'stage' => strtolower(str_replace('_', ' ', $status)), 'updated_at' => now()]);
            if ($by) {
                DB::table('case_status_history')->insert(['case_id' => $caseId, 'from_status' => $case->status, 'to_status' => $status, 'reason' => 'Updated automatically: ' . $reason, 'changed_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
            }
            AuditLog::record('case.status_automatic', 'case', $caseId, ['from' => $case->status, 'to' => $status], $by, 'success',
                ['description' => 'Case status updated automatically: ' . $reason, 'old_value' => $case->status, 'new_value' => $status]);

            return $status;
        }, 3);
    }
}

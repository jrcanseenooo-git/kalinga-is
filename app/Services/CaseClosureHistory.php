<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

/** Call mutations only while holding the parent case row in a transaction. */
class CaseClosureHistory
{
    private const CURRENT_SLOT = 1;

    public function current(int $case): ?object
    {
        return DB::table('case_closure_records')->where('case_id', $case)->where('current_slot', self::CURRENT_SLOT)->first();
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Closure history mutations require a case transaction.');
        }
    }

    public function captureSurvivingLegacy(object $case, User $actor, bool $unattributedProjection = false): ?object
    {
        $this->assertTransaction();
        $current = $this->current((int) $case->id);
        if ($current) {
            abort_unless($current->summary === $case->closure_summary, 409, 'Closure content differs from its current history. Record an authorized revision before reviewing, closing or reopening it.');

            return $current;
        }
        $hasPriorHistory = DB::table('case_closure_records')->where('case_id', $case->id)->exists();
        $hasSurvivingEvidence = !empty($case->closure_summary) || !empty($case->closure_reviewed_by) || !empty($case->closure_reviewed_at)
            || (!$hasPriorHistory && !empty($case->closed_at));
        if (!$hasSurvivingEvidence) {
            return null;
        }
        $author = $unattributedProjection ? null : DB::table('audit_logs')->where('event', 'case.closure_recorded')->where('auditable_type', 'case')->where('auditable_id', $case->id)->latest('created_at')->orderByDesc('id')->value('user_id');
        $id = $this->insert($case, $actor, $case->closure_summary, 'legacy_case_summary', $author ? (int) $author : null, null, $case->closure_reviewed_by, $case->closure_reviewed_at, $case->closed_at);
        AuditLog::insert(['user_id' => $actor->id, 'event' => 'case.closure_history_captured', 'auditable_type' => 'case', 'auditable_id' => $case->id, 'changes' => json_encode(['closure_record_id' => $id, 'source' => 'legacy_case_summary']), 'created_at' => now(), 'updated_at' => now()]);

        return $this->current((int) $case->id);
    }

    private function insert(object $case, User $actor, ?string $summary, string $source, ?int $author, mixed $recordedAt, mixed $reviewer = null, mixed $reviewedAt = null, mixed $closedAt = null): int
    {
        return DB::table('case_closure_records')->insertGetId([
            'case_id' => $case->id, 'version' => ((int) DB::table('case_closure_records')->where('case_id', $case->id)->max('version')) + 1,
            'current_slot' => self::CURRENT_SLOT, 'summary' => $summary, 'source' => $source, 'recorded_by' => $author, 'recorded_at' => $recordedAt,
            'captured_by' => $actor->id, 'reviewed_by' => $reviewer, 'reviewed_at' => $reviewedAt, 'closed_at' => $closedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Identical retries retain the current record and its review state. */
    public function record(object $case, User $actor, string $summary): bool
    {
        $this->assertTransaction();
        $prior = $this->current((int) $case->id);
        $drift = $prior && $prior->summary !== $case->closure_summary;
        if ($drift) {
            DB::table('case_closure_records')->where('id', $prior->id)->update(['current_slot' => null, 'superseded_at' => now(), 'updated_at' => now()]);
        }
        $current = $this->captureSurvivingLegacy($case, $actor, (bool) $drift);
        if (!$drift && $current && $current->summary === $summary) {
            return false;
        }
        if ($current) {
            DB::table('case_closure_records')->where('id', $current->id)->update(['current_slot' => null, 'superseded_at' => now(), 'updated_at' => now()]);
        }
        $this->insert($case, $actor, $summary, 'authored_record', $actor->id, now());

        return true;
    }

    public function review(object $case, User $actor): void
    {
        $current = $this->captureSurvivingLegacy($case, $actor);
        if ($current) {
            DB::table('case_closure_records')->where('id', $current->id)->update(['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        }
    }

    public function close(object $case, User $actor): void
    {
        $current = $this->captureSurvivingLegacy($case, $actor);
        if ($current) {
            DB::table('case_closure_records')->where('id', $current->id)->update(['closed_at' => now(), 'updated_at' => now()]);
        }
    }

    public function reopen(object $case, User $actor): void
    {
        $current = $this->captureSurvivingLegacy($case, $actor);
        if ($current) {
            DB::table('case_closure_records')->where('id', $current->id)->update(['current_slot' => null, 'superseded_at' => now(), 'updated_at' => now()]);
        }
        // The former narrative and approval remain in history; a new helping
        // cycle must not borrow their authority for a later closure.
        DB::table('cases')->where('id', $case->id)->update(['closure_summary' => null, 'closure_reviewed_by' => null, 'closure_reviewed_at' => null]);
    }
}

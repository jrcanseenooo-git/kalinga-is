<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Kalinga suggests": rule-based next actions for the signed-in case manager,
 * drawn only from cases they may open. No external AI; every rule is listed
 * here so it can be reviewed. Items carry the case number, never names.
 *
 * OWNER REVIEW: thresholds follow the configured referral targets and common
 * CANE+D practice (safety review within 7 days for high risk, no case left
 * without a note for 30 days, assessment within 7 days of intake).
 */
class SmartSuggestions
{
    private const LIMIT = 8;

    public function __construct(private readonly ReferralTracking $tracking) {}

    /** @return array<int, array{kind:string, tone:string, title:string, text:string, case_id:int, case_number:string, section:string}> */
    public function forCases(Builder $authorizedCases): array
    {
        return array_slice($this->all($authorizedCases), 0, self::LIMIT);
    }

    /**
     * The most pressing suggestion per case, for the case list "Next step"
     * hint and the "Needs action" view.
     *
     * @return array<int, array{kind:string, tone:string, title:string, section:string}>
     */
    public function firstPerCase(Builder $authorizedCases): array
    {
        $first = [];
        foreach ($this->all($authorizedCases) as $item) {
            $first[$item['case_id']] ??= ['kind' => $item['kind'], 'tone' => $item['tone'], 'title' => $item['title'], 'section' => $item['section']];
        }

        return $first;
    }

    /** @return array<int, array{kind:string, tone:string, title:string, text:string, case_id:int, case_number:string, section:string}> */
    private function all(Builder $authorizedCases): array
    {
        $caseIds = (clone $authorizedCases)->whereNotIn('cases.status', ['CLOSED'])->pluck('cases.id');
        if ($caseIds->isEmpty()) {
            return [];
        }
        $numbers = DB::table('cases')->whereIn('id', $caseIds)->pluck('case_number', 'id');
        $out = [];
        $add = function (string $kind, string $tone, int $case, string $title, string $text, string $section) use (&$out, $numbers) {
            $out[] = ['kind' => $kind, 'tone' => $tone, 'title' => $title, 'text' => $text, 'case_id' => $case, 'case_number' => (string) ($numbers[$case] ?? ''), 'section' => $section];
        };

        // 1. Referral follow-ups past their target.
        $targets = $this->tracking->targets();
        foreach (DB::table('referrals')->whereIn('case_id', $caseIds)->whereIn('status', ['SENT', 'ACCEPTED', 'IN_PROGRESS'])->get(['id', 'case_id', 'status', 'referred_to', 'service_category', 'referred_at', 'accepted_at', 'created_at']) as $ref) {
            $accepted = in_array($ref->status, ['ACCEPTED', 'IN_PROGRESS'], true) && $ref->accepted_at;
            $due = Carbon::parse($accepted ? $ref->accepted_at : ($ref->referred_at ?? $ref->created_at))->addDays($accepted ? $targets['completion_days'] : $targets['response_days']);
            if ($due->isPast()) {
                $add('referral', 'urgent', (int) $ref->case_id, $accepted ? 'Service completion is overdue' : 'No response to a referral yet',
                    'Follow up with ' . ($ref->referred_to ?: 'the receiving office') . ' on ' . strtolower($ref->service_category) . ' (due ' . $due->timezone('Asia/Manila')->format('M j') . ').', 'monitoring');
            }
        }

        // 2. High or critical risk without a safety reassessment in the last 7 days.
        $latestRisk = DB::table('risk_assessments')->whereIn('case_id', $caseIds)->select('case_id', DB::raw('MAX(assessed_at) as last_at'))->groupBy('case_id')->pluck('last_at', 'case_id');
        foreach (DB::table('cases')->whereIn('id', $caseIds)->whereIn('initial_risk_level', ['high', 'critical'])->get(['id', 'opened_at']) as $case) {
            $last = $latestRisk[$case->id] ?? null;
            if (!$last || Carbon::parse($last)->lt(now()->subDays(7))) {
                $add('safety', 'urgent', (int) $case->id, 'High-risk case needs a safety review', $last ? 'Last safety assessment was more than 7 days ago.' : 'No safety and risk assessment recorded since intake.', 'safety');
            }
        }

        // 3. Opened more than 7 days ago with no comprehensive assessment.
        $assessed = DB::table('case_assessments')->whereIn('case_id', $caseIds)->distinct()->pluck('case_id')->all();
        foreach (DB::table('cases')->whereIn('id', $caseIds)->where('opened_at', '<', now()->subDays(7))->whereNotIn('id', $assessed)->get(['id']) as $case) {
            $add('assessment', 'action', (int) $case->id, 'Start the comprehensive assessment', 'The case was opened over a week ago and has no assessment yet.', 'assessment');
        }

        // 4. No progress note in 30 days.
        $lastNote = DB::table('case_notes')->whereIn('case_id', $caseIds)->select('case_id', DB::raw('MAX(occurred_at) as last_at'))->groupBy('case_id')->pluck('last_at', 'case_id');
        foreach ($caseIds as $case) {
            $last = $lastNote[$case] ?? null;
            if (!$last || Carbon::parse($last)->lt(now()->subDays(30))) {
                $add('stale', 'info', (int) $case, 'No progress note in 30 days', $last ? 'Record the latest contact or monitoring visit.' : 'No progress note recorded yet.', 'monitoring');
            }
        }

        // 5. Care plan review due within 7 days.
        foreach (DB::table('case_plans')->whereIn('case_id', $caseIds)->where('is_current', true)->whereNotNull('review_due_at')->where('review_due_at', '<=', now()->addDays(7)->toDateString())->get(['case_id', 'review_due_at']) as $plan) {
            $add('plan', 'action', (int) $plan->case_id, 'Care plan review is due', 'Review due ' . Carbon::parse($plan->review_due_at)->format('M j') . '.', 'care');
        }

        $order = ['urgent' => 0, 'action' => 1, 'info' => 2];
        usort($out, fn ($a, $b) => $order[$a['tone']] <=> $order[$b['tone']]);

        return $out;
    }
}

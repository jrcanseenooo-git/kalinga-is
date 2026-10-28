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
                    'Follow up with ' . ($ref->referred_to ?: 'the receiving office') . ' on ' . strtolower($ref->service_category) . ' (due ' . $due->timezone('Asia/Manila')->format('M j') . ').', 'notes');
            }
        }

        // 2. KaVAT: Baseline (separate tool; only its result is recorded here), Midline
        // and Endline (answered in Kalinga). The Midline is suggested 3 months after the
        // case opened; the Endline when the case goes to closure review.
        $withBaseline = DB::table('kavat_baselines')->whereIn('case_id', $caseIds)->pluck('case_id')->all();
        $kavatDraft = DB::table('kavat_assessments')->whereIn('case_id', $caseIds)->where('status', 'draft')->distinct()->pluck('case_id')->all();
        $done = DB::table('kavat_assessments')->whereIn('case_id', $caseIds)->get(['case_id', 'occasion'])->groupBy('occasion')->map(fn ($rows) => $rows->pluck('case_id')->all());
        foreach (DB::table('cases')->whereIn('id', $caseIds)->whereNotIn('id', $withBaseline)->get(['id']) as $case) {
            $add('kavat', 'action', (int) $case->id, 'Record the baseline KaVAT result', 'Enter the scores from the baseline KaVAT done before intake.', 'kavat');
        }
        foreach ($kavatDraft as $caseId) {
            $add('kavat', 'action', (int) $caseId, 'Finish the KaVAT in progress', 'Answer the remaining statements, then finalize it.', 'kavat');
        }
        foreach (DB::table('cases')->whereIn('id', $caseIds)->whereNotIn('id', array_merge($kavatDraft, $done['midline'] ?? []))->where('status', '!=', 'FOR_CLOSURE_REVIEW')
            ->where('opened_at', '<', now()->subMonths(3))->get(['id']) as $case) {
            $add('kavat', 'info', (int) $case->id, 'Midline KaVAT not done yet', 'The case opened over 3 months ago. Administer the Midline KaVAT with the child.', 'kavat');
        }
        foreach (DB::table('cases')->whereIn('id', $caseIds)->whereNotIn('id', array_merge($kavatDraft, $done['endline'] ?? []))->where('status', 'FOR_CLOSURE_REVIEW')->get(['id']) as $case) {
            $add('kavat', 'action', (int) $case->id, 'Administer the Endline KaVAT', 'The case is in closure review. The Endline KaVAT shows the change since the baseline.', 'kavat');
        }
        // 3. Remarks from the supervisor or monitors not yet read.
        foreach (DB::table('case_remarks')->whereIn('case_id', $caseIds)->whereNull('parent_id')->whereNull('acknowledged_at')->select('case_id', DB::raw('COUNT(*) as total'))->groupBy('case_id')->get() as $row) {
            $add('remark', 'urgent', (int) $row->case_id, 'Read the remarks on this case', $row->total . ' unread ' . ((int) $row->total === 1 ? 'remark' : 'remarks') . ' from your supervisor or monitors.', 'overview');
        }
        // Kalinga forms: Case Summary after a week, Intervention Plan after two weeks.
        $written = DB::table('case_forms')->whereIn('case_id', $caseIds)->get(['case_id', 'form_type'])->groupBy('form_type')->map(fn ($rows) => $rows->pluck('case_id')->all());
        foreach (DB::table('cases')->whereIn('id', $caseIds)->where('opened_at', '<', now()->subDays(7))->whereNotIn('id', $written['summary'] ?? [])->get(['id']) as $case) {
            $add('forms', 'action', (int) $case->id, 'Write the Case Summary Report', 'The case was opened over a week ago.', 'forms');
        }
        // Intervention Plan: submitted (finalized) within 7 days of the intake; reminded 2 days before.
        $submitted = DB::table('case_form_versions')->whereIn('case_id', $caseIds)->where('form_type', 'plan')->distinct()->pluck('case_id')->all();
        foreach (DB::table('cases')->whereIn('id', $caseIds)->whereNotIn('id', $submitted)->get(['id', 'intake_completed_at', 'opened_at', 'created_at']) as $case) {
            $deadline = CaseForms::planDeadline($case);
            if (!$deadline['overdue'] && $deadline['days_left'] > 2) {
                continue; // The case page shows the due date; the reminder starts 2 days before.
            }
            $due = Carbon::parse($deadline['due'])->format('M j');
            $add('forms', $deadline['overdue'] ? 'urgent' : 'action', (int) $case->id, $deadline['overdue'] ? 'Intervention Plan overdue' : 'Submit the Intervention Plan',
                $deadline['overdue'] ? "It was due {$due}, 7 days after the intake." : "Finalize it by {$due} (7 days after the intake).", 'forms');
        }

        // 4. No progress note in 30 days.
        $lastNote = DB::table('case_notes')->whereIn('case_id', $caseIds)->select('case_id', DB::raw('MAX(occurred_at) as last_at'))->groupBy('case_id')->pluck('last_at', 'case_id');
        foreach ($caseIds as $case) {
            $last = $lastNote[$case] ?? null;
            if (!$last || Carbon::parse($last)->lt(now()->subDays(30))) {
                $add('stale', 'info', (int) $case, 'No progress note in 30 days', $last ? 'Record the latest contact or monitoring visit.' : 'No progress note recorded yet.', 'notes');
            }
        }

        $order = ['urgent' => 0, 'action' => 1, 'info' => 2];
        usort($out, fn ($a, $b) => $order[$a['tone']] <=> $order[$b['tone']]);

        return $out;
    }
}

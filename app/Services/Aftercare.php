<?php

namespace App\Services;

use App\Support\PhilippineDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aftercare of a closed case (owner, 2026-10-28): the aftercare plan written
 * at closure and the follow-up visits after the child returns to the
 * community. A visit that finds the child "at risk" suggests reopening the
 * case; the next visit date shows on the calendar.
 */
final class Aftercare
{
    public const FREQUENCIES = ['monthly' => ['Every month', 1], 'bimonthly' => ['Every 2 months', 2], 'quarterly' => ['Every 3 months', 3]];

    public const STATUSES = ['stable' => 'Stable', 'needs_support' => 'Needs support', 'at_risk' => 'At risk'];

    /** The Kalinga aftercare plan of the case (the latest one with the Kalinga fields). */
    public static function plan(int $caseId): ?object
    {
        return DB::table('case_aftercare_plans')->where('case_id', $caseId)->whereNotNull('living_arrangement')->orderByDesc('id')->first();
    }

    /** @return array{plan: ?array, visits: list<array>, next_due: ?string, suggest_reopen: bool, complete: bool} */
    public static function forCase(int $caseId): array
    {
        $plan = self::plan($caseId);
        $visits = DB::table('aftercare_visits')->leftJoin('users', 'users.id', '=', 'aftercare_visits.recorded_by')->where('aftercare_visits.case_id', $caseId)
            ->orderByDesc('aftercare_visits.visited_on')->orderByDesc('aftercare_visits.id')
            ->get(['aftercare_visits.id', 'aftercare_visits.visited_on', 'aftercare_visits.status', 'aftercare_visits.notes', 'aftercare_visits.created_at', 'users.name as recorded_by'])
            ->map(fn ($row) => ['id' => $row->id, 'visited_on' => substr((string) $row->visited_on, 0, 10), 'status' => $row->status, 'status_label' => self::STATUSES[$row->status] ?? $row->status,
                'notes' => $row->notes, 'recorded_by' => $row->recorded_by])->values()->all();
        $next = $plan ? self::nextDue($plan, $visits[0]['visited_on'] ?? null) : null;

        return [
            'plan' => $plan ? ['id' => $plan->id, 'living_arrangement' => $plan->living_arrangement, 'support_people' => $plan->support_people, 'services_to_continue' => $plan->services_to_continue,
                'risks_to_watch' => $plan->risks_to_watch, 'responsible_office' => $plan->responsible_office, 'follow_up_frequency' => $plan->follow_up_frequency,
                'initial_follow_up_at' => $plan->initial_follow_up_at ? substr((string) $plan->initial_follow_up_at, 0, 10) : null,
                'follow_up_until' => $plan->follow_up_until ? substr((string) $plan->follow_up_until, 0, 10) : null, 'notes' => $plan->continuity_notes, 'updated_at' => $plan->updated_at] : null,
            'visits' => $visits,
            'next_due' => $next,
            'suggest_reopen' => ($visits[0]['status'] ?? null) === 'at_risk',
            'complete' => $plan !== null && $next === null,
        ];
    }

    /** The next follow-up visit: the first one, then one frequency after the last visit, until the end date. */
    public static function nextDue(object $plan, ?string $lastVisit): ?string
    {
        $months = self::FREQUENCIES[$plan->follow_up_frequency][1] ?? 1;
        $next = $lastVisit ? Carbon::parse($lastVisit)->addMonthsNoOverflow($months) : ($plan->initial_follow_up_at ? Carbon::parse($plan->initial_follow_up_at) : null);
        if (!$next) {
            return null;
        }
        if ($plan->follow_up_until && $next->gt(Carbon::parse($plan->follow_up_until))) {
            return null;
        }

        return $next->toDateString();
    }

    /** Whether the next visit is past due (for alerts). */
    public static function overdue(?string $nextDue): bool
    {
        return $nextDue !== null && $nextDue < PhilippineDate::today();
    }
}

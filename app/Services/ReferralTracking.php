<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Referral status history for referrals the caller has already authorized. */
class ReferralTracking
{
    /** Adds `events` (status changes with remarks, outcome, reason and actor name) to each referral. */
    public function withEvents(Collection $items): Collection
    {
        $events = DB::table('referral_events')->leftJoin('users', 'users.id', '=', 'referral_events.actor_id')
            ->whereIn('referral_events.referral_id', $items->pluck('id'))->orderBy('referral_events.id')
            ->get(['referral_events.referral_id', 'referral_events.from_status', 'referral_events.to_status', 'referral_events.remarks',
                'referral_events.outcome', 'referral_events.reason', 'referral_events.created_at', 'users.name as actor'])
            ->groupBy('referral_id');

        return $items->map(function ($item) use ($events) {
            $item->events = ($events[$item->id] ?? collect())->values();

            return $item;
        });
    }

    public function hasSharingConsent(int $case): bool
    {
        return DB::table('consents')->where('case_id', $case)->where('sharing_allowed', true)->whereNull('withdrawn_at')->exists();
    }

    /** Follow-up targets shared with the interface. */
    public function targets(): array
    {
        return ['response_days' => (int) config('kalinga.referral_response_days'), 'completion_days' => (int) config('kalinga.referral_completion_days')];
    }
}

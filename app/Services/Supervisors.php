<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The supervisor of a case manager is the LGU Head (C/MSWDO head) of the LGU
 * the case belongs to. Used for status alerts the supervisor must see, such
 * as the Intervention Plan deadline and rescheduled activities.
 */
final class Supervisors
{
    /** @return list<int> active LGU Heads assigned to the LGU */
    public static function forLgu(?int $lguId): array
    {
        if (!$lguId) {
            return [];
        }

        return DB::table('users')->join('user_lgu_assignments as assignment', 'assignment.user_id', '=', 'users.id')
            ->where('users.role', 'lgu_head')->where('users.active', true)->where('assignment.active', true)->where('assignment.lgu_id', $lguId)
            ->where(fn ($dates) => $dates->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>', now()))
            ->distinct()->pluck('users.id')->map(fn ($id) => (int) $id)->values()->all();
    }
}

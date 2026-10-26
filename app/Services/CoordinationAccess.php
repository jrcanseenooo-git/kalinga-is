<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Shared current eligibility for group chat and its private member conversations. */
class CoordinationAccess
{
    public function members(int $circle): Builder
    {
        return DB::table('lgu_coordination_members as member')->join('users', 'users.id', '=', 'member.user_id')
            ->join('lgu_coordination_circles as circle', 'circle.id', '=', 'member.circle_id')
            ->join('lgu_units as lgu', 'lgu.id', '=', 'circle.lgu_id')
            ->where('member.circle_id', $circle)->whereNull('member.left_at')->where('users.active', true)
            ->where('circle.active', true)->where('lgu.active', true)
            ->where(fn ($dates) => $dates->whereNull('member.joined_at')->orWhere('member.joined_at', '<=', now()))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('user_lgu_assignments as assignment')
                ->whereColumn('assignment.user_id', 'users.id')->whereColumn('assignment.lgu_id', 'circle.lgu_id')
                ->where('assignment.active', true)->where('assignment.effective_from', '<=', now())
                ->where(fn ($dates) => $dates->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>', now())));
    }

    public function participant(int $user, int $circle, string $permission): User
    {
        $actor = User::find($user);
        abort_unless($actor && $actor->active && $actor->hasPermission($permission)
            && $this->members($circle)->where('users.id', $user)->exists(), 403, 'Active LGU circle membership is required.');

        return $actor;
    }
}

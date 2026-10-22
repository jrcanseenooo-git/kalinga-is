<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** One eligibility query for case endpoints, worklists and reviewed chat capture. */
class MdtGrantScope
{
    public function forUser(User $user): Builder
    {
        return $this->forUserId($user->id);
    }

    public function forUserId(int $userId): Builder
    {
        return $this->eligibleForUserId($userId, false);
    }

    /** Internal post-write check after an authorized terminal action only. */
    public function forCompletingUserId(int $userId): Builder
    {
        return $this->eligibleForUserId($userId, true);
    }

    private function eligibleForUserId(int $userId, bool $allowCompleted): Builder
    {
        return DB::table('mdt_case_access')
            ->join('cases', 'cases.id', '=', 'mdt_case_access.case_id')
            ->join('clients', 'clients.id', '=', 'cases.client_id')
            ->join('users as member', 'member.id', '=', 'mdt_case_access.user_id')
            ->where('mdt_case_access.user_id', $userId)->where('member.active', true)
            ->where('member.role', 'cpu_member')->whereNull('cases.deleted_at')->where('cases.status', '!=', 'CLOSED')
            ->whereNull('mdt_case_access.revoked_at')->whereNotIn('mdt_case_access.status', $allowCompleted ? ['revoked', 'returned'] : ['revoked', 'completed', 'returned'])
            ->where(fn ($query) => $query->whereNull('mdt_case_access.access_starts_at')->orWhere('mdt_case_access.access_starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('mdt_case_access.access_expires_at')->orWhere('mdt_case_access.access_expires_at', '>', now()))
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('user_lgu_assignments as assignment')
                    ->join('lgu_units as lgu', 'lgu.id', '=', 'assignment.lgu_id')
                    ->whereColumn('assignment.user_id', 'mdt_case_access.user_id')
                    ->where('assignment.active', true)->where('lgu.active', true)
                    ->where('assignment.effective_from', '<=', now())
                    ->where(fn ($dates) => $dates->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>', now()))
                    ->where(fn ($scope) => $scope->whereColumn('assignment.lgu_id', 'cases.lgu_id')
                        ->orWhere(fn ($legacy) => $legacy->whereNull('cases.lgu_id')->whereColumn('lgu.name', 'clients.municipality')
                            ->where(fn ($province) => $province->whereNull('clients.province')->orWhere('clients.province', '')->orWhereColumn('lgu.province', 'clients.province'))));
            });
    }
}

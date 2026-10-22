<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the active LGU boundary. Legacy municipality/province fields remain
 * a compatibility fallback while deployments migrate to PSGC-backed LGU IDs.
 */
class LguAssignmentScope
{
    public function activeLguIds(User $user): array
    {
        return $this->activeAssignments()->where('user_id', $user->id)
            ->pluck('lgu_id')->map(fn ($id) => (int) $id)->all();
    }

    private function activeAssignments(): Builder
    {
        $asOf = now();

        return DB::table('user_lgu_assignments')->where('active', true)
            ->where('effective_from', '<=', $asOf)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $asOf));
    }

    private function assignmentsForCase(object $case, ?string $municipality, ?string $province): Builder
    {
        $query = $this->activeAssignments();
        if (!empty($case->lgu_id)) {
            return $query->where('lgu_id', (int) $case->lgu_id);
        }

        return $query->whereIn('lgu_id', DB::table('lgu_units')->select('id')
            ->where('name', trim((string) $municipality))
            ->when($province, fn ($lgus) => $lgus->where('province', trim($province))));
    }

    public function activeUserIdsForCase(object $case, ?string $municipality, ?string $province): array
    {
        return $this->assignmentsForCase($case, $municipality, $province)
            ->distinct()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    public function hasActiveAssignment(User $user): bool
    {
        return count($this->activeLguIds($user)) > 0;
    }

    public function caseMatches(User $user, object $case, ?string $municipality, ?string $province = null): bool
    {
        return $this->assignmentsForCase($case, $municipality, $province)->where('user_id', $user->id)->exists();
    }

    public function lguNameFor(User $user): ?string
    {
        $ids = $this->activeLguIds($user);
        if ($ids !== []) {
            return DB::table('lgu_units')->where('id', $ids[0])->value('name');
        }

        return null;
    }

    public function matchesMunicipality(User $user, string $municipality): bool
    {
        $assigned = $this->activeLguIds($user);
        if ($assigned === []) {
            return false;
        }

        return DB::table('lgu_units')->whereIn('id', $assigned)->where('name', trim($municipality))->exists();
    }

    public function dashboardCaseIds(User $user): array
    {
        $ids = $this->activeLguIds($user);
        if ($ids !== []) {
            return DB::table('cases')->whereIn('lgu_id', $ids)->whereNull('deleted_at')->pluck('id')->all();
        }

        return [];
    }
}

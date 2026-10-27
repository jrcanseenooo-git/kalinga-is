<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which cases a monitoring role may see (secured names, read-only):
 * - Project Manager: the regions in users.region_scope (["*"] = all regions);
 * - Field Office user: its own region (users.region);
 * - LGU Head: its active LGU assignments.
 * Cases are placed by their LGU (cases.lgu_id). Every other role sees none here.
 */
final class MonitoringScope
{
    public const ALL_REGIONS = '*';

    public function __construct(private readonly LguAssignmentScope $lgus) {}

    /** @return list<int>|null LGU ids the user monitors; null means every LGU. */
    public function lguIds(User $user): ?array
    {
        $regions = $this->regions($user);
        if ($user->role === 'lgu_head') {
            return $this->lgus->activeLguIds($user);
        }
        if ($regions === null) {
            return null;
        }

        return $regions === [] ? [] : DB::table('lgu_units')->whereIn('region', $regions)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<string>|null region names; null means all regions. */
    public function regions(User $user): ?array
    {
        if ($user->role === 'project_manager') {
            $scope = array_values(array_filter((array) ($user->region_scope ?? []), 'is_string'));

            return in_array(self::ALL_REGIONS, $scope, true) ? null : $scope;
        }
        if ($user->role === 'fo_user') {
            return trim((string) $user->region) === '' ? [] : [trim((string) $user->region)];
        }
        if ($user->role === 'lgu_head') {
            $ids = $this->lgus->activeLguIds($user);

            return $ids === [] ? [] : DB::table('lgu_units')->whereIn('id', $ids)->distinct()->pluck('region')->filter()->values()->all();
        }

        return [];
    }

    public function scopeCases(Builder $query, User $user, string $caseTable = 'cases'): Builder
    {
        if (!$user->hasPermission('cases.monitor') || $user->active === false) {
            return $query->whereRaw('1 = 0');
        }
        $ids = $this->lguIds($user);
        if ($ids === null) {
            return $query;
        }

        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn("{$caseTable}.lgu_id", $ids);
    }

    public function allowsCase(object $case, User $user): bool
    {
        if (!$user->hasPermission('cases.monitor') || $user->active === false) {
            return false;
        }
        $ids = $this->lguIds($user);

        return $ids === null || (!empty($case->lgu_id) && in_array((int) $case->lgu_id, $ids, true));
    }

    /** Plain words for the page header, for example "All regions" or "City of Tabuk". */
    public function label(User $user): string
    {
        if ($user->role === 'lgu_head') {
            $names = DB::table('lgu_units')->whereIn('id', $this->lgus->activeLguIds($user) ?: [0])->orderBy('name')->pluck('name')->all();

            return $names === [] ? 'No LGU assigned' : implode(', ', $names);
        }
        $regions = $this->regions($user);

        return $regions === null ? 'All regions' : ($regions === [] ? 'No region assigned' : implode(', ', $regions));
    }
}

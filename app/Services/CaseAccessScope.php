<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * Applies the minimum-necessary jurisdiction boundary to casework queries.
 * Query scope must match detail authorization. Administrators have no case access;
 * monitoring roles use MonitoringScope (secured names, read-only).
 */
class CaseAccessScope
{
    public function __construct(private readonly LguAssignmentScope $lgus) {}

    public function scopeCases(Builder $query, User $user, string $caseTable = 'cases', string $clientTable = 'clients'): Builder
    {
        if (in_array($user->role, ['social_worker'], true)) {
            $ids = $this->lgus->activeLguIds($user);
            if ($ids !== []) {
                return $query->whereIn("{$caseTable}.lgu_id", $ids)
                    ->where("{$caseTable}.assigned_to", $user->id);
            }

            return $query->whereRaw('1 = 0');
        }

        if (app(RolePermissions::class)->isFieldWorker($user)) {
            return $query->where("{$caseTable}.assigned_to", $user->id);
        }

        if ($user->role === 'lgu_head') {
            $ids = $this->lgus->activeLguIds($user);

            return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn("{$caseTable}.lgu_id", $ids);
        }

        return $query->whereRaw('1 = 0');
    }

    public function allowsCase(object $case, User $user, ?string $municipality, ?string $province): bool
    {
        if (!$user->hasPermission('cases.view')) {
            return false;
        }

        if (in_array($user->role, ['social_worker'], true)) {
            return (int) $case->assigned_to === (int) $user->id
                && $this->lgus->caseMatches($user, $case, $municipality, $province);
        }

        if (app(RolePermissions::class)->isFieldWorker($user)) {
            return (int) $case->assigned_to === $user->id;
        }

        if ($user->role === 'lgu_head') {
            return $this->lgus->caseMatches($user, $case, $municipality, $province);
        }

        return false;
    }

    public function scopeMunicipality(Builder $query, User $user, string $column): Builder
    {
        return $this->hasAssignment($user->municipality)
            ? $query->where($column, trim((string) $user->municipality))
            : $query->whereRaw('1 = 0');
    }

    public function scopeProvince(Builder $query, User $user, string $column): Builder
    {
        return $this->hasAssignment($user->province)
            ? $query->where($column, trim((string) $user->province))
            : $query->whereRaw('1 = 0');
    }

    private function hasAssignment(?string $value): bool
    {
        return trim((string) $value) !== '';
    }
}

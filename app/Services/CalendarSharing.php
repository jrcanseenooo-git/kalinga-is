<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who can be invited to a calendar activity, and who is told when an
 * activity is moved or cancelled.
 *
 * - Invitees: active users working in the organizer's area (an LGU they are
 *   assigned to or, for monitors, the LGUs they monitor), plus the Field
 *   Office and Project Managers covering that area. System administrators
 *   are never invited (IT role).
 * - Watchers of a change: the LGU Head of the organizer's LGU and the Field
 *   Office and Project Managers covering its region (owner instruction:
 *   reschedules and cancellations go to the supervisor, Field Office and
 *   Project Manager).
 *
 * An invitation never opens the linked case: invitees see the case number
 * only when they may already open that case.
 */
final class CalendarSharing
{
    public const MAX_PARTICIPANTS = 20;

    public function __construct(private readonly MonitoringScope $monitoring, private readonly LguAssignmentScope $lgus) {}

    /** @return list<int>|null LGU ids of the organizer's area; null = every LGU. */
    public function areaLguIds(User $user): ?array
    {
        if ($user->hasPermission('cases.monitor')) {
            return $this->monitoring->lguIds($user);
        }

        return $this->lgus->activeLguIds($user);
    }

    /** @return list<string>|null */
    private function areaRegions(?array $lguIds): ?array
    {
        return $lguIds === null ? null : DB::table('lgu_units')->whereIn('id', $lguIds ?: [0])->distinct()->pluck('region')->filter()->values()->all();
    }

    /**
     * People who can be invited, found by name (any letter case) or by role
     * ("supervisor", "CPU", "field office", "project manager"): case
     * managers, LGU Heads (supervisors) and CPU members of the organizer's
     * LGUs, Field Office focals of its regions and Project Managers covering
     * them. CPU members are also found through the LGU CPU member list.
     */
    public function candidates(User $organizer, string $search = ''): Collection
    {
        $lguIds = $this->areaLguIds($organizer);
        $regions = $this->areaRegions($lguIds);
        $query = DB::table('users')->where('users.active', true)->where('users.id', '!=', $organizer->id)->where('users.role', '!=', 'system_admin');
        if ($lguIds !== null) {
            $query->where(function ($where) use ($lguIds, $regions) {
                $where->whereExists(fn ($assigned) => $assigned->selectRaw('1')->from('user_lgu_assignments as assignment')->whereColumn('assignment.user_id', 'users.id')
                    ->where('assignment.active', true)->whereIn('assignment.lgu_id', $lguIds ?: [0])
                    ->where(fn ($dates) => $dates->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>', now())))
                    ->orWhereExists(fn ($cpu) => $cpu->selectRaw('1')->from('lgu_cpu_members as member')->whereColumn('member.user_id', 'users.id')
                        ->where('member.active', true)->whereIn('member.lgu_id', $lguIds ?: [0]))
                    ->orWhere(fn ($fo) => $fo->where('users.role', 'fo_user')->where(fn ($region) => $region->whereIn('users.region', $regions ?: [''])->orWhereNotNull('users.region_scope')))
                    ->orWhere(fn ($pm) => $pm->where('users.role', 'project_manager'));
            });
        }
        $term = mb_strtolower(trim($search));
        if ($term !== '') {
            // Role words match from 3 letters ("sup" finds supervisors), so a short name search stays a name search.
            $roles = mb_strlen($term) < 3 ? [] : collect(self::ROLE_WORDS)->filter(fn ($words) => collect($words)->contains(fn ($word) => str_starts_with($word, $term) || str_contains($term, $word)))->keys()->all();
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $query->where(fn ($match) => $match->whereRaw('LOWER(users.name) LIKE ?', [$like])->when($roles, fn ($byRole) => $byRole->orWhereIn('users.role', $roles)));
        }

        return $query->orderBy('users.name')->limit(200)->get(['users.id', 'users.name', 'users.role', 'users.region', 'users.region_scope', 'users.position'])
            // Field Office and Project Managers are invited only for the regions they cover.
            ->filter(fn ($user) => !in_array($user->role, ['project_manager', 'fo_user'], true) || $lguIds === null
                || ($user->role === 'fo_user' && in_array($user->region, $regions ?? [], true)) || $this->coversRegions($user, $regions))
            ->take(30)->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'role' => RolePermissions::label($user->role) . ($user->position ? ' - ' . $user->position : '')])->values();
    }

    /** Words that find people by role in the invite search (lower case). */
    private const ROLE_WORDS = [
        'lgu_head' => ['supervisor', 'lgu head', 'head', 'mswdo', 'cswdo'],
        'cpu_member' => ['cpu', 'child protection', 'mdt'],
        'fo_user' => ['field office', 'focal', 'regional'],
        'project_manager' => ['project manager', 'central office', 'focal'],
        'social_worker' => ['case manager', 'social worker'],
    ];

    public function canInvite(User $organizer, array $userIds): bool
    {
        $allowed = $this->candidates($organizer)->pluck('id')->all();
        // Candidates are capped for the picker; check the rest directly.
        foreach ($userIds as $id) {
            if (!in_array((int) $id, $allowed, true) && !$this->candidateById($organizer, (int) $id)) {
                return false;
            }
        }

        return true;
    }

    private function candidateById(User $organizer, int $id): bool
    {
        $name = DB::table('users')->where('id', $id)->value('name');

        return $name !== null && $this->candidates($organizer, $name)->contains('id', $id);
    }

    /** @return list<int> users to tell about a reschedule or cancellation of the organizer's activity */
    public function watchers(User $organizer): array
    {
        $lguIds = $this->lgus->activeLguIds($organizer);
        if ($lguIds === []) {
            return [];
        }
        $regions = $this->areaRegions($lguIds);
        $heads = DB::table('users')->join('user_lgu_assignments as assignment', 'assignment.user_id', '=', 'users.id')
            ->where('users.role', 'lgu_head')->where('users.active', true)->where('assignment.active', true)->whereIn('assignment.lgu_id', $lguIds)->pluck('users.id');
        $fo = DB::table('users')->where('role', 'fo_user')->where('active', true)->whereIn('region', $regions ?: [''])->pluck('id');
        $pm = DB::table('users')->where('role', 'project_manager')->where('active', true)->get(['id', 'region_scope'])
            ->filter(fn ($user) => $this->coversRegions($user, $regions))->pluck('id');

        return $heads->merge($fo)->merge($pm)->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === $organizer->id)->unique()->values()->all();
    }

    private function coversRegions(object $user, ?array $regions): bool
    {
        $scope = is_array($user->region_scope) ? $user->region_scope : (json_decode((string) $user->region_scope, true) ?: []);
        if (in_array(MonitoringScope::ALL_REGIONS, $scope, true)) {
            return true;
        }

        return $regions !== null && array_intersect($scope, $regions) !== [];
    }
}

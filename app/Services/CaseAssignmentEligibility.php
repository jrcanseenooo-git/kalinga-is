<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CaseAssignmentEligibility
{
    public function __construct(private readonly CaseAccessScope $scope, private readonly LguAssignmentScope $lgus) {}

    private function location(object $case): array
    {
        if (property_exists($case, 'municipality') && property_exists($case, 'province')) {
            return [$case->municipality, $case->province];
        }
        $client = DB::table('clients')->where('id', $case->client_id)->first(['municipality', 'province']);

        return [$client?->municipality, $client?->province];
    }

    public function allows(User $worker, object $case): bool
    {
        // Worker worklists require a verified case LGU ID. Do not infer a new
        // ownership entitlement from a legacy client's residence text.
        if (empty($case->lgu_id) || !$worker->active || $worker->role !== 'social_worker') {
            return false;
        }
        $proposed = clone $case;
        $proposed->assigned_to = $worker->id;

        return $this->canRead($worker, $proposed);
    }

    public function canRead(User $user, object $case): bool
    {
        [$municipality, $province] = $this->location($case);

        return $user->active && $this->scope->allowsCase($case, $user, $municipality, $province);
    }

    public function mayAssign(User $actor, object $case): bool
    {
        return $actor->can('cases.assign') && $this->canRead($actor, $case);
    }

    public function candidates(object $case): Collection
    {
        if (empty($case->lgu_id)) {
            return collect();
        }
        [$municipality, $province] = $this->location($case);
        $ids = $this->lgus->activeUserIdsForCase($case, $municipality, $province);

        // The shared LGU predicate is also used by detail authorization. Avoid
        // loading a jurisdiction-wide staff directory or querying each member.
        return User::query()->whereIn('id', $ids)->where('role', 'social_worker')->where('active', true)
            ->orderBy('name')->get(['id', 'name', 'role', 'active'])
            ->filter(fn (User $worker) => $worker->hasPermission('cases.view'))
            ->map(fn (User $worker) => ['id' => $worker->id, 'name' => $worker->name, 'role' => $worker->role])->values();
    }
}

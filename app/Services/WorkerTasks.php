<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Personal tasks retain ownership; linked case data additionally requires current case access. */
class WorkerTasks
{
    public function __construct(private readonly CaseAccessScope $scope) {}

    public function cases(User $user): Builder
    {
        return $this->scope->scopeCases(DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id')
            ->whereNull('cases.deleted_at'), $user);
    }

    public function query(User $user): Builder
    {
        $caseIds = $this->cases($user)->select('cases.id');

        return DB::table('tasks')->leftJoin('cases', 'cases.id', '=', 'tasks.case_id')
            ->where('tasks.assigned_to', $user->id)
            ->where(fn ($query) => $query->whereNull('tasks.case_id')->orWhereIn('tasks.case_id', $caseIds));
    }

    public function actionQueue(User $user, Builder $authorizedCases)
    {
        $rows = $this->query($user)->whereIn('tasks.case_id', (clone $authorizedCases)->select('cases.id'))
            ->where('tasks.status', 'open')
            ->orderByRaw('CASE WHEN tasks.due_at IS NULL THEN 1 ELSE 0 END')->orderBy('tasks.due_at')
            ->orderByRaw("CASE cases.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderBy('tasks.id')->limit(10)
            ->get(['tasks.id as task_id', 'tasks.case_id', 'tasks.title as next_action', 'tasks.due_at',
                'cases.case_number', 'cases.stage', 'cases.status', 'cases.priority']);

        return $this->withIsoDates($rows);
    }

    public function withIsoDates(Collection $rows): Collection
    {
        return $rows->map(function (object $row) {
            $row->due_at = $row->due_at ? Carbon::parse($row->due_at)->toIso8601String() : null;

            return $row;
        });
    }
}

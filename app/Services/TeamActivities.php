<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The supervisor (LGU Head) sees the calendar activities of the case
 * managers of the LGU, and every rescheduled activity is an attention item
 * for the case manager who moved it and for the supervisor until the
 * supervisor marks it reviewed (owner, 2026-10-28: for accountability, a
 * move may hide a missed activity).
 */
final class TeamActivities
{
    /** Moves stay on the list for this many days if nobody reviews them. */
    public const KEEP_DAYS = 30;

    public function __construct(private readonly LguAssignmentScope $lgus) {}

    public function isSupervisor(User $user): bool
    {
        return $user->role === 'lgu_head';
    }

    /** @return list<int> case managers with an active assignment in the supervisor's LGUs */
    public function caseManagerIds(User $supervisor): array
    {
        $lguIds = $this->lgus->activeLguIds($supervisor);

        return $lguIds === [] ? [] : DB::table('users')->join('user_lgu_assignments as assignment', 'assignment.user_id', '=', 'users.id')
            ->where('users.role', 'social_worker')->where('users.active', true)->where('assignment.active', true)->whereIn('assignment.lgu_id', $lguIds)
            ->where(fn ($dates) => $dates->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>', now()))
            ->distinct()->pluck('users.id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** Calendar activities of the supervisor's case managers (read only). */
    public function calendar(User $supervisor): Collection
    {
        $team = $this->caseManagerIds($supervisor);
        if ($team === []) {
            return collect();
        }
        $eligibility = app(CaseAssignmentEligibility::class);
        $rows = DB::table('tasks')->join('users', 'users.id', '=', 'tasks.assigned_to')->leftJoin('cases', 'cases.id', '=', 'tasks.case_id')
            ->whereIn('tasks.assigned_to', $team)->whereNotNull('tasks.due_at')
            ->where(fn ($q) => $q->where('tasks.status', 'open')->orWhere('tasks.updated_at', '>=', now()->subDays(90)))
            ->orderBy('tasks.due_at')->limit(500)
            ->get(['tasks.id', 'tasks.title', 'tasks.event_type', 'tasks.due_at', 'tasks.location', 'tasks.status', 'tasks.case_id', 'cases.case_number', 'cases.deleted_at', 'users.name as organizer']);
        $history = DB::table('task_history')->join('users', 'users.id', '=', 'task_history.actor_id')->whereIn('task_history.task_id', $rows->pluck('id')->all() ?: [0])
            ->orderBy('task_history.id')->get(['task_history.task_id', 'task_history.event', 'task_history.from_due_at', 'task_history.to_due_at', 'task_history.reason', 'task_history.created_at', 'users.name as actor'])
            ->groupBy('task_id');
        $readable = [];

        return $rows->map(function ($row) use ($supervisor, $eligibility, $history, &$readable) {
            $visible = false;
            if ($row->case_id && !$row->deleted_at) {
                $readable[$row->case_id] ??= (($case = DB::table('cases')->find($row->case_id)) && $eligibility->canRead($supervisor, $case));
                $visible = $readable[$row->case_id];
            }

            // Details stay with the organizer; the supervisor sees what, when, where and the changes.
            return (object) ['id' => $row->id, 'title' => $row->title, 'event_type' => $row->event_type, 'due_at' => Carbon::parse($row->due_at)->toIso8601String(),
                'location' => $row->location, 'details' => null, 'status' => $row->status, 'case_id' => $visible ? $row->case_id : null,
                'case_number' => $visible ? $row->case_number : ($row->case_id ? 'Case-linked activity' : null),
                'shared' => false, 'team' => true, 'organizer' => $row->organizer, 'participants' => [], 'history' => ($history[$row->id] ?? collect())->values()];
        });
    }

    /** Rescheduled activities to look at: the case manager's own, or the team's for the supervisor. */
    public function moves(User $user): Collection
    {
        $actors = $this->isSupervisor($user) ? $this->caseManagerIds($user) : [$user->id];
        if ($actors === []) {
            return collect();
        }

        return DB::table('task_history')->join('tasks', 'tasks.id', '=', 'task_history.task_id')->join('users', 'users.id', '=', 'task_history.actor_id')
            ->leftJoin('cases', 'cases.id', '=', 'tasks.case_id')
            ->where('task_history.event', 'rescheduled')->whereIn('task_history.actor_id', $actors)->whereNull('task_history.reviewed_at')
            ->where('task_history.created_at', '>=', now()->subDays(self::KEEP_DAYS))
            ->orderByDesc('task_history.id')->limit(20)
            ->get(['task_history.id', 'task_history.from_due_at', 'task_history.to_due_at', 'task_history.reason', 'task_history.created_at', 'tasks.title', 'tasks.status',
                'users.name as actor', 'cases.case_number']);
    }

    public function review(User $supervisor, int $historyId): bool
    {
        $move = DB::table('task_history')->where('id', $historyId)->where('event', 'rescheduled')->whereNull('reviewed_at')->first();
        if (!$move || !$this->isSupervisor($supervisor) || !in_array((int) $move->actor_id, $this->caseManagerIds($supervisor), true)) {
            return false;
        }
        DB::table('task_history')->where('id', $historyId)->update(['reviewed_at' => now(), 'reviewed_by' => $supervisor->id]);
        AuditLog::record('task.move_reviewed', 'task', (int) $move->task_id, ['history_id' => $historyId], $supervisor->id, 'success', ['description' => 'Rescheduled activity reviewed by the supervisor']);

        return true;
    }
}

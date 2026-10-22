<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DispatchDeadlineAlerts extends Command
{
    protected $signature = 'kalinga:dispatch-deadline-alerts {--dry-run : Report eligible reminders without creating notifications}';

    protected $description = 'Create deduplicated in-app alerts for due and overdue tasks and case-plan reviews.';

    public function handle(): int
    {
        $now = now('Asia/Manila');
        $created = 0;
        $eligible = 0;

        $tasks = DB::table('tasks')
            ->where('status', 'open')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now->copy()->addDay())
            ->get(['id', 'assigned_to', 'title', 'due_at']);

        foreach ($tasks as $task) {
            $eligible++;
            $dueAt = Carbon::parse($task->due_at, 'Asia/Manila');
            $overdue = $dueAt->isBefore($now);
            $created += $this->createOnce(
                $task->assigned_to,
                $overdue ? 'task_overdue' : 'task_due_soon',
                $overdue ? 'Task overdue' : 'Task due soon',
                "{$task->title} · due {$dueAt->format('M j, g:i A')} · activity #{$task->id}",
                "/tasks?task={$task->id}"
            );
        }

        $plans = DB::table('case_plans')
            ->join('cases', 'cases.id', '=', 'case_plans.case_id')
            ->where('case_plans.status', 'active')
            ->whereNotNull('case_plans.review_due_at')
            ->whereNotNull('cases.assigned_to')
            ->whereNull('cases.deleted_at')
            ->whereNotIn('cases.status', ['CLOSED'])
            ->where('case_plans.review_due_at', '<=', $now->toDateString())
            ->get(['case_plans.id', 'case_plans.case_id', 'case_plans.review_due_at', 'cases.assigned_to', 'cases.case_number']);

        foreach ($plans as $plan) {
            $eligible++;
            $reviewDate = Carbon::parse($plan->review_due_at, 'Asia/Manila');
            $overdue = $reviewDate->isBefore($now->copy()->startOfDay());
            $created += $this->createOnce(
                $plan->assigned_to,
                $overdue ? 'plan_review_overdue' : 'plan_review_due',
                $overdue ? 'Case plan review overdue' : 'Case plan review due today',
                "{$plan->case_number} · review {$reviewDate->format('M j')} · plan #{$plan->id}",
                "/cases/{$plan->case_id}"
            );
        }

        $this->info($this->option('dry-run')
            ? "{$eligible} deadline reminder(s) eligible; no notifications created."
            : "{$created} deadline reminder(s) created from {$eligible} eligible item(s)."
        );

        return self::SUCCESS;
    }

    private function createOnce(int $userId, string $type, string $title, string $body, string $actionUrl): int
    {
        $query = DB::table('notifications')
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('body', $body);

        if ($query->exists() || $this->option('dry-run')) {
            return 0;
        }

        DB::table('notifications')->insert([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'action_url' => $actionUrl,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 1;
    }
}

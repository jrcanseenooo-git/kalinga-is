<?php

namespace App\Console\Commands;

use App\Services\Aftercare;
use App\Services\CaseForms;
use App\Services\OperationalNotification;
use App\Services\Supervisors;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DispatchDeadlineAlerts extends Command
{
    protected $signature = 'kalinga:dispatch-deadline-alerts {--dry-run : Report eligible reminders without creating notifications}';

    protected $description = 'Create deduplicated in-app alerts for due and overdue tasks, case-plan reviews and Intervention Plans.';

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

        // Intervention Plan: due 7 days after intake. Two days before, the case manager is reminded;
        // when overdue, the case manager and the supervisor are told (it counts in the submission time).
        $submitted = DB::table('case_form_versions')->where('form_type', 'plan')->distinct()->pluck('case_id')->all();
        $cases = DB::table('cases')->whereNull('deleted_at')->whereNotNull('assigned_to')->whereNotIn('status', ['CLOSED', 'ARCHIVED'])
            ->whereNotIn('id', $submitted ?: [0])->where(DB::raw('COALESCE(intake_completed_at, opened_at)'), '>=', $now->copy()->subDays(90))
            ->get(['id', 'case_number', 'assigned_to', 'lgu_id', 'intake_completed_at', 'opened_at', 'created_at']);
        foreach ($cases as $case) {
            $deadline = CaseForms::planDeadline($case);
            if ($deadline['days_left'] === null || $deadline['days_left'] > 2) {
                continue;
            }
            $eligible++;
            $due = Carbon::parse($deadline['due'])->format('M j');
            if (!$deadline['overdue']) {
                $created += $this->createOnce((int) $case->assigned_to, 'plan_due_soon', 'Intervention Plan due soon', "{$case->case_number} - submit the Intervention Plan by {$due}", "/cases/{$case->id}?section=forms");

                continue;
            }
            $created += $this->createOnce((int) $case->assigned_to, 'plan_overdue', 'Intervention Plan overdue', "{$case->case_number} - the Intervention Plan was due {$due}", "/cases/{$case->id}?section=forms");
            foreach (Supervisors::forLgu($case->lgu_id) as $supervisor) {
                $created += $this->createOnce($supervisor, 'plan_overdue', 'Intervention Plan overdue', "{$case->case_number} - the Intervention Plan was due {$due}", "/cases/{$case->id}?section=forms");
            }
        }

        // Aftercare: remind the case manager on the day a follow-up visit is due (and once when past due).
        foreach (DB::table('cases')->where('status', 'CLOSED')->whereNull('deleted_at')->whereNotNull('assigned_to')
            ->whereExists(fn ($plan) => $plan->selectRaw('1')->from('case_aftercare_plans')->whereColumn('case_aftercare_plans.case_id', 'cases.id')->whereNotNull('case_aftercare_plans.living_arrangement'))
            ->get(['id', 'case_number', 'assigned_to']) as $case) {
            $next = Aftercare::forCase((int) $case->id)['next_due'];
            if (!$next || $next > $now->toDateString()) {
                continue;
            }
            $eligible++;
            $created += $this->createOnce((int) $case->assigned_to, 'aftercare_visit_due', 'Aftercare follow-up visit due', "{$case->case_number} - follow-up visit due " . Carbon::parse($next)->format('M j'), "/cases/{$case->id}?section=closure");
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

        app(OperationalNotification::class)->send($userId, $type, $title, $body, $actionUrl);

        return 1;
    }
}

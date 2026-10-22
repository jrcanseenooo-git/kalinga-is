<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeadlineAlertCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_one_alert_for_due_tasks_and_plan_reviews_without_duplicates(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $clientId = DB::table('clients')->insertGetId([
            'first_name' => 'Deadline', 'last_name' => 'Demo', 'region' => 'Cordillera Administrative Region (CAR)',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $caseId = DB::table('cases')->insertGetId([
            'case_number' => 'KLG-2026-DEADLINE-001', 'client_id' => $clientId, 'assigned_to' => $worker->id,
            'status' => 'IN_PROGRESS', 'stage' => 'assessment', 'priority' => 'normal', 'opened_at' => now()->subWeek(),
            'offline_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tasks')->insert([
            'case_id' => $caseId, 'assigned_to' => $worker->id, 'title' => 'Complete household visit',
            'due_at' => now()->subHour(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('case_plans')->insert([
            'case_id' => $caseId, 'summary' => 'Review support plan.', 'review_due_at' => now('Asia/Manila')->toDateString(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('kalinga:dispatch-deadline-alerts');
        Artisan::call('kalinga:dispatch-deadline-alerts');

        $this->assertSame(1, DB::table('notifications')->where('user_id', $worker->id)->where('type', 'task_overdue')->count());
        $this->assertSame(1, DB::table('notifications')->where('user_id', $worker->id)->where('type', 'plan_review_due')->count());
    }
}

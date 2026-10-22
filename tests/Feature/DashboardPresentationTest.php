<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_trend_and_upcoming_tasks_preserve_case_scope_and_philippine_month_boundary(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 18)->setTime(4, 0));
        $worker = User::factory()->create(['role' => 'social_worker']);
        $other = User::factory()->create(['role' => 'social_worker']);
        $lgu = $this->ensureNormalizedLguAssignment($worker);
        $cases = [];
        foreach ([$worker, $other] as $owner) {
            $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Dashboard', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
            $cases[] = DB::table('cases')->insertGetId(['case_number' => 'SYN-UI-' . $client, 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $owner->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'high', 'opened_at' => '2026-09-30 16:30:00', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($cases as $case) {
            DB::table('tasks')->insert(['case_id' => $case, 'assigned_to' => $worker->id, 'title' => 'Synthetic scoped schedule', 'due_at' => now()->addHour(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('tasks')->insert(['case_id' => null, 'assigned_to' => $worker->id, 'title' => 'Synthetic personal schedule', 'due_at' => now()->addHours(2), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->be($worker)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('intakeTrend', 6)->where('intakeTrend.5.month', '2026-10')->where('intakeTrend.5.total', 1)
            ->where('intakeTrend.4.total', 0)->has('caseStatuses', 1)->where('caseStatuses.0.total', 1)
            ->has('upcomingActivities', 2)->where('upcomingActivities.0.case_id', $cases[0])->where('upcomingActivities.1.case_id', null));
        DB::table('user_lgu_assignments')->where('user_id', $worker->id)->update(['active' => false]);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('intakeTrend.5.total', 0)->has('caseStatuses', 0)->has('upcomingActivities', 1)->where('upcomingActivities.0.case_id', null));
    }
}

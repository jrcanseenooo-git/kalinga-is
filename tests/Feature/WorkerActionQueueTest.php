<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkerActionQueueTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $other = User::factory()->create(['role' => 'social_worker']);
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'TEST-ACTIONS', 'name' => 'SyntheticTown', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_lgu_assignments')->insert(['user_id' => $worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $cases = [];
        foreach ([$worker, $other] as $index => $owner) {
            $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Client', 'municipality' => 'SyntheticTown', 'created_at' => now(), 'updated_at' => now()]);
            $cases[] = DB::table('cases')->insertGetId(['case_number' => 'SYNTHETIC-ACTION-' . $index, 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $owner->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$worker, $cases[0], $cases[1]];
    }

    private function task(User $worker, ?int $case, string $title, $due, string $status = 'open'): int
    {
        return DB::table('tasks')->insertGetId(['case_id' => $case, 'assigned_to' => $worker->id, 'title' => $title, 'due_at' => $due, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_action_queue_and_drill_down_are_scoped_ordered_and_use_recorded_deadlines(): void
    {
        [$worker, $own, $hidden] = $this->fixture();
        $this->task($worker, $own, 'Due soon', now()->addHours(2));
        $overdue = $this->task($worker, $own, 'Overdue action', now()->subHour());
        $this->task($worker, $hidden, 'Out of scope marker', now()->subDay());
        $this->task($worker, null, 'Personal task', now()->subHour());
        $this->task($worker, $own, 'Completed marker', now()->subHour(), 'completed');
        $this->be($worker);
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('actionQueue', 2)->where('actionQueue.0.task_id', $overdue)
            ->where('attention.overdueActions', 1)->where('attention.dueSoon', 1));
        // The task list now lives in the calendar.
        $this->get(route('tasks.index'))->assertRedirect(route('calendar.index'));
        $this->get(route('calendar.index'))->assertOk()->assertDontSee('Out of scope marker')->assertSee('Personal task')
            ->assertInertia(fn (Assert $page) => $page->has('availableCases', 1)->where('availableCases.0.id', $own)->where('summary.overdue', 2));
    }

    public function test_case_drill_down_validates_filters_and_keeps_authorization(): void
    {
        [$worker, $own] = $this->fixture();
        DB::table('cases')->where('id', $own)->update(['status' => 'FOR_FOLLOW_UP']);
        $this->be($worker);
        $this->get(route('cases.index', ['status' => 'FOR_FOLLOW_UP']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('cases.data', 1)->where('filters.status', 'FOR_FOLLOW_UP'));
        $this->getJson(route('cases.index', ['status' => 'invented']))->assertUnprocessable();
    }

    public function test_revoked_case_assignment_removes_linked_task_and_denies_transition(): void
    {
        [$worker, $own] = $this->fixture();
        $task = $this->task($worker, $own, 'Must be hidden after revocation', now()->addHour());
        DB::table('user_lgu_assignments')->where('user_id', $worker->id)->update(['active' => false]);
        $this->be($worker);
        $this->get(route('calendar.index'))->assertOk()->assertDontSee('Must be hidden after revocation')->assertInertia(fn (Assert $page) => $page->has('availableCases', 0));
        $this->patch(route('tasks.transition', $task), ['status' => 'completed'])->assertForbidden();
        $this->assertDatabaseHas('tasks', ['id' => $task, 'status' => 'open']);
    }

    public function test_case_list_priority_dates_and_sort_remain_within_current_scope(): void
    {
        [$worker, $own] = $this->fixture();
        $older = (array) DB::table('cases')->find($own);
        unset($older['id']);
        $older['case_number'] = 'SYNTHETIC-ACTION-OLDER';
        $older['priority'] = 'high';
        $older['opened_at'] = now()->subDays(2)->toDateTimeString();
        $olderId = DB::table('cases')->insertGetId($older);
        $this->be($worker);
        $this->get(route('cases.index', ['priority' => 'high', 'from' => now()->subDays(3)->toDateString(), 'to' => now()->subDay()->toDateString()]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('cases.data', 1)->where('cases.data.0.id', $olderId)->where('filters.priority', 'high'));
        $this->get(route('cases.index', ['sort' => 'opened_asc']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('cases.data', 2)->where('cases.data.0.id', $olderId));
        $this->getJson(route('cases.index', ['sort' => 'cases.id desc; DROP TABLE cases']))->assertUnprocessable();
        $this->getJson(route('cases.index', ['priority' => 'invented']))->assertUnprocessable();
        $this->getJson(route('cases.index', ['from' => '2026-10-17', 'to' => '2026-10-01']))->assertUnprocessable();
    }

    public function test_task_time_offsets_are_normalized_and_calendar_today_uses_philippine_boundaries(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17T01:00:00Z'));
        [$worker, $own] = $this->fixture();
        $this->be($worker)->post(route('tasks.store'), ['case_id' => $own, 'title' => 'Philippine tomorrow', 'due_at' => '2026-10-18T01:00:00+08:00'])->assertRedirect();
        $this->assertDatabaseHas('tasks', ['title' => 'Philippine tomorrow', 'due_at' => '2026-10-17 17:00:00']);
        $this->get(route('calendar.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('summary.dueToday', 0)->where('summary.overdue', 0)
            ->where('tasks.0.due_at', '2026-10-17T17:00:00+00:00'));
    }

    public function test_case_workspace_task_dates_and_report_capability_follow_server_authorization(): void
    {
        [$worker, $own] = $this->fixture();
        $this->task($worker, $own, 'Recorded case action', '2026-10-17 17:00:00');
        $this->be($worker)->get(route('cases.show', $own))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tasks.0.due_at', '2026-10-17T17:00:00+00:00')->where('canGenerateReport', true));
        DB::table('clients')->update(['province' => 'Kalinga']);
        $this->be(User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']))
            ->get(route('cases.show', $own))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canGenerateReport', false));
    }
}

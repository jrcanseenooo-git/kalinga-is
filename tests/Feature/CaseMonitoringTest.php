<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** LGU Head, Field Office and Project Manager monitoring: secured names, remarks, closure approval. */
class CaseMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private int $lgu;

    private int $otherLgu;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'MON-1', 'name' => 'Synthetic Tabuk', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->otherLgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'MON-2', 'name' => 'Synthetic Tacloban', 'province' => 'Leyte', 'region' => 'Region VIII', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->worker = User::factory()->create(['role' => 'social_worker', 'name' => 'Synthetic Case Manager', 'active' => true]);
        DB::table('user_lgu_assignments')->insert(['user_id' => $this->worker->id, 'lgu_id' => $this->lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function case(int $lgu, string $number, string $status = 'IN_PROGRESS', ?string $alias = null): int
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'John', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz', 'alias' => $alias, 'phone' => '09171234567', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => $number, 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $this->worker->id, 'status' => $status, 'priority' => 'normal',
            'category' => 'CEFMU', 'opened_at' => now(), 'offline_version' => 1, 'closure_summary' => $status === 'FOR_CLOSURE_REVIEW' ? 'Synthetic closure summary.' : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function lguHead(): User
    {
        $head = User::factory()->create(['role' => 'lgu_head', 'name' => 'Synthetic LGU Head']);
        DB::table('user_lgu_assignments')->insert(['user_id' => $head->id, 'lgu_id' => $this->lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);

        return $head;
    }

    public function test_project_manager_case_table_shows_secured_names_case_manager_region_and_status_only(): void
    {
        $own = $this->case($this->lgu, 'MON-CASE-1');
        $this->case($this->otherLgu, 'MON-CASE-2', 'OPEN', 'Ning');
        $focal = User::factory()->create(['role' => 'project_manager', 'region_scope' => ['CAR']]);

        $response = $this->actingAs($focal)->get(route('monitoring.cases.index'))->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Monitoring/Cases')->where('cases.total', 1)
            ->where('cases.data.0.name', 'J**S***D**UZ')->where('cases.data.0.case_manager', 'Synthetic Case Manager')
            ->where('cases.data.0.region', 'CAR')->where('cases.data.0.status', 'IN_PROGRESS')->where('scopeLabel', 'CAR'));
        $this->assertStringNotContainsString('Dela Cruz', $response->getContent());
        $this->assertStringNotContainsString('09171234567', $response->getContent());

        $national = User::factory()->create(['role' => 'project_manager', 'region_scope' => ['*']]);
        $this->actingAs($national)->get(route('monitoring.cases.index'))->assertInertia(fn ($page) => $page->where('cases.total', 2));
        $this->actingAs($national)->get(route('monitoring.cases.index', ['search' => 'Ning']))->assertInertia(fn ($page) => $page->where('cases.total', 1)->where('cases.data.0.name', 'Ning'));
        $this->actingAs($focal)->get(route('monitoring.cases.show', $own))->assertOk()->assertDontSee('Dela Cruz');
        $this->actingAs($focal)->get(route('cases.show', $own))->assertForbidden();
    }

    public function test_monitors_cannot_see_cases_outside_their_scope(): void
    {
        $outside = $this->case($this->otherLgu, 'MON-CASE-3');
        $fo = User::factory()->create(['role' => 'fo_user', 'region' => 'CAR']);
        $this->actingAs($fo)->get(route('monitoring.cases.show', $outside))->assertNotFound();
        $this->post(route('monitoring.cases.remarks.store', $outside), ['body' => 'Synthetic remark'])->assertNotFound();
        $this->actingAs($this->lguHead())->get(route('monitoring.cases.show', $outside))->assertNotFound();
        $this->actingAs($this->worker)->get(route('monitoring.cases.index'))->assertForbidden();
    }

    public function test_remark_reaches_the_case_manager_who_replies_and_marks_it_read(): void
    {
        $case = $this->case($this->lgu, 'MON-CASE-4');
        $head = $this->lguHead();
        $this->actingAs($head)->post(route('monitoring.cases.remarks.store', $case), ['body' => 'Please schedule the home visit this week.'])->assertRedirect();
        $remark = DB::table('case_remarks')->first();
        $this->assertSame('lgu_head', $remark->author_role);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->worker->id, 'type' => 'case.remark']);

        $this->actingAs($this->worker)->get(route('cases.show', $case))->assertOk()->assertInertia(fn ($page) => $page->where('remarks.0.body', 'Please schedule the home visit this week.'));
        $this->post(route('cases.remarks.reply', [$case, $remark->id]), ['body' => 'Scheduled for Thursday.'])->assertRedirect();
        $this->assertNotNull(DB::table('case_remarks')->where('id', $remark->id)->value('acknowledged_at'));
        $this->assertDatabaseHas('notifications', ['user_id' => $head->id, 'type' => 'case.remark_reply']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'case_remark.created', 'log_type' => 'data_creation']);
    }

    public function test_lgu_head_approves_or_returns_closure_but_holds_no_cases_and_writes_no_case_content(): void
    {
        $case = $this->case($this->lgu, 'MON-CASE-5', 'FOR_CLOSURE_REVIEW');
        $head = $this->lguHead();
        $this->actingAs($head)->post(route('monitoring.cases.closure.return', $case), ['reason' => 'Add the aftercare plan first.'])->assertRedirect();
        $this->assertDatabaseHas('cases', ['id' => $case, 'status' => 'IN_PROGRESS']);
        DB::table('cases')->where('id', $case)->update(['status' => 'FOR_CLOSURE_REVIEW']);
        $this->post(route('monitoring.cases.closure.approve', $case), ['note' => 'Goals achieved.'])->assertRedirect();
        $this->assertDatabaseHas('cases', ['id' => $case, 'status' => 'CLOSED', 'closure_reviewed_by' => $head->id]);

        $this->get(route('cases.create'))->assertForbidden();
        $this->post(route('cases.notes.store', $case), ['body' => 'Synthetic note'])->assertForbidden();
        $this->assertSame(0, DB::table('cases')->where('assigned_to', $head->id)->count());
        $fo = User::factory()->create(['role' => 'fo_user', 'region' => 'CAR']);
        $this->actingAs($fo)->post(route('monitoring.cases.closure.approve', $case))->assertForbidden();
    }
}

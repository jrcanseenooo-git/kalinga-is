<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CaseStatusAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Case status follows the recorded work (owner decision 2026-10-28). */
class CaseStatusAutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private int $case;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worker = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $lgu = $this->fixtureLgu('City of Tabuk', 'Kalinga', 'CAR');
        DB::table('user_lgu_assignments')->insert(['user_id' => $this->worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $client = DB::table('clients')->insertGetId(['first_name' => 'A', 'last_name' => 'B', 'municipality' => 'City of Tabuk', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        $this->case = DB::table('cases')->insertGetId(['case_number' => 'AUTO-1', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $this->worker->id, 'status' => 'OPEN', 'priority' => 'normal',
            'opened_at' => now(), 'offline_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function caseStatus(): string
    {
        return (string) DB::table('cases')->where('id', $this->case)->value('status');
    }

    private function plan(array $rows): void
    {
        DB::table('case_forms')->updateOrInsert(['case_id' => $this->case, 'form_type' => 'plan'], ['content' => json_encode(['rows' => $rows]), 'status' => 'draft', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_status_moves_with_the_work_and_never_by_hand(): void
    {
        $this->assertNull(CaseStatusAutomation::sync($this->case));
        $this->assertSame('OPEN', $this->caseStatus());

        // Recording work moves it to In progress (here through a normal write: a progress note).
        $this->plan([['problem' => 'Synthetic need', 'objective' => 'Synthetic objective']]);
        $this->assertSame('IN_PROGRESS', CaseStatusAutomation::sync($this->case, $this->worker->id));
        $this->assertDatabaseHas('case_status_history', ['case_id' => $this->case, 'from_status' => 'OPEN', 'to_status' => 'IN_PROGRESS']);

        $referral = DB::table('referrals')->insertGetId(['case_id' => $this->case, 'service_category' => 'Education', 'lawful_basis' => 'public_task', 'status' => 'SENT', 'created_by' => $this->worker->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('AWAITING_REFERRAL', CaseStatusAutomation::sync($this->case));
        DB::table('referrals')->where('id', $referral)->update(['status' => 'ACCEPTED']);
        $this->assertSame('FOR_FOLLOW_UP', CaseStatusAutomation::sync($this->case), 'Answered referral, plan row still open.');
        $this->plan([['problem' => 'Synthetic need', 'objective' => 'Synthetic objective', 'status' => 'done']]);
        $this->assertSame('IN_PROGRESS', CaseStatusAutomation::sync($this->case));
        $this->assertDatabaseHas('audit_logs', ['event' => 'case.status_automatic', 'auditable_id' => $this->case, 'new_value' => 'IN_PROGRESS']);

        // People cannot set the automatic statuses; only the closure steps.
        $this->actingAs($this->worker)->patch(route('cases.transition', $this->case), ['status' => 'AWAITING_REFERRAL'])->assertStatus(422);
        $this->patch(route('cases.transition', $this->case), ['status' => 'FOR_CLOSURE_REVIEW'])->assertRedirect();
        $this->assertSame('FOR_CLOSURE_REVIEW', $this->caseStatus());
        $this->assertNull(CaseStatusAutomation::sync($this->case), 'Closure review is never changed automatically.');
        $this->get(route('cases.show', $this->case))->assertInertia(fn ($page) => $page->where('caseRecord.status', 'FOR_CLOSURE_REVIEW')->where('transitions', ['CLOSED', 'IN_PROGRESS'])->where('statusInfo.automatic', false));
        $this->patch(route('cases.transition', $this->case), ['status' => 'IN_PROGRESS'])->assertRedirect();
    }

    public function test_a_write_to_the_case_updates_the_status_straight_away(): void
    {
        $this->actingAs($this->worker)->get(route('cases.show', $this->case))->assertInertia(fn ($page) => $page->where('caseRecord.status', 'OPEN')
            ->where('statusInfo.automatic', true)->where('transitions', ['FOR_CLOSURE_REVIEW']));
        $this->post(route('cases.kavat.baseline', $this->case), ['administered_at' => now('Asia/Manila')->toDateString(), 'empowerment_rate' => 50, 'safety_rate' => 50])->assertSessionHasNoErrors();
        $this->assertSame('IN_PROGRESS', $this->caseStatus());
    }
}

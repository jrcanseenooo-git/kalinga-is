<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanningWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $owner): int
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'Jo', 'last_name' => 'Reyes', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-PLAN-01', 'client_id' => $client, 'assigned_to' => $owner->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_assessment_and_plan_are_saved_for_assigned_worker(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($worker);

        $this->actingAs($worker)->post(route('cases.assessment.store', $case), ['summary' => 'Initial safety assessment completed.', 'responses' => ['safety' => 'stable'], 'status' => 'completed'])->assertRedirect();
        $this->assertDatabaseHas('assessments', ['case_id' => $case, 'status' => 'completed', 'version' => 1]);
        $this->actingAs($worker)->post(route('cases.plan.store', $case), ['summary' => 'Family support plan.', 'review_due_at' => '2026-10-15', 'interventions' => [['need' => 'Counselling', 'goal' => 'Improve wellbeing', 'service_category' => 'Psychosocial', 'target_date' => '2026-10-01']]])->assertRedirect();
        $this->assertDatabaseHas('case_plans', ['case_id' => $case, 'summary' => 'Family support plan.']);
        $this->assertDatabaseHas('interventions', ['need' => 'Counselling', 'goal' => 'Improve wellbeing']);
    }

    public function test_stale_assessment_and_read_only_access_are_rejected(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($worker);
        $this->actingAs($worker)->post(route('cases.assessment.store', $case), ['status' => 'draft', 'version' => 0]);
        $this->actingAs($worker)->postJson(route('cases.assessment.store', $case), ['status' => 'draft', 'version' => 0])->assertStatus(409);
        $viewer = User::factory()->create(['role' => 'cpu_member']);
        $this->actingAs($viewer)->post(route('cases.plan.store', $case), ['interventions' => []])->assertForbidden();
    }
}

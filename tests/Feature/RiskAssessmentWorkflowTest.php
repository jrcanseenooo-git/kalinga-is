<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RiskAssessmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $worker): int
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'Safety', 'last_name' => 'Child', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => 'KLG-RISK-' . uniqid(), 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'high', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_worker_can_retain_repeatable_finalized_risk_history_without_automatic_scoring(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($worker);
        $payload = ['assessment_type' => 'initial', 'assessed_at' => now()->format('Y-m-d H:i:s'), 'overall_risk_level' => 'high', 'immediate_protection_required' => true, 'safety_plan_status' => 'required', 'required_action' => 'Coordinate immediate safe placement.', 'responsible_person' => 'Case manager', 'next_reassessment_at' => now()->addDays(2)->format('Y-m-d H:i:s'), 'professional_rationale' => 'Professional assessment based on current information.', 'status' => 'final', 'factors' => [['domain' => 'Immediate danger', 'finding_level' => 'high_concern', 'is_protective_factor' => false], ['domain' => 'Trusted or safe adult', 'finding_level' => 'no_concern', 'is_protective_factor' => true]]];

        $this->actingAs($worker)->post(route('cases.risk-assessments.store', $case), $payload)->assertRedirect();
        $this->assertDatabaseHas('risk_assessments', ['case_id' => $case, 'overall_risk_level' => 'high', 'status' => 'final', 'finalized_by' => $worker->id]);
        $this->assertDatabaseCount('risk_assessment_factors', 2);
        $this->assertDatabaseHas('cases', ['id' => $case, 'current_risk_level' => 'high']);

        $payload['assessment_type'] = 'reassessment';
        $payload['overall_risk_level'] = 'moderate';
        $payload['immediate_protection_required'] = false;
        $this->actingAs($worker)->post(route('cases.risk-assessments.store', $case), $payload)->assertRedirect();
        $this->assertDatabaseCount('risk_assessments', 2);
        $this->assertDatabaseHas('cases', ['id' => $case, 'current_risk_level' => 'moderate']);

        $payload['assessment_type'] = 'other';
        $payload['overall_risk_level'] = 'critical';
        $payload['assessed_at'] = now()->subDay()->format('Y-m-d H:i:s');
        $this->actingAs($worker)->post(route('cases.risk-assessments.store', $case), $payload)->assertRedirect();
        $this->assertDatabaseHas('cases', ['id' => $case, 'current_risk_level' => 'moderate']);
        $this->get(route('cases.show', $case))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('caseRecord.current_risk_level', 'moderate'));
    }

    public function test_high_risk_requires_professional_action_and_unassigned_user_is_denied(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($worker);
        $payload = ['assessment_type' => 'emergency', 'assessed_at' => now()->format('Y-m-d H:i:s'), 'overall_risk_level' => 'critical', 'immediate_protection_required' => false, 'safety_plan_status' => 'required', 'status' => 'final'];
        $this->actingAs($worker)->post(route('cases.risk-assessments.store', $case), $payload)->assertRedirect()->assertSessionHasErrors('required_action');

        $viewer = User::factory()->create(['role' => 'cpu_member']);
        $this->actingAs($viewer)->post(route('cases.risk-assessments.store', $case), $payload + ['required_action' => 'Contact protection authority.'])->assertForbidden();
    }

    public function test_missing_high_risk_action_returns_recoverable_field_validation_without_saving(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($worker);
        $this->actingAs($worker)->postJson(route('cases.risk-assessments.store', $case), [
            'assessment_type' => 'emergency', 'assessed_at' => now()->format('Y-m-d H:i:s'),
            'overall_risk_level' => 'critical', 'immediate_protection_required' => false,
            'safety_plan_status' => 'required', 'status' => 'final',
        ])->assertUnprocessable()->assertJsonValidationErrors('required_action');
        $this->assertDatabaseCount('risk_assessments', 0);
        $this->assertDatabaseCount('risk_assessment_factors', 0);
    }
}

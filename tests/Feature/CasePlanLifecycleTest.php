<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticTrigger;
use Tests\TestCase;

class CasePlanLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $u, string $municipality = 'Tabuk'): int
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'Plan', 'last_name' => 'Child', 'municipality' => $municipality, 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => 'PLAN-' . uniqid(), 'client_id' => $client, 'assigned_to' => $u->id, 'status' => 'OPEN', 'stage' => 'assessment', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_new_intervention_accepts_only_an_available_provider_and_preserves_history(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $plan = $this->plan($worker, $case);
        $goal = $this->postJson(route('cases.plans.goals.store', [$case, $plan]), ['domain' => 'education', 'need_problem' => 'Synthetic need', 'goal_statement' => 'Synthetic goal', 'priority' => 'normal'])->assertCreated()->json('id');
        $provider = DB::table('service_providers')->insertGetId(['name' => 'Synthetic provider', 'service_type' => 'Education', 'active' => false, 'created_at' => now(), 'updated_at' => now()]);
        $body = ['case_plan_goal_id' => $goal, 'activity' => 'Synthetic agreed service', 'service_category' => 'Education', 'priority' => 'normal', 'service_provider_id' => $provider];
        $this->postJson(route('cases.plans.interventions.store', [$case, $plan]), $body)->assertUnprocessable()->assertJsonValidationErrors('service_provider_id');
        $this->assertDatabaseCount('interventions', 0);
        DB::table('service_providers')->where('id', $provider)->update(['active' => true]);
        $id = $this->postJson(route('cases.plans.interventions.store', [$case, $plan]), $body)->assertCreated()->json('id');
        DB::table('service_providers')->where('id', $provider)->update(['active' => false]);
        $this->getJson(route('cases.plans.index', $case))->assertOk()->assertJsonPath('plans.0.interventions.0.provider_name', 'Synthetic provider');
        $this->assertDatabaseHas('interventions', ['id' => $id, 'service_provider_id' => $provider]);
    }

    public function test_service_outcome_and_audit_commit_together(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $plan = $this->plan($worker, $case);
        $goal = $this->postJson(route('cases.plans.goals.store', [$case, $plan]), ['domain' => 'education', 'need_problem' => 'Synthetic need', 'goal_statement' => 'Synthetic goal', 'priority' => 'normal'])->assertCreated()->json('id');
        $intervention = $this->postJson(route('cases.plans.interventions.store', [$case, $plan]), ['case_plan_goal_id' => $goal, 'activity' => 'Synthetic service', 'service_category' => 'Education', 'priority' => 'normal'])->assertCreated()->json('id');
        DB::table('case_plans')->where('id', $plan)->update(['status' => 'active']);
        SyntheticTrigger::create("CREATE TRIGGER synthetic_outcome_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.event = 'case_plan.intervention_updated' BEGIN SELECT RAISE(ABORT, 'Synthetic outcome audit failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->patchJson(route('cases.plans.interventions.update', [$case, $plan, $intervention]), ['outcome_status' => 'achieved', 'actual_outcome' => 'Synthetic outcome.']);
            $this->fail('Audit failure must reject this outcome update.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic outcome audit failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('interventions', ['id' => $intervention, 'outcome_status' => null, 'actual_outcome' => null]);
    }

    private function plan(User $u, int $case, ?int $prior = null): int
    {
        $this->actingAs($u)->postJson(route('cases.plans.store', $case), ['plan_date' => today(), 'overall_objective' => 'Safe, continuous support', 'previous_plan_id' => $prior])->assertCreated();

        return DB::table('case_plans')->latest('id')->value('id');
    }

    public function test_plan_versions_goals_and_linked_task_keep_separate_outcome(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $supervisor = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $plan = $this->plan($worker, $case);
        $goal = $this->actingAs($worker)->postJson(route('cases.plans.goals.store', [$case, $plan]), ['domain' => 'education', 'need_problem' => 'Attendance barrier', 'goal_statement' => 'Sustain attendance', 'priority' => 'high'])->assertCreated()->json('id');
        $task = DB::table('tasks')->insertGetId(['case_id' => $case, 'assigned_to' => $worker->id, 'title' => 'School contact', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $intervention = $this->actingAs($worker)->postJson(route('cases.plans.interventions.store', [$case, $plan]), ['case_plan_goal_id' => $goal, 'activity' => 'Coordinate school contact', 'service_category' => 'Education', 'priority' => 'high', 'operational_record_type' => 'task', 'operational_record_id' => $task])->assertCreated()->json('id');
        $this->actingAs($worker)->patchJson(route('cases.plans.transition', [$case, $plan]), ['status' => 'for_review'])->assertNoContent();
        $this->actingAs($supervisor)->patchJson(route('cases.plans.transition', [$case, $plan]), ['status' => 'active'])->assertNoContent();
        $this->actingAs($worker)->patchJson(route('cases.plans.interventions.update', [$case, $plan, $intervention]), ['progress' => 'completed'])->assertStatus(422);
        $this->actingAs($worker)->patchJson(route('cases.plans.interventions.update', [$case, $plan, $intervention]), ['outcome_status' => 'partially_achieved'])->assertNoContent();
        $data = $this->actingAs($worker)->getJson(route('cases.plans.index', $case))->assertOk()->json('plans.0.interventions.0');
        $this->assertSame('open', $data['operational_status']);
        $this->assertSame('partially_achieved', DB::table('interventions')->where('id', $intervention)->value('outcome_status'));
        $this->actingAs($worker)->patchJson(route('cases.plans.transition', [$case, $plan]), ['status' => 'under_review'])->assertNoContent();
        $this->actingAs($supervisor)->postJson(route('cases.plans.reviews.store', [$case, $plan]), ['review_date' => today(), 'overall_progress' => 'delayed', 'revision_required' => true])->assertCreated();
        $new = $this->plan($worker, $case, $plan);
        $this->assertDatabaseHas('case_plans', ['id' => $plan, 'status' => 'under_review']);
        $this->actingAs($worker)->patchJson(route('cases.plans.transition', [$case, $new]), ['status' => 'for_review'])->assertNoContent();
        $this->actingAs($supervisor)->patchJson(route('cases.plans.transition', [$case, $new]), ['status' => 'active'])->assertNoContent();
        $this->assertDatabaseHas('case_plans', ['id' => $plan, 'status' => 'superseded']);
        $this->assertDatabaseHas('case_plans', ['id' => $new, 'previous_plan_id' => $plan, 'version' => 2]);
    }

    public function test_aftercare_reintegration_transfer_and_scope_are_conservative(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $receiver = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $outsider = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Other']);
        $case = $this->caseFor($worker);
        $lgu = $this->ensureNormalizedLguAssignment($worker);
        $this->ensureNormalizedLguAssignment($receiver, $lgu);
        $plan = $this->plan($worker, $case);
        $this->actingAs($worker)->postJson(route('cases.aftercare.store', $case), ['case_plan_id' => $plan, 'required' => false])->assertCreated();
        $this->actingAs($worker)->postJson(route('cases.reintegration.store', $case), ['case_plan_id' => $plan, 'applicable' => false])->assertCreated();
        $transfer = $this->actingAs($worker)->postJson(route('cases.transfers.store', $case), ['originating_office' => 'Tabuk', 'receiving_office' => 'Tabuk', 'receiving_worker_id' => $receiver->id, 'reason' => 'Move'])->assertCreated()->json('id');
        foreach (['requested', 'endorsed', 'accepted', 'completed'] as $status) {
            $this->actingAs($worker)->patchJson(route('cases.transfers.transition', [$case, $transfer]), ['status' => $status])->assertNoContent();
        }$this->assertSame($receiver->id, (int) DB::table('cases')->where('id', $case)->value('assigned_to'));
        $this->assertDatabaseCount('cases', 1);
        $this->actingAs($outsider)->getJson(route('cases.plans.index', $case))->assertForbidden();
    }

    public function test_cross_jurisdiction_transfer_is_denied_until_atomic_handoff_policy_is_approved(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $external = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Other']);
        $case = $this->caseFor($worker);
        $this->actingAs($worker)->postJson(route('cases.transfers.store', $case), ['originating_office' => 'Tabuk', 'receiving_office' => 'Other', 'receiving_worker_id' => $external->id, 'reason' => 'Move'])->assertStatus(422);
    }

    public function test_linked_operational_records_remain_authoritative_for_each_supported_type(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $supervisor = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $plan = $this->plan($worker, $case);
        $goal = $this->actingAs($worker)->postJson(route('cases.plans.goals.store', [$case, $plan]), ['domain' => 'education', 'need_problem' => 'Need', 'goal_statement' => 'Goal', 'priority' => 'normal'])->assertCreated()->json('id');
        $task = DB::table('tasks')->insertGetId(['case_id' => $case, 'assigned_to' => $worker->id, 'title' => 'Task', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $referral = DB::table('referrals')->insertGetId(['case_id' => $case, 'service_category' => 'Education', 'status' => 'SENT', 'created_by' => $worker->id, 'created_at' => now(), 'updated_at' => now()]);
        $grant = DB::table('mdt_case_access')->insertGetId(['case_id' => $case, 'user_id' => $worker->id, 'professional_role' => 'QA', 'organization' => 'LGU', 'purpose' => 'QA', 'allowed_categories' => '[]', 'status' => 'assigned', 'assigned_by' => $worker->id, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $mdt = DB::table('mdt_actions')->insertGetId(['case_id' => $case, 'mdt_access_id' => $grant, 'title' => 'Action', 'status' => 'responded', 'created_by' => $worker->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['task', $task, 'open'], ['referral', $referral, 'SENT'], ['mdt_action', $mdt, 'responded'], ['risk_reassessment_request', $task, 'open']] as [$type,$source,$status]) {
            $this->actingAs($worker)->postJson(route('cases.plans.interventions.store', [$case, $plan]), ['case_plan_goal_id' => $goal, 'activity' => 'Linked ' . $type, 'service_category' => 'Support', 'priority' => 'normal', 'operational_record_type' => $type, 'operational_record_id' => $source])->assertCreated();
        }$this->actingAs($worker)->patchJson(route('cases.plans.transition', [$case, $plan]), ['status' => 'for_review'])->assertNoContent();
        $this->actingAs($supervisor)->patchJson(route('cases.plans.transition', [$case, $plan]), ['status' => 'active'])->assertNoContent();
        $payload = $this->actingAs($worker)->getJson(route('cases.plans.index', $case))->assertOk()->json('plans.0.interventions');
        $this->assertSame(['open', 'SENT', 'responded', 'open'], collect($payload)->pluck('operational_status')->all());
        foreach ($payload as $row) {
            $this->actingAs($worker)->patchJson(route('cases.plans.interventions.update', [$case, $plan, $row['id']]), ['progress' => 'completed'])->assertStatus(422);
        }
    }

    public function test_returned_plan_can_be_revised_and_only_a_different_reviewer_can_activate(): void
    {
        $author = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $reviewer = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($author);
        $plan = $this->plan($author, $case);
        $this->actingAs($author)->patchJson(route('cases.plans.transition', [$case, $plan]), ['status' => 'for_review'])->assertNoContent();
        $this->actingAs($author)->patchJson(route('cases.plans.transition', [$case, $plan]), ['status' => 'active'])->assertStatus(422);
        $this->actingAs($reviewer)->patchJson(route('cases.plans.transition', [$case, $plan]), ['status' => 'returned', 'comments' => 'Specify the accountable office and target date.'])->assertNoContent();
        $this->assertDatabaseHas('case_plans', ['id' => $plan, 'status' => 'returned', 'returned_by' => $reviewer->id]);
        $this->actingAs($author)->postJson(route('cases.plans.goals.store', [$case, $plan]), ['domain' => 'family_strengthening', 'need_problem' => 'Need', 'goal_statement' => 'Goal', 'priority' => 'normal'])->assertCreated();
        $this->actingAs($author)->patchJson(route('cases.plans.transition', [$case, $plan]), ['status' => 'for_review'])->assertNoContent();
        $this->actingAs($reviewer)->patchJson(route('cases.plans.transition', [$case, $plan]), ['status' => 'active'])->assertNoContent();
        $this->assertDatabaseHas('case_plans', ['id' => $plan, 'status' => 'active', 'is_current' => true, 'reviewed_by' => $reviewer->id]);
    }

    public function test_closure_readiness_exposes_configured_blocker_and_warnings_without_closing_case(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        DB::table('cases')->where('id', $case)->update(['current_risk_level' => 'critical']);
        DB::table('tasks')->insert(['case_id' => $case, 'assigned_to' => $worker->id, 'title' => 'Open task', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $data = $this->actingAs($worker)->getJson(route('cases.closure-readiness.show', $case))->assertOk()->json();
        $this->assertSame('high_or_critical_risk', $data['critical_blockers'][0]['code']);
        $this->assertContains('open_tasks', collect($data['warnings'])->pluck('code')->all());
        $this->assertTrue($data['policy_validation_required']);
        $this->assertSame('OPEN', DB::table('cases')->where('id', $case)->value('status'));
    }

    public function test_plan_endpoint_requires_case_scope_and_finalized_source_references(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $outsider = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Other']);
        $case = $this->caseFor($owner);
        $this->actingAs($outsider)->getJson(route('cases.plans.index', $case))->assertForbidden();
        $draftRisk = DB::table('risk_assessments')->insertGetId(['case_id' => $case, 'assessment_type' => 'initial', 'assessed_at' => now(), 'overall_risk_level' => 'low', 'immediate_protection_required' => false, 'safety_plan_status' => 'not_required', 'status' => 'draft', 'assessed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->postJson(route('cases.plans.store', $case), ['plan_date' => today(), 'overall_objective' => 'Plan', 'risk_assessment_id' => $draftRisk])->assertStatus(422);
    }

    public function test_reopening_case_creates_a_new_draft_planning_cycle_without_reactivating_prior_plan(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $prior = $this->plan($worker, $case);
        DB::table('case_plans')->where('id', $prior)->update(['status' => 'completed', 'is_current' => false]);
        DB::table('cases')->where('id', $case)->update(['status' => 'CLOSED']);
        $this->actingAs($worker)->patch(route('cases.transition', $case), ['status' => 'REOPENED'])->assertRedirect();
        $new = DB::table('case_plans')->where('case_id', $case)->latest('id')->first();
        $this->assertSame('draft', $new->status);
        $this->assertSame($prior, (int) $new->previous_plan_id);
        $this->assertFalse((bool) $new->is_current);
        $this->assertSame('REOPENED', DB::table('cases')->where('id', $case)->value('status'));
    }

    public function test_overview_and_aggregate_surfaces_do_not_receive_plan_narrative(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $secret = 'PLAN-NARRATIVE-MUST-NOT-LEAK';
        DB::table('case_plans')->insert(['case_id' => $case, 'version' => 1, 'summary' => $secret, 'overall_objective' => $secret, 'status' => 'active', 'is_current' => true, 'authored_by' => $worker->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($worker)->get(route('cases.show', $case))->assertOk()->assertDontSee($secret)->assertSee('planSummary');
        $this->actingAs($worker)->get(route('dashboard'))->assertOk()->assertDontSee($secret);
        $this->actingAs($worker)->get(route('analytics.index'))->assertOk()->assertDontSee($secret);
        $this->actingAs($worker)->getJson(route('cases.plans.index', $case))->assertOk()->assertSee($secret);
    }

    public function test_finalized_reviews_are_append_only_and_do_not_overwrite_prior_review(): void
    {
        $author = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $reviewer = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($author);
        $plan = $this->plan($author, $case);
        DB::table('case_plans')->where('id', $plan)->update(['status' => 'under_review']);
        $first = $this->actingAs($reviewer)->postJson(route('cases.plans.reviews.store', [$case, $plan]), ['review_date' => today(), 'overall_progress' => 'delayed', 'recommendation' => 'Keep first record', 'revision_required' => true])->assertCreated()->json('id');
        $this->assertDatabaseHas('case_plan_reviews', ['id' => $first, 'recommendation' => 'Keep first record']);
        $second = $this->actingAs($reviewer)->postJson(route('cases.plans.reviews.store', [$case, $plan]), ['review_date' => today(), 'overall_progress' => 'on_track', 'recommendation' => 'Second independent review', 'revision_required' => true])->assertCreated()->json('id');
        $this->assertNotSame($first, $second);
        $this->assertDatabaseHas('case_plan_reviews', ['id' => $first, 'recommendation' => 'Keep first record']);
        $this->assertDatabaseCount('case_plan_reviews', 2);
        $this->actingAs($reviewer)->patchJson('/cases/' . $case . '/plans/' . $plan . '/reviews/' . $first, ['recommendation' => 'Silently changed'])->assertNotFound();
        $this->actingAs($reviewer)->patchJson(route('cases.plans.transition', [$case, $plan]), ['status' => 'completed'])->assertNoContent();
        $this->assertDatabaseHas('case_plans', ['id' => $plan, 'status' => 'completed']);
    }
}

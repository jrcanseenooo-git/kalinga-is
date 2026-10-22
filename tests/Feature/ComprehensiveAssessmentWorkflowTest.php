<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ComprehensiveAssessmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $assignee): int
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'Case', 'last_name' => 'Assessment', 'municipality' => 'Tabuk', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => 'KLG-COMP-' . uniqid(), 'client_id' => $client, 'assigned_to' => $assignee->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function createInitial(User $author, int $case): int
    {
        $this->actingAs($author)->post(route('cases.comprehensive-assessments.store', $case), ['assessment_date' => today()->toDateString(), 'record_type' => 'full_revision'])->assertRedirect();

        return (int) DB::table('case_assessments')->value('id');
    }

    public function test_case_workspace_recognizes_an_existing_initial_draft_without_exposing_section_content(): void
    {
        $author = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($author);
        $id = $this->createInitial($author, $case);
        DB::table('case_assessment_sections')->insert(['case_assessment_id' => $id, 'section_key' => 'presenting_situation', 'completion_state' => 'partially_completed', 'facts' => 'Synthetic assessment detail stays behind scoped retrieval.', 'restricted' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->get(route('cases.show', $case))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('assessmentSummary.id', $id)->where('assessmentSummary.status', 'draft')->missing('assessmentSummary.facts'));
    }

    public function test_json_assessment_writes_acknowledge_without_redirecting_mutation_methods_to_the_case_page(): void
    {
        $author = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($author);
        $this->actingAs($author)->postJson(route('cases.comprehensive-assessments.store', $case), ['assessment_date' => today()->toDateString(), 'record_type' => 'full_revision'])
            ->assertCreated()->assertJsonPath('status', 'draft');
        $id = (int) DB::table('case_assessments')->value('id');
        $this->putJson(route('cases.comprehensive-assessments.sections.save', [$case, $id]), ['section_key' => 'presenting_situation', 'completion_state' => 'completed', 'facts' => 'Synthetic JSON save.', 'restricted' => false])
            ->assertOk()->assertExactJson(['status' => 'saved']);
        $this->patchJson(route('cases.comprehensive-assessments.transition', [$case, $id]), ['status' => 'for_review'])
            ->assertOk()->assertExactJson(['status' => 'for_review']);
        $this->assertDatabaseHas('case_assessments', ['id' => $id, 'status' => 'for_review']);
    }

    public function test_sections_are_saved_independently_and_final_versions_are_immutable(): void
    {
        $author = User::factory()->create(['role' => 'social_worker']);
        $reviewer = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($author);
        $id = $this->createInitial($author, $case);
        $base = ['completion_state' => 'completed', 'restricted' => false];
        $this->actingAs($author)->put(route('cases.comprehensive-assessments.sections.save', [$case, $id]), $base + ['section_key' => 'presenting_situation', 'facts' => 'Verified facts'])->assertRedirect();
        $this->actingAs($author)->put(route('cases.comprehensive-assessments.sections.save', [$case, $id]), $base + ['section_key' => 'strengths_support', 'analysis' => 'Family strengths'])->assertRedirect();
        $this->assertDatabaseCount('case_assessment_sections', 2)->assertDatabaseHas('case_assessment_sections', ['case_assessment_id' => $id, 'section_key' => 'presenting_situation', 'facts' => 'Verified facts']);
        $this->actingAs($author)->patch(route('cases.comprehensive-assessments.transition', [$case, $id]), ['status' => 'for_review'])->assertRedirect();
        $this->actingAs($author)->patch(route('cases.comprehensive-assessments.transition', [$case, $id]), ['status' => 'reviewed'])->assertForbidden();
        $this->actingAs($reviewer)->patch(route('cases.comprehensive-assessments.transition', [$case, $id]), ['status' => 'reviewed', 'review_comments' => 'Ready to finalize'])->assertRedirect();
        $this->actingAs($author)->patch(route('cases.comprehensive-assessments.transition', [$case, $id]), ['status' => 'final'])->assertForbidden();
        $this->actingAs($reviewer)->patch(route('cases.comprehensive-assessments.transition', [$case, $id]), ['status' => 'final'])->assertRedirect();
        $this->assertDatabaseHas('case_assessments', ['id' => $id, 'status' => 'final', 'is_current' => true]);
        $this->actingAs($author)->put(route('cases.comprehensive-assessments.sections.save', [$case, $id]), $base + ['section_key' => 'presenting_situation', 'facts' => 'Changed'])->assertStatus(422);
    }

    public function test_return_history_and_new_full_version_preserve_finalized_source(): void
    {
        $author = User::factory()->create(['role' => 'social_worker']);
        $reviewer = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($author);
        $id = $this->createInitial($author, $case);
        $this->actingAs($author)->patch(route('cases.comprehensive-assessments.transition', [$case, $id]), ['status' => 'for_review'])->assertRedirect();
        $this->actingAs($reviewer)->patch(route('cases.comprehensive-assessments.transition', [$case, $id]), ['status' => 'returned_for_revision', 'review_comments' => 'Clarify source attribution'])->assertRedirect();
        $this->assertDatabaseHas('case_assessment_workflow_events', ['case_assessment_id' => $id, 'event' => 'returned_for_revision', 'comments' => 'Clarify source attribution']);
        $this->actingAs($author)->patch(route('cases.comprehensive-assessments.transition', [$case, $id]), ['status' => 'for_review'])->assertRedirect();
        $this->actingAs($reviewer)->patch(route('cases.comprehensive-assessments.transition', [$case, $id]), ['status' => 'reviewed'])->assertRedirect();
        $this->actingAs($author)->patch(route('cases.comprehensive-assessments.transition', [$case, $id]), ['status' => 'final'])->assertForbidden();
        $this->actingAs($reviewer)->patch(route('cases.comprehensive-assessments.transition', [$case, $id]), ['status' => 'final'])->assertRedirect();
        $this->actingAs($author)->post(route('cases.comprehensive-assessments.store', $case), ['assessment_date' => today()->toDateString(), 'previous_assessment_id' => $id, 'record_type' => 'full_revision', 'amendment_reason' => 'New verified information'])->assertRedirect();
        $this->assertDatabaseHas('case_assessments', ['case_id' => $case, 'previous_assessment_id' => $id, 'version' => 2, 'status' => 'draft', 'record_type' => 'full_revision']);
        $this->actingAs($author)->post(route('cases.comprehensive-assessments.store', $case), ['assessment_date' => today()->toDateString(), 'previous_assessment_id' => $id, 'record_type' => 'addendum', 'amendment_reason' => 'Supplemental professional note'])->assertRedirect();
        $addendum = (int) DB::table('case_assessments')->where('record_type', 'addendum')->value('id');
        $this->actingAs($author)->put(route('cases.comprehensive-assessments.sections.save', [$case, $addendum]), ['section_key' => 'strengths_support', 'completion_state' => 'completed', 'restricted' => false])->assertStatus(422);
    }

    public function test_restricted_section_response_is_redacted_for_a_user_without_the_dedicated_permission(): void
    {
        $author = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($author);
        $id = $this->createInitial($author, $case);
        DB::table('case_assessment_sections')->insert(['case_assessment_id' => $id, 'section_key' => 'health_srh', 'completion_state' => 'completed', 'facts' => 'Sensitive Health Narrative', 'restricted' => true, 'created_at' => now(), 'updated_at' => now()]);
        app()->instance(RolePermissions::class, new class extends RolePermissions
        {
            public function allows(User $user, string $permission): bool
            {
                return $permission === 'cases.view';
            }

            public function isFieldWorker(User $user): bool
            {
                return false;
            }
        });
        $reader = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $this->actingAs($reader)->getJson(route('cases.comprehensive-assessments.show', [$case, $id]))->assertOk()->assertJsonPath('sections.0.placeholder', 'Restricted section')->assertJsonMissing(['Sensitive Health Narrative']);
        $this->actingAs($reader)->getJson(route('cases.comprehensive-assessments.index', $case))->assertOk()->assertJsonMissing(['Sensitive Health Narrative']);
        $this->assertAuditNeverContains('Sensitive Health Narrative');
        $outsider = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Elsewhere']);
        $this->actingAs($outsider)->getJson(route('cases.comprehensive-assessments.show', [$case, $id]))->assertForbidden();
    }
}

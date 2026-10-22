<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChildParticipationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $assignee): int
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'Child', 'last_name' => 'Participant', 'municipality' => 'Tabuk', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => 'KLG-CP-' . uniqid(), 'client_id' => $client, 'assigned_to' => $assignee->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function create(User $author, int $case, string $title = 'Initial case plan', string $status = 'participated'): int
    {
        $payload = ['occasion_type' => 'initial_case_planning', 'decision_title' => $title, 'participation_date' => today()->toDateString(), 'participation_status' => $status, 'record_type' => 'formal_record'];
        if (!in_array($status, ['participated', 'partially_participated'])) {
            $payload['non_participation_reason'] = 'Neutral, recorded reason.';
        } $this->actingAs($author)->post(route('cases.child-participation.store', $case), $payload)->assertRedirect();

        return (int) DB::table('child_participation_records')->latest('id')->value('id');
    }

    public function test_json_participation_mutations_acknowledge_without_redirect_replay(): void
    {
        $author = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($author);
        $this->actingAs($author)->postJson(route('cases.child-participation.store', $case), ['occasion_type' => 'education', 'decision_title' => 'Synthetic education decision', 'participation_date' => today()->toDateString(), 'participation_status' => 'participated', 'record_type' => 'formal_record'])
            ->assertCreated()->assertJsonPath('status', 'draft');
        $id = (int) DB::table('child_participation_records')->value('id');
        $this->putJson(route('cases.child-participation.sections.save', [$case, $id]), ['section_key' => 'child_views', 'child_views' => 'Synthetic reported child view.'])
            ->assertOk()->assertExactJson(['status' => 'saved']);
        $this->patchJson(route('cases.child-participation.transition', [$case, $id]), ['status' => 'for_review'])
            ->assertOk()->assertExactJson(['status' => 'for_review']);
    }

    public function test_non_participation_reason_has_recoverable_field_validation_without_creating_record(): void
    {
        $author = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($author);
        $this->actingAs($author)->postJson(route('cases.child-participation.store', $case), ['occasion_type' => 'education', 'decision_title' => 'Synthetic education decision', 'participation_date' => today()->toDateString(), 'participation_status' => 'child_declined', 'record_type' => 'formal_record'])
            ->assertUnprocessable()->assertJsonValidationErrors('non_participation_reason');
        $this->assertDatabaseCount('child_participation_records', 0);
    }

    public function test_return_without_comments_has_field_error_and_keeps_review_state(): void
    {
        $author = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($author);
        $id = $this->create($author, $case);
        $this->patchJson(route('cases.child-participation.transition', [$case, $id]), ['status' => 'for_review'])->assertOk();
        $reviewer = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga', 'active' => true]);
        $this->be($reviewer);
        $this->patchJson(route('cases.child-participation.transition', [$case, $id]), ['status' => 'returned_for_revision'])
            ->assertUnprocessable()->assertJsonValidationErrors('review_comments');
        $this->assertDatabaseHas('child_participation_records', ['id' => $id, 'status' => 'for_review']);
        $this->assertDatabaseMissing('child_participation_workflow_events', ['child_participation_record_id' => $id, 'event' => 'returned_for_revision']);
    }

    public static function limitedParticipationFields(): array
    {
        return [
            ['participation_context', 'preferred_language', 120], ['participation_context', 'location_environment', 255],
            ['communication_accommodations', 'communication_method', 255], ['communication_accommodations', 'interpreter_or_support', 255],
            ['communication_accommodations', 'trusted_adult_or_support_person', 255], ['decision_under_consideration', 'decision_title', 255],
            ['feedback_to_child', 'feedback_by', 255], ['feedback_to_child', 'feedback_method_language', 255],
        ];
    }

    #[DataProvider('limitedParticipationFields')]
    public function test_section_validation_respects_existing_database_string_limits(string $section, string $field, int $limit): void
    {
        $author = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($author);
        $id = $this->create($author, $case);
        $this->putJson(route('cases.child-participation.sections.save', [$case, $id]), ['section_key' => $section, $field => str_repeat('x', $limit)])->assertOk();
        $this->putJson(route('cases.child-participation.sections.save', [$case, $id]), ['section_key' => $section, $field => str_repeat('x', $limit + 1)])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseHas('child_participation_records', ['id' => $id, $field => str_repeat('x', $limit)]);
    }

    public function test_multiple_occasions_statuses_independent_saves_and_final_immutability(): void
    {
        $author = User::factory()->create(['role' => 'social_worker']);
        $reviewer = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($author);
        $first = $this->create($author, $case);
        $second = $this->create($author, $case, 'Placement review', 'child_declined');
        $this->assertDatabaseCount('child_participation_records', 2)->assertDatabaseHas('child_participation_records', ['id' => $second, 'participation_status' => 'child_declined']);
        $this->actingAs($author)->put(route('cases.child-participation.sections.save', [$case, $first]), ['section_key' => 'child_views', 'child_views' => 'Child wants continuity'])->assertRedirect();
        $this->actingAs($author)->put(route('cases.child-participation.sections.save', [$case, $first]), ['section_key' => 'feedback_to_child', 'outcome_explained_to_child' => true, 'feedback_date' => today()->toDateString(), 'feedback_by' => 'Worker'])->assertRedirect();
        $this->assertDatabaseHas('child_participation_records', ['id' => $first, 'child_views' => 'Child wants continuity', 'outcome_explained_to_child' => true]);
        $this->actingAs($author)->patch(route('cases.child-participation.transition', [$case, $first]), ['status' => 'for_review'])->assertRedirect();
        $this->actingAs($author)->patch(route('cases.child-participation.transition', [$case, $first]), ['status' => 'reviewed'])->assertForbidden();
        $this->actingAs($reviewer)->patch(route('cases.child-participation.transition', [$case, $first]), ['status' => 'reviewed'])->assertRedirect();
        $this->actingAs($author)->patch(route('cases.child-participation.transition', [$case, $first]), ['status' => 'final'])->assertForbidden();
        $this->actingAs($reviewer)->patch(route('cases.child-participation.transition', [$case, $first]), ['status' => 'final'])->assertRedirect();
        $this->actingAs($author)->put(route('cases.child-participation.sections.save', [$case, $first]), ['section_key' => 'child_views', 'child_views' => 'Changed'])->assertStatus(422);
    }

    public function test_restricted_endpoint_index_history_and_audit_do_not_leak_narratives(): void
    {
        $author = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($author);
        $id = $this->create($author, $case);
        DB::table('child_participation_records')->where('id', $id)->update(['child_views' => 'Sensitive child narrative', 'review_comments' => 'Sensitive reviewer comment']);
        // Search, analytics, and notifications have no Child Participation join or narrative payload.
        $this->actingAs($author)->getJson('/search?q=Sensitive')->assertOk()->assertJsonMissing(['Sensitive child narrative']);
        $this->actingAs($author)->get('/analytics')->assertOk()->assertDontSee('Sensitive child narrative');
        $this->actingAs($author)->get('/notifications')->assertOk()->assertDontSee('Sensitive child narrative');
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
        $this->actingAs($reader)->getJson(route('cases.child-participation.show', [$case, $id]))->assertOk()->assertJsonPath('placeholder', 'Restricted Child Participation record')->assertJsonMissing(['Sensitive child narrative', 'Sensitive reviewer comment']);
        $this->actingAs($reader)->getJson(route('cases.child-participation.index', $case))->assertOk()->assertJsonMissing(['Sensitive child narrative', 'Initial case plan']);
        $this->assertAuditNeverContains('Sensitive child narrative');
        $mdtUser = User::factory()->create(['role' => 'cpu_member', 'municipality' => 'Tabuk']);
        $this->actingAs($mdtUser)->getJson(route('cases.child-participation.show', [$case, $id]))->assertForbidden();
    }

    public function test_revision_lineage_is_limited_to_the_same_finalized_occasion(): void
    {
        $author = User::factory()->create(['role' => 'social_worker']);
        $reviewer = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($author);
        $id = $this->create($author, $case);
        $this->actingAs($author)->patch(route('cases.child-participation.transition', [$case, $id]), ['status' => 'for_review']);
        $this->actingAs($reviewer)->patch(route('cases.child-participation.transition', [$case, $id]), ['status' => 'reviewed']);
        $this->actingAs($reviewer)->patch(route('cases.child-participation.transition', [$case, $id]), ['status' => 'final']);
        $this->actingAs($author)->post(route('cases.child-participation.store', $case), ['occasion_type' => 'initial_case_planning', 'decision_title' => 'Initial case plan', 'participation_date' => today()->toDateString(), 'participation_status' => 'participated', 'record_type' => 'full_revision', 'previous_record_id' => $id, 'amendment_reason' => 'Corrected information'])->assertRedirect();
        $this->assertDatabaseHas('child_participation_records', ['previous_record_id' => $id, 'version' => 2, 'record_type' => 'full_revision']);
        $this->actingAs($author)->post(route('cases.child-participation.store', $case), ['occasion_type' => 'closure', 'decision_title' => 'Closure review', 'participation_date' => today()->toDateString(), 'participation_status' => 'participated', 'record_type' => 'full_revision', 'previous_record_id' => $id, 'amendment_reason' => 'Wrong occasion'])->assertStatus(422);
    }
}

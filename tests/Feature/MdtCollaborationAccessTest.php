<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MdtCollaborationAccessTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $owner): int
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'Protected', 'last_name' => 'Child', 'municipality' => 'Tabuk', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => 'KLG-MDT-' . uniqid(), 'client_id' => $client, 'assigned_to' => $owner->id, 'status' => 'OPEN', 'stage' => 'assessment', 'priority' => 'high', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function grant(User $owner, User $member, int $case): int
    {
        $this->actingAs($owner)->postJson(route('cases.mdt.access.store', $case), ['user_id' => $member->id, 'professional_role' => 'Psychologist', 'organization' => 'LGU Health Office', 'purpose' => 'Provide a time-limited professional observation', 'allowed_categories' => ['case_summary', 'case_plan'], 'access_expires_at' => now()->addDays(7)->toIso8601String(), 'lawful_basis' => 'Case-management coordination'])->assertCreated();

        return (int) DB::table('mdt_case_access')->latest('id')->value('id');
    }

    public function test_mdt_member_cannot_browse_case_without_active_case_specific_grant(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $member = User::factory()->create(['role' => 'cpu_member']);
        $case = $this->caseFor($owner);
        $this->actingAs($member)->getJson(route('cases.mdt.show', $case))->assertForbidden();
        $this->actingAs($member)->get(route('cases.show', $case))->assertForbidden();
    }

    public function test_active_grant_only_exposes_allowed_metadata_and_not_child_participation_or_client_identity(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $member = User::factory()->create(['role' => 'cpu_member']);
        $case = $this->caseFor($owner);
        $grant = $this->grant($owner, $member, $case);
        $this->actingAs($member)->getJson(route('cases.mdt.show', $case))->assertOk()->assertJsonPath('case_number', DB::table('cases')->find($case)->case_number)->assertJsonPath('summary.priority', 'high')->assertJsonMissing(['Protected', 'Child', 'child_views', 'participation']);
        $this->actingAs($member)->get(route('cases.show', $case))->assertForbidden();
        $this->actingAs($member)->patchJson(route('cases.mdt.access.acknowledge', [$case, $grant]))->assertNoContent();
        $this->assertDatabaseHas('mdt_case_access', ['id' => $grant, 'status' => 'received']);
    }

    public function test_revocation_immediately_blocks_endpoint_discussion_and_note_creation(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $member = User::factory()->create(['role' => 'cpu_member']);
        $case = $this->caseFor($owner);
        $grant = $this->grant($owner, $member, $case);
        $this->actingAs($member)->postJson(route('cases.mdt.discussion.store', $case), ['body' => 'Professional coordination only.'])->assertCreated();
        $this->actingAs($member)->postJson(route('cases.mdt.notes.store', $case), ['category' => 'Professional observation', 'findings' => 'Relevant finding.'])->assertCreated();
        $this->actingAs($owner)->deleteJson(route('cases.mdt.access.revoke', [$case, $grant]))->assertNoContent();
        $this->actingAs($member)->getJson(route('cases.mdt.show', $case))->assertForbidden();
        $this->actingAs($member)->postJson(route('cases.mdt.discussion.store', $case), ['body' => 'Must not be accepted.'])->assertForbidden();
        $this->actingAs($member)->postJson(route('cases.mdt.notes.store', $case), ['category' => 'Observation'])->assertForbidden();
    }

    public function test_only_the_assigned_mdt_member_can_respond_to_their_action(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $member = User::factory()->create(['role' => 'cpu_member']);
        $other = User::factory()->create(['role' => 'cpu_member']);
        $case = $this->caseFor($owner);
        $grant = $this->grant($owner, $member, $case);
        $this->actingAs($owner)->postJson(route('cases.mdt.actions.store', $case), ['mdt_access_id' => $grant, 'title' => 'Provide professional observation', 'due_at' => now()->addDay()->toIso8601String()])->assertCreated();
        $action = (int) DB::table('mdt_actions')->latest('id')->value('id');
        $this->actingAs($other)->patchJson(route('cases.mdt.actions.respond', [$case, $action]), ['status' => 'responded'])->assertForbidden();
        $this->actingAs($member)->patchJson(route('cases.mdt.actions.respond', [$case, $action]), ['status' => 'completed'])->assertNoContent();
        $this->assertDatabaseHas('mdt_actions', ['id' => $action, 'status' => 'completed']);
    }

    public function test_manager_can_read_discussion_but_another_jurisdiction_cannot(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $member = User::factory()->create(['role' => 'cpu_member']);
        $outsider = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Other']);
        $case = $this->caseFor($owner);
        $this->grant($owner, $member, $case);
        $this->actingAs($member)->postJson(route('cases.mdt.discussion.store', $case), ['body' => 'Minimum necessary coordination.'])->assertCreated();
        $this->actingAs($owner)->getJson(route('cases.mdt.discussion.index', $case))->assertOk()->assertJsonFragment(['body' => 'Minimum necessary coordination.']);
        $this->actingAs($outsider)->getJson(route('cases.mdt.discussion.index', $case))->assertForbidden();
    }

    public function test_expired_deactivated_and_closed_cases_lose_mdt_capability(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $member = User::factory()->create(['role' => 'cpu_member']);
        $case = $this->caseFor($owner);
        $grant = $this->grant($owner, $member, $case);
        DB::table('mdt_case_access')->where('id', $grant)->update(['access_expires_at' => now()->subMinute()]);
        $this->actingAs($member)->getJson(route('cases.mdt.show', $case))->assertForbidden();
        DB::table('mdt_case_access')->where('id', $grant)->update(['access_expires_at' => now()->addDay()]);
        DB::table('users')->where('id', $member->id)->update(['active' => false]);
        $this->actingAs($member)->postJson(route('cases.mdt.discussion.store', $case), ['body' => 'No access'])->assertForbidden();
        DB::table('users')->where('id', $member->id)->update(['active' => true]);
        DB::table('cases')->where('id', $case)->update(['status' => 'CLOSED']);
        $this->actingAs($member)->postJson(route('cases.mdt.notes.store', $case), ['category' => 'Observation'])->assertForbidden();
    }

    public function test_final_note_is_immutable_addendum_preserves_original_and_attachment_is_grant_scoped(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'social_worker']);
        $member = User::factory()->create(['role' => 'cpu_member']);
        $other = User::factory()->create(['role' => 'cpu_member']);
        $case = $this->caseFor($owner);
        $grant = $this->grant($owner, $member, $case);
        $this->actingAs($member)->postJson(route('cases.mdt.notes.store', $case), ['category' => 'Observation', 'findings' => 'Original finding'])->assertCreated();
        $note = (int) DB::table('mdt_case_notes')->latest('id')->value('id');
        $this->actingAs($member)->patchJson(route('cases.mdt.notes.finalize', [$case, $note]))->assertNoContent();
        $this->actingAs($member)->postJson(route('cases.mdt.notes.addendum', [$case, $note]), ['reason' => 'Later clarification', 'category' => 'Observation', 'findings' => 'Clarification'])->assertCreated();
        $this->assertDatabaseHas('mdt_case_notes', ['id' => $note, 'findings' => 'Original finding', 'status' => 'final']);
        $this->assertDatabaseHas('mdt_case_notes', ['previous_note_id' => $note, 'record_type' => 'addendum', 'addendum_reason' => 'Later clarification']);
        $this->actingAs($member)->postJson(route('cases.mdt.attachments.store', $case), ['purpose_category' => 'supporting_record', 'mdt_note_id' => $note, 'attachment' => UploadedFile::fake()->create('support.pdf', 100, 'application/pdf')])->assertCreated();
        $file = (int) DB::table('mdt_attachments')->latest('id')->value('id');
        $this->actingAs($other)->get(route('cases.mdt.attachments.download', [$case, $file]))->assertForbidden();
    }
}

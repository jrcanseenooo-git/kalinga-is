<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CpuAssignmentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $cpu = User::factory()->create(['role' => 'cpu_member']);
        $other = User::factory()->create(['role' => 'cpu_member']);
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'SYN-CPU-VIEW', 'name' => 'Synthetic CPU LGU', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$owner, $cpu, $other] as $user) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $lgu, 'effective_from' => now()->subDay(), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $client = DB::table('clients')->insertGetId(['first_name' => 'Protected identity sentinel', 'last_name' => 'Synthetic child', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYN-CPU-VIEW', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $owner->id, 'status' => 'FOR_FOLLOW_UP', 'stage' => 'monitoring', 'priority' => 'high', 'category' => 'Synthetic category', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $grants = [];
        foreach ([$cpu, $cpu, $other] as $index => $user) {
            $grants[] = DB::table('mdt_case_access')->insertGetId(['case_id' => $case, 'user_id' => $user->id, 'professional_role' => 'CPU counterpart', 'organization' => 'Synthetic office', 'purpose' => 'Synthetic referral purpose ' . $index, 'remarks' => 'Synthetic referral instructions ' . $index, 'allowed_categories' => json_encode($index === 0 ? ['case_summary'] : ['case_stage']), 'status' => $index === 0 ? 'action_required' : 'assigned', 'assigned_by' => $owner->id, 'assigned_at' => now(), 'access_starts_at' => now()->subDay(), 'access_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        [$old, $new, $foreign] = $grants;
        $action = DB::table('mdt_actions')->insertGetId(['case_id' => $case, 'mdt_access_id' => $old, 'title' => 'Synthetic requested health coordination', 'instructions' => 'Synthetic detailed action instructions.', 'status' => 'action_required', 'created_by' => $owner->id, 'due_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        return compact('owner', 'cpu', 'other', 'case', 'old', 'new', 'foreign', 'action');
    }

    public function test_default_prioritizes_pending_work_and_explicit_selection_preserves_its_scope(): void
    {
        extract($this->context());
        $this->actingAs($cpu)->getJson(route('cases.mdt.show', $case))->assertOk()->assertJsonPath('grant.id', $old)
            ->assertJsonPath('grant.status', 'action_required')->assertJsonPath('status', 'FOR_FOLLOW_UP')
            ->assertJsonPath('actions.0.instructions', 'Synthetic detailed action instructions.')
            ->assertJsonPath('referrer.name', $owner->name)->assertJsonCount(2, 'assignments')
            ->assertJsonPath('summary.category', 'Synthetic category')->assertDontSee('Protected identity sentinel');
        $this->getJson(route('cases.mdt.show', ['case' => $case, 'mdt_access_id' => $new]))->assertOk()->assertJsonPath('grant.id', $new)->assertJsonCount(0, 'actions')->assertJsonMissingPath('summary');
        $this->get(route('cases.mdt.workspace', ['case' => $case, 'mdt_access_id' => $old]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Mdt/CaseWorkspace')->where('assignmentId', $old));
        $this->get(route('mdt.collaboration.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('assignments', 2)->where('assignments.0.pending_actions', 1));
    }

    public function test_selected_old_assignment_receives_notes_and_action_responses_without_modifying_new_assignment(): void
    {
        extract($this->context());
        $id = $this->actingAs($cpu)->postJson(route('cases.mdt.notes.store', $case), ['mdt_access_id' => $old, 'category' => 'Synthetic category', 'findings' => 'Synthetic professional findings'])->assertCreated()->json('id');
        $this->assertDatabaseHas('mdt_case_notes', ['id' => $id, 'mdt_access_id' => $old]);
        $this->patchJson(route('cases.mdt.notes.finalize', [$case, $id]), ['mdt_access_id' => $new])->assertForbidden();
        $this->patchJson(route('cases.mdt.notes.finalize', [$case, $id]), ['mdt_access_id' => $old])->assertNoContent();
        $this->patchJson(route('cases.mdt.actions.respond', [$case, $action]), ['mdt_access_id' => $new, 'status' => 'responded'])->assertForbidden();
        $this->patchJson(route('cases.mdt.actions.respond', [$case, $action]), ['mdt_access_id' => $old, 'status' => 'responded'])->assertNoContent();
        $this->assertDatabaseHas('mdt_case_access', ['id' => $new, 'status' => 'assigned']);
        $this->assertDatabaseHas('mdt_case_access', ['id' => $old, 'status' => 'responded']);
    }

    public function test_foreign_and_revoked_selection_never_fall_back_to_another_active_assignment(): void
    {
        extract($this->context());
        $this->actingAs($cpu)->getJson(route('cases.mdt.show', ['case' => $case, 'mdt_access_id' => $foreign]))->assertForbidden();
        $this->postJson(route('cases.mdt.notes.store', $case), ['mdt_access_id' => $foreign, 'category' => 'Denied'])->assertForbidden();
        DB::table('mdt_case_access')->where('id', $old)->update(['revoked_at' => now(), 'status' => 'revoked']);
        $this->getJson(route('cases.mdt.show', ['case' => $case, 'mdt_access_id' => $old]))->assertForbidden();
        $this->postJson(route('cases.mdt.notes.store', $case), ['mdt_access_id' => $old, 'category' => 'Denied'])->assertForbidden();
        $this->getJson(route('cases.mdt.show', ['case' => $case, 'mdt_access_id' => 'invalid']))->assertUnprocessable();
        $this->assertDatabaseCount('mdt_case_notes', 0);
    }

    public function test_another_cpu_cannot_see_the_older_assignment_actions_or_referral_note(): void
    {
        extract($this->context());
        $this->actingAs($other)->getJson(route('cases.mdt.show', $case))->assertOk()->assertJsonPath('grant.id', $foreign)
            ->assertJsonCount(1, 'assignments')->assertJsonCount(0, 'actions')->assertDontSee('Synthetic detailed action instructions.')->assertDontSee('Synthetic referral instructions 0');
    }

    public function test_attachments_stay_with_the_selected_assignment_and_scan_gate(): void
    {
        extract($this->context());
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $id = $this->actingAs($cpu)->postJson(route('cases.mdt.attachments.store', $case), [
            'mdt_access_id' => $old, 'purpose_category' => 'supporting_record', 'mdt_action_id' => $action,
            'attachment' => UploadedFile::fake()->create('synthetic-support.pdf', 10, 'application/pdf'),
        ])->assertCreated()->json('id');
        $this->assertDatabaseHas('mdt_attachments', ['id' => $id, 'mdt_access_id' => $old, 'scan_state' => 'pending_scan']);
        $this->get(route('cases.mdt.attachments.download', ['case' => $case, 'attachment' => $id, 'mdt_access_id' => $new]))->assertNotFound();
        $this->get(route('cases.mdt.attachments.download', ['case' => $case, 'attachment' => $id, 'mdt_access_id' => $old]))->assertStatus(423);
        $this->postJson(route('cases.mdt.attachments.store', $case), ['mdt_access_id' => $new, 'purpose_category' => 'supporting_record', 'mdt_action_id' => $action, 'attachment' => UploadedFile::fake()->create('synthetic-denied.pdf', 10, 'application/pdf')])->assertUnprocessable();
        $this->assertDatabaseCount('mdt_attachments', 1);
        $this->getJson(route('cases.mdt.show', ['case' => $case, 'mdt_access_id' => $new]))->assertOk()->assertJsonCount(0, 'attachments');
    }
}

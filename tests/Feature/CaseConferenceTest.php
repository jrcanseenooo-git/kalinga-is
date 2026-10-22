<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CaseConferenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeCase(User $u): int
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'Protected', 'last_name' => 'Child', 'municipality' => 'Tabuk', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => 'CC-' . uniqid(), 'client_id' => $client, 'assigned_to' => $u->id, 'status' => 'OPEN', 'stage' => 'assessment', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function draft(User $u, int $case): int
    {
        $this->actingAs($u)->postJson(route('cases.conferences.store', $case), ['conference_type' => 'initial'])->assertCreated();

        return DB::table('case_conferences')->latest('id')->value('id');
    }

    public function test_scope_and_external_participant_are_enforced(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $outsider = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Other']);
        $case = $this->makeCase($owner);
        $id = $this->draft($owner, $case);
        $this->actingAs($owner)->postJson(route('cases.conferences.participants.store', [$case, $id]), ['name' => 'External caregiver', 'attendance_status' => 'attended'])->assertCreated();
        $this->assertDatabaseHas('case_conference_participants', ['name' => 'External caregiver', 'user_id' => null]);
        $this->actingAs($outsider)->getJson(route('cases.conferences.show', [$case, $id]))->assertForbidden();
    }

    public function test_return_reschedule_final_immutability_and_addendum(): void
    {
        $author = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $reviewer = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->makeCase($author);
        $id = $this->draft($author, $case);
        $this->actingAs($author)->postJson(route('cases.conferences.reschedule', [$case, $id]), ['scheduled_at' => now()->addDay(), 'reason' => 'Availability'])->assertNoContent();
        $this->actingAs($author)->patchJson(route('cases.conferences.transition', [$case, $id]), ['status' => 'for_review'])->assertNoContent();
        $this->actingAs($reviewer)->patchJson(route('cases.conferences.transition', [$case, $id]), ['status' => 'returned_for_revision', 'comments' => 'Clarify actions'])->assertNoContent();
        $this->actingAs($author)->patchJson(route('cases.conferences.transition', [$case, $id]), ['status' => 'for_review'])->assertNoContent();
        $this->actingAs($reviewer)->patchJson(route('cases.conferences.transition', [$case, $id]), ['status' => 'final'])->assertNoContent();
        $this->actingAs($author)->patchJson(route('cases.conferences.update', [$case, $id]), ['key_issues' => 'No edit'])->assertForbidden();
        $this->actingAs($author)->postJson(route('cases.conferences.addendum', [$case, $id]), ['reason' => 'Correction'])->assertCreated();
        $this->assertDatabaseHas('case_conference_history', ['case_conference_id' => $id, 'event' => 'rescheduled']);
        $this->assertDatabaseHas('case_conferences', ['previous_conference_id' => $id, 'record_type' => 'addendum']);
    }

    public function test_closed_case_and_linked_task_remain_authoritative(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->makeCase($owner);
        DB::table('cases')->where('id', $case)->update(['status' => 'CLOSED']);
        $this->actingAs($owner)->postJson(route('cases.conferences.store', $case), ['conference_type' => 'initial'])->assertStatus(422);
        DB::table('cases')->where('id', $case)->update(['status' => 'OPEN']);
        $id = $this->draft($owner, $case);
        $task = DB::table('tasks')->insertGetId(['case_id' => $case, 'assigned_to' => $owner->id, 'title' => 'Visit', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->postJson(route('cases.conferences.actions.store', [$case, $id]), ['action' => 'Complete visit', 'priority' => 'normal', 'source_type' => 'task', 'source_id' => $task])->assertCreated();
        $this->assertDatabaseHas('case_conference_actions', ['source_type' => 'task', 'source_id' => $task]);
        $this->assertDatabaseHas('tasks', ['id' => $task, 'status' => 'open']);
    }

    public function test_referral_intervention_mdt_and_reassessment_links_keep_operational_status(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->makeCase($owner);
        $conference = $this->draft($owner, $case);
        $referral = DB::table('referrals')->insertGetId(['case_id' => $case, 'service_category' => 'Health', 'status' => 'SENT', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $plan = DB::table('case_plans')->insertGetId(['case_id' => $case, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $intervention = DB::table('interventions')->insertGetId(['case_plan_id' => $plan, 'need' => 'Need', 'goal' => 'Goal', 'service_category' => 'Support', 'progress' => 'in_progress', 'created_at' => now(), 'updated_at' => now()]);
        $grant = DB::table('mdt_case_access')->insertGetId(['case_id' => $case, 'user_id' => $owner->id, 'professional_role' => 'QA', 'organization' => 'LGU', 'purpose' => 'QA', 'allowed_categories' => '[]', 'status' => 'assigned', 'assigned_by' => $owner->id, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $mdt = DB::table('mdt_actions')->insertGetId(['case_id' => $case, 'mdt_access_id' => $grant, 'title' => 'MDT', 'status' => 'responded', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $riskTask = DB::table('tasks')->insertGetId(['case_id' => $case, 'assigned_to' => $owner->id, 'title' => 'Reassess', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['referral', $referral, 'SENT'], ['intervention', $intervention, 'in_progress'], ['mdt_action', $mdt, 'responded'], ['risk_reassessment_request', $riskTask, 'open']] as [$type,$source,$status]) {
            $this->actingAs($owner)->postJson(route('cases.conferences.actions.store', [$case, $conference]), ['action' => 'Linked ' . $type, 'priority' => 'normal', 'source_type' => $type, 'source_id' => $source])->assertCreated();
        }$payload = $this->actingAs($owner)->getJson(route('cases.conferences.show', [$case, $conference]))->assertOk()->json('actions');
        $this->assertSame(['SENT', 'in_progress', 'responded', 'open'], collect($payload)->pluck('operational_status')->all());
    }

    public function test_conference_privacy_boundaries_exclude_restricted_records_and_narratives(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->makeCase($owner);
        $conference = $this->draft($owner, $case);
        $conferenceNarrative = 'CONFERENCE-NARRATIVE-MUST-NOT-LEAK';
        $childNarrative = 'CHILD-PARTICIPATION-NARRATIVE-MUST-NOT-LEAK';
        $healthNarrative = 'HEALTH-SRH-NARRATIVE-MUST-NOT-LEAK';
        $child = DB::table('child_participation_records')->insertGetId(['case_id' => $case, 'lineage_key' => (string) Str::uuid(), 'version' => 1, 'record_type' => 'formal_record', 'occasion_type' => 'initial_case_planning', 'decision_title' => 'Protected decision', 'participation_date' => today(), 'participation_status' => 'participated', 'status' => 'final', 'authored_by' => $owner->id, 'finalized_by' => $owner->id, 'finalized_at' => now(), 'is_current' => true, 'child_views' => $childNarrative, 'created_at' => now(), 'updated_at' => now()]);
        $assessment = DB::table('case_assessments')->insertGetId(['case_id' => $case, 'version' => 1, 'assessment_date' => today(), 'status' => 'final', 'authored_by' => $owner->id, 'finalized_by' => $owner->id, 'finalized_at' => now(), 'is_current' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('case_assessment_sections')->insert(['case_assessment_id' => $assessment, 'section_key' => 'health_srh', 'completion_state' => 'completed', 'facts' => $healthNarrative, 'restricted' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->patchJson(route('cases.conferences.update', [$case, $conference]), ['case_updates' => $conferenceNarrative, 'child_participation_record_id' => $child])->assertNoContent();
        $show = $this->actingAs($owner)->getJson(route('cases.conferences.show', [$case, $conference]))->assertOk();
        $show->assertDontSee($childNarrative)->assertDontSee($healthNarrative)->assertSee($conferenceNarrative);
        $this->actingAs($owner)->getJson(route('search', ['q' => $conferenceNarrative]))->assertOk()->assertDontSee($conferenceNarrative);
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertDontSee($conferenceNarrative)->assertDontSee($childNarrative)->assertDontSee($healthNarrative);
        $this->actingAs($owner)->get(route('analytics.index'))->assertOk()->assertDontSee($conferenceNarrative)->assertDontSee($childNarrative)->assertDontSee($healthNarrative);
        $this->actingAs($owner)->get(route('cases.show', $case))->assertOk()->assertDontSee($conferenceNarrative)->assertDontSee($childNarrative)->assertDontSee($healthNarrative)->assertSee('conferenceSummary')->assertSee('conferenceActionSummary');
        $this->assertDatabaseMissing('notifications', ['body' => $conferenceNarrative]);
        $this->assertAuditChanges([], ['case_updates' => $conferenceNarrative], false);
        $audit = DB::table('audit_logs')->where('auditable_type', 'case_conference')->get();
        $this->assertNotEmpty($audit);
        $this->assertStringNotContainsString($conferenceNarrative, $audit->pluck('changes')->implode(' '));
        $this->assertStringNotContainsString($childNarrative, $audit->pluck('changes')->implode(' '));
        $this->assertStringNotContainsString($healthNarrative, $audit->pluck('changes')->implode(' '));
    }
}

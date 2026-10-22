<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyntheticTrigger;
use Tests\TestCase;

class ConferenceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $owner = User::factory()->create(['role' => 'social_worker', 'province' => 'Kalinga']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Conference', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYN-CONFERENCE-1', 'client_id' => $client, 'assigned_to' => $owner->id, 'status' => 'OPEN', 'stage' => 'assessment', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $case, $client];
    }

    public function test_risk_reassessment_action_cannot_link_or_read_another_cases_task(): void
    {
        [$owner, $case, $client] = $this->context();
        $this->actingAs($owner)->postJson(route('cases.conferences.store', $case), ['conference_type' => 'initial'])->assertCreated();
        $conference = DB::table('case_conferences')->value('id');
        $other = DB::table('cases')->insertGetId(['case_number' => 'SYN-CONFERENCE-2', 'client_id' => $client, 'assigned_to' => $owner->id, 'status' => 'OPEN', 'stage' => 'assessment', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $task = DB::table('tasks')->insertGetId(['case_id' => $other, 'assigned_to' => $owner->id, 'title' => 'Synthetic other case task', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson(route('cases.conferences.index', $case))->assertOk()->assertJsonCount(0, 'sources.task')->assertDontSee('Synthetic other case task');
        $this->postJson(route('cases.conferences.actions.store', [$case, $conference]), ['action' => 'Synthetic reassessment', 'priority' => 'normal', 'source_type' => 'risk_reassessment_request', 'source_id' => $task])->assertUnprocessable();
        // Historical invalid links must also fail closed on read.
        DB::table('case_conference_actions')->insert(['case_conference_id' => $conference, 'action' => 'Synthetic legacy link', 'priority' => 'normal', 'local_status' => 'pending', 'source_type' => 'risk_reassessment_request', 'source_id' => $task, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson(route('cases.conferences.show', [$case, $conference]))->assertOk()->assertJsonPath('actions.0.operational_status', null);
    }

    public function test_conference_creation_history_and_audit_are_atomic(): void
    {
        [$owner, $case] = $this->context();
        $this->actingAs($owner);
        SyntheticTrigger::create("CREATE TRIGGER synthetic_conference_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.event = 'conference.created' BEGIN SELECT RAISE(ABORT, 'Synthetic conference audit failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->postJson(route('cases.conferences.store', $case), ['conference_type' => 'initial']);
            $this->fail('Audit failure must reject creation.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic conference audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('case_conferences', 0);
        $this->assertDatabaseCount('case_conference_history', 0);
    }

    public function test_conference_schedule_offsets_are_normalized_to_utc(): void
    {
        [$owner, $case] = $this->context();
        $this->actingAs($owner)->postJson(route('cases.conferences.store', $case), ['conference_type' => 'initial', 'scheduled_at' => '2026-10-20T14:30:00+08:00'])->assertCreated();
        $this->assertDatabaseHas('case_conferences', ['case_id' => $case, 'scheduled_at' => '2026-10-20 06:30:00']);
    }

    public function test_draft_related_writes_and_rescheduling_match_author_only_edit_controls(): void
    {
        [$owner, $case] = $this->context();
        $this->actingAs($owner)->postJson(route('cases.conferences.store', $case), ['conference_type' => 'initial'])->assertCreated();
        $conference = DB::table('case_conferences')->value('id');
        $reviewer = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']);
        $this->actingAs($reviewer)->getJson(route('cases.conferences.show', [$case, $conference]))->assertOk()->assertJsonPath('allowed_actions.edit_draft', false);
        $this->postJson(route('cases.conferences.participants.store', [$case, $conference]), ['name' => 'Synthetic non-author', 'attendance_status' => 'attended'])->assertForbidden();
        $this->postJson(route('cases.conferences.decisions.store', [$case, $conference]), ['decision_type' => 'decision', 'statement' => 'Synthetic non-author decision'])->assertForbidden();
        $this->postJson(route('cases.conferences.actions.store', [$case, $conference]), ['action' => 'Synthetic non-author action', 'priority' => 'normal'])->assertForbidden();
        $this->postJson(route('cases.conferences.reschedule', [$case, $conference]), ['scheduled_at' => '2026-10-20T14:30:00+08:00', 'reason' => 'Synthetic non-author reason'])->assertForbidden();
    }

    public function test_related_record_audits_identify_the_parent_conference_and_child_record(): void
    {
        [$owner, $case] = $this->context();
        $this->actingAs($owner)->postJson(route('cases.conferences.store', $case), ['conference_type' => 'initial'])->assertCreated();
        $conference = DB::table('case_conferences')->value('id');
        foreach ([1, 2] as $number) {
            $participant = $this->postJson(route('cases.conferences.participants.store', [$case, $conference]), ['name' => 'Synthetic participant ' . $number, 'attendance_status' => 'invited'])->assertCreated()->json('id');
            $this->assertAuditChanges(['event' => 'conference.participant_recorded', 'auditable_type' => 'case_conference', 'auditable_id' => $conference], ['participant_id' => $participant]);
        }
        $decision = $this->postJson(route('cases.conferences.decisions.store', [$case, $conference]), ['decision_type' => 'decision', 'statement' => 'Synthetic decision'])->assertCreated()->json('id');
        $action = $this->postJson(route('cases.conferences.actions.store', [$case, $conference]), ['action' => 'Synthetic action', 'priority' => 'normal'])->assertCreated()->json('id');
        $this->assertAuditChanges(['event' => 'conference.decision_recorded', 'auditable_id' => $conference], ['decision_id' => $decision]);
        $this->assertAuditChanges(['event' => 'conference.action_recorded', 'auditable_id' => $conference], ['action_id' => $action]);
    }

    public static function auditedMutations(): array
    {
        return array_map(fn ($kind) => [$kind], ['update', 'participant', 'decision', 'action', 'submit', 'return', 'finalize', 'reschedule', 'addendum']);
    }

    #[DataProvider('auditedMutations')]
    public function test_each_mutation_rolls_back_its_record_history_and_audit_on_failure(string $kind): void
    {
        [$owner, $case] = $this->context();
        $this->actingAs($owner)->postJson(route('cases.conferences.store', $case), ['conference_type' => 'initial'])->assertCreated();
        $conference = DB::table('case_conferences')->value('id');
        if (in_array($kind, ['return', 'finalize'], true)) {
            DB::table('case_conferences')->where('id', $conference)->update(['status' => 'for_review']);
            $this->actingAs(User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']));
        } elseif ($kind === 'addendum') {
            DB::table('case_conferences')->where('id', $conference)->update(['status' => 'final']);
        }
        $tables = ['case_conferences', 'case_conference_participants', 'case_conference_decisions', 'case_conference_actions', 'case_conference_history', 'audit_logs'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        SyntheticTrigger::create("CREATE TRIGGER synthetic_conference_mutation_failure BEFORE INSERT ON audit_logs WHEN NEW.event LIKE 'conference.%' BEGIN SELECT RAISE(ABORT, 'Synthetic mutation audit failure'); END");
        $this->withoutExceptionHandling();
        try {
            match ($kind) {
                'update' => $this->patchJson(route('cases.conferences.update', [$case, $conference]), ['key_issues' => 'Synthetic revised issues']),
                'participant' => $this->postJson(route('cases.conferences.participants.store', [$case, $conference]), ['name' => 'Synthetic participant', 'attendance_status' => 'attended']),
                'decision' => $this->postJson(route('cases.conferences.decisions.store', [$case, $conference]), ['decision_type' => 'decision', 'statement' => 'Synthetic decision']),
                'action' => $this->postJson(route('cases.conferences.actions.store', [$case, $conference]), ['action' => 'Synthetic action', 'priority' => 'normal']),
                'submit', 'return', 'finalize' => $this->patchJson(route('cases.conferences.transition', [$case, $conference]), ['status' => match ($kind) {
                    'submit' => 'for_review', 'return' => 'returned_for_revision', 'finalize' => 'final'
                }, 'comments' => 'Synthetic review comment']),
                'reschedule' => $this->postJson(route('cases.conferences.reschedule', [$case, $conference]), ['scheduled_at' => '2026-10-20T14:30:00+08:00', 'reason' => 'Synthetic reason']),
                'addendum' => $this->postJson(route('cases.conferences.addendum', [$case, $conference]), ['reason' => 'Synthetic correction']),
            };
            $this->fail('The mutation must not acknowledge an unaudited change.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic mutation audit failure', $exception->getMessage());
        }
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table . ' must roll back');
        }
    }
}

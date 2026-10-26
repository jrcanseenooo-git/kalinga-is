<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyntheticTrigger;
use Tests\TestCase;

class MdtWriteIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $member = User::factory()->create(['role' => 'cpu_member']);
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'SYN-MDT-WRITES', 'name' => 'Synthetic MDT LGU', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$owner, $member] as $actor) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $actor->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'MDT integrity', 'province' => 'Kalinga', 'municipality' => 'Synthetic MDT LGU', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYN-MDT-WRITES', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $owner->id, 'status' => 'OPEN', 'stage' => 'casework', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $grant = DB::table('mdt_case_access')->insertGetId(['case_id' => $case, 'user_id' => $member->id, 'professional_role' => 'CPU', 'organization' => 'Synthetic office', 'purpose' => 'Synthetic MDT integrity review', 'allowed_categories' => json_encode(['case_summary']), 'status' => 'assigned', 'assigned_by' => $owner->id, 'assigned_at' => now(), 'access_starts_at' => now()->subDay(), 'access_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $action = DB::table('mdt_actions')->insertGetId(['case_id' => $case, 'mdt_access_id' => $grant, 'title' => 'Synthetic current action', 'status' => 'action_required', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $note = DB::table('mdt_case_notes')->insertGetId(['case_id' => $case, 'mdt_access_id' => $grant, 'category' => 'Synthetic existing professional note', 'findings' => 'Synthetic retained professional findings.', 'status' => 'draft', 'created_by' => $member->id, 'created_at' => now(), 'updated_at' => now()]);

        return compact('owner', 'member', 'case', 'grant', 'action', 'note');
    }

    public static function operations(): array
    {
        return array_map(fn ($operation) => [$operation], ['grant', 'acknowledge', 'revoke', 'assign_action', 'respond_action', 'discussion', 'note', 'finalize', 'addendum']);
    }

    #[DataProvider('operations')]
    public function test_audit_failure_cannot_leave_any_unaudited_mdt_mutation(string $operation): void
    {
        extract($this->context());
        if ($operation === 'addendum') {
            DB::table('mdt_case_notes')->where('id', $note)->update(['status' => 'final', 'finalized_at' => now()]);
        }
        $before = [];
        foreach (['mdt_case_access', 'mdt_actions', 'mdt_discussions', 'mdt_case_notes', 'notifications', 'cases'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        SyntheticTrigger::create("CREATE TRIGGER synthetic_mdt_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.auditable_type = 'mdt' BEGIN SELECT RAISE(ABORT, 'Synthetic MDT audit failure'); END");
        $this->actingAs(in_array($operation, ['grant', 'revoke', 'assign_action'], true) ? $owner : $member)->withoutExceptionHandling();
        try {
            match ($operation) {
                'grant' => $this->postJson(route('cases.mdt.access.store', $case), ['user_id' => $member->id, 'professional_role' => 'CPU', 'organization' => 'Synthetic office', 'purpose' => 'Synthetic proposed second assignment', 'allowed_categories' => ['case_summary'], 'access_expires_at' => now()->addDays(2)->toIso8601String()]),
                'acknowledge' => $this->patchJson(route('cases.mdt.access.acknowledge', [$case, $grant])),
                'revoke' => $this->deleteJson(route('cases.mdt.access.revoke', [$case, $grant])),
                'assign_action' => $this->postJson(route('cases.mdt.actions.store', $case), ['mdt_access_id' => $grant, 'title' => 'Synthetic proposed new action']),
                'respond_action' => $this->patchJson(route('cases.mdt.actions.respond', [$case, $action]), ['status' => 'responded']),
                'discussion' => $this->postJson(route('cases.mdt.discussion.store', $case), ['body' => 'Synthetic purpose-limited case discussion.']),
                'note' => $this->postJson(route('cases.mdt.notes.store', $case), ['category' => 'Synthetic proposed note', 'findings' => 'Synthetic proposed findings.']),
                'finalize' => $this->patchJson(route('cases.mdt.notes.finalize', [$case, $note])),
                'addendum' => $this->postJson(route('cases.mdt.notes.addendum', [$case, $note]), ['reason' => 'Synthetic clarification', 'category' => 'Synthetic addendum', 'findings' => 'Synthetic supplementary findings.']),
            };
            $this->fail('An unaudited MDT mutation must not persist.');
        } catch (QueryException) {
            foreach ($before as $table => $snapshot) {
                $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson(), $table . ' must roll back after audit failure.');
            }
        }
    }

    public function test_revoked_grant_during_note_write_cannot_commit_a_note_or_case_version(): void
    {
        extract($this->context());
        $version = DB::table('cases')->where('id', $case)->value('offline_version');
        SyntheticTrigger::create('CREATE TRIGGER synthetic_mdt_grant_revoked AFTER INSERT ON mdt_case_notes BEGIN UPDATE mdt_case_access SET revoked_at = CURRENT_TIMESTAMP WHERE id = NEW.mdt_access_id; END');
        $this->actingAs($member)->postJson(route('cases.mdt.notes.store', $case), ['category' => 'Synthetic rejected note'])->assertForbidden();
        $this->assertDatabaseCount('mdt_case_notes', 1);
        $this->assertNoDataChangeAudited();
        $this->assertSame($version, DB::table('cases')->where('id', $case)->value('offline_version'));
    }

    public function test_recipient_lgu_expiry_during_action_creation_rolls_back_action_notification_and_grant_status(): void
    {
        extract($this->context());
        SyntheticTrigger::create('CREATE TRIGGER synthetic_mdt_recipient_expiry AFTER INSERT ON mdt_actions BEGIN UPDATE user_lgu_assignments SET effective_to = CURRENT_TIMESTAMP WHERE user_id = (SELECT user_id FROM mdt_case_access WHERE id = NEW.mdt_access_id); END');
        $this->actingAs($owner)->postJson(route('cases.mdt.actions.store', $case), ['mdt_access_id' => $grant, 'title' => 'Synthetic proposed action after recipient expiry'])->assertUnprocessable();
        $this->assertDatabaseCount('mdt_actions', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertNoDataChangeAudited();
        $this->assertDatabaseHas('mdt_case_access', ['id' => $grant, 'status' => 'assigned']);
    }

    public function test_parent_archived_during_acknowledgement_prevents_commit(): void
    {
        extract($this->context());
        SyntheticTrigger::create("CREATE TRIGGER synthetic_mdt_parent_archive AFTER UPDATE ON mdt_case_access WHEN NEW.status = 'received' BEGIN UPDATE cases SET deleted_at = CURRENT_TIMESTAMP WHERE id = NEW.case_id; END");
        $this->actingAs($member)->patchJson(route('cases.mdt.access.acknowledge', [$case, $grant]))->assertForbidden();
        $this->assertDatabaseHas('mdt_case_access', ['id' => $grant, 'status' => 'assigned']);
        $this->assertNoDataChangeAudited();
    }

    public function test_authorized_terminal_completion_commits_but_does_not_restore_read_access(): void
    {
        extract($this->context());
        $this->actingAs($member)->patchJson(route('cases.mdt.actions.respond', [$case, $action]), ['status' => 'completed'])->assertNoContent();
        $this->assertDatabaseHas('mdt_actions', ['id' => $action, 'status' => 'completed']);
        $this->assertDatabaseHas('mdt_case_access', ['id' => $grant, 'status' => 'completed']);
        $this->getJson(route('cases.mdt.show', $case))->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['event' => 'mdt.action_completed']);
    }
}

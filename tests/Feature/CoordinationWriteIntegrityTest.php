<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticTrigger;
use Tests\TestCase;

class CoordinationWriteIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $member = User::factory()->create(['role' => 'cpu_member']);
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'SYN-INTEGRITY-LGU', 'name' => 'Synthetic integrity LGU', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $circle = DB::table('lgu_coordination_circles')->insertGetId(['lgu_id' => $lgu, 'name' => 'Synthetic integrity circle', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$worker, $member] as $user) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('lgu_coordination_members')->insert(['circle_id' => $circle, 'user_id' => $user->id, 'joined_at' => now()->subDay(), 'membership_role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        }
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Integrity', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYN-CHAT-INTEGRITY', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'casework', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $source = DB::table('lgu_coordination_messages')->insertGetId(['circle_id' => $circle, 'author_id' => $worker->id, 'body' => 'Synthetic existing source message.', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mdt_case_access')->insert(['case_id' => $case, 'user_id' => $member->id, 'professional_role' => 'CPU', 'organization' => 'Synthetic office', 'purpose' => 'Synthetic coordination', 'allowed_categories' => json_encode(['case_summary']), 'status' => 'assigned', 'assigned_by' => $worker->id, 'assigned_at' => now(), 'access_starts_at' => now()->subDay(), 'access_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        return compact('worker', 'member', 'lgu', 'circle', 'case', 'source');
    }

    public function test_failed_message_audit_rolls_back_message_mentions_and_notifications(): void
    {
        extract($this->context());
        SyntheticTrigger::create("CREATE TRIGGER synthetic_chat_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.event = 'cpu_chat.message_posted' BEGIN SELECT RAISE(ABORT, 'Synthetic chat audit failure'); END");
        $this->actingAs($worker)->withoutExceptionHandling();
        try {
            $this->postJson(route('cpu.coordination.messages.store', $circle), ['body' => 'Synthetic unaudited proposed message.', 'mention_user_ids' => [$member->id]]);
            $this->fail('An unaudited message must not persist.');
        } catch (QueryException) {
            $this->assertDatabaseCount('lgu_coordination_messages', 1);
            $this->assertDatabaseCount('lgu_coordination_message_mentions', 0);
            $this->assertDatabaseCount('notifications', 0);
        }
    }

    public function test_failed_capture_audit_rolls_back_the_formal_note(): void
    {
        extract($this->context());
        SyntheticTrigger::create("CREATE TRIGGER synthetic_capture_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.event = 'cpu_chat.record_captured' BEGIN SELECT RAISE(ABORT, 'Synthetic capture audit failure'); END");
        $this->actingAs($worker)->withoutExceptionHandling();
        try {
            $this->postJson(route('cpu.coordination.messages.capture', [$circle, $source]), ['case_id' => $case, 'record_type' => 'progress_note', 'professional_summary' => 'Synthetic reviewed summary that must roll back.', 'confirmed' => true]);
            $this->fail('An unaudited formal capture must not persist.');
        } catch (QueryException) {
            $this->assertDatabaseCount('case_notes', 0);
            $this->assertDatabaseCount('lgu_coordination_messages', 1);
        }
    }

    public function test_membership_revoked_during_a_message_write_prevents_commit(): void
    {
        extract($this->context());
        SyntheticTrigger::create('CREATE TRIGGER synthetic_revoke_sender AFTER INSERT ON lgu_coordination_messages BEGIN UPDATE lgu_coordination_members SET left_at = CURRENT_TIMESTAMP WHERE circle_id = NEW.circle_id AND user_id = NEW.author_id; END');
        $this->actingAs($worker)->postJson(route('cpu.coordination.messages.store', $circle), ['body' => 'Synthetic post-revocation message.'])->assertForbidden();
        $this->assertDatabaseCount('lgu_coordination_messages', 1);
        $this->assertNoDataChangeAudited();
    }

    public function test_case_archived_during_capture_prevents_formal_note_commit(): void
    {
        extract($this->context());
        SyntheticTrigger::create('CREATE TRIGGER synthetic_archive_during_capture AFTER INSERT ON case_notes BEGIN UPDATE cases SET deleted_at = CURRENT_TIMESTAMP WHERE id = NEW.case_id; END');
        $this->actingAs($worker)->postJson(route('cpu.coordination.messages.capture', [$circle, $source]), ['case_id' => $case, 'record_type' => 'progress_note', 'professional_summary' => 'Synthetic captured summary while access changes.', 'confirmed' => true])->assertForbidden();
        $this->assertDatabaseCount('case_notes', 0);
        $this->assertNoDataChangeAudited();
    }

    public function test_mdt_grant_revoked_during_capture_rolls_back_note_case_version_and_audit(): void
    {
        extract($this->context());
        $version = DB::table('cases')->where('id', $case)->value('offline_version');
        SyntheticTrigger::create('CREATE TRIGGER synthetic_capture_grant_revocation AFTER INSERT ON mdt_case_notes BEGIN UPDATE mdt_case_access SET revoked_at = CURRENT_TIMESTAMP WHERE id = NEW.mdt_access_id; END');
        $this->actingAs($member)->postJson(route('cpu.coordination.messages.capture', [$circle, $source]), ['case_id' => $case, 'record_type' => 'mdt_note', 'professional_summary' => 'Synthetic MDT summary after grant revocation.', 'confirmed' => true])->assertForbidden();
        $this->assertDatabaseCount('mdt_case_notes', 0);
        $this->assertNoDataChangeAudited();
        $this->assertSame($version, DB::table('cases')->where('id', $case)->value('offline_version'));
    }

    public function test_mention_recipient_revoked_during_notification_creation_rolls_back_the_message(): void
    {
        extract($this->context());
        SyntheticTrigger::create('CREATE TRIGGER synthetic_mention_revocation AFTER INSERT ON notifications BEGIN UPDATE lgu_coordination_members SET left_at = CURRENT_TIMESTAMP WHERE user_id = NEW.user_id; END');
        $this->actingAs($worker)->postJson(route('cpu.coordination.messages.store', $circle), ['body' => 'Synthetic notification to revoked member.', 'mention_user_ids' => [$member->id]])->assertUnprocessable();
        $this->assertDatabaseCount('lgu_coordination_messages', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('lgu_coordination_message_mentions', 0);
        $this->assertNoDataChangeAudited();
    }

    public function test_capture_permission_removed_during_note_creation_prevents_commit(): void
    {
        extract($this->context());
        SyntheticTrigger::create('CREATE TRIGGER synthetic_capture_account_revocation AFTER INSERT ON case_notes BEGIN UPDATE users SET active = 0 WHERE id = NEW.created_by; END');
        $this->actingAs($worker)->postJson(route('cpu.coordination.messages.capture', [$circle, $source]), ['case_id' => $case, 'record_type' => 'progress_note', 'professional_summary' => 'Synthetic summary after account removal.', 'confirmed' => true])->assertForbidden();
        $this->assertDatabaseCount('case_notes', 0);
        $this->assertNoDataChangeAudited();
    }
}

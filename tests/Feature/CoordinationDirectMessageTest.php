<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyntheticTrigger;
use Tests\TestCase;

class CoordinationDirectMessageTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $cpu = User::factory()->create(['role' => 'cpu_member', 'active' => true]);
        $third = User::factory()->create(['role' => 'cpu_member', 'active' => true]);
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'SYN-DM', 'name' => 'Synthetic LGU', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $circle = DB::table('lgu_coordination_circles')->insertGetId(['lgu_id' => $lgu, 'name' => 'Synthetic circle', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$worker, $cpu, $third] as $user) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('lgu_coordination_members')->insert(['user_id' => $user->id, 'circle_id' => $circle, 'joined_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$worker, $cpu, $third, $circle];
    }

    public function test_private_send_read_search_reply_and_group_separation(): void
    {
        [$worker, $cpu, $third, $circle] = $this->context();
        $send = route('cpu.direct.messages.store', [$circle, $cpu->id]);
        $id = $this->actingAs($worker)->postJson($send, ['body' => 'Synthetic private schedule', 'is_urgent' => true])->assertCreated()->json('id');
        $this->assertDatabaseCount('lgu_coordination_messages', 0);
        $this->actingAs($cpu)->getJson(route('cpu.direct.messages.index', [$circle, $worker->id]))->assertOk()
            ->assertJsonPath('messages.0.body', 'Synthetic private schedule')->assertJsonPath('messages.0.is_own', false)
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->postJson(route('cpu.direct.messages.store', [$circle, $worker->id]), ['body' => 'Confirmed', 'parent_id' => $id])->assertCreated();
        $this->postJson(route('cpu.direct.search', [$circle, $worker->id]), ['q' => 'schedule'])->assertOk()->assertJsonCount(1, 'messages');
        $this->actingAs($third)->getJson(route('cpu.direct.messages.index', [$circle, $worker->id]))->assertOk()->assertJsonCount(0, 'messages');
        $this->postJson(route('cpu.direct.messages.store', [$circle, $worker->id]), ['body' => 'Invalid reply', 'parent_id' => $id])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['event' => 'cpu_chat.direct_message_posted', 'auditable_id' => $id]);
        $this->assertStringNotContainsString('Synthetic private schedule', DB::table('audit_logs')->where('event', 'cpu_chat.direct_message_posted')->value('changes'));
    }

    public function test_revocation_denies_reads_search_and_writes(): void
    {
        [$worker, $cpu, , $circle] = $this->context();
        $this->actingAs($worker)->postJson(route('cpu.direct.messages.store', [$circle, $cpu->id]), ['body' => 'Synthetic private message'])->assertCreated();
        DB::table('user_lgu_assignments')->where('user_id', $cpu->id)->update(['effective_to' => now()->subMinute()]);
        $this->getJson(route('cpu.direct.messages.index', [$circle, $cpu->id]))->assertForbidden();
        $this->postJson(route('cpu.direct.search', [$circle, $cpu->id]), ['q' => 'private'])->assertForbidden();
        $this->postJson(route('cpu.direct.messages.store', [$circle, $cpu->id]), ['body' => 'Denied'])->assertForbidden();
        $this->assertDatabaseCount('coordination_direct_messages', 1);
    }

    public function test_self_outsider_and_case_disclosures_are_rejected(): void
    {
        [$worker, $cpu, , $circle] = $this->context();
        $outside = User::factory()->create(['role' => 'cpu_member']);
        $this->actingAs($worker)->postJson(route('cpu.direct.messages.store', [$circle, $worker->id]), ['body' => 'Self'])->assertUnprocessable();
        $this->postJson(route('cpu.direct.messages.store', [$circle, $outside->id]), ['body' => 'Outside'])->assertForbidden();
        $this->postJson(route('cpu.direct.messages.store', [$circle, $cpu->id]), ['body' => 'Case tag', 'case_id' => 1])->assertUnprocessable();
        $this->postJson(route('cpu.direct.messages.store', [$circle, $cpu->id]), ['body' => 'Mention', 'mention_user_ids' => [$outside->id]])->assertUnprocessable();
        $this->assertDatabaseCount('coordination_direct_messages', 0);
    }

    public function test_audit_failure_rolls_back_private_send(): void
    {
        [$worker, $cpu, , $circle] = $this->context();
        SyntheticTrigger::create("CREATE TRIGGER synthetic_dm_audit BEFORE INSERT ON audit_logs WHEN NEW.event = 'cpu_chat.direct_message_posted' BEGIN SELECT RAISE(ABORT, 'Synthetic audit failure'); END");
        $this->actingAs($worker)->withoutExceptionHandling();
        try {
            $this->postJson(route('cpu.direct.messages.store', [$circle, $cpu->id]), ['body' => 'Must roll back']);
            $this->fail('Unaudited message persisted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('coordination_direct_messages', 0);
        }
    }

    public function test_membership_revoked_during_send_rolls_back_message(): void
    {
        [$worker, $cpu, , $circle] = $this->context();
        SyntheticTrigger::create('CREATE TRIGGER synthetic_dm_revoke AFTER INSERT ON coordination_direct_messages BEGIN UPDATE lgu_coordination_members SET left_at = CURRENT_TIMESTAMP WHERE user_id = NEW.recipient_id; END');
        $this->actingAs($worker)->postJson(route('cpu.direct.messages.store', [$circle, $cpu->id]), ['body' => 'Revoked during send'])->assertForbidden();
        $this->assertDatabaseCount('coordination_direct_messages', 0);
    }

    public function test_history_is_bounded_and_excludes_other_pairs(): void
    {
        [$worker, $cpu, $third, $circle] = $this->context();
        for ($i = 0; $i < 45; $i++) {
            DB::table('coordination_direct_messages')->insert(['circle_id' => $circle, 'sender_id' => $worker->id, 'recipient_id' => $cpu->id, 'body' => 'Synthetic message ' . $i, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('coordination_direct_messages')->insert(['circle_id' => $circle, 'sender_id' => $third->id, 'recipient_id' => $cpu->id, 'body' => 'Other pair sentinel', 'created_at' => now(), 'updated_at' => now()]);
        $url = route('cpu.direct.messages.index', [$circle, $cpu->id]);
        $response = $this->actingAs($worker)->getJson($url)->assertOk()->assertJsonCount(40, 'messages')->assertJsonPath('pagination.has_more', true)->assertDontSee('Other pair sentinel');
        $this->getJson($url . '?before=' . $response->json('pagination.before'))->assertOk()->assertJsonCount(5, 'messages')->assertJsonPath('pagination.has_more', false);
    }

    public static function ineligiblePairs(): array
    {
        return array_map(fn ($reason) => [$reason], ['actor_inactive', 'recipient_inactive', 'recipient_left', 'recipient_future', 'circle_disabled', 'recipient_admin']);
    }

    #[DataProvider('ineligiblePairs')]
    public function test_current_pair_eligibility_is_required_for_reads_and_sends(string $reason): void
    {
        [$worker, $cpu, , $circle] = $this->context();
        $this->actingAs($worker);
        match ($reason) {
            'actor_inactive' => DB::table('users')->where('id', $worker->id)->update(['active' => false]),
            'recipient_inactive' => DB::table('users')->where('id', $cpu->id)->update(['active' => false]),
            'recipient_left' => DB::table('lgu_coordination_members')->where('user_id', $cpu->id)->update(['left_at' => now()]),
            'recipient_future' => DB::table('user_lgu_assignments')->where('user_id', $cpu->id)->update(['effective_from' => now()->addDay()]),
            'circle_disabled' => DB::table('lgu_coordination_circles')->where('id', $circle)->update(['active' => false]),
            'recipient_admin' => DB::table('users')->where('id', $cpu->id)->update(['role' => 'system_admin']),
        };
        $this->getJson(route('cpu.direct.messages.index', [$circle, $cpu->id]))->assertForbidden();
        $this->postJson(route('cpu.direct.messages.store', [$circle, $cpu->id]), ['body' => 'Must be denied'])->assertForbidden();
        $this->assertDatabaseCount('coordination_direct_messages', 0);
    }
}

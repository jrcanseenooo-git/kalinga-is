<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoordinationEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $member = User::factory()->create(['role' => 'cpu_member', 'municipality' => 'SyntheticTown']);
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'TEST-CPU', 'name' => 'SyntheticTown', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$owner, $member] as $user) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $client = DB::table('clients')->insertGetId(['first_name' => 'SyntheticPrivate', 'last_name' => 'Client', 'municipality' => 'SyntheticTown', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYNTHETIC-CPU-1', 'client_id' => $client, 'assigned_to' => $owner->id, 'lgu_id' => $lgu, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $circle = DB::table('lgu_coordination_circles')->insertGetId(['lgu_id' => $lgu, 'name' => 'Synthetic Circle', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$owner, $member] as $user) {
            DB::table('lgu_coordination_members')->insert(['circle_id' => $circle, 'user_id' => $user->id, 'joined_at' => now()->subDay(), 'membership_role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        }
        $message = DB::table('lgu_coordination_messages')->insertGetId(['circle_id' => $circle, 'author_id' => $owner->id, 'case_id' => $case, 'case_reference' => 'SYNTHETIC-CPU-1', 'body' => 'Synthetic coordination message', 'created_at' => now(), 'updated_at' => now()]);
        $grant = DB::table('mdt_case_access')->insertGetId(['case_id' => $case, 'user_id' => $member->id, 'professional_role' => 'CPU', 'organization' => 'Synthetic LGU', 'purpose' => 'Synthetic coordination', 'allowed_categories' => json_encode(['case_summary']), 'status' => 'assigned', 'assigned_by' => $owner->id, 'assigned_at' => now(), 'access_starts_at' => now()->subDay(), 'access_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        return compact('owner', 'member', 'lgu', 'case', 'circle', 'message', 'grant');
    }

    public function test_expired_lgu_assignment_blocks_circle_list_read_search_post_and_capture_even_with_legacy_location(): void
    {
        extract($this->fixture());
        DB::table('user_lgu_assignments')->where('user_id', $member->id)->update(['effective_to' => now()->subSecond()]);
        $this->be($member);
        $this->get(route('cpu.coordination.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('circles', 0)->has('messages', 0)->has('members', 0));
        $this->getJson(route('cpu.coordination.messages.index', $circle))->assertForbidden();
        $this->getJson(route('cpu.coordination.search', ['circle' => $circle, 'q' => 'Synthetic']))->assertForbidden();
        $this->postJson(route('cpu.coordination.messages.store', $circle), ['body' => 'Rejected'])->assertForbidden();
        $this->postJson(route('cpu.coordination.messages.capture', [$circle, $message]), ['case_id' => $case, 'record_type' => 'mdt_note', 'professional_summary' => 'Rejected', 'confirmed' => true])->assertForbidden();
    }

    public function test_directory_and_mentions_exclude_member_with_inactive_lgu_assignment(): void
    {
        extract($this->fixture());
        DB::table('user_lgu_assignments')->where('user_id', $member->id)->update(['active' => false]);
        $this->be($owner);
        $this->getJson(route('cpu.coordination.messages.index', $circle))->assertOk()->assertJsonCount(1, 'members')->assertJsonPath('members.0.id', $owner->id);
        $this->postJson(route('cpu.coordination.messages.store', $circle), ['body' => 'Rejected mention', 'mention_user_ids' => [$member->id]])->assertUnprocessable();
        $this->assertDatabaseMissing('lgu_coordination_messages', ['body' => 'Rejected mention']);
    }

    public function test_inactive_circle_blocks_direct_messages(): void
    {
        extract($this->fixture());
        DB::table('lgu_coordination_circles')->where('id', $circle)->update(['active' => false]);
        $this->be($member)->getJson(route('cpu.coordination.messages.index', $circle))->assertForbidden();
    }

    public function test_chat_history_is_bounded_and_only_visible_messages_are_marked_read(): void
    {
        extract($this->fixture());
        for ($index = 0; $index < 54; $index++) {
            DB::table('lgu_coordination_messages')->insert([
                'circle_id' => $circle, 'author_id' => $owner->id, 'body' => 'Synthetic page ' . $index,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->be($member);
        $response = $this->getJson(route('cpu.coordination.messages.index', $circle))->assertOk()->assertJsonCount(50, 'messages')->assertJsonPath('pagination.has_more', true);
        $before = $response->json('pagination.before');
        $this->assertSame(50, DB::table('lgu_coordination_message_reads')->where('user_id', $member->id)->count());
        $older = $this->getJson(route('cpu.coordination.messages.index', ['circle' => $circle, 'before' => $before]))->assertOk()->assertJsonCount(5, 'messages')->assertJsonPath('pagination.has_more', false);
        $this->assertSame($message, $older->json('messages.0.id'));
        $this->assertEmpty(array_intersect(array_column($response->json('messages'), 'id'), array_column($older->json('messages'), 'id')));
        $this->getJson(route('cpu.coordination.messages.index', ['circle' => $circle, 'before' => 'invalid']))->assertUnprocessable();
        $search = $this->postJson(route('cpu.coordination.search', $circle), ['q' => 'Synthetic page'])->assertOk()->assertJsonCount(50, 'messages')->assertJsonPath('pagination.has_more', true);
        $this->postJson(route('cpu.coordination.search', $circle), ['q' => 'Synthetic page', 'before' => $search->json('pagination.before')])->assertOk()->assertJsonCount(4, 'messages')->assertJsonPath('pagination.has_more', false);
        DB::table('lgu_coordination_members')->where('circle_id', $circle)->where('user_id', $member->id)->update(['left_at' => now()]);
        $this->postJson(route('cpu.coordination.search', $circle), ['q' => 'Synthetic page'])->assertForbidden();
    }

    public static function invalidGrants(): array
    {
        return array_map(fn ($state) => [$state], ['future', 'expired', 'revoked', 'completed', 'returned', 'closed', 'archived', 'lgu_revoked']);
    }

    #[DataProvider('invalidGrants')]
    public function test_mdt_list_and_detail_agree_on_ineligible_grants(string $state): void
    {
        extract($this->fixture());
        if ($state === 'future') {
            DB::table('mdt_case_access')->where('id', $grant)->update(['access_starts_at' => now()->addDay()]);
        } elseif ($state === 'expired') {
            DB::table('mdt_case_access')->where('id', $grant)->update(['access_expires_at' => now()->subSecond()]);
        } elseif ($state === 'closed') {
            DB::table('cases')->where('id', $case)->update(['status' => 'CLOSED']);
        } elseif ($state === 'archived') {
            DB::table('cases')->where('id', $case)->update(['deleted_at' => now()]);
        } elseif ($state === 'lgu_revoked') {
            DB::table('user_lgu_assignments')->where('user_id', $member->id)->update(['active' => false]);
        } else {
            DB::table('mdt_case_access')->where('id', $grant)->update(['status' => $state]);
        }
        $this->be($member);
        $this->get(route('mdt.collaboration.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('assignments', 0)->where('lguSummary.referred_cases', 0));
        $this->getJson(route('cases.mdt.show', $case))->assertStatus($state === 'archived' ? 404 : 403);
    }

    public function test_future_grant_cannot_open_case_hint_or_capture_chat_as_mdt_note(): void
    {
        extract($this->fixture());
        DB::table('mdt_case_access')->where('id', $grant)->update(['access_starts_at' => now()->addDay()]);
        $this->be($member);
        $this->getJson(route('cpu.coordination.messages.index', $circle))->assertOk()->assertJsonPath('messages.0.can_open_case', false);
        $this->postJson(route('cpu.coordination.messages.capture', [$circle, $message]), ['case_id' => $case, 'record_type' => 'mdt_note', 'professional_summary' => 'Reviewed summary', 'confirmed' => true])->assertForbidden();
        $this->assertDatabaseCount('mdt_case_notes', 0);
    }

    public function test_current_grant_remains_listed_and_accessible(): void
    {
        extract($this->fixture());
        $this->be($member);
        $this->get(route('mdt.collaboration.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('assignments', 1)->where('assignments.0.id', $grant));
        $this->getJson(route('cases.mdt.show', $case))->assertOk()->assertJsonMissing(['first_name' => 'SyntheticPrivate']);
    }

    #[DataProvider('invalidGrants')]
    public function test_assigning_an_action_cannot_reactivate_an_ineligible_grant(string $state): void
    {
        extract($this->fixture());
        if ($state === 'future') {
            DB::table('mdt_case_access')->where('id', $grant)->update(['access_starts_at' => now()->addDay()]);
        } elseif ($state === 'expired') {
            DB::table('mdt_case_access')->where('id', $grant)->update(['access_expires_at' => now()->subSecond()]);
        } elseif ($state === 'closed') {
            DB::table('cases')->where('id', $case)->update(['status' => 'CLOSED']);
        } elseif ($state === 'archived') {
            DB::table('cases')->where('id', $case)->update(['deleted_at' => now()]);
        } elseif ($state === 'lgu_revoked') {
            DB::table('user_lgu_assignments')->where('user_id', $member->id)->update(['active' => false]);
        } else {
            DB::table('mdt_case_access')->where('id', $grant)->update(['status' => $state]);
        }
        $this->be($owner)->postJson(route('cases.mdt.actions.store', $case), ['mdt_access_id' => $grant, 'title' => 'Must not reactivate access'])
            ->assertStatus($state === 'archived' ? 404 : 422);
        $this->assertDatabaseCount('mdt_actions', 0);
    }
}

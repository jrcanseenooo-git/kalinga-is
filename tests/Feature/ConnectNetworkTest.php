<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phase 8: Project Managers message case managers across their regions and run group chats. */
class ConnectNetworkTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role, string $name, ?int $lgu, array $extra = []): User
    {
        $user = User::factory()->create(['role' => $role, 'name' => $name, 'active' => true] + $extra);
        if ($lgu) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    public function test_project_manager_reaches_staff_in_covered_regions_and_runs_a_group(): void
    {
        $leyte = DB::table('lgu_units')->insertGetId(['psgc_code' => 'NET-1', 'name' => 'Synthetic Tacloban', 'province' => 'Leyte', 'region' => 'Region VIII', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $kalinga = DB::table('lgu_units')->insertGetId(['psgc_code' => 'NET-2', 'name' => 'Synthetic Tabuk', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $cmLeyte = $this->staff('social_worker', 'Synthetic Leyte CM', $leyte);
        $cmLeyte2 = $this->staff('social_worker', 'Synthetic Leyte CM Two', $leyte);
        $cmKalinga = $this->staff('social_worker', 'Synthetic Kalinga CM', $kalinga);
        $cpu = $this->staff('cpu_member', 'Synthetic CPU', $leyte);
        $focal = $this->staff('project_manager', 'Synthetic Region VIII Focal', null, ['region_scope' => ['Region VIII']]);
        $national = $this->staff('project_manager', 'Synthetic National PM', null, ['region_scope' => ['*']]);

        $this->actingAs($focal)->getJson(route('connect.network.people', ['q' => 'Synthetic']))->assertOk()
            ->assertJsonFragment(['name' => 'Synthetic Leyte CM'])->assertJsonMissing(['name' => 'Synthetic Kalinga CM'])->assertJsonMissing(['name' => 'Synthetic CPU']);
        $this->actingAs($national)->getJson(route('connect.network.people', ['region' => 'CAR']))->assertJsonFragment(['name' => 'Synthetic Kalinga CM'])->assertJsonMissing(['name' => 'Synthetic Leyte CM']);

        // Direct message: reused when started again; outside the scope is refused.
        $thread = $this->actingAs($focal)->postJson(route('connect.network.direct'), ['user_id' => $cmLeyte->id])->assertOk()->json('thread_id');
        $this->assertSame($thread, $this->postJson(route('connect.network.direct'), ['user_id' => $cmLeyte->id])->json('thread_id'));
        $this->postJson(route('connect.network.direct'), ['user_id' => $cmKalinga->id])->assertNotFound();
        $this->postJson(route('connect.network.send', $thread), ['body' => 'Please update the quarterly report by Friday.'])->assertOk();
        $this->assertDatabaseHas('notifications', ['user_id' => $cmLeyte->id, 'type' => 'connect.message']);
        $this->actingAs($cmLeyte)->getJson(route('connect.network.messages', $thread))->assertOk()->assertJsonPath('messages.0.body', 'Please update the quarterly report by Friday.');
        $this->postJson(route('connect.network.send', $thread), ['body' => 'Noted, will do.'])->assertOk();
        $this->actingAs($cmLeyte)->postJson(route('connect.network.direct'), ['user_id' => $focal->id])->assertForbidden();

        // Group chat with everyone shown; only staff in scope can be added.
        $this->actingAs($focal)->postJson(route('connect.network.groups'), ['title' => 'Region VIII', 'members' => [$cmLeyte->id, $cmKalinga->id]])->assertStatus(422);
        $group = $this->postJson(route('connect.network.groups'), ['title' => 'Region VIII', 'members' => [$cmLeyte->id, $cmLeyte2->id]])->assertOk()->json('thread_id');
        $this->assertDatabaseHas('notifications', ['user_id' => $cmLeyte2->id, 'type' => 'connect.added']);
        $this->actingAs($cmLeyte2)->get(route('connect.network'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Connect/Network')->where('canStart', false)
            ->where('threads', fn ($threads) => collect($threads)->contains('title', 'Region VIII')));
        $this->postJson(route('connect.network.send', $group), ['body' => 'Hello team', 'is_urgent' => true])->assertOk();
        $this->actingAs($cmKalinga)->getJson(route('connect.network.messages', $group))->assertNotFound();
        $this->actingAs($cmKalinga)->postJson(route('connect.network.send', $group), ['body' => 'x'])->assertNotFound();
        $this->actingAs($cpu)->get(route('connect.network'))->assertForbidden();

        $this->actingAs($cmLeyte2)->postJson(route('connect.network.leave', $group))->assertOk();
        $this->getJson(route('connect.network.messages', $group))->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['event' => 'connect.thread_created']);
        $this->assertStringNotContainsString('Hello team', (string) DB::table('audit_logs')->where('event', 'connect.message_sent')->pluck('changes')->implode(' '));
    }
}

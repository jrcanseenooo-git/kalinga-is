<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ChatUnread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Unread message badges in the menu: Kalinga Connect and Project team chat. */
class ChatBadgesTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_team_chat_badge_counts_unread_messages_and_clears_when_read(): void
    {
        $pm = User::factory()->create(['role' => 'project_manager', 'active' => true, 'region_scope' => ['*']]);
        $worker = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $thread = DB::table('connect_threads')->insertGetId(['kind' => 'direct', 'created_by' => $pm->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$pm, $worker] as $user) {
            DB::table('connect_thread_members')->insert(['thread_id' => $thread, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('connect_messages')->insert([['thread_id' => $thread, 'sender_id' => $pm->id, 'body' => 'Synthetic one', 'created_at' => now(), 'updated_at' => now()],
            ['thread_id' => $thread, 'sender_id' => $pm->id, 'body' => 'Synthetic two', 'created_at' => now(), 'updated_at' => now()]]);

        $this->assertSame(2, ChatUnread::team($worker));
        $this->assertSame(0, ChatUnread::team($pm), 'Your own messages are never unread.');
        $this->actingAs($worker)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('navCounts.teamUnread', 2));
        $this->getJson(route('connect.network.messages', $thread))->assertOk();
        $this->assertSame(0, ChatUnread::team($worker));
    }

    public function test_kalinga_connect_badge_counts_group_and_private_messages(): void
    {
        $lgu = $this->fixtureLgu('City of Tabuk', 'Kalinga', 'CAR');
        $circle = DB::table('lgu_coordination_circles')->insertGetId(['lgu_id' => $lgu, 'name' => 'Synthetic circle', 'created_at' => now(), 'updated_at' => now()]);
        $worker = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $cpu = User::factory()->create(['role' => 'cpu_member', 'active' => true]);
        foreach ([$worker, $cpu] as $user) {
            DB::table('lgu_coordination_members')->insert(['circle_id' => $circle, 'user_id' => $user->id, 'joined_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('lgu_coordination_messages')->insert(['circle_id' => $circle, 'author_id' => $cpu->id, 'body' => 'Synthetic group', 'created_at' => now(), 'updated_at' => now()]);
        $dm = DB::table('coordination_direct_messages')->insertGetId(['circle_id' => $circle, 'sender_id' => $cpu->id, 'recipient_id' => $worker->id, 'body' => 'Synthetic private', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(2, ChatUnread::connect($worker));
        $this->assertSame(0, ChatUnread::connect($cpu));
        DB::table('coordination_direct_messages')->where('id', $dm)->update(['read_at' => now()]);
        $this->assertSame(1, ChatUnread::connect($worker));
        DB::table('lgu_coordination_members')->where('user_id', $worker->id)->update(['left_at' => now()]);
        $this->assertSame(0, ChatUnread::connect($worker), 'No badge for circles the user left.');
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_mark_only_their_own_notifications_as_read(): void
    {
        $user = User::factory()->create(['role' => 'social_worker']);
        $otherUser = User::factory()->create(['role' => 'social_worker']);

        $ownNotification = DB::table('notifications')->insertGetId([
            'user_id' => $user->id, 'type' => 'task_assigned', 'title' => 'Own alert', 'body' => 'Visible only to this user.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherNotification = DB::table('notifications')->insertGetId([
            'user_id' => $otherUser->id, 'type' => 'task_assigned', 'title' => 'Other alert', 'body' => 'Must remain unread.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->patch(route('notifications.read-all'))->assertRedirect();

        $this->assertNotNull(DB::table('notifications')->find($ownNotification)->read_at);
        $this->assertNull(DB::table('notifications')->find($otherNotification)->read_at);
    }
}

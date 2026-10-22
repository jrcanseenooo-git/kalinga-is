<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MidnightIdleLogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_session_is_ended_after_midnight_when_idle_for_five_minutes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-26 00:06:00', 'Asia/Manila'));
        $user = User::factory()->create(['role' => 'social_worker']);

        $this->actingAs($user)->withSession([
            'kalinga.session_day' => '2026-09-25',
            'kalinga.last_activity_at' => '2026-09-26T00:00:00+08:00',
        ])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_active_session_remains_available_after_midnight_until_it_becomes_idle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-26 00:03:00', 'Asia/Manila'));
        $user = User::factory()->create(['role' => 'social_worker']);

        $this->actingAs($user)->withSession([
            'kalinga.session_day' => '2026-09-25',
            'kalinga.last_activity_at' => '2026-09-26T00:01:30+08:00',
        ])->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}

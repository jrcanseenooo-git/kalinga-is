<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\SessionKey;
use Tests\TestCase;

class BrowserHistoryPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_inertia_response_encrypts_history_clears_old_access_and_forbids_http_caching(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertViewHas('page', fn ($page) => $page['encryptHistory'] === true && $page['clearHistory'] === true)
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_authenticated_workspace_also_encrypts_history_and_forbids_http_caching(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $this->be($worker);
        $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('page', fn ($page) => $page['encryptHistory'] === true)
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_logout_requests_history_key_rotation_after_invalidating_session(): void
    {
        $this->be(User::factory()->create(['role' => 'social_worker', 'active' => true]));
        $this->post(route('logout'))->assertRedirect('/')->assertSessionHas(SessionKey::ClearHistory->value, true);
        $this->assertGuest();
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private function enableGoogle(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
        ]);
    }

    private function mockGoogleUser(string $id, string $email, string $name): void
    {
        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn($id);
        $socialiteUser->shouldReceive('getEmail')->andReturn($email);
        $socialiteUser->shouldReceive('getName')->andReturn($name);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_google_redirect_returns_redirect(): void
    {
        $this->enableGoogle();
        $this->mockGoogleUser('123', 'test@example.com', 'Test');

        $this->get(route('auth.google'))->assertRedirect();
    }

    public function test_google_callback_links_existing_user_by_email(): void
    {
        $this->enableGoogle();
        $user = User::factory()->create(['email' => 'admin@kalinga.test', 'active' => true]);
        $this->mockGoogleUser('google-123', 'admin@kalinga.test', 'Admin');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertEquals('google-123', $user->fresh()->google_id);
    }

    public function test_google_callback_links_existing_user_by_google_id(): void
    {
        $this->enableGoogle();
        $user = User::factory()->create([
            'email' => 'admin@kalinga.test', 'active' => true, 'google_id' => 'google-456',
        ]);
        $this->mockGoogleUser('google-456', 'admin@kalinga.test', 'Admin');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_google_callback_rejects_unknown_email(): void
    {
        $this->enableGoogle();
        $this->mockGoogleUser('google-999', 'nobody@example.com', 'Nobody');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_google_callback_rejects_inactive_user(): void
    {
        $this->enableGoogle();
        User::factory()->create(['email' => 'deactivated@kalinga.test', 'active' => false]);
        $this->mockGoogleUser('google-111', 'deactivated@kalinga.test', 'Deactivated');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_google_login_skips_mfa_challenge(): void
    {
        $this->enableGoogle();
        $user = User::factory()->create([
            'role' => 'system_admin', 'active' => true, 'google_id' => 'google-admin',
        ]);
        $this->mockGoogleUser('google-admin', $user->email, 'Admin');

        $this->get(route('auth.google.callback'));

        $this->actingAs($user)
            ->withSession(['kalinga.auth_method' => 'google'])
            ->get(route('admin.index'))
            ->assertOk();
    }

    public function test_google_login_sets_auth_method_session(): void
    {
        $this->enableGoogle();
        $user = User::factory()->create(['email' => 'test@kalinga.test', 'active' => true]);
        $this->mockGoogleUser('google-test', 'test@kalinga.test', 'Test');

        $this->get(route('auth.google.callback'));

        $this->assertEquals('google', session('kalinga.auth_method'));
    }

    public function test_google_enabled_prop_reflects_config(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
        $this->get(route('login'))->assertInertia(fn ($page) => $page->component('Auth/Login')->where('googleEnabled', false)
        );

        $this->enableGoogle();
        $this->get(route('login'))->assertInertia(fn ($page) => $page->component('Auth/Login')->where('googleEnabled', true)
        );
    }

    public function test_google_redirect_is_not_available_when_configuration_is_incomplete(): void
    {
        config(['services.google.client_id' => 'test-client-id', 'services.google.client_secret' => null]);

        $this->get(route('auth.google'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }
}

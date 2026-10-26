<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_page_loads_with_roles(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/Register')
                ->has('registrableRoles', 3)
            );
    }

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->post(route('register'), [
            'name' => 'Maria Santos',
            'email' => 'maria@lgu.test',
            'password' => 'K@lingaTest2026!x',
            'password_confirmation' => 'K@lingaTest2026!x',
            'requested_role' => 'social_worker',
            'region' => 'Region V',
            'province' => 'Albay',
            'municipality' => 'Legazpi City',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        $user = User::where('email', 'maria@lgu.test')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->active);
        $this->assertEquals('pending', $user->account_status);
        $this->assertEquals('social_worker', $user->requested_role);
        $this->assertEquals('social_worker', $user->role);
        $this->assertEquals('Region V', $user->region);
        $this->assertEquals('Legazpi City', $user->municipality);
    }

    public function test_registration_rejects_admin_roles(): void
    {
        $this->post(route('register'), [
            'name' => 'Hacker',
            'email' => 'hacker@evil.test',
            'password' => 'K@lingaTest2026!x',
            'password_confirmation' => 'K@lingaTest2026!x',
            'requested_role' => 'system_admin',
            'region' => 'NCR',
            'municipality' => 'Manila',
        ])->assertSessionHasErrors('requested_role');

        $this->assertNull(User::where('email', 'hacker@evil.test')->first());
    }

    public function test_pending_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'pending@lgu.test',
            'active' => false,
            'account_status' => 'pending',
        ]);

        $this->post(route('login'), [
            'email' => 'pending@lgu.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_declined_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'declined@lgu.test',
            'active' => false,
            'account_status' => 'declined',
        ]);

        $this->post(route('login'), [
            'email' => 'declined@lgu.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_admin_can_approve_registration(): void
    {
        $admin = User::factory()->create(['role' => 'system_admin', 'active' => true, 'account_status' => 'approved']);
        $pending = User::factory()->create([
            'active' => false, 'account_status' => 'pending', 'requested_role' => 'social_worker',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.registrations.approve', $pending->id), ['role' => 'social_worker'])
            ->assertRedirect();

        $pending->refresh();
        $this->assertTrue($pending->active);
        $this->assertEquals('approved', $pending->account_status);
        $this->assertEquals('social_worker', $pending->role);
        $this->assertEquals($admin->id, $pending->reviewed_by);
    }

    public function test_admin_can_decline_registration(): void
    {
        $admin = User::factory()->create(['role' => 'system_admin', 'active' => true, 'account_status' => 'approved']);
        $pending = User::factory()->create([
            'active' => false, 'account_status' => 'pending', 'requested_role' => 'social_worker',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.registrations.decline', $pending->id))
            ->assertRedirect();

        $pending->refresh();
        $this->assertFalse($pending->active);
        $this->assertEquals('declined', $pending->account_status);
    }

    public function test_non_admin_cannot_approve(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'active' => true, 'account_status' => 'approved']);
        $pending = User::factory()->create(['active' => false, 'account_status' => 'pending']);

        $this->actingAs($worker)
            ->post(route('admin.registrations.approve', $pending->id), ['role' => 'social_worker'])
            ->assertForbidden();
    }

    public function test_login_page_shows_register_link(): void
    {
        $this->get(route('login'))->assertInertia(fn ($page) => $page
            ->where('registrationEnabled', true)
        );
    }

    public function test_registration_requires_region_and_municipality(): void
    {
        $this->post(route('register'), [
            'name' => 'Test',
            'email' => 'test@lgu.test',
            'password' => 'K@lingaTest2026!x',
            'password_confirmation' => 'K@lingaTest2026!x',
            'requested_role' => 'social_worker',
        ])->assertSessionHasErrors(['region', 'municipality']);
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->post(route('register'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'requested_role' => 'social_worker',
            'region' => 'NCR',
            'municipality' => 'Manila',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}

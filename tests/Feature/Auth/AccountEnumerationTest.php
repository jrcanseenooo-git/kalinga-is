<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountEnumerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_error_does_not_reveal_whether_email_exists(): void
    {
        User::factory()->create(['email' => 'real@test.com', 'active' => true, 'password' => bcrypt('password')]);

        $realResponse = $this->post(route('login'), ['email' => 'real@test.com', 'password' => 'wrong']);
        $fakeResponse = $this->post(route('login'), ['email' => 'fake@test.com', 'password' => 'wrong']);

        $realErrors = $realResponse->assertSessionHasErrors('email')->assertStatus(302);
        $fakeErrors = $fakeResponse->assertSessionHasErrors('email')->assertStatus(302);
    }

    public function test_password_reset_does_not_reveal_whether_email_exists(): void
    {
        User::factory()->create(['email' => 'real@test.com', 'active' => true]);

        $realResponse = $this->post(route('password.email'), ['email' => 'real@test.com']);
        $fakeResponse = $this->post(route('password.email'), ['email' => 'nonexistent@test.com']);

        $realResponse->assertStatus(302);
        $fakeResponse->assertStatus(302);
    }

    public function test_duplicate_registration_returns_same_message_as_new(): void
    {
        User::factory()->create(['email' => 'existing@test.com', 'active' => true]);

        $data = [
            'name' => 'Test User',
            'email' => 'existing@test.com',
            'password' => 'K@lingaTest2026!x',
            'password_confirmation' => 'K@lingaTest2026!x',
            'requested_role' => 'social_worker',
            'region' => 'Region V',
            'province' => 'Albay',
            'municipality' => 'Legazpi City',
        ];

        $response = $this->post(route('register'), $data);
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_inactive_user_login_does_not_reveal_account_status(): void
    {
        User::factory()->create([
            'email' => 'pending@test.com',
            'active' => false,
            'account_status' => 'pending',
            'password' => bcrypt('password'),
        ]);

        $response = $this->post(route('login'), ['email' => 'pending@test.com', 'password' => 'password']);
        $response->assertSessionHasErrors('email');
    }
}

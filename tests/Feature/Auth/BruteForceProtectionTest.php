<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Guessing protection for sign-in, verification codes and recovery codes
 * (vulnerability assessment: brute force / credential stuffing).
 */
class BruteForceProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kalinga.mfa_enforcement' => true]);
        RateLimiter::clear('login-account:target@kalinga.test');
    }

    private function userWithCode(string $code = '123456'): User
    {
        $user = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        User::where('id', $user->id)->update(['two_factor_email_code' => encrypt($code), 'two_factor_email_code_expires_at' => now()->addMinutes(10)]);
        RateLimiter::clear('two-factor:' . $user->id);

        return $user->fresh();
    }

    public function test_repeated_wrong_codes_end_the_session_and_require_the_password_again(): void
    {
        $user = $this->userWithCode('482913');
        for ($i = 1; $i < TwoFactorController::MAX_CODE_ATTEMPTS; $i++) {
            $this->actingAs($user)->post(route('two-factor.verify'), ['code' => sprintf('%06d', $i), 'method' => 'email'])->assertSessionHasErrors('code');
            $this->assertAuthenticated();
        }
        $this->actingAs($user)->post(route('two-factor.verify'), ['code' => '000000', 'method' => 'email'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'two_factor.locked', 'user_id' => $user->id]);
    }

    public function test_correct_code_clears_the_counter_and_renews_the_session(): void
    {
        $user = $this->userWithCode('482913');
        $this->actingAs($user)->post(route('two-factor.verify'), ['code' => '111111', 'method' => 'email']);
        $before = session()->getId();
        $this->actingAs($user)->post(route('two-factor.verify'), ['code' => '482913', 'method' => 'email'])->assertRedirect();
        $this->assertTrue(session('two_factor_confirmed'));
        $this->assertNotSame($before, session()->getId());
        $this->assertSame(0, RateLimiter::attempts('two-factor:' . $user->id));
    }

    public function test_recovery_code_is_single_use(): void
    {
        $user = $this->userWithCode();
        User::where('id', $user->id)->update(['two_factor_recovery_codes' => encrypt(json_encode(['ABCDEFGHIJ', 'KLMNOPQRST']))]);
        $this->actingAs($user->fresh())->post(route('two-factor.verify'), ['recovery_code' => 'ABCDEFGHIJ'])->assertRedirect();
        $this->assertSame(['KLMNOPQRST'], json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true));
        session()->forget('two_factor_confirmed');
        $this->actingAs($user->fresh())->post(route('two-factor.verify'), ['recovery_code' => 'ABCDEFGHIJ'])->assertSessionHasErrors('code');
    }

    public function test_one_account_is_locked_after_failures_from_many_addresses(): void
    {
        User::factory()->create(['email' => 'target@kalinga.test', 'password' => bcrypt('Correct-Horse-9!')]);
        for ($i = 0; $i < LoginRequest::ACCOUNT_ATTEMPTS; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])->post('/login', ['email' => 'target@kalinga.test', 'password' => 'wrong-' . $i]);
        }
        // Even the right password from a fresh address is refused while the account is locked.
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->post('/login', ['email' => 'target@kalinga.test', 'password' => 'Correct-Horse-9!'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}

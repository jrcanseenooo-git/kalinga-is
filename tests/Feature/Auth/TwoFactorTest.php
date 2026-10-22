<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kalinga.mfa_enforcement' => true]);
    }

    public function test_privileged_role_is_redirected_to_challenge(): void
    {
        $admin = User::factory()->create(['role' => 'system_admin', 'active' => true]);

        $this->actingAs($admin)->get(route('admin.index'))->assertRedirect(route('two-factor.challenge'));
    }

    public function test_challenge_sends_email_otp_for_email_method(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => 'system_admin', 'active' => true]);

        $this->actingAs($admin)->get(route('two-factor.challenge'))->assertOk();

        Notification::assertSentTo($admin, TwoFactorCodeNotification::class);
        $this->assertNotNull($admin->fresh()->two_factor_email_code);
    }

    public function test_email_otp_can_be_verified(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => 'system_admin', 'active' => true]);

        $code = '123456';
        User::where('id', $admin->id)->update([
            'two_factor_email_code' => encrypt($code),
            'two_factor_email_code_expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($admin)
            ->post(route('two-factor.verify'), ['code' => $code, 'method' => 'email'])
            ->assertRedirect(route('dashboard'));

        $this->assertNull($admin->fresh()->two_factor_email_code);
    }

    public function test_expired_email_otp_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'system_admin', 'active' => true]);

        User::where('id', $admin->id)->update([
            'two_factor_email_code' => encrypt('123456'),
            'two_factor_email_code_expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)
            ->post(route('two-factor.verify'), ['code' => '123456', 'method' => 'email'])
            ->assertSessionHasErrors('code');
    }

    public function test_mfa_setup_renders_for_totp_opt_in(): void
    {
        $admin = User::factory()->create(['role' => 'system_admin', 'active' => true]);

        $this->actingAs($admin)->get(route('two-factor.setup'))->assertOk();
    }

    public function test_totp_can_be_confirmed_and_sets_method(): void
    {
        $g2fa = new Google2FA;
        $secret = $g2fa->generateSecretKey();

        $admin = User::factory()->create(['role' => 'system_admin', 'active' => true, 'two_factor_secret' => encrypt($secret)]);
        $code = $g2fa->getCurrentOtp($secret);

        $response = $this->actingAs($admin)->post(route('two-factor.confirm'), ['code' => $code]);

        $response->assertOk();
        $fresh = $admin->fresh();
        $this->assertNotNull($fresh->two_factor_confirmed_at);
        $this->assertEquals('totp', $fresh->two_factor_method);
    }

    public function test_totp_challenge_blocks_access_until_verified(): void
    {
        $g2fa = new Google2FA;
        $secret = $g2fa->generateSecretKey();

        $admin = User::factory()->create([
            'role' => 'system_admin', 'active' => true,
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(['RECOVERY01'])),
            'two_factor_method' => 'totp',
        ]);

        $this->actingAs($admin)->get(route('admin.index'))->assertRedirect(route('two-factor.challenge'));
    }

    public function test_totp_challenge_can_be_passed_with_valid_code(): void
    {
        $g2fa = new Google2FA;
        $secret = $g2fa->generateSecretKey();

        $admin = User::factory()->create([
            'role' => 'system_admin', 'active' => true,
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(['RECOVERY01'])),
            'two_factor_method' => 'totp',
        ]);

        $code = $g2fa->getCurrentOtp($secret);

        $this->actingAs($admin)->post(route('two-factor.verify'), ['code' => $code])->assertRedirect(route('dashboard'));
        $this->actingAs($admin)->withSession(['two_factor_confirmed' => true])
            ->get(route('admin.index'))->assertOk();
    }

    public function test_recovery_code_accepted_for_totp_user(): void
    {
        $g2fa = new Google2FA;
        $secret = $g2fa->generateSecretKey();

        $admin = User::factory()->create([
            'role' => 'system_admin', 'active' => true,
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(['RECOVERY01', 'RECOVERY02'])),
            'two_factor_method' => 'totp',
        ]);

        $this->actingAs($admin)->post(route('two-factor.verify'), ['recovery_code' => 'RECOVERY01'])->assertRedirect(route('dashboard'));

        $remaining = json_decode(decrypt($admin->fresh()->two_factor_recovery_codes), true);
        $this->assertCount(1, $remaining);
        $this->assertNotContains('RECOVERY01', $remaining);
    }

    public function test_role_outside_the_configured_list_is_not_challenged(): void
    {
        config(['kalinga.mfa_roles' => ['system_admin', 'social_worker', 'fo_user']]);
        $user = User::factory()->create(['role' => 'cpu_member', 'active' => true]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('mdt.collaboration.index'));
    }

    public function test_cpu_members_are_challenged_by_default_because_referrals_show_case_details(): void
    {
        $this->assertContains('cpu_member', config('kalinga.mfa_roles'));
        $user = User::factory()->create(['role' => 'cpu_member', 'active' => true]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));
    }

    public function test_totp_user_can_fall_back_to_email_on_challenge(): void
    {
        Notification::fake();

        $g2fa = new Google2FA;
        $secret = $g2fa->generateSecretKey();

        $admin = User::factory()->create([
            'role' => 'system_admin', 'active' => true,
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(['R1'])),
            'two_factor_method' => 'totp',
        ]);

        $response = $this->actingAs($admin)->get(route('two-factor.challenge', ['via' => 'email']));
        $response->assertOk();

        Notification::assertSentTo($admin, TwoFactorCodeNotification::class);
    }

    public function test_resend_email_otp_sends_new_code(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => 'system_admin', 'active' => true]);

        $this->actingAs($admin)->post(route('two-factor.email-resend'))->assertRedirect();

        Notification::assertSentTo($admin, TwoFactorCodeNotification::class);
    }

    public function test_reset_authenticator_switches_to_email_method(): void
    {
        $admin = User::factory()->create([
            'role' => 'system_admin', 'active' => true,
            'two_factor_secret' => encrypt('TESTSECRET'),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(['R1'])),
            'two_factor_method' => 'totp',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($admin)
            ->withSession(['two_factor_confirmed' => true])
            ->post(route('two-factor.reset'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));

        $fresh = $admin->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertEquals('email', $fresh->two_factor_method);
    }
}

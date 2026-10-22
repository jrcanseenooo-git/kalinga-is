<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TwoFactorOtpRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kalinga.mfa_enforcement' => true]);
    }

    private function adminWithEmailOtp(string $code = '123456'): User
    {
        $admin = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        User::where('id', $admin->id)->update([
            'two_factor_email_code' => encrypt($code),
            'two_factor_email_code_expires_at' => now()->addMinutes(10),
        ]);

        return $admin;
    }

    public function test_valid_otp_accepted_on_first_attempt(): void
    {
        $admin = $this->adminWithEmailOtp('654321');

        $response = $this->actingAs($admin)
            ->post(route('two-factor.verify'), ['code' => '654321', 'method' => 'email']);

        $response->assertRedirect(route('dashboard'));
        $this->assertTrue(session('two_factor_confirmed'));
        $this->assertNull($admin->fresh()->two_factor_email_code);
    }

    public function test_invalid_otp_rejected_with_error(): void
    {
        $admin = $this->adminWithEmailOtp('654321');

        $response = $this->actingAs($admin)
            ->post(route('two-factor.verify'), ['code' => '000000', 'method' => 'email']);

        $response->assertSessionHasErrors('code');
        $this->assertNotNull($admin->fresh()->two_factor_email_code);
    }

    public function test_correct_code_works_after_wrong_attempt(): void
    {
        $admin = $this->adminWithEmailOtp('654321');

        $this->actingAs($admin)
            ->post(route('two-factor.verify'), ['code' => '111111', 'method' => 'email'])
            ->assertSessionHasErrors('code');

        $response = $this->actingAs($admin)
            ->withSession([])
            ->post(route('two-factor.verify'), ['code' => '654321', 'method' => 'email']);

        $response->assertRedirect(route('dashboard'));
    }

    public function test_empty_code_rejected(): void
    {
        $admin = $this->adminWithEmailOtp();

        $response = $this->actingAs($admin)
            ->post(route('two-factor.verify'), ['code' => '', 'method' => 'email']);

        $response->assertSessionHasErrors('code');
    }

    public function test_code_cleared_after_successful_verification(): void
    {
        $admin = $this->adminWithEmailOtp('999999');

        $this->actingAs($admin)
            ->post(route('two-factor.verify'), ['code' => '999999', 'method' => 'email']);

        $fresh = $admin->fresh();
        $this->assertNull($fresh->two_factor_email_code);
        $this->assertNull($fresh->two_factor_email_code_expires_at);
    }

    public function test_code_preserved_after_failed_verification(): void
    {
        $admin = $this->adminWithEmailOtp('999999');

        $this->actingAs($admin)
            ->post(route('two-factor.verify'), ['code' => '000000', 'method' => 'email']);

        $this->assertNotNull($admin->fresh()->two_factor_email_code);
    }

    public function test_resend_generates_new_code(): void
    {
        Notification::fake();

        $admin = $this->adminWithEmailOtp('111111');
        $originalCode = $admin->fresh()->two_factor_email_code;

        $this->actingAs($admin)->post(route('two-factor.email-resend'));

        Notification::assertSentTo($admin, TwoFactorCodeNotification::class);
        $this->assertNotEquals($originalCode, $admin->fresh()->two_factor_email_code);
    }

    public function test_missing_code_and_recovery_returns_error(): void
    {
        $admin = $this->adminWithEmailOtp();

        $response = $this->actingAs($admin)
            ->post(route('two-factor.verify'), ['method' => 'email']);

        $response->assertSessionHasErrors('code');
    }
}

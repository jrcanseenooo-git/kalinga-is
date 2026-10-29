<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use App\Services\LguAssignmentScope;
use App\Services\MonitoringScope;
use App\Services\UatAccounts;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UatAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        config(['kalinga.uat_enabled' => true, 'app.debug' => false]);
        $this->admin = User::factory()->create(['role' => 'system_admin', 'active' => true]);
    }

    private function createRoster(): array
    {
        return app(UatAccounts::class)->create($this->admin, CarbonImmutable::now()->addDay());
    }

    public function test_exact_roster_counts_password_hashing_scope_and_no_secrets_in_audit(): void
    {
        $credentials = $this->createRoster();
        $this->assertCount(43, $credentials);
        $this->assertSame(43, User::whereNotNull('uat_key')->count());
        $this->assertSame(13, User::whereNotNull('uat_key')->where('role', 'social_worker')->count());
        $this->assertSame(17, User::whereNotNull('uat_key')->where('role', 'cpu_member')->count());
        $this->assertSame(9, User::whereNotNull('uat_key')->where('role', 'lgu_head')->count());
        $this->assertSame(4, User::whereNotNull('uat_key')->where('role', 'fo_user')->count());
        $this->assertSame(39, DB::table('user_lgu_assignments')->count());
        $this->assertSame(39, DB::table('lgu_coordination_members')->count());
        $this->assertSame(4, DB::table('lgu_units')->count());
        $this->assertCount(43, array_unique(array_column($credentials, 'password')));
        $audit = DB::table('audit_logs')->get()->toJson();
        foreach ($credentials as $credential) {
            $user = User::where('email', $credential['email'])->firstOrFail();
            $this->assertTrue(Hash::check($credential['password'], $user->password));
            $this->assertTrue(UatAccounts::usable($user));
            $this->assertNull($user->email_verified_at, 'Test identifiers must not be represented as verified mailboxes.');
            $this->assertStringNotContainsString($credential['password'], $audit);
            if ($user->role === 'fo_user') {
                $this->assertSame([$user->region], app(MonitoringScope::class)->regions($user));
            } else {
                $ids = app(LguAssignmentScope::class)->activeLguIds($user);
                $this->assertCount(1, $ids);
                $this->assertSame($credential['psgc_code'], DB::table('lgu_units')->where('id', $ids[0])->value('psgc_code'));
            }
        }
        $this->assertSame(43, DB::table('audit_logs')->where('event', 'user.uat_created')->count());
        $this->assertNull($this->admin->fresh()->uat_key);
    }

    public function test_repeated_creation_preserves_existing_passwords(): void
    {
        $this->createRoster();
        $before = User::whereNotNull('uat_key')->pluck('password', 'email')->all();
        try {
            $this->createRoster();
            $this->fail('Repeat creation must be refused.');
        } catch (ValidationException) {
            $this->assertSame($before, User::whereNotNull('uat_key')->pluck('password', 'email')->all());
        }
    }

    public function test_unmarked_email_collision_prevents_all_creation(): void
    {
        $existing = User::factory()->create(['email' => 'car-cm01@uat.kalinga.test', 'role' => 'social_worker']);
        try {
            $this->createRoster();
            $this->fail('Email collision must be refused.');
        } catch (ValidationException) {
            $this->assertSame(0, User::whereNotNull('uat_key')->count());
            $this->assertNull($existing->fresh()->uat_key);
            $this->assertFalse(UatAccounts::usable($existing));
        }
    }

    public function test_lgu_collision_rolls_back_earlier_regions(): void
    {
        DB::table('lgu_units')->insert(['psgc_code' => 'LEGACY-MIM', 'name' => "Brooke's Point", 'province' => 'Palawan', 'region' => 'MIMAROPA Region', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        try {
            $this->createRoster();
            $this->fail('LGU mismatch must require review.');
        } catch (ValidationException) {
            $this->assertSame(0, User::whereNotNull('uat_key')->count());
            $this->assertSame(1, DB::table('lgu_units')->count());
            $this->assertSame(0, DB::table('audit_logs')->where('event', 'user.uat_created')->count());
        }
    }

    public function test_only_enabled_administrator_can_create_and_receive_no_store_credentials(): void
    {
        $pm = User::factory()->create(['role' => 'project_manager', 'active' => true]);
        $payload = ['expires_at' => now('Asia/Manila')->addDay()->format('Y-m-d\TH:i'), 'confirmed' => true];
        $this->actingAs($pm)->withSession(['two_factor_confirmed' => true])->postJson(route('admin.uat.store'), $payload)->assertForbidden();
        config(['kalinga.uat_enabled' => false]);
        $this->actingAs($this->admin)->postJson(route('admin.uat.store'), $payload)->assertForbidden();
        config(['kalinga.uat_enabled' => true]);
        $this->postJson(route('admin.uat.store'), array_merge($payload, ['confirmed' => false]))->assertUnprocessable();
        $response = $this->postJson(route('admin.uat.store'), $payload)->assertOk()->assertJsonCount(43, 'accounts');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_roster_accounts_display_working_codes_without_smtp_even_in_production(): void
    {
        Notification::fake();
        $this->createRoster();
        $this->app['env'] = 'production';
        $user = User::where('uat_key', 'car-cm01')->firstOrFail();
        $this->actingAs($user)->get(route('two-factor.challenge'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('TwoFactor/Challenge')->where('uatCode', fn ($code) => is_string($code) && strlen($code) === 6)->missing('debugCode'));
        Notification::assertNothingSent();
        $code = decrypt($user->fresh()->two_factor_email_code);
        $this->withSession(['_token' => 'uat-test-csrf'])->post(route('two-factor.verify'), ['_token' => 'uat-test-csrf', 'code' => $code, 'method' => 'email'])->assertRedirect(route('dashboard'));
        $this->assertNull($user->fresh()->two_factor_email_code);
        $this->assertDatabaseHas('audit_logs', ['event' => 'two_factor.uat_verified', 'user_id' => $user->id]);
    }

    public function test_personal_accounts_never_get_uat_codes_and_production_debug_does_not_disclose_codes(): void
    {
        Notification::fake();
        $this->app['env'] = 'production';
        config(['app.debug' => true]);
        $this->actingAs($this->admin)->get(route('two-factor.challenge'))->assertOk()->assertInertia(fn (Assert $page) => $page->missing('uatCode')->missing('debugCode'));
        Notification::assertSentTo($this->admin, TwoFactorCodeNotification::class);
    }

    public function test_expiry_or_disabled_switch_ends_existing_uat_session(): void
    {
        $this->createRoster();
        $user = User::where('uat_key', 'car-cm01')->firstOrFail();
        $this->actingAs($user)->withSession(['two_factor_confirmed' => true]);
        $user->forceFill(['uat_expires_at' => now()->subMinute()])->save();
        $this->getJson(route('dashboard'))->assertForbidden();
        $this->assertGuest();
        $user->forceFill(['uat_expires_at' => now()->addDay()])->save();
        $this->actingAs($user)->withSession(['two_factor_confirmed' => true]);
        config(['kalinga.uat_enabled' => false]);
        $this->getJson(route('dashboard'))->assertForbidden();
        $this->assertGuest();
    }

    public function test_invalid_expiry_or_changed_role_cannot_use_uat_access(): void
    {
        foreach ([CarbonImmutable::now()->subMinute(), CarbonImmutable::now()->addDays(8)] as $expiry) {
            try {
                app(UatAccounts::class)->create($this->admin, $expiry);
                $this->fail('Invalid expiry must fail.');
            } catch (ValidationException) {
                $this->assertSame(0, User::whereNotNull('uat_key')->count());
            }
        }
        $this->createRoster();
        $user = User::where('uat_key', 'car-cm01')->firstOrFail();
        $user->forceFill(['role' => 'system_admin'])->save();
        $this->assertFalse(UatAccounts::usable($user));
    }
}

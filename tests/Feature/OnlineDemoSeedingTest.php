<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** kalinga:seed-demo as the online demo server runs it on every start. */
class OnlineDemoSeedingTest extends TestCase
{
    use RefreshDatabase;

    private const ENV = [
        'DEMO_SEED' => 'true',
        'DEMO_PASSWORD' => 'Demo-Kalinga-2026!',
        'DEMO_ACCOUNT_EMAIL' => 'presenter@example.com',
        'DEMO_WORKER_EMAIL' => 'presenter.google@example.com',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENV as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $this->seed(ReferenceDataSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::ENV) as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        parent::tearDown();
    }

    private function activeLgus(User $user): array
    {
        return DB::table('user_lgu_assignments')->join('lgu_units', 'lgu_units.id', '=', 'user_lgu_assignments.lgu_id')
            ->where('user_id', $user->id)->where('user_lgu_assignments.active', true)->pluck('lgu_units.name')->all();
    }

    public function test_fresh_demo_uses_real_addresses_strong_password_and_tabuk_scope(): void
    {
        $this->artisan('kalinga:seed-demo')->assertSuccessful();

        $worker = User::where('email', 'presenter.google@example.com')->firstOrFail();
        $this->assertSame('social_worker', $worker->role);
        $this->assertTrue(Hash::check('Demo-Kalinga-2026!', $worker->password));
        $this->assertFalse(Hash::check('password', $worker->password));
        $this->assertNotNull(User::where('email', 'presenter+admin@example.com')->where('role', 'system_admin')->first());
        $this->assertSame(0, User::where('email', 'like', '%@kalinga.test')->count());
        $this->assertSame(['City of Tabuk'], $this->activeLgus($worker));
        $this->assertSame(1, DB::table('lgu_coordination_members')->where('user_id', $worker->id)->whereNull('left_at')->count());
    }

    public function test_rerun_repairs_an_older_demo_with_extra_lgu_assignments(): void
    {
        $this->artisan('kalinga:seed-demo')->assertSuccessful();
        $worker = User::where('email', 'presenter.google@example.com')->firstOrFail();
        // An older online demo gave the worker every demo LGU.
        $rizal = DB::table('lgu_units')->where('psgc_code', 'DEMO-LGU-02')->value('id');
        DB::table('user_lgu_assignments')->insert(['user_id' => $worker->id, 'lgu_id' => $rizal, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $cases = DB::table('cases')->count();

        $this->artisan('kalinga:seed-demo')->expectsOutputToContain('already present')->assertSuccessful();

        $this->assertSame(['City of Tabuk'], $this->activeLgus($worker));
        $this->assertSame($cases, DB::table('cases')->count());
        $this->assertSame(1, User::where('role', 'system_admin')->count());
    }
}

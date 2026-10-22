<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JurisdictionAndAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    private function createCase(User $worker, string $municipality): int
    {
        $clientId = DB::table('clients')->insertGetId([
            'first_name' => 'Protected',
            'last_name' => $municipality,
            'municipality' => $municipality,
            'province' => 'Kalinga',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('cases')->insertGetId([
            'case_number' => 'KLG-2026-SCOPE-' . str_pad((string) $clientId, 3, '0', STR_PAD_LEFT),
            'client_id' => $clientId,
            'assigned_to' => $worker->id,
            'status' => 'OPEN',
            'stage' => 'intake',
            'priority' => 'normal',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_social_worker_without_lgu_assignment_cannot_open_casework_from_legacy_municipality_text(): void
    {
        $worker = User::factory()->create(['role' => 'fo_user']);
        $supervisor = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk City', 'account_status' => 'fixture_unassigned']);
        $legacyCase = $this->createCase($worker, 'Tabuk City');

        $this->actingAs($supervisor)->get(route('cases.show', $legacyCase))->assertForbidden();
    }

    public function test_deactivated_account_cannot_continue_an_authenticated_session(): void
    {
        $inactive = User::factory()->create(['role' => 'social_worker', 'active' => false]);

        $this->actingAs($inactive)->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_administrator_must_submit_a_valid_psgc_jurisdiction_hierarchy(): void
    {
        $administrator = User::factory()->create(['role' => 'system_admin']);
        $worker = User::factory()->create(['role' => 'social_worker']);

        $this->actingAs($administrator)->patch(route('admin.users.update', $worker), [
            'role' => 'fo_user', 'active' => true,
            'region' => 'Cordillera Administrative Region (CAR)', 'province' => 'Kalinga', 'municipality' => 'City of Tabuk',
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $worker->id, 'municipality' => 'City of Tabuk']);

        $this->actingAs($administrator)->patch(route('admin.users.update', $worker), [
            'role' => 'fo_user', 'active' => true,
            'region' => 'Cordillera Administrative Region (CAR)', 'province' => 'Kalinga', 'municipality' => 'Caloocan City',
        ])->assertSessionHasErrors('municipality');
    }
}

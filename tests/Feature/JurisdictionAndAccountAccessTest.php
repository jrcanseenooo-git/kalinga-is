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

    public function test_field_office_accounts_need_a_valid_psgc_region_and_keep_no_lower_level(): void
    {
        $administrator = User::factory()->create(['role' => 'system_admin']);
        $worker = User::factory()->create(['role' => 'social_worker']);

        $this->actingAs($administrator)->patch(route('admin.users.update', $worker), [
            'role' => 'fo_user', 'active' => true,
            'region' => 'Cordillera Administrative Region (CAR)', 'province' => 'Kalinga', 'municipality' => 'City of Tabuk',
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $worker->id, 'region' => 'Cordillera Administrative Region (CAR)', 'province' => null, 'municipality' => null]);

        $this->actingAs($administrator)->patch(route('admin.users.update', $worker), ['role' => 'fo_user', 'active' => true, 'region' => 'Not a PSGC region'])->assertSessionHasErrors('region');
        $this->actingAs($administrator)->patch(route('admin.users.update', $worker), ['role' => 'fo_user', 'active' => true])->assertStatus(422);
    }

    public function test_project_manager_manages_only_field_accounts_in_their_regions(): void
    {
        $car = DB::table('lgu_units')->insertGetId(['psgc_code' => 'SYN-CAR-1', 'name' => 'Synthetic CAR Town', 'province' => 'Kalinga', 'region' => 'Cordillera Administrative Region (CAR)', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $r8 = DB::table('lgu_units')->insertGetId(['psgc_code' => 'SYN-R8-1', 'name' => 'Synthetic Leyte Town', 'province' => 'Leyte', 'region' => 'Region VIII (Eastern Visayas)', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $manager = User::factory()->create(['role' => 'project_manager', 'region_scope' => ['Cordillera Administrative Region (CAR)']]);
        $inside = User::factory()->create(['role' => 'cpu_member']);
        $outside = User::factory()->create(['role' => 'cpu_member']);
        foreach ([[$inside, $car], [$outside, $r8]] as [$user, $lgu]) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $pending = User::factory()->create(['role' => 'cpu_member', 'active' => false, 'account_status' => 'pending', 'requested_role' => 'cpu_member', 'region' => 'Cordillera Administrative Region (CAR)']);
        $pendingElsewhere = User::factory()->create(['role' => 'cpu_member', 'active' => false, 'account_status' => 'pending', 'requested_role' => 'cpu_member', 'region' => 'Region VIII (Eastern Visayas)']);

        $this->actingAs($manager)->get(route('admin.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('users', fn ($users) => collect($users)->pluck('id')->contains($inside->id) && !collect($users)->pluck('id')->contains($outside->id))
            ->where('pendingRegistrations', fn ($rows) => collect($rows)->pluck('id')->all() === [$pending->id]));
        $this->post(route('admin.registrations.approve', $pending), ['role' => 'cpu_member'])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $pending->id, 'account_status' => 'approved', 'active' => true]);
        $this->post(route('admin.registrations.approve', $pendingElsewhere), ['role' => 'cpu_member'])->assertNotFound();
        $this->post(route('admin.registrations.approve', $pending), ['role' => 'system_admin'])->assertSessionHasErrors('role');
        $this->patch(route('admin.users.update', $outside), ['role' => 'cpu_member', 'active' => false])->assertNotFound();
        $this->get(route('admin.forms'))->assertForbidden();
        $this->get(route('admin.audits'))->assertForbidden();
    }
}

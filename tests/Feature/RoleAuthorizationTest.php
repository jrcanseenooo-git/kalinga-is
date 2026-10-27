<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RolePermissions;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $worker, string $municipality = 'Tabuk City'): int
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'Role', 'last_name' => 'Protected', 'municipality' => $municipality, 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-RBAC-' . str_pad((string) $client, 2, '0', STR_PAD_LEFT), 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_system_administrator_can_administer_but_cannot_read_case_content(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $administrator = User::factory()->create(['role' => 'system_admin']);
        $case = $this->caseFor($worker);

        $this->actingAs($administrator)->get(route('admin.index'))->assertOk();
        $this->actingAs($administrator)->get(route('cases.show', $case))->assertForbidden();
    }

    public function test_system_administrator_lands_on_a_governance_overview_not_casework_dashboard(): void
    {
        $administrator = User::factory()->create(['role' => 'system_admin']);

        // The administrator's dashboard is a to-do list with no casework.
        $this->actingAs($administrator)->get(route('dashboard'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('metrics.activeCases', null)->has('actionQueue', 0)->missing('cases'));
        $this->actingAs($administrator)->get(route('admin.overview'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Overview')->has('metrics')->missing('cases')->missing('clients'));
    }

    public function test_social_worker_is_jurisdiction_scoped_not_globally_privileged(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $scopedWorker = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']);
        $localCase = $this->caseFor($worker, 'Tabuk City');
        $outsideCase = $this->caseFor($worker, 'Pasil');
        DB::table('clients')->where('id', DB::table('cases')->where('id', $outsideCase)->value('client_id'))->update(['province' => 'Apayao']);

        $this->actingAs($scopedWorker)->get(route('cases.show', $localCase))->assertOk();
        $this->actingAs($scopedWorker)->get(route('cases.show', $outsideCase))->assertForbidden();
    }

    public function test_role_catalogue_seeds_every_baseline_role_and_removes_legacy_cefmu_admin(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->assertDatabaseCount('roles', count(RolePermissions::ROLES));
        $this->assertDatabaseMissing('roles', ['code' => 'cefm_admin']);
        $this->assertDatabaseHas('roles', ['code' => 'social_worker']);
        $this->assertDatabaseHas('roles', ['code' => 'cpu_member']);
        $this->assertDatabaseHas('roles', ['code' => 'fo_user']);
    }
}

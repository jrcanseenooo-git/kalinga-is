<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ManagementAnalyticsPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function cases(User $worker, int $count, string $municipality): void
    {
        for ($i = 0; $i < $count; $i++) {
            $client = DB::table('clients')->insertGetId(['first_name' => 'Private' . $i, 'last_name' => 'Child', 'municipality' => $municipality, 'region' => 'CAR', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('cases')->insert(['case_number' => 'KLG-MGMT-' . uniqid(), 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_management_kpis_and_export_suppress_a_narrow_low_count_filter(): void
    {
        $admin = User::factory()->create(['role' => 'system_admin']);
        $worker = User::factory()->create(['role' => 'social_worker']);
        $this->cases($worker, 4, 'Smallville');

        $this->actingAs($admin)->get(route('management.dashboard', ['municipality' => 'Smallville']))
            ->assertOk()->assertSee('Suppressed')->assertDontSee('Private0');
        $this->actingAs($admin)->get(route('management.dashboard.export', ['municipality' => 'Smallville']))
            ->assertOk()->assertSee('Suppressed')->assertDontSee('Private0');
    }

    public function test_management_dashboard_requires_its_dedicated_permission(): void
    {
        $viewer = User::factory()->create(['role' => 'cpu_member']);
        $this->actingAs($viewer)->get(route('management.dashboard'))->assertForbidden();
        $this->actingAs($viewer)->get(route('management.dashboard.export'))->assertForbidden();
    }

    public function test_management_workspace_is_split_into_authorized_aggregate_only_views(): void
    {
        $admin = User::factory()->create(['role' => 'system_admin']);
        $worker = User::factory()->create(['role' => 'social_worker']);
        $this->cases($worker, 5, 'Tabuk City');

        $this->actingAs($admin)->get(route('management.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Management/Overview')->has('kpis')->missing('clients'));
        $this->actingAs($admin)->get(route('management.section', ['section' => 'geography']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Management/Section')->where('section', 'geography')->has('by_lgu', 1)->missing('by_status')->missing('clients'));
    }

    public function test_management_export_keeps_formula_looking_geography_labels_as_contextual_text(): void
    {
        $admin = User::factory()->create(['role' => 'system_admin']);
        $worker = User::factory()->create(['role' => 'social_worker']);
        $this->cases($worker, 5, '=1+1');

        $this->actingAs($admin)->get(route('management.dashboard.export'))
            ->assertOk()
            ->assertSee('"LGU: =1+1","5"', false);
    }
}

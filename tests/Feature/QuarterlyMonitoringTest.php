<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuarterlyMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_view_and_export_quarterly_aggregates_without_small_area_disclosure(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        foreach (range(1, 4) as $index) {
            $client = DB::table('clients')->insertGetId(['first_name' => "Client{$index}", 'last_name' => 'Quarterly', 'municipality' => 'Smalltown', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('cases')->insert(['case_number' => "KLG-2026-Q-{$index}", 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $administrator = User::factory()->create(['role' => 'system_admin']);

        $this->actingAs($administrator)->get(route('analytics.quarterly', ['year' => now()->year, 'quarter' => (int) ceil(now()->month / 3)]))
            ->assertOk()->assertDontSee('Smalltown');
        $this->actingAs($administrator)->get(route('analytics.quarterly.export', ['year' => now()->year, 'quarter' => (int) ceil(now()->month / 3)]))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertDontSee('Smalltown');
        $this->assertDatabaseHas('audit_logs', ['event' => 'analytics.quarterly_exported', 'user_id' => $administrator->id]);
    }

    public function test_viewer_cannot_open_quarterly_monitoring(): void
    {
        $viewer = User::factory()->create(['role' => 'cpu_member']);

        $this->actingAs($viewer)->get(route('analytics.quarterly'))->assertForbidden();
        $this->actingAs($viewer)->get(route('analytics.quarterly.export'))->assertForbidden();
    }
}

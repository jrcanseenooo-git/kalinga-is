<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_download_aggregate_csv_without_small_area_row(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        for ($i = 0; $i < 4; $i++) {
            $client = DB::table('clients')->insertGetId(['first_name' => 'A' . $i, 'last_name' => 'Test', 'municipality' => 'Smalltown', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('cases')->insert(['case_number' => 'KLG-2026-AN-' . $i, 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $administrator = User::factory()->create(['role' => 'system_admin']);
        $response = $this->actingAs($administrator)->get(route('analytics.export'));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertSee('"Total cases","Suppressed"', false)->assertDontSee('Smalltown');
        $this->assertDatabaseHas('audit_logs', ['event' => 'analytics.exported', 'user_id' => $administrator->id]);
    }

    public function test_viewer_cannot_open_private_analytics_or_export(): void
    {
        $viewer = User::factory()->create(['role' => 'cpu_member']);
        $this->actingAs($viewer)->get(route('analytics.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('analytics.export'))->assertForbidden();
    }

    public function test_case_worker_export_contains_only_assigned_case_aggregates(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $otherWorker = User::factory()->create(['role' => 'social_worker']);

        foreach ([[$worker, 'Mine'], [$otherWorker, 'Other']] as [$assignee, $firstName]) {
            $client = DB::table('clients')->insertGetId(['first_name' => $firstName, 'last_name' => 'Client', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('cases')->insert(['case_number' => 'KLG-2026-' . $firstName, 'client_id' => $client, 'assigned_to' => $assignee->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($worker)->get(route('analytics.export'))
            ->assertOk()
            ->assertSee('"Total cases","1"', false);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_dashboard_suppresses_small_groups_and_client_identity(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        for ($i = 0; $i < 4; $i++) {
            $client = DB::table('clients')->insertGetId(['first_name' => 'Private' . $i, 'last_name' => 'Resident', 'municipality' => 'Hiddenville', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('cases')->insert(['case_number' => 'KLG-2026-PUB-' . $i, 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $response = $this->get(route('public.dashboard'));
        $response->assertOk()->assertDontSee('Private0')->assertDontSee('Hiddenville');
    }

    public function test_public_dashboard_shows_eligible_aggregate_only(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        for ($i = 0; $i < 5; $i++) {
            $client = DB::table('clients')->insertGetId(['first_name' => 'Hidden' . $i, 'last_name' => 'Resident', 'municipality' => 'Publishedtown', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('cases')->insert(['case_number' => 'KLG-2026-PUB-X' . $i, 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->get(route('public.dashboard'))->assertOk()->assertSee('Publishedtown')->assertDontSee('Hidden0');
    }
}

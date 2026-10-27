<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_case_worker_search_is_limited_to_assigned_cases(): void
    {
        $viewer = User::factory()->create(['role' => 'social_worker']);
        $owner = User::factory()->create(['role' => 'social_worker']);
        $client = $this->client('Search', 'Target');
        $this->caseFor($client, $owner, 'KLG-2026-09999');

        $this->actingAs($viewer)->getJson(route('search', ['q' => 'Search']))
            ->assertOk()->assertJsonCount(0, 'cases');
    }

    public function test_case_worker_search_does_not_expose_another_workers_report(): void
    {
        $viewer = User::factory()->create(['role' => 'social_worker']);
        $owner = User::factory()->create(['role' => 'social_worker']);
        DB::table('reports')->insert(['reference_no' => 'RPT-PRIVATE-001', 'narrative' => 'Private report for access-control testing.', 'status' => 'FOR_SCREENING', 'priority' => 'normal', 'submitted_at' => now(), 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($viewer)->getJson(route('search', ['q' => 'PRIVATE']))
            ->assertOk()->assertJsonCount(0, 'reports');
    }

    public function test_monitoring_roles_cannot_use_the_case_manager_search(): void
    {
        $fieldOffice = User::factory()->create(['role' => 'fo_user', 'region' => 'Cordillera Administrative Region (CAR)']);
        $this->actingAs($fieldOffice)->getJson(route('search', ['q' => 'Province']))->assertForbidden();
    }

    public function test_municipal_worker_search_does_not_cross_lgu_report_boundary(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk City']);
        DB::table('reports')->insert([
            'reference_no' => 'RPT-OTHER-LGU-001',
            'narrative' => 'Access-control fixture only.',
            'status' => 'FOR_SCREENING',
            'priority' => 'normal',
            'is_public_submission' => true,
            'endorsed_municipality' => 'Rizal',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($worker)->getJson(route('search', ['q' => 'OTHER-LGU']))
            ->assertOk()->assertJsonCount(0, 'reports');
    }

    private function client(string $first, string $last, string $province = 'Kalinga'): int
    {
        return DB::table('clients')->insertGetId(['first_name' => $first, 'last_name' => $last, 'province' => $province, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function caseFor(int $client, User $owner, string $number): void
    {
        DB::table('cases')->insert(['case_number' => $number, 'client_id' => $client, 'assigned_to' => $owner->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CaseMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_worker_can_update_case_and_client_details(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Demo', 'last_name' => 'Original', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-01001', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($worker)->patch(route('cases.update', $case), ['first_name' => 'Demo', 'last_name' => 'Updated', 'birthdate' => '2014-01-01', 'sex' => 'Female', 'phone' => '09171234567', 'region' => 'Cordillera Administrative Region (CAR)', 'province' => 'Kalinga', 'municipality' => 'Tabuk City', 'barangay' => 'Bulanao Centro', 'category' => 'Child protection', 'priority' => 'high'])->assertRedirect();

        $this->assertDatabaseHas('clients', ['id' => $client, 'last_name' => 'Updated', 'province' => 'Kalinga', 'barangay' => 'Bulanao Centro']);
        $this->assertDatabaseHas('cases', ['id' => $case, 'priority' => 'high', 'category' => 'Child protection']);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'case', 'auditable_id' => $case, 'event' => 'case.updated']);
    }

    public function test_supervisor_archives_without_deleting_client_or_audit_history(): void
    {
        $supervisor = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk City']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Demo', 'last_name' => 'Archive', 'municipality' => 'Tabuk City', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-01002', 'client_id' => $client, 'assigned_to' => $supervisor->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($supervisor)->delete(route('cases.destroy', $case))->assertRedirect(route('cases.index'));

        $this->assertSoftDeleted('cases', ['id' => $case]);
        $this->assertDatabaseHas('clients', ['id' => $client]);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'case', 'auditable_id' => $case, 'event' => 'case.archived']);
    }
}

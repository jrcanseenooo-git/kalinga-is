<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CaseAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $worker): int
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'Mia', 'last_name' => 'Tan', 'municipality' => 'Tabuk City', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-ASSIGN-01', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_supervisor_can_reassign_case_and_notify_new_worker(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $supervisor = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']);
        $newWorker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($owner);
        // A valid transfer must leave the recipient inside the existing read scope.
        $lgu = $this->ensureNormalizedLguAssignment($owner);
        $this->ensureNormalizedLguAssignment($newWorker, $lgu);
        $this->actingAs($supervisor)->patch(route('cases.assign', $case), ['assigned_to' => $newWorker->id, 'expected_version' => 1])->assertRedirect();
        $this->assertDatabaseHas('cases', ['id' => $case, 'assigned_to' => $newWorker->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $newWorker->id, 'type' => 'case_assigned']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'case.reassigned', 'auditable_id' => $case]);
    }

    public function test_case_worker_cannot_reassign_case(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $other = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($owner);
        // cpu_member lacks cases.assign permission
        $cpuUser = User::factory()->create(['role' => 'cpu_member']);
        $this->actingAs($cpuUser)->patch(route('cases.assign', $case), ['assigned_to' => $other->id])->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RelationshipWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $owner): array
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'Mae', 'last_name' => 'Lim', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-REL-01', 'client_id' => $client, 'assigned_to' => $owner->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return [$case, $client];
    }

    public function test_worker_can_record_guardian_for_case_client(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        [$case, $client] = $this->caseFor($worker);
        $this->actingAs($worker)->post(route('cases.relationships.store', $case), ['name' => 'Rosa Lim', 'relationship_type' => 'Mother', 'phone' => '09171234567', 'is_guardian' => true])->assertRedirect();
        $this->assertDatabaseHas('relationships', ['client_id' => $client, 'name' => 'Rosa Lim', 'is_guardian' => true]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'relationship.recorded']);
    }

    public function test_viewer_cannot_add_relationship(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $viewer = User::factory()->create(['role' => 'cpu_member']);
        [$case] = $this->caseFor($worker);
        $this->actingAs($viewer)->post(route('cases.relationships.store', $case), ['name' => 'No Access', 'relationship_type' => 'Parent', 'is_guardian' => false])->assertForbidden();
    }
}

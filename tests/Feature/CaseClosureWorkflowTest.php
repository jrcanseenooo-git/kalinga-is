<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CaseClosureWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $worker): int
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'Ian', 'last_name' => 'Cruz', 'municipality' => 'Tabuk City', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-CLOSE-01', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'FOR_CLOSURE_REVIEW', 'stage' => 'for closure review', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_case_requires_closure_summary_before_final_close(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $supervisor = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']);
        $case = $this->caseFor($worker);
        $this->actingAs($worker)->patch(route('cases.transition', $case), ['status' => 'CLOSED'])->assertStatus(422);
        $this->actingAs($worker)->post(route('cases.closure.store', $case), ['closure_summary' => 'Objectives were met and the family received post-closure contacts.'])->assertRedirect();
        $this->assertNotNull(DB::table('cases')->where('id', $case)->value('retention_review_at'));
        $this->actingAs($worker)->patch(route('cases.transition', $case), ['status' => 'CLOSED'])->assertStatus(422);
        $this->actingAs($supervisor)->patch(route('cases.closure.review', $case))->assertRedirect();
        $this->actingAs($supervisor)->patch(route('cases.transition', $case), ['status' => 'CLOSED'])->assertRedirect();
        $this->assertDatabaseHas('cases', ['id' => $case, 'status' => 'CLOSED']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'case.closure_recorded', 'auditable_id' => $case]);
    }

    public function test_closure_record_is_limited_to_closure_review(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Non', 'last_name' => 'Review', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-CLOSE-02', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($worker)->post(route('cases.closure.store', $case), ['closure_summary' => 'Too early.'])->assertStatus(422);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportIntakeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function reportFor(User $user, string $status = 'FOR_SCREENING'): int
    {
        return DB::table('reports')->insertGetId([
            'reference_no' => 'RPT-2026-00001', 'status' => $status, 'narrative' => 'A concern requiring assessment.',
            'priority' => 'high', 'is_public_submission' => true, 'endorsed_municipality' => 'Tabuk City',
            'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_report_must_follow_screening_and_validation_path(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk City']);
        $report = $this->reportFor($worker);

        $this->actingAs($worker)->patch(route('reports.transition', $report), ['status' => 'ACCEPTED'])->assertStatus(422);
        $this->actingAs($worker)->patch(route('reports.transition', $report), ['status' => 'UNDER_VALIDATION'])->assertRedirect();
        $this->assertDatabaseHas('reports', ['id' => $report, 'status' => 'UNDER_VALIDATION', 'screened_by' => $worker->id]);
        $this->actingAs($worker)->patch(route('reports.transition', $report), ['status' => 'VALIDATED'])->assertRedirect();
        $this->assertDatabaseHas('reports', ['id' => $report, 'status' => 'VALIDATED', 'validated_by' => $worker->id]);
        $this->actingAs($worker)->patch(route('reports.transition', $report), ['status' => 'ACCEPTED'])->assertRedirect();
        $this->assertDatabaseHas('reports', ['id' => $report, 'status' => 'ACCEPTED']);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'report', 'auditable_id' => $report, 'event' => 'report.transitioned']);
    }

    public function test_only_accepted_report_can_create_case_folder(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk City']);
        $report = $this->reportFor($worker);
        $payload = ['first_name' => 'Lea', 'last_name' => 'Santos', 'municipality' => 'Tabuk', 'category' => 'Child Protection'];

        $this->actingAs($worker)->post(route('reports.case.store', $report), $payload)->assertStatus(422);
        $this->actingAs($worker)->patch(route('reports.transition', $report), ['status' => 'UNDER_VALIDATION']);
        $this->actingAs($worker)->patch(route('reports.transition', $report), ['status' => 'VALIDATED']);
        $this->actingAs($worker)->patch(route('reports.transition', $report), ['status' => 'ACCEPTED']);
        $this->actingAs($worker)->post(route('reports.case.store', $report), $payload)->assertRedirect();
        $this->assertDatabaseHas('reports', ['id' => $report, 'status' => 'CASE_CREATED']);
        $this->assertDatabaseHas('cases', ['report_id' => $report, 'priority' => 'high', 'status' => 'OPEN']);
    }

    public function test_cpu_member_cannot_validate_or_accept_a_report(): void
    {
        $worker = User::factory()->create(['role' => 'cpu_member', 'municipality' => 'Tabuk City']);
        $report = $this->reportFor($worker, 'UNDER_VALIDATION');

        $this->actingAs($worker)->patch(route('reports.transition', $report), ['status' => 'VALIDATED'])->assertForbidden();
    }
}

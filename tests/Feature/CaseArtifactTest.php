<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SyntheticTrigger;
use Tests\TestCase;

class CaseArtifactTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $owner): int
    {
        $client = DB::table('clients')->insertGetId([
            'first_name' => 'Mila', 'last_name' => 'Dela Cruz', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('cases')->insertGetId([
            'case_number' => 'KLG-2026-ART-01', 'client_id' => $client, 'assigned_to' => $owner->id,
            'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_visit_audit_failure_rolls_back_visit_and_case_version(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($owner);
        $this->actingAs($owner);
        $version = DB::table('cases')->where('id', $case)->value('offline_version');
        SyntheticTrigger::create("CREATE TRIGGER synthetic_visit_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.event = 'monitoring_visit.recorded' BEGIN SELECT RAISE(ABORT, 'Synthetic visit audit failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->post(route('cases.monitoring.store', $case), ['visited_at' => '2026-10-19', 'observations' => 'Synthetic recorded visit.']);
            $this->fail('Audit failure must reject visit.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic visit audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('monitoring_visits', 0);
        $this->assertDatabaseHas('cases', ['id' => $case, 'offline_version' => $version]);
    }

    public function test_assigned_case_worker_can_upload_a_document_and_record_monitoring(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($owner);

        $this->actingAs($owner)->post(route('cases.documents.upload', $case), [
            'document' => UploadedFile::fake()->create('intake.pdf', 20, 'application/pdf'),
        ])->assertRedirect();
        $this->assertDatabaseHas('documents', ['case_id' => $case, 'name' => 'intake.pdf', 'version' => 1]);

        $this->actingAs($owner)->post(route('cases.monitoring.store', $case), [
            'visited_at' => '2026-09-22', 'observations' => 'Client was safe and attended the scheduled visit.',
        ])->assertRedirect();
        $this->assertDatabaseHas('monitoring_visits', ['case_id' => $case, 'visited_at' => '2026-09-22']);
    }

    public function test_unassigned_or_read_only_user_cannot_access_case_artifacts(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($owner);
        $otherWorker = User::factory()->create(['role' => 'social_worker']);
        $viewer = User::factory()->create(['role' => 'cpu_member']);

        $this->actingAs($otherWorker)->post(route('cases.monitoring.store', $case), [
            'visited_at' => '2026-09-22', 'observations' => 'Not permitted.',
        ])->assertForbidden();
        $this->actingAs($viewer)->post(route('cases.monitoring.store', $case), [
            'visited_at' => '2026-09-22', 'observations' => 'Not permitted.',
        ])->assertForbidden();
    }
}

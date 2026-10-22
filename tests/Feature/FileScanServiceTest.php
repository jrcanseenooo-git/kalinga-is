<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FileScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileScanServiceTest extends TestCase
{
    use RefreshDatabase;

    private function pendingDocument(): int
    {
        Storage::fake('local');
        Storage::disk('local')->put('cases/1/quarantine/sample.txt', 'safe fixture');
        $user = User::factory()->create(['role' => 'social_worker']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Scan', 'last_name' => 'Fixture', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-SCAN-1', 'client_id' => $client, 'assigned_to' => $user->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $document = DB::table('documents')->insertGetId(['case_id' => $case, 'lineage_id' => (string) str()->uuid(), 'name' => 'sample.txt', 'disk' => 'local', 'path' => 'cases/1/quarantine/sample.txt', 'mime_type' => 'text/plain', 'version' => 1, 'uploaded_by' => $user->id, 'source_module' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('record_governance')->insert(['case_id' => $case, 'record_type' => 'document', 'record_id' => $document, 'lifecycle_state' => 'active', 'scan_state' => 'pending_scan', 'legacy_record' => false, 'created_at' => now(), 'updated_at' => now()]);

        return $document;
    }

    public function test_disabled_scanner_keeps_upload_quarantined(): void
    {
        $document = $this->pendingDocument();
        config(['kalinga.scan_driver' => 'none']);

        $this->assertSame('pending_scan', app(FileScanService::class)->scanDocument($document));
        $this->assertDatabaseHas('record_governance', ['record_id' => $document, 'scan_state' => 'pending_scan']);
    }

    public function test_unknown_scanner_fails_closed(): void
    {
        $document = $this->pendingDocument();
        config(['kalinga.scan_driver' => 'unsupported']);

        $this->assertSame('scan_error', app(FileScanService::class)->scanDocument($document));
        $this->assertDatabaseHas('record_governance', ['record_id' => $document, 'scan_state' => 'scan_error']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'document.scan_completed', 'auditable_id' => $document, 'outcome' => 'scan_error']);
    }
}

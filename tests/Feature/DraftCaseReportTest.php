<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DraftCaseReportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'SYNTHETIC-REPORT', 'name' => 'SyntheticTown', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_lgu_assignments')->insert(['user_id' => $worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $client = DB::table('clients')->insertGetId(['first_name' => '  =1+1', 'last_name' => 'Synthetic', 'municipality' => 'SyntheticTown', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYNTHETIC-REPORT-1', 'client_id' => $client, 'assigned_to' => $worker->id, 'lgu_id' => $lgu, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return [$worker, $case];
    }

    public function test_preview_and_csv_are_explicit_drafts_with_authenticated_provenance_and_no_cache(): void
    {
        [$worker, $case] = $this->fixture();
        $this->be($worker);
        $preview = $this->get(route('cases.social-report.show', $case))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.report_status', 'draft')->where('report.template_version', 'working-summary-v1')
            ->where('report.prepared_by', $worker->name)->where('report.source.case_id', $case));
        $this->assertStringContainsString('no-store', $preview->headers->get('Cache-Control'));
        $export = $this->get(route('cases.social-report.download', $case))->assertOk()->assertSee('DRAFT - Not approved for official release');
        $this->assertStringContainsString('no-store', $export->headers->get('Cache-Control'));
        $rows = array_map(fn ($line) => str_getcsv($line), explode("\n", trim($export->getContent())));
        $clientRow = collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'Client');
        $this->assertSame("'  =1+1 Synthetic", $clientRow[1]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'social_case_report.generated', 'auditable_id' => $case]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'social_case_report.downloaded', 'auditable_id' => $case]);
    }

    public function test_draft_metadata_never_grants_another_worker_or_admin_report_access(): void
    {
        [, $case] = $this->fixture();
        foreach (['social_worker', 'cpu_member', 'system_admin'] as $role) {
            $this->be(User::factory()->create(['role' => $role]));
            $this->get(route('cases.social-report.show', $case))->assertForbidden();
            $this->get(route('cases.social-report.download', $case))->assertForbidden();
        }
        $this->assertDatabaseMissing('audit_logs', ['event' => 'social_case_report.downloaded']);
    }
}

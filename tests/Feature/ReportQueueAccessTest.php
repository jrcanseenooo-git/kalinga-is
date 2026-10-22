<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportQueueAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_worker_only_sees_and_can_transition_public_reports_endorsed_to_their_lgu(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk City']);
        $reportId = DB::table('reports')->insertGetId([
            'reference_no' => 'RPT-PRIVATE-QUEUE-001', 'narrative' => 'Restricted report queue record.',
            'status' => 'FOR_SCREENING', 'priority' => 'normal', 'submitted_at' => now(),
            'is_public_submission' => true, 'endorsed_municipality' => 'Lubuagan',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('reports')->insert([
            'reference_no' => 'RPT-TABUK-QUEUE-001', 'narrative' => 'Report endorsed to the worker LGU.',
            'status' => 'FOR_SCREENING', 'priority' => 'normal', 'submitted_at' => now(),
            'is_public_submission' => true, 'endorsed_municipality' => 'Tabuk City',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($worker)->get(route('reports.index'))->assertOk()->assertDontSee('RPT-PRIVATE-QUEUE-001');
        $this->actingAs($worker)->get(route('reports.index'))->assertSee('RPT-TABUK-QUEUE-001');
        $this->actingAs($worker)->patch(route('reports.transition', $reportId), ['status' => 'UNDER_VALIDATION'])->assertForbidden();
    }
}

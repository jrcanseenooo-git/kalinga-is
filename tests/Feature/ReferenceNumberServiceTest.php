<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ReferenceNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReferenceNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_continues_existing_yearly_sequences_without_using_table_counts(): void
    {
        $user = User::factory()->create(['role' => 'social_worker']);
        $year = now()->year;
        DB::table('reports')->insert([
            'reference_no' => "RPT-{$year}-00007",
            'narrative' => 'Existing report.',
            'priority' => 'normal',
            'status' => 'FOR_SCREENING',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(ReferenceNumberService::class);
        $report = DB::transaction(fn () => $service->nextReport());
        $case = DB::transaction(fn () => $service->nextCase());

        $this->assertSame("RPT-{$year}-00008", $report);
        $this->assertSame("KLG-{$year}-00001", $case);
        $this->assertDatabaseHas('reference_sequences', ['prefix' => 'RPT', 'year' => $year, 'last_number' => 8]);
    }
}

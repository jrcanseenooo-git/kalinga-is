<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClosureVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_draft_review_close_and_reopen_requests_cannot_overwrite_a_newer_case_snapshot(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'province' => 'Kalinga']);
        $reviewer = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Version', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYN-CLOSURE-VERSION', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'FOR_CLOSURE_REVIEW', 'stage' => 'for closure review', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $version = fn () => (int) DB::table('cases')->where('id', $case)->value('offline_version');
        $first = $version();
        $this->actingAs($worker)->postJson(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic initial summary.', 'expected_version' => $first])->assertOk()->assertJsonPath('saved', true);
        $this->postJson(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic stale edit.', 'expected_version' => $first])->assertConflict();
        $this->assertDatabaseHas('cases', ['id' => $case, 'closure_summary' => 'Synthetic initial summary.']);
        $this->actingAs($reviewer)->patchJson(route('cases.closure.review', $case), ['expected_version' => $first])->assertConflict();
        $beforeReview = $version();
        $this->patchJson(route('cases.closure.review', $case), ['expected_version' => $beforeReview])->assertOk()->assertJsonPath('saved', true);
        $this->assertGreaterThan($beforeReview, $version());
        $this->patchJson(route('cases.transition', $case), ['status' => 'CLOSED', 'expected_version' => $beforeReview])->assertConflict();
        $reviewed = $version();
        $this->patchJson(route('cases.transition', $case), ['status' => 'CLOSED', 'expected_version' => $reviewed])->assertOk()->assertJsonPath('saved', true);
        $this->actingAs($worker)->patchJson(route('cases.transition', $case), ['status' => 'REOPENED', 'expected_version' => $reviewed])->assertConflict();
        $this->assertDatabaseHas('cases', ['id' => $case, 'status' => 'CLOSED']);
        $this->patchJson(route('cases.transition', $case), ['status' => 'REOPENED', 'expected_version' => $version()])->assertOk()->assertJsonPath('saved', true);
        $this->assertDatabaseCount('case_closure_records', 1);
        $this->assertDatabaseCount('case_plans', 1);
    }
}

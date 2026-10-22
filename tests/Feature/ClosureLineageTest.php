<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticTrigger;
use Tests\TestCase;

class ClosureLineageTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'province' => 'Kalinga']);
        $reviewer = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Lineage', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYN-CLOSURE-LINEAGE', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'FOR_CLOSURE_REVIEW', 'stage' => 'for closure review', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return [$worker, $reviewer, $case];
    }

    public function test_reopening_preserves_the_old_closure_but_cannot_reuse_its_approval(): void
    {
        [$worker,$reviewer,$case] = $this->context();
        $this->actingAs($worker)->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic first closure recommendation.'])->assertRedirect();
        $this->actingAs($reviewer)->patch(route('cases.closure.review', $case))->assertRedirect();
        $this->patch(route('cases.transition', $case), ['status' => 'CLOSED'])->assertRedirect();
        $this->actingAs($worker)->patch(route('cases.transition', $case), ['status' => 'REOPENED'])->assertRedirect();
        $this->assertDatabaseHas('cases', ['id' => $case, 'closure_summary' => null, 'closure_reviewed_by' => null, 'closure_reviewed_at' => null]);
        $this->assertDatabaseHas('case_closure_records', ['case_id' => $case, 'summary' => 'Synthetic first closure recommendation.', 'current_slot' => null, 'reviewed_by' => $reviewer->id]);
        $this->patch(route('cases.transition', $case), ['status' => 'IN_PROGRESS'])->assertRedirect();
        $this->patch(route('cases.transition', $case), ['status' => 'FOR_CLOSURE_REVIEW'])->assertRedirect();
        $this->actingAs($reviewer)->patch(route('cases.transition', $case), ['status' => 'CLOSED'])->assertUnprocessable();
        $this->assertSame(1, DB::table('case_plans')->where('case_id', $case)->count());
    }

    public function test_revisions_preserve_original_text_and_identical_retries_do_not_create_another_version(): void
    {
        [$worker,,$case] = $this->context();
        $this->actingAs($worker);
        $this->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic original recommendation.'])->assertRedirect();
        $this->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic original recommendation.'])->assertRedirect();
        $this->assertSame(1, DB::table('case_closure_records')->where('case_id', $case)->count());
        $this->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic revised recommendation.'])->assertRedirect();
        $this->assertDatabaseHas('case_closure_records', ['case_id' => $case, 'version' => 1, 'summary' => 'Synthetic original recommendation.', 'current_slot' => null]);
        $this->assertDatabaseHas('case_closure_records', ['case_id' => $case, 'version' => 2, 'summary' => 'Synthetic revised recommendation.', 'current_slot' => 1]);
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'case.closure_recorded')->where('auditable_id', $case)->count());
    }

    public function test_legacy_capture_preserves_actual_surviving_text_without_inventing_author_or_recorded_time(): void
    {
        [$worker,,$case] = $this->context();
        DB::table('cases')->where('id', $case)->update(['closure_summary' => 'Synthetic surviving legacy recommendation.']);
        $this->actingAs($worker)->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic new recommendation.'])->assertRedirect();
        $this->assertDatabaseHas('case_closure_records', ['case_id' => $case, 'summary' => 'Synthetic surviving legacy recommendation.', 'source' => 'legacy_case_summary', 'recorded_by' => null, 'recorded_at' => null, 'current_slot' => null]);
        $this->assertDatabaseHas('cases', ['id' => $case, 'closure_summary' => 'Synthetic new recommendation.']);
    }

    public function test_surviving_projection_changed_by_a_legacy_writer_is_captured_before_another_revision(): void
    {
        [$worker,,$case] = $this->context();
        $this->actingAs($worker)->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic original versioned text.'])->assertRedirect();
        DB::table('cases')->where('id', $case)->update(['closure_summary' => 'Synthetic surviving legacy writer text.']);
        $this->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic next authorized revision.'])->assertRedirect();
        $this->assertDatabaseHas('case_closure_records', ['case_id' => $case, 'summary' => 'Synthetic surviving legacy writer text.', 'source' => 'legacy_case_summary', 'recorded_by' => null, 'recorded_at' => null, 'current_slot' => null]);
        $this->assertDatabaseHas('case_closure_records', ['case_id' => $case, 'version' => 3, 'summary' => 'Synthetic next authorized revision.', 'current_slot' => 1]);
    }

    public function test_review_or_closing_cannot_borrow_approval_after_a_legacy_projection_drift(): void
    {
        [$worker,$reviewer,$case] = $this->context();
        $this->actingAs($worker)->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic coherent recommendation.'])->assertRedirect();
        $this->actingAs($reviewer)->patch(route('cases.closure.review', $case))->assertRedirect();
        DB::table('cases')->where('id', $case)->update(['closure_summary' => 'Synthetic unattributed drift.']);
        $this->patch(route('cases.closure.review', $case))->assertConflict();
        $this->patch(route('cases.transition', $case), ['status' => 'CLOSED'])->assertConflict();
        $this->assertDatabaseHas('cases', ['id' => $case, 'status' => 'FOR_CLOSURE_REVIEW', 'closure_summary' => 'Synthetic unattributed drift.']);
        $this->actingAs($worker)->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic unattributed drift.'])->assertRedirect();
        $this->assertDatabaseHas('cases', ['id' => $case, 'closure_reviewed_by' => null, 'closure_reviewed_at' => null]);
        $this->assertDatabaseHas('case_closure_records', ['case_id' => $case, 'version' => 3, 'summary' => 'Synthetic unattributed drift.', 'current_slot' => 1, 'recorded_by' => $worker->id, 'reviewed_at' => null]);
    }

    public function test_legacy_reopening_preserves_review_and_close_metadata_even_when_summary_is_missing(): void
    {
        [$worker,$reviewer,$case] = $this->context();
        DB::table('cases')->where('id', $case)->update(['status' => 'CLOSED', 'closure_summary' => null, 'closure_reviewed_by' => $reviewer->id, 'closure_reviewed_at' => '2026-01-02 01:00:00', 'closed_at' => '2026-01-02 02:00:00']);
        $this->actingAs($worker)->patch(route('cases.transition', $case), ['status' => 'REOPENED'])->assertRedirect();
        $this->assertDatabaseHas('case_closure_records', ['case_id' => $case, 'summary' => null, 'source' => 'legacy_case_summary', 'recorded_at' => null, 'reviewed_by' => $reviewer->id, 'reviewed_at' => '2026-01-02 01:00:00', 'closed_at' => '2026-01-02 02:00:00', 'current_slot' => null]);
    }

    public function test_failed_record_audit_rolls_back_new_history_and_preserves_the_legacy_field(): void
    {
        [$worker,,$case] = $this->context();
        DB::table('cases')->where('id', $case)->update(['closure_summary' => 'Synthetic legacy text retained after failure.']);
        $this->actingAs($worker);
        SyntheticTrigger::create("CREATE TRIGGER synthetic_lineage_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.event = 'case.closure_recorded' BEGIN SELECT RAISE(ABORT, 'Synthetic lineage audit failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic proposed revision.']);
            $this->fail('Unaudited revision must not persist.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic lineage audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('case_closure_records', 0);
        $this->assertDatabaseHas('cases', ['id' => $case, 'closure_summary' => 'Synthetic legacy text retained after failure.']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'case.closure_history_captured', 'auditable_id' => $case]);
    }

    public function test_failed_reopening_rolls_back_history_approval_and_new_planning_cycle(): void
    {
        [$worker,$reviewer,$case] = $this->context();
        $this->actingAs($worker)->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic completed closure.'])->assertRedirect();
        $this->actingAs($reviewer)->patch(route('cases.closure.review', $case))->assertRedirect();
        $this->patch(route('cases.transition', $case), ['status' => 'CLOSED'])->assertRedirect();
        $this->actingAs($worker);
        SyntheticTrigger::create("CREATE TRIGGER synthetic_reopening_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.event = 'case.transitioned' BEGIN SELECT RAISE(ABORT, 'Synthetic reopening audit failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->patch(route('cases.transition', $case), ['status' => 'REOPENED']);
            $this->fail('Unaudited reopening must not persist.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic reopening audit failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('cases', ['id' => $case, 'status' => 'CLOSED', 'closure_summary' => 'Synthetic completed closure.', 'closure_reviewed_by' => $reviewer->id]);
        $this->assertDatabaseHas('case_closure_records', ['case_id' => $case, 'summary' => 'Synthetic completed closure.', 'current_slot' => 1, 'reviewed_by' => $reviewer->id]);
        $this->assertDatabaseCount('case_plans', 0);
        $this->assertSame(1, DB::table('case_status_history')->where('case_id', $case)->count());
    }
}

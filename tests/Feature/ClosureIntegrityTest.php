<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticTrigger;
use Tests\TestCase;

class ClosureIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'province' => 'Kalinga']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Closure', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYN-CLOSURE-1', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'FOR_CLOSURE_REVIEW', 'stage' => 'for closure review', 'priority' => 'normal', 'offline_version' => 1, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($worker);

        return [$worker, $case];
    }

    public function test_failed_closure_audit_does_not_leave_a_summary_or_version_change(): void
    {
        [, $case] = $this->context();
        SyntheticTrigger::create("CREATE TRIGGER synthetic_closure_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.event = 'case.closure_recorded' BEGIN SELECT RAISE(ABORT, 'Synthetic closure audit failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic closure recommendation.']);
            $this->fail('Unaudited closure must not persist.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic closure audit failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('cases', ['id' => $case, 'closure_summary' => null, 'closure_reviewed_at' => null, 'offline_version' => 1]);
    }

    public function test_failed_review_audit_does_not_authorize_closure(): void
    {
        [, $case] = $this->context();
        $this->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic closure recommendation.'])->assertRedirect();
        $reviewer = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']);
        $this->actingAs($reviewer);
        SyntheticTrigger::create("CREATE TRIGGER synthetic_closure_review_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.event = 'case.closure_reviewed' BEGIN SELECT RAISE(ABORT, 'Synthetic review audit failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->patch(route('cases.closure.review', $case));
            $this->fail('Unaudited review must not authorize closure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic review audit failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('cases', ['id' => $case, 'closure_reviewed_by' => null, 'closure_reviewed_at' => null]);
    }

    public function test_same_second_revision_uses_latest_author_and_still_requires_an_independent_review(): void
    {
        $this->freezeTime();
        [, $case] = $this->context();
        $this->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic earlier closure recommendation.'])->assertRedirect();
        // Only the case manager records the closure; the same person can never also review it.
        $this->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic revised closure recommendation.'])->assertRedirect();
        $this->patch(route('cases.closure.review', $case))->assertUnprocessable();
        $this->assertDatabaseHas('cases', ['id' => $case, 'closure_reviewed_by' => null, 'closure_reviewed_at' => null]);
    }

    public function test_archived_case_cannot_record_or_review_closure(): void
    {
        [, $case] = $this->context();
        $this->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic closure recommendation.'])->assertRedirect();
        DB::table('cases')->where('id', $case)->update(['deleted_at' => now()]);
        $this->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic archived revision.'])->assertNotFound();
        $reviewer = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']);
        $this->actingAs($reviewer)->patch(route('cases.closure.review', $case))->assertNotFound();
    }

    public function test_closing_rechecks_a_review_invalidated_after_the_initial_case_read(): void
    {
        [, $case] = $this->context();
        $this->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic reviewed recommendation.'])->assertRedirect();
        $reviewer = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']);
        $this->actingAs($reviewer)->patch(route('cases.closure.review', $case))->assertRedirect();
        $changed = false;
        // Controlled interleaving on synthetic SQLite, not a PostgreSQL race certification.
        DB::listen(function (QueryExecuted $query) use ($case, &$changed) {
            if (!$changed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'from "cases"')) {
                $changed = true;
                DB::table('cases')->where('id', $case)->update(['closure_summary' => 'Synthetic concurrent revision.', 'closure_reviewed_by' => null, 'closure_reviewed_at' => null]);
            }
        });
        $this->patch(route('cases.transition', $case), ['status' => 'CLOSED'])->assertUnprocessable();
        $this->assertTrue($changed);
        $this->assertDatabaseHas('cases', ['id' => $case, 'status' => 'FOR_CLOSURE_REVIEW', 'closure_reviewed_at' => null]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'case.transitioned', 'auditable_id' => $case]);
    }
}

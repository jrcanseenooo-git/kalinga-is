<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyntheticTrigger;
use Tests\TestCase;

class CaseHandoverIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $target = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'SYN-ASSIGN-1', 'name' => 'City of Tabuk', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $otherLgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'SYN-ASSIGN-2', 'name' => 'Rizal', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->membership($owner, $lgu);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Reassignment', 'municipality' => 'City of Tabuk', 'province' => 'Kalinga', 'region' => 'CAR', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYN-ASSIGN-2026-0001', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $owner->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'offline_version' => 1, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $target, $case, $lgu, $otherLgu];
    }

    public function test_handover_creation_and_candidates_require_current_recipient_access(): void
    {
        [$owner,$target,$case,$lgu,$otherLgu] = $this->fixture();
        foreach ([$owner, $target] as $user) {
            $user->forceFill(['municipality' => 'City of Tabuk', 'province' => 'Kalinga'])->save();
        }
        $this->membership($target, $otherLgu);
        $this->be($owner);
        $this->postJson(route('cases.transfers.store', $case), [
            'originating_office' => 'City of Tabuk', 'receiving_office' => 'City of Tabuk',
            'receiving_worker_id' => $target->id, 'reason' => 'Synthetic eligibility check',
        ])->assertUnprocessable()->assertJsonValidationErrors('receiving_worker_id');
        $this->assertDatabaseCount('case_transfers', 0);
        $this->get(route('cases.plans.workspace', $case))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('transferCandidates', fn ($candidates) => !collect($candidates)->pluck('id')->contains($target->id)));
        $this->membership($target, $lgu);
        $this->postJson(route('cases.transfers.store', $case), [
            'originating_office' => 'City of Tabuk', 'receiving_office' => 'City of Tabuk',
            'receiving_worker_id' => $target->id, 'reason' => 'Synthetic eligible handover',
        ])->assertCreated();
        $this->get(route('cases.plans.workspace', $case))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('transferCandidates', fn ($candidates) => collect($candidates)->pluck('id')->contains($target->id)));
    }

    public static function handoverEligibilityChanges(): array
    {
        return [['revoked'], ['inactive'], ['expired'], ['future']];
    }

    #[DataProvider('handoverEligibilityChanges')]
    public function test_formal_handover_rechecks_revoked_receiver_before_changing_ownership(string $change): void
    {
        [$owner, $target, $case, $lgu] = $this->fixture();
        foreach ([$owner, $target] as $user) {
            $user->forceFill(['municipality' => 'City of Tabuk', 'province' => 'Kalinga'])->save();
        }
        $this->membership($target, $lgu);
        $this->be($owner);
        $transfer = $this->postJson(route('cases.transfers.store', $case), [
            'originating_office' => 'City of Tabuk', 'receiving_office' => 'City of Tabuk',
            'receiving_worker_id' => $target->id, 'reason' => 'Synthetic continuity handover',
        ])->assertCreated()->json('id');
        foreach (['requested', 'endorsed', 'accepted'] as $status) {
            $this->patchJson(route('cases.transfers.transition', [$case, $transfer]), ['status' => $status])->assertNoContent();
        }
        if ($change === 'inactive') {
            $target->forceFill(['active' => false])->save();
        } else {
            DB::table('user_lgu_assignments')->where('user_id', $target->id)->update(match ($change) {
                'expired' => ['effective_to' => now()],
                'future' => ['effective_from' => now()->addDay()],
                default => ['active' => false],
            });
        }
        $this->patchJson(route('cases.transfers.transition', [$case, $transfer]), ['status' => 'completed'])->assertUnprocessable();
        $this->assertDatabaseHas('cases', ['id' => $case, 'assigned_to' => $owner->id, 'offline_version' => 1]);
        $this->assertDatabaseHas('case_transfers', ['id' => $transfer, 'status' => 'accepted']);
        $this->assertDatabaseMissing('case_transfer_history', ['case_transfer_id' => $transfer, 'event' => 'completed']);
    }

    public function test_formal_handover_audit_failure_rolls_back_ownership_and_history(): void
    {
        [$owner,$target,$case,$lgu] = $this->fixture();
        foreach ([$owner, $target] as $user) {
            $user->forceFill(['municipality' => 'City of Tabuk', 'province' => 'Kalinga'])->save();
        }
        $this->membership($target, $lgu);
        $transfer = DB::table('case_transfers')->insertGetId([
            'case_id' => $case, 'originating_worker_id' => $owner->id, 'receiving_worker_id' => $target->id,
            'originating_office' => 'City of Tabuk', 'receiving_office' => 'City of Tabuk',
            'reason' => 'Synthetic audit rollback', 'created_by' => $owner->id, 'status' => 'accepted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        SyntheticTrigger::create("CREATE TRIGGER synthetic_handover_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.event = 'case_transfer.completed' BEGIN SELECT RAISE(ABORT, 'Synthetic handover audit failure'); END");
        $this->be($owner);
        $this->withoutExceptionHandling();
        try {
            $this->patchJson(route('cases.transfers.transition', [$case, $transfer]), ['status' => 'completed']);
            $this->fail('Audit failure must reject handover.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic handover audit failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('cases', ['id' => $case, 'assigned_to' => $owner->id, 'offline_version' => 1]);
        $this->assertDatabaseHas('case_transfers', ['id' => $transfer, 'status' => 'accepted']);
        $this->assertDatabaseMissing('case_transfer_history', ['case_transfer_id' => $transfer, 'event' => 'completed']);
    }

    private function membership(User $worker, int $lgu, array $overrides = []): void
    {
        DB::table('user_lgu_assignments')->insert(array_merge(['user_id' => $worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'effective_to' => null, 'created_at' => now(), 'updated_at' => now()], $overrides));
    }

    public function test_direct_reassignment_was_removed_and_the_case_page_no_longer_offers_it(): void
    {
        [$owner, $target, $case, $lgu] = $this->fixture();
        $this->membership($target, $lgu);
        $this->be($owner);
        $this->assertFalse(Route::has('cases.assign'));
        $this->patch('/cases/' . $case . '/assignment', ['assigned_to' => $target->id, 'expected_version' => 1])->assertNotFound();
        $this->assertDatabaseHas('cases', ['id' => $case, 'assigned_to' => $owner->id, 'offline_version' => 1]);
        $this->get(route('cases.show', $case))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('canAssignCase')->missing('assignableWorkers')->missing('assignmentUpdated'));
        $this->assertFalse($owner->hasPermission('cases.assign'));
    }
}

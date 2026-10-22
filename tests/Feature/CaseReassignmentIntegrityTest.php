<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CaseAccessScope;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyntheticTrigger;
use Tests\TestCase;

class CaseReassignmentIntegrityTest extends TestCase
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

    public static function ineligibleTargets(): array
    {
        return array_map(fn ($state) => [$state], ['outside_lgu', 'missing', 'expired', 'ends_now', 'revoked', 'future', 'inactive', 'wrong_role']);
    }

    #[DataProvider('ineligibleTargets')]
    public function test_ineligible_reassignment_preserves_owner_version_and_side_effects(string $state): void
    {
        [$owner, $target, $case, $lgu, $otherLgu] = $this->fixture();
        if ($state !== 'missing') {
            $this->membership($target, $state === 'outside_lgu' ? $otherLgu : $lgu, match ($state) {
                'expired' => ['effective_to' => now()->subSecond()],
                'ends_now' => ['effective_to' => now()],
                'revoked' => ['active' => false],
                'future' => ['effective_from' => now()->addDay()],
                default => [],
            });
        }
        if ($state === 'inactive') {
            $target->forceFill(['active' => false])->save();
        }
        if ($state === 'wrong_role') {
            $target->forceFill(['role' => 'cpu_member'])->save();
        }

        if ($state !== 'inactive') {
            $proposed = DB::table('cases')->find($case);
            $proposed->assigned_to = $target->id;
            $this->assertFalse(app(CaseAccessScope::class)->allowsCase($proposed, $target->fresh(), 'City of Tabuk', 'Kalinga'));
        }

        $this->be($owner); // Do not use the legacy role/LGU fixture adapter.
        $this->patchJson(route('cases.assign', $case), ['assigned_to' => $target->id, 'expected_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->assertDatabaseHas('cases', ['id' => $case, 'assigned_to' => $owner->id, 'offline_version' => 1]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'case.reassigned', 'auditable_id' => $case]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $target->id, 'type' => 'case_assigned']);
    }

    public function test_candidate_payload_matches_current_read_eligibility_and_revocation(): void
    {
        [$owner, $target, $case, $lgu, $otherLgu] = $this->fixture();
        $this->membership($target, $lgu);
        $outside = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $this->membership($outside, $otherLgu);
        $this->be($owner);
        $this->get(route('cases.show', $case))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('assignableWorkers', fn ($workers) => collect($workers)->pluck('id')->sort()->values()->all() === collect([$owner->id, $target->id])->sort()->values()->all()));
        DB::table('user_lgu_assignments')->where('user_id', $target->id)->update(['active' => false]);
        $this->get(route('cases.show', $case))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('assignableWorkers', fn ($workers) => collect($workers)->pluck('id')->all() === [$owner->id]));
        $this->patchJson(route('cases.assign', $case), ['assigned_to' => $target->id, 'expected_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    }

    public function test_eligible_reassignment_changes_access_and_redirects_former_owner_safely(): void
    {
        [$owner, $target, $case, $lgu] = $this->fixture();
        $this->membership($target, $lgu);
        $this->be($owner);
        $this->from(route('cases.show', $case))->patch(route('cases.assign', $case), ['assigned_to' => $target->id, 'expected_version' => 1])
            ->assertRedirect(route('cases.index'))->assertSessionHas(SessionKey::ClearHistory->value, true);
        $this->assertDatabaseHas('cases', ['id' => $case, 'assigned_to' => $target->id, 'offline_version' => 2, 'status' => 'OPEN', 'stage' => 'intake']);
        $this->assertDatabaseHas('notifications', ['user_id' => $target->id, 'type' => 'case_assigned']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'case.reassigned', 'auditable_id' => $case]);
        $this->get(route('cases.show', $case))->assertForbidden();
        $this->be($target);
        $this->get(route('cases.show', $case))->assertOk();
        $this->get(route('cases.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('cases.data', 1)->where('cases.data.0.id', $case));
    }

    public function test_stale_version_cannot_overwrite_a_newer_case_or_create_side_effects(): void
    {
        [$owner, $target, $case, $lgu] = $this->fixture();
        $this->membership($target, $lgu);
        DB::table('cases')->where('id', $case)->update(['offline_version' => 2]);
        $this->be($owner);
        $this->patchJson(route('cases.assign', $case), ['assigned_to' => $target->id, 'expected_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->assertDatabaseHas('cases', ['id' => $case, 'assigned_to' => $owner->id, 'offline_version' => 2]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'case.reassigned', 'auditable_id' => $case]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $target->id, 'type' => 'case_assigned']);
    }

    public function test_audit_failure_rolls_back_assignment_notification_and_version(): void
    {
        [$owner, $target, $case, $lgu] = $this->fixture();
        $this->membership($target, $lgu);
        SyntheticTrigger::create("CREATE TRIGGER synthetic_assignment_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.event = 'case.reassigned' BEGIN SELECT RAISE(ABORT, 'Synthetic assignment audit failure'); END");
        $this->be($owner);
        $this->withoutExceptionHandling();
        try {
            $this->patchJson(route('cases.assign', $case), ['assigned_to' => $target->id, 'expected_version' => 1]);
            $this->fail('Synthetic audit failure must reject the assignment.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic assignment audit failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('cases', ['id' => $case, 'assigned_to' => $owner->id, 'offline_version' => 1]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $target->id, 'type' => 'case_assigned']);
    }

    public function test_archived_case_cannot_be_reassigned(): void
    {
        [$owner, $target, $case, $lgu] = $this->fixture();
        $this->membership($target, $lgu);
        DB::table('cases')->where('id', $case)->update(['deleted_at' => now()]);
        $this->be($owner);
        $this->patchJson(route('cases.assign', $case), ['assigned_to' => $target->id, 'expected_version' => 1])->assertNotFound();
        $this->assertDatabaseHas('cases', ['id' => $case, 'assigned_to' => $owner->id, 'offline_version' => 1]);
    }

    public function test_missing_version_cannot_mutate_assignment(): void
    {
        [$owner, $target, $case, $lgu] = $this->fixture();
        $this->membership($target, $lgu);
        $this->be($owner);
        $this->patchJson(route('cases.assign', $case), ['assigned_to' => $target->id])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->assertDatabaseHas('cases', ['id' => $case, 'assigned_to' => $owner->id, 'offline_version' => 1]);
    }

    public function test_replayed_oversight_request_cannot_repeat_side_effects(): void
    {
        [$owner, $target, $case, $lgu] = $this->fixture();
        $this->membership($target, $lgu);
        $reviewer = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga', 'active' => true]);
        $this->be($reviewer);
        $this->patchJson(route('cases.assign', $case), ['assigned_to' => $target->id, 'expected_version' => 1])->assertRedirect(route('cases.show', $case));
        $this->patchJson(route('cases.assign', $case), ['assigned_to' => $owner->id, 'expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'case.reassigned')->where('auditable_id', $case)->count());
        $this->assertSame(1, DB::table('notifications')->where('user_id', $target->id)->where('type', 'case_assigned')->count());
        $this->assertDatabaseHas('cases', ['id' => $case, 'assigned_to' => $target->id, 'offline_version' => 2]);
    }

    public function test_deactivated_actor_cannot_reassign_through_a_stale_authenticated_model(): void
    {
        [$owner, $target, $case, $lgu] = $this->fixture();
        $this->membership($target, $lgu);
        $this->be($owner);
        DB::table('users')->where('id', $owner->id)->update(['active' => false]);
        $this->patchJson(route('cases.assign', $case), ['assigned_to' => $target->id, 'expected_version' => 1])->assertForbidden();
        $this->assertDatabaseHas('cases', ['id' => $case, 'assigned_to' => $owner->id, 'offline_version' => 1]);
    }

    public function test_legacy_case_without_verified_lgu_keeps_existing_read_access_but_cannot_be_transferred(): void
    {
        [$owner, $target, $case, $lgu] = $this->fixture();
        $this->membership($target, $lgu);
        DB::table('cases')->where('id', $case)->update(['lgu_id' => null]);
        $this->be($owner);
        $this->get(route('cases.show', $case))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canAssignCase', true)->has('assignableWorkers', 0));
        $this->patchJson(route('cases.assign', $case), ['assigned_to' => $target->id, 'expected_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->assertDatabaseHas('cases', ['id' => $case, 'assigned_to' => $owner->id, 'offline_version' => 1, 'lgu_id' => null]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'case.reassigned', 'auditable_id' => $case]);
    }

    public function test_membership_expiring_during_side_effect_writes_rolls_back_transfer(): void
    {
        $this->travelTo(now()->startOfSecond());
        try {
            [$owner, $target, $case, $lgu] = $this->fixture();
            $this->membership($target, $lgu, ['effective_to' => now()->addSecond()]);
            $advanced = false;
            DB::listen(function (QueryExecuted $query) use (&$advanced) {
                if (!$advanced && str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'notifications')) {
                    $advanced = true;
                    $this->travel(2)->seconds();
                }
            });
            $this->be($owner);
            $this->patchJson(route('cases.assign', $case), ['assigned_to' => $target->id, 'expected_version' => 1])
                ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
            $this->assertTrue($advanced);
            $this->assertDatabaseHas('cases', ['id' => $case, 'assigned_to' => $owner->id, 'offline_version' => 1]);
            $this->assertDatabaseMissing('notifications', ['user_id' => $target->id, 'type' => 'case_assigned']);
            $this->assertDatabaseMissing('audit_logs', ['event' => 'case.reassigned', 'auditable_id' => $case]);
        } finally {
            $this->travelBack();
        }
    }
}

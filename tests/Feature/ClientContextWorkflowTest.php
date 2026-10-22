<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticTrigger;
use Tests\TestCase;

class ClientContextWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'province' => 'Kalinga']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Context', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYN-CONTEXT-1', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $consent = DB::table('consents')->insertGetId(['case_id' => $case, 'lawful_basis' => 'Synthetic basis', 'purposes' => '["case management"]', 'sharing_allowed' => false, 'granted_at' => '2026-10-19', 'recorded_by' => $worker->id, 'created_at' => now(), 'updated_at' => now()]);

        return [$worker, $case, $client, $consent];
    }

    private function write(string $kind, int $case, int $consent)
    {
        return match ($kind) {
            'relationship' => $this->post(route('cases.relationships.store', $case), ['name' => 'Synthetic support', 'relationship_type' => 'Aunt', 'is_guardian' => false]),
            'consent' => $this->post(route('cases.consents.store', $case), ['lawful_basis' => 'Synthetic basis', 'purposes' => ['case management'], 'sharing_allowed' => false, 'granted_at' => '2026-10-19']),
            'withdrawal' => $this->patch(route('cases.consents.withdraw', [$case, $consent])),
        };
    }

    public function test_case_visibility_does_not_grant_named_relationship_or_consent_write_permissions(): void
    {
        [, $case, , $consent] = $this->context();
        $viewer = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']);
        $this->actingAs($viewer)->get(route('cases.show', $case))->assertOk();
        foreach (['relationship', 'consent', 'withdrawal'] as $kind) {
            $this->write($kind, $case, $consent)->assertForbidden();
        }
        $this->assertDatabaseCount('relationships', 0);
        $this->assertDatabaseCount('consents', 1);
        $this->assertDatabaseHas('consents', ['id' => $consent, 'withdrawn_at' => null]);
    }

    public function test_support_writes_and_withdrawal_roll_back_when_audit_storage_fails(): void
    {
        [$worker, $case, , $consent] = $this->context();
        $this->actingAs($worker);
        SyntheticTrigger::create("CREATE TRIGGER synthetic_context_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.event IN ('relationship.recorded','consent.recorded','consent.withdrawn') BEGIN SELECT RAISE(ABORT, 'Synthetic context audit failure'); END");
        $this->withoutExceptionHandling();
        foreach (['relationship', 'consent', 'withdrawal'] as $kind) {
            try {
                $this->write($kind, $case, $consent);
                $this->fail('Audit failure must reject the write.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Synthetic context audit failure', $exception->getMessage());
            }
            $this->assertDatabaseCount('relationships', 0);
            $this->assertDatabaseCount('consents', 1);
            $this->assertDatabaseHas('consents', ['id' => $consent, 'withdrawn_at' => null]);
        }
    }

    public function test_archived_case_rejects_relationship_and_consent_writes(): void
    {
        [$worker, $case, , $consent] = $this->context();
        DB::table('cases')->where('id', $case)->update(['deleted_at' => now()]);
        $this->actingAs($worker);
        foreach (['relationship', 'consent', 'withdrawal'] as $kind) {
            $this->write($kind, $case, $consent)->assertNotFound();
        }
    }

    public function test_withdrawal_is_case_scoped_and_cannot_create_duplicate_audit_events(): void
    {
        [$worker, $case, $client, $consent] = $this->context();
        $other = DB::table('cases')->insertGetId(['case_number' => 'SYN-CONTEXT-2', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($worker)->patch(route('cases.consents.withdraw', [$other, $consent]))->assertNotFound();
        $this->assertDatabaseHas('consents', ['id' => $consent, 'withdrawn_at' => null]);
        $this->patch(route('cases.consents.withdraw', [$case, $consent]))->assertRedirect();
        $this->patch(route('cases.consents.withdraw', [$case, $consent]))->assertNotFound();
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'consent.withdrawn')->where('auditable_id', $consent)->count());
    }
}

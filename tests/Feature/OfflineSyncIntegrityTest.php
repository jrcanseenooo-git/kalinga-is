<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OfflineSyncIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $other = User::factory()->create(['role' => 'social_worker']);
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'SYN-SYNC', 'name' => 'SyntheticTown', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$worker, $other] as $user) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $device = (string) Str::uuid();
        foreach ([$worker, $other] as $user) {
            DB::table('offline_devices')->insert(['user_id' => $user->id, 'device_id' => $device, 'last_seen_at' => now(), 'pack_expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Sync', 'municipality' => 'SyntheticTown', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYN-SYNC-01', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'offline_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['offline_id' => (string) Str::uuid(), 'resource' => 'case_note', 'operation' => 'create', 'base_version' => 1, 'payload' => ['case_id' => $case, 'body' => 'Synthetic acknowledged note']];
        $this->withHeader('X-Kalinga-Device-ID', $device)->be($worker);

        return compact('worker', 'other', 'case', 'payload', 'device');
    }

    public function test_replayed_upload_has_matching_acknowledgement_without_duplicate_record(): void
    {
        extract($this->fixture());
        foreach ([1, 2] as $attempt) {
            $this->postJson('/sync', $payload)->assertOk()->assertJsonPath('status', 'synced')->assertJsonPath('offline_id', $payload['offline_id']);
        }
        $this->assertSame(1, DB::table('case_notes')->where('case_id', $case)->count());
        $this->assertSame(2, DB::table('cases')->where('id', $case)->value('offline_version'));
    }

    public function test_replay_cannot_acknowledge_changed_content_or_another_users_key(): void
    {
        extract($this->fixture());
        $this->postJson('/sync', $payload)->assertOk();
        $changed = $payload;
        $changed['payload']['body'] = 'Synthetic changed content';
        $this->postJson('/sync', $changed)->assertUnprocessable();
        $this->be($other)->postJson('/sync', $payload)->assertForbidden();
        $this->assertSame(1, DB::table('case_notes')->where('case_id', $case)->count());
    }

    public function test_replay_and_new_upload_recheck_current_lgu_authorization(): void
    {
        extract($this->fixture());
        $this->postJson('/sync', $payload)->assertOk();
        DB::table('user_lgu_assignments')->where('user_id', $worker->id)->update(['active' => false]);
        $this->postJson('/sync', $payload)->assertForbidden();
        $payload['offline_id'] = (string) Str::uuid();
        $payload['base_version'] = 2;
        $this->postJson('/sync', $payload)->assertForbidden();
    }

    public static function revokedAccess(): array
    {
        return [['expired_device'], ['revoked_device'], ['reassigned_case'], ['archived_case']];
    }

    #[DataProvider('revokedAccess')]
    public function test_access_expiry_or_removal_blocks_replay_and_new_upload(string $state): void
    {
        extract($this->fixture());
        $this->postJson('/sync', $payload)->assertOk();
        match ($state) {
            'expired_device' => DB::table('offline_devices')->where('user_id', $worker->id)->update(['pack_expires_at' => now()->subSecond()]),
            'revoked_device' => DB::table('offline_devices')->where('user_id', $worker->id)->update(['revoked_at' => now()]),
            'reassigned_case' => DB::table('cases')->where('id', $case)->update(['assigned_to' => $other->id]),
            'archived_case' => DB::table('cases')->where('id', $case)->update(['deleted_at' => now()]),
        };
        $this->postJson('/sync', $payload)->assertStatus($state === 'archived_case' ? 422 : 403);
        $payload['offline_id'] = (string) Str::uuid();
        $payload['base_version'] = 2;
        $this->postJson('/sync', $payload)->assertStatus($state === 'archived_case' ? 422 : 403);
        $this->assertSame(1, DB::table('case_notes')->where('case_id', $case)->count());
        $this->assertSame(1, DB::table('offline_sync_items')->count());
    }

    public function test_unsupported_update_does_not_silently_create_a_note_and_audit_has_no_narrative(): void
    {
        extract($this->fixture());
        $payload['operation'] = 'update';
        $this->postJson('/sync', $payload)->assertUnprocessable();
        $this->assertSame(0, DB::table('case_notes')->count());
        $payload['operation'] = 'create';
        $this->postJson('/sync', $payload)->assertOk();
        $this->assertStringNotContainsString($payload['payload']['body'], DB::table('audit_logs')->pluck('changes')->implode(' '));
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_conflict_retry_requires_current_version_and_remains_idempotent(): void
    {
        extract($this->fixture());
        $this->postJson('/sync', $payload)->assertOk();
        $payload['offline_id'] = (string) Str::uuid();
        $payload['payload']['body'] = 'Synthetic reviewed conflict note';
        $this->postJson('/sync', $payload)->assertConflict();
        $this->postJson('/sync', $payload)->assertConflict();
        $this->assertSame(1, DB::table('case_notes')->count());
        $payload['base_version'] = 2;
        $this->postJson('/sync', $payload)->assertOk()->assertJsonPath('offline_id', $payload['offline_id']);
        $this->postJson('/sync', $payload)->assertOk();
        $this->assertSame(2, DB::table('case_notes')->count());
        $this->assertSame(3, DB::table('cases')->where('id', $case)->value('offline_version'));
    }

    public function test_malformed_case_identifier_is_rejected_without_creating_sync_or_case_records(): void
    {
        extract($this->fixture());
        $payload['payload']['case_id'] = ['not' => 'a case identifier'];
        $this->postJson('/sync', $payload)->assertUnprocessable();
        $this->assertSame(0, DB::table('offline_sync_items')->count());
        $this->assertSame(0, DB::table('case_notes')->count());
    }
}

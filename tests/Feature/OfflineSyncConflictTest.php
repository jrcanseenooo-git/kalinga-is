<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineSyncConflictTest extends TestCase
{
    use RefreshDatabase;

    private function deviceHeaders(User $worker): array
    {
        $deviceId = (string) Str::uuid();
        DB::table('offline_devices')->insert(['user_id' => $worker->id, 'device_id' => $deviceId, 'last_seen_at' => now(), 'pack_expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        return ['X-Kalinga-Device-ID' => $deviceId];
    }

    public function test_stale_offline_operation_is_recorded_as_conflict(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $headers = $this->deviceHeaders($worker);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Pat', 'last_name' => 'Lee', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-OFFLINE-01', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'offline_version' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $offlineId = (string) Str::uuid();

        $this->actingAs($worker)->withHeaders($headers)->postJson(route('sync.store'), ['offline_id' => $offlineId, 'resource' => 'case_note', 'operation' => 'create', 'base_version' => 2, 'payload' => ['case_id' => $case, 'body' => 'Created while offline.']])->assertStatus(409)->assertJsonPath('current_version', 3);
        $this->assertDatabaseHas('offline_sync_items', ['offline_id' => $offlineId, 'status' => 'conflict']);
        $this->assertDatabaseMissing('case_notes', ['case_id' => $case, 'body' => 'Created while offline.']);
    }

    public function test_current_offline_operation_syncs_and_advances_version(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $headers = $this->deviceHeaders($worker);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Pat', 'last_name' => 'Lee', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-OFFLINE-02', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'offline_version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($worker)->withHeaders($headers)->postJson(route('sync.store'), ['offline_id' => (string) Str::uuid(), 'resource' => 'case_note', 'operation' => 'create', 'base_version' => 2, 'payload' => ['case_id' => $case, 'body' => 'Created while offline.']])->assertOk()->assertJsonPath('status', 'synced');
        $this->assertSame(3, DB::table('cases')->where('id', $case)->value('offline_version'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OfflineWorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_cacheable_field_shell_excludes_notification_narratives_but_online_inbox_remains(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        DB::table('notifications')->insert([
            'user_id' => $worker->id, 'type' => 'synthetic', 'title' => 'Synthetic private title',
            'body' => 'Synthetic private notification narrative', 'action_url' => '/cases',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->be($worker)->get(route('offline.workspace'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notificationPreview', 0)->where('unreadNotifications', 0));
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notificationPreview', 1)->where('notificationPreview.0.body', 'Synthetic private notification narrative')
            ->where('unreadNotifications', 1));
    }

    private function caseFor(User $worker, string $suffix): int
    {
        $client = DB::table('clients')->insertGetId([
            'first_name' => 'Offline', 'last_name' => $suffix,
            'region' => 'Cordillera Administrative Region (CAR)',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('cases')->insertGetId([
            'case_number' => "KLG-2026-OFFLINE-{$suffix}", 'client_id' => $client,
            'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake',
            'priority' => 'normal', 'opened_at' => now(), 'offline_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_field_worker_pack_contains_only_assigned_cases_and_minimal_fields(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $otherWorker = User::factory()->create(['role' => 'social_worker']);
        $assignedCase = $this->caseFor($worker, 'ASSIGNED');
        $this->caseFor($otherWorker, 'OTHER');

        $response = $this->actingAs($worker)->withHeader('X-Kalinga-Device-ID', (string) Str::uuid())->getJson(route('offline.workspace.pack'));

        $response->assertOk()
            ->assertJsonPath('user_id', $worker->id)
            ->assertJsonCount(1, 'cases')
            ->assertJsonPath('cases.0.id', $assignedCase)
            ->assertJsonMissingPath('cases.0.phone')
            ->assertJsonMissingPath('cases.0.birthdate');
    }

    public function test_read_only_user_cannot_download_or_sync_offline_case_data(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($worker, 'PROTECTED');
        $viewer = User::factory()->create(['role' => 'cpu_member']);

        $this->actingAs($viewer)->getJson(route('offline.workspace.pack'))->assertForbidden();
        $this->actingAs($viewer)->get(route('offline.workspace'))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('sync.store'), [
            'offline_id' => (string) Str::uuid(), 'resource' => 'case_note', 'operation' => 'create',
            'base_version' => 1, 'payload' => ['case_id' => $case, 'body' => 'Blocked local note.'],
        ])->assertForbidden();
    }

    public function test_social_worker_cannot_sync_to_another_workers_case(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $socialWorker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($owner, 'NOT-ASSIGNED');
        $deviceId = (string) Str::uuid();
        DB::table('offline_devices')->insert(['user_id' => $socialWorker->id, 'device_id' => $deviceId, 'last_seen_at' => now(), 'pack_expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($socialWorker)->withHeader('X-Kalinga-Device-ID', $deviceId)->postJson(route('sync.store'), [
            'offline_id' => (string) Str::uuid(), 'resource' => 'case_note', 'operation' => 'create',
            'base_version' => 1, 'payload' => ['case_id' => $case, 'body' => 'Blocked note.'],
        ])->assertForbidden();
    }
}

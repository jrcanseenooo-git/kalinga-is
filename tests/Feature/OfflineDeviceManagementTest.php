<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfflineDeviceManagementTest extends TestCase
{
    use RefreshDatabase;

    private function workerWithDevice(): array
    {
        $user = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $deviceId = fake()->uuid();
        $dbId = DB::table('offline_devices')->insertGetId([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'device_name' => 'Field tablet',
            'last_seen_at' => now(),
            'pack_expires_at' => now()->addHours(72),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $deviceId, $dbId];
    }

    public function test_user_can_list_own_devices(): void
    {
        [$user, , $dbId] = $this->workerWithDevice();

        $response = $this->actingAs($user)->getJson(route('offline.devices.index'));

        $response->assertOk();
        $response->assertJsonCount(1, 'devices');
        $response->assertJsonPath('devices.0.id', $dbId);
    }

    public function test_user_cannot_see_other_users_devices(): void
    {
        [$user1] = $this->workerWithDevice();
        $user2 = User::factory()->create(['role' => 'social_worker', 'active' => true]);

        $response = $this->actingAs($user2)->getJson(route('offline.devices.index'));

        $response->assertOk();
        $response->assertJsonCount(0, 'devices');
    }

    public function test_user_can_revoke_own_device(): void
    {
        [$user, , $dbId] = $this->workerWithDevice();

        $response = $this->actingAs($user)->postJson(route('offline.devices.revoke', $dbId));

        $response->assertOk();
        $this->assertNotNull(DB::table('offline_devices')->where('id', $dbId)->value('revoked_at'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'offline.device_revoked', 'user_id' => $user->id]);
    }

    public function test_user_cannot_revoke_another_users_device(): void
    {
        [$user1, , $dbId] = $this->workerWithDevice();
        $user2 = User::factory()->create(['role' => 'social_worker', 'active' => true]);

        $response = $this->actingAs($user2)->postJson(route('offline.devices.revoke', $dbId));

        $response->assertStatus(404);
        $this->assertNull(DB::table('offline_devices')->where('id', $dbId)->value('revoked_at'));
    }

    public function test_user_can_rename_device(): void
    {
        [$user, , $dbId] = $this->workerWithDevice();

        $response = $this->actingAs($user)->patchJson(route('offline.devices.rename', $dbId), [
            'device_name' => 'Community tablet #2',
        ]);

        $response->assertOk();
        $this->assertEquals('Community tablet #2', DB::table('offline_devices')->where('id', $dbId)->value('device_name'));
    }

    public function test_device_status_returns_revoked(): void
    {
        [$user, $deviceId, $dbId] = $this->workerWithDevice();
        DB::table('offline_devices')->where('id', $dbId)->update(['revoked_at' => now()]);

        $response = $this->actingAs($user)
            ->withHeader('X-Kalinga-Device-ID', $deviceId)
            ->getJson(route('offline.devices.status'));

        $response->assertOk();
        $response->assertJsonPath('registered', true);
        $response->assertJsonPath('revoked', true);
    }

    public function test_device_status_returns_expired(): void
    {
        [$user, $deviceId, $dbId] = $this->workerWithDevice();
        DB::table('offline_devices')->where('id', $dbId)->update(['pack_expires_at' => now()->subHour()]);

        $response = $this->actingAs($user)
            ->withHeader('X-Kalinga-Device-ID', $deviceId)
            ->getJson(route('offline.devices.status'));

        $response->assertOk();
        $response->assertJsonPath('expired', true);
    }

    public function test_device_status_unregistered_device(): void
    {
        $user = User::factory()->create(['role' => 'social_worker', 'active' => true]);

        $response = $this->actingAs($user)
            ->withHeader('X-Kalinga-Device-ID', fake()->uuid())
            ->getJson(route('offline.devices.status'));

        $response->assertOk();
        $response->assertJsonPath('registered', false);
    }

    public function test_cpu_member_cannot_access_device_endpoints(): void
    {
        $cpu = User::factory()->create(['role' => 'cpu_member', 'active' => true]);

        $this->actingAs($cpu)->getJson(route('offline.devices.index'))->assertForbidden();
        $this->actingAs($cpu)->getJson(route('offline.devices.status'))->assertForbidden();
    }

    public function test_admin_can_list_user_devices(): void
    {
        [$worker, , $dbId] = $this->workerWithDevice();
        $admin = User::factory()->create(['role' => 'system_admin', 'active' => true]);

        $response = $this->actingAs($admin)->getJson(route('admin.users.devices.index', $worker->id));

        $response->assertOk();
        $response->assertJsonCount(1, 'devices');
    }

    public function test_admin_can_revoke_any_device(): void
    {
        [$worker, , $dbId] = $this->workerWithDevice();
        $admin = User::factory()->create(['role' => 'system_admin', 'active' => true]);

        $response = $this->actingAs($admin)->postJson(route('admin.devices.revoke', $dbId));

        $response->assertOk();
        $this->assertNotNull(DB::table('offline_devices')->where('id', $dbId)->value('revoked_at'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'offline.device_revoked_by_admin', 'user_id' => $admin->id]);
    }

    public function test_non_admin_cannot_access_admin_device_endpoints(): void
    {
        [$worker, , $dbId] = $this->workerWithDevice();

        $this->actingAs($worker)->getJson(route('admin.users.devices.index', $worker->id))->assertForbidden();
        $this->actingAs($worker)->postJson(route('admin.devices.revoke', $dbId))->assertForbidden();
    }

    public function test_double_revoke_returns_404(): void
    {
        [$user, , $dbId] = $this->workerWithDevice();
        DB::table('offline_devices')->where('id', $dbId)->update(['revoked_at' => now()]);

        $response = $this->actingAs($user)->postJson(route('offline.devices.revoke', $dbId));

        $response->assertStatus(404);
    }

    public function test_revoke_name_too_long_rejected(): void
    {
        [$user, , $dbId] = $this->workerWithDevice();

        $response = $this->actingAs($user)->patchJson(route('offline.devices.rename', $dbId), [
            'device_name' => str_repeat('x', 81),
        ]);

        $response->assertUnprocessable();
    }
}
